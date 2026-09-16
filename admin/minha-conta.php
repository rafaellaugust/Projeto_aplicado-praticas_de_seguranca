<?php
/**
 * @file admin/minha-conta.php
 * @brief Gerenciamento de perfil e credenciais do administrador
 */

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../src/Security.php';

$db = Database::getInstance();
$adminId = $_SESSION['admin_id'] ?? 0;

$msgSuccess = '';
$msgError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrfToken()) {
        $msgError = 'Sessão expirada ou token de segurança inválido.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_perfil') {
            $nome = sanitize($_POST['nome'] ?? '');
            $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
            $senhaAtual = $_POST['senha_atual'] ?? '';
            $novaSenha = $_POST['nova_senha'] ?? '';
            $confirmarSenha = $_POST['confirmar_senha'] ?? '';

            if (empty($nome) || !$email) {
                $msgError = 'Preencha um nome válido e um e-mail válido.';
            } else {
                // Verificar se o e-mail já pertence a outro admin
                $stmtCheck = $db->prepare("SELECT id FROM administradores WHERE email = ? AND id != ?");
                $stmtCheck->execute([$email, $adminId]);
                if ($stmtCheck->fetch()) {
                    $msgError = 'Este e-mail já está sendo utilizado por outro administrador.';
                } else {
                    $stmtAdmin = $db->prepare("SELECT senha FROM administradores WHERE id = ?");
                    $stmtAdmin->execute([$adminId]);
                    $currentData = $stmtAdmin->fetch();

                    $alterarSenha = !empty($novaSenha);

                    if ($alterarSenha) {
                        if (empty($senhaAtual)) {
                            $msgError = 'Para alterar a senha, informe sua senha atual.';
                        } elseif (!password_verify($senhaAtual, $currentData['senha'] ?? '')) {
                            $msgError = 'A senha atual informada está incorreta.';
                        } elseif (strlen($novaSenha) < 8) {
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
                                $stmtUp->execute([$nome, $email, $hashNovaSenha, $adminId]);
                            } else {
                                $stmtUp = $db->prepare("UPDATE administradores SET nome = ?, email = ? WHERE id = ?");
                                $stmtUp->execute([$nome, $email, $adminId]);
                            }

                            $_SESSION['admin_nome'] = $nome;
                            $_SESSION['admin_email'] = $email;

                            Database::log('admin_conta', "Perfil atualizado com sucesso", [
                                'admin_id' => $adminId,
                                'email' => $email,
                                'senha_alterada' => $alterarSenha ? 'sim' : 'nao',
                                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido'
                            ]);

                            $msgSuccess = 'Dados da conta atualizados com sucesso!';
                        } catch (Exception $e) {
                            $msgError = 'Erro ao salvar alterações: ' . $e->getMessage();
                        }
                    }
                }
            }
        } elseif ($action === 'reset_2fa') {
            try {
                $stmtReset = $db->prepare("UPDATE administradores SET totp_enabled = 0, totp_secret = NULL, backup_codes = NULL WHERE id = ?");
                $stmtReset->execute([$adminId]);

                Database::log('admin_conta', "2FA redefinido pelo próprio administrador", [
                    'admin_id' => $adminId,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido'
                ]);

                unset($_SESSION['totp_setup_secret']);
                $_SESSION['pending_admin_id'] = $adminId;
                $_SESSION['pending_admin_nome'] = $_SESSION['admin_nome'] ?? 'Admin';
                $_SESSION['pending_admin_email'] = $_SESSION['admin_email'] ?? '';

                header('Location: 2fa-setup.php?reset=1');
                exit;
            } catch (Exception $e) {
                $msgError = 'Erro ao redefinir 2FA: ' . $e->getMessage();
            }
        }
    }
}

// Buscar dados atuais do admin
$stmtCurr = $db->prepare("SELECT id, nome, email, totp_enabled, last_login, last_ip FROM administradores WHERE id = ?");
$stmtCurr->execute([$adminId]);
$admin = $stmtCurr->fetch() ?: [
    'nome' => $_SESSION['admin_nome'] ?? '',
    'email' => $_SESSION['admin_email'] ?? '',
    'totp_enabled' => 0,
    'last_login' => null,
    'last_ip' => null
];

