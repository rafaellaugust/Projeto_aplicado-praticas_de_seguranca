<?php
/**
 * cliente/gerar-pix-multiplo.php
 * 
 * API endpoint: Gera PIX com valor total de N meses (1-6)
 * Chamada via POST (AJAX) da página index.php do cliente
 * 
 * POST params:
 *   quantidade_meses  (int 1-6)
 * 
 * Retorna JSON:
 *   { success, fatura_id, qr_code, copia_cola, valor_total, meses }
 */

session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['cliente_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sessão expirada.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método inválido.']);
    exit;
}

$clienteId      = (int)$_SESSION['cliente_id'];
$quantMeses     = max(1, min(6, (int)($_POST['quantidade_meses'] ?? 1)));
$email          = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $_POST['email'] : '';

$db = Database::getInstance();

// Busca dados do cliente e plano
$stmt = $db->prepare("
    SELECT c.*, p.nome as plano_nome, p.valor as plano_valor, p.profile_mikrotik
    FROM clientes c
    LEFT JOIN planos p ON c.plano_id = p.id
    WHERE c.id = ?
");
$stmt->execute([$clienteId]);
$cliente = $stmt->fetch();

if (!$cliente) {
    echo json_encode(['success' => false, 'message' => 'Cliente não encontrado.']);
    exit;
}

$valorPlano  = (float)($cliente['plano_valor'] ?? 0);
$valorTotal  = round($valorPlano * $quantMeses, 2);
$diaVenc     = (int)($cliente['vencimento_dia'] ?? 10);
$mesAtual    = (int)date('n');
$anoAtual    = (int)date('Y');

if ($valorTotal <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valor do plano inválido.']);
    exit;
}

// ─── Determina a partir de qual mês gerar faturas ────────────────────────────
// Verifica status atual no histórico do MK para saber se começa no mês atual ou no próximo
$stmtHist = $db->prepare("SELECT status FROM mikrotik_payment_history WHERE cliente_id=? AND ano=? AND mes=?");
$stmtHist->execute([$clienteId, $anoAtual, $mesAtual]);
$statusAtual = $stmtHist->fetchColumn();

// Se já está 'paid' no mês atual, começa a gerar do próximo mês (pagamento adiantado)
// Se está em 'warning' ou 'overdue', começa do mês atual
$iniciaMes = $mesAtual;
$iniciaAno = $anoAtual;

if ($statusAtual === 'paid') {
    // Verifica se já tem fatura paga deste mês → começa do próximo
    $stmtFatPaga = $db->prepare("
        SELECT id FROM faturas 
        WHERE cliente_id=? AND YEAR(data_vencimento)=? AND MONTH(data_vencimento)=? AND status='pago'
        LIMIT 1
    ");
    $stmtFatPaga->execute([$clienteId, $anoAtual, $mesAtual]);
    if ($stmtFatPaga->fetchColumn()) {
        // Já pagou este mês, adiantamento começa no próximo
        $iniciaMes = $mesAtual === 12 ? 1 : $mesAtual + 1;
        $iniciaAno = $mesAtual === 12 ? $anoAtual + 1 : $anoAtual;
    }
}

// ─── Cria/busca as faturas de cada mês ───────────────────────────────────────
$faturaIds    = [];
$mesesCobertos = [];
$mes           = $iniciaMes;
$ano           = $iniciaAno;

for ($i = 0; $i < $quantMeses; $i++) {
    $dataVenc  = date('Y-m-d', mktime(0, 0, 0, $mes, $diaVenc, $ano));
    $descricao = 'Mensalidade ' . date('m/Y', mktime(0, 0, 0, $mes, 1, $ano));

    // Verifica se já existe fatura pendente/atrasada para este mês
    $stmtExist = $db->prepare("
        SELECT id, status FROM faturas
        WHERE cliente_id=? AND YEAR(data_vencimento)=? AND MONTH(data_vencimento)=?
        ORDER BY id DESC LIMIT 1
    ");
    $stmtExist->execute([$clienteId, $ano, $mes]);
    $fatExist = $stmtExist->fetch();

    if ($fatExist && $fatExist['status'] === 'pago') {
        // Mês já pago, não precisa de nova fatura – avança
        $mesesCobertos[] = ['mes' => $mes, 'ano' => $ano, 'fatura_id' => $fatExist['id'], 'ja_pago' => true];
    } elseif ($fatExist) {
        // Fatura pendente existente — usa ela
        $faturaIds[]     = $fatExist['id'];
        $mesesCobertos[] = ['mes' => $mes, 'ano' => $ano, 'fatura_id' => $fatExist['id'], 'ja_pago' => false];
    } else {
        // Cria nova fatura para este mês
        $db->prepare("
            INSERT INTO faturas (cliente_id, plano_id, valor, data_vencimento, status, descricao, meses_cobertos)
            VALUES (?, ?, ?, ?, 'pendente', ?, 1)
        ")->execute([$clienteId, $cliente['plano_id'], $valorPlano, $dataVenc, $descricao]);
        $novaFaturaId = (int)$db->lastInsertId();
        $faturaIds[]     = $novaFaturaId;
        $mesesCobertos[] = ['mes' => $mes, 'ano' => $ano, 'fatura_id' => $novaFaturaId, 'ja_pago' => false];
    }

    // Avança para o próximo mês
    if ($mes === 12) { $mes = 1; $ano++; }
    else { $mes++; }
}

// Filtra apenas os IDs que realmente precisam ser pagos
$faturaIdsParaPagar = array_filter($faturaIds, fn($id) => $id > 0);

if (empty($faturaIdsParaPagar)) {
    echo json_encode(['success' => false, 'message' => 'Todos os meses selecionados já estão pagos.']);
    exit;
}

// ─── Cria uma fatura consolidada para o PIX (representa o total) ──────────────
// A "fatura principal" é a do mês mais antigo a ser pago
$faturaIdPrincipal = reset($faturaIdsParaPagar);

// Atualiza a fatura principal para refletir o valor total e os meses cobertos
$idsJson = implode(',', array_values($faturaIdsParaPagar));
$db->prepare("
    UPDATE faturas 
    SET valor = ?, meses_cobertos = ?, descricao = ?
    WHERE id = ?
")->execute([
    $valorTotal,
    $quantMeses,
    "Pagamento {$quantMeses} mês(es) - PIX consolidado",
    $faturaIdPrincipal
]);

// Grava nos demais faturas o ID da fatura principal (para o webhook saber quais marcar como pagas)
foreach ($faturaIdsParaPagar as $fid) {
    if ($fid !== $faturaIdPrincipal) {
        $db->prepare("UPDATE faturas SET external_reference = ? WHERE id = ?")->execute(["VINCULADA_{$faturaIdPrincipal}", $fid]);
    }
}

// ─── Gera o PIX via Mercado Pago ──────────────────────────────────────────────
try {
    if (empty($email)) {
        $email = !empty($cliente['email']) ? $cliente['email'] : "cliente{$clienteId}@spaconett.com";
    }

    $descPix   = "Internet {$quantMeses}x - " . ($cliente['plano_nome'] ?? 'Plano');
    $gateway   = new PaymentGateway();
    $pix       = $gateway->generatePixCharge(
        $faturaIdPrincipal,
        $valorTotal,
        $descPix,
        $email,
        $cliente['cpf_cnpj'] ?? ''
    );

    if (!$pix['success']) {
        echo json_encode(['success' => false, 'message' => $pix['message'] ?? 'Falha ao gerar PIX.']);
        exit;
    }

    // Atualiza a fatura principal com dados do PIX
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
        $pix['qr_code_base64'],
        $pix['gateway_id'],
        $pix['mp_payment_id'],
        date('Y-m-d H:i:s', strtotime($pix['expiration_date'])),
        $faturaIdPrincipal
    ]);

    echo json_encode([
        'success'          => true,
        'fatura_id'        => $faturaIdPrincipal,
        'qr_code'          => $pix['qr_code'],
        'copia_cola'       => $pix['copia_cola'],
        'qr_code_base64'   => $pix['qr_code_base64'] ?? '',
        'valor_total'      => $valorTotal,
        'quantidade_meses' => $quantMeses,
        'meses_cobertos'   => $mesesCobertos,
        'ticket_url'       => $pix['ticket_url'] ?? '',
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    Database::log('gateway', 'Erro gerar-pix-multiplo: ' . $e->getMessage(), []);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
