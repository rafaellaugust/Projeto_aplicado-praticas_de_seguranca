<?php
require_once __DIR__ . '/header.php';
$db = Database::getInstance();

$msgSuccess = '';
$msgError = '';

try {
    $existingColsCfg = array_column(
        $db->query("SHOW COLUMNS FROM configuracoes")->fetchAll(),
        'Field'
    );
    if (!in_array('wa_delay_pix', $existingColsCfg)) {
        $db->exec("ALTER TABLE configuracoes ADD COLUMN wa_delay_pix INT DEFAULT 3");
    }
    if (!in_array('wa_modelo_confirmacao', $existingColsCfg)) {
        $db->exec("ALTER TABLE configuracoes ADD COLUMN wa_modelo_confirmacao TEXT");
    }
    if (!in_array('wa_aviso_ativo', $existingColsCfg)) {
        $db->exec("ALTER TABLE configuracoes ADD COLUMN wa_aviso_ativo TINYINT(1) DEFAULT 1");
    }
    if (!in_array('wa_aviso_tolerancia', $existingColsCfg)) {
        $db->exec("ALTER TABLE configuracoes ADD COLUMN wa_aviso_tolerancia INT DEFAULT 5");
    }
    if (!in_array('wa_modelo_aviso', $existingColsCfg)) {
        $db->exec("ALTER TABLE configuracoes ADD COLUMN wa_modelo_aviso TEXT");
    }
    // Normalizar números existentes (remover 55 inicial mantendo DDD + número)
    $db->exec("UPDATE clientes SET whatsapp = SUBSTRING(whatsapp, 3) WHERE (LENGTH(whatsapp) = 12 OR LENGTH(whatsapp) = 13) AND whatsapp LIKE '55%'");
    $db->exec("UPDATE whatsapp_disparos SET whatsapp = SUBSTRING(whatsapp, 3) WHERE (LENGTH(whatsapp) = 12 OR LENGTH(whatsapp) = 13) AND whatsapp LIKE '55%'");
} catch (Exception $e) {
}

