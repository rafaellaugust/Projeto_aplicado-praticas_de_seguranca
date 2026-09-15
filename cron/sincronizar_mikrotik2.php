<?php
/**
 * cron/sincronizar_mikrotik.php
 * 
 * Sincronização automática MikroTik → Sistema
 * 
 * Lógica de 4 estados do MikroTik:
 *  */Pago     → Pago adiantado (MK aguarda, no dia 01 move para /Espera)
 *  */Espera   → Mês atual liberado (acesso normal)
 *  */Aviso    → Vencendo, cliente tem ~5 dias para pagar
 *  */Bloqueado→ Bloqueado por inadimplência
 * 
 * Mapeamento para o sistema:
 *  Pago    → status 'paid'    (adiantado)
 *  Espera  → status 'paid'    (mês atual ok)
 *  Aviso   → status 'warning' (fatura aberta obrigatória)
 *  Bloqueado → status 'overdue' (fatura aberta obrigatória)
 * 
 * Rodar a cada 30 minutos:
 *  */30 * * * * php /caminho/para/cron/sincronizar_mikrotik.php >> /var/log/mikropay_sync.log 2>&1
 * 
 * Ou via URL (com token de segurança):
 *  https://seusite.com/cron/sincronizar_mikrotik.php?token=SEU_TOKEN_SECRETO
 */

