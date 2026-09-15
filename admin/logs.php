<?php
require_once __DIR__ . '/../config.php';
checkAdminLogin();

$db = Database::getInstance();

$msgSuccess = '';
$msgError = '';

// Ação de Limpeza de Logs Antigos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'limpar_antigos') {
        $dias = (int)($_POST['dias'] ?? 30);
        if ($dias < 1) $dias = 30;
        try {
            $stmt = $db->prepare("DELETE FROM logs WHERE criado_em < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $stmt->execute([$dias]);
            $afetados = $stmt->rowCount();
            Database::log('sistema', "Limpeza de logs: {$afetados} registros anteriores a {$dias} dias foram removidos por " . ($_SESSION['admin_nome'] ?? 'Admin'));
            $msgSuccess = "{$afetados} log(s) com mais de {$dias} dias foram removidos com sucesso!";
        } catch (Exception $e) {
            $msgError = "Erro ao limpar logs: " . $e->getMessage();
        }
    } elseif ($_POST['action'] === 'limpar_tudo') {
        try {
            $db->exec("TRUNCATE TABLE logs");
            Database::log('sistema', "Tabela de logs truncada/limpa por " . ($_SESSION['admin_nome'] ?? 'Admin'));
            $msgSuccess = "Todos os logs do sistema foram apagados com sucesso!";
        } catch (Exception $e) {
            $msgError = "Erro ao esvaziar logs: " . $e->getMessage();
        }
    }
}

// Filtros de busca
$tipoFilter   = sanitize($_GET['tipo'] ?? '');
$buscaFilter  = sanitize($_GET['busca'] ?? '');
$dataDe       = sanitize($_GET['data_de'] ?? '');
$dataAte      = sanitize($_GET['data_ate'] ?? '');

$where = ["1=1"];
$params = [];

if ($tipoFilter !== '') {
    $where[] = "tipo = ?";
    $params[] = $tipoFilter;
}

if ($buscaFilter !== '') {
    $where[] = "(mensagem LIKE ? OR detalhes LIKE ?)";
    $params[] = "%{$buscaFilter}%";
    $params[] = "%{$buscaFilter}%";
}

if ($dataDe !== '') {
    $where[] = "criado_em >= ?";
    $params[] = $dataDe . " 00:00:00";
}

if ($dataAte !== '') {
    $where[] = "criado_em <= ?";
    $params[] = $dataAte . " 23:59:59";
}

$whereSql = implode(" AND ", $where);

// Estatísticas Rápidas
try {
    $totalLogs = (int)$db->query("SELECT COUNT(*) FROM logs")->fetchColumn();
    $totalErros = (int)$db->query("SELECT COUNT(*) FROM logs WHERE tipo LIKE '%error%' OR mensagem LIKE '%erro%' OR mensagem LIKE '%falha%'")->fetchColumn();
    $totalHoje = (int)$db->query("SELECT COUNT(*) FROM logs WHERE DATE(criado_em) = CURRENT_DATE()")->fetchColumn();
    $tiposDisponiveis = $db->query("SELECT tipo, COUNT(*) as qtd FROM logs GROUP BY tipo ORDER BY qtd DESC")->fetchAll();
} catch (Exception $e) {
    $totalLogs = 0;
    $totalErros = 0;
    $totalHoje = 0;
    $tiposDisponiveis = [];
}

// Paginação
$limit = 30;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$stmtCount = $db->prepare("SELECT COUNT(*) FROM logs WHERE {$whereSql}");
$stmtCount->execute($params);
$totalFiltrados = (int)$stmtCount->fetchColumn();
$totalPages = ceil($totalFiltrados / $limit);
if ($totalPages < 1) $totalPages = 1;

$stmtLogs = $db->prepare("SELECT * FROM logs WHERE {$whereSql} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}");
$stmtLogs->execute($params);
$logs = $stmtLogs->fetchAll();

