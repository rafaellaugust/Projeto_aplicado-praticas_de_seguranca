<?php
/**
 * cron/disparar_whatsapp.php
 * Disparo em lote de notificações WhatsApp para faturas pendentes.
 *
 * Pode ser chamado por:
 *  - Node.js agendador (Bearer token)
 *  - Painel Admin via HTTP interno (Bearer token ou sessão admin)
 *  - CLI: php cron/disparar_whatsapp.php
 *
 * Parâmetros GET:
 *  ?action=schedule_check  → Retorna se deve disparar agora (usado pelo Node.js a cada minuto)
 *  ?action=contar_pendentes → Conta quantas faturas seriam enviadas
 *  (sem action / action=dispatch) → Executa o disparo em lote
 *
 * NOVIDADES:
 *  - Antes de enviar o WhatsApp, se a fatura não tiver PIX Copia e Cola gerado
 *    (ou o PIX já expirou), o sistema gera automaticamente via PaymentGateway,
 *    igual ao endpoint payment/pix.php. Assim a chave chega ao cliente mesmo
 *    que ele nunca tenha acessado a plataforma.
 *  - A notificação é enviada em DUAS mensagens (corpo + PIX separado), com
 *    delay configurável (wa_delay_pix), implementado em
 *    WhatsAppService::sendInvoiceNotification().
 *  - Se a geração automática do PIX falhar (ex: erro no gateway Mercado Pago),
 *    a fatura NÃO é marcada como notificada — fica pendente para nova tentativa
 *    no próximo disparo, e o histórico registra "falhou" com o motivo real.
 */

set_time_limit(300);
ignore_user_abort(true);

require_once __DIR__ . '/../config.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}

$db = Database::getInstance();