// Buscar histórico recente de acessos
$loginHistory = [];
try {
    $stmtHist = $db->prepare("
        SELECT ip_address, geo_city, geo_country, user_agent, success, created_at 
        FROM login_attempts 
        WHERE email_or_user = ? OR user_type = 'admin'
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmtHist->execute([$admin['email']]);
    $loginHistory = $stmtHist->fetchAll();
} catch (Exception $e) {}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="text-white fw-bold mb-1">
            <i class="fa-solid fa-user-gear text-info me-2"></i>Minha Conta
        </h4>
        <p class="text-secondary small mb-0">Gerencie seu perfil de administrador, segurança de senha e autenticação em dois fatores (2FA).</p>
    </div>
</div>

<?php if ($msgSuccess): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($msgSuccess) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($msgError): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($msgError) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- 1. FORMULÁRIO DADOS DA CONTA -->
    <div class="col-lg-7">
        <div class="card-custom h-100">
            <h5 class="text-white fw-bold mb-3">
                <i class="fa-solid fa-id-card text-info me-2"></i>Dados do Administrador
            </h5>
            
            <form method="POST" action="">
                <?= Security::generateCsrfToken() ?>
                <input type="hidden" name="action" value="update_perfil">

                <div class="mb-3">
                    <label class="form-label small text-secondary">Nome Completo</label>
                    <input type="text" name="nome" class="form-control-custom" value="<?= htmlspecialchars($admin['nome']) ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small text-secondary">E-mail de Acesso</label>
                    <input type="email" name="email" class="form-control-custom" value="<?= htmlspecialchars($admin['email']) ?>" required>
                    <small class="text-secondary d-block mt-1">Este e-mail é utilizado para login e recuperação de acesso.</small>
                </div>

                <hr class="my-4" style="border-color: var(--border-color);">

                <h6 class="text-white fw-bold mb-3">
                    <i class="fa-solid fa-lock text-warning me-2"></i>Alterar Senha (Opcional)
                </h6>
                <p class="text-secondary small">Deixe os campos de senha em branco se deseja manter a senha atual inalterada.</p>

                <div class="mb-3">
                    <label class="form-label small text-secondary">Senha Atual</label>
                    <input type="password" name="senha_atual" class="form-control-custom" placeholder="••••••••••••">
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small text-secondary">Nova Senha</label>
                        <input type="password" name="nova_senha" class="form-control-custom" placeholder="Mínimo 8 caracteres">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-secondary">Confirmar Nova Senha</label>
                        <input type="password" name="confirmar_senha" class="form-control-custom" placeholder="Repita a nova senha">
                    </div>
                </div>

                <div class="mt-4 text-end">
                    <button type="submit" class="btn btn-primary-custom">
                        <i class="fa-solid fa-floppy-disk me-2"></i>Salvar Alterações
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 2. STATUS 2FA E DISPOSITIVO -->
    <div class="col-lg-5">
        <div class="card-custom mb-4">
            <h5 class="text-white fw-bold mb-3">
                <i class="fa-solid fa-shield-halved text-success me-2"></i>Autenticação em Dois Fatores (2FA)
            </h5>

            <div class="d-flex align-items-center justify-content-between p-3 rounded bg-dark border border-secondary mb-3">
                <div>
                    <div class="text-secondary small">Status do 2FA:</div>
                    <?php if (!empty($admin['totp_enabled'])): ?>
                        <span class="badge bg-success fs-6 mt-1"><i class="fa-solid fa-circle-check me-1"></i> ATIVO</span>
                    <?php else: ?>
                        <span class="badge bg-danger fs-6 mt-1"><i class="fa-solid fa-triangle-exclamation me-1"></i> DESATIVADO</span>
                    <?php endif; ?>
                </div>
                <i class="fa-solid fa-mobile-screen-button text-info fa-2x"></i>
            </div>

            <p class="text-secondary small">
                O 2FA adiciona uma camada adicional de segurança via aplicativo autenticador (Google Authenticator, Authy, etc.).
            </p>

            <form method="POST" action="" onsubmit="return confirm('ATENÇÃO: Ao reconfigurar o 2FA, você precisará escanear um novo QR Code imediatamente. Deseja prosseguir?');">
                <?= Security::generateCsrfToken() ?>
                <input type="hidden" name="action" value="reset_2fa">
                <button type="submit" class="btn btn-outline-warning w-100">
                    <i class="fa-solid fa-arrows-rotate me-2"></i>Reconfigurar QR Code / 2FA
                </button>
            </form>
        </div>

        <div class="card-custom">
            <h5 class="text-white fw-bold mb-3">
                <i class="fa-solid fa-circle-info text-info me-2"></i>Informações da Sessão Atual
            </h5>
            <ul class="list-unstyled mb-0 small text-secondary">
                <li class="mb-2"><strong class="text-white">Seu IP Atual:</strong> <?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') ?></li>
                <li class="mb-2"><strong class="text-white">Navegador:</strong> <?= htmlspecialchars(substr($_SERVER['HTTP_USER_AGENT'] ?? 'Desconhecido', 0, 70)) ?>...</li>
                <li><strong class="text-white">ID da Conta:</strong> #<?= (int)$adminId ?></li>
            </ul>
        </div>
    </div>

    <!-- 3. HISTÓRICO DE ACESSOS RECENTES -->
    <div class="col-12">
        <div class="card-custom">
            <h5 class="text-white fw-bold mb-3">
                <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Histórico Recente de Tentativas de Acesso
            </h5>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Data / Hora</th>
                            <th>Endereço IP</th>
                            <th>Localização Geográfica</th>
                            <th>Navegador / Dispositivo</th>
                            <th>Resultado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($loginHistory)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-secondary py-3">Nenhum histórico de acesso recente registrado.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($loginHistory as $lh): ?>
                                <tr>
                                    <td class="small text-secondary"><?= date('d/m/Y H:i:s', strtotime($lh['created_at'])) ?></td>
                                    <td class="font-monospace small text-info"><?= htmlspecialchars($lh['ip_address']) ?></td>
                                    <td class="small">
                                        <?= htmlspecialchars(trim(($lh['geo_city'] ?? '') . ', ' . ($lh['geo_country'] ?? ''), ', ') ?: 'Não identificado') ?>
                                    </td>
                                    <td class="small text-secondary text-truncate" style="max-width: 250px;" title="<?= htmlspecialchars($lh['user_agent']) ?>">
                                        <?= htmlspecialchars($lh['user_agent']) ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($lh['success'])): ?>
                                            <span class="badge bg-success">Sucesso</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Falha</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
