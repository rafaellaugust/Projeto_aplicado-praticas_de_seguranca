<?php
require_once __DIR__ . '/header.php';

$db = Database::getInstance();
$msgSuccess = '';
$msgError = '';

// Sincronizar Todos
if (isset($_GET['action']) && $_GET['action'] === 'sincronizar_todos') {
    $mkApi = new MikrotikAPI();
    $secrets = $mkApi->getSecrets();
    $sincronizados = 0;

    if (!empty($secrets)) {
        $mesAtual = date('n');
        $anoAtual = date('Y');

        foreach ($secrets as $sec) {
            $user = sanitize($sec['name'] ?? '');
            $profile = sanitize($sec['profile'] ?? '');

            if (!empty($user)) {
                $stmtC = $db->prepare("SELECT id FROM clientes WHERE pppoe_usuario = ?");
                $stmtC->execute([$user]);
                $cli = $stmtC->fetch();

                if ($cli) {
                    $statusHistory = 'paid';
                    if (strpos(strtolower($profile), 'bloqueado') !== false) {
                        $statusHistory = 'overdue';
                    } elseif (strpos(strtolower($profile), 'aviso') !== false) {
                        $statusHistory = 'warning';
                    }

                    $stmtH = $db->prepare("
                        INSERT INTO mikrotik_payment_history (cliente_id, ano, mes, status) 
                        VALUES (?, ?, ?, ?) 
                        ON DUPLICATE KEY UPDATE status = VALUES(status)
                    ");
                    $stmtH->execute([$cli['id'], $anoAtual, $mesAtual, $statusHistory]);
                    $sincronizados++;
                }
            }
        }
        $msgSuccess = "Sincronização em lote concluída! {$sincronizados} clientes atualizados a partir do RouterOS.";
    } else {
        $msgError = "Falha ao conectar ao MikroTik para sincronização.";
    }
}

// Salvar Status de Mês Específico via Modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'salvar_status_mes') {
    $clienteId = (int)$_POST['cliente_id'];
    $mes = (int)$_POST['mes'];
    $ano = (int)$_POST['ano'];
    $status = sanitize($_POST['status']);

    $stmtH = $db->prepare("
        INSERT INTO mikrotik_payment_history (cliente_id, ano, mes, status) 
        VALUES (?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE status = VALUES(status)
    ");
    $stmtH->execute([$clienteId, $ano, $mes, $status]);

    if ($status === 'paid') {
        $stmtC = $db->prepare("
            SELECT c.pppoe_usuario, p.profile_mikrotik 
            FROM clientes c 
            LEFT JOIN planos p ON c.plano_id = p.id 
            WHERE c.id = ?
        ");
        $stmtC->execute([$clienteId]);
        $cliData = $stmtC->fetch();

        if ($cliData && !empty($cliData['profile_mikrotik'])) {
            $mkApi = new MikrotikAPI();
            $mkApi->changeSecretProfile($cliData['pppoe_usuario'], $cliData['profile_mikrotik']);
            $mkApi->disconnectActiveSession($cliData['pppoe_usuario']);
        }
    }

    $msgSuccess = "Status atualizado com sucesso!";
}

// FILTROS - COM cliente_id SEM MUDAR DESIGN
$filterPlano = (int)($_GET['plano_id'] ?? 0);
$filterBusca = sanitize($_GET['busca'] ?? '');
$filterAno = (int)($_GET['ano'] ?? date('Y'));
$filterClienteId = (int)($_GET['cliente_id'] ?? 0);
$limitPerPage = (int)($_GET['per_page'] ?? 25);
$page = (int)($_GET['page'] ?? 1);
$offset = ($page - 1) * $limitPerPage;

$whereClause = "WHERE 1=1";
$params = [];

if ($filterClienteId > 0) {
    $whereClause .= " AND c.id = ?";
    $params[] = $filterClienteId;
}
if ($filterPlano > 0) {
    $whereClause .= " AND c.plano_id = ?";
    $params[] = $filterPlano;
}
if (!empty($filterBusca)) {
    $whereClause .= " AND (c.nome LIKE ? OR c.pppoe_usuario LIKE ? OR c.cpf_cnpj LIKE ?)";
    $params[] = "%$filterBusca%";
    $params[] = "%$filterBusca%";
    $params[] = "%$filterBusca%";
}

// Buscar Lista de Clientes
$sqlClientes = "
    SELECT c.*, p.nome as plano_nome, p.valor as plano_valor 
    FROM clientes c 
    LEFT JOIN planos p ON c.plano_id = p.id 
    {$whereClause} 
    ORDER BY c.id ASC 
    LIMIT {$limitPerPage} OFFSET {$offset}
";
$stmtMain = $db->prepare($sqlClientes);
$stmtMain->execute($params);
$listaClientes = $stmtMain->fetchAll();

// Total para Paginação
$stmtTotal = $db->prepare("SELECT COUNT(*) FROM clientes c {$whereClause}");
$stmtTotal->execute($params);
$totalClientesFiltrados = $stmtTotal->fetchColumn();
$totalPaginas = ceil($totalClientesFiltrados / $limitPerPage);

// Buscar Todo o Histórico do Ano Selecionado - COM FILTRO cliente_id
if ($filterClienteId > 0) {
    $stmtHist = $db->prepare("SELECT cliente_id, mes, status FROM mikrotik_payment_history WHERE ano = ? AND cliente_id = ?");
    $stmtHist->execute([$filterAno, $filterClienteId]);
} else {
    $stmtHist = $db->prepare("SELECT cliente_id, mes, status FROM mikrotik_payment_history WHERE ano = ?");
    $stmtHist->execute([$filterAno]);
}
$rowsHist = $stmtHist->fetchAll();

// Mapear histórico por cliente_id e mês
$matrizHistorico = [];
foreach ($rowsHist as $rh) {
    $matrizHistorico[$rh['cliente_id']][$rh['mes']] = $rh['status'];
}

$planosDisponiveis = $db->query("SELECT * FROM planos ORDER BY id ASC")->fetchAll();
$roteadorPadrao = $db->query("SELECT nome FROM roteadores LIMIT 1")->fetchColumn() ?: 'MikroTik';
?>

<!-- CABEÇALHO DA PÁGINA -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="text-white fw-bold mb-1">
            <i class="fa-solid fa-calendar-days text-info me-2"></i>Histórico de Pagamentos - <span class="text-info"><?= sanitize($roteadorPadrao) ?></span>
        </h4>
        <p class="text-secondary small mb-0">Visualização completa da matriz mensal de pagamentos de janeiro a dezembro.</p>
    </div>
  <!--  <a href="mikrotik-historico.php?action=sincronizar_todos" class="btn btn-primary-custom">
        <i class="fa-solid fa-rotate me-1"></i> 🔄 Sincronizar todos
    </a>-->
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

<!-- FILTROS -->
<div class="card-custom mb-4">
    <form method="GET" action="mikrotik-historico.php" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small text-secondary">Plano</label>
            <select name="plano_id" class="form-control-custom">
                <option value="">Todos os Planos</option>
                <?php foreach ($planosDisponiveis as $pl): ?>
                    <option value="<?= $pl['id'] ?>" <?= $filterPlano == $pl['id'] ? 'selected' : '' ?>><?= sanitize($pl['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label small text-secondary">Buscar Cliente / PPPoE</label>
            <input type="text" name="busca" class="form-control-custom" placeholder="Nome, Usuário..." value="<?= sanitize($filterBusca) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small text-secondary">Ano</label>
            <select name="ano" class="form-control-custom">
                <option value="2025" <?= $filterAno == 2025 ? 'selected' : '' ?>>2025</option>
                <option value="2026" <?= $filterAno == 2026 ? 'selected' : '' ?>>2026</option>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary-custom w-100"><i class="fa-solid fa-magnifying-glass me-1"></i> Filtrar</button>
            <a href="mikrotik-historico.php" class="btn btn-outline-light"><i class="fa-solid fa-broom"></i></a>
        </div>
    </form>
</div>

<!-- INFORMAÇÕES DE PAGINAÇÃO -->
<div class="d-flex justify-content-between align-items-center mb-2 px-1">
    <span class="text-secondary small">Mostrando <?= count($listaClientes) ?> de <?= $totalClientesFiltrados ?> clientes (Página <?= $page ?> de <?= max(1, $totalPaginas) ?>)</span>
    <span class="text-secondary small">Clique na badge de qualquer mês para alterar o status do cliente.</span>
</div>

<!-- TABELA DE MATRIZ DE HISTÓRICO (CLIENTE X 12 MESES) -->
<div class="card-custom mb-4">
    <div class="table-responsive">
        <table class="table-custom text-center">
            <thead>
                <tr>
                    <th class="text-start">#</th>
                    <th class="text-start">CLIENTE</th>
                    <th class="text-start">PLANO</th>
                    <th>JAN</th>
                    <th>FEV</th>
                    <th>MAR</th>
                    <th>ABR</th>
                    <th>MAI</th>
                    <th>JUN</th>
                    <th>JUL</th>
                    <th>AGO</th>
                    <th>SET</th>
                    <th>OUT</th>
                    <th>NOV</th>
                    <th>DEZ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($listaClientes)): ?>
                    <tr>
                        <td colspan="15" class="text-center py-4 text-secondary">Nenhum cliente encontrado.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($listaClientes as $c): ?>
                        <tr>
                            <td class="text-start">#<?= $c['id'] ?></td>
                            <td class="text-start">
                                <strong class="text-white"><?= sanitize($c['nome']) ?></strong><br>
                                <code class="text-info small"><?= sanitize($c['pppoe_usuario']) ?></code>
                            </td>
                            <td class="text-start small"><?= sanitize($c['plano_nome'] ?? 'Sem Plano') ?></td>

                            <!-- 12 MESES -->
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <?php 
                                $statusMes = $matrizHistorico[$c['id']][$m] ?? null;
                                $iconStatus = '➖';
                                $badgeColor = 'text-secondary';

                                if ($statusMes === 'paid') {
                                    $iconStatus = '✅';
                                } elseif ($statusMes === 'warning') {
                                    $iconStatus = '⚠️';
                                } elseif ($statusMes === 'overdue') {
                                    $iconStatus = '❌';
                                } elseif ($statusMes === 'npago') {
                                    $iconStatus = '🔴';
                                }
                                ?>
                                <td>
                                    <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none fs-5" 
                                            onclick="abrirModalHistorico(<?= $c['id'] ?>, '<?= sanitize($c['nome']) ?>', <?= $m ?>, <?= $filterAno ?>, '<?= $statusMes ?? 'paid' ?>')"
                                            title="Clique para alterar status do Mês <?= $m ?>">
                                        <?= $iconStatus ?>
                                    </button>
                                </td>
                            <?php endfor; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- PAGINAÇÃO -->
    <?php if ($totalPaginas > 1): ?>
        <div class="d-flex justify-content-center mt-4">
            <ul class="pagination pagination-sm">
                <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                    <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                        <a class="page-link bg-dark text-light border-secondary" href="mikrotik-historico.php?page=<?= $p ?>&plano_id=<?= $filterPlano ?>&ano=<?= $filterAno ?>"><?= $p ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>

<!-- LEGENDA EXPLICATIVA COMPLETA -->
<div class="card-custom bg-dark border-secondary mb-4">
    <h6 class="text-info fw-bold mb-2"><i class="fa-solid fa-book-open me-2"></i>Legenda de Status de Pagamento</h6>
    <div class="row g-2 text-secondary small">
        <div class="col-md-3"><strong>✅ Pago:</strong> Cliente com pagamento confirmado e Profile Normal ativo.</div>
        <div class="col-md-3"><strong>⚠️ Aviso:</strong> Próximo ao vencimento (Profile de Aviso).</div>
        <div class="col-md-3"><strong>❌ Atraso:</strong> Cliente bloqueado no MikroTik (Profile de Bloqueio).</div>
        <div class="col-md-3"><strong>🔴 Não Pago:</strong> Cliente foi desbloqueado sem pagamento efetuado.</div>
    </div>
</div>

<!-- MODAL: ATUALIZAR STATUS DO MÊS -->
<div class="modal fade" id="modalHistoricoStatus" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-dark text-light border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-calendar-check text-info me-2"></i>Atualizar Status - <span id="histClienteNome" class="text-info"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="salvar_status_mes">
                <input type="hidden" name="cliente_id" id="histClienteId">
                <input type="hidden" name="mes" id="histMes">
                <input type="hidden" name="ano" id="histAno">

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small text-secondary">Status no Mês <span id="histMesTexto" class="text-white font-monospace"></span>/<span id="histAnoTexto" class="text-white font-monospace"></span></label>
                        <select name="status" id="histStatusSelect" class="form-control-custom">
                            <option value="paid">✅ Pago (Profile Normal)</option>
                            <option value="warning">⚠️ Aviso (Profile de Aviso)</option>
                            <option value="overdue">❌ Atraso (Profile de Bloqueio)</option>
                            <option value="npago">🔴 Não Pago (Desbloqueado s/ pagamento)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-secondary justify-content-between">
                    <a href="mikrotik-historico.php?action=sincronizar_todos" class="btn btn-outline-info">
                        <i class="fa-solid fa-rotate me-1"></i> Buscar do MikroTik
                    </a>
                    <div>
                        <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary-custom">Salvar Status</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function abrirModalHistorico(id, nome, mes, ano, statusAtual) {
    document.getElementById('histClienteId').value = id;
    document.getElementById('histClienteNome').innerText = nome;
    document.getElementById('histMes').value = mes;
    document.getElementById('histAno').value = ano;
    document.getElementById('histMesTexto').innerText = mes;
    document.getElementById('histAnoTexto').innerText = ano;
    if (statusAtual) {
        document.getElementById('histStatusSelect').value = statusAtual;
    }
    const modal = new bootstrap.Modal(document.getElementById('modalHistoricoStatus'));
    modal.show();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
