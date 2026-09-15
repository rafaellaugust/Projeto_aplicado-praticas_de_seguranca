<?php
/**
 * @file admin/2fa-verify.php
 * @brief Página de verificação 2FA (Google Authenticator, E-mail e Códigos de Recuperação)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/SessionGuard.php';

SessionGuard::init();
Security::sendSecurityHeaders();

if (empty($_SESSION['pending_admin_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';
$admin_id = (int)$_SESSION['pending_admin_id'];
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

$db = Database::getInstance();
$stmt = $db->prepare('SELECT * FROM administradores WHERE id = ?');
$stmt->execute([$admin_id]);
$admin = $stmt->fetch();

if (!$admin) {
    header('Location: login.php');
    exit;
}

// Mascarar e-mail para exibição segura (ex: ad***@admin.com)
$emailPartes = explode('@', $admin['email']);
$emailUser = $emailPartes[0];
$emailDomain = $emailPartes[1] ?? '';
$emailMascarado = substr($emailUser, 0, 2) . str_repeat('*', max(3, strlen($emailUser) - 2)) . '@' . $emailDomain;

// Envio de código por e-mail via POST (solicitado pelo usuário)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_email_code') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken($csrfToken)) {
        $error = 'Token de segurança inválido.';
    } else {
        $otpCode = Security::generateEmailOtp($admin['id'], 'admin');
        $enviado = Security::sendEmailOtp($admin['email'], $otpCode, $admin['nome']);
        if ($enviado) {
            $success = "Código de verificação enviado para {$emailMascarado}! Válido por 10 minutos.";
        } else {
            // Em caso de falha no mail server local, registra no log e notifica o usuário
            $error = "Não foi possível enviar o e-mail automaticamente. Verifique as configurações de SMTP ou use o Google Authenticator.";
            Database::log('auth_email_erro', "Falha no envio de e-mail 2FA para: {$admin['email']}");
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken($csrfToken)) {
        $error = 'Token de segurança inválido. Tente novamente.';
    } elseif (!Security::checkRateLimit($ip)) {
        $error = 'Limite de tentativas excedido. Tente novamente mais tarde.';
    } else {
        $code = trim($_POST['totp_code'] ?? '');
        $authMode = $_POST['auth_mode'] ?? 'totp'; // 'totp', 'email', 'backup'
        $authenticated = false;

        if ($authMode === 'email') {
            // Validação por E-mail
            $emailCode = preg_replace('/[^0-9]/', '', $code);
            if (Security::verifyEmailOtp($emailCode)) {
                $authenticated = true;
            } else {
                $error = 'Código de e-mail inválido ou expirado (válido por 10 minutos).';
            }
        } elseif ($authMode === 'backup') {
            // Validação por Código de Recuperação
            $backupCode = trim($code);
            $backupCodes = json_decode($admin['backup_codes'] ?? '[]', true);
            if (is_array($backupCodes) && in_array($backupCode, $backupCodes)) {
                $authenticated = true;
                // Remove o código de backup utilizado
                $backupCodes = array_diff($backupCodes, [$backupCode]);
                $db->prepare('UPDATE administradores SET backup_codes = ? WHERE id = ?')
                   ->execute([json_encode(array_values($backupCodes)), $admin_id]);
                Database::log('auth', "Código de backup 2FA utilizado por admin ID: {$admin_id}");
            } else {
                $error = 'Código de recuperação inválido.';
            }
        } else {
            // Validação padrão via Google Authenticator (TOTP RFC 6238)
            $totpCode = preg_replace('/[^0-9]/', '', $code);
            $decryptedSecret = Security::decryptSecret($admin['totp_secret'] ?? '');
            if (!empty($decryptedSecret) && Security::verifyTotpCode($decryptedSecret, $totpCode, 2)) {
                $authenticated = true;
            } else {
                $error = 'Código do autenticador incorreto ou expirado. Verifique o horário do celular e tente novamente.';
            }
        }

        if ($authenticated) {
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_nome'] = $admin['nome'];
            $_SESSION['admin_email'] = $admin['email'];

            if (isset($_POST['trust_device'])) {
                $deviceHash = Security::getDeviceFingerprint();
                $deviceName = Security::getDeviceName();
                $stmtTrust = $db->prepare('
                    INSERT INTO trusted_devices (user_id, user_type, device_hash, device_name, ip_address, trusted_until) 
                    VALUES (?, "admin", ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY)) 
                    ON DUPLICATE KEY UPDATE trusted_until = DATE_ADD(NOW(), INTERVAL 30 DAY)
                ');
                $stmtTrust->execute([$admin['id'], $deviceHash, $deviceName, $ip]);
            }

            unset($_SESSION['pending_admin_id'], $_SESSION['pending_admin_nome'], $_SESSION['pending_admin_email']);
            SessionGuard::registerActiveSession($admin['id'], 'admin');

            $db->prepare('UPDATE administradores SET last_login = NOW(), last_ip = ? WHERE id = ?')
               ->execute([$ip, $admin['id']]);

            Database::log('auth', "Login 2FA bem-sucedido ({$authMode}): {$admin['nome']}", ['ip' => $ip]);

            header('Location: index.php');
            exit;
        } else {
            Security::recordAttempt($ip, '2fa', false, $admin['email'], 'admin');
            Database::log('auth_falha', "Tentativa 2FA falhou para {$admin['nome']} (modo: {$authMode})", ['ip' => $ip]);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificação em Duas Etapas - MikroTik Pay</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="bg-dark text-light d-flex align-items-center min-vh-100">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card bg-secondary border-0 shadow-lg rounded-4">
                    <div class="card-body p-4 p-md-5">
                        <div class="text-center mb-4">
                            <i class="fas fa-shield-halved fa-3x text-info mb-3"></i>
                            <h4 class="mb-1 text-white fw-bold">Verificação em Duas Etapas</h4>
                            <p class="text-muted small" id="mode-description">Insira o código gerado no aplicativo <strong>Google Authenticator</strong>.</p>
                        </div>

                        <?php if ($error): ?>
                            <div class="alert alert-danger py-2 text-center" role="alert">
                                <i class="fas fa-triangle-exclamation me-1"></i> <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                            <div class="alert alert-success py-2 text-center" role="alert">
                                <i class="fas fa-circle-check me-1"></i> <?= htmlspecialchars($success) ?>
                            </div>
                        <?php endif; ?>

                        <!-- Seletor de Modo de Verificação -->
                        <div class="d-flex justify-content-center gap-2 mb-4">
                            <button type="button" class="btn btn-sm btn-outline-info active" id="btn-mode-totp" onclick="switchMode('totp')">
                                <i class="fas fa-mobile-screen me-1"></i> Google Auth
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-info" id="btn-mode-email" onclick="switchMode('email')">
                                <i class="fas fa-envelope me-1"></i> E-mail
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-info" id="btn-mode-backup" onclick="switchMode('backup')">
                                <i class="fas fa-key me-1"></i> Backup
                            </button>
                        </div>

                        <!-- Botão de Solicitação de Código por E-mail (quando no modo email) -->
                        <div id="email-request-section" class="mb-3 text-center d-none">
                            <form method="POST" action="2fa-verify.php" class="d-inline">
                                <?= Security::generateCsrfToken() ?>
                                <input type="hidden" name="action" value="send_email_code">
                                <button type="submit" class="btn btn-warning btn-sm">
                                    <i class="fas fa-paper-plane me-1"></i> Enviar Código para <?= htmlspecialchars($emailMascarado) ?>
                                </button>
                            </form>
                        </div>

                        <!-- Formulário Principal de Validação -->
                        <form method="POST" action="2fa-verify.php" id="verify-form">
                            <?= Security::generateCsrfToken() ?>
                            <input type="hidden" name="auth_mode" id="auth_mode_input" value="totp">

                            <div class="mb-4">
                                <label id="code-label" for="totp_code" class="form-label text-center d-block fw-semibold text-light">Código de 6 dígitos</label>
                                <input type="text" class="form-control bg-dark text-light border-0 text-center fs-2 tracking-widest" id="totp_code" name="totp_code" maxlength="6" pattern="[0-9]{6}" required autofocus autocomplete="off" placeholder="••••••">
                            </div>

                            <div class="form-check mb-4" id="trust-device-wrapper">
                                <input class="form-check-input" type="checkbox" id="trust_device" name="trust_device" value="1">
                                <label class="form-check-label text-muted small" for="trust_device">
                                    Confiar neste dispositivo por 30 dias
                                </label>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold mb-3">
                                <i class="fas fa-lock-open me-1"></i> Confirmar e Entrar
                            </button>
                        </form>

                        <div class="text-center mt-3 border-top border-dark pt-3">
                            <a href="login.php" class="text-decoration-none text-muted small">
                                <i class="fas fa-arrow-left me-1"></i> Voltar para o login
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function switchMode(mode) {
            const input = document.getElementById('totp_code');
            const label = document.getElementById('code-label');
            const authModeInput = document.getElementById('auth_mode_input');
            const desc = document.getElementById('mode-description');
            const trustWrapper = document.getElementById('trust-device-wrapper');
            const emailSection = document.getElementById('email-request-section');

            document.getElementById('btn-mode-totp').classList.remove('active');
            document.getElementById('btn-mode-email').classList.remove('active');
            document.getElementById('btn-mode-backup').classList.remove('active');

            authModeInput.value = mode;

            if (mode === 'email') {
                document.getElementById('btn-mode-email').classList.add('active');
                desc.innerHTML = 'Insira o código de 6 dígitos enviado para <strong><?= htmlspecialchars($emailMascarado) ?></strong>.';
                label.innerText = 'Código recebido por E-mail';
                input.maxLength = 6;
                input.placeholder = '••••••';
                trustWrapper.classList.remove('d-none');
                emailSection.classList.remove('d-none');
            } else if (mode === 'backup') {
                document.getElementById('btn-mode-backup').classList.add('active');
                desc.innerHTML = 'Insira um dos seus <strong>códigos de recuperação</strong> de uso único (8 caracteres).';
                label.innerText = 'Código de Recuperação (8 caracteres)';
                input.maxLength = 8;
                input.removeAttribute('pattern');
                input.placeholder = '••••••••';
                trustWrapper.classList.add('d-none');
                emailSection.classList.add('d-none');
            } else {
                document.getElementById('btn-mode-totp').classList.add('active');
                desc.innerHTML = 'Insira o código de 6 dígitos gerado no <strong>Google Authenticator</strong>.';
                label.innerText = 'Código do Aplicativo Autenticador';
                input.maxLength = 6;
                input.setAttribute('pattern', '[0-9]{6}');
                input.placeholder = '••••••';
                trustWrapper.classList.remove('d-none');
                emailSection.classList.add('d-none');
            }

            input.value = '';
            input.focus();
        }
    </script>
</body>
</html>
