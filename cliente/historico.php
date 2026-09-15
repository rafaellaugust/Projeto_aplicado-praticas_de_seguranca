<?php
require_once __DIR__ . '/header.php';

$historico = $db->prepare("SELECT * FROM faturas WHERE cliente_id = ? ORDER BY data_vencimento DESC");
$historico->execute([$_SESSION['cliente_id']]);
$faturas = $historico->fetchAll();

$months = [1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro'];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="text-white fw-bold mb-1"><i class="fa-solid fa-clock-rotate-left me-2 text-info"></i>Histórico de Pagamentos</h4>
        <p class="text-secondary small mb-0">Acompanhe todas as suas faturas e status mensais.</p>
    </div>
    <a href="index.php" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-arrow-left me-1"></i> Dashboard</a>
</div>

<div class="card-custom">
    <div class="table-responsive">
        <table class="table-custom">
            <thead><tr><th>Período</th><th>Fatura</th><th>Vencimento</th><th>Status</th><th>Pagamento</th><th>Valor</th><th>Ação</th></tr></thead>
            <tbody>
                <?php if (empty($faturas)): ?>
                    <tr><td colspan="7" class="text-center py-4 text-secondary"><i class="fa-solid fa-inbox display-6 d-block mb-2"></i>Nenhum registro encontrado.</td></tr>
                <?php else: foreach ($faturas as $f): 
                    $mes = (int)date('n', strtotime($f['data_vencimento']));
                    $ano = date('Y', strtotime($f['data_vencimento']));
                ?>
                    <tr>
                        <td><strong><?= $months[$mes] ?? $mes ?>/<?= $ano ?></strong></td>
                        <td>#<?= $f['id'] ?></td>
                        <td><?= formatData($f['data_vencimento']) ?></td>
                        <td>
                            <?php if ($f['status']==='pago'): ?><span class="badge bg-success px-3 py-2 rounded-pill"><i class="fa-solid fa-check me-1"></i>Pago</span>
                            <?php elseif ($f['status']==='atrasado'): ?><span class="badge bg-danger px-3 py-2 rounded-pill"><i class="fa-solid fa-xmark me-1"></i>Atrasado</span>
                            <?php else: ?><span class="badge bg-warning text-dark px-3 py-2 rounded-pill"><i class="fa-solid fa-clock me-1"></i><?= ucfirst($f['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= $f['data_pagamento'] ? formatData($f['data_pagamento']) : '-' ?></td>
                        <td class="fw-bold"><?= formatMoeda($f['valor']) ?></td>
                        <td>
                            <?php if ($f['status']!=='pago'): ?><a href="fatura.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-warning rounded-3"><i class="fa-solid fa-qrcode"></i> Pagar</a>
                            <?php else: ?><span class="text-success"><i class="fa-solid fa-check"></i></span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card-custom mt-3 bg-dark border border-secondary">
    <small class="text-secondary"><i class="fa-solid fa-circle-info me-1"></i> <strong>Legenda:</strong> <span class="badge bg-success ms-2">Pago</span> Confirmado | <span class="badge bg-warning text-dark ms-2">Pendente</span> Aguardando | <span class="badge bg-danger ms-2">Atrasado</span> Em atraso</small>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