require_once __DIR__ . '/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h4 class="text-white fw-bold mb-1">
            <i class="fa-solid fa-clipboard-list text-info me-2"></i>Auditoria & Logs do Sistema
        </h4>
        <p class="text-secondary small mb-0">
            Monitore em tempo real eventos de autenticação, MikroTik RouterOS, WhatsApp API, Webhooks do Mercado Pago, rotinas de cobrança e erros.
        </p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modalLimparLogs">
            <i class="fa-solid fa-trash-can me-1"></i> Limpar / Purgar Logs
        </button>
        <a href="logs.php" class="btn btn-outline-info btn-sm">
            <i class="fa-solid fa-rotate me-1"></i> Atualizar
        </a>
    </div>
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

<!-- CARDS DE ESTATÍSTICAS -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="stat-label">Total de Eventos</span>
                    <div class="stat-value text-white"><?= number_format($totalLogs, 0, ',', '.') ?></div>
                </div>
                <div class="p-3 rounded" style="background: rgba(56, 189, 248, 0.1);">
                    <i class="fa-solid fa-database text-info fs-3"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="stat-label">Eventos de Hoje</span>
                    <div class="stat-value text-success"><?= number_format($totalHoje, 0, ',', '.') ?></div>
                </div>
                <div class="p-3 rounded" style="background: rgba(16, 185, 129, 0.1);">
                    <i class="fa-solid fa-calendar-day text-success fs-3"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="stat-label">Alertas / Erros</span>
                    <div class="stat-value text-danger"><?= number_format($totalErros, 0, ',', '.') ?></div>
                </div>
                <div class="p-3 rounded" style="background: rgba(239, 68, 68, 0.1);">
                    <i class="fa-solid fa-triangle-exclamation text-danger fs-3"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="stat-label">Filtrados</span>
                    <div class="stat-value text-warning"><?= number_format($totalFiltrados, 0, ',', '.') ?></div>
                </div>
                <div class="p-3 rounded" style="background: rgba(245, 158, 11, 0.1);">
                    <i class="fa-solid fa-filter text-warning fs-3"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- FILTROS DE BUSCA -->
