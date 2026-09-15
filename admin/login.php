<?php
/**
 * @file admin/login.php
 * @brief Página de login do administrador com verificação 2FA
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/SessionGuard.php';

SessionGuard::init();
Security::sendSecurityHeaders();

$error = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (Security::isIpBlocked($ip)) {
    $error = 'Muitas tentativas falhas. Seu IP foi bloqueado temporariamente.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken($csrfToken)) {
        $error = 'Token de segurança inválido. Tente novamente.';
    } elseif (!Security::verifyRecaptcha($_POST['g-recaptcha-response'] ?? '')) {
        $error = 'Falha na verificação do reCAPTCHA. Confirme que você não é um robô.';
    } elseif (Security::checkRateLimit($ip)) {
        $email = sanitize($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';
        
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id, nome, email, senha, totp_enabled FROM administradores WHERE email = ?');
        $stmt->execute([$email]);
        $admin = $stmt->fetch();
        
        if ($admin && password_verify($senha, $admin['senha'])) {
            // Senha correta, verificar 2FA
            if ($admin['totp_enabled']) {
                $_SESSION['pending_admin_id'] = $admin['id'];
                $_SESSION['pending_admin_nome'] = $admin['nome'];
                $_SESSION['pending_admin_email'] = $admin['email'];
                
                $deviceHash = Security::getDeviceFingerprint();
                $stmtTrust = $db->prepare('SELECT id FROM trusted_devices WHERE user_id = ? AND user_type = "admin" AND device_hash = ? AND trusted_until > NOW()');
                $stmtTrust->execute([$admin['id'], $deviceHash]);
                if ($stmtTrust->fetch()) {
                    // Dispositivo confiável, pular 2FA
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_nome'] = $admin['nome'];
                    $_SESSION['admin_email'] = $admin['email'];
                    unset($_SESSION['pending_admin_id'], $_SESSION['pending_admin_nome'], $_SESSION['pending_admin_email']);
                    
                    SessionGuard::registerActiveSession($admin['id'], 'admin');
                    
                    $db->prepare('UPDATE administradores SET last_login = NOW(), last_ip = ? WHERE id = ?')
                       ->execute([$ip, $admin['id']]);
                       
                    Database::log('auth', "Login de admin (dispositivo confiável): {$admin['nome']}", ['ip' => $ip]);
                    header('Location: index.php');
                    exit;
                } else {
                    header('Location: 2fa-verify.php');
                    exit;
                }
            } else {
                $_SESSION['pending_admin_id'] = $admin['id'];
                $_SESSION['pending_admin_nome'] = $admin['nome'];
                $_SESSION['pending_admin_email'] = $admin['email'];
                header('Location: 2fa-setup.php');
                exit;
            }
        } else {
            Security::recordAttempt($ip, 'login', false, $email, 'admin');
            $error = 'Credenciais inválidas.';
            Database::log('auth', "Falha de login admin: {$email}", ['ip' => $ip]);
        }
    } else {
         $error = 'Limite de tentativas excedido. Tente novamente mais tarde.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Administrativo - MikroTik Pay</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="bg-dark text-light d-flex align-items-center min-vh-100">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <div class="card bg-secondary border-0 shadow-lg">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <i class="fas fa-server fa-3x text-primary mb-3"></i>
                            <h4 class="mb-0">Administração</h4>
                        </div>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger" role="alert">
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="login.php">
                            <?= Security::generateCsrfToken() ?>
                            
                            <div class="mb-3">
                                <label for="email" class="form-label">E-mail</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-dark border-0 text-light"><i class="fas fa-envelope"></i></span>
                                    <input type="email" class="form-control bg-dark text-light border-0" id="email" name="email" required>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="senha" class="form-label">Senha</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-dark border-0 text-light"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control bg-dark text-light border-0" id="senha" name="senha" required>
                                </div>
                            </div>

                            <?= Security::renderRecaptchaWidget() ?>
                            
                            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">Entrar</button>
                        </form>
                        
                        <div class="text-center mt-4">
                            <a href="../cliente/login.php" class="text-decoration-none text-muted">Acesso do Cliente</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
