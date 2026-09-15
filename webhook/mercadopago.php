<?php
/**
 * webhook/mercadopago.php
 * 
 * Webhook do Mercado Pago — chamado automaticamente quando um PIX é pago.
 * 
 * Ao aprovar:
 *  1. Marca fatura(s) como 'pago'
 *  2. Atualiza mikrotik_payment_history para cada mês coberto
 *  3. Muda profile PPPoE no MikroTik para /Espera (ou /Pago se pago adiantado)
 *  4. Atualiza clientes.status = 'ativo'
 *  5. Notifica via WhatsApp e Telegram
 */
require_once __DIR__ . '/../config.php';

$jsonInput = file_get_contents('php://input');
$dataInput = json_decode($jsonInput, true);
Database::log('webhook', 'MP notification', ['get' => $_GET, 'body' => $dataInput]);

$paymentId = $_REQUEST['id'] ?? $_GET['id'] ?? $_GET['data_id'] ?? $dataInput['data']['id'] ?? null;

if (!$paymentId) {
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'reason' => 'sem id']);
    exit;
}

$db       = Database::getInstance();
$stmtCfg  = $db->query("SELECT mp_access_token FROM configuracoes WHERE id = 1");
$cfg      = $stmtCfg->fetch();
$accessToken = trim($cfg['mp_access_token'] ?? '');

if (empty($accessToken)) {
    http_response_code(200);
    echo json_encode(['status' => 'error', 'msg' => 'token vazio']);
    exit;
}

// Consulta o pagamento na API do MP
$ch = curl_init("https://api.mercadopago.com/v1/payments/$paymentId");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'accept: application/json',
        'content-type: application/json',
        'Authorization: Bearer ' . $accessToken
    ],
    CURLOPT_TIMEOUT => 10
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);
Database::log('webhook', "Consulta MP $paymentId HTTP $httpCode", ['resp' => substr($response, 0, 1000)]);

if (!isset($data['external_reference'])) {
    http_response_code(200);
    echo json_encode(['status' => 'ignored']);
    exit;
}

$externalReference = $data['external_reference'];
$status            = $data['status'];
$valuePayment      = (float)($data['transaction_amount'] ?? 0);

// ─── Busca a fatura principal pelo external_reference ────────────────────────
$stmt = $db->prepare("SELECT id, status, valor, cliente_id, plano_id, meses_cobertos FROM faturas WHERE external_reference = :ext LIMIT 1");
$stmt->execute([':ext' => $externalReference]);
$faturaDb = $stmt->fetch(PDO::FETCH_ASSOC);

