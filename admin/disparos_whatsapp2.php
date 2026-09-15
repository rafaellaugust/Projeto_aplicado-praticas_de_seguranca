<?php
require_once __DIR__ . '/header.php';
$db = Database::getInstance();

$msgSuccess = '';
$msgError = '';

// ─── Auto-migrate: coluna do delay da 2ª mensagem (PIX) ──────────────────────
try {
    $existingColsCfg = array_column(
        $db->query("SHOW COLUMNS FROM configuracoes")->fetchAll(),
        'Field'
    );
    if (!in_array('wa_delay_pix', $existingColsCfg)) {
        $db->exec("ALTER TABLE configuracoes ADD COLUMN wa_delay_pix INT DEFAULT 2");
    }
} catch (Exception $e) {}

// ─── Salvar configurações de Disparo ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'salvar_disparos') {
    $wa_disparo_ativo = isset($_POST['wa_disparo_ativo']) ? 1 : 0;
    $wa_disparo_hora  = sanitize($_POST['wa_disparo_hora']  ?? '08:00');
    $wa_disparo_delay = (int)($_POST['wa_disparo_delay'] ?? 5);
    $wa_disparo_dias  = (int)($_POST['wa_disparo_dias']  ?? 3);
    $wa_delay_pix     = (int)($_POST['wa_delay_pix'] ?? 2);
    if ($wa_delay_pix < 1)  $wa_delay_pix = 1;
    if ($wa_delay_pix > 30) $wa_delay_pix = 30;

    try {
        $db->prepare("
            UPDATE configuracoes SET 
                wa_disparo_ativo = ?, 
                wa_disparo_hora = ?, 
                wa_disparo_delay = ?, 
                wa_disparo_dias = ?,
                wa_delay_pix = ?
            WHERE id = 1
        ")->execute([$wa_disparo_ativo, $wa_disparo_hora, $wa_disparo_delay, $wa_disparo_dias, $wa_delay_pix]);
        
        $msgSuccess = "Configurações de disparos salvas com sucesso!";
    } catch (Exception $e) {
        $msgError = "Erro ao salvar: " . $e->getMessage();
    }
}

// ─── Salvar modelo de mensagem ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'salvar_modelo') {
    $wa_modelo_mensagem = $_POST['wa_modelo_mensagem'] ?? '';
    
    try {
        $db->prepare("
            UPDATE configuracoes SET 
                wa_modelo_mensagem = ?
            WHERE id = 1
        ")->execute([$wa_modelo_mensagem]);
        
        $msgSuccess = "Modelo de mensagem salvo com sucesso!";
    } catch (Exception $e) {
        $msgError = "Erro ao salvar modelo: " . $e->getMessage();
    }
}

// ─── Obter configurações ──────────────────────────────────────────────────────
$config = $db->query("SELECT * FROM configuracoes WHERE id = 1")->fetch();

// ─── Modelo padrão caso não exista ────────────────────────────────────────────
$modeloPadrao = "🔔 *FATURA DISPONÍVEL - PROVEDOR DE INTERNET*

Olá, *{NOME_CLIENTE}*!
Sua fatura de internet já está disponível para pagamento.

💰 *Valor:* {VALOR_FATURA}
📅 *Vencimento:* {DATA_VENCIMENTO}

🔗 *Acesse sua fatura online:* {LINK_FATURA}

Obrigado por utilizar nossos serviços!";

$modeloAtual = $config['wa_modelo_mensagem'] ?? $modeloPadrao;

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
    // Tabela pode não estar criada ainda
}

$totalPages = ceil($totalLogs / $limit);
if ($totalPages < 1) $totalPages = 1;

$activeTab = isset($_GET['tab']) ? sanitize($_GET['tab']) : 'controlador';

