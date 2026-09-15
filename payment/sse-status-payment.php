<?php
/**
 * sse-status-payment.php ADAPTADO do antigo para faturas
 * - Mantém SSE EventSource
 * - Checa faturas WHERE external_reference = X AND status = 'pago' (antes era pagamento)
 */

require_once __DIR__ . '/../config.php';

if (!isset($_GET['external_reference']) && !isset($_GET['fatura_id'])) {
    die;
}

ob_start();
header("Content-Type: text/event-stream");
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$externalReference = isset($_GET['external_reference']) ? htmlspecialchars(strip_tags($_GET['external_reference'])) : null;
$faturaId = isset($_GET['fatura_id']) ? (int)$_GET['fatura_id'] : 0;

$db = Database::getInstance();

while (true) {
    $isApproved = false;

    if ($externalReference) {
        $stmt = $db->prepare("SELECT id FROM faturas WHERE external_reference = :ext AND status = 'pago'");
        $stmt->bindValue(':ext', $externalReference);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $isApproved = isset($row['id']);
    } elseif ($faturaId) {
        $stmt = $db->prepare("SELECT id FROM faturas WHERE id = :id AND status = 'pago'");
        $stmt->bindValue(':id', $faturaId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $isApproved = isset($row['id']);
    }

    echo "event: statusPayment\n";
    echo "data: " . ($isApproved ? '1' : '0') . "\n\n";

    ob_end_flush();
    flush();

    if ($isApproved) {
        break;
    }

    sleep(3);
}