// Fallback: tenta extrair ID do external_reference (formato FATURA_123_...)
if (!$faturaDb && preg_match('/FATURA_(\d+)_/', $externalReference, $m)) {
    $fid  = (int)$m[1];
    $stmt = $db->prepare("SELECT id, status, valor, cliente_id, plano_id, meses_cobertos FROM faturas WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $fid]);
    $faturaDb = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$faturaDb) {
    Database::log('webhook', "Fatura não encontrada ext $externalReference", $data);
    http_response_code(200);
    echo json_encode(['status' => 'not_found']);
    exit;
}

// ─── Aprovado: processa o pagamento completo ─────────────────────────────────
if ($status === 'approved') {

    // PREVENÇÃO DE NOTIFICAÇÕES DUPLICADAS:
    // O Mercado Pago envia múltiplas notificações para o mesmo pagamento (created, updated, etc).
    // Se a fatura já estiver com status 'pago', respondemos 200 OK imediatamente para não reenviar WhatsApp/Telegram.
    if (($faturaDb['status'] ?? '') === 'pago') {
        Database::log('webhook', "Fatura #{$faturaDb['id']} já estava processada como PAGA. Notificação duplicada ignorada.");
        http_response_code(200);
        echo json_encode([
            'status'    => 'already_paid',
            'fatura_id' => $faturaDb['id'],
            'message'   => 'Fatura já se encontra paga. Notificações repetidas ignoradas.'
        ]);
        exit;
    }

    $clienteId    = (int)$faturaDb['cliente_id'];
    $mesesCobertos = max(1, (int)($faturaDb['meses_cobertos'] ?? 1));

    // 1. Marca a fatura principal como paga (com trava atômica via rowCount)
    $stmtUpMain = $db->prepare("
        UPDATE faturas SET status = 'pago', data_pagamento = NOW(), gateway_status = 'approved'
        WHERE id = :id AND status != 'pago'
    ");
    $stmtUpMain->execute([':id' => $faturaDb['id']]);

    // Se nenhuma linha foi alterada, outra requisição concorrente já marcou como pago
    if ($stmtUpMain->rowCount() === 0) {
        Database::log('webhook', "Fatura #{$faturaDb['id']} foi baixada simultaneamente por outra requisição. Notificações repetidas ignoradas.");
        http_response_code(200);
        echo json_encode([
            'status'    => 'already_paid',
            'fatura_id' => $faturaDb['id'],
            'message'   => 'Fatura já processada por requisição simultânea.'
        ]);
        exit;
    }

    // 2. Marca faturas vinculadas (pagamento multi-meses) como pagas
    $db->prepare("
        UPDATE faturas SET status = 'pago', data_pagamento = NOW(), gateway_status = 'approved'
        WHERE external_reference = ? AND status != 'pago'
    ")->execute(["VINCULADA_{$faturaDb['id']}"]);

    // 3. Busca dados completos do cliente + plano
    $stmtF = $db->prepare("
        SELECT f.*, c.nome, c.whatsapp, c.pppoe_usuario, c.roteador_id,
               p.profile_mikrotik, p.dias_validade, p.valor as plano_valor, p.nome as plano_nome
        FROM faturas f
        JOIN clientes c ON f.cliente_id = c.id
        LEFT JOIN planos p ON f.plano_id = p.id
        WHERE f.id = ?
    ");
    $stmtF->execute([$faturaDb['id']]);
    $fat = $stmtF->fetch();

    if ($fat) {
        // 4. Registra no histórico de pagamentos
        try {
            $db->prepare("
                INSERT INTO historico_pagamentos (cliente_id, fatura_id, valor, forma_pagamento, comprovante_ref)
                VALUES (?, ?, ?, 'PIX', ?)
            ")->execute([$fat['cliente_id'], $fat['id'], $fat['valor'], 'MP_' . $paymentId]);
        } catch (Exception $e) {}

        // 5. Atualiza mikrotik_payment_history para cada mês coberto
        $vencDate  = $fat['data_vencimento'] ?? date('Y-m-d');
        $mes       = (int)date('n', strtotime($vencDate));
        $ano       = (int)date('Y', strtotime($vencDate));
        $valorPlano = (float)($fat['plano_valor'] ?? $fat['valor']);

        for ($i = 0; $i < $mesesCobertos; $i++) {
            $db->prepare("
                INSERT INTO mikrotik_payment_history (cliente_id, ano, mes, status, valor)
                VALUES (?, ?, ?, 'paid', ?)
                ON DUPLICATE KEY UPDATE status = 'paid', valor = VALUES(valor)
            ")->execute([$clienteId, $ano, $mes, $valorPlano]);

            // Avança um mês
            if ($mes === 12) { $mes = 1; $ano++; }
            else { $mes++; }
        }

        // 6. Determina o profile de destino no MikroTik
        // - Se pagou apenas o mês atual (1 mês) → vai para /Espera
        // - Se pagou mais de um mês (adiantado) → vai para /Pago (MK irá mover para Espera no dia 01)
        $profileAlvo = $fat['profile_mikrotik']; // /Espera por padrão
        
        // Tenta detectar profile /Pago se existir (substituindo /Espera por /Pago no nome)
        if ($mesesCobertos > 1 && !empty($fat['profile_mikrotik'])) {
            $profilePago = str_ireplace('/Espera', '/Pago', $fat['profile_mikrotik']);
            // Só usa /Pago se for diferente do original (ou seja, se /Espera existia no nome)
            if ($profilePago !== $fat['profile_mikrotik']) {
                $profileAlvo = $profilePago;
            }
        }

        // 7. Muda profile no MikroTik
        if (!empty($profileAlvo) && !empty($fat['pppoe_usuario'])) {
            try {
                $mk = new MikrotikAPI($fat['roteador_id'] ?? 1);
                $mk->changeSecretProfile($fat['pppoe_usuario'], $profileAlvo);
                $mk->disconnectActiveSession($fat['pppoe_usuario']); // kick para reconectar com novo profile
                Database::log('mikrotik', "Webhook pagamento: {$fat['pppoe_usuario']} → {$profileAlvo} ({$mesesCobertos} meses)");
            } catch (Exception $e) {
                Database::log('mikrotik', 'Erro webhook changeProfile: ' . $e->getMessage());
                try {
                    $tg = new TelegramService();
                    $tg->notifyMikrotikError($fat['pppoe_usuario'], $profileAlvo, $e->getMessage());
                } catch (Exception $te) {}
            }
        }

        // 8. Atualiza data de expiração do cliente
        $dias    = (int)($fat['dias_validade'] ?? 30);
        $diasTotais = $dias * $mesesCobertos;
        $novaExp = date('Y-m-d', strtotime("+{$diasTotais} days"));
        $db->prepare("UPDATE clientes SET status = 'ativo', data_expiracao = ? WHERE id = ?")->execute([$novaExp, $fat['cliente_id']]);

        // 9. Notificações
        try {
            $stmtCheckWa = $db->prepare("SELECT id FROM whatsapp_disparos WHERE fatura_id = ? AND tipo = 'confirmacao' AND status = 'enviado' LIMIT 1");
            $stmtCheckWa->execute([$fat['id']]);
            if (!$stmtCheckWa->fetch()) {
                $wa = new WhatsAppService();
                $wa->sendPaymentConfirmation($fat['whatsapp'], $fat['nome'], $fat['valor'], $fat['id'], $fat['plano_nome'] ?? '');
            }
        } catch (Exception $e) {}

        try {
            $tg = new TelegramService();
            $tg->notifyPaymentReceived($fat['nome'], $fat['valor'], $fat['id']);
        } catch (Exception $e) {}

        // 9.5. Webhook Callback Customizado (POST)
        try {
            $wbc = new WebhookCallbackService();
            $wbc->sendPaymentNotification($fat['nome'], (float)$fat['valor'], $fat['id'], 'PIX (Mercado Pago)');
        } catch (Exception $e) {}
    }

    echo json_encode([
        'status'        => 'success',
        'fatura_id'     => $faturaDb['id'],
        'meses_pagos'   => $mesesCobertos,
        'profile_alvo'  => $profileAlvo ?? 'N/A'
    ]);
    exit;

} else {
    // Outros status (pending, rejected, etc): apenas atualiza gateway_status
    $db->prepare("UPDATE faturas SET gateway_status = :st WHERE id = :id")->execute([
        ':st' => $status,
        ':id' => $faturaDb['id']
    ]);
    echo json_encode(['status' => 'updated', 'mp_status' => $status]);
    exit;
}