// ─── Buscar clientes com faturas pendentes ────────────────────────────────────
$pendentes = [];
try {
    $diasAntecedencia = (int)($config['wa_disparo_dias'] ?? 3);
    $dataLimite = date('Y-m-d', strtotime("+{$diasAntecedencia} days"));
    
    $stmtPendentes = $db->prepare("
        SELECT 
            c.id as cliente_id,
            c.nome,
            c.whatsapp,
            c.pppoe_usuario,
            p.nome as plano_nome,
            p.valor as plano_valor,
            f.id as fatura_id,
            f.valor,
            f.data_vencimento,
            f.status as fatura_status,
            f.descricao,
            mph.status as history_status
        FROM clientes c
        LEFT JOIN planos p ON c.plano_id = p.id
        LEFT JOIN faturas f ON f.cliente_id = c.id 
            AND f.status IN ('pendente', 'atrasado')
            AND f.data_vencimento <= ?
        LEFT JOIN mikrotik_payment_history mph ON mph.cliente_id = c.id 
            AND mph.ano = YEAR(f.data_vencimento)
            AND mph.mes = MONTH(f.data_vencimento)
        WHERE c.status = 'ativo'
        AND f.id IS NOT NULL
        ORDER BY f.data_vencimento ASC
        LIMIT 100
    ");
    $stmtPendentes->execute([$dataLimite]);
    $pendentes = $stmtPendentes->fetchAll();
} catch (Exception $e) {
    // Ignora se tabela não existir
}
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
        <a class="nav-link text-light <?= $activeTab === 'modelo' ? 'active bg-dark border-dark' : 'border-transparent' ?>" id="modelo-tab" href="?tab=modelo"><i class="fa-solid fa-pen-to-square me-2 text-warning"></i>Modelo de Mensagem</a>
    </li>
    <li class="nav-item">
        <a class="nav-link text-light <?= $activeTab === 'historico' ? 'active bg-dark border-dark' : 'border-transparent' ?>" id="historico-tab" href="?tab=historico"><i class="fa-solid fa-list-check me-2 text-success"></i>Histórico de Disparos</a>
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
                            <label class="form-label small text-secondary">Intervalo entre Clientes (Segundos)</label>
                            <input type="number" name="wa_disparo_delay" class="form-control-custom" value="<?= (int)($config['wa_disparo_delay'] ?? 5) ?>" min="2" max="60" required>
                            <div class="text-secondary small">Tempo de espera entre o disparo de um cliente e o próximo. Previne o banimento do seu número pelo WhatsApp.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small text-secondary">Delay da 2ª Mensagem — Chave PIX (Segundos)</label>
                            <input type="number" name="wa_delay_pix" class="form-control-custom" value="<?= (int)($config['wa_delay_pix'] ?? 2) ?>" min="1" max="30" required>
                            <div class="text-secondary small">Tempo de espera entre a mensagem do corpo da fatura e a mensagem com a chave PIX Copia e Cola, para o mesmo cliente.</div>
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
                                    
                  <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="text-white fw-bold mb-0"><i class="fa-solid fa-clock text-danger me-2"></i>Clientes com Faturas Pendentes</h5>
                    <span class="badge bg-danger fs-6 py-2 px-3"><?= count($pendentes) ?> pendente(s)</span>
                  </div>
                  <p class="text-secondary small mb-3">Lista de clientes que possuem faturas pendentes e serão incluídos no próximo disparo automático.</p>

                  <?php if (empty($pendentes)): ?>
                      <div class="text-center py-5 text-secondary">
                          <i class="fa-solid fa-check-circle text-success fs-1 mb-3 d-block"></i>
                          <h5 class="text-white">Nenhum cliente com fatura pendente</h5>
                          <p class="small">Todos os clientes estão em dia ou não há faturas a vencer nos próximos dias.</p>
                      </div>
                  <?php else: ?>
                      <div class="table-responsive">
                          <table class="table-custom">
                              <thead>
                                  <tr>
                                      <th>Cliente</th>
                                      <th>Plano</th>
                                      <th>Valor</th>
                                      <th>Vencimento</th>
                                      <th>Status</th>
                                      <th>WhatsApp</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php foreach ($pendentes as $p): 
                                      $statusBadge = 'bg-warning text-dark';
                                      $statusText = 'Aviso';
                                      if ($p['fatura_status'] === 'atrasado') {
                                          $statusBadge = 'bg-danger';
                                          $statusText = 'Atrasado';
                                      }
                                      $whatsapp = !empty($p['whatsapp']) ? $p['whatsapp'] : 'Não cadastrado';
                                  ?>
                                      <tr>
                                          <td>
                                              <strong class="text-white"><?= htmlspecialchars($p['nome']) ?></strong>
                                              <br><small class="text-secondary"><?= htmlspecialchars($p['pppoe_usuario']) ?></small>
                                          </td>
                                          <td><span class="badge bg-dark border border-secondary text-info"><?= htmlspecialchars($p['plano_nome'] ?? 'N/A') ?></span></td>
                                          <td class="fw-bold text-success">R$ <?= number_format($p['valor'] ?? 0, 2, ',', '.') ?></td>
                                          <td>
                                              <?= date('d/m/Y', strtotime($p['data_vencimento'])) ?>
                                              <?php 
                                              $diasRestantes = (int)ceil((strtotime($p['data_vencimento']) - time()) / 86400);
                                              if ($diasRestantes < 0): ?>
                                                  <br><span class="badge bg-danger"><?= abs($diasRestantes) ?> dias atrasado</span>
                                              <?php elseif ($diasRestantes <= 3): ?>
                                                  <br><span class="badge bg-warning text-dark"><?= $diasRestantes ?> dias</span>
                                              <?php endif; ?>
                                          </td>
                                          <td>
                                              <span class="badge <?= $statusBadge ?>" style="font-size:0.75rem; padding:6px 12px; border-radius:20px">
                                                  <?= $statusText ?>
                                              </span>
                                              <?php if ($p['history_status'] === 'overdue'): ?>
                                                  <br><span class="badge bg-danger bg-opacity-50 text-light small">Bloqueado</span>
                                              <?php endif; ?>
                                          </td>
                                          <td>
                                              <?php if ($whatsapp !== 'Não cadastrado'): ?>
                                                  <a href="https://wa.me/55<?= preg_replace('/\D/', '', $whatsapp) ?>" target="_blank" class="text-success text-decoration-none">
                                                      <i class="fa-brands fa-whatsapp me-1"></i><?= htmlspecialchars($whatsapp) ?>
                                                  </a>
                                              <?php else: ?>
                                                  <span class="text-secondary small"><?= $whatsapp ?></span>
                                              <?php endif; ?>
                                          </td>
                                      </tr>
                                  <?php endforeach; ?>
                              </tbody>
                          </table>
                      </div>
                  <?php endif; ?>

                  <div class="mt-auto py-3">
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

    <!-- ABA 2: MODELO DE MENSAGEM -->
    <?php if ($activeTab === 'modelo'): ?>
    <div class="tab-pane fade show active">
        <div class="card-custom">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h5 class="text-white fw-bold mb-1"><i class="fa-solid fa-pen-to-square text-warning me-2"></i>Modelo de Mensagem de Cobrança</h5>
                    <p class="text-secondary small mb-0">Personalize a mensagem enviada aos clientes. Use as variáveis entre { } para substituir dinamicamente.</p>
                </div>
                <a href="?tab=modelo&reset=1" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Restaurar modelo padrão?')">
                    <i class="fa-solid fa-rotate-left me-1"></i>Restaurar Padrão
                </a>
            </div>

            <div class="row g-4">
                <!-- Coluna do Editor -->
                <div class="col-md-7">
                    <form method="POST" action="?tab=modelo">
                        <input type="hidden" name="action" value="salvar_modelo">
                        
                        <div class="mb-3">
                            <label class="form-label small text-secondary fw-bold">📝 Corpo da Mensagem</label>
                            <textarea name="wa_modelo_mensagem" class="form-control-custom" rows="12" style="font-family: monospace; font-size: 0.9rem; line-height: 1.7;"><?= htmlspecialchars($modeloAtual) ?></textarea>
                            <div class="text-secondary small mt-2">
                                <i class="fa-solid fa-info-circle me-1"></i>
                                Use as variáveis para personalizar. O PIX é sempre enviado em uma 2ª mensagem separada — não é preciso incluir {PIX_COPIA_COLA} no corpo.
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary-custom w-100"><i class="fa-solid fa-floppy-disk me-2"></i>Salvar Modelo de Mensagem</button>
                    </form>
                </div>

                <!-- Coluna de Ajuda / Preview -->
                <div class="col-md-5">
                    <div class="bg-dark p-3 rounded border border-secondary">
						<h6 class="text-white fw-bold"><i class="fa-solid fa-eye text-success me-2"></i>Exemplo de Mensagem</h6>
                        <div class="bg-black p-3 rounded text-light small" style="white-space: pre-wrap; font-family: monospace; line-height: 1.6; border: 1px solid #334155;">
<?php
// Exemplo de substituição para preview
$exemplo = str_replace(
    ['{NOME_CLIENTE}', '{PPPOE_USUARIO}', '{VALOR_FATURA}', '{DATA_VENCIMENTO}', '{LINK_FATURA}', '{PLANO_NOME}', '{EMPRESA_NOME}'],
    ['João Silva', 'joao.silva', 'R$ 89,90', '10/09/2026', (defined('BASE_URL') ? BASE_URL : 'https://aplicacao.spaconett.com') . '/cliente/fatura.php?id=123', 'S1-40Megas-01', 'SpacoNett'],
    $modeloAtual
);
echo htmlspecialchars($exemplo);
?>
                        </div>
                        <div class="text-secondary small mt-2">
                            <i class="fa-solid fa-brands fa-whatsapp text-success me-1"></i>
                            Em seguida, após <?= (int)($config['wa_delay_pix'] ?? 2) ?>s, é enviada uma 2ª mensagem contendo apenas a chave PIX Copia e Cola.
                        </div>

                    <div class="bg-dark p-3 rounded border border-secondary mt-3">
                    <h6 class="text-white fw-bold"><i class="fa-solid fa-code text-info me-2"></i>Variáveis Disponíveis</h6>
                        <div class="table-responsive">
                            <table class="table-custom table-sm">
                                <thead>
                                    <tr>
                                        <th>Variável</th>
                                        <th>Descrição</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td><code>{NOME_CLIENTE}</code></td><td class="text-secondary">Nome completo do cliente</td></tr>
                                    <tr><td><code>{PPPOE_USUARIO}</code></td><td class="text-secondary">Usuário PPPoE</td></tr>
                                    <tr><td><code>{VALOR_FATURA}</code></td><td class="text-secondary">Valor formatado (ex: R$ 89,90)</td></tr>
                                    <tr><td><code>{DATA_VENCIMENTO}</code></td><td class="text-secondary">Data de vencimento formatada</td></tr>
                                    <tr><td><code>{LINK_FATURA}</code></td><td class="text-secondary">Link para acessar a fatura online</td></tr>
                                    <tr><td><code>{PLANO_NOME}</code></td><td class="text-secondary">Nome do plano contratado</td></tr>
                                    <tr><td><code>{EMPRESA_NOME}</code></td><td class="text-secondary">Nome da sua empresa</td></tr>
                                </tbody>
                            </table>
                        </div>   
				
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ABA 3: HISTÓRICO -->
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
    try {
        const res = await fetch('../cron/disparar_whatsapp.php?action=contar_pendentes');
        const data = await res.json();
        // Atualiza o badge de pendentes se existir
        const badgePendentes = document.querySelector('.badge.bg-danger.fs-6');
        if (badgePendentes) {
            badgePendentes.textContent = data.total + ' pendente(s)';
        }
    } catch (e) {
        console.error('Erro ao verificar faturas:', e);
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
                let linha = `[${r.status.toUpperCase()}] ${icon} Cliente: ${r.cliente} (${r.whatsapp}) - Fatura #${r.fatura_id}`;
                if (r.status !== 'enviado' && r.erro) {
                    linha += ` | Motivo: ${r.erro}`;
                }
                area.innerHTML += linha + '\n';
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
document.addEventListener('DOMContentLoaded', function() {
    verificarFaturas();
});
</script>

<?php
require_once __DIR__ . '/footer.php';
?>