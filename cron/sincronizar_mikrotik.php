<?php
/**
 * cron/sincronizar_mikrotik.php
 * 
 * Sincronização automática MikroTik → Sistema
 * Lógica baseada exatamente na ação 'sincronizar_mikrotik' do caixa.php
 * 
 * Rodar a cada 30 minutos:
 *  * /30 * * * * php /caminho/para/cron/sincronizar_mikrotik.php >> /var/log/mikropay_sync.log 2>&1
 * 
 * Ou via URL:
 *  https://seusite.com/cron/sincronizar_mikrotik.php
 */

require_once __DIR__ . '/../config.php';

// Se acessado via navegador web / HTTP externo, exigir token seguro configurado ou sessão administrativa
if (PHP_SAPI !== 'cli') {
    $tokenInformado = $_GET['key'] ?? ($_GET['token'] ?? '');

    // Suporte também a Bearer token via Header HTTP Authorization
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (empty($authHeader) && function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        $authHeader = $hdrs['Authorization'] ?? '';
    }
    $bearerToken = trim(str_replace('Bearer ', '', $authHeader));
    $tokenChecar = !empty($tokenInformado) ? $tokenInformado : $bearerToken;

    $cronSecret = getenv('CRON_TOKEN') ?: '';

    // Busca o wa_token do banco para manter o mesmo padrão de segurança dos outros crons
    $waToken = '';
    try {
        if (class_exists('Database')) {
            $dbCheck = Database::getInstance();
            $waToken = $dbCheck->query("SELECT wa_token FROM configuracoes WHERE id = 1")->fetchColumn() ?: '';
        }
    } catch (\Throwable $e) {}

    $autorizado = false;
    if (!empty($tokenChecar)) {
        if (!empty($cronSecret) && hash_equals($cronSecret, $tokenChecar)) {
            $autorizado = true;
        } elseif (!empty($waToken) && hash_equals($waToken, $tokenChecar)) {
            $autorizado = true;
        }
    }

    if (!$autorizado && !empty($_SESSION['admin_id'])) {
        $autorizado = true;
    }

    if (!$autorizado) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode([
            'status' => 'error',
            'error'  => 'Acesso não autorizado à sincronização MikroTik. Informe o token correto via URL (?key=...) ou header Authorization: Bearer.'
        ], JSON_UNESCAPED_UNICODE));
    }
    header('Content-Type: application/json; charset=utf-8');
}

