<?php
/**
 * payment/pix.php
 * 
 * Endpoint chamado pelo MacroDroid via GET para gerar PIX de cobrança.
 * 
 * Uso pelo MacroDroid:
 *   GET https://seu-dominio/payment/pix.php?client_name=NOME_DO_CLIENTE
 *   GET https://seu-dominio/payment/pix.php?pppoe=ppp7
 *   GET https://seu-dominio/payment/pix.php?id=42  (por fatura_id)
 * 
 * Retorna JSON com:
 *   success, qr_code (copia e cola), valor, cliente_nome, fatura_id
 */

require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // permite chamada externa (MacroDroid)

$db = Database::getInstance();

// ─── LÓGICA DE CONSULTA DE PAGAMENTOS (MacroDroid) ──────────────────────────
$action = $_GET['action'] ?? '';
$isConsulta = ($action === 'consultar' || $action === 'consulta' || isset($_GET['consultar']));

if ($isConsulta) {
    $cliente = null;
    $clienteId = (int)($_GET['cliente_id'] ?? $_GET['id'] ?? 0);
    $pppoe = trim($_GET['pppoe'] ?? '');
    $clientName = trim($_GET['client_name'] ?? '');

    if ($clienteId > 0) {
        $stmtC = $db->prepare("SELECT c.*, p.nome as plano_nome, p.valor as plano_valor FROM clientes c LEFT JOIN planos p ON c.plano_id = p.id WHERE c.id = ?");
        $stmtC->execute([$clienteId]);
        $cliente = $stmtC->fetch();
    } elseif (!empty($pppoe)) {
        $stmtC = $db->prepare("SELECT c.*, p.nome as plano_nome, p.valor as plano_valor FROM clientes c LEFT JOIN planos p ON c.plano_id = p.id WHERE c.pppoe_usuario = ?");
        $stmtC->execute([$pppoe]);
        $cliente = $stmtC->fetch();
    } elseif (!empty($clientName)) {
        $stmtC = $db->prepare("SELECT c.*, p.nome as plano_nome, p.valor as plano_valor FROM clientes c LEFT JOIN planos p ON c.plano_id = p.id WHERE c.nome LIKE ? LIMIT 1");
        $stmtC->execute(['%' . $clientName . '%']);
        $cliente = $stmtC->fetch();
    } else {
        $faturaId = (int)($_GET['fatura_id'] ?? 0);
        if ($faturaId > 0) {
            $stmtC = $db->prepare("SELECT c.*, p.nome as plano_nome, p.valor as plano_valor FROM faturas f JOIN clientes c ON f.cliente_id = c.id LEFT JOIN planos p ON c.plano_id = p.id WHERE f.id = ?");
            $stmtC->execute([$faturaId]);
            $cliente = $stmtC->fetch();
        }
    }

    if (!$cliente) {
        echo json_encode(['success' => false, 'message' => 'Cliente não encontrado para consulta.']);
        exit;
    }

    $mesAtual = (int)date('n');
    $anoAtual = (int)date('Y');

    // Busca status no histórico de pagamento (MikroTik)
    $stmtHist = $db->prepare("SELECT status FROM mikrotik_payment_history WHERE cliente_id = ? AND ano = ? AND mes = ?");
    $stmtHist->execute([$cliente['id'], $anoAtual, $mesAtual]);
    $statusCaixa = $stmtHist->fetchColumn() ?: 'npago';

    // Busca fatura do mês atual (paga ou pendente)
    $stmtF = $db->prepare("
        SELECT * FROM faturas 
        WHERE cliente_id = ? 
          AND YEAR(data_vencimento) = ? 
          AND MONTH(data_vencimento) = ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmtF->execute([$cliente['id'], $anoAtual, $mesAtual]);
    $fatura = $stmtF->fetch();

    echo json_encode([
        'success' => true,
        'action' => 'consulta',
        'cliente' => [
            'id' => (int)$cliente['id'],
            'nome' => $cliente['nome'],
            'pppoe' => $cliente['pppoe_usuario'],
            'whatsapp' => $cliente['whatsapp'],
            'status' => $cliente['status']
        ],
        'plano' => [
            'nome' => $cliente['plano_nome'],
            'valor' => (float)$cliente['plano_valor']
        ],
        'historico_mikrotik' => [
            'mes' => $mesAtual,
            'ano' => $anoAtual,
            'status' => $statusCaixa // paid, warning, overdue, npago
        ],
        'fatura_atual' => $fatura ? [
            'id' => (int)$fatura['id'],
            'valor' => (float)$fatura['valor'],
            'vencimento' => $fatura['data_vencimento'],
            'status' => $fatura['status'], // pago, pendente, atrasado
            'data_pagamento' => $fatura['data_pagamento'],
            'pix_copia_cola' => $fatura['pix_copia_cola']
        ] : null
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ─── Localiza o cliente (Fluxo normal de geração de PIX) ──────────────────────
$fatura   = null;
$cliente  = null;

// Opção 1: por fatura_id direto
$faturaId = (int)($_GET['id'] ?? $_GET['fatura_id'] ?? 0);
if ($faturaId > 0) {
    $stmtF = $db->prepare("
        SELECT f.*, c.nome, c.email, c.cpf_cnpj, c.pppoe_usuario,
               p.nome as plano_nome, p.valor as plano_valor
        FROM faturas f
        JOIN clientes c ON f.cliente_id = c.id
        LEFT JOIN planos p ON f.plano_id = p.id
        WHERE f.id = ? AND f.status != 'pago'
    ");
    $stmtF->execute([$faturaId]);
    $fatura = $stmtF->fetch();
}

// Opção 2: por nome do cliente (client_name) — usado pelo MacroDroid
if (!$fatura && !empty($_GET['client_name'])) {
    $nome = '%' . trim($_GET['client_name']) . '%';
    $stmtC = $db->prepare("
        SELECT c.id, c.nome, c.email, c.cpf_cnpj, c.pppoe_usuario, c.plano_id, c.vencimento_dia,
               p.nome as plano_nome, p.valor as plano_valor
        FROM clientes c
        LEFT JOIN planos p ON c.plano_id = p.id
        WHERE c.nome LIKE ? LIMIT 1
    ");
    $stmtC->execute([$nome]);
    $cliente = $stmtC->fetch();
}

// Opção 3: por usuário PPPoE (pppoe=ppp7) — mais preciso para MacroDroid
if (!$fatura && !$cliente && !empty($_GET['pppoe'])) {
    $pppoe = trim($_GET['pppoe']);
    $stmtC = $db->prepare("
        SELECT c.id, c.nome, c.email, c.cpf_cnpj, c.pppoe_usuario, c.plano_id, c.vencimento_dia,
               p.nome as plano_nome, p.valor as plano_valor
        FROM clientes c
        LEFT JOIN planos p ON c.plano_id = p.id
        WHERE c.pppoe_usuario = ? LIMIT 1
    ");
    $stmtC->execute([$pppoe]);
    $cliente = $stmtC->fetch();
}

// Se encontrou cliente mas não fatura, busca/cria a fatura pendente do mês
if (!$fatura && $cliente) {
    $mesAtual = (int)date('n');
    $anoAtual = (int)date('Y');

    // Busca fatura pendente existente do mês
    $stmtF = $db->prepare("
        SELECT * FROM faturas
        WHERE cliente_id = ?
          AND YEAR(data_vencimento) = ?
          AND MONTH(data_vencimento) = ?
          AND status != 'pago'
        ORDER BY id DESC LIMIT 1
    ");
    $stmtF->execute([$cliente['id'], $anoAtual, $mesAtual]);
    $fatura = $stmtF->fetch();

    // Se não tem fatura, cria uma
    if (!$fatura) {
        $diaVenc    = (int)($cliente['vencimento_dia'] ?? 10);
        $valorPlano = (float)($cliente['plano_valor'] ?? 0);
        $dataVenc   = date('Y-m-d', mktime(0, 0, 0, $mesAtual, $diaVenc, $anoAtual));
        $descricao  = 'Mensalidade ' . date('m/Y');

        try {
            $db->prepare("
                INSERT INTO faturas (cliente_id, plano_id, valor, data_vencimento, status, descricao, meses_cobertos)
                VALUES (?, ?, ?, ?, 'pendente', ?, 1)
            ")->execute([$cliente['id'], $cliente['plano_id'], $valorPlano, $dataVenc, $descricao]);
            $novaId = (int)$db->lastInsertId();

            $stmtF = $db->prepare("
                SELECT f.*, c.nome, c.email, c.cpf_cnpj, c.pppoe_usuario,
                       p.nome as plano_nome, p.valor as plano_valor
                FROM faturas f
                JOIN clientes c ON f.cliente_id = c.id
                LEFT JOIN planos p ON f.plano_id = p.id
                WHERE f.id = ?
            ");
            $stmtF->execute([$novaId]);
            $fatura = $stmtF->fetch();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Erro ao criar fatura: ' . $e->getMessage()]);
            exit;
        }
    } else {
        // Complementa dados do cliente na fatura (query JOIN)
        $stmtFull = $db->prepare("
            SELECT f.*, c.nome, c.email, c.cpf_cnpj, c.pppoe_usuario,
                   p.nome as plano_nome, p.valor as plano_valor
            FROM faturas f
            JOIN clientes c ON f.cliente_id = c.id
            LEFT JOIN planos p ON f.plano_id = p.id
            WHERE f.id = ?
        ");
        $stmtFull->execute([$fatura['id']]);
        $fatura = $stmtFull->fetch();
    }
}

if (!$fatura) {
    echo json_encode([
        'success' => false,
        'message' => 'Cliente ou fatura não encontrado. Use: ?client_name=NOME, ?pppoe=usuario ou ?id=FATURA_ID'
    ]);
    exit;
}

// ─── Gera o PIX ──────────────────────────────────────────────────────────────
// Se o PIX já existe e não expirou, retorna sem gerar novo
if (!empty($fatura['pix_copia_cola']) && !empty($fatura['expiration_date'])) {
    $expTs = strtotime($fatura['expiration_date']);
    if ($expTs > time()) {
        echo json_encode([
            'success'      => true,
            'fatura_id'    => $fatura['id'],
            'cliente_nome' => $fatura['nome'],
            'pppoe'        => $fatura['pppoe_usuario'],
            'valor'        => (float)$fatura['valor'],
            'vencimento'   => $fatura['data_vencimento'],
            'qr_code'      => $fatura['pix_copia_cola'],
            'copia_cola'   => $fatura['pix_copia_cola'],
            'expira_em'    => $fatura['expiration_date'],
            'reutilizado'  => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Gera novo PIX via gateway
try {
    $email   = !empty($fatura['email']) ? $fatura['email'] : "cliente{$fatura['cliente_id']}@spaconett.com";
    $descPix = "Mensalidade - " . ($fatura['plano_nome'] ?? 'Internet') . " - " . $fatura['nome'];

    $gateway = new PaymentGateway();
    $pix     = $gateway->generatePixCharge(
        $fatura['id'],
        (float)$fatura['valor'],
        $descPix,
        $email,
        $fatura['cpf_cnpj'] ?? ''
    );

    if (!$pix['success']) {
        echo json_encode(['success' => false, 'message' => $pix['message'] ?? 'Falha ao gerar PIX.']);
        exit;
    }

    // Salva dados do PIX na fatura
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
        'success'      => true,
        'fatura_id'    => (int)$fatura['id'],
        'cliente_nome' => $fatura['nome'],
        'pppoe'        => $fatura['pppoe_usuario'],
        'valor'        => (float)$fatura['valor'],
        'vencimento'   => $fatura['data_vencimento'],
        'qr_code'      => $pix['qr_code'],
        'copia_cola'   => $pix['copia_cola'],
        'expira_em'    => $pix['expiration_date'],
        'ticket_url'   => $pix['ticket_url'] ?? '',
        'reutilizado'  => false,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    Database::log('gateway', 'Erro pix.php MacroDroid: ' . $e->getMessage(), []);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
