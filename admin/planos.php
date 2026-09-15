<?php
require_once __DIR__ . '/header.php';

$db = Database::getInstance();
$msgSuccess = '';
$msgError = '';

$mkApi = new MikrotikAPI();
$mkProfiles = $mkApi->getProfiles();
$mkProfileNames = [];
if (!empty($mkProfiles)) {
    foreach ($mkProfiles as $mp) {
        if (isset($mp['name'])) $mkProfileNames[] = $mp['name'];
    }
}

// 1. Cadastrar Novo Plano
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cadastrar') {
    $nome = sanitize($_POST['nome'] ?? '');
    $prefixoGrupo = sanitize($_POST['prefixo_grupo'] ?? 'S1');
    $profileMikrotik = sanitize($_POST['profile_mikrotik'] ?? '');
    $profileAviso = sanitize($_POST['profile_aviso'] ?? '');
    $profileBloqueado = sanitize($_POST['profile_bloqueado'] ?? '');
    $velocidadeDown = sanitize($_POST['velocidade_down'] ?? '40M');
    $velocidadeUp = sanitize($_POST['velocidade_up'] ?? '20M');
    $valor = (float)str_replace(',', '.', $_POST['valor'] ?? '0');
    $diasValidade = (int)($_POST['dias_validade'] ?? 30);
    $descricao = sanitize($_POST['descricao'] ?? '');

    // Se aviso não preenchido, auto-gera a partir do normal: /Espera -> /Aviso
    if (empty($profileAviso) && !empty($profileMikrotik)) {
        $profileAviso = str_replace(['/Espera','/NORMAL','/Espera','.NORMAL'], ['/Aviso','/AVISO','/Aviso','/AVISO'], $profileMikrotik);
    }

    if ($nome && $profileMikrotik && $valor >= 0) {
        try {
            $stmt = $db->prepare("
                INSERT INTO planos (nome, prefixo_grupo, profile_mikrotik, profile_aviso, profile_bloqueado, velocidade_down, velocidade_up, valor, dias_validade, descricao) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nome, $prefixoGrupo, $profileMikrotik, $profileAviso, $profileBloqueado, $velocidadeDown, $velocidadeUp, $valor, $diasValidade, $descricao]);
            $msgSuccess = "Plano {$nome} cadastrado com sucesso! Aviso: {$profileAviso}";
        } catch (Exception $e) {
            $msgError = "Erro ao cadastrar plano: " . $e->getMessage();
        }
    } else {
        $msgError = "Preencha o Nome, Valor e escolha o Profile Normal/Espera do MikroTik.";
    }
}

// 2. Editar Plano Existente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'editar') {
    $id = (int)$_POST['plano_id'];
    $nome = sanitize($_POST['nome'] ?? '');
    $prefixoGrupo = sanitize($_POST['prefixo_grupo'] ?? 'S1');
    $profileMikrotik = sanitize($_POST['profile_mikrotik'] ?? '');
    $profileAviso = sanitize($_POST['profile_aviso'] ?? '');
    $profileBloqueado = sanitize($_POST['profile_bloqueado'] ?? '');
    $velocidadeDown = sanitize($_POST['velocidade_down'] ?? '40M');
    $velocidadeUp = sanitize($_POST['velocidade_up'] ?? '20M');
    $valor = (float)str_replace(',', '.', $_POST['valor'] ?? '0');
    $diasValidade = (int)($_POST['dias_validade'] ?? 30);
    $descricao = sanitize($_POST['descricao'] ?? '');

    if (empty($profileAviso) && !empty($profileMikrotik)) {
        $profileAviso = str_replace(['/Espera','/NORMAL'], ['/Aviso','/AVISO'], $profileMikrotik);
    }

    if ($id && $nome && $profileMikrotik) {
        try {
            $stmt = $db->prepare("
                UPDATE planos SET 
                    nome = ?, prefixo_grupo = ?, profile_mikrotik = ?, profile_aviso = ?, profile_bloqueado = ?, 
                    velocidade_down = ?, velocidade_up = ?, valor = ?, dias_validade = ?, descricao = ? 
                WHERE id = ?
            ");
            $stmt->execute([$nome, $prefixoGrupo, $profileMikrotik, $profileAviso, $profileBloqueado, $velocidadeDown, $velocidadeUp, $valor, $diasValidade, $descricao, $id]);
            $msgSuccess = "Plano #{$id} ({$nome}) atualizado com sucesso!";
        } catch (Exception $e) {
            $msgError = "Erro ao atualizar plano: " . $e->getMessage();
        }
    }
}

