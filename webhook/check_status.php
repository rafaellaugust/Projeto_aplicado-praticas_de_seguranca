<?php
/**
 * webhook/check_status.php - Polling para cliente
 * Estrutura: Novo_projeto/webhook/
 * Chamado por: fetch('webhook/check_status.php?id=1') ou cliente/fatura.php
 */
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$faturaId = (int)($_GET['id'] ?? $_GET['fatura_id'] ?? 0);
$externalRef = $_GET['external_reference'] ?? null;

if ($faturaId <= 0 && empty($externalRef)) {
    echo json_encode(['status'=>'nao_encontrado']);
    exit;
}

$db = Database::getInstance();

if ($externalRef) {
    $stmt = $db->prepare("SELECT id, status, data_pagamento, valor FROM faturas WHERE external_reference = ?");
    $stmt->execute([$externalRef]);
} else {
    $stmt = $db->prepare("SELECT id, status, data_pagamento, valor FROM faturas WHERE id = ?");
    $stmt->execute([$faturaId]);
}

$fat = $stmt->fetch();

if ($fat) {
    echo json_encode(['status'=>$fat['status'], 'id'=>$fat['id'], 'data_pagamento'=>$fat['data_pagamento'], 'valor'=>$fat['valor']]);
    exit;
}

echo json_encode(['status'=>'nao_encontrado']);
