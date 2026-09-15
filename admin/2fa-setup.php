<?php
/**
 * @file admin/2fa-setup.php
 * @brief Página de configuração do 2FA no primeiro acesso do administrador
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
$admin_nome = $_SESSION['pending_admin_nome'] ?? 'Admin';
$admin_email = $_SESSION['pending_admin_email'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $secret = Security::generateTotpSecret();
    $_SESSION['totp_setup_secret'] = $secret;
    $backupCodes = Security::generateBackupCodes();
    $_SESSION['totp_setup_backup'] = $backupCodes;
} else {
    $secret = $_SESSION['totp_setup_secret'] ?? '';
    $backupCodes = $_SESSION['totp_setup_backup'] ?? [];
}

$qrCodeUrl = Security::getTotpQrCodeUrl($admin_email, $secret, 'MikroTik Pay');
$totpAuthUrl = Security::getTotpAuthUrl($admin_email, $secret, 'MikroTik Pay');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrfToken($csrfToken)) {
        $error = 'Token de segurança inválido. Tente novamente.';
    } else {
        $code = $_POST['totp_code'] ?? '';
        if (Security::verifyTotpCode($secret, $code)) {
            $encryptedSecret = Security::encryptSecret($secret);
            $backupCodesJson = json_encode($backupCodes);
            
            $db = Database::getInstance();
            $stmt = $db->prepare('UPDATE administradores SET totp_secret = ?, totp_enabled = 1, backup_codes = ? WHERE id = ?');
            $stmt->execute([$encryptedSecret, $backupCodesJson, $admin_id]);
            
            $_SESSION['admin_id'] = $admin_id;
            $_SESSION['admin_nome'] = $admin_nome;
            $_SESSION['admin_email'] = $admin_email;
            unset($_SESSION['pending_admin_id'], $_SESSION['pending_admin_nome'], $_SESSION['pending_admin_email']);
            unset($_SESSION['totp_setup_secret'], $_SESSION['totp_setup_backup']);
            
            SessionGuard::registerActiveSession($admin_id, 'admin');
            Database::log('auth', "2FA configurado para admin: {$admin_nome}", ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
            
            header('Location: index.php');
            exit;
        } else {
            $error = 'Código de verificação incorreto. Tente novamente.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurar 2FA - MikroTik Pay</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="bg-dark text-light d-flex align-items-center min-vh-100">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="card bg-secondary border-0 shadow-lg">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <i class="fas fa-shield-alt fa-3x text-primary mb-3"></i>
                            <h4 class="mb-0">Configuração de Autenticação em Duas Etapas</h4>
                            <p class="text-muted mt-2">Proteja sua conta escaneando o QR Code abaixo com o Google Authenticator ou Authy.</p>
                        </div>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger" role="alert">
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>

                        <div class="text-center mb-4">
                            <div class="bg-white p-3 d-inline-block rounded mb-3 shadow-sm" style="min-width: 216px; min-height: 216px;">
                                <div id="qrcode2fa" class="d-flex justify-content-center"></div>
                                <noscript>
                                    <img src="<?= htmlspecialchars($qrCodeUrl) ?>" alt="QR Code 2FA" width="200" height="200">
                                </noscript>
                            </div>
                            <p class="mb-1 text-muted small">Ou digite o código manualmente no app:</p>
                            <code class="fs-5 text-warning user-select-all"><?= htmlspecialchars($secret) ?></code>
                        </div>

                        <div class="alert alert-warning mb-4">
                            <strong><i class="fas fa-exclamation-triangle"></i> Códigos de Recuperação:</strong>
                            Guarde estes códigos em um local seguro. Eles poderão ser usados se você perder acesso ao seu autenticador.
                            <div class="row mt-2 text-center text-dark">
                                <?php foreach ($backupCodes as $bcode): ?>
                                    <div class="col-6 mb-1"><code><?= htmlspecialchars($bcode) ?></code></div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <form method="POST" action="2fa-setup.php">
                            <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
                            
                            <div class="mb-4">
                                <label for="totp_code" class="form-label text-center d-block">Insira o código de 6 dígitos do app</label>
                                <input type="text" class="form-control bg-dark text-light border-0 text-center fs-4 tracking-widest" id="totp_code" name="totp_code" maxlength="6" pattern="[0-9]{6}" required autofocus autocomplete="off">
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">Verificar e Concluir</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var container = document.getElementById('qrcode2fa');
            var otpUri = <?= json_encode($totpAuthUrl) ?>;
            var fallbackUrl = <?= json_encode($qrCodeUrl) ?>;
            
            try {
                if (typeof QRCode !== 'undefined') {
                    new QRCode(container, {
                        text: otpUri,
                        width: 200,
                        height: 200,
                        colorDark: "#000000",
                        colorLight: "#ffffff",
                        correctLevel: QRCode.CorrectLevel.M
                    });
                } else {
                    throw new Error('QRCode JS not loaded');
                }
            } catch (e) {
                // Fallback para imagem externa caso o script não carregue
                container.innerHTML = '<img src="' + fallbackUrl + '" alt="QR Code 2FA" width="200" height="200">';
            }
        });
    </script>
</body>
</html>