// 3. Excluir Plano
if (isset($_GET['action']) && $_GET['action'] === 'excluir' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmtDel = $db->prepare("DELETE FROM planos WHERE id = ?");
    $stmtDel->execute([$id]);
    $msgSuccess = "Plano excluído com sucesso.";
}

$planos = $db->query("SELECT * FROM planos ORDER BY velocidade_down ASC, prefixo_grupo ASC, id ASC")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="text-white fw-bold mb-1"><i class="fa-solid fa-cubes text-info me-2"></i>Gerenciamento de Planos & Perfis</h4>
        <p class="text-secondary small mb-0">Cadastre e edite planos vinculados aos 3 Perfis no MikroTik: Normal (Espera), Aviso e Bloqueado. S1/S2 são equivalentes pelo sufixo.</p>
    </div>
    <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalNovoPlano">
        <i class="fa-solid fa-plus me-1"></i> Criar Novo Plano
    </button>
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


<!-- Tabela Detalhada de Planos -->
<div class="card-custom mb-4">
    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Grupo</th>
                    <th>Plan Name (Plano)</th>
                    <th>Profile Normal / Espera</th>
                    <th>Profile Aviso (NOVO)</th>
                    <th>Blocked Profile (Bloqueado)</th>
                    <th>Velocidade</th>
                    <th>Price (Valor)</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($planos)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4 text-secondary">Nenhum plano cadastrado ainda.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($planos as $p): ?>
                        <tr>
                            <td><strong>#<?= $p['id'] ?></strong></td>
                            <td><span class="badge bg-primary text-white"><?= sanitize($p['prefixo_grupo'] ?? 'S1') ?></span></td>
                            <td><strong class="text-white"><?= sanitize($p['nome']) ?></strong><br><small class="text-secondary"><?= sanitize($p['descricao'] ?? '') ?></small></td>
                            <td><span class="badge bg-success bg-opacity-10 text-success border border-success"><?= sanitize($p['profile_mikrotik']) ?></span></td>
                            <td>
                                <?php if(!empty($p['profile_aviso'])): ?>
                                    <span class="badge bg-warning bg-opacity-10 text-warning border border-warning"><?= sanitize($p['profile_aviso']) ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary bg-opacity-10 text-secondary border">Não definido</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-danger bg-opacity-10 text-danger border border-danger"><?= sanitize($p['profile_bloqueado']) ?></span></td>
                            <td><small class="text-info"><?= sanitize($p['velocidade_down']) ?>/<?= sanitize($p['velocidade_up'] ?? '20M') ?></small></td>
                            <td class="fw-bold text-info"><?= formatMoeda($p['valor']) ?><br><small class="text-secondary"><?= $p['dias_validade'] ?> dias</small></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-info me-1" onclick='editarPlano(<?= json_encode($p, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)' title="Editar"><i class="fa-solid fa-pen"></i></button>
                                <a href="planos.php?action=excluir&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Excluir plano <?= sanitize($p['nome']) ?>?')" title="Excluir"><i class="fa-solid fa-trash"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Novo Plano -->
