<?php
require_once __DIR__ . '/header.php';

$faturaId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$clienteId = (int)$_SESSION['cliente_id'];

$stmtF = $db->prepare("
    SELECT f.*, 
           c.nome as cliente_nome, c.cpf_cnpj, c.email,
           p.nome as plano_nome, p.valor as plano_valor
    FROM faturas f
    JOIN clientes c ON f.cliente_id = c.id
    LEFT JOIN planos p ON f.plano_id = p.id
    WHERE f.id = ? AND f.cliente_id = ?
");
$stmtF->execute([$faturaId, $clienteId]);
$fatura = $stmtF->fetch();

if (!$fatura) {
    echo "<div class='alert alert-danger m-4'>Fatura não encontrada.</div>";
    require_once __DIR__ . '/footer.php';
    exit;
}

$valorTotal    = (float)($fatura['valor'] > 0 ? $fatura['valor'] : ($fatura['plano_valor'] ?? 50.00));
$mesesCobertos = max(1, (int)($fatura['meses_cobertos'] ?? 1));
$pixJaExiste   = !empty($fatura['pix_copia_cola']);
?>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

<style>
.payment-card { border-radius:20px; overflow:hidden; box-shadow:0 20px 40px rgba(0,0,0,.3); background:#1e293b; border:1px solid #334155; }
.payment-header { padding:20px; text-align:center; color:white; }
.payment-header.normal { background:linear-gradient(135deg,#38bdf8 0%,#6366f1 100%); }
.payment-header.danger { background:linear-gradient(135deg,#ef4444 0%,#b91c1c 100%); }
.payment-header.success { background:linear-gradient(135deg,#10b981 0%,#065f46 100%); }
.pix-code-box { word-break:break-all; font-family:monospace; font-size:.75rem; color:#38bdf8; background:rgba(56,189,248,.08); padding:12px; border-radius:8px; border:1px solid rgba(56,189,248,.2); }
.expiration-timer { font-family:monospace; font-size:1.4rem; font-weight:bold; color:#f59e0b; }
</style>

<div class="container py-3">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card payment-card border-0">
                <div class="payment-header <?php echo $fatura['status'] === 'pago' ? 'success' : ($fatura['status'] === 'atrasado' ? 'danger' : 'normal'); ?>">
                    <i class="fa-solid <?php echo $fatura['status'] === 'pago' ? 'fa-circle-check' : 'fa-qrcode'; ?> display-2 text-white mb-2"></i>
                    <h3 class="fw-bold mb-1">
                        <?php if ($fatura['status'] === 'pago') { ?>
                            Pagamento Confirmado
                        <?php } else { ?>
                            Pagar via PIX
                        <?php } ?>
                    </h3>
                </div>

                <div class="p-4">
                    <div class="text-center mb-4">
                        <p class="text-secondary small mb-1">Valor da mensalidade</p>
                        <h2 class="text-success fw-bold mb-0"><?php echo formatMoeda($valorTotal); ?></h2>
                        <p class="text-secondary small mt-1 mb-0">Venc. <?php echo formatData($fatura['data_vencimento']); ?></p>
                    </div>

                    <?php if ($fatura['status'] !== 'pago') { ?>
                        <div id="pixLoading" class="text-center py-4" <?php echo $pixJaExiste ? 'style="display:none"' : ''; ?>>
                            <i class="fa-solid fa-spinner fa-spin fa-2x text-warning mb-3 d-block"></i>
                            <p class="text-secondary">Gerando QR Code PIX...</p>
                        </div>

                        <div id="pixArea" <?php echo !$pixJaExiste ? 'style="display:none"' : ''; ?>>
                            <div class="alert alert-info py-2 small mb-3">
                                <i class="fa-solid fa-info-circle me-1"></i>
                                Escaneie o QR Code ou copie o código abaixo
                            </div>

                            <div class="d-flex justify-content-center bg-white p-3 rounded-3 mb-3" id="qrcodeCanvas"></div>

                            <button type="button" id="btnCopiarPix" class="btn btn-primary-custom w-100 py-2 mb-3">
                                <i class="fa-solid fa-copy me-2"></i>Copiar Código PIX
                            </button>

                            <div class="pix-code-box mb-3" id="codePixText"><?php echo htmlspecialchars($fatura['pix_copia_cola'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>

                            <div class="text-center mb-3">
                                <small class="text-warning"><i class="fa-solid fa-clock me-1"></i>Expira em: <span id="expirationTimer" class="expiration-timer">30:00</span></small>
                            </div>

                            <div id="statusPagamento" class="text-center text-secondary small">
                                <i class="fa-solid fa-hourglass-half me-1"></i>Aguardando confirmação...
                            </div>
                        </div>

                        <div id="pixErro" style="display:none" class="text-center py-3">
                            <i class="fa-solid fa-triangle-exclamation fa-2x text-danger mb-2 d-block"></i>
                            <p class="text-danger" id="pixMsgErro"></p>
                            <button id="btnTentarPix" type="button" class="btn btn-outline-warning btn-sm">Tentar Novamente</button>
                        </div>
                    <?php } else { ?>
                        <div class="text-center py-4">
                            <i class="fa-solid fa-circle-check text-success display-4 mb-2 d-block"></i>
                            <p class="text-success fw-bold">Pagamento Confirmado</p>
                            <p class="text-secondary small">Pago em <?php echo formatData($fatura['data_pagamento'] ?? ''); ?></p>
                        </div>
                    <?php } ?>

                    <div class="text-center mt-4 pt-3 border-top border-secondary">
                        <a href="index.php" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>Voltar</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const pixJaExiste = <?php echo $pixJaExiste ? 'true' : 'false'; ?>;
const faturaId = <?php echo (int)$fatura['id']; ?>;
const emailCliente = <?php echo json_encode($fatura['email'] ?? ''); ?>;
let pixCodeStr = <?php echo json_encode($fatura['pix_copia_cola'] ?? ''); ?>;
let timerInterval = null;
let pollingInterval = null;

function obterBotaoCopiar() {
    return document.getElementById('btnCopiarPix');
}

function copiarPixCliente(evento) {
    const codigoEl = document.getElementById('codePixText');
    const botao = obterBotaoCopiar();
    if (!codigoEl || !botao) return;

    const codigo = codigoEl.innerText.trim();
    if (!codigo) return;

    navigator.clipboard.writeText(codigo).then(function () {
        const textoOriginal = botao.innerHTML;
        const classeOriginal = botao.className;
        botao.innerHTML = '<i class="fa-solid fa-check me-2"></i>Copiado!';
        botao.className = 'btn btn-success w-100 py-2 mb-3';
        botao.disabled = true;
        setTimeout(function () {
            botao.innerHTML = textoOriginal;
            botao.className = classeOriginal;
            botao.disabled = false;
        }, 2500);
    }).catch(function () {
        const textoOriginal = botao.innerHTML;
        const classeOriginal = botao.className;
        botao.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-2"></i>Erro ao copiar';
        botao.className = 'btn btn-danger w-100 py-2 mb-3';
        setTimeout(function () {
            botao.innerHTML = textoOriginal;
            botao.className = classeOriginal;
        }, 2500);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const botao = obterBotaoCopiar();
    if (botao) botao.addEventListener('click', copiarPixCliente);

    const tentar = document.getElementById('btnTentarPix');
    if (tentar) tentar.addEventListener('click', gerarPix);

    if (pixJaExiste) {
        renderQrCode(pixCodeStr);
        startTimer(30 * 60);
        iniciarPolling(faturaId);
    } else if (document.getElementById('pixArea')) {
        gerarPix();
    }
});

async function gerarPix() {
    document.getElementById('pixLoading').style.display = 'block';
    document.getElementById('pixArea').style.display = 'none';
    document.getElementById('pixErro').style.display = 'none';

    try {
        const fd = new FormData();
        fd.append('fatura_id', faturaId);
        fd.append('email', emailCliente || ('cliente' + faturaId + '@spaconett.com'));

        const resp = await fetch('cliente-pix-api.php', { method: 'POST', body: fd });
        const data = await resp.json();
        document.getElementById('pixLoading').style.display = 'none';

        if (data.success) {
            pixCodeStr = data.qr_code || data.copia_cola;
            document.getElementById('codePixText').innerText = pixCodeStr;
            renderQrCode(pixCodeStr);
            document.getElementById('pixArea').style.display = 'block';
            startTimer(30 * 60);
            iniciarPolling(faturaId);
        } else {
            document.getElementById('pixErro').style.display = 'block';
            document.getElementById('pixMsgErro').innerText = data.message || 'Falha ao gerar PIX.';
        }
    } catch (err) {
        document.getElementById('pixLoading').style.display = 'none';
        document.getElementById('pixErro').style.display = 'block';
        document.getElementById('pixMsgErro').innerText = 'Erro de conexão: ' + err.message;
    }
}

function renderQrCode(text) {
    const canvas = document.getElementById('qrcodeCanvas');
    if (canvas && text) {
        canvas.innerHTML = '';
        new QRCode(canvas, { text: text, width: 220, height: 220 });
    }
}

function startTimer(segundos) {
    const el = document.getElementById('expirationTimer');
    if (!el) return;
    if (timerInterval) clearInterval(timerInterval);
    timerInterval = setInterval(function () {
        const min = String(Math.floor(segundos / 60)).padStart(2, '0');
        const seg = String(segundos % 60).padStart(2, '0');
        el.textContent = min + ':' + seg;
        segundos--;
        if (segundos < 0) {
            clearInterval(timerInterval);
            el.textContent = 'EXPIRADO';
        }
    }, 1000);
}

function iniciarPolling(fid) {
    const statusEl = document.getElementById('statusPagamento');
    if (!statusEl) return;
    if (pollingInterval) clearInterval(pollingInterval);
    pollingInterval = setInterval(async function () {
        try {
            const r = await fetch('../payment/check_status.php?fatura_id=' + encodeURIComponent(fid));
            const d = await r.json();
            if (d.status === 'pago') {
                clearInterval(pollingInterval);
                statusEl.innerHTML = '<i class="fa-solid fa-circle-check text-success me-1"></i><strong class="text-success">Pago! Liberando acesso...</strong>';
                setTimeout(function () { location.href = 'index.php?payment_success=1'; }, 2000);
            }
        } catch (e) {}
    }, 5000);
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
