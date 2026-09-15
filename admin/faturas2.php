<?php
require_once __DIR__ . '/header.php';

$db = Database::getInstance();
$msgSuccess = '';
$msgError = '';

// --- AÇÕES ---

// 1. Gerar Nova Fatura PIX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'gerar_fatura') {
    $clienteId = (int)$_POST['cliente_id'];
    $valor = (float)str_replace(',', '.', $_POST['valor'] ?? '0');
    $dataVencimento = $_POST['data_vencimento'] ?? date('Y-m-d', strtotime('+5 days'));

    if ($clienteId && $valor > 0) {
        $stmtC = $db->prepare("SELECT * FROM clientes WHERE id = ?");
        $stmtC->execute([$clienteId]);
        $cliente = $stmtC->fetch();

        if ($cliente) {
            // Inserir fatura inicial
            $stmtIns = $db->prepare("
                INSERT INTO faturas (cliente_id, plano_id, valor, data_vencimento, status) 
                VALUES (?, ?, ?, ?, 'pendente')
            ");
            $stmtIns->execute([$clienteId, $cliente['plano_id'], $valor, $dataVencimento]);
            $faturaId = $db->lastInsertId();

            // Gerar cobrança PIX via PaymentGateway
            $gateway = new PaymentGateway();
            $pixData = $gateway->generatePixCharge($faturaId, $valor, "Fatura #{$faturaId} - Internet", $cliente['email'], $cliente['cpf_cnpj']);

            if ($pixData['success']) {
                $stmtUp = $db->prepare("
                    UPDATE faturas SET pix_txid = ?, pix_copia_cola = ?, pix_qr_code_base64 = ?, gateway_id = ? 
                    WHERE id = ?
                ");
                $stmtUp->execute([$pixData['txid'], $pixData['copia_cola'], $pixData['qr_code_base64'], $pixData['gateway_id'], $faturaId]);
            }

            $msgSuccess = "Fatura #{$faturaId} gerada com sucesso com PIX Copia e Cola!";
        }
    } else {
        $msgError = "Selecione o cliente e informe o valor válido.";
    }
}

// 2. Dar Baixa Manual na Fatura
if (isset($_GET['action']) && $_GET['action'] === 'baixar' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmtUp = $db->prepare("UPDATE faturas SET status = 'pago', data_pagamento = NOW() WHERE id = ?");
    $stmtUp->execute([$id]);

    // Enviar WhatsApp de Confirmação
    $stmtF = $db->prepare("
        SELECT f.*, c.nome, c.whatsapp 
        FROM faturas f 
        JOIN clientes c ON f.cliente_id = c.id 
        WHERE f.id = ?
    ");
    $stmtF->execute([$id]);
    $fat = $stmtF->fetch();

    if ($fat) {
        $wa = new WhatsAppService();
        $wa->sendPaymentConfirmation($fat['whatsapp'], $fat['nome'], $fat['valor'], $fat['id']);
    }

    $msgSuccess = "Fatura #{$id} marcada como PAGA e comprovante enviado via WhatsApp!";
}

// 3. Enviar Cobrança via WhatsApp
if (isset($_GET['action']) && $_GET['action'] === 'cobrar_wa' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmtF = $db->prepare("
        SELECT f.*, c.nome, c.whatsapp 
        FROM faturas f 
        JOIN clientes c ON f.cliente_id = c.id 
        WHERE f.id = ?
    ");
    $stmtF->execute([$id]);
    $fat = $stmtF->fetch();

    if ($fat) {
        $faturaUrl = BASE_URL . "/cliente/fatura.php?id=" . $fat['id'];
        $wa = new WhatsAppService();
        $res = $wa->sendInvoiceNotification($fat['whatsapp'], $fat['nome'], $fat['valor'], $fat['data_vencimento'], $fat['pix_copia_cola'], $faturaUrl);

        if ($res) {
            $db->exec("UPDATE faturas SET notificado_wa = 1 WHERE id = " . $id);
            $msgSuccess = "Cobrança enviada com sucesso no WhatsApp de {$fat['nome']}!";
        } else {
            $msgError = "Falha ao enviar WhatsApp. Verifique as configurações da API OpenWA.";
        }
    }
}

// Filtro de Status
$statusFilter = sanitize($_GET['status'] ?? '');
$sqlWhere = $statusFilter ? "WHERE f.status = '$statusFilter'" : "";

$faturas = $db->query("
    SELECT f.*, c.nome as cliente_nome, c.whatsapp as cliente_wa 
    FROM faturas f 
    JOIN clientes c ON f.cliente_id = c.id 
    $sqlWhere 
    ORDER BY f.id DESC
")->fetchAll();

$clientes = $db->query("SELECT id, nome, pppoe_usuario FROM clientes ORDER BY nome ASC")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="text-white fw-bold mb-1"><i class="fa-solid fa-file-invoice-dollar text-info me-2"></i>Gestão de Faturas</h4>
        <p class="text-secondary small mb-0">Emita faturas com PIX automático e envie diretamente para o WhatsApp do cliente.</p>
    </div>
    <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalNovaFatura">
        <i class="fa-solid fa-plus me-1"></i> Nova Fatura PIX
    </button>
</div>

<?php if ($msgSuccess): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-1"></i> <?= $msgSuccess ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($msgError): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= $msgError ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Filtros de Status -->
<div class="mb-3 d-flex gap-2">
    <a href="faturas.php" class="btn btn-sm <?= empty($statusFilter) ? 'btn-info' : 'btn-outline-light' ?>">Todas</a>
    <a href="faturas.php?status=pendente" class="btn btn-sm <?= $statusFilter === 'pendente' ? 'btn-warning' : 'btn-outline-light' ?>">Pendentes</a>
    <a href="faturas.php?status=pago" class="btn btn-sm <?= $statusFilter === 'pago' ? 'btn-success' : 'btn-outline-light' ?>">Pagas</a>
    <a href="faturas.php?status=atrasado" class="btn btn-sm <?= $statusFilter === 'atrasado' ? 'btn-danger' : 'btn-outline-light' ?>">Atrasadas</a>
</div>

<!-- Tabela de Faturas -->
<div class="card-custom">
    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th># ID</th>
                    <th>Cliente</th>
                    <th>Valor</th>
                    <th>Vencimento</th>
                    <th>Data Pagamento</th>
                    <th>Status</th>
                    <th>Notificação WA</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($faturas)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-secondary">Nenhuma fatura encontrada.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($faturas as $f): ?>
                        <tr>
                            <td>#<?= $f['id'] ?></td>
                            <td>
                                <strong><?= sanitize($f['cliente_nome']) ?></strong><br>
                                <span class="text-secondary small"><i class="fa-brands fa-whatsapp text-success"></i> <?= sanitize($f['cliente_wa']) ?></span>
                            </td>
                            <td class="fw-bold text-light"><?= formatMoeda($f['valor']) ?></td>
                            <td><?= formatData($f['data_vencimento']) ?></td>
                            <td><?= $f['data_pagamento'] ? formatData($f['data_pagamento']) : '-' ?></td>
                            <td>
                                <span class="badge-custom badge-<?= strtolower($f['status']) ?>">
                                    <?= ucfirst($f['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($f['notificado_wa']): ?>
                                    <span class="badge bg-success"><i class="fa-solid fa-check"></i> Enviado</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Não enviado</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="../cliente/fatura.php?id=<?= $f['id'] ?>" target="_blank" class="btn btn-sm btn-outline-info" title="Ver Fatura / PIX">
                                    <i class="fa-solid fa-qrcode"></i>
                                </a>
                                <?php if ($f['status'] !== 'pago'): ?>
                                    <a href="faturas.php?action=cobrar_wa&id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-success" title="Enviar Cobrança WhatsApp">
                                        <i class="fa-brands fa-whatsapp"></i>
                                    </a>
                                    <a href="faturas.php?action=baixar&id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-warning" onclick="return confirm('Confirmar baixa manual desta fatura?')" title="Dar Baixa Manual">
                                        <i class="fa-solid fa-check"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Nova Fatura -->
<div class="modal fade" id="modalNovaFatura" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark text-light border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-file-invoice-dollar text-info me-2"></i>Emitir Fatura PIX</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="gerar_fatura">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Cliente *</label>
                        <select name="cliente_id" class="form-control-custom" required>
                            <option value="">Selecione o Cliente...</option>
                            <?php foreach ($clientes as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= sanitize($c['nome']) ?> (PPPoE: <?= sanitize($c['pppoe_usuario']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Valor da Fatura (R$) *</label>
                        <input type="text" name="valor" class="form-control-custom" required placeholder="79.90">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Data de Vencimento *</label>
                        <input type="date" name="data_vencimento" class="form-control-custom" required value="<?= date('Y-m-d', strtotime('+5 days')) ?>">
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary-custom">Gerar Fatura & PIX</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