<div class="modal fade" id="modalNovoPlano" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark text-light border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus text-info me-2"></i>Novo Plano S1/S2</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="cadastrar">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small text-secondary">Prefixo Grupo *</label>
                            <select name="prefixo_grupo" class="form-control-custom" required>
                                <option value="S1">S1 (Servidor 1)</option>
                                <option value="S2">S2 (Servidor 2)</option>
                            </select>
                        </div>
                        <div class="col-md-9">
                            <label class="form-label small text-secondary">Nome do Plano (Plan Name) *</label>
                            <input type="text" name="nome" class="form-control-custom" required placeholder="Ex: S1-40Megas-01 (S2 será S2-40Megas-01)">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-secondary">Profile Normal / Espera *</label>
                            <input type="text" name="profile_mikrotik" list="mkProfilesList" class="form-control-custom" required placeholder="Ex: S1-R.NORMAL-01/Espera">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-secondary">Profile Aviso (NOVO) *</label>
                            <input type="text" name="profile_aviso" list="mkProfilesList" class="form-control-custom" placeholder="Ex: S1-R.NORMAL-01/Aviso (auto)">
                            <small class="text-secondary" style="font-size:0.68rem">Se deixar vazio, cria auto: /Espera → /Aviso</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-secondary">Blocked Profile (Bloqueado) *</label>
                            <input type="text" name="profile_bloqueado" list="mkProfilesList" class="form-control-custom" required placeholder="Ex: S1-R.NORMAL-01/Bloqueado">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-secondary">Preço (R$) *</label>
                            <input type="text" name="valor" class="form-control-custom" required placeholder="50.00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-secondary">Dias Validade *</label>
                            <input type="number" name="dias_validade" class="form-control-custom" required value="30">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-secondary">Down</label>
                            <input type="text" name="velocidade_down" class="form-control-custom" value="40M" placeholder="40M">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-secondary">Up</label>
                            <input type="text" name="velocidade_up" class="form-control-custom" value="20M" placeholder="20M">
                        </div>
                        <div class="col-12">
                            <label class="form-label small text-secondary">Descrição</label>
                            <input type="text" name="descricao" class="form-control-custom" placeholder="Plano 40 Mega Grupo S1">
                        </div>
                    </div>
                    <datalist id="mkProfilesList">
                        <?php foreach($mkProfileNames as $pn): ?>
                            <option value="<?= htmlspecialchars($pn) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary-custom">Salvar Plano</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Plano -->
<div class="modal fade" id="modalEditarPlano" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark text-light border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square text-info me-2"></i>Editar Plano</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="plano_id" id="edit_plano_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small text-secondary">Prefixo Grupo *</label>
                            <select name="prefixo_grupo" id="edit_prefixo_grupo" class="form-control-custom" required>
                                <option value="S1">S1</option>
                                <option value="S2">S2</option>
                            </select>
                        </div>
                        <div class="col-md-9">
                            <label class="form-label small text-secondary">Nome do Plano *</label>
                            <input type="text" name="nome" id="edit_nome" class="form-control-custom" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-secondary">Profile Normal / Espera *</label>
                            <input type="text" name="profile_mikrotik" id="edit_profile_mikrotik" list="mkProfilesList" class="form-control-custom" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-secondary">Profile Aviso (NOVO) *</label>
                            <input type="text" name="profile_aviso" id="edit_profile_aviso" list="mkProfilesList" class="form-control-custom" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-secondary">Blocked Profile (Bloqueado) *</label>
                            <input type="text" name="profile_bloqueado" id="edit_profile_bloqueado" list="mkProfilesList" class="form-control-custom" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-secondary">Preço (R$) *</label>
                            <input type="text" name="valor" id="edit_valor" class="form-control-custom" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-secondary">Dias Validade *</label>
                            <input type="number" name="dias_validade" id="edit_dias_validade" class="form-control-custom" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-secondary">Down</label>
                            <input type="text" name="velocidade_down" id="edit_velocidade_down" class="form-control-custom">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-secondary">Up</label>
                            <input type="text" name="velocidade_up" id="edit_velocidade_up" class="form-control-custom">
                        </div>
                        <div class="col-12">
                            <label class="form-label small text-secondary">Descrição</label>
                            <input type="text" name="descricao" id="edit_descricao" class="form-control-custom">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary-custom">Atualizar Alterações</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editarPlano(p) {
    document.getElementById('edit_plano_id').value = p.id;
    document.getElementById('edit_prefixo_grupo').value = p.prefixo_grupo || 'S1';
    document.getElementById('edit_nome').value = p.nome;
    document.getElementById('edit_profile_mikrotik').value = p.profile_mikrotik;
    document.getElementById('edit_profile_aviso').value = p.profile_aviso || '';
    document.getElementById('edit_profile_bloqueado').value = p.profile_bloqueado;
    document.getElementById('edit_valor').value = p.valor;
    document.getElementById('edit_dias_validade').value = p.dias_validade || 30;
    document.getElementById('edit_velocidade_down').value = p.velocidade_down || '40M';
    document.getElementById('edit_velocidade_up').value = p.velocidade_up || '20M';
    document.getElementById('edit_descricao').value = p.descricao || '';
    const modal = new bootstrap.Modal(document.getElementById('modalEditarPlano'));
    modal.show();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
