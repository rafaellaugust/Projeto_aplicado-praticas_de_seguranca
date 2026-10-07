<?php
/**
 * @file admin/administradores.php
 * @brief Gestão completa de usuários administradores e operadores do sistema
 */

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../src/Security.php';

$db = Database::getInstance();
$currentAdminId = (int)($_SESSION['admin_id'] ?? 0);

$msgSuccess = '';
$msgError = '';

// Processamento de Ações POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrfToken()) {
        $msgError = 'Sessão expirada ou token de segurança inválido. Tente novamente.';
    } else {
        $action = $_POST['action'] ?? '';

        // 1. Cadastrar Novo Administrador
        if ($action === 'cadastrar') {
            $nome = sanitize($_POST['nome'] ?? '');
            $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
            $senha = $_POST['senha'] ?? '';
            $confirmarSenha = $_POST['confirmar_senha'] ?? '';

            if (empty($nome) || !$email) {
                $msgError = 'Preencha um nome válido e um e-mail correto.';
            } elseif (strlen($senha) < 8) {
                $msgError = 'A senha de acesso deve possuir no mínimo 8 caracteres.';
            } elseif ($senha !== $confirmarSenha) {
                $msgError = 'A confirmação de senha não confere com a senha informada.';
            } else {
                $stmtCheck = $db->prepare("SELECT id FROM administradores WHERE email = ?");
                $stmtCheck->execute([$email]);
                if ($stmtCheck->fetch()) {
                    $msgError = 'Já existe um administrador cadastrado com este e-mail.';
                } else {
                    try {
                        $hashSenha = password_hash($senha, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("INSERT INTO administradores (nome, email, senha, totp_enabled, criado_em) VALUES (?, ?, ?, 0, NOW())");
                        $stmt->execute([$nome, $email, $hashSenha]);
                        $novoId = $db->lastInsertId();

                        Database::log('admin_created', "Novo administrador cadastrado: {$email} (ID: {$novoId})", [
                            'created_by' => $currentAdminId,
                            'target_id' => $novoId,
                            'email' => $email,
                            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido'
                        ]);

                        $msgSuccess = "Administrador <strong>" . htmlspecialchars($nome) . "</strong> cadastrado com sucesso!";
                    } catch (Exception $e) {
                        $msgError = 'Erro ao cadastrar administrador: ' . $e->getMessage();
                    }
                }
            }
        }

        // 2. Editar Administrador Existente
        if ($action === 'editar') {
            $id = (int)($_POST['admin_id'] ?? 0);
            $nome = sanitize($_POST['nome'] ?? '');
            $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
            $novaSenha = $_POST['nova_senha'] ?? '';
            $confirmarSenha = $_POST['confirmar_senha'] ?? '';

            if ($id <= 0 || empty($nome) || !$email) {
                $msgError = 'Dados inválidos para atualização.';
            } else {
                $stmtCheck = $db->prepare("SELECT id FROM administradores WHERE email = ? AND id != ?");
                $stmtCheck->execute([$email, $id]);
                if ($stmtCheck->fetch()) {
                    $msgError = 'Este e-mail já pertence a outro administrador cadastrado.';
                } else {
                    $alterarSenha = !empty($novaSenha);
                    if ($alterarSenha) {
                        if (strlen($novaSenha) < 8) {
                            $msgError = 'A nova senha deve possuir no mínimo 8 caracteres.';
                        } elseif ($novaSenha !== $confirmarSenha) {
                            $msgError = 'A confirmação da nova senha não confere.';
                        }
                    }

                    if (empty($msgError)) {
                        try {
                            if ($alterarSenha) {
                                $hashNovaSenha = password_hash($novaSenha, PASSWORD_DEFAULT);
                                $stmtUp = $db->prepare("UPDATE administradores SET nome = ?, email = ?, senha = ? WHERE id = ?");
                                $stmtUp->execute([$nome, $email, $hashNovaSenha, $id]);
                            } else {
                                $stmtUp = $db->prepare("UPDATE administradores SET nome = ?, email = ? WHERE id = ?");
                                $stmtUp->execute([$nome, $email, $id]);
                            }

                            // Se alterou a própria conta, atualiza a sessão
                            if ($id === $currentAdminId) {
                                $_SESSION['admin_nome'] = $nome;
                                $_SESSION['admin_email'] = $email;
                            }

                            Database::log('admin_updated', "Administrador atualizado: {$email} (ID: {$id})", [
                                'updated_by' => $currentAdminId,
                                'target_id' => $id,
                                'senha_alterada' => $alterarSenha ? 'sim' : 'nao',
                                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido'
                            ]);

                            $msgSuccess = "Administrador <strong>" . htmlspecialchars($nome) . "</strong> atualizado com sucesso!";
                        } catch (Exception $e) {
                            $msgError = 'Erro ao atualizar administrador: ' . $e->getMessage();
                        }
                    }
                }
            }
        }

        // 3. Resetar Autenticação 2FA (Suporte Emergencial)
        if ($action === 'reset_2fa') {
            $id = (int)($_POST['admin_id'] ?? 0);
            if ($id <= 0) {
                $msgError = 'Identificador de administrador inválido.';
            } else {
                try {
                    $stmtReset = $db->prepare("UPDATE administradores SET totp_enabled = 0, totp_secret = NULL, backup_codes = NULL WHERE id = ?");
                    $stmtReset->execute([$id]);

                    Database::log('admin_2fa_reset', "2FA resetado para administrador ID: {$id}", [
                        'reset_by' => $currentAdminId,
                        'target_id' => $id,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido'
                    ]);

                    $msgSuccess = "Autenticação em 2 etapas (2FA) desativada com sucesso para este administrador!";
                } catch (Exception $e) {
                    $msgError = 'Erro ao resetar 2FA: ' . $e->getMessage();
                }
            }
        }

        // 4. Excluir Administrador
        if ($action === 'excluir') {
            $id = (int)($_POST['admin_id'] ?? 0);
            
            // Regra de segurança 1: não pode excluir a própria conta logada
            if ($id === $currentAdminId) {
                $msgError = 'Você não pode excluir sua própria conta enquanto estiver logado nela.';
            } else {
                // Regra de segurança 2: não pode excluir se for o único admin restante
                $totalAdmins = (int)$db->query("SELECT COUNT(*) FROM administradores")->fetchColumn();
                if ($totalAdmins <= 1) {
                    $msgError = 'Operação negada: o sistema precisa manter pelo menos 1 administrador ativo.';
                } else {
                    try {
                        $stmtUser = $db->prepare("SELECT nome, email FROM administradores WHERE id = ?");
                        $stmtUser->execute([$id]);
                        $alvo = $stmtUser->fetch();

                        $stmtDel = $db->prepare("DELETE FROM administradores WHERE id = ?");
                        $stmtDel->execute([$id]);

                        Database::log('admin_deleted', "Administrador excluído: " . ($alvo['email'] ?? $id), [
                            'deleted_by' => $currentAdminId,
                            'target_id' => $id,
                            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido'
                        ]);

                        $msgSuccess = "Administrador removido com sucesso do sistema!";
                    } catch (Exception $e) {
                        $msgError = 'Erro ao excluir administrador: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Buscar lista de administradores
$stmt = $db->query("SELECT id, nome, email, totp_enabled, last_login, last_ip, criado_em FROM administradores ORDER BY id ASC");
$admins = $stmt->fetchAll();

$totalAdmins = count($admins);
$total2Fa = count(array_filter($admins, fn($a) => (int)$a['totp_enabled'] === 1));
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <div>
        <h3 class="fw-bold text-white mb-1">
            <i class="fa-solid fa-users-gear text-primary me-2"></i> Gerenciamento de Administradores
        </h3>
        <p class="text-secondary mb-0">Controle de acesso, credenciais e políticas de segundo fator (2FA) para a equipe</p>
    </div>
    <div>
        <button class="btn btn-primary d-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalNovoAdmin">
            <i class="fa-solid fa-user-plus"></i> Novo Administrador
        </button>
    </div>
</div>

<?php if (!empty($msgSuccess)): ?>
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i> <?= $msgSuccess ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!empty($msgError)): ?>
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i> <?= htmlspecialchars($msgError) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Métricas Resumidas -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card bg-dark border-secondary border-opacity-25 shadow-sm p-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-secondary small text-uppercase fw-semibold">Total de Administradores</span>
                    <h3 class="text-white fw-bold mt-1 mb-0"><?= $totalAdmins ?></h3>
                </div>
                <div class="rounded-circle bg-primary bg-opacity-10 p-3 text-primary">
                    <i class="fa-solid fa-users fs-4"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-dark border-secondary border-opacity-25 shadow-sm p-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-secondary small text-uppercase fw-semibold">Protegidos com 2FA</span>
                    <h3 class="text-success fw-bold mt-1 mb-0"><?= $total2Fa ?> / <?= $totalAdmins ?></h3>
                </div>
                <div class="rounded-circle bg-success bg-opacity-10 p-3 text-success">
                    <i class="fa-solid fa-shield-halved fs-4"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-dark border-secondary border-opacity-25 shadow-sm p-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-secondary small text-uppercase fw-semibold">Nível de Segurança</span>
                    <h5 class="text-info fw-bold mt-1 mb-0">Role-Based Access (RBAC)</h5>
                </div>
                <div class="rounded-circle bg-info bg-opacity-10 p-3 text-info">
                    <i class="fa-solid fa-lock fs-4"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tabela de Administradores -->
<div class="card bg-dark border-secondary border-opacity-25 shadow-sm">
    <div class="card-header bg-dark border-secondary border-opacity-25 py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title text-white mb-0 fw-semibold">
            <i class="fa-solid fa-list me-2 text-primary"></i> Usuários com Acesso Administrativo
        </h5>
        <span class="badge bg-secondary"><?= $totalAdmins ?> cadastrados</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark table-hover mb-0 align-middle">
                <thead class="table-secondary text-uppercase small" style="letter-spacing: 0.5px;">
                    <tr>
                        <th class="ps-4">Usuário</th>
                        <th>E-mail</th>
                        <th>Status 2FA</th>
                        <th>Último Acesso</th>
                        <th>Cadastrado em</th>
                        <th class="text-end pe-4">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($admins)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-secondary">Nenhum administrador encontrado.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($admins as $adm): 
                            $isMe = ((int)$adm['id'] === $currentAdminId);
                            $iniciais = strtoupper(substr($adm['nome'], 0, 2));
                        ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold shadow-sm <?= $isMe ? 'bg-primary text-white' : 'bg-secondary text-light' ?>" style="width: 40px; height: 40px; font-size: 0.85rem;">
                                            <?= $iniciais ?>
                                        </div>
                                        <div>
                                            <div class="fw-semibold text-white">
                                                <?= htmlspecialchars($adm['nome']) ?>
                                                <?php if ($isMe): ?>
                                                    <span class="badge bg-primary ms-1" style="font-size: 0.65rem;">Você</span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-secondary">ID #<?= $adm['id'] ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="text-light"><?= htmlspecialchars($adm['email']) ?></span>
                                </td>
                                <td>
                                    <?php if ((int)$adm['totp_enabled'] === 1): ?>
                                        <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-25 px-2 py-1">
                                            <i class="fa-solid fa-check-circle me-1"></i> Ativo (TOTP)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-25 px-2 py-1">
                                            <i class="fa-solid fa-triangle-exclamation me-1"></i> Inativo
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($adm['last_login'])): ?>
                                        <div class="text-light small"><?= date('d/m/Y H:i', strtotime($adm['last_login'])) ?></div>
                                        <small class="text-secondary">IP: <?= htmlspecialchars($adm['last_ip'] ?? '-') ?></small>
                                    <?php else: ?>
                                        <span class="text-secondary small">Nunca acessou</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-secondary small">
                                        <?= !empty($adm['criado_em']) ? date('d/m/Y', strtotime($adm['criado_em'])) : '-' ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-outline-info" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalEditarAdmin"
                                                data-id="<?= $adm['id'] ?>"
                                                data-nome="<?= htmlspecialchars($adm['nome']) ?>"
                                                data-email="<?= htmlspecialchars($adm['email']) ?>"
                                                title="Editar Informações">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>

                                        <?php if ((int)$adm['totp_enabled'] === 1): ?>
                                            <button class="btn btn-sm btn-outline-warning"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalReset2fa"
                                                    data-id="<?= $adm['id'] ?>"
                                                    data-nome="<?= htmlspecialchars($adm['nome']) ?>"
                                                    title="Resetar 2FA">
                                                <i class="fa-solid fa-key"></i>
                                            </button>
                                        <?php endif; ?>

                                        <?php if (!$isMe && $totalAdmins > 1): ?>
                                            <button class="btn btn-sm btn-outline-danger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalExcluirAdmin"
                                                    data-id="<?= $adm['id'] ?>"
                                                    data-nome="<?= htmlspecialchars($adm['nome']) ?>"
                                                    title="Excluir Administrador">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-secondary opacity-50" disabled title="Você não pode excluir sua própria conta ou o único administrador">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL: Novo Administrador -->
<div class="modal fade" id="modalNovoAdmin" tabindex="-1" aria-labelledby="modalNovoAdminLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <form method="POST" action="administradores.php">
                <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
                <input type="hidden" name="action" value="cadastrar">

                <div class="modal-header border-secondary">
                    <h5 class="modal-title fw-bold" id="modalNovoAdminLabel">
                        <i class="fa-solid fa-user-plus text-primary me-2"></i> Cadastrar Novo Administrador
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label text-secondary small text-uppercase fw-semibold">Nome Completo</label>
                        <input type="text" name="nome" class="form-control bg-black text-white border-secondary" placeholder="Ex: Roberto Silva" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary small text-uppercase fw-semibold">E-mail Institucional</label>
                        <input type="email" name="email" class="form-control bg-black text-white border-secondary" placeholder="Ex: roberto@provedor.com" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-secondary small text-uppercase fw-semibold">Senha Inicial</label>
                            <input type="password" name="senha" class="form-control bg-black text-white border-secondary" placeholder="Mínimo 8 dígitos" required minlength="8">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small text-uppercase fw-semibold">Confirmar Senha</label>
                            <input type="password" name="confirmar_senha" class="form-control bg-black text-white border-secondary" placeholder="Repita a senha" required minlength="8">
                        </div>
                    </div>
                    <div class="alert alert-info bg-info bg-opacity-10 border-info border-opacity-25 text-info small mb-0">
                        <i class="fa-solid fa-circle-info me-1"></i> A senha será armazenada usando hash Bcrypt com salt aleatório. O novo administrador poderá ativar seu próprio 2FA no primeiro login.
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Cadastrar Administrador</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: Editar Administrador -->
<div class="modal fade" id="modalEditarAdmin" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <form method="POST" action="administradores.php">
                <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="admin_id" id="edit_admin_id" value="">

                <div class="modal-header border-secondary">
                    <h5 class="modal-title fw-bold">
                        <i class="fa-solid fa-pen-to-square text-info me-2"></i> Editar Administrador
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label text-secondary small text-uppercase fw-semibold">Nome Completo</label>
                        <input type="text" name="nome" id="edit_nome" class="form-control bg-black text-white border-secondary" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-secondary small text-uppercase fw-semibold">E-mail</label>
                        <input type="email" name="email" id="edit_email" class="form-control bg-black text-white border-secondary" required>
                    </div>
                    <hr class="border-secondary opacity-25">
                    <p class="text-secondary small mb-2"><i class="fa-solid fa-key me-1"></i> Alteração de Senha (opcional - deixe em branco para manter a atual):</p>
                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <label class="form-label text-secondary small text-uppercase fw-semibold">Nova Senha</label>
                            <input type="password" name="nova_senha" class="form-control bg-black text-white border-secondary" placeholder="Deixe em branco p/ não alterar" minlength="8">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small text-uppercase fw-semibold">Confirmar Nova Senha</label>
                            <input type="password" name="confirmar_senha" class="form-control bg-black text-white border-secondary" placeholder="Confirmar nova senha" minlength="8">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-info">Salvar Alterações</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: Resetar 2FA -->
<div class="modal fade" id="modalReset2fa" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <form method="POST" action="administradores.php">
                <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
                <input type="hidden" name="action" value="reset_2fa">
                <input type="hidden" name="admin_id" id="reset_admin_id" value="">

                <div class="modal-header border-secondary">
                    <h5 class="modal-title fw-bold text-warning">
                        <i class="fa-solid fa-key me-2"></i> Desativar 2FA Emergencial
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Tem certeza que deseja desativar a autenticação de 2 fatores para o administrador <strong id="reset_admin_nome" class="text-info"></strong>?</p>
                    <p class="small text-secondary mb-0">Use esta opção caso o operador tenha perdido acesso ao aplicativo autenticador (Google Authenticator). Após a redefinição, ele poderá fazer login apenas com e-mail e senha e configurar um novo código.</p>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning">Confirmar Reset de 2FA</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: Excluir Administrador -->
<div class="modal fade" id="modalExcluirAdmin" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <form method="POST" action="administradores.php">
                <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
                <input type="hidden" name="action" value="excluir">
                <input type="hidden" name="admin_id" id="del_admin_id" value="">

                <div class="modal-header border-secondary">
                    <h5 class="modal-title fw-bold text-danger">
                        <i class="fa-solid fa-triangle-exclamation me-2"></i> Confirmar Exclusão
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">Deseja realmente remover o administrador <strong id="del_admin_nome" class="text-danger"></strong>? Esta ação é definitiva e revogará imediatamente o acesso deste usuário ao sistema.</p>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Excluir Definitivamente</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Modal Editar
    const modalEditar = document.getElementById('modalEditarAdmin');
    if (modalEditar) {
        modalEditar.addEventListener('show.bs.modal', function(event) {
            const btn = event.relatedTarget;
            document.getElementById('edit_admin_id').value = btn.getAttribute('data-id');
            document.getElementById('edit_nome').value = btn.getAttribute('data-nome');
            document.getElementById('edit_email').value = btn.getAttribute('data-email');
        });
    }

    // Modal Reset 2FA
    const modalReset = document.getElementById('modalReset2fa');
    if (modalReset) {
        modalReset.addEventListener('show.bs.modal', function(event) {
            const btn = event.relatedTarget;
            document.getElementById('reset_admin_id').value = btn.getAttribute('data-id');
            document.getElementById('reset_admin_nome').textContent = btn.getAttribute('data-nome');
        });
    }

    // Modal Excluir
    const modalExcluir = document.getElementById('modalExcluirAdmin');
    if (modalExcluir) {
        modalExcluir.addEventListener('show.bs.modal', function(event) {
            const btn = event.relatedTarget;
            document.getElementById('del_admin_id').value = btn.getAttribute('data-id');
            document.getElementById('del_admin_nome').textContent = btn.getAttribute('data-nome');
        });
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
