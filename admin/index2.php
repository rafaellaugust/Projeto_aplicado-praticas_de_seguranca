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
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Faturado no Mês</div>
                    <div class="stat-value text-info"><?= formatMoeda($faturadoMes) ?></div>
                </div>
                <div class="bg-info bg-opacity-20 p-3 rounded-circle text-info">
                    <i class="fa-solid fa-file-invoice fs-3"></i>
                </div>
            </div>
            <div class="mt-2 text-secondary small">
                <span>Vencimento em <?= $mesesNomes[$filterMes] ?> • <?= $totalAtivos ?> clientes ativos</span>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Recebido no Mês (PIX)</div>
                    <div class="stat-value text-success"><?= formatMoeda($recebidoMes) ?></div>
                </div>
                <div class="bg-success bg-opacity-20 p-3 rounded-circle text-success">
                    <i class="fa-solid fa-circle-check fs-3"></i>
                </div>
            </div>
            <div class="mt-2 text-secondary small">
                <span class="text-success"><?= $taxaRecebimento ?>% do faturado</span> <span class="ms-2">Pago em <?= $mesesNomes[$filterMes] ?></span>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">A Receber (Estoque)</div>
                    <div class="stat-value text-warning"><?= formatMoeda($faturasPendentesValor) ?></div>
                </div>
                <div class="bg-warning bg-opacity-20 p-3 rounded-circle text-warning">
                    <i class="fa-solid fa-clock fs-3"></i>
                </div>
            </div>
            <div class="mt-2 text-secondary small">
                <span>Pendente + Atrasado (todos os meses)</span>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Total Clientes</div>
                    <div class="stat-value"><?= number_format($totalClientes) ?></div>
                </div>
                <div class="bg-primary bg-opacity-20 p-3 rounded-circle text-info">
                    <i class="fa-solid fa-users fs-3"></i>
                </div>
            </div>
            <div class="mt-2 text-secondary small">
                <span class="text-success"><i class="fa-solid fa-user-check me-1"></i><?= $clientesAtivos ?> Ativos</span> <span class="ms-2 text-secondary">de <?= $totalClientes ?> total</span>
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
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Pagos (Profile Normal)</div>
                    <div class="stat-value text-success"><?= $countPagos ?></div>
                </div>
                <div class="bg-success bg-opacity-20 p-3 rounded-circle text-success">
                    <i class="fa-solid fa-check fs-3"></i>
                </div>
            </div>
            <div class="mt-2 text-secondary small">
                <span class="text-success"><?= $pctPagos ?>% dos ativos</span> <a href="caixa.php?status=paid&mes=<?= $filterMes ?>&ano=<?= $filterAno ?>" class="text-success ms-2">Ver lista →</a>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Em Aviso</div>
                    <div class="stat-value text-warning"><?= $countAviso ?></div>
                </div>
                <div class="bg-warning bg-opacity-20 p-3 rounded-circle text-warning">
                    <i class="fa-solid fa-triangle-exclamation fs-3"></i>
                </div>
            </div>
            <div class="mt-2 text-secondary small">
                <span>Profile Aviso no MK</span> <a href="caixa.php?status=warning&mes=<?= $filterMes ?>&ano=<?= $filterAno ?>" class="text-warning ms-2">Ver lista →</a>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Bloqueados (Atraso)</div>
                    <div class="stat-value text-danger"><?= $countAtraso ?></div>
                </div>
                <div class="bg-danger bg-opacity-20 p-3 rounded-circle text-danger">
                    <i class="fa-solid fa-ban fs-3"></i>
                </div>
            </div>
            <div class="mt-2 text-secondary small">
                <span>Profile Bloqueado</span> <a href="caixa.php?status=overdue&mes=<?= $filterMes ?>&ano=<?= $filterAno ?>" class="text-danger ms-2">Ver lista →</a>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Não Pago + Inadimplentes</div>
                    <div class="stat-value text-white"><?= $countInadimplentes ?></div>
                </div>
                <div class="bg-secondary bg-opacity-20 p-3 rounded-circle text-secondary">
                    <i class="fa-solid fa-circle-xmark fs-3"></i>
                </div>
            </div>
            <div class="mt-2 text-secondary small">
                <span><?= $countAtraso ?> bloqueados + <?= $countNaoPago ?> não pagos</span> <a href="caixa.php?status=npago&mes=<?= $filterMes ?>&ano=<?= $filterAno ?>" class="text-white ms-2">Ver →</a>
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
