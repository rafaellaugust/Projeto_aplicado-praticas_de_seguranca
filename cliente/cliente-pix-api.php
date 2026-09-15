<?php
/**
 * cliente/cliente-pix-api.php
 * 
 * Gera PIX para uma fatura existente (identificada por fatura_id).
 * Não cria novas faturas — isso é feito por gerar-pix-multiplo.php ou pela página index.
 * 
 * POST params:
 *   fatura_id  (int, obrigatório)
 *   email      (string, obrigatório)
 * 
 * Retorna JSON: { success, qr_code, copia_cola, qr_code_base64, value, fatura_id }
 */

session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['cliente_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sessão expirada.']);
    exit;
}

$clienteId = (int)$_SESSION['cliente_id'];
$faturaId  = (int)($_POST['fatura_id'] ?? $_GET['fatura_id'] ?? 0);
$email     = trim($_POST['email'] ?? '');

if ($faturaId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID da fatura inválido.']);
    exit;
}

$db = Database::getInstance();

// Busca cliente
$stmtC = $db->prepare("SELECT c.*, p.nome as plano_nome, p.valor as plano_valor FROM clientes c LEFT JOIN planos p ON c.plano_id = p.id WHERE c.id = ?");
$stmtC->execute([$clienteId]);
$cliente = $stmtC->fetch();

if (!$cliente) {
    echo json_encode(['success' => false, 'message' => 'Cliente não encontrado.']);
    exit;
}

// Busca a fatura — deve pertencer ao cliente e estar pendente/atrasada
$stmtF = $db->prepare("SELECT * FROM faturas WHERE id = ? AND cliente_id = ?");
$stmtF->execute([$faturaId, $clienteId]);
$fatura = $stmtF->fetch();

if (!$fatura) {
    echo json_encode(['success' => false, 'message' => 'Fatura não encontrada ou não pertence ao cliente.']);
    exit;
}

if ($fatura['status'] === 'pago') {
    echo json_encode(['success' => false, 'message' => 'Esta fatura já foi paga.']);
    exit;
}

// Valida e-mail
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $email = !empty($cliente['email']) ? $cliente['email'] : "cliente{$clienteId}@spaconett.com";
}

$valorTotal    = (float)$fatura['valor'];
$mesesCobertos = max(1, (int)($fatura['meses_cobertos'] ?? 1));

try {
    $descPix = $mesesCobertos > 1
        ? "Internet {$mesesCobertos}x meses - " . ($cliente['plano_nome'] ?? 'Plano')
        : "Fatura #{$fatura['id']} - " . ($cliente['plano_nome'] ?? 'Internet');

    $gateway = new PaymentGateway();
    $pix     = $gateway->generatePixCharge(
        $fatura['id'],
        $valorTotal,
        $descPix,
        $email,
        $cliente['cpf_cnpj'] ?? ''
    );

    if (!$pix['success']) {
        echo json_encode(['success' => false, 'message' => $pix['message'] ?? 'Falha ao gerar PIX.']);
        exit;
    }

    // Atualiza a fatura com dados do PIX
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
        $fatura['id']
    ]);

    echo json_encode([
        'success'          => true,
        'fatura_id'        => $fatura['id'],
        'qr_code'          => $pix['qr_code'],
        'copia_cola'       => $pix['copia_cola'],
        'qr_code_base64'   => $pix['qr_code_base64'] ?? '',
        'ticket_url'       => $pix['ticket_url'] ?? '',
        'value'            => $valorTotal,
        'meses_cobertos'   => $mesesCobertos,
        'external_reference' => $pix['external_reference'],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    Database::log('gateway', 'Erro cliente-pix-api: ' . $e->getMessage(), []);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
