<?php
require_once __DIR__ . '/header.php';
$db = Database::getInstance();

$msgSuccess = '';
$msgError = '';

// ─── Salvar configurações de Disparo ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'salvar_disparos') {
    $wa_disparo_ativo = isset($_POST['wa_disparo_ativo']) ? 1 : 0;
    $wa_disparo_hora  = sanitize($_POST['wa_disparo_hora']  ?? '08:00');
    $wa_disparo_delay = (int)($_POST['wa_disparo_delay'] ?? 5);
    $wa_disparo_dias  = (int)($_POST['wa_disparo_dias']  ?? 3);

    try {
        $db->prepare("
            UPDATE configuracoes SET 
                wa_disparo_ativo = ?, 
                wa_disparo_hora = ?, 
                wa_disparo_delay = ?, 
                wa_disparo_dias = ? 
            WHERE id = 1
        ")->execute([$wa_disparo_ativo, $wa_disparo_hora, $wa_disparo_delay, $wa_disparo_dias]);
        
        $msgSuccess = "Configurações de disparos salvas com sucesso!";
    } catch (Exception $e) {
        $msgError = "Erro ao salvar: " . $e->getMessage();
    }
}

// ─── Obter configurações ──────────────────────────────────────────────────────
$config = $db->query("SELECT * FROM configuracoes WHERE id = 1")->fetch();

// ─── Configurações de paginação do Histórico ──────────────────────────────────
$limit = 25;
$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

// Filtros do Histórico
$statusFilter = isset($_GET['status']) ? sanitize($_GET['status']) : '';
$where = "WHERE 1=1";
$params = [];

if ($statusFilter !== '') {
    $where .= " AND status = ?";
    $params[] = $statusFilter;
}

// Buscar total e registros
$totalLogs = 0;
$logs = [];
try {
    $stmtCount = $db->prepare("SELECT COUNT(*) FROM whatsapp_disparos $where");
    $stmtCount->execute($params);
    $totalLogs = (int)$stmtCount->fetchColumn();

    $stmtLogs = $db->prepare("SELECT * FROM whatsapp_disparos $where ORDER BY id DESC LIMIT $limit OFFSET $offset");
    $stmtLogs->execute($params);
    $logs = $stmtLogs->fetchAll();
} catch (Exception $e) {
    // Tabela pode não estar criada ainda no primeiro acesso (auto-migrate roda no disparar_whatsapp.php)
}

$totalPages = ceil($totalLogs / $limit);
if ($totalPages < 1) $totalPages = 1;

$activeTab = isset($_GET['tab']) ? sanitize($_GET['tab']) : 'controlador';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="text-white fw-bold mb-1"><i class="fa-brands fa-whatsapp text-success me-2"></i>Controle de Disparos WhatsApp</h4>
        <p class="text-secondary small mb-0">Agendamento inteligente sem necessidade de Cron Jobs no cPanel</p>
    </div>
</div>

<?php if ($msgSuccess): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= $msgSuccess ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($msgError): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= $msgError ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<!-- Abas de Navegação -->
<ul class="nav nav-tabs border-dark mb-4" id="disparosTabs" role="tablist">
    <li class="nav-item">
        <a class="nav-link text-light <?= $activeTab === 'controlador' ? 'active bg-dark border-dark' : 'border-transparent' ?>" id="controlador-tab" href="?tab=controlador"><i class="fa-solid fa-gears me-2 text-info"></i>Configuração & Disparo</a>
    </li>
    <li class="nav-item">
        <a class="nav-link text-light <?= $activeTab === 'historico' ? 'active bg-dark border-dark' : 'border-transparent' ?>" id="historico-tab" href="?tab=historico"><i class="fa-solid fa-list-check me-2 text-warning"></i>Histórico de Disparos</a>
    </li>
</ul>

