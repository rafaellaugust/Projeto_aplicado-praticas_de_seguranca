<?php
require_once __DIR__ . '/header.php';

// ─── Busca status atual do MikroTik (fonte da verdade) ───────────────────────
$mesAtual  = (int)date('n');
$anoAtual  = (int)date('Y');

$stmtHist = $db->prepare("SELECT status FROM mikrotik_payment_history WHERE cliente_id=? AND ano=? AND mes=?");
$stmtHist->execute([$_SESSION['cliente_id'], $anoAtual, $mesAtual]);
$statusCaixa = $stmtHist->fetchColumn() ?: null;
// statusCaixa: 'paid' | 'warning' | 'overdue' | null

// ─── Determina labels e cores com base no status do MK ───────────────────────
$statusTexto   = 'Verificando...';
$statusClasse  = 'text-secondary';
$statusIcone   = 'fa-circle-question';
$podeGerar     = false;   // se pode gerar fatura/PIX
$mostrarAviso  = false;   // fatura em aberto para pagar

if ($statusCaixa === 'paid') {
    $statusTexto  = 'Em Dia';
    $statusClasse = 'text-success';
    $statusIcone  = 'fa-circle-check';
} elseif ($statusCaixa === 'warning') {
    $statusTexto  = 'Aviso';
    $statusClasse = 'text-warning';
    $statusIcone  = 'fa-clock';
    $mostrarAviso = true;
    $podeGerar    = true;
} elseif ($statusCaixa === 'overdue') {
    $statusTexto  = 'Bloqueado';
    $statusClasse = 'text-danger';
    $statusIcone  = 'fa-triangle-exclamation';
    $mostrarAviso = true;
    $podeGerar    = true;
} else {
    // Sem registro no histórico ainda: trata como Aviso até sync
    $statusTexto  = 'Pendente';
    $statusClasse = 'text-warning';
    $statusIcone  = 'fa-hourglass-half';
    $mostrarAviso = true;
    $podeGerar    = true;
}

