<?php
require_once __DIR__ . '/header.php';

$db = Database::getInstance();
$msgSuccess = '';
$msgError = '';

try {
    $existingColsFat = array_column(
        $db->query("SHOW COLUMNS FROM faturas")->fetchAll(),
        'Field'
    );
    if (!in_array('notificado_aviso', $existingColsFat)) {
        $db->exec("ALTER TABLE faturas ADD COLUMN notificado_aviso TINYINT(1) DEFAULT 0");
    }
} catch (Exception $e) {}

// --- AÇÕES ---

// 1. Gerar Nova Fatura PIX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'gerar_fatura') {
    $clienteId = (int)$_POST['cliente_id'];
    $valor = (float)str_replace(',', '.', $_POST['valor'] ?? '0');
    $dataVencimento = $_POST['data_vencimento'] ?? date('Y-m-d', strtotime('+5 days'));
    $enviarWa = isset($_POST['enviar_wa']);

    if ($clienteId && $valor > 0) {
        $stmtC = $db->prepare("
            SELECT c.*, p.nome as plano_nome 
            FROM clientes c 
            LEFT JOIN planos p ON c.plano_id = p.id 
            WHERE c.id = ?
        ");
        $stmtC->execute([$clienteId]);
        $cliente = $stmtC->fetch();

        if ($cliente) {
            // Inserir fatura inicial
            $stmtIns = $db->prepare("
                INSERT INTO faturas (cliente_id, plano_id, valor, data_vencimento, status) 
                VALUES (?, ?, ?, ?, 'pendente')
            ");
            $stmtIns->execute([$clienteId, $cliente['plano_id'], $valor, $dataVencimento]);
            $faturaId = (int)$db->lastInsertId();

            // Gerar cobrança PIX via PaymentGateway
            $gateway = new PaymentGateway();
            $pixData = $gateway->generatePixCharge($faturaId, $valor, "Fatura #{$faturaId} - Internet", $cliente['email'], $cliente['cpf_cnpj']);

            $copiaCola = '';
            if (!empty($pixData['success'])) {
                $copiaCola = $pixData['copia_cola'] ?? '';
                $stmtUp = $db->prepare("
                    UPDATE faturas SET pix_txid = ?, pix_copia_cola = ?, pix_qr_code_base64 = ?, gateway_id = ?, expiration_date = ? 
                    WHERE id = ?
                ");
                $stmtUp->execute([
                    $pixData['txid'],
                    $copiaCola,
                    $pixData['qr_code_base64'] ?? '',
                    $pixData['gateway_id'] ?? null,
                    !empty($pixData['expiration_date']) ? date('Y-m-d H:i:s', strtotime($pixData['expiration_date'])) : null,
                    $faturaId
                ]);
            }

            $msgSuccess = "Fatura #{$faturaId} gerada com sucesso com PIX Copia e Cola!";

            // Disparo imediato no WhatsApp se marcado
            if ($enviarWa) {
                if (empty($cliente['whatsapp'])) {
                    $msgSuccess .= " (Cliente sem WhatsApp cadastrado para envio automático).";
                } else {
                    $faturaUrl = BASE_URL . "/cliente/fatura.php?id=" . $faturaId;
                    $wa = new WhatsAppService();
                    $resWa = $wa->sendInvoiceNotification(
                        $cliente['whatsapp'],
                        $cliente['nome'],
                        $valor,
                        $dataVencimento,
                        $copiaCola,
                        $faturaUrl,
                        $cliente['pppoe_usuario'] ?? '',
                        $cliente['plano_nome'] ?? '',
                        '',
                        $faturaId,
                        $pixData['expiration_date'] ?? null
                    );

                    if (!empty($resWa['corpo'])) {
                        $db->prepare("UPDATE faturas SET notificado_wa = 1 WHERE id = ?")->execute([$faturaId]);
                        $msgSuccess .= " Cobrança e chave PIX enviadas no WhatsApp com sucesso!";
                    } else {
                        $msgSuccess .= " (Falha ao enviar WhatsApp: " . ($resWa['erro'] ?? 'Verifique a API') . ")";
                    }
                }
            }
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

    Database::log('pagamento_manual', "Baixa manual na fatura #{$id} realizada pelo administrador " . ($_SESSION['admin_nome'] ?? 'Admin'), [
        'fatura_id' => $id,
        'admin_id' => $_SESSION['admin_id'] ?? null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido'
    ]);

    // Enviar WhatsApp de Confirmação
    $stmtF = $db->prepare("
        SELECT f.*, c.nome, c.whatsapp, p.nome as plano_nome 
        FROM faturas f 
        JOIN clientes c ON f.cliente_id = c.id 
        LEFT JOIN planos p ON f.plano_id = p.id
        WHERE f.id = ?
    ");
    $stmtF->execute([$id]);
    $fat = $stmtF->fetch();

    if ($fat && !empty($fat['whatsapp'])) {
        $wa = new WhatsAppService();
        $wa->sendPaymentConfirmation($fat['whatsapp'], $fat['nome'], $fat['valor'], $fat['id'], $fat['plano_nome'] ?? '');
    }

    $msgSuccess = "Fatura #{$id} marcada como PAGA e comprovante enviado via WhatsApp!";
}

// 3. Reenviar Comprovante de Pagamento via WhatsApp
if (isset($_GET['action']) && $_GET['action'] === 'enviar_recibo_wa' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmtF = $db->prepare("
        SELECT f.*, c.nome, c.whatsapp, p.nome as plano_nome 
        FROM faturas f 
        JOIN clientes c ON f.cliente_id = c.id 
        LEFT JOIN planos p ON f.plano_id = p.id
        WHERE f.id = ?
    ");
    $stmtF->execute([$id]);
    $fat = $stmtF->fetch();

    if ($fat) {
        if (empty($fat['whatsapp'])) {
            $msgError = "Cliente {$fat['nome']} não possui WhatsApp cadastrado.";
        } else {
            $wa = new WhatsAppService();
            $ok = $wa->sendPaymentConfirmation($fat['whatsapp'], $fat['nome'], $fat['valor'], $fat['id'], $fat['plano_nome'] ?? '');
            if ($ok) {
                $msgSuccess = "Comprovante da fatura #{$id} reenviado com sucesso para {$fat['nome']} via WhatsApp!";
            } else {
                $msgError = "Falha ao enviar comprovante no WhatsApp. Verifique a API.";
            }
        }
    }
}

// 4. Enviar Cobrança / Aviso via WhatsApp
if (isset($_GET['action']) && $_GET['action'] === 'cobrar_wa' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmtF = $db->prepare("
        SELECT f.*, c.nome, c.whatsapp, c.pppoe_usuario, p.nome as plano_nome 
        FROM faturas f 
        JOIN clientes c ON f.cliente_id = c.id 
        LEFT JOIN planos p ON f.plano_id = p.id
        WHERE f.id = ?
    ");
    $stmtF->execute([$id]);
    $fat = $stmtF->fetch();

    if ($fat) {
        if (empty($fat['whatsapp'])) {
            $msgError = "Cliente {$fat['nome']} não possui WhatsApp cadastrado.";
        } else {
            $faturaUrl = BASE_URL . "/cliente/fatura.php?id=" . $fat['id'];
            $wa = new WhatsAppService();

            $isAtrasado = (strtotime($fat['data_vencimento']) < strtotime(date('Y-m-d')));
            $diasAtraso = $isAtrasado ? (int)floor((time() - strtotime($fat['data_vencimento'])) / 86400) : 0;

            if ($isAtrasado) {
                $res = $wa->sendDueWarningNotification(
                    $fat['whatsapp'],
                    $fat['nome'],
                    $fat['valor'],
                    $fat['data_vencimento'],
                    $fat['pix_copia_cola'] ?? '',
                    $faturaUrl,
                    $fat['pppoe_usuario'] ?? '',
                    $fat['plano_nome'] ?? '',
                    '',
                    (int)$fat['id'],
                    $fat['expiration_date'] ?? null,
                    $diasAtraso
                );
            } else {
                $res = $wa->sendInvoiceNotification(
                    $fat['whatsapp'],
                    $fat['nome'],
                    $fat['valor'],
                    $fat['data_vencimento'],
                    $fat['pix_copia_cola'] ?? '',
                    $faturaUrl,
                    $fat['pppoe_usuario'] ?? '',
                    $fat['plano_nome'] ?? '',
                    '',
                    (int)$fat['id'],
                    $fat['expiration_date'] ?? null
                );
            }

            if (!empty($res['corpo'])) {
                if ($isAtrasado) {
                    $db->prepare("UPDATE faturas SET notificado_aviso = 1 WHERE id = ?")->execute([$id]);
                    $msgSuccess = "Aviso de pré-bloqueio enviado com sucesso no WhatsApp de {$fat['nome']}!";
                } else {
                    $db->prepare("UPDATE faturas SET notificado_wa = 1 WHERE id = ?")->execute([$id]);
                    $msgSuccess = "Cobrança enviada com sucesso no WhatsApp de {$fat['nome']}!";
                }
            } else {
                $msgError = "Falha ao enviar WhatsApp: " . ($res['erro'] ?? 'Verifique as configurações da API.');
            }
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

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

<style>
.pix-code-box { word-break:break-all; font-family:monospace; font-size:0.75rem; color:#38bdf8; background:rgba(56,189,248,0.08); padding:12px; border-radius:8px; border:1px solid rgba(56,189,248,0.2); }
</style>

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
                                <span class="text-secondary small"><i class="fa-brands fa-whatsapp text-success"></i> <?= sanitize(WhatsAppService::sanitizePhone($f['cliente_wa'])) ?></span>
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
                                <?php if (!empty($f['notificado_wa'])): ?>
                                    <span class="badge bg-success" title="Cobrança enviada com sucesso"><i class="fa-solid fa-check"></i> Cobrança</span>
                                <?php endif; ?>
                                <?php if (!empty($f['notificado_aviso'])): ?>
                                    <span class="badge bg-warning text-dark" title="Aviso pré-bloqueio enviado"><i class="fa-solid fa-triangle-exclamation"></i> Aviso</span>
                                <?php endif; ?>
                                <?php if (empty($f['notificado_wa']) && empty($f['notificado_aviso'])): ?>
                                    <span class="badge bg-secondary">Não enviado</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-info btn-ver-pix"
                                    title="Ver Fatura / PIX"
                                    data-fatura-id="<?= (int)$f['id'] ?>"
                                    data-cliente="<?= htmlspecialchars($f['cliente_nome'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-valor="<?= htmlspecialchars(formatMoeda($f['valor']), ENT_QUOTES, 'UTF-8') ?>"
                                    data-vencimento="<?= htmlspecialchars(formatData($f['data_vencimento']), ENT_QUOTES, 'UTF-8') ?>"
                                    data-status="<?= htmlspecialchars($f['status'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-wa="<?= htmlspecialchars(WhatsAppService::sanitizePhone($f['cliente_wa'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                    data-pix="<?= htmlspecialchars($f['pix_copia_cola'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="fa-solid fa-qrcode"></i>
                                </button>
                                <?php if ($f['status'] === 'pago'): ?>
                                    <a href="faturas.php?action=enviar_recibo_wa&id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-success" title="Reenviar Recibo no WhatsApp">
                                        <i class="fa-brands fa-whatsapp"></i>
                                    </a>
                                <?php else: ?>
                                    <?php
                                        $isAtrasadoItem = (strtotime($f['data_vencimento']) < strtotime(date('Y-m-d')));
                                        $tituloWa = $isAtrasadoItem ? "Enviar Alerta Pré-Bloqueio WhatsApp" : "Enviar Cobrança WhatsApp";
                                        $corWa = $isAtrasadoItem ? "btn-outline-warning" : "btn-outline-success";
                                    ?>
                                    <a href="faturas.php?action=cobrar_wa&id=<?= $f['id'] ?>" class="btn btn-sm <?= $corWa ?>" title="<?= $tituloWa ?>">
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
                    <div class="form-check form-switch mb-2 pt-2 border-top border-secondary">
                        <input class="form-check-input" type="checkbox" name="enviar_wa" id="enviar_wa" checked>
                        <label class="form-check-label text-light fw-bold" for="enviar_wa">
                            <i class="fa-brands fa-whatsapp text-success me-1"></i> Disparar cobrança no WhatsApp imediatamente
                        </label>
                        <div class="text-secondary small">Gera a chave PIX e envia a notificação no celular do cliente na hora.</div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary-custom"><i class="fa-solid fa-paper-plane me-1"></i>Gerar & Emitir Fatura</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Ver Fatura / PIX -->
<div class="modal fade" id="modalVerPix" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark text-light border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-qrcode text-info me-2"></i>Fatura #<span id="pixModalFaturaId"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <strong id="pixModalCliente" class="d-block mb-1"></strong>
                    <span class="text-secondary small">Venc. <span id="pixModalVencimento"></span></span>
                    <h3 class="text-success fw-bold mt-2 mb-0" id="pixModalValor"></h3>
                </div>

                <!-- Fatura já paga -->
                <div id="pixModalPago" class="text-center py-4" style="display:none">
                    <i class="fa-solid fa-circle-check text-success display-4 mb-2 d-block"></i>
                    <p class="text-success fw-bold mb-0">Pagamento Confirmado</p>
                </div>

                <!-- Sem PIX gerado ainda -->
                <div id="pixModalVazio" class="text-center py-4" style="display:none">
                    <i class="fa-solid fa-triangle-exclamation text-warning display-4 mb-2 d-block"></i>
                    <p class="text-secondary mb-0">Nenhuma chave PIX foi gerada ainda para esta fatura.</p>
                    <p class="text-secondary small">O PIX é gerado automaticamente quando o cliente acessa a fatura ou quando a cobrança é enviada via WhatsApp.</p>
                </div>

                <!-- QR Code + Copia e Cola -->
                <div id="pixModalArea" style="display:none">
                    <div class="text-center mb-3">
                        <div class="d-flex justify-content-center bg-white p-3 rounded-3 mb-3" id="pixModalQrCanvas"></div>
                        <button type="button" class="btn btn-primary-custom w-100 py-2 mb-3" onclick="copiarPixModal()">
                            <i class="fa-solid fa-copy me-2"></i>Copiar Código PIX
                        </button>
                    </div>
                    <div class="pix-code-box mb-2" id="pixModalCodeText"></div>
                </div>
            </div>
            <div class="modal-footer border-secondary d-flex justify-content-between">
                <div>
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Fechar</button>
                </div>
                <div class="d-flex gap-2">
                    <a id="pixModalBtnWa" href="#" class="btn btn-success">
                        <i class="fa-brands fa-whatsapp me-1"></i><span id="pixModalBtnWaTexto">Enviar WhatsApp</span>
                    </a>
                    <a id="pixModalLinkCompleto" href="#" target="_blank" class="btn btn-outline-info">
                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Abrir Fatura
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.btn-ver-pix').forEach(function (botao) {
        botao.addEventListener('click', function () {
            abrirModalPix(
                this.dataset.faturaId,
                this.dataset.cliente,
                this.dataset.valor,
                this.dataset.vencimento,
                this.dataset.status,
                this.dataset.pix,
                this.dataset.wa
            );
        });
    });
});

function abrirModalPix(faturaId, cliente, valor, vencimento, status, pixCopiaCola, clienteWa) {
    document.getElementById('pixModalFaturaId').innerText = faturaId;
    document.getElementById('pixModalCliente').innerText = cliente;
    document.getElementById('pixModalValor').innerText = valor;
    document.getElementById('pixModalVencimento').innerText = vencimento;
    document.getElementById('pixModalLinkCompleto').href =
        '../cliente/fatura.php?id=' + encodeURIComponent(faturaId);

    const btnWa = document.getElementById('pixModalBtnWa');
    const btnWaText = document.getElementById('pixModalBtnWaTexto');
    if (btnWa) {
        if (!clienteWa || clienteWa.trim() === '') {
            btnWa.style.display = 'none';
        } else {
            btnWa.style.display = 'inline-block';
            if (status === 'pago') {
                btnWa.href = 'faturas.php?action=enviar_recibo_wa&id=' + encodeURIComponent(faturaId);
                if (btnWaText) btnWaText.innerText = 'Reenviar Recibo no WhatsApp';
                btnWa.className = 'btn btn-success';
            } else {
                btnWa.href = 'faturas.php?action=cobrar_wa&id=' + encodeURIComponent(faturaId);
                if (btnWaText) btnWaText.innerText = 'Enviar Cobrança / PIX no WhatsApp';
                btnWa.className = 'btn btn-success';
            }
        }
    }

    const elPago = document.getElementById('pixModalPago');
    const elVazio = document.getElementById('pixModalVazio');
    const elArea = document.getElementById('pixModalArea');

    elPago.style.display = 'none';
    elVazio.style.display = 'none';
    elArea.style.display = 'none';

    if (status === 'pago') {
        elPago.style.display = 'block';
    } else if (!pixCopiaCola || pixCopiaCola.trim() === '') {
        elVazio.style.display = 'block';
    } else {
        elArea.style.display = 'block';

        document.getElementById('pixModalCodeText').innerText = pixCopiaCola;

        const canvas = document.getElementById('pixModalQrCanvas');
        canvas.innerHTML = '';

        new QRCode(canvas, {
            text: pixCopiaCola,
            width: 220,
            height: 220
        });
    }

    const modal = bootstrap.Modal.getOrCreateInstance(
        document.getElementById('modalVerPix')
    );

    modal.show();
}

function copiarPixModal() {
    const code = document.getElementById('pixModalCodeText').innerText.trim();

    navigator.clipboard.writeText(code).then(function () {
        const botao = document.querySelector('#modalVerPix button[onclick="copiarPixModal()"]');

        if (!botao) {
            return;
        }

        const textoOriginal = botao.innerHTML;
        botao.innerHTML = '<i class="fa-solid fa-check me-2"></i>Copiado!';
        botao.classList.remove('btn-primary-custom');
        botao.classList.add('btn-success');

        setTimeout(function () {
            botao.innerHTML = textoOriginal;
            botao.classList.remove('btn-success');
            botao.classList.add('btn-primary-custom');
        }, 2500);
    });
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