<div class="tab-content" id="disparosTabsContent">
    
    <!-- ABA 1: CONTROLADOR -->
    <?php if ($activeTab === 'controlador'): ?>
    <div class="tab-pane fade show active">
        <div class="row g-4">
            
            <!-- Coluna de Configuração -->
            <div class="col-md-5">
                <div class="card-custom h-100">
                    <h5 class="text-white fw-bold mb-3"><i class="fa-solid fa-sliders text-info me-2"></i>Ajustes do Agendamento</h5>
                    <form method="POST" action="?tab=controlador">
                        <input type="hidden" name="action" value="salvar_disparos">
                        
                        <div class="form-check form-switch mb-4">
                            <input class="form-check-input" type="checkbox" name="wa_disparo_ativo" id="wa_disparo_ativo" <?= ($config['wa_disparo_ativo'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label text-light fw-bold" for="wa_disparo_ativo">Ativar Disparos Automáticos Diários</label>
                            <div class="text-secondary small">Se ativado, o servidor Node.js chamará o sistema no horário definido.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small text-secondary">Horário de Envio Diário</label>
                            <input type="time" name="wa_disparo_hora" class="form-control-custom" value="<?= sanitize($config['wa_disparo_hora'] ?? '08:00') ?>" required>
                            <div class="text-secondary small">Horário de Brasília em que as mensagens começarão a ser enviadas.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small text-secondary">Intervalo entre Mensagens (Segundos)</label>
                            <input type="number" name="wa_disparo_delay" class="form-control-custom" value="<?= (int)($config['wa_disparo_delay'] ?? 5) ?>" min="2" max="60" required>
                            <div class="text-secondary small">Previne o banimento do seu número pelo WhatsApp.</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label small text-secondary">Antecedência do Envio (Dias antes do Vencimento)</label>
                            <input type="number" name="wa_disparo_dias" class="form-control-custom" value="<?= (int)($config['wa_disparo_dias'] ?? 3) ?>" min="1" max="15" required>
                            <div class="text-secondary small">Envia a fatura para clientes com vencimento até X dias à frente.</div>
                        </div>

                        <button type="submit" class="btn btn-primary-custom w-100"><i class="fa-solid fa-floppy-disk me-2"></i>Salvar Ajustes</button>
                    </form>
                </div>
            </div>

            <!-- Coluna de Ação Manual -->
            <div class="col-md-7">
                <div class="card-custom h-100 d-flex flex-column">
                    <h5 class="text-white fw-bold mb-3"><i class="fa-solid fa-paper-plane text-success me-2"></i>Disparar Cobranças Manualmente</h5>
                    <p class="text-secondary">Você pode iniciar o lote de envio imediatamente. O sistema identificará todos os clientes que se enquadram nas regras de vencimento configuradas e enviará uma mensagem de cada vez.</p>
                    
                    <div class="mt-auto py-3">
                        <div class="row g-2 mb-3 align-items-center">
                            <div class="col-sm-6 text-light fs-5 fw-bold">
                                Faturas pendentes identificadas:
                            </div>
                            <div class="col-sm-6 text-end">
                                <span class="badge bg-dark border border-secondary text-info fs-5 py-2 px-3" id="contadorFaturas">Verificando...</span>
                            </div>
                        </div>

                        <div id="progressoEnvio" style="display: none;">
                            <div class="progress bg-dark mb-3" style="height: 25px;">
                                <div id="progressBar" class="progress-bar bg-success progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%;">0%</div>
                            </div>
                            <div class="text-center small text-light mb-3" id="progressoStatus">Preparando lote...</div>
                        </div>

                        <div id="areaLogs" class="bg-dark p-3 rounded mb-3 text-secondary font-monospace small overflow-y-auto" style="max-height: 180px; display: none;"></div>

                        <div class="d-flex gap-2">
                            <button id="btnVerificar" class="btn btn-outline-info flex-fill" onclick="verificarFaturas()"><i class="fa-solid fa-magnifying-glass me-1"></i>Verificar Lote</button>
                            <button id="btnDisparar" class="btn btn-success flex-fill" onclick="iniciarDisparo()"><i class="fa-solid fa-play me-1"></i>Iniciar Disparo Manual</button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <?php endif; ?>

    <!-- ABA 2: HISTÓRICO -->
    <?php if ($activeTab === 'historico'): ?>
    <div class="tab-pane fade show active">
        <!-- Filtros -->
        <div class="card-custom mb-3">
            <form method="GET" action="" class="row g-2 align-items-center">
                <input type="hidden" name="tab" value="historico">
                <div class="col-md-3">
                    <select name="status" class="form-control-custom">
                        <option value="">Todos os status</option>
                        <option value="enviado" <?= $statusFilter === 'enviado' ? 'selected' : '' ?>>Enviado com sucesso</option>
                        <option value="falhou" <?= $statusFilter === 'falhou' ? 'selected' : '' ?>>Falha no envio</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary-custom w-100"><i class="fa-solid fa-filter me-1"></i>Filtrar</button>
                </div>
            </form>
        </div>

        <!-- Tabela -->
        <div class="card-custom">
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Data/Hora</th>
                            <th>Cliente</th>
                            <th>WhatsApp</th>
                            <th>Fatura</th>
                            <th>Tipo</th>
                            <th>Status</th>
                            <th>Erro / Detalhe</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-secondary">Nenhum log de disparo encontrado.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?= formatData($log['criado_em']) ?> <?= date('H:i:s', strtotime($log['criado_em'])) ?></td>
                                    <td class="text-white fw-bold"><?= htmlspecialchars($log['nome_cliente']) ?></td>
                                    <td><?= htmlspecialchars($log['whatsapp']) ?></td>
                                    <td><a href="faturas.php?busca=<?= $log['fatura_id'] ?>" class="text-info">#<?= $log['fatura_id'] ?></a></td>
                                    <td><span class="badge bg-secondary"><?= ucfirst($log['tipo']) ?></span></td>
                                    <td>
                                        <?php if ($log['status'] === 'enviado'): ?>
                                            <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Enviado</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1"></i>Falhou</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-secondary"><?= htmlspecialchars($log['erro_msg'] ?? 'Sem detalhes') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Paginação -->
            <?php if ($totalPages > 1): ?>
                <div class="d-flex justify-content-center mt-3">
                    <nav>
                        <ul class="pagination pagination-dark mb-0">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $page === $i ? 'active' : '' ?>">
                                    <a class="page-link" href="?tab=historico&page=<?= $i ?>&status=<?= $statusFilter ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Scripts de disparos manuais AJAX -->
<script>
let abortController = null;

async function verificarFaturas() {
    const badge = document.getElementById('contadorFaturas');
    badge.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Verificando...';
    try {
        const res = await fetch('../cron/disparar_whatsapp.php?action=contar_pendentes');
        const data = await res.json();
        badge.innerHTML = data.total + ' pendente(s)';
    } catch (e) {
        badge.innerHTML = 'Erro';
    }
}

async function iniciarDisparo() {
    const btnDisp = document.getElementById('btnDisparar');
    const btnVerif = document.getElementById('btnVerificar');
    const prog = document.getElementById('progressoEnvio');
    const pBar = document.getElementById('progressBar');
    const pStat = document.getElementById('progressoStatus');
    const area = document.getElementById('areaLogs');

    // Reset layout
    btnDisp.disabled = true;
    btnVerif.disabled = true;
    prog.style.display = 'block';
    area.style.display = 'block';
    pBar.style.width = '0%';
    pBar.innerHTML = '0%';
    pBar.className = 'progress-bar bg-success progress-bar-striped progress-bar-animated';
    pStat.innerText = 'Inicializando conexão com a fila...';
    area.innerHTML = '[INFO] Iniciando processo de disparo...\n';

    try {
        // Obter total antes
        const resTotal = await fetch('../cron/disparar_whatsapp.php?action=contar_pendentes');
        const dataTotal = await resTotal.json();
        const total = dataTotal.total;

        if (total === 0) {
            pStat.innerText = 'Nenhuma fatura pendente encontrada para envio hoje!';
            pBar.className = 'progress-bar bg-info';
            pBar.style.width = '100%';
            pBar.innerHTML = 'Concluído';
            btnDisp.disabled = false;
            btnVerif.disabled = false;
            return;
        }

        area.innerHTML += `[INFO] ${total} faturas na fila. Iniciando disparos em lote...\n`;
        
        // Chamada real de disparo
        const resDispatch = await fetch('../cron/disparar_whatsapp.php?action=dispatch');
        const dData = await resDispatch.json();

        if (dData.sucesso) {
            pBar.style.width = '100%';
            pBar.innerHTML = '100%';
            pBar.className = 'progress-bar bg-success';
            pStat.innerText = `Processo concluído! Sucessos: ${dData.enviados} | Falhas: ${dData.falhos}`;

            dData.resultados.forEach(r => {
                const icon = r.status === 'enviado' ? '✓' : '✗';
                area.innerHTML += `[${r.status.toUpperCase()}] ${icon} Cliente: ${r.cliente} (${r.whatsapp}) - Fatura #${r.fatura_id}\n`;
            });
            area.innerHTML += `[INFO] Lote encerrado.\n`;
        } else {
            throw new Error(dData.error || 'Erro desconhecido');
        }

    } catch (e) {
        pStat.innerText = 'Ocorreu um erro no processo de disparo.';
        pBar.className = 'progress-bar bg-danger';
        pBar.style.width = '100%';
        pBar.innerHTML = 'Falha';
        area.innerHTML += `[ERRO] ${e.message}\n`;
    }

    btnDisp.disabled = false;
    btnVerif.disabled = false;
    verificarFaturas();
}

// Rodar verificação de faturas automaticamente ao carregar
if (document.getElementById('contadorFaturas')) {
    verificarFaturas();
}
</script>

<?php
require_once __DIR__ . '/footer.php';
?>