// ─── Busca fatura aberta do mês atual ────────────────────────────────────────
$stmtFatMes = $db->prepare("
    SELECT * FROM faturas
    WHERE cliente_id = ?
      AND YEAR(data_vencimento) = ?
      AND MONTH(data_vencimento) = ?
      AND status != 'pago'
    ORDER BY id DESC LIMIT 1
");
$stmtFatMes->execute([$_SESSION['cliente_id'], $anoAtual, $mesAtual]);
$faturaAberta = $stmtFatMes->fetch();

// Se cliente em aviso/bloqueado e não tem fatura, dispara criação via cron-on-demand
if ($mostrarAviso && !$faturaAberta) {
    $valorPlano = (float)($cliente['plano_valor'] ?? 0);
    $diaVenc    = (int)($cliente['vencimento_dia'] ?? 10);
    $dataVenc   = date('Y-m-d', mktime(0, 0, 0, $mesAtual, $diaVenc, $anoAtual));
    $statusFat  = ($statusCaixa === 'overdue') ? 'atrasado' : 'pendente';
    $descricao  = 'Mensalidade ' . date('m/Y');

    try {
        $db->prepare("
            INSERT INTO faturas (cliente_id, plano_id, valor, data_vencimento, status, descricao, meses_cobertos)
            VALUES (?, ?, ?, ?, ?, ?, 1)
        ")->execute([$_SESSION['cliente_id'], $cliente['plano_id'], $valorPlano, $dataVenc, $statusFat, $descricao]);
        $novaFatId = (int)$db->lastInsertId();
        $stmtNew   = $db->prepare("SELECT * FROM faturas WHERE id = ?");
        $stmtNew->execute([$novaFatId]);
        $faturaAberta = $stmtNew->fetch();
    } catch (Exception $e) {
        // Fatura pode já existir (constraint), busca novamente
        $stmtFatMes->execute([$_SESSION['cliente_id'], $anoAtual, $mesAtual]);
        $faturaAberta = $stmtFatMes->fetch();
    }
}

// ─── Total pendente ───────────────────────────────────────────────────────────
$stmtPend = $db->prepare("SELECT SUM(valor) FROM faturas WHERE cliente_id=? AND status IN ('pendente','atrasado')");
$stmtPend->execute([$_SESSION['cliente_id']]);
$totalPendente = (float)($stmtPend->fetchColumn() ?: 0);

// ─── Últimas faturas pagas ────────────────────────────────────────────────────
$stmtPagas = $db->prepare("SELECT * FROM faturas WHERE cliente_id=? AND status='pago' ORDER BY data_pagamento DESC LIMIT 6");
$stmtPagas->execute([$_SESSION['cliente_id']]);
$pagas = $stmtPagas->fetchAll();

// ─── Valor do plano para cálculo multi-meses ─────────────────────────────────
$valorPlano = (float)($cliente['plano_valor'] ?? 0);
?>

<style>
.stat-card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; transition: all 0.3s; height: 100%; }
.stat-card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.3); border-color: #38bdf8; }
.card-custom { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 20px; }
.table-custom { width: 100%; color: #cbd5e1; }
.table-custom th { color: #94a3b8; font-size: 0.75rem; text-transform: uppercase; padding: 10px; border-bottom: 1px solid #334155; }
.table-custom td { padding: 12px 10px; border-bottom: 1px solid #1e293b; font-size: 0.85rem; }
.badge-custom { padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; }
.badge-pendente { background: #f59e0b; color: #000; }
.badge-atrasado { background: #ef4444; color: #fff; }
.badge-pago { background: #10b981; color: #fff; }
.mes-btn { cursor: pointer; border-radius: 12px; padding: 10px 18px; border: 2px solid #334155; background: #0f172a; color: #94a3b8; transition: all 0.2s; font-weight: 600; }
.mes-btn.ativo { border-color: #f59e0b; background: rgba(245,158,11,0.15); color: #f59e0b; }
.mes-btn:hover { border-color: #38bdf8; color: #38bdf8; }
.pix-modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:9999; align-items:center; justify-content:center; }
.pix-modal-overlay.show { display:flex; }
.pix-modal-box { background:#1e293b; border:1px solid #334155; border-radius:20px; padding:30px; max-width:420px; width:95%; text-align:center; }
</style>

<!-- Cabeçalho -->
<div class="row mb-4">
    <div class="col-md-8">
        <h4 class="text-white fw-bold mb-1"><i class="fa-solid fa-gauge-high me-2 text-info"></i>Olá, <?= sanitize($cliente['nome']) ?>!</h4>
        
    </div>
    <div class="col-md-4 text-md-end mt-2 mt-md-0">
        <span class="badge bg-dark border border-secondary px-3 py-2"></span>
    </div>
</div>

<!-- Cards de status -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body p-3">
                <h6 class="text-secondary small mb-1">Status Pagamento</h6>
                <h5 class="mb-0 fw-bold <?= $statusClasse ?>">
                    <i class="fa-solid <?= $statusIcone ?> me-1"></i><?= $statusTexto ?>
                </h5>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body p-3">
                <h6 class="text-secondary small mb-1">Plano</h6>
                <h6 class="mb-0 fw-bold text-white"><?= sanitize($cliente['plano_nome'] ?? 'N/A') ?></h6>
                <small class="text-secondary"><?= sanitize($cliente['velocidade_down'] ?? '?') ?>/<?= sanitize($cliente['velocidade_up'] ?? '?') ?></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body p-3">
                <h6 class="text-secondary small mb-1">Vencimento</h6>
                <h6 class="mb-0 fw-bold text-white">Dia <?= (int)($cliente['vencimento_dia'] ?? 10) ?></h6>
                <small class="text-secondary"><?= date('m/Y') ?></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body p-3">
                <h6 class="text-secondary small mb-1">Pendente</h6>
                <h5 class="mb-0 fw-bold text-warning"><?= formatMoeda($totalPendente) ?></h5>
                <small class="text-secondary">Valor em aberto</small>
            </div>
        </div>
    </div>
</div>

<!-- Bloco principal: aviso/bloqueado ou tudo em dia -->
<?php if ($mostrarAviso && $faturaAberta): ?>
<div class="card-custom mb-4 border <?= $statusCaixa === 'overdue' ? 'border-danger' : 'border-warning' ?> border-opacity-50"
     style="background: linear-gradient(135deg, #1e293b 0%, #334155 100%);">
    <div class="row align-items-center">
        <div class="col-md-7">
            <?php if ($statusCaixa === 'overdue'): ?>
                <h5 class="text-danger fw-bold mb-2"><i class="fa-solid fa-ban me-2"></i>Acesso Bloqueado</h5>
                <p class="mb-1 text-white">Sua internet está bloqueada por inadimplência.</p>
            <?php else: ?>
                <h5 class="text-warning fw-bold mb-2"><i class="fa-solid fa-clock me-2"></i>Fatura em Aberto</h5>
                <p class="mb-1 text-white">Vencimento: <strong><?= formatData($faturaAberta['data_vencimento']) ?></strong></p>
            <?php endif; ?>
            <p class="mb-1 text-white">Valor: <strong class="text-success fs-5"><?= formatMoeda($faturaAberta['valor']) ?></strong></p>
            <small class="text-secondary">Fatura #<?= $faturaAberta['id'] ?> — Pague para liberar o acesso</small>
        </div>
        <div class="col-md-5 text-md-end mt-3 mt-md-0">
            <a href="fatura.php?id=<?= $faturaAberta['id'] ?>" class="btn <?= $statusCaixa === 'overdue' ? 'btn-danger' : 'btn-warning' ?> btn btn-warning fw-bold w-100 py-3 rounded-3">
                <i class="fa-solid fa-qrcode me-2"></i>Pagar via PIX
            </a>
        </div>
    </div>
</div>
<?php elseif ($statusCaixa === 'paid'): ?>
<div class="card-custom mb-4 border border-success border-opacity-50 text-center py-3">
    <i class="fa-solid fa-circle-check text-success display-6 mb-2 d-block"></i>
    <h5 class="text-success fw-bold">Tudo em dia!</h5>
    <p class="text-secondary small mb-0">Nenhuma fatura pendente. Você pode pagar meses adiantados abaixo.</p>
</div>
<?php else: ?>
<div class="card-custom mb-4 border border-secondary border-opacity-50 text-center py-3">
    <i class="fa-solid fa-hourglass-half text-secondary display-6 mb-2 d-block"></i>
    <h5 class="text-secondary fw-bold">Aguardando sincronização</h5>
    <p class="text-secondary small mb-0">Seu status será atualizado em breve.</p>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Coluna: Pagar múltiplos meses -->
    <div class="col-md-7">
        <div class="card-custom">
            <h5 class="text-white fw-bold mb-1"><i class="fa-solid fa-calendar-check me-2 text-warning"></i>
                <?= $statusCaixa === 'paid' ? 'Pagar Adiantado' : 'Regularizar + Pagar' ?>
            </h5>
            <p class="text-secondary small mb-3">
                <?= $statusCaixa === 'paid'
                    ? 'Pague meses futuros antecipadamente e não se preocupe com o vencimento.'
                    : 'Selecione quantos meses deseja pagar e gere um único PIX.' ?>
            </p>

            <!-- Seletor de meses -->
            <div class="d-flex flex-wrap gap-2 mb-4" id="seletorMeses">
                <?php for ($m = 1; $m <= 6; $m++): ?>
                <button type="button" class="mes-btn <?= $m === 1 ? 'ativo' : '' ?>"
                        onclick="selecionarMeses(<?= $m ?>)"
                        id="mes-btn-<?= $m ?>">
                    <?= $m ?> mês<?= $m > 1 ? 'es' : '' ?>
                </button>
                <?php endfor; ?>
            </div>

            <!-- Resumo do valor -->
            <div class="bg-dark rounded-3 p-3 mb-3 border border-secondary">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <small class="text-secondary d-block">Plano: <?= sanitize($cliente['plano_nome'] ?? 'Sem Plano') ?></small>
                        <small class="text-secondary d-block">Valor por mês: <?= formatMoeda($valorPlano) ?></small>
                    </div>
                    <div class="text-end">
                        <small class="text-secondary d-block">Total a pagar:</small>
                        <h4 class="text-success fw-bold mb-0" id="totalCalculado"><?= formatMoeda($valorPlano) ?></h4>
                    </div>
                </div>
                <div class="mt-2 pt-2 border-top border-secondary">
                    <small class="text-info" id="detalhesMeses">
                        <?= date('m/Y') ?> — <?= formatMoeda($valorPlano) ?>
                    </small>
                </div>
            </div>



            <button type="button" id="btnGerarPIX" class="btn btn-warning fw-bold w-100 py-3 rounded-3"
                    onclick="gerarPixMultiplo()">
                <i class="fa-solid fa-qrcode me-2"></i>
                <span id="btnTexto">Gerar PIX — <?= formatMoeda($valorPlano) ?></span>
            </button>
        </div>

        <!-- Histórico de faturas pagas -->
        <?php if (!empty($pagas)): ?>
        <div class="card-custom mt-3">
            <h6 class="text-white fw-bold mb-3"><i class="fa-solid fa-clock-rotate-left me-2 text-success"></i>Últimos Pagamentos</h6>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead><tr><th>#</th><th>Vencimento</th><th>Valor</th><th>Pago em</th></tr></thead>
                    <tbody>
                    <?php foreach ($pagas as $pg): ?>
                        <tr>
                            <td>#<?= $pg['id'] ?></td>
                            <td><?= formatData($pg['data_vencimento']) ?></td>
                            <td class="text-success fw-bold"><?= formatMoeda($pg['valor']) ?></td>
                            <td><?= $pg['data_pagamento'] ? formatData($pg['data_pagamento']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="text-center mt-2">
                <a href="historico.php" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-list me-1"></i>Ver tudo</a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Coluna: Acesso rápido e info do plano -->
    <div class="col-md-5">
        <div class="card-custom mb-3">
            <h5 class="text-white fw-bold mb-3"><i class="fa-solid fa-bolt me-2 text-warning"></i>Acesso Rápido</h5>
            <div class="d-grid gap-2">
                <a href="perfil.php" class="btn btn-dark border border-secondary text-start"><i class="fa-solid fa-user me-2 text-info"></i>Meu Perfil e Dados</a>
                <a href="suporte.php" class="btn btn-dark border border-secondary text-start"><i class="fa-solid fa-headset me-2 text-success"></i>Suporte Técnico</a>
                <a href="historico.php" class="btn btn-dark border border-secondary text-start"><i class="fa-solid fa-clock-rotate-left me-2 text-warning"></i>Histórico de Pagamentos</a>
            </div>
        </div>

    </div>
</div>

<!-- Modal PIX -->
<div class="pix-modal-overlay" id="pixModal">
    <div class="pix-modal-box">
        <div id="pixLoading" class="py-4">
            <i class="fa-solid fa-spinner fa-spin display-4 text-warning mb-3 d-block"></i>
            <h5 class="text-white">Gerando PIX no Mercado Pago...</h5>
        </div>
        <div id="pixSucesso" style="display:none">
            <div class="bg-white p-3 rounded-3 mb-3 d-flex justify-content-center">
                <div id="qrcodePixModal"></div>
            </div>
            <div class="alert alert-info py-2 small mb-3">
                <i class="fa-solid fa-info-circle me-1"></i>Escaneie o QR Code ou copie o código abaixo
            </div>
            <div style="word-break:break-all;font-family:monospace;font-size:0.75rem;color:#38bdf8;background:rgba(56,189,248,0.1);padding:10px;border-radius:6px;margin-bottom:10px" id="pixCodigoModal"></div>
            <button type="button" class="btn btn-primary-custom w-100 mb-2" onclick="copiarCodigo()">
                <i class="fa-solid fa-copy me-2"></i>Copiar Código PIX
            </button>
            <p class="text-secondary small mb-2" id="pixMesesInfo"></p>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-light flex-fill" onclick="fecharModal()">Fechar</button>
                <button type="button" class="btn btn-success flex-fill" id="btnVerFatura" onclick="verFatura()">
                    <i class="fa-solid fa-receipt me-1"></i>Ver Fatura
                </button>
            </div>
        </div>
        <div id="pixErro" style="display:none">
            <i class="fa-solid fa-triangle-exclamation display-4 text-danger mb-3 d-block"></i>
            <h5 class="text-white">Erro ao Gerar PIX</h5>
            <p class="text-secondary" id="pixMsgErro"></p>
            <button type="button" class="btn btn-outline-light w-100" onclick="fecharModal()">Fechar</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
const valorPlano = <?= $valorPlano ?>;
let mesesSelecionados = 1;
let faturaIdAtual = null;

// Meses iniciando em (depends on client status)
const statusCaixa = <?= json_encode($statusCaixa) ?>;
const mesAtual = <?= $mesAtual ?>;
const anoAtual = <?= $anoAtual ?>;

function getMesNome(mes) {
    const nomes = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
    return nomes[mes - 1];
}

function calcularMeses(qtd) {
    // Se já está pago, começa do próximo mês; senão começa do atual
    let inicioMes = mesAtual;
    let inicioAno = anoAtual;
    if (statusCaixa === 'paid') {
        inicioMes = mesAtual === 12 ? 1 : mesAtual + 1;
        inicioAno = mesAtual === 12 ? anoAtual + 1 : anoAtual;
    }
    let detalhes = [];
    let m = inicioMes, a = inicioAno;
    for (let i = 0; i < qtd; i++) {
        detalhes.push(getMesNome(m) + '/' + a);
        if (m === 12) { m = 1; a++; } else { m++; }
    }
    return detalhes;
}

function selecionarMeses(qtd) {
    mesesSelecionados = qtd;
    document.querySelectorAll('.mes-btn').forEach(b => b.classList.remove('ativo'));
    document.getElementById('mes-btn-' + qtd).classList.add('ativo');
    const total = (valorPlano * qtd).toFixed(2).replace('.', ',');
    document.getElementById('totalCalculado').innerText = 'R$ ' + total;
    const mesesList = calcularMeses(qtd);
    document.getElementById('detalhesMeses').innerText = mesesList.join(' · ');
    const totalFmt = 'R$ ' + total;
    document.getElementById('btnTexto').innerText = 'Gerar PIX — ' + totalFmt;
}

async function gerarPixMultiplo() {
    const emailAuto = <?= json_encode($cliente['email'] ?? '') ?> || ('cliente<?= $clienteId ?>@spaconett.com');

    document.getElementById('pixModal').classList.add('show');
    document.getElementById('pixLoading').style.display = 'block';
    document.getElementById('pixSucesso').style.display = 'none';
    document.getElementById('pixErro').style.display = 'none';

    try {
        const fd = new FormData();
        fd.append('quantidade_meses', mesesSelecionados);
        fd.append('email', emailAuto);

        const resp = await fetch('gerar-pix-multiplo.php', { method: 'POST', body: fd });
        const data = await resp.json();

        document.getElementById('pixLoading').style.display = 'none';

        if (data.success) {
            faturaIdAtual = data.fatura_id;
            document.getElementById('pixSucesso').style.display = 'block';
            document.getElementById('pixCodigoModal').innerText = data.qr_code || data.copia_cola;

            // Gera QR Code
            const qrContainer = document.getElementById('qrcodePixModal');
            qrContainer.innerHTML = '';
            new QRCode(qrContainer, { text: data.qr_code || data.copia_cola, width: 220, height: 220 });

            const mesesNomes = calcularMeses(data.quantidade_meses);
            document.getElementById('pixMesesInfo').innerText =
                data.quantidade_meses + ' mês(es): ' + mesesNomes.join(', ') +
                ' — Total: R$ ' + parseFloat(data.valor_total).toFixed(2).replace('.', ',');

            // Inicia polling de status do pagamento
            iniciarPolling(data.fatura_id);
        } else {
            document.getElementById('pixErro').style.display = 'block';
            document.getElementById('pixMsgErro').innerText = data.message || 'Erro desconhecido.';
        }
    } catch(err) {
        document.getElementById('pixLoading').style.display = 'none';
        document.getElementById('pixErro').style.display = 'block';
        document.getElementById('pixMsgErro').innerText = 'Erro de conexão: ' + err.message;
    }
}

function iniciarPolling(faturaId) {
    const interval = setInterval(async () => {
        try {
            const r = await fetch('../payment/check_status.php?fatura_id=' + faturaId);
            const d = await r.json();
            if (d.status === 'pago' || d.status === 'approved') {
                clearInterval(interval);
                fecharModal();
                location.href = 'index.php?payment_success=1';
            }
        } catch(e) {}
    }, 5000);
}

function copiarCodigo() {
    const code = document.getElementById('pixCodigoModal').innerText;
    navigator.clipboard.writeText(code).then(() => {
        const btn = event.target.closest('button');
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check me-2"></i>Copiado!';
        btn.classList.add('btn-success');
        btn.classList.remove('btn-primary-custom');
        setTimeout(() => { btn.innerHTML = orig; btn.classList.remove('btn-success'); btn.classList.add('btn-primary-custom'); }, 2500);
    });
}

function verFatura() {
    if (faturaIdAtual) location.href = 'fatura.php?id=' + faturaIdAtual;
}

function fecharModal() {
    document.getElementById('pixModal').classList.remove('show');
}

// Fecha modal ao clicar fora
document.getElementById('pixModal').addEventListener('click', function(e) {
    if (e.target === this) fecharModal();
});

// Inicializa com 1 mês selecionado
selecionarMeses(1);

// Sucesso de pagamento (redirect com parâmetro)
const urlParams = new URLSearchParams(window.location.search);
if (urlParams.get('payment_success') === '1') {
    const div = document.createElement('div');
    div.className = 'alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3 shadow-lg';
    div.style.zIndex = '9999';
    div.innerHTML = '<i class="fa-solid fa-circle-check me-2"></i><strong>Pagamento confirmado!</strong> Seu acesso será liberado em instantes. <button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    document.body.prepend(div);
    setTimeout(() => div.remove(), 8000);
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