$inicioExecucao = microtime(true);
$logs = [];
$resultado = [
    'clientes_sincronizados' => 0,
    'faturas_criadas'        => 0,
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

logSync("=== INICIANDO SINCRONIZAÇÃO MIKROTIK → SISTEMA (LOGICA DO CAIXA) ===");

try {
    $db = Database::getInstance();
    $mkApi = new MikrotikAPI();
    $secrets = $mkApi->getSecrets();

    if (!empty($secrets)) {
        $mesAtual = (int)date('n');
        $anoAtual = (int)date('Y');
        logSync("Conectado ao MikroTik. Total de segredos: " . count($secrets));

        foreach ($secrets as $sec) {
            $user = sanitize($sec['name'] ?? '');
            $profile = sanitize($sec['profile'] ?? '');
            if (empty($user)) continue;

            $stmtC = $db->prepare("SELECT c.id, c.plano_id, c.vencimento_dia, p.valor as plano_valor FROM clientes c LEFT JOIN planos p ON c.plano_id=p.id WHERE c.pppoe_usuario=?");
            $stmtC->execute([$user]);
            $cli = $stmtC->fetch();
            if (!$cli) continue;

            // 4 estados: /Pago=paid_advance, /Espera=paid, /Aviso=warning, /Bloqueado=overdue
            $lower = strtolower($profile);
            $statusHistory = 'paid';
            if (strpos($lower, 'bloqueado') !== false) {
                $statusHistory = 'overdue';
            } elseif (strpos($lower, 'aviso') !== false) {
                $statusHistory = 'warning';
            } elseif (strpos($lower, '/pago') !== false || substr($lower, -5) === '/pago') {
                $statusHistory = 'paid';
            } elseif (strpos($lower, 'espera') !== false) {
                $statusHistory = 'paid';
            }

            $valorP = (float)($cli['plano_valor'] ?? 0);

            // Verifica se já existe registro no histórico para este cliente neste ano/mês
            $stmtHist = $db->prepare("SELECT id FROM mikrotik_payment_history WHERE cliente_id = ? AND ano = ? AND mes = ? LIMIT 1");
            $stmtHist->execute([$cli['id'], $anoAtual, $mesAtual]);
            $histId = $stmtHist->fetchColumn();

            if ($histId) {
                // Registro existente: apenas atualiza o status, valor e timestamp (NUNCA duplica)
                $db->prepare("UPDATE mikrotik_payment_history SET status = ?, valor = ?, data_atualizacao = NOW() WHERE id = ?")
                   ->execute([$statusHistory, $valorP, $histId]);
            } else {
                // Novo registro: insere com salvaguarda ON DUPLICATE KEY
                $db->prepare("INSERT INTO mikrotik_payment_history (cliente_id, ano, mes, status, valor, data_atualizacao) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE status = VALUES(status), valor = VALUES(valor), data_atualizacao = NOW()")
                   ->execute([$cli['id'], $anoAtual, $mesAtual, $statusHistory, $valorP]);
            }

            // Atualiza status operacional do cliente na tabela clientes
            $novoStatusCli = 'ativo';
            if ($statusHistory === 'overdue') {
                $novoStatusCli = 'suspenso';
            } elseif ($statusHistory === 'warning') {
                $novoStatusCli = 'aviso';
            }
            $db->prepare("UPDATE clientes SET status = ? WHERE id = ?")->execute([$novoStatusCli, $cli['id']]);

            // Cria fatura se cliente em aviso/overdue e não tem fatura do mês
            if ($statusHistory === 'warning' || $statusHistory === 'overdue') {
                $stmtFat = $db->prepare("SELECT id, status FROM faturas WHERE cliente_id=? AND YEAR(data_vencimento)=? AND MONTH(data_vencimento)=? LIMIT 1");
                $stmtFat->execute([$cli['id'], $anoAtual, $mesAtual]);
                $fatExistente = $stmtFat->fetch();

                if (!$fatExistente) {
                    $diaVenc = (int)($cli['vencimento_dia'] ?? 10);
                    $dataVenc = date('Y-m-d', mktime(0, 0, 0, $mesAtual, $diaVenc, $anoAtual));
                    $stFat = ($statusHistory === 'overdue') ? 'atrasado' : 'pendente';
                    try {
                        $db->prepare("INSERT INTO faturas (cliente_id, plano_id, valor, data_vencimento, status, descricao, meses_cobertos) VALUES (?,?,?,?,?,?,1)")->execute([$cli['id'], $cli['plano_id'], $valorP, $dataVenc, $stFat, 'Mensalidade ' . date('m/Y')]);
                        $resultado['faturas_criadas']++;
                    } catch (Exception $e) {
                        logSync("Erro ao criar fatura para {$user}: " . $e->getMessage());
                    }
                } elseif ($statusHistory === 'overdue' && $fatExistente['status'] === 'pendente') {
                    // Atualiza fatura para atrasado se cliente foi bloqueado no MikroTik
                    $db->prepare("UPDATE faturas SET status = 'atrasado' WHERE id = ?")->execute([$fatExistente['id']]);
                }
            }
            $resultado['clientes_sincronizados']++;
        }
        logSync("Sincronização concluída! {$resultado['clientes_sincronizados']} clientes, {$resultado['faturas_criadas']} fatura(s) criada(s).");
    } else {
        $errorMsg = "Falha ao conectar no MikroTik ou nenhum segredo encontrado: " . $mkApi->getLastError();
        logSync("ERRO: " . $errorMsg);
        $resultado['erros']++;
    }
} catch (Exception $e) {
    logSync("ERRO CRÍTICO: " . $e->getMessage());
    $resultado['erros']++;
}

$tempoTotal = round(microtime(true) - $inicioExecucao, 2);
logSync("=== SINCRONIZAÇÃO CONCLUÍDA em {$tempoTotal}s ===");

// Grava no log do sistema
try {
    Database::log('mikrotik', "Sync automático (cron): {$resultado['clientes_sincronizados']} clientes, {$resultado['faturas_criadas']} faturas criadas.", null);
} catch (Exception $e) {}

if (PHP_SAPI !== 'cli') {
    echo json_encode(array_merge($resultado, [
        'tempo_execucao_s' => $tempoTotal,
        'logs' => $logs
    ]), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