// ─── Auto-migrate: criar tabela e colunas se não existirem ───────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `whatsapp_disparos` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `fatura_id`    INT NOT NULL,
        `cliente_id`   INT NOT NULL,
        `nome_cliente` VARCHAR(150) DEFAULT '',
        `whatsapp`     VARCHAR(20)  DEFAULT '',
        `tipo`         ENUM('cobranca','confirmacao','teste') DEFAULT 'cobranca',
        `status`       ENUM('enviado','falhou') DEFAULT 'falhou',
        `erro_msg`     TEXT,
        `criado_em`    DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_fatura   (fatura_id),
        INDEX idx_cliente  (cliente_id),
        INDEX idx_criado   (criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

try {
    $existingCols = array_column(
        $db->query("SHOW COLUMNS FROM configuracoes")->fetchAll(),
        'Field'
    );
    if (!in_array('wa_disparo_hora', $existingCols)) {
        $db->exec("ALTER TABLE configuracoes
            ADD COLUMN wa_disparo_hora  VARCHAR(5)   DEFAULT '08:00',
            ADD COLUMN wa_disparo_delay INT          DEFAULT 5,
            ADD COLUMN wa_disparo_ativo TINYINT(1)   DEFAULT 0,
            ADD COLUMN wa_disparo_dias  INT          DEFAULT 3,
            ADD COLUMN wa_ultimo_disparo DATE        DEFAULT NULL
        ");
    }
    if (!in_array('wa_delay_pix', $existingCols)) {
        $db->exec("ALTER TABLE configuracoes ADD COLUMN wa_delay_pix INT DEFAULT 3");
    }
    if (!in_array('wa_aviso_ativo', $existingCols)) {
        $db->exec("ALTER TABLE configuracoes ADD COLUMN wa_aviso_ativo TINYINT(1) DEFAULT 1");
    }
    if (!in_array('wa_aviso_tolerancia', $existingCols)) {
        $db->exec("ALTER TABLE configuracoes ADD COLUMN wa_aviso_tolerancia INT DEFAULT 5");
    }
    if (!in_array('wa_modelo_aviso', $existingCols)) {
        $db->exec("ALTER TABLE configuracoes ADD COLUMN wa_modelo_aviso TEXT");
    }
    if (!in_array('wa_modelo_confirmacao', $existingCols)) {
        $db->exec("ALTER TABLE configuracoes ADD COLUMN wa_modelo_confirmacao TEXT");
    }
} catch (Exception $e) {}

try {
    $existingColsFat = array_column(
        $db->query("SHOW COLUMNS FROM faturas")->fetchAll(),
        'Field'
    );
    if (!in_array('notificado_aviso', $existingColsFat)) {
        $db->exec("ALTER TABLE faturas ADD COLUMN notificado_aviso TINYINT(1) DEFAULT 0");
    }
} catch (Exception $e) {}

try {
    $db->exec("ALTER TABLE whatsapp_disparos MODIFY COLUMN tipo VARCHAR(50) DEFAULT 'cobranca'");
} catch (Exception $e) {}

try {
    $db->exec("UPDATE clientes SET whatsapp = SUBSTRING(whatsapp, 3) WHERE (LENGTH(whatsapp) = 12 OR LENGTH(whatsapp) = 13) AND whatsapp LIKE '55%'");
    $db->exec("UPDATE whatsapp_disparos SET whatsapp = SUBSTRING(whatsapp, 3) WHERE (LENGTH(whatsapp) = 12 OR LENGTH(whatsapp) = 13) AND whatsapp LIKE '55%'");
} catch (Exception $e) {}

$cfg     = $db->query("SELECT * FROM configuracoes WHERE id = 1")->fetch();
$waToken = $cfg['wa_token'] ?? '';

// ─── Segurança ────────────────────────────────────────────────────────────────
$isAdmin = !empty($_SESSION['admin_id']);
$isCron  = false;

if (!$isAdmin && PHP_SAPI !== 'cli') {
    $tokenGet = $_GET['key'] ?? ($_GET['token'] ?? '');
    // Tenta obter o Bearer token do header HTTP
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (empty($authHeader) && function_exists('apache_request_headers')) {
        $hdrs       = apache_request_headers();
        $authHeader = $hdrs['Authorization'] ?? '';
    }
    $bearerToken = str_replace('Bearer ', '', $authHeader);

    // Aceita se o token for igual ao wa_token cadastrado, chave spaconett_cron ou se não houver token configurado
    if (!empty($waToken)) {
        $isCron = ($bearerToken === $waToken || $tokenGet === $waToken || $tokenGet === 'spaconett_cron');
    } else {
        $isCron = true; // Sem token = sem restrição
    }
}

if (!$isAdmin && !$isCron && PHP_SAPI !== 'cli') {
    Database::log('cron_bloqueado', 'Tentativa de execução de cron sem token ou autorização', [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido',
        'uri' => $_SERVER['REQUEST_URI'] ?? ''
    ]);
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado. Token inválido ou ausente. Use ?key=SEU_TOKEN ou Bearer token.']);
    exit;
}

$action = $_GET['action'] ?? 'dispatch';

// ─── schedule_check: usado pelo Node.js para verificar se é hora de disparar ─
if ($action === 'schedule_check') {
    $horaConfig    = $cfg['wa_disparo_hora']  ?? '08:00';
    $ativo         = (int)($cfg['wa_disparo_ativo'] ?? 0);
    $ultimoDisparo = $cfg['wa_ultimo_disparo'] ?? null;
    $hojeStr       = date('Y-m-d');
    $horaAtual     = date('H:i');

    $shouldDispatch = ($ativo === 1 && $horaAtual === $horaConfig && $ultimoDisparo !== $hojeStr);

    echo json_encode([
        'should_dispatch'  => $shouldDispatch,
        'ativo'            => (bool)$ativo,
        'hora_configurada' => $horaConfig,
        'hora_atual'       => $horaAtual,
        'ultimo_disparo'   => $ultimoDisparo,
        'hoje'             => $hojeStr,
    ]);
    exit;
}

// ─── contar_pendentes: quantas faturas seriam disparadas (1º + 2º disparo) ────
if ($action === 'contar_pendentes') {
    $diasCobranca = (int)($cfg['wa_disparo_dias'] ?? 3);
    $avisoAtivo   = (int)($cfg['wa_aviso_ativo'] ?? 1);
    $tolerancia   = (int)($cfg['wa_aviso_tolerancia'] ?? 5);

    // 1. Cobrança Preventiva (antes do vencimento)
    $stCob = $db->prepare("
        SELECT COUNT(*) FROM faturas f
        JOIN clientes c ON f.cliente_id = c.id
        WHERE f.status = 'pendente'
          AND f.notificado_wa = 0
          AND c.whatsapp != ''
          AND f.data_vencimento BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL ? DAY)
    ");
    $stCob->execute([$diasCobranca]);
    $totalCobrancas = (int)$stCob->fetchColumn();

    // 2. Aviso Pré-Bloqueio (vencidas dentro da carência de até 5 dias antes do bloqueio no dia 06)
    $totalAvisos = 0;
    if ($avisoAtivo === 1) {
        $stAvi = $db->prepare("
            SELECT COUNT(*) FROM faturas f
            JOIN clientes c ON f.cliente_id = c.id
            WHERE f.status IN ('pendente','atrasado')
              AND f.notificado_aviso = 0
              AND c.whatsapp != ''
              AND f.data_vencimento < CURRENT_DATE()
              AND DATEDIFF(CURRENT_DATE(), f.data_vencimento) BETWEEN 1 AND ?
        ");
        $stAvi->execute([$tolerancia]);
        $totalAvisos = (int)$stAvi->fetchColumn();
    }

    $totalGeral = $totalCobrancas + $totalAvisos;

    echo json_encode([
        'total'     => $totalGeral,
        'cobrancas' => $totalCobrancas,
        'avisos'    => $totalAvisos
    ]);
    exit;
}

/**
 * Garante que a fatura tenha um PIX Copia e Cola válido (não expirado).
 * Se não tiver ou estiver expirado, gera um novo via PaymentGateway,
 * salva na fatura e retorna um array:
 *   ['pix' => string, 'erro' => string|null]
 *   - 'pix' vazio + 'erro' preenchido = falha real na geração (gateway).
 */
function garantirPixFatura(array $fat, $db): array {
    $pixAtual = $fat['pix_copia_cola'] ?? '';
    $expira   = $fat['expiration_date'] ?? null;

    if (!empty($pixAtual) && !empty($expira) && strtotime($expira) > time()) {
        return ['pix' => $pixAtual, 'erro' => null];
    }

    try {
        $stmtFull = $db->prepare("
            SELECT f.*, c.nome, c.email, c.cpf_cnpj, c.pppoe_usuario,
                   p.nome as plano_nome, p.valor as plano_valor
            FROM faturas f
            JOIN clientes c ON f.cliente_id = c.id
            LEFT JOIN planos p ON f.plano_id = p.id
            WHERE f.id = ?
        ");
        $stmtFull->execute([$fat['fatura_id']]);
        $faturaFull = $stmtFull->fetch();

        if (!$faturaFull) {
            return ['pix' => '', 'erro' => 'Fatura não encontrada ao gerar PIX.'];
        }

        $email   = !empty($faturaFull['email']) ? $faturaFull['email'] : "cliente{$faturaFull['cliente_id']}@spaconett.com";
        $descPix = "Mensalidade - " . ($faturaFull['plano_nome'] ?? 'Internet') . " - " . $faturaFull['nome'];

        $gateway = new PaymentGateway();
        $pix     = $gateway->generatePixCharge(
            (int)$faturaFull['id'],
            (float)$faturaFull['valor'],
            $descPix,
            $email,
            $faturaFull['cpf_cnpj'] ?? ''
        );

        if (empty($pix['success'])) {
            $motivo = $pix['message'] ?? 'Falha desconhecida no gateway de pagamento.';
            Database::log('whatsapp', "Falha ao gerar PIX automático para fatura #{$fat['fatura_id']}", $pix);
            return ['pix' => '', 'erro' => 'Falha ao gerar PIX (gateway): ' . $motivo];
        }

        $db->prepare("
            UPDATE faturas SET
                external_reference = ?,
                pix_txid           = ?,
                pix_copia_cola     = ?,
                qr_code            = ?,
                pix_qr_code_base64 = ?,
                gateway_id         = ?,
                mp_payment_id      = ?,
                gateway_status     = 'pending',
                expiration_date    = ?
            WHERE id = ?
        ")->execute([
            $pix['external_reference'],
            $pix['txid'],
            $pix['copia_cola'],
            $pix['qr_code'],
            $pix['qr_code_base64'] ?? '',
            $pix['gateway_id'],
            $pix['mp_payment_id'],
            date('Y-m-d H:i:s', strtotime($pix['expiration_date'])),
            $faturaFull['id']
        ]);

        return ['pix' => $pix['copia_cola'], 'erro' => null];

    } catch (Exception $e) {
        Database::log('whatsapp', "Erro ao gerar PIX automático para fatura #{$fat['fatura_id']}: " . $e->getMessage());
        return ['pix' => '', 'erro' => 'Exceção ao gerar PIX: ' . $e->getMessage()];
    }
}

// ─── dispatch: executa o disparo em lote (1º Cobrança + 2º Aviso Pré-Bloqueio) ─
$diasCobranca = (int)($cfg['wa_disparo_dias']  ?? 3);
$delay        = (int)($cfg['wa_disparo_delay'] ?? 5);
$avisoAtivo   = (int)($cfg['wa_aviso_ativo'] ?? 1);
$tolerancia   = (int)($cfg['wa_aviso_tolerancia'] ?? 5);

// 1. Faturas para Cobrança Preventiva (antes do vencimento)
$stmtFat1 = $db->prepare("
    SELECT f.id AS fatura_id, f.valor, f.data_vencimento, f.pix_copia_cola, f.expiration_date,
           c.id AS cliente_id, c.nome, c.whatsapp, c.pppoe_usuario,
           'cobranca' AS tipo_envio, 0 AS dias_atraso
    FROM faturas f
    JOIN clientes c ON f.cliente_id = c.id
    WHERE f.status = 'pendente'
      AND f.notificado_wa = 0
      AND c.whatsapp != ''
      AND f.data_vencimento BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL ? DAY)
    ORDER BY f.data_vencimento ASC
");
$stmtFat1->execute([$diasCobranca]);
$faturasCobranca = $stmtFat1->fetchAll();

// 2. Faturas para Aviso Pré-Bloqueio (vencidas dentro do período de carência de até 5 dias antes do bloqueio no dia 06)
$faturasAviso = [];
if ($avisoAtivo === 1) {
    $stmtFat2 = $db->prepare("
        SELECT f.id AS fatura_id, f.valor, f.data_vencimento, f.pix_copia_cola, f.expiration_date,
               c.id AS cliente_id, c.nome, c.whatsapp, c.pppoe_usuario,
               'aviso_bloqueio' AS tipo_envio, DATEDIFF(CURRENT_DATE(), f.data_vencimento) AS dias_atraso
        FROM faturas f
        JOIN clientes c ON f.cliente_id = c.id
        WHERE f.status IN ('pendente','atrasado')
          AND f.notificado_aviso = 0
          AND c.whatsapp != ''
          AND f.data_vencimento < CURRENT_DATE()
          AND DATEDIFF(CURRENT_DATE(), f.data_vencimento) BETWEEN 1 AND ?
        ORDER BY f.data_vencimento ASC
    ");
    $stmtFat2->execute([$tolerancia]);
    $faturasAviso = $stmtFat2->fetchAll();
}

// Junta a fila de envios
$faturas = array_merge($faturasCobranca, $faturasAviso);

if (!empty($faturas)) {
    Database::log('disparo_whatsapp', "Iniciando processamento da fila de disparos: " . count($faturas) . " fatura(s) selecionada(s) (" . count($faturasCobranca) . " cobranças + " . count($faturasAviso) . " avisos pré-bloqueio)");
}

$enviados   = 0;
$falhos     = 0;
$resultados = [];
$wa         = new WhatsAppService();

foreach ($faturas as $idx => $fat) {
    if ($idx > 0 && $delay > 0) {
        sleep($delay);
    }

    $faturaUrl = BASE_URL . '/cliente/fatura.php?id=' . $fat['fatura_id'];

    // Garante que exista um PIX válido antes de notificar (gera automaticamente se preciso)
    $pixResult    = garantirPixFatura($fat, $db);
    $pixCopiaCola = $pixResult['pix'];
    $erroPixGeracao = $pixResult['erro'];

    try {
        if ($fat['tipo_envio'] === 'aviso_bloqueio') {
            $envio = $wa->sendDueWarningNotification(
                $fat['whatsapp'],
                $fat['nome'],
                $fat['valor'],
                $fat['data_vencimento'],
                $pixCopiaCola,
                $faturaUrl,
                $fat['pppoe_usuario'] ?? '',
                '',
                '',
                (int)$fat['fatura_id'],
                $fat['expiration_date'] ?? null,
                (int)$fat['dias_atraso']
            );
        } else {
            $envio = $wa->sendInvoiceNotification(
                $fat['whatsapp'],
                $fat['nome'],
                $fat['valor'],
                $fat['data_vencimento'],
                $pixCopiaCola,
                $faturaUrl,
                $fat['pppoe_usuario'] ?? '',
                '',
                '',
                (int)$fat['fatura_id'],
                $fat['expiration_date'] ?? null
            );
        }
    } catch (Exception $e) {
        $envio = ['corpo' => false, 'pix' => null, 'erro' => $e->getMessage()];
    }

    // Sucesso REAL exige: corpo enviado E PIX obtido E PIX enviado.
    $sucesso = $envio['corpo'] && empty($erroPixGeracao) && ($envio['pix'] === null || $envio['pix'] === true);

    $statusLog = $sucesso ? 'enviado' : 'falhou';

    if (!$sucesso) {
        if (!$envio['corpo']) {
            $erroMsg = $envio['erro'] ?? 'Falha ao enviar o corpo da mensagem.';
        } elseif (!empty($erroPixGeracao)) {
            $erroMsg = 'Corpo enviado. ' . $erroPixGeracao;
        } else {
            $erroMsg = $envio['erro'] ?? 'Corpo enviado, falha ao enviar PIX';
        }
    } else {
        $erroMsg = null;
    }

    // Grava no histórico com o tipo exato ('cobranca' ou 'aviso_bloqueio')
    $db->prepare("
        INSERT INTO whatsapp_disparos (fatura_id, cliente_id, nome_cliente, whatsapp, tipo, status, erro_msg)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([$fat['fatura_id'], $fat['cliente_id'], $fat['nome'], WhatsAppService::sanitizePhone($fat['whatsapp']), $fat['tipo_envio'], $statusLog, $erroMsg]);

    if ($sucesso) {
        if ($fat['tipo_envio'] === 'aviso_bloqueio') {
            $db->prepare("UPDATE faturas SET notificado_aviso = 1 WHERE id = ?")->execute([$fat['fatura_id']]);
        } else {
            $db->prepare("UPDATE faturas SET notificado_wa = 1 WHERE id = ?")->execute([$fat['fatura_id']]);
        }
        $enviados++;
    } else {
        $falhos++;
    }

    $resultados[$fat['fatura_id']] = [
        'fatura_id'  => $fat['fatura_id'],
        'cliente'    => $fat['nome'],
        'whatsapp'   => $fat['whatsapp'],
        'tipo'       => $fat['tipo_envio'],
        'status'     => $statusLog,
        'erro'       => $erroMsg,
        'pix_gerado_automaticamente' => !empty($pixCopiaCola) && empty($fat['pix_copia_cola']),
        'tentativas' => 1
    ];

    if (PHP_SAPI === 'cli') {
        $icon = $sucesso ? '✓' : '✗';
        $tagTipo = ($fat['tipo_envio'] === 'aviso_bloqueio') ? '[AVISO PRÉ-BLOQUEIO]' : '[COBRANÇA]';
        echo "[" . date('H:i:s') . "] {$icon} {$tagTipo} {$fat['nome']} ({$fat['whatsapp']}) — Fatura #{$fat['fatura_id']}" . (!$sucesso ? " | {$erroMsg}" : '') . "\n";
    }
}

// ─── ROTINA DE REENTREVISTAS / RETENTATIVAS (Até 3 tentativas ao fim) ──────────
$faturasFalhas = array_filter($faturas, function($f) use ($resultados) {
    return isset($resultados[$f['fatura_id']]) && $resultados[$f['fatura_id']]['status'] === 'falhou';
});

if (!empty($faturasFalhas)) {
    $maxTentativas = 3;
    Database::log('disparo_whatsapp', "Iniciando fila de retentativas para " . count($faturasFalhas) . " fatura(s) com falha inicial (até {$maxTentativas} tentativas cada).");

    for ($tentativa = 2; $tentativa <= $maxTentativas; $tentativa++) {
        if (empty($faturasFalhas)) {
            break; // Todas foram reenviadas com sucesso!
        }

        // Aguarda 5 segundos entre rodadas de retentativa para permitir estabilização da conexão
        sleep(5);

        if (PHP_SAPI === 'cli') {
            echo "\n[INFO] Executando rodada {$tentativa} de retentativas para " . count($faturasFalhas) . " fatura(s)...\n";
        }

        $falhasRestantes = [];

        foreach ($faturasFalhas as $fat) {
            if ($delay > 0) sleep($delay);

            $faturaUrl = BASE_URL . '/cliente/fatura.php?id=' . $fat['fatura_id'];
            $pixResult = garantirPixFatura($fat, $db);
            $pixCopiaCola = $pixResult['pix'];
            $erroPixGeracao = $pixResult['erro'];

            try {
                if ($fat['tipo_envio'] === 'aviso_bloqueio') {
                    $envio = $wa->sendDueWarningNotification(
                        $fat['whatsapp'],
                        $fat['nome'],
                        $fat['valor'],
                        $fat['data_vencimento'],
                        $pixCopiaCola,
                        $faturaUrl,
                        $fat['pppoe_usuario'] ?? '',
                        '',
                        '',
                        (int)$fat['fatura_id'],
                        $fat['expiration_date'] ?? null,
                        (int)$fat['dias_atraso']
                    );
                } else {
                    $envio = $wa->sendInvoiceNotification(
                        $fat['whatsapp'],
                        $fat['nome'],
                        $fat['valor'],
                        $fat['data_vencimento'],
                        $pixCopiaCola,
                        $faturaUrl,
                        $fat['pppoe_usuario'] ?? '',
                        '',
                        '',
                        (int)$fat['fatura_id'],
                        $fat['expiration_date'] ?? null
                    );
                }
            } catch (Exception $e) {
                $envio = ['corpo' => false, 'pix' => null, 'erro' => $e->getMessage()];
            }

            $sucesso = $envio['corpo'] && empty($erroPixGeracao) && ($envio['pix'] === null || $envio['pix'] === true);

            if ($sucesso) {
                if ($fat['tipo_envio'] === 'aviso_bloqueio') {
                    $db->prepare("UPDATE faturas SET notificado_aviso = 1 WHERE id = ?")->execute([$fat['fatura_id']]);
                } else {
                    $db->prepare("UPDATE faturas SET notificado_wa = 1 WHERE id = ?")->execute([$fat['fatura_id']]);
                }

                // Ajusta contadores globais
                $enviados++;
                $falhos--;

                $resultados[$fat['fatura_id']]['status'] = 'enviado';
                $resultados[$fat['fatura_id']]['erro'] = null;
                $resultados[$fat['fatura_id']]['tentativas'] = $tentativa;

                $db->prepare("
                    INSERT INTO whatsapp_disparos (fatura_id, cliente_id, nome_cliente, whatsapp, tipo, status, erro_msg)
                    VALUES (?, ?, ?, ?, ?, 'enviado', ?)
                ")->execute([$fat['fatura_id'], $fat['cliente_id'], $fat['nome'], WhatsAppService::sanitizePhone($fat['whatsapp']), $fat['tipo_envio'], "Enviado com sucesso na tentativa {$tentativa}"]);

                Database::log('disparo_whatsapp', "Sucesso no reenvio da fatura #{$fat['fatura_id']} ({$fat['nome']}) na tentativa {$tentativa}/{$maxTentativas}");

                if (PHP_SAPI === 'cli') {
                    echo "[" . date('H:i:s') . "] ✓ [RETENTATIVA {$tentativa}] Sucesso para {$fat['nome']} — Fatura #{$fat['fatura_id']}\n";
                }
            } else {
                $erroMsg = $envio['erro'] ?? 'Falha persistente no reenvio.';
                $resultados[$fat['fatura_id']]['erro'] = "Tentativa {$tentativa}/{$maxTentativas}: " . $erroMsg;
                $resultados[$fat['fatura_id']]['tentativas'] = $tentativa;
                $falhasRestantes[] = $fat;

                if (PHP_SAPI === 'cli') {
                    echo "[" . date('H:i:s') . "] ✗ [RETENTATIVA {$tentativa}] Falha para {$fat['nome']} — Fatura #{$fat['fatura_id']} | {$erroMsg}\n";
                }
            }
        }

        $faturasFalhas = $falhasRestantes;
    }
}

// Converte resultados para array indexado para envio de resposta
$resultados = array_values($resultados);

// Atualiza data do último disparo e relata no Telegram
if (!empty($faturas)) {
    $db->exec("UPDATE configuracoes SET wa_ultimo_disparo = '" . date('Y-m-d') . "' WHERE id = 1");
    
    Database::log('disparo_whatsapp', "Disparo em lote finalizado: {$enviados} enviados, {$falhos} falhas (Total: " . count($faturas) . " faturas)", [
        'total' => count($faturas),
        'enviados' => $enviados,
        'falhas' => $falhos,
        'executado_por' => (PHP_SAPI === 'cli' ? 'CLI/Cron' : (!empty($_SESSION['admin_nome']) ? 'Admin (' . $_SESSION['admin_nome'] . ')' : 'Agendador Node.js'))
    ]);

    try {
        $tg = new TelegramService();
        $tg->notifyDisparoReport($enviados, $falhos, $resultados);
    } catch (Exception $e) {
        Database::log('telegram', 'Erro ao enviar relatório de disparos: ' . $e->getMessage());
    }
}

$output = [
    'sucesso'    => true,
    'total'      => count($faturas),
    'enviados'   => $enviados,
    'falhos'     => $falhos,
    'resultados' => $resultados,
    'timestamp'  => date('Y-m-d H:i:s'),
];

if (PHP_SAPI === 'cli') {
    echo "\n=== DISPARO CONCLUÍDO: {$enviados} enviados, {$falhos} falhos de " . count($faturas) . " faturas ===\n";
} else {
    echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}