<?php
/**
 * @file admin/2fa-verify.php
 * @brief Página de verificação do 2FA no login do administrador
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/SessionGuard.php';

SessionGuard::init();

if (empty($_SESSION['pending_admin_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$admin_id = $_SESSION['pending_admin_id'];
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken($csrfToken)) {
        $error = 'Token de segurança inválido. Tente novamente.';
    } elseif (!Security::checkRateLimit($ip)) {
        $error = 'Limite de tentativas excedido. Tente novamente mais tarde.';
    } else {
        $code = $_POST['totp_code'] ?? '';
        $use_backup = isset($_POST['use_backup']);
        
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM administradores WHERE id = ?');
        $stmt->execute([$admin_id]);
        $admin = $stmt->fetch();
        
        $authenticated = false;
        
        if ($admin) {
            if ($use_backup) {
                $backupCodes = json_decode($admin['backup_codes'] ?? '[]', true);
                if (is_array($backupCodes) && in_array($code, $backupCodes)) {
                    $authenticated = true;
                    // Remover código usado
                    $backupCodes = array_diff($backupCodes, [$code]);
                    $db->prepare('UPDATE administradores SET backup_codes = ? WHERE id = ?')
                       ->execute([json_encode(array_values($backupCodes)), $admin_id]);
                }
            } else {
                $decryptedSecret = Security::decryptSecret($admin['totp_secret']);
                if (Security::verifyTotpCode($decryptedSecret, $code)) {
                    $authenticated = true;
                }
            }
        }
        
        if ($authenticated) {
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_nome'] = $admin['nome'];
            $_SESSION['admin_email'] = $admin['email'];
            
            if (isset($_POST['trust_device'])) {
                $deviceHash = Security::getDeviceFingerprint();
                $deviceName = Security::getDeviceName();
                $stmtTrust = $db->prepare('INSERT INTO trusted_devices (user_id, user_type, device_hash, device_name, ip_address, trusted_until) VALUES (?, "admin", ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY)) ON DUPLICATE KEY UPDATE trusted_until = DATE_ADD(NOW(), INTERVAL 30 DAY)');
                $stmtTrust->execute([$admin['id'], $deviceHash, $deviceName, $ip]);
            }
            
            unset($_SESSION['pending_admin_id'], $_SESSION['pending_admin_nome'], $_SESSION['pending_admin_email']);
            SessionGuard::registerActiveSession($admin['id'], 'admin');
            
            $db->prepare('UPDATE administradores SET last_login = NOW(), last_ip = ? WHERE id = ?')
               ->execute([$ip, $admin['id']]);
               
            Database::log('auth', "Login 2FA de admin: {$admin['nome']}", ['ip' => $ip]);
            
            header('Location: index.php');
            exit;
        } else {
            Security::recordAttempt($ip);
            $error = 'Código incorreto. Tente novamente.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificação de Segurança - MikroTik Pay</title>
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
                            <i class="fas fa-lock fa-3x text-primary mb-3"></i>
                            <h4 class="mb-0">Verificação em Duas Etapas</h4>
                            <p class="text-muted mt-2">Insira o código do seu aplicativo autenticador.</p>
                        </div>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger" role="alert">
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="2fa-verify.php" id="verify-form">
                            <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
                            <input type="hidden" name="use_backup" id="use_backup_input" value="0" disabled>
                            
                            <div class="mb-4">
                                <label id="code-label" for="totp_code" class="form-label text-center d-block">Código de 6 dígitos</label>
                                <input type="text" class="form-control bg-dark text-light border-0 text-center fs-3 tracking-widest" id="totp_code" name="totp_code" maxlength="8" required autofocus autocomplete="off">
                            </div>
                            
                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" id="trust_device" name="trust_device" value="1">
                                <label class="form-check-label text-muted" for="trust_device">
                                    Confiar neste dispositivo por 30 dias
                                </label>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold mb-3">Verificar</button>
                            
                            <div class="text-center">
                                <a href="#" id="toggle-backup" class="text-decoration-none text-muted small">Usar código de recuperação</a>
                            </div>
                        </form>
                        
                        <div class="text-center mt-4 border-top border-dark pt-3">
                            <a href="login.php" class="text-decoration-none text-muted"><i class="fas fa-arrow-left"></i> Voltar ao login</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.getElementById('toggle-backup').addEventListener('click', function(e) {
            e.preventDefault();
            const input = document.getElementById('totp_code');
            const label = document.getElementById('code-label');
            const useBackup = document.getElementById('use_backup_input');
            const trust = document.getElementById('trust_device');
            
            if (useBackup.disabled) {
                // Switching to backup mode
                useBackup.disabled = false;
                label.innerText = 'Código de Recuperação (8 caracteres)';
                input.maxLength = 8;
                input.removeAttribute('pattern');
                this.innerText = 'Usar aplicativo autenticador';
                trust.disabled = true;
            } else {
                // Switching to TOTP mode
                useBackup.disabled = true;
                label.innerText = 'Código de 6 dígitos';
                input.maxLength = 6;
                input.setAttribute('pattern', '[0-9]{6}');
                this.innerText = 'Usar código de recuperação';
                trust.disabled = false;
            }
            input.value = '';
            input.focus();
        });
    </script>
</body>
</html>