if (isset($_GET['reset']) && ($_GET['tab'] ?? '') === 'modelo') {
    if ($_GET['reset'] === 'confirmacao') {
        $db->exec("UPDATE configuracoes SET wa_modelo_confirmacao = NULL WHERE id = 1");
        $msgSuccess = "Modelo de confirmação de pagamento restaurado para o padrão!";
    } elseif ($_GET['reset'] === 'aviso') {
        $db->exec("UPDATE configuracoes SET wa_modelo_aviso = NULL WHERE id = 1");
        $msgSuccess = "Modelo de aviso pré-bloqueio restaurado para o padrão!";
    } elseif ($_GET['reset'] === 'cobranca' || $_GET['reset'] === '1') {
        $db->exec("UPDATE configuracoes SET wa_modelo_mensagem = NULL WHERE id = 1");
        $msgSuccess = "Modelo de cobrança restaurado para o padrão!";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'salvar_disparos') {
    $wa_disparo_ativo    = isset($_POST['wa_disparo_ativo']) ? 1 : 0;
    $wa_disparo_hora     = sanitize($_POST['wa_disparo_hora']  ?? '08:00');
    $wa_disparo_delay    = (int)($_POST['wa_disparo_delay'] ?? 5);
    $wa_disparo_dias     = (int)($_POST['wa_disparo_dias']  ?? 3);
    // Unifica o delay: o mesmo tempo de Espera p/ Cliente serve para a 2ª mensagem (chave PIX)
    $wa_delay_pix        = $wa_disparo_delay;
    $wa_aviso_ativo      = isset($_POST['wa_aviso_ativo']) ? 1 : 0;
    $wa_aviso_tolerancia = (int)($_POST['wa_aviso_tolerancia'] ?? 5);

    if ($wa_disparo_delay < 2)  $wa_disparo_delay = 2;
    if ($wa_disparo_delay > 60) $wa_disparo_delay = 60;
    $wa_delay_pix = $wa_disparo_delay;
    if ($wa_aviso_tolerancia < 1)  $wa_aviso_tolerancia = 1;
    if ($wa_aviso_tolerancia > 15) $wa_aviso_tolerancia = 15;

    try {
        $db->prepare("UPDATE configuracoes SET 
            wa_disparo_ativo = ?, 
            wa_disparo_hora = ?, 
            wa_disparo_delay = ?, 
            wa_disparo_dias = ?, 
            wa_delay_pix = ?,
            wa_aviso_ativo = ?,
            wa_aviso_tolerancia = ?
            WHERE id = 1
        ")->execute([
            $wa_disparo_ativo,
            $wa_disparo_hora,
            $wa_disparo_delay,
            $wa_disparo_dias,
            $wa_delay_pix,
            $wa_aviso_ativo,
            $wa_aviso_tolerancia
        ]);
        $msgSuccess = "Configurações de disparos salvas com sucesso!";
    } catch (Exception $e) {
        $msgError = "Erro ao salvar: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'salvar_modelo') {
    $wa_modelo_mensagem = $_POST['wa_modelo_mensagem'] ?? '';

    try {
        $db->prepare("UPDATE configuracoes SET wa_modelo_mensagem = ? WHERE id = 1")->execute([$wa_modelo_mensagem]);
        $msgSuccess = "Modelo de mensagem de cobrança salvo com sucesso!";
    } catch (Exception $e) {
        $msgError = "Erro ao salvar modelo: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'salvar_modelo_aviso') {
    $wa_modelo_aviso = $_POST['wa_modelo_aviso'] ?? '';

    try {
        $db->prepare("UPDATE configuracoes SET wa_modelo_aviso = ? WHERE id = 1")->execute([$wa_modelo_aviso]);
        $msgSuccess = "Modelo de aviso pré-bloqueio salvo com sucesso!";
    } catch (Exception $e) {
        $msgError = "Erro ao salvar modelo de aviso: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'salvar_modelo_confirmacao') {
    $wa_modelo_confirmacao = $_POST['wa_modelo_confirmacao'] ?? '';

    try {
        $db->prepare("UPDATE configuracoes SET wa_modelo_confirmacao = ? WHERE id = 1")->execute([$wa_modelo_confirmacao]);
        $msgSuccess = "Modelo de confirmação de pagamento salvo com sucesso!";
    } catch (Exception $e) {
        $msgError = "Erro ao salvar modelo de confirmação: " . $e->getMessage();
    }
}

$config = $db->query("SELECT * FROM configuracoes WHERE id = 1")->fetch();

$modeloPadrao = "🔔 *FATURA DISPONÍVEL - PROVEDOR DE INTERNET*" . chr(10) . chr(10) .
    "Olá, *{NOME_CLIENTE}*!" . chr(10) .
    "Sua fatura de internet já está disponível para pagamento." . chr(10) . chr(10) .
    "💰 *Valor:* {VALOR_FATURA}" . chr(10) .
    "📅 *Vencimento:* {DATA_VENCIMENTO}" . chr(10) . chr(10) .
    "🔗 *Acesse sua fatura online:* {LINK_FATURA}" . chr(10) . chr(10) .
    "Obrigado por utilizar nossos serviços!";

$modeloAtual = !empty($config['wa_modelo_mensagem']) ? $config['wa_modelo_mensagem'] : $modeloPadrao;

$modeloAvisoPadrao = "⚠️ *AVISO DE VENCIMENTO - ALERTA DE BLOQUEIO*" . chr(10) . chr(10) .
    "Olá, *{NOME_CLIENTE}*!" . chr(10) .
    "Identificamos que sua fatura de internet no valor de *{VALOR_FATURA}* com vencimento em *{DATA_VENCIMENTO}* continua pendente." . chr(10) . chr(10) .
    "O seu acesso entrará em *bloqueio automático* caso o pagamento não seja identificado." . chr(10) . chr(10) .
    "🔗 *Acesse sua fatura online:* {LINK_FATURA}" . chr(10) . chr(10) .
    "Para evitar o corte do sinal, efetue o pagamento pelo PIX Copia e Cola abaixo:";

$modeloAvisoAtual = !empty($config['wa_modelo_aviso']) ? $config['wa_modelo_aviso'] : $modeloAvisoPadrao;

$modeloConfirmacaoPadrao = "✅ *PAGAMENTO CONFIRMADO!*" . chr(10) . chr(10) .
    "Olá, *{NOME_CLIENTE}*!" . chr(10) .
    "Confirmamos o recebimento do seu pagamento no valor de *{VALOR_FATURA}* referente à fatura #{FATURA_ID}." . chr(10) . chr(10) .
    "Seu acesso à internet continua ativo. Agradecemos a preferência!";

$modeloConfirmacaoAtual = !empty($config['wa_modelo_confirmacao']) ? $config['wa_modelo_confirmacao'] : $modeloConfirmacaoPadrao;

$limit = 25;
$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$statusFilter = isset($_GET['status']) ? sanitize($_GET['status']) : '';
$where = "WHERE 1=1";
$params = array();

if ($statusFilter !== '') {
    $where = $where . " AND status = ?";
    $params[] = $statusFilter;
}

$totalLogs = 0;
$logs = array();
try {
    $stmtCount = $db->prepare("SELECT COUNT(*) FROM whatsapp_disparos " . $where);
    $stmtCount->execute($params);
    $totalLogs = (int)$stmtCount->fetchColumn();

    $stmtLogs = $db->prepare("SELECT * FROM whatsapp_disparos " . $where . " ORDER BY id DESC LIMIT " . $limit . " OFFSET " . $offset);
    $stmtLogs->execute($params);
    $logs = $stmtLogs->fetchAll();
} catch (Exception $e) {
}

$totalPages = ceil($totalLogs / $limit);
if ($totalPages < 1) $totalPages = 1;

$activeTab = isset($_GET['tab']) ? sanitize($_GET['tab']) : 'controlador';

$pendentes = array();
$totalCobrancas = 0;
$totalAvisos = 0;
try {
    $diasAntecedencia = (int)($config['wa_disparo_dias'] ?? 3);
    $toleranciaAviso  = (int)($config['wa_aviso_tolerancia'] ?? 5);
    $avisoAtivo       = (int)($config['wa_aviso_ativo'] ?? 1);

    $dataLimite = date('Y-m-d', strtotime("+" . $diasAntecedencia . " days"));

    // 1. Cobrança Preventiva (antes do vencimento)
    $sqlCob = "SELECT c.id as cliente_id, c.nome, c.whatsapp, c.pppoe_usuario, p.nome as plano_nome, p.valor as plano_valor, f.id as fatura_id, f.valor, f.data_vencimento, f.status as fatura_status, f.descricao, 'cobranca' as etapa_envio, 0 as dias_atraso, mph.status as history_status 
               FROM clientes c 
               JOIN faturas f ON f.cliente_id = c.id 
               LEFT JOIN planos p ON c.plano_id = p.id 
               LEFT JOIN mikrotik_payment_history mph ON mph.cliente_id = c.id AND mph.ano = YEAR(f.data_vencimento) AND mph.mes = MONTH(f.data_vencimento) 
               WHERE c.status = 'ativo' AND f.status = 'pendente' AND f.notificado_wa = 0 AND f.data_vencimento BETWEEN CURRENT_DATE() AND ? 
               ORDER BY f.data_vencimento ASC LIMIT 50";
    $stmtCob = $db->prepare($sqlCob);
    $stmtCob->execute([$dataLimite]);
    $listCob = $stmtCob->fetchAll();
    $totalCobrancas = count($listCob);

    // 2. Aviso Pré-Bloqueio (na carência de 5 dias após vencimento)
    $listAvi = [];
    if ($avisoAtivo) {
        $sqlAvi = "SELECT c.id as cliente_id, c.nome, c.whatsapp, c.pppoe_usuario, p.nome as plano_nome, p.valor as plano_valor, f.id as fatura_id, f.valor, f.data_vencimento, f.status as fatura_status, f.descricao, 'aviso_bloqueio' as etapa_envio, DATEDIFF(CURRENT_DATE(), f.data_vencimento) as dias_atraso, mph.status as history_status 
                   FROM clientes c 
                   JOIN faturas f ON f.cliente_id = c.id 
                   LEFT JOIN planos p ON c.plano_id = p.id 
                   LEFT JOIN mikrotik_payment_history mph ON mph.cliente_id = c.id AND mph.ano = YEAR(f.data_vencimento) AND mph.mes = MONTH(f.data_vencimento) 
                   WHERE c.status = 'ativo' AND f.status IN ('pendente', 'atrasado') AND f.notificado_aviso = 0 AND f.data_vencimento < CURRENT_DATE() AND DATEDIFF(CURRENT_DATE(), f.data_vencimento) BETWEEN 1 AND ? 
                   ORDER BY f.data_vencimento ASC LIMIT 50";
        $stmtAvi = $db->prepare($sqlAvi);
        $stmtAvi->execute([$toleranciaAviso]);
        $listAvi = $stmtAvi->fetchAll();
        $totalAvisos = count($listAvi);
    }

    $pendentes = array_merge($listCob, $listAvi);
} catch (Exception $e) {
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="text-white fw-bold mb-1"><i class="fa-brands fa-whatsapp text-success me-2"></i>Controle de Disparos WhatsApp</h4>
        <p class="text-secondary small mb-0">Agendamento inteligente sem necessidade de Cron Jobs no cPanel</p>
    </div>
</div>

<?php if ($msgSuccess) { ?>
    <div class="alert alert-success alert-dismissible fade show"><?php echo $msgSuccess; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php } ?>
<?php if ($msgError) { ?>
    <div class="alert alert-danger alert-dismissible fade show"><?php echo $msgError; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php } ?>

<ul class="nav nav-tabs border-dark mb-4" id="disparosTabs" role="tablist">
    <li class="nav-item">
        <a class="nav-link text-light <?php echo ($activeTab === 'controlador') ? 'active bg-dark border-dark' : 'border-transparent'; ?>" id="controlador-tab" href="?tab=controlador"><i class="fa-solid fa-gears me-2 text-info"></i>Configuração e Disparo</a>
    </li>
    <li class="nav-item">
        <a class="nav-link text-light <?php echo ($activeTab === 'modelo') ? 'active bg-dark border-dark' : 'border-transparent'; ?>" id="modelo-tab" href="?tab=modelo"><i class="fa-solid fa-pen-to-square me-2 text-warning"></i>Modelos de Mensagem</a>
    </li>
    <li class="nav-item">
        <a class="nav-link text-light <?php echo ($activeTab === 'historico') ? 'active bg-dark border-dark' : 'border-transparent'; ?>" id="historico-tab" href="?tab=historico"><i class="fa-solid fa-list-check me-2 text-success"></i>Histórico de Disparos</a>
    </li>
</ul>

<div class="tab-content" id="disparosTabsContent">

<?php if ($activeTab === 'controlador') { ?>
    <div class="tab-pane fade show active">
        <div class="row g-4">
            <div class="col-md-5">
                <div class="card-custom h-100">
                    <h5 class="text-white fw-bold mb-3"><i class="fa-solid fa-sliders text-info me-2"></i>Ajustes do Agendamento</h5>
                    <form method="POST" action="?tab=controlador">
                        <input type="hidden" name="action" value="salvar_disparos">

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="wa_disparo_ativo" id="wa_disparo_ativo" <?php echo ($config['wa_disparo_ativo'] ?? 0) ? 'checked' : ''; ?>>
                            <label class="form-check-label text-light fw-bold" for="wa_disparo_ativo">Ativar Disparos Automáticos Diários</label>
                            <div class="text-secondary small">Se ativado, o servidor Node.js executará os disparos diários no horário definido.</div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small text-secondary">Horário Diário</label>
                                <input type="time" name="wa_disparo_hora" class="form-control-custom" value="<?php echo sanitize($config['wa_disparo_hora'] ?? '08:00'); ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small text-secondary">Espera p/ Mensagem & Cliente (s)</label>
                                <input type="number" name="wa_disparo_delay" id="wa_disparo_delay" class="form-control-custom" value="<?php echo (int)($config['wa_disparo_delay'] ?? 5); ?>" min="2" max="60" required onchange="document.getElementById('wa_delay_pix').value = this.value;">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small text-secondary">Delay da 2ª Mensagem - Chave PIX (Segundos)</label>
                            <input type="number" name="wa_delay_pix" id="wa_delay_pix" class="form-control-custom" value="<?php echo (int)($config['wa_disparo_delay'] ?? ($config['wa_delay_pix'] ?? 5)); ?>" min="2" max="60" readonly style="opacity: 0.85;">
                            <div class="text-secondary small"><i class="fa-solid fa-lock me-1"></i>Unificado com o tempo de espera para simular digitação e prevenir bloqueios no WhatsApp.</div>
                        </div>

                        <!-- 1º DISPARO -->
                        <div class="border-top border-secondary pt-3 mt-3">
                            <h6 class="text-info fw-bold mb-2"><i class="fa-solid fa-file-invoice-dollar me-2"></i>1º Disparo: Cobrança Preventiva</h6>
                            <div class="mb-2">
                                <label class="form-label small text-secondary">Dias de Antecedência do Vencimento</label>
                                <input type="number" name="wa_disparo_dias" class="form-control-custom" value="<?php echo (int)($config['wa_disparo_dias'] ?? 3); ?>" min="1" max="15" required>
                                <div class="text-secondary small">Envia a fatura e chave PIX para clientes que vencerão em até X dias.</div>
                            </div>
                        </div>

                        <!-- 2º DISPARO -->
                        <div class="border-top border-secondary pt-3 mt-3 mb-4">
                            <h6 class="text-warning fw-bold mb-2"><i class="fa-solid fa-triangle-exclamation me-2"></i>2º Disparo: Aviso Pré-Bloqueio (Tolerância MikroTik)</h6>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="wa_aviso_ativo" id="wa_aviso_ativo" <?php echo ($config['wa_aviso_ativo'] ?? 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label text-light fw-bold" for="wa_aviso_ativo">Ativar Alerta Pré-Bloqueio</label>
                                <div class="text-secondary small">Envia aviso com PIX aos clientes vencidos durante o período de carência.</div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small text-secondary">Dias de Tolerância antes do Bloqueio MikroTik</label>
                                <input type="number" name="wa_aviso_tolerancia" class="form-control-custom" value="<?php echo (int)($config['wa_aviso_tolerancia'] ?? 5); ?>" min="1" max="15" required>
                                <div class="text-secondary small">Janela de carência (padrão: 5 dias). No 6º dia o script MikroTik bloqueia o cliente.</div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary-custom w-100"><i class="fa-solid fa-floppy-disk me-2"></i>Salvar Ajustes</button>
                    </form>
                </div>
            </div>

            <div class="col-md-7">
                <div class="card-custom h-100 d-flex flex-column">

                  <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="text-white fw-bold mb-0"><i class="fa-solid fa-clock text-danger me-2"></i>Fila de Clientes p/ Próximo Disparo</h5>
                    <span class="badge bg-danger fs-6 py-2 px-3" id="badgeTotalPendentes"><?php echo count($pendentes); ?> pendente(s)</span>
                  </div>
                  <p class="text-secondary small mb-3">Inclui 1º disparo (cobrança preventiva) e 2º disparo (alerta pré-bloqueio na tolerância).</p>

                  <?php if (empty($pendentes)) { ?>
                      <div class="text-center py-5 text-secondary">
                          <i class="fa-solid fa-check-circle text-success fs-1 mb-3 d-block"></i>
                          <h5 class="text-white">Nenhum cliente com fatura pendente</h5>
                          <p class="small">Todos os clientes estão em dia ou não há faturas no período configurado.</p>
                      </div>
                  <?php } else { ?>
                      <div class="table-responsive">
                          <table class="table-custom">
                              <thead>
                                  <tr>
                                      <th>Cliente</th>
                                      <th>Plano</th>
                                      <th>Valor</th>
                                      <th>Vencimento</th>
                                      <th>Etapa Envio</th>
                                      <th>WhatsApp</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php foreach ($pendentes as $p) { ?>
                                       <?php
                                           $whatsapp = !empty($p['whatsapp']) ? WhatsAppService::sanitizePhone($p['whatsapp']) : 'Não cadastrado';
                                           $diasRestantes = (int)ceil((strtotime($p['data_vencimento']) - time()) / 86400);
                                           $isAviso = ($p['etapa_envio'] ?? '') === 'aviso_bloqueio';
                                       ?>
                                       <tr>
                                           <td>
                                               <strong class="text-white"><?php echo htmlspecialchars($p['nome']); ?></strong>
                                               <br><small class="text-secondary"><?php echo htmlspecialchars($p['pppoe_usuario']); ?></small>
                                           </td>
                                           <td><span class="badge bg-dark border border-secondary text-info"><?php echo htmlspecialchars($p['plano_nome'] ?? 'N/A'); ?></span></td>
                                           <td class="fw-bold text-success">R$ <?php echo number_format($p['valor'] ?? 0, 2, ',', '.'); ?></td>
                                           <td>
                                               <?php echo date('d/m/Y', strtotime($p['data_vencimento'])); ?>
                                               <?php if ($diasRestantes < 0) { ?>
                                                   <br><span class="badge bg-danger"><?php echo abs($diasRestantes); ?>d atrasado</span>
                                               <?php } else if ($diasRestantes <= 3) { ?>
                                                   <br><span class="badge bg-warning text-dark"><?php echo $diasRestantes; ?>d restantes</span>
                                               <?php } ?>
                                           </td>
                                           <td>
                                               <?php if ($isAviso) { ?>
                                                   <span class="badge bg-warning text-dark" style="font-size:0.72rem; padding:5px 8px; border-radius:12px">
                                                       <i class="fa-solid fa-triangle-exclamation me-1"></i>2º Aviso Pré-Bloqueio
                                                   </span>
                                               <?php } else { ?>
                                                   <span class="badge bg-info text-dark" style="font-size:0.72rem; padding:5px 8px; border-radius:12px">
                                                       <i class="fa-solid fa-file-invoice-dollar me-1"></i>1º Cobrança
                                                   </span>
                                               <?php } ?>
                                               <?php if ($p['history_status'] === 'overdue') { ?>
                                                   <br><span class="badge bg-danger bg-opacity-50 text-light small mt-1">Bloqueado Mikrotik</span>
                                               <?php } ?>
                                           </td>
                                           <td>
                                               <?php if ($whatsapp !== 'Não cadastrado') { ?>
                                                   <a href="https://wa.me/<?php echo WhatsAppService::formatPhone($whatsapp); ?>" target="_blank" class="text-success text-decoration-none">
                                                       <i class="fa-brands fa-whatsapp me-1"></i><?php echo htmlspecialchars($whatsapp); ?>
                                                   </a>
                                               <?php } else { ?>
                                                   <span class="text-secondary small"><?php echo $whatsapp; ?></span>
                                               <?php } ?>
                                           </td>
                                       </tr>
                                  <?php } ?>
                              </tbody>
                          </table>
                      </div>
                  <?php } ?>

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
<?php } ?>

<?php if ($activeTab === 'modelo') { 
    $subModelo = sanitize($_GET['sub'] ?? 'cobranca');
?>
    <div class="tab-pane fade show active">
        <!-- Sub-navegação entre modelos -->
        <ul class="nav nav-pills mb-4 gap-2">
            <li class="nav-item">
                <a class="nav-link <?php echo ($subModelo === 'cobranca') ? 'active bg-warning text-dark fw-bold' : 'text-light bg-dark border border-secondary'; ?>" href="?tab=modelo&sub=cobranca">
                    <i class="fa-solid fa-file-invoice-dollar me-2"></i>1. Mensagem de Cobrança (Fatura + PIX)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($subModelo === 'aviso') ? 'active bg-danger text-white fw-bold' : 'text-light bg-dark border border-secondary'; ?>" href="?tab=modelo&sub=aviso">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i>2. Aviso Pré-Bloqueio (Alerta + PIX)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($subModelo === 'confirmacao') ? 'active bg-success text-white fw-bold' : 'text-light bg-dark border border-secondary'; ?>" href="?tab=modelo&sub=confirmacao">
                    <i class="fa-solid fa-circle-check me-2"></i>3. Confirmação de Pagamento (Recibo)
                </a>
            </li>
        </ul>

        <?php if ($subModelo === 'cobranca') { ?>
            <!-- CARD 1: MODELO DE COBRANÇA -->
            <div class="card-custom">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h5 class="text-white fw-bold mb-1"><i class="fa-solid fa-file-invoice-dollar text-warning me-2"></i>Modelo de Mensagem de Cobrança</h5>
                        <p class="text-secondary small mb-0">Mensagem enviada aos clientes antes do vencimento (1º Disparo). Use as variáveis entre chaves.</p>
                    </div>
                    <a href="?tab=modelo&sub=cobranca&reset=cobranca" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Restaurar modelo de cobrança padrão?')">
                        <i class="fa-solid fa-rotate-left me-1"></i>Restaurar Padrão
                    </a>
                </div>

                <div class="row g-4">
                    <div class="col-md-7">
                        <form method="POST" action="?tab=modelo&sub=cobranca">
                            <input type="hidden" name="action" value="salvar_modelo">

                            <div class="mb-3">
                                <label class="form-label small text-secondary fw-bold">Corpo da Mensagem de Cobrança</label>
                                <textarea name="wa_modelo_mensagem" class="form-control-custom" rows="12" style="font-family: monospace; font-size: 0.9rem; line-height: 1.7;"><?php echo htmlspecialchars($modeloAtual); ?></textarea>
                                <div class="text-secondary small mt-2">
                                    <i class="fa-solid fa-info-circle me-1"></i>
                                    A chave PIX Copia e Cola é sempre enviada automaticamente em uma segunda mensagem de texto puro, facilitando a cópia no celular.
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary-custom w-100"><i class="fa-solid fa-floppy-disk me-2"></i>Salvar Modelo de Cobrança</button>
                        </form>
                    </div>

                    <div class="col-md-5">
                        <div class="bg-dark p-3 rounded border border-secondary">
                            <h6 class="text-white fw-bold"><i class="fa-solid fa-eye text-success me-2"></i>Prévia da Mensagem</h6>
                            <div class="bg-black p-3 rounded text-light small" style="white-space: pre-wrap; font-family: monospace; line-height: 1.6; border: 1px solid #334155;">
<?php
$exemplo = str_replace(
    array('{NOME_CLIENTE}', '{PPPOE_USUARIO}', '{VALOR_FATURA}', '{DATA_VENCIMENTO}', '{LINK_FATURA}', '{PLANO_NOME}', '{EMPRESA_NOME}'),
    array('João Silva', 'joao.silva', 'R$ 89,90', '10/09/2026', (defined('BASE_URL') ? BASE_URL : 'https://aplicacao.spaconett.com') . '/cliente/fatura.php?id=123', 'S1-40Megas-01', $config['empresa_nome'] ?? 'SpacoNett'),
    $modeloAtual
);
echo htmlspecialchars($exemplo);
?>
                            </div>
                            <div class="text-secondary small mt-2">
                                <i class="fa-brands fa-whatsapp text-success me-1"></i>
                                Em seguida, após <?php echo (int)($config['wa_delay_pix'] ?? 3); ?> segundos, é enviada a segunda mensagem contendo a chave PIX Copia e Cola.
                            </div>
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
                                        <tr><td><code>{VALOR_FATURA}</code></td><td class="text-secondary">Valor formatado (R$ 89,90)</td></tr>
                                        <tr><td><code>{DATA_VENCIMENTO}</code></td><td class="text-secondary">Data de vencimento (10/09/2026)</td></tr>
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
        <?php } elseif ($subModelo === 'aviso') { ?>
            <!-- CARD 2: MODELO DE AVISO PRÉ-BLOQUEIO -->
            <div class="card-custom">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h5 class="text-white fw-bold mb-1"><i class="fa-solid fa-triangle-exclamation text-danger me-2"></i>Modelo de Aviso Pré-Bloqueio (2º Disparo)</h5>
                        <p class="text-secondary small mb-0">Mensagem de urgência enviada na janela de tolerância de 5 dias após o vencimento, antes do corte pelo script MikroTik.</p>
                    </div>
                    <a href="?tab=modelo&sub=aviso&reset=aviso" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Restaurar modelo de aviso padrão?')">
                        <i class="fa-solid fa-rotate-left me-1"></i>Restaurar Padrão
                    </a>
                </div>

                <div class="row g-4">
                    <div class="col-md-7">
                        <form method="POST" action="?tab=modelo&sub=aviso">
                            <input type="hidden" name="action" value="salvar_modelo_aviso">

                            <div class="mb-3">
                                <label class="form-label small text-secondary fw-bold">Corpo do Aviso de Pré-Bloqueio</label>
                                <textarea name="wa_modelo_aviso" class="form-control-custom" rows="12" style="font-family: monospace; font-size: 0.9rem; line-height: 1.7;"><?php echo htmlspecialchars($modeloAvisoAtual); ?></textarea>
                                <div class="text-secondary small mt-2">
                                    <i class="fa-solid fa-info-circle me-1"></i>
                                    A chave PIX Copia e Cola também é enviada automaticamente após esta mensagem para facilitar o pagamento imediato.
                                </div>
                            </div>

                            <button type="submit" class="btn btn-danger w-100"><i class="fa-solid fa-floppy-disk me-2"></i>Salvar Modelo de Aviso Pré-Bloqueio</button>
                        </form>
                    </div>

                    <div class="col-md-5">
                        <div class="bg-dark p-3 rounded border border-secondary">
                            <h6 class="text-white fw-bold"><i class="fa-solid fa-eye text-warning me-2"></i>Prévia do Alerta</h6>
                            <div class="bg-black p-3 rounded text-light small" style="white-space: pre-wrap; font-family: monospace; line-height: 1.6; border: 1px solid #334155;">
<?php
$exemploAvi = str_replace(
    array('{NOME_CLIENTE}', '{PPPOE_USUARIO}', '{VALOR_FATURA}', '{DATA_VENCIMENTO}', '{LINK_FATURA}', '{PLANO_NOME}', '{EMPRESA_NOME}', '{DIAS_ATRASO}'),
    array('João Silva', 'joao.silva', 'R$ 89,90', '01/09/2026', (defined('BASE_URL') ? BASE_URL : 'https://aplicacao.spaconett.com') . '/cliente/fatura.php?id=123', 'S1-40Megas-01', $config['empresa_nome'] ?? 'SpacoNett', '3 dia(s)'),
    $modeloAvisoAtual
);
echo htmlspecialchars($exemploAvi);
?>
                            </div>
                            <div class="text-secondary small mt-2">
                                <i class="fa-brands fa-whatsapp text-success me-1"></i>
                                Em seguida, após <?php echo (int)($config['wa_delay_pix'] ?? 3); ?> segundos, é enviada a segunda mensagem com a chave PIX.
                            </div>
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
                                        <tr><td><code>{VALOR_FATURA}</code></td><td class="text-secondary">Valor formatado (R$ 89,90)</td></tr>
                                        <tr><td><code>{DATA_VENCIMENTO}</code></td><td class="text-secondary">Data de vencimento</td></tr>
                                        <tr><td><code>{DIAS_ATRASO}</code></td><td class="text-secondary">Quantidade de dias vencida (ex: 3 dia(s))</td></tr>
                                        <tr><td><code>{LINK_FATURA}</code></td><td class="text-secondary">Link da fatura online</td></tr>
                                        <tr><td><code>{PLANO_NOME}</code></td><td class="text-secondary">Nome do plano do cliente</td></tr>
                                        <tr><td><code>{EMPRESA_NOME}</code></td><td class="text-secondary">Nome da sua empresa</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php } else { ?>
            <!-- CARD 3: MODELO DE CONFIRMAÇÃO DE PAGAMENTO -->
            <div class="card-custom">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h5 class="text-white fw-bold mb-1"><i class="fa-solid fa-circle-check text-success me-2"></i>Modelo de Confirmação de Pagamento (Recibo)</h5>
                        <p class="text-secondary small mb-0">Mensagem enviada automaticamente para o WhatsApp do cliente quando o pagamento é aprovado (Mercado Pago ou Baixa Manual).</p>
                    </div>
                    <a href="?tab=modelo&sub=confirmacao&reset=confirmacao" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Restaurar modelo de confirmação padrão?')">
                        <i class="fa-solid fa-rotate-left me-1"></i>Restaurar Padrão
                    </a>
                </div>

                <div class="row g-4">
                    <div class="col-md-7">
                        <form method="POST" action="?tab=modelo&sub=confirmacao">
                            <input type="hidden" name="action" value="salvar_modelo_confirmacao">

                            <div class="mb-3">
                                <label class="form-label small text-secondary fw-bold">Corpo da Mensagem de Confirmação</label>
                                <textarea name="wa_modelo_confirmacao" class="form-control-custom" rows="12" style="font-family: monospace; font-size: 0.9rem; line-height: 1.7;"><?php echo htmlspecialchars($modeloConfirmacaoAtual); ?></textarea>
                                <div class="text-secondary small mt-2">
                                    <i class="fa-solid fa-info-circle me-1"></i>
                                    Esta mensagem é disparada instantaneamente após a aprovação do PIX ou baixa manual pelo painel.
                                </div>
                            </div>

                            <button type="submit" class="btn btn-success w-100"><i class="fa-solid fa-floppy-disk me-2"></i>Salvar Modelo de Confirmação</button>
                        </form>
                    </div>

                    <div class="col-md-5">
                        <div class="bg-dark p-3 rounded border border-secondary">
                            <h6 class="text-white fw-bold"><i class="fa-solid fa-eye text-success me-2"></i>Prévia do Recibo</h6>
                            <div class="bg-black p-3 rounded text-light small" style="white-space: pre-wrap; font-family: monospace; line-height: 1.6; border: 1px solid #334155;">
<?php
$exemploConf = str_replace(
    array('{NOME_CLIENTE}', '{VALOR_FATURA}', '{FATURA_ID}', '{DATA_PAGAMENTO}', '{PLANO_NOME}', '{EMPRESA_NOME}'),
    array('João Silva', 'R$ 89,90', '12', date('d/m/Y H:i'), 'S1-40Megas-01', $config['empresa_nome'] ?? 'SpacoNett'),
    $modeloConfirmacaoAtual
);
echo htmlspecialchars($exemploConf);
?>
                            </div>
                            <div class="text-secondary small mt-2">
                                <i class="fa-solid fa-shield-halved text-info me-1"></i>
                                Enviado em canal único e registrado com status no Histórico de Disparos.
                            </div>
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
                                        <tr><td><code>{VALOR_FATURA}</code></td><td class="text-secondary">Valor pago formatado (R$ 89,90)</td></tr>
                                        <tr><td><code>{FATURA_ID}</code></td><td class="text-secondary">Número da fatura paga (ex: 12)</td></tr>
                                        <tr><td><code>{DATA_PAGAMENTO}</code></td><td class="text-secondary">Data e hora do pagamento confirmado</td></tr>
                                        <tr><td><code>{PLANO_NOME}</code></td><td class="text-secondary">Nome do plano (S1-40Megas-01)</td></tr>
                                        <tr><td><code>{EMPRESA_NOME}</code></td><td class="text-secondary">Nome da sua empresa</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php } ?>
    </div>
<?php } ?>

<?php if ($activeTab === 'historico') { ?>
    <div class="tab-pane fade show active">
        <div class="card-custom mb-3">
            <form method="GET" action="" class="row g-2 align-items-center">
                <input type="hidden" name="tab" value="historico">
                <div class="col-md-3">
                    <select name="status" class="form-control-custom">
                        <option value="">Todos os status</option>
                        <option value="enviado" <?php echo ($statusFilter === 'enviado') ? 'selected' : ''; ?>>Enviado com sucesso</option>
                        <option value="falhou" <?php echo ($statusFilter === 'falhou') ? 'selected' : ''; ?>>Falha no envio</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary-custom w-100"><i class="fa-solid fa-filter me-1"></i>Filtrar</button>
                </div>
            </form>
        </div>

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
                        <?php if (empty($logs)) { ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-secondary">Nenhum log de disparo encontrado.</td>
                            </tr>
                        <?php } else { ?>
                            <?php foreach ($logs as $log) { ?>
                                <tr>
                                    <td><?php echo formatData($log['criado_em']); ?> <?php echo date('H:i:s', strtotime($log['criado_em'])); ?></td>
                                    <td class="text-white fw-bold"><?php echo htmlspecialchars($log['nome_cliente']); ?></td>
                                    <td><?php echo htmlspecialchars(WhatsAppService::sanitizePhone($log['whatsapp'])); ?></td>
                                    <td><a href="faturas.php?busca=<?php echo $log['fatura_id']; ?>" class="text-info">#<?php echo $log['fatura_id']; ?></a></td>
                                    <td>
                                        <?php if ($log['tipo'] === 'aviso_bloqueio') { ?>
                                            <span class="badge bg-warning text-dark"><i class="fa-solid fa-triangle-exclamation me-1"></i>Aviso Bloqueio</span>
                                        <?php } elseif ($log['tipo'] === 'confirmacao') { ?>
                                            <span class="badge bg-success"><i class="fa-solid fa-circle-check me-1"></i>Recibo</span>
                                        <?php } else { ?>
                                            <span class="badge bg-info text-dark"><i class="fa-solid fa-file-invoice-dollar me-1"></i>Cobrança</span>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <?php if ($log['status'] === 'enviado') { ?>
                                            <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Enviado</span>
                                        <?php } else { ?>
                                            <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1"></i>Falhou</span>
                                        <?php } ?>
                                    </td>
                                    <td class="small text-secondary"><?php echo htmlspecialchars($log['erro_msg'] ?? 'Sem detalhes'); ?></td>
                                </tr>
                            <?php } ?>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1) { ?>
                <div class="d-flex justify-content-center mt-3">
                    <nav>
                        <ul class="pagination pagination-dark mb-0">
                            <?php for ($i = 1; $i <= $totalPages; $i++) { ?>
                                <li class="page-item <?php echo ($page === $i) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?tab=historico&page=<?php echo $i; ?>&status=<?php echo $statusFilter; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php } ?>
                        </ul>
                    </nav>
                </div>
            <?php } ?>
        </div>
    </div>
<?php } ?>

</div>

<script>
function verificarFaturas() {
    fetch('../cron/disparar_whatsapp.php?action=contar_pendentes')
        .then(function(res) { return res.json(); })
        .then(function(data) {
            var badgePendentes = document.getElementById('badgeTotalPendentes') || document.querySelector('.badge.bg-danger.fs-6');
            if (badgePendentes) {
                if (data.total > 0) {
                    badgePendentes.textContent = data.total + ' pendente(s) (' + (data.cobrancas || 0) + ' cobr. + ' + (data.avisos || 0) + ' avisos)';
                } else {
                    badgePendentes.textContent = '0 pendente(s)';
                }
            }
        })
        .catch(function(e) { console.error('Erro ao verificar faturas:', e); });
}

function iniciarDisparo() {
    var btnDisp = document.getElementById('btnDisparar');
    var btnVerif = document.getElementById('btnVerificar');
    var prog = document.getElementById('progressoEnvio');
    var pBar = document.getElementById('progressBar');
    var pStat = document.getElementById('progressoStatus');
    var area = document.getElementById('areaLogs');

    btnDisp.disabled = true;
    btnVerif.disabled = true;
    prog.style.display = 'block';
    area.style.display = 'block';
    pBar.style.width = '0%';
    pBar.innerHTML = '0%';
    pBar.className = 'progress-bar bg-success progress-bar-striped progress-bar-animated';
    pStat.innerText = 'Inicializando conexao com a fila...';
    area.innerHTML = '[INFO] Iniciando processo de disparo...\n';

    fetch('../cron/disparar_whatsapp.php?action=contar_pendentes')
        .then(function(resTotal) { return resTotal.json(); })
        .then(function(dataTotal) {
            var total = dataTotal.total;

            if (total === 0) {
                pStat.innerText = 'Nenhuma fatura pendente encontrada para envio hoje!';
                pBar.className = 'progress-bar bg-info';
                pBar.style.width = '100%';
                pBar.innerHTML = 'Concluido';
                btnDisp.disabled = false;
                btnVerif.disabled = false;
                return;
            }

            area.innerHTML += '[INFO] ' + total + ' faturas na fila. Iniciando disparos em lote...\n';

            return fetch('../cron/disparar_whatsapp.php?action=dispatch')
                .then(function(resDispatch) { return resDispatch.json(); })
                .then(function(dData) {
                    if (dData.sucesso) {
                        pBar.style.width = '100%';
                        pBar.innerHTML = '100%';
                        pBar.className = 'progress-bar bg-success';
                        pStat.innerText = 'Processo concluido! Sucessos: ' + dData.enviados + ' | Falhas: ' + dData.falhos;

                        dData.resultados.forEach(function(r) {
                            var icon = (r.status === 'enviado') ? 'OK' : 'FALHOU';
                            var linha = '[' + r.status.toUpperCase() + '] ' + icon + ' Cliente: ' + r.cliente + ' (' + r.whatsapp + ') - Fatura #' + r.fatura_id;
                            if (r.status !== 'enviado' && r.erro) {
                                linha += ' | Motivo: ' + r.erro;
                            }
                            area.innerHTML += linha + '\n';
                        });
                        area.innerHTML += '[INFO] Lote encerrado.\n';
                    } else {
                        throw new Error(dData.error || 'Erro desconhecido');
                    }
                });
        })
        .catch(function(e) {
            pStat.innerText = 'Ocorreu um erro no processo de disparo.';
            pBar.className = 'progress-bar bg-danger';
            pBar.style.width = '100%';
            pBar.innerHTML = 'Falha';
            area.innerHTML += '[ERRO] ' + e.message + '\n';
        })
        .finally(function() {
            btnDisp.disabled = false;
            btnVerif.disabled = false;
            verificarFaturas();
        });
}

document.addEventListener('DOMContentLoaded', function() {
    verificarFaturas();
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