<div class="card-custom mb-4">
    <form method="GET" action="logs.php" class="row g-3 align-items-end">
        <div class="col-md-3">
            <label class="form-label small text-secondary">Categoria / Tipo</label>
            <select name="tipo" class="form-control-custom">
                <option value="">Todas as categorias</option>
                <?php foreach ($tiposDisponiveis as $t): ?>
                    <option value="<?= htmlspecialchars($t['tipo']) ?>" <?= ($tipoFilter === $t['tipo']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars(strtoupper($t['tipo'])) ?> (<?= $t['qtd'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label small text-secondary">Buscar no Texto / Detalhes</label>
            <input type="text" name="busca" class="form-control-custom" placeholder="IP, usuário, ID fatura, erro..." value="<?= htmlspecialchars($buscaFilter) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small text-secondary">Data De</label>
            <input type="date" name="data_de" class="form-control-custom" value="<?= htmlspecialchars($dataDe) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small text-secondary">Data Até</label>
            <input type="date" name="data_ate" class="form-control-custom" value="<?= htmlspecialchars($dataAte) ?>">
        </div>
        <div class="col-md-1">
            <button type="submit" class="btn btn-primary-custom w-100" title="Aplicar Filtros">
                <i class="fa-solid fa-magnifying-glass"></i>
            </button>
        </div>
    </form>
</div>

<!-- TABELA DE LOGS -->
<div class="card-custom">
    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th style="width: 70px;">ID</th>
                    <th style="width: 150px;">Data / Hora</th>
                    <th style="width: 140px;">Categoria</th>
                    <th>Mensagem do Evento</th>
                    <th style="width: 100px;" class="text-end">Detalhes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5 text-secondary">
                            <i class="fa-solid fa-inbox fa-3x mb-3 d-block" style="color: var(--border-color);"></i>
                            Nenhum registro de log encontrado com os filtros informados.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                        <?php
                            $tipoLower = strtolower($l['tipo']);
                            $badgeClass = 'badge-suspenso';
                            $badgeIcon = 'fa-circle-info';
                            $isErro = (strpos($tipoLower, 'error') !== false || stripos($l['mensagem'], 'erro') !== false || stripos($l['mensagem'], 'falha') !== false);

                            if ($isErro) {
                                $badgeClass = 'badge-atrasado';
                                $badgeIcon = 'fa-triangle-exclamation';
                            } elseif (in_array($tipoLower, ['auth', 'login', 'autenticacao', 'logout'])) {
                                $badgeClass = 'badge-aviso';
                                $badgeIcon = 'fa-user-shield';
                            } elseif (in_array($tipoLower, ['mikrotik', 'routeros'])) {
                                $badgeClass = 'badge-ativo';
                                $badgeIcon = 'fa-server';
                            } elseif (in_array($tipoLower, ['whatsapp'])) {
                                $badgeClass = 'badge-ativo';
                                $badgeIcon = 'fa-brands fa-whatsapp';
                            } elseif (in_array($tipoLower, ['telegram'])) {
                                $badgeClass = 'badge-aviso';
                                $badgeIcon = 'fa-paper-plane';
                            } elseif (in_array($tipoLower, ['webhook', 'webhook_callback'])) {
                                $badgeClass = 'badge-pendente';
                                $badgeIcon = 'fa-bolt';
                            } elseif (in_array($tipoLower, ['gateway', 'pagamento'])) {
                                $badgeClass = 'badge-pago';
                                $badgeIcon = 'fa-credit-card';
                            } elseif (in_array($tipoLower, ['backup_sucesso', 'backup_restore', 'backup_delete'])) {
                                $badgeClass = 'badge-pago';
                                $badgeIcon = 'fa-database';
                            }
                        ?>
                        <tr>
                            <td class="text-secondary font-monospace small">#<?= $l['id'] ?></td>
                            <td class="text-secondary small">
                                <i class="fa-regular fa-clock me-1"></i><?= date('d/m/Y H:i:s', strtotime($l['criado_em'])) ?>
                            </td>
                            <td>
                                <span class="badge-custom <?= $badgeClass ?>">
                                    <i class="<?= strpos($badgeIcon, 'fa-') !== false && strpos($badgeIcon, 'fa-brands') === false ? 'fa-solid ' : '' ?><?= $badgeIcon ?> me-1"></i>
                                    <?= htmlspecialchars(strtoupper($l['tipo'])) ?>
                                </span>
                            </td>
                            <td>
                                <div class="text-light fw-medium text-break">
                                    <?= htmlspecialchars($l['mensagem']) ?>
                                </div>
                            </td>
                            <td class="text-end">
                                <?php if (!empty($l['detalhes'])): ?>
                                    <button type="button" class="btn btn-sm btn-outline-info" onclick="verDetalhes(<?= $l['id'] ?>)" title="Ver Detalhes Técnicos">
                                        <i class="fa-solid fa-code"></i> JSON
                                    </button>
                                    <div id="detalhes_<?= $l['id'] ?>" style="display:none;"><?= htmlspecialchars($l['detalhes']) ?></div>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- PAGINAÇÃO -->
    <?php if ($totalPages > 1): ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-4 pt-3" style="border-top: 1px solid var(--border-color);">
            <div class="text-secondary small">
                Mostrando página <strong><?= $page ?></strong> de <strong><?= $totalPages ?></strong> (<?= number_format($totalFiltrados, 0, ',', '.') ?> registros)
            </div>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php
                        $queryArgs = $_GET;
                        $makeUrl = function($p) use ($queryArgs) {
                            $queryArgs['page'] = $p;
                            return 'logs.php?' . http_build_query($queryArgs);
                        };
                    ?>
                    <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link bg-dark text-light border-secondary" href="<?= $makeUrl($page - 1) ?>">Anterior</a>
                    </li>
                    <?php
                        $startP = max(1, $page - 2);
                        $endP = min($totalPages, $page + 2);
                        for ($i = $startP; $i <= $endP; $i++):
                    ?>
                        <li class="page-item <?= ($page === $i) ? 'active' : '' ?>">
                            <a class="page-link <?= ($page === $i) ? 'bg-info border-info text-dark fw-bold' : 'bg-dark text-light border-secondary' ?>" href="<?= $makeUrl($i) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                        <a class="page-link bg-dark text-light border-secondary" href="<?= $makeUrl($page + 1) ?>">Próxima</a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL DE DETALHES TÉCNICOS JSON -->
<div class="modal fade" id="modalDetalhes" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="background: var(--bg-card); color: var(--text-primary); border: 1px solid var(--border-color);">
            <div class="modal-header" style="border-color: var(--border-color); background: rgba(0,0,0,0.15);">
                <h5 class="modal-title fw-bold text-info">
                    <i class="fa-solid fa-code me-2"></i>Detalhes Técnicos do Log #<span id="logIdDisplay"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <pre class="m-0 p-3" id="logContentDisplay" style="background:#0f172a; color:#38bdf8; font-family:monospace; font-size:0.85rem; max-height:450px; overflow:auto;"></pre>
            </div>
            <div class="modal-footer" style="border-color: var(--border-color);">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL DE LIMPEZA / PURGAÇÃO -->
<div class="modal fade" id="modalLimparLogs" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--bg-card); color: var(--text-primary); border: 1px solid var(--danger-red);">
            <div class="modal-header" style="border-color: var(--border-color); background: rgba(239,68,68,0.08);">
                <h5 class="modal-title fw-bold text-danger">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i>Limpeza da Tabela de Logs
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" class="mb-4">
                    <input type="hidden" name="action" value="limpar_antigos">
                    <h6 class="text-white fw-bold mb-2">1. Purgar logs antigos (Recomendado)</h6>
                    <p class="text-secondary small">Remove eventos antigos para economizar espaço em disco no banco de dados.</p>
                    <div class="input-group mb-3">
                        <select name="dias" class="form-control-custom">
                            <option value="15">Mais antigos que 15 dias</option>
                            <option value="30" selected>Mais antigos que 30 dias</option>
                            <option value="60">Mais antigos que 60 dias</option>
                            <option value="90">Mais antigos que 90 dias</option>
                        </select>
                        <button type="submit" class="btn btn-warning text-dark fw-semibold" onclick="return confirm('Deseja purgar os logs antigos conforme selecionado?');">
                            <i class="fa-solid fa-broom me-1"></i> Purgar Antigos
                        </button>
                    </div>
                </form>

                <hr style="border-color: var(--border-color);">

                <form method="POST">
                    <input type="hidden" name="action" value="limpar_tudo">
                    <h6 class="text-danger fw-bold mb-2">2. Esvaziar todos os logs (Atenção!)</h6>
                    <p class="text-secondary small">Apaga permanentemente todo o histórico de auditoria.</p>
                    <button type="submit" class="btn btn-danger w-100 fw-bold" onclick="return confirm('ATENÇÃO: Tem certeza absoluta que deseja apagar TODOS os logs do sistema? Essa ação não pode ser desfeita!');">
                        <i class="fa-solid fa-trash-can me-1"></i> Esvaziar Todos os Logs
                    </button>
                </form>
            </div>
            <div class="modal-footer" style="border-color: var(--border-color);">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            </div>
        </div>
    </div>
</div>

<script>
function verDetalhes(id) {
    var raw = document.getElementById('detalhes_' + id).innerText;
    var formatted = raw;
    try {
        var parsed = JSON.parse(raw);
        formatted = JSON.stringify(parsed, null, 2);
    } catch(e) {}
    document.getElementById('logIdDisplay').textContent = id;
    document.getElementById('logContentDisplay').textContent = formatted;
    var modal = new bootstrap.Modal(document.getElementById('modalDetalhes'));
    modal.show();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>