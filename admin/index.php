<?php
require_once __DIR__ . '/header.php';

$db = Database::getInstance();

// === MÉTRICAS FINANCEIRAS (FATURAS) - SEM AMBIGUIDADE ===
$totalClientes = $db->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
$clientesAtivos = $db->query("SELECT COUNT(*) FROM clientes WHERE status = 'ativo'")->fetchColumn();

$filterMes = (int)date('n');
$filterAno = (int)date('Y');
$mesesNomes = [1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro'];

// 1. FATURADO NO MÊS = tudo que vence neste mês (expectativa, não importa se pago)
$stmtFaturado = $db->prepare("SELECT SUM(valor) FROM faturas WHERE MONTH(data_vencimento) = ? AND YEAR(data_vencimento) = ?");
$stmtFaturado->execute([$filterMes, $filterAno]);
$faturadoMes = $stmtFaturado->fetchColumn() ?: 0;

// 2. RECEBIDO NO MÊS = só o que foi efetivamente pago neste mês (data_pagamento)
$stmtRecebido = $db->prepare("SELECT SUM(valor) FROM faturas WHERE status = 'pago' AND MONTH(data_pagamento) = ? AND YEAR(data_pagamento) = ?");
$stmtRecebido->execute([$filterMes, $filterAno]);
$recebidoMes = $stmtRecebido->fetchColumn() ?: 0;

// 3. A RECEBER = pendente + atrasado (estoque total, não só do mês)
$faturasPendentesValor = $db->query("SELECT SUM(valor) FROM faturas WHERE status IN ('pendente', 'atrasado')")->fetchColumn() ?: 0;

// 4. TAXA DE RECEBIMENTO = recebido / faturado
$taxaRecebimento = $faturadoMes > 0 ? round(($recebidoMes / $faturadoMes) * 100) : 0;

// === MÉTRICAS OPERACIONAIS MIKROTIK (mikrotik_payment_history) - DA CAIXA-ANTIGA ===
$stmtTotalAtivos = $db->query("SELECT COUNT(*) FROM clientes WHERE status = 'ativo'");
$totalAtivos = $stmtTotalAtivos->fetchColumn() ?: 1;

$stmtPagos = $db->prepare("SELECT COUNT(*) FROM mikrotik_payment_history WHERE ano = ? AND mes = ? AND status = 'paid'");
$stmtPagos->execute([$filterAno, $filterMes]);
$countPagos = $stmtPagos->fetchColumn() ?: 0;
$pctPagos = round(($countPagos / max(1, $totalAtivos)) * 100);

$stmtAviso = $db->prepare("SELECT COUNT(*) FROM mikrotik_payment_history WHERE ano = ? AND mes = ? AND status = 'warning'");
$stmtAviso->execute([$filterAno, $filterMes]);
$countAviso = $stmtAviso->fetchColumn() ?: 0;

$stmtAtraso = $db->prepare("SELECT COUNT(*) FROM mikrotik_payment_history WHERE ano = ? AND mes = ? AND status = 'overdue'");
$stmtAtraso->execute([$filterAno, $filterMes]);
$countAtraso = $stmtAtraso->fetchColumn() ?: 0;

$stmtNaoPago = $db->prepare("SELECT COUNT(*) FROM mikrotik_payment_history WHERE ano = ? AND mes = ? AND status = 'npago'");
$stmtNaoPago->execute([$filterAno, $filterMes]);
$countNaoPago = $stmtNaoPago->fetchColumn() ?: 0;

$countInadimplentes = $countAtraso + $countNaoPago;

// Últimas 10 faturas
$stmtFaturas = $db->query("SELECT f.*, c.nome as cliente_nome, c.whatsapp as cliente_wa FROM faturas f JOIN clientes c ON f.cliente_id = c.id ORDER BY f.id DESC LIMIT 10");
$faturasRecentes = $stmtFaturas->fetchAll();
?>

<!-- LINHA 1 - FINANCEIRO (SEM AMBIGUIDADE) - MESMO DESIGN INDEX -->
<div class="row g-4 mb-4">
    <div class="col-12">
        <h6 class="text-white fw-bold mb-0"><i class="fa-solid fa-sack-dollar text-success me-2"></i>Financeiro - <?= $mesesNomes[$filterMes] ?>/<?= $filterAno ?></h6>
        <small class="text-secondary">Faturado = vencimento no mês | Recebido = pago no mês (PIX) | A Receber = estoque total pendente</small>
    </div>

    <div class="col-md-3">
        <div class="card-custom h-100" style="border-left: 4px solid #00bcd4; display: flex; flex-direction: column; justify-content: space-between;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Faturado no Mês</div>
                    <div class="stat-value text-info fs-3 fw-bold"><?= formatMoeda($faturadoMes) ?></div>
                </div>
                <div style="background: rgba(0, 188, 212, 0.12); color: #00bcd4; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center;" class="rounded-circle">
                    <i class="fa-solid fa-file-invoice fs-4"></i>
                </div>
            </div>
            <div class="mt-2 text-secondary small pt-2 border-top border-secondary border-opacity-10">
                <span>Vencimento em <?= $mesesNomes[$filterMes] ?> • <?= $totalAtivos ?> ativos</span>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-custom h-100" style="border-left: 4px solid #10b981; display: flex; flex-direction: column; justify-content: space-between;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Recebido no Mês (PIX)</div>
                    <div class="stat-value text-success fs-3 fw-bold"><?= formatMoeda($recebidoMes) ?></div>
                </div>
                <div style="background: rgba(16, 185, 129, 0.12); color: #10b981; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center;" class="rounded-circle">
                    <i class="fa-solid fa-circle-check fs-4"></i>
                </div>
            </div>
            <div class="mt-2 text-secondary small pt-2 border-top border-secondary border-opacity-10">
                <span class="text-success fw-bold"><?= $taxaRecebimento ?>% do faturado</span> <span class="ms-1">pago no mês</span>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-custom h-100" style="border-left: 4px solid #f59e0b; display: flex; flex-direction: column; justify-content: space-between;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">A Receber (Estoque)</div>
                    <div class="stat-value text-warning fs-3 fw-bold"><?= formatMoeda($faturasPendentesValor) ?></div>
                </div>
                <div style="background: rgba(245, 158, 11, 0.12); color: #f59e0b; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center;" class="rounded-circle">
                    <i class="fa-solid fa-clock fs-4"></i>
                </div>
            </div>
            <div class="mt-2 text-secondary small pt-2 border-top border-secondary border-opacity-10">
                <span>Pendente + Atrasado (geral)</span>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-custom h-100" style="border-left: 4px solid #6366f1; display: flex; flex-direction: column; justify-content: space-between;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Total Clientes</div>
                    <div class="stat-value text-white fs-3 fw-bold"><?= number_format($totalClientes) ?></div>
                </div>
                <div style="background: rgba(99, 102, 241, 0.12); color: #6366f1; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center;" class="rounded-circle">
                    <i class="fa-solid fa-users fs-4"></i>
                </div>
            </div>
            <div class="mt-2 text-secondary small pt-2 border-top border-secondary border-opacity-10">
                <span class="text-success"><i class="fa-solid fa-user-check me-1"></i><?= $clientesAtivos ?> Ativos</span> <span class="ms-1">de <?= $totalClientes ?> total</span>
            </div>
        </div>
    </div>
</div>

<!-- LINHA 2 - OPERACIONAL MIKROTIK (DA CAIXA-ANTIGA) - MESMO DESIGN INDEX -->
<div class="row g-4 mb-4">
    <div class="col-12">
        <h6 class="text-white fw-bold mb-0"><i class="fa-solid fa-server text-warning me-2"></i>Operacional MikroTik - <?= $mesesNomes[$filterMes] ?>/<?= $filterAno ?> <small class="text-secondary ms-2">Baseado no profile real no RouterOS (/ppp/secret)</small></h6>
        <small class="text-secondary">Pago = Profile Normal/Espera | Aviso = Profile Aviso | Bloqueado = Profile Bloqueado | Não Pago = Aviso sem pagamento</small>
    </div>

    <div class="col-md-3">
        <div class="card-custom h-100" style="border-left: 4px solid #10b981; display: flex; flex-direction: column; justify-content: space-between;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Pagos (Profile Normal)</div>
                    <div class="stat-value text-success fs-3 fw-bold"><?= $countPagos ?></div>
                </div>
                <div style="background: rgba(16, 185, 129, 0.12); color: #10b981; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center;" class="rounded-circle">
                    <i class="fa-solid fa-check fs-4"></i>
                </div>
            </div>
            <div class="mt-2 text-secondary small pt-2 border-top border-secondary border-opacity-10">
                <span class="text-success"><?= $pctPagos ?>% dos ativos</span> <a href="caixa.php?status=paid&mes=<?= $filterMes ?>&ano=<?= $filterAno ?>" class="text-success ms-2 fw-bold text-decoration-none">Ver lista →</a>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-custom h-100" style="border-left: 4px solid #f59e0b; display: flex; flex-direction: column; justify-content: space-between;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Em Aviso</div>
                    <div class="stat-value text-warning fs-3 fw-bold"><?= $countAviso ?></div>
                </div>
                <div style="background: rgba(245, 158, 11, 0.12); color: #f59e0b; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center;" class="rounded-circle">
                    <i class="fa-solid fa-triangle-exclamation fs-4"></i>
                </div>
            </div>
            <div class="mt-2 text-secondary small pt-2 border-top border-secondary border-opacity-10">
                <span>Profile Aviso no MK</span> <a href="caixa.php?status=warning&mes=<?= $filterMes ?>&ano=<?= $filterAno ?>" class="text-warning ms-2 fw-bold text-decoration-none">Ver lista →</a>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-custom h-100" style="border-left: 4px solid #ef4444; display: flex; flex-direction: column; justify-content: space-between;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Bloqueados (Atraso)</div>
                    <div class="stat-value text-danger fs-3 fw-bold"><?= $countAtraso ?></div>
                </div>
                <div style="background: rgba(239, 68, 68, 0.12); color: #ef4444; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center;" class="rounded-circle">
                    <i class="fa-solid fa-ban fs-4"></i>
                </div>
            </div>
            <div class="mt-2 text-secondary small pt-2 border-top border-secondary border-opacity-10">
                <span>Profile Bloqueado</span> <a href="caixa.php?status=overdue&mes=<?= $filterMes ?>&ano=<?= $filterAno ?>" class="text-danger ms-2 fw-bold text-decoration-none">Ver lista →</a>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-custom h-100" style="border-left: 4px solid #94a3b8; display: flex; flex-direction: column; justify-content: space-between;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Não Pago + Inadimplentes</div>
                    <div class="stat-value text-white fs-3 fw-bold"><?= $countInadimplentes ?></div>
                </div>
                <div style="background: rgba(148, 163, 184, 0.12); color: #94a3b8; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center;" class="rounded-circle">
                    <i class="fa-solid fa-circle-xmark fs-4"></i>
                </div>
            </div>
            <div class="mt-2 text-secondary small pt-2 border-top border-secondary border-opacity-10">
                <span><?= $countAtraso ?> bloq + <?= $countNaoPago ?> n/pagos</span> <a href="caixa.php?status=npago&mes=<?= $filterMes ?>&ano=<?= $filterAno ?>" class="text-light ms-2 fw-bold text-decoration-none">Ver →</a>
            </div>
        </div>
    </div>
</div>

<!-- Tabela de Faturas Recentes - DESIGN ORIGINAL MANTIDO 100% -->
<div class="card-custom mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0 text-white fw-bold"><i class="fa-solid fa-receipt me-2 text-info"></i>Faturas Recentes</h5>
        <a href="faturas.php" class="btn btn-outline-light btn-sm">Ver Todas</a>
    </div>

    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th># ID</th>
                    <th>Cliente</th>
                    <th>Valor</th>
                    <th>Vencimento</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($faturasRecentes)): ?>
                    <tr><td colspan="6" class="text-center py-4 text-secondary">Nenhuma fatura encontrada.</td></tr>
                <?php else: foreach ($faturasRecentes as $f): ?>
                    <tr>
                        <td>#<?= $f['id'] ?></td>
                        <td><strong><?= sanitize($f['cliente_nome']) ?></strong><br><span class="text-secondary small"><i class="fa-brands fa-whatsapp text-success"></i> <?= sanitize($f['cliente_wa']) ?></span></td>
                        <td class="fw-bold"><?= formatMoeda($f['valor']) ?></td>
                        <td><?= formatData($f['data_vencimento']) ?></td>
                        <td><span class="badge-custom badge-<?= strtolower($f['status']) ?>"><?= ucfirst($f['status']) ?></span></td>
                        <td>
                            <a href="../cliente/fatura.php?id=<?= $f['id'] ?>" target="_blank" class="btn btn-sm btn-outline-info" title="Ver Fatura"><i class="fa-solid fa-eye"></i></a>
                            <?php if ($f['status'] !== 'pago'): ?>
                                <a href="faturas.php?action=cobrar_wa&id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-success" title="Cobrar WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
                                <a href="faturas.php?action=baixar&id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-warning" onclick="return confirm('Confirmar baixa manual desta fatura?')" title="Baixar Fatura"><i class="fa-solid fa-check"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