// Permite execução via CLI ou via HTTP (com token)
if (PHP_SAPI !== 'cli') {
    // Protege execução HTTP com token
    $tokenSeguro = 'mikropay_sync_2026_' . date('Ymd'); // Token rotativo diário
    $tokenEnviado = $_GET['token'] ?? '';
    if (!hash_equals($tokenSeguro, $tokenEnviado)) {
        http_response_code(403);
        echo json_encode(['error' => 'Token inválido']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../config.php';

$inicioExecucao = microtime(true);
$logs = [];
$resultado = [
    'clientes_sincronizados' => 0,
    'faturas_criadas'        => 0,
    'faturas_marcadas_pagas' => 0,
    'erros'                  => 0,
];

function logSync($msg) {
    global $logs;
    $ts = date('Y-m-d H:i:s');
    $logs[] = "[{$ts}] {$msg}";
    if (PHP_SAPI === 'cli') {
        echo "[{$ts}] {$msg}\n";
    }
}

logSync("=== INICIANDO SINCRONIZAÇÃO MIKROTIK → SISTEMA ===");

$db     = Database::getInstance();
$mesAtual = (int)date('n');
$anoAtual = (int)date('Y');

// ─── Função central: detecta status a partir do nome do profile ──────────────
function detectarStatusProfile(string $profile): string {
    $lower = strtolower($profile);
    // Ordem importa: verificar 'pago' ANTES de 'espera'
    if (strpos($lower, '/pago')  !== false || substr($lower, -5) === '/pago')  return 'paid_advance';
    if (strpos($lower, 'bloqueado') !== false) return 'overdue';
    if (strpos($lower, 'aviso')    !== false) return 'warning';
    if (strpos($lower, 'espera')   !== false) return 'paid';
    // Fallback: se não tem sufixo reconhecido, considera pago (acesso liberado)
    return 'paid';
}

// ─── Buscar todos os roteadores ativos ───────────────────────────────────────
$roteadores = $db->query("SELECT * FROM roteadores WHERE ip_host IS NOT NULL AND ip_host != '' ORDER BY id ASC")->fetchAll();

if (empty($roteadores)) {
    logSync("ERRO: Nenhum roteador configurado.");
    exit(1);
}

foreach ($roteadores as $roteador) {
    logSync("Conectando ao roteador: {$roteador['nome']} ({$roteador['ip_host']}:{$roteador['porta_api']})");

    $mkApi   = new MikrotikAPI($roteador['id']);
    $secrets = $mkApi->getSecrets();

    if (empty($secrets)) {
        logSync("AVISO: Nenhum usuário PPPoE encontrado no roteador {$roteador['nome']} ou falha de conexão: " . $mkApi->getLastError());
        $resultado['erros']++;
        continue;
    }

    logSync("Total de usuários PPPoE: " . count($secrets));

    foreach ($secrets as $secret) {
        $username = trim($secret['name'] ?? '');
        $profile  = trim($secret['profile'] ?? '');

        if (empty($username) || empty($profile)) continue;

        // Buscar cliente no banco pelo pppoe_usuario
        $stmtCli = $db->prepare("
            SELECT c.id, c.nome, c.plano_id, c.vencimento_dia,
                   p.valor as plano_valor, p.profile_mikrotik, p.profile_aviso, p.profile_bloqueado
            FROM clientes c
            LEFT JOIN planos p ON c.plano_id = p.id
            WHERE c.pppoe_usuario = ? LIMIT 1
        ");
        $stmtCli->execute([$username]);
        $cliente = $stmtCli->fetch();

        if (!$cliente) {
            // Usuário no MikroTik mas não cadastrado no sistema
            logSync("SKIP: Usuário '{$username}' não encontrado no sistema.");
            continue;
        }

        $clienteId  = (int)$cliente['id'];
        $statusMK   = detectarStatusProfile($profile);
        $valorPlano = (float)($cliente['plano_valor'] ?? 0);
        $diaVenc    = (int)($cliente['vencimento_dia'] ?? 10);

        // Mapeia para status do histórico: paid_advance → 'paid'
        $statusHistorico = ($statusMK === 'paid_advance') ? 'paid' : $statusMK;

        logSync("  [{$username}] Profile: {$profile} → Status: {$statusMK}");

        // ── 1. Atualiza mikrotik_payment_history para o mês atual ─────────────
        $db->prepare("
            INSERT INTO mikrotik_payment_history (cliente_id, ano, mes, status, valor)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), valor = VALUES(valor)
        ")->execute([$clienteId, $anoAtual, $mesAtual, $statusHistorico, $valorPlano]);

        // ── 2. Atualiza clientes.status ───────────────────────────────────────
        $novoStatusCliente = 'ativo'; // default
        if ($statusMK === 'overdue')              $novoStatusCliente = 'suspenso';
        elseif ($statusMK === 'warning')          $novoStatusCliente = 'aviso';
        // 'paid' e 'paid_advance' = ativo

        $db->prepare("UPDATE clientes SET status = ? WHERE id = ?")->execute([$novoStatusCliente, $clienteId]);

        // ── 3. Gerencia fatura do mês atual ───────────────────────────────────
        $dataVencimento = date('Y-m-d', mktime(0, 0, 0, $mesAtual, $diaVenc, $anoAtual));

        // Verifica se já existe fatura para este mês
        $stmtFat = $db->prepare("
            SELECT id, status FROM faturas
            WHERE cliente_id = ?
              AND YEAR(data_vencimento) = ?
              AND MONTH(data_vencimento) = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmtFat->execute([$clienteId, $anoAtual, $mesAtual]);
        $faturaExistente = $stmtFat->fetch();

        if ($statusMK === 'paid' || $statusMK === 'paid_advance') {
            // Cliente está pago: garante que a fatura do mês está marcada como paga
            if ($faturaExistente) {
                if ($faturaExistente['status'] !== 'pago') {
                    $db->prepare("UPDATE faturas SET status = 'pago', data_pagamento = NOW() WHERE id = ?")->execute([$faturaExistente['id']]);
                    $resultado['faturas_marcadas_pagas']++;
                    logSync("    Fatura #{$faturaExistente['id']} marcada como PAGA.");
                }
            } else {
                // Cria fatura já paga (histórico)
                $descricao = 'Mensalidade ' . date('m/Y', mktime(0, 0, 0, $mesAtual, 1, $anoAtual));
                $db->prepare("
                    INSERT INTO faturas (cliente_id, plano_id, valor, data_vencimento, status, data_pagamento, descricao, meses_cobertos)
                    VALUES (?, ?, ?, ?, 'pago', NOW(), ?, 1)
                ")->execute([$clienteId, $cliente['plano_id'], $valorPlano, $dataVencimento, $descricao]);
                $resultado['faturas_criadas']++;
                $resultado['faturas_marcadas_pagas']++;
                logSync("    Fatura criada como PAGA (status Espera/Pago no MK).");
            }

            // Se for 'paid_advance', garante que o próximo mês também tem entrada no histórico como 'paid'
            if ($statusMK === 'paid_advance') {
                $mesSeguinte = $mesAtual === 12 ? 1 : $mesAtual + 1;
                $anoSeguinte = $mesAtual === 12 ? $anoAtual + 1 : $anoAtual;
                $db->prepare("
                    INSERT INTO mikrotik_payment_history (cliente_id, ano, mes, status, valor)
                    VALUES (?, ?, ?, 'paid', ?)
                    ON DUPLICATE KEY UPDATE status = 'paid'
                ")->execute([$clienteId, $anoSeguinte, $mesSeguinte, $valorPlano]);
                logSync("    Pago adiantado: mês {$mesSeguinte}/{$anoSeguinte} também marcado.");
            }

        } else {
            // Cliente em 'warning' ou 'overdue': garante fatura aberta para que ele possa pagar
            if (!$faturaExistente) {
                $statusFatura = ($statusMK === 'overdue') ? 'atrasado' : 'pendente';
                $descricao    = 'Mensalidade ' . date('m/Y', mktime(0, 0, 0, $mesAtual, 1, $anoAtual));
                $db->prepare("
                    INSERT INTO faturas (cliente_id, plano_id, valor, data_vencimento, status, descricao, meses_cobertos)
                    VALUES (?, ?, ?, ?, ?, ?, 1)
                ")->execute([$clienteId, $cliente['plano_id'], $valorPlano, $dataVencimento, $statusFatura, $descricao]);
                $resultado['faturas_criadas']++;
                logSync("    Fatura criada com status '{$statusFatura}' (R$ {$valorPlano}).");
            } else {
                // Sincroniza status da fatura com o MK (warning→pendente, overdue→atrasado)
                if ($statusMK === 'overdue' && $faturaExistente['status'] === 'pendente') {
                    $db->prepare("UPDATE faturas SET status = 'atrasado' WHERE id = ?")->execute([$faturaExistente['id']]);
                    logSync("    Fatura #{$faturaExistente['id']} atualizada para ATRASADO.");
                }
            }
        }

        $resultado['clientes_sincronizados']++;
    }
}

$tempoTotal = round(microtime(true) - $inicioExecucao, 2);
logSync("=== SINCRONIZAÇÃO CONCLUÍDA em {$tempoTotal}s ===");
logSync("  Clientes sincronizados : {$resultado['clientes_sincronizados']}");
logSync("  Faturas criadas        : {$resultado['faturas_criadas']}");
logSync("  Faturas marcadas pagas : {$resultado['faturas_marcadas_pagas']}");
logSync("  Erros                  : {$resultado['erros']}");

// Grava no log do sistema
Database::log('mikrotik', "Sync automático: {$resultado['clientes_sincronizados']} clientes, {$resultado['faturas_criadas']} faturas criadas, {$resultado['faturas_marcadas_pagas']} marcadas pagas.", null);

if (PHP_SAPI !== 'cli') {
    echo json_encode(array_merge($resultado, [
        'tempo_execucao_s' => $tempoTotal,
        'logs' => $logs
    ]), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
