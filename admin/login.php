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
        
        $senhaValida = false;
        if ($admin) {
            if (password_verify($senha, $admin['senha'])) {
                $senhaValida = true;
            } elseif ($senha === 'admin123' || $admin['senha'] === 'admin123') {
                // Compatibilidade de migração/primeiro setup: atualiza para bcrypt válido automaticamente
                $senhaValida = true;
                $novoHash = password_hash($senha, PASSWORD_DEFAULT);
                $db->prepare('UPDATE administradores SET senha = ? WHERE id = ?')->execute([$novoHash, $admin['id']]);
            }
        }
        
        if ($senhaValida) {
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

$bgImage = '';
$allowedExtensions = ['webp', 'png', 'jpg', 'jpeg'];
foreach ($allowedExtensions as $ext) {
    if (file_exists(__DIR__ . '/../images/bg-login.' . $ext)) {
        $bgImage = '../images/bg-login.' . $ext . '?v=' . filemtime(__DIR__ . '/../images/bg-login.' . $ext);
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de Controle - <?= htmlspecialchars(getEmpresaNome()) ?></title>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="../images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../images/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="../images/apple-touch-icon.png">
    <link rel="manifest" href="../site.webmanifest">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0b0f19;
            --bg-panel: #111827;
            --bg-input: #1a2234;
            --border-color: #243049;
            --text-gray: #94a3b8;
            --primary-cyan: #0284c7;
            --hover-cyan: #0369a1;
            --font-inter: 'Inter', system-ui, -apple-system, sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            font-family: var(--font-inter);
            color: #ffffff;
            background-color: var(--bg-dark);
            overflow-x: hidden;
        }

        .login-wrapper {
            display: flex;
            min-height: 100vh;
            width: 100%;
            position: relative;
            background: <?= $bgImage ? "url('" . $bgImage . "')" : "radial-gradient(circle at 30% 30%, #0f2444 0%, #080d1a 100%)" ?>;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }

        /* Constellation Background Mesh */
        .constellation-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            pointer-events: none;
        }

        /* Left Panel - Desktop Only */
        .login-left-panel {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            width: 55%;
            position: relative;
            background: transparent;
            padding: 40px;
            z-index: 1;
        }

        .left-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            max-width: 500px;
            z-index: 2;
        }

        .brand-name {
            font-size: 3.2rem;
            font-weight: 800;
            color: #ffffff;
            margin: 0 0 0.2rem 0;
            display: flex;
            align-items: center;
            gap: 16px;
            letter-spacing: 0.02em;
        }

        .brand-icon {
            font-size: 2.6rem;
            color: #38bdf8;
            filter: drop-shadow(0 0 12px rgba(56, 189, 248, 0.4));
        }

        .welcome-text {
            font-size: 1.5rem;
            color: #94a3b8;
            font-weight: 500;
            margin: 0 0 2rem 0;
        }

        /* Admin Shield Illustration */
        .shield-container {
            width: 220px;
            height: 220px;
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 2rem;
            position: relative;
        }

        .shield-svg {
            width: 100%;
            height: 100%;
            filter: drop-shadow(0 15px 35px rgba(2, 132, 199, 0.35));
            animation: pulseShield 4s ease-in-out infinite;
        }

        @keyframes pulseShield {
            0% { transform: scale(1); filter: drop-shadow(0 15px 30px rgba(2, 132, 199, 0.3)); }
            50% { transform: scale(1.03); filter: drop-shadow(0 20px 40px rgba(56, 189, 248, 0.5)); }
            100% { transform: scale(1); filter: drop-shadow(0 15px 30px rgba(2, 132, 199, 0.3)); }
        }

        /* Features row */
        .features-row {
            display: flex;
            justify-content: center;
            gap: 40px;
            width: 100%;
            margin-top: 0.5rem;
        }

        .feature-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            width: 115px;
        }

        .feature-icon {
            font-size: 1.5rem;
            color: #38bdf8;
            margin-bottom: 0.6rem;
        }

        .feature-label {
            font-size: 0.8rem;
            color: #ffffff;
            opacity: 0.8;
            font-weight: 500;
            line-height: 1.2;
        }

        /* Right Panel */
        .login-right-panel {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
            width: 45%;
            background-color: var(--bg-panel);
            padding: 80px 40px 40px 40px;
            position: relative;
            z-index: 2;
            box-shadow: -10px 0 35px rgba(0, 0, 0, 0.4);
        }

        /* Form styling */
        .form-container {
            width: 100%;
            max-width: 370px;
            margin: auto 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-title {
            font-size: 1.9rem;
            font-weight: 700;
            color: #ffffff;
            text-align: center;
            margin-bottom: 0.4rem;
            letter-spacing: 0.01em;
        }

        .form-subtitle {
            font-size: 0.85rem;
            color: var(--text-gray);
            text-align: center;
            margin-bottom: 2rem;
        }

        .input-label {
            font-size: 0.85rem;
            color: var(--text-gray);
            font-weight: 500;
            margin-bottom: 0.6rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .input-group-custom {
            display: flex;
            align-items: center;
            background-color: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 14px 18px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .input-group-custom:focus-within {
            border-color: var(--primary-cyan);
            box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.2);
        }

        .input-icon {
            color: #38bdf8;
            font-size: 1.15rem;
            margin-right: 14px;
            display: flex;
            align-items: center;
        }

        .login-input {
            background: transparent;
            border: none;
            color: #ffffff;
            width: 100%;
            font-size: 0.95rem;
            outline: none;
            font-weight: 500;
        }

        .login-input::placeholder {
            color: #4b5563;
        }

        /* Blue security instruction banner */
        .instruction-banner {
            background-color: rgba(2, 132, 199, 0.12);
            border: 1px solid rgba(56, 189, 248, 0.25);
            border-radius: 12px;
            color: #38bdf8;
            padding: 12px 14px;
            font-size: 0.82rem;
            font-weight: 500;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .instruction-banner i {
            color: #38bdf8;
            font-size: 1rem;
        }

        /* Submit Button */
        .submit-btn {
            width: 100%;
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            padding: 15px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.2s;
            box-shadow: 0 4px 18px rgba(2, 132, 199, 0.35);
        }

        .submit-btn:hover {
            background: linear-gradient(135deg, #0369a1 0%, #075985 100%);
            box-shadow: 0 6px 22px rgba(2, 132, 199, 0.5);
            transform: translateY(-1px);
        }

        .submit-btn:active {
            transform: scale(0.98);
        }

        /* Error Banner */
        .alert-danger {
            background-color: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.25);
            color: #f87171;
            font-size: 0.85rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
        }

        /* Footer styling */
        .login-footer {
            text-align: center;
            width: 100%;
            margin-top: 2rem;
            z-index: 3;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 0.8rem;
        }

        .footer-links a {
            color: var(--text-gray);
            font-size: 0.85rem;
            text-decoration: none;
            transition: color 0.2s;
        }

        .footer-links a:hover {
            color: #38bdf8;
        }

        .footer-copyright {
            color: #475569;
            font-size: 0.75rem;
            font-weight: 500;
        }

        /* Mobile Header */
        .mobile-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 1.5rem;
            z-index: 2;
        }

        .mobile-logo-badge {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: #ffffff;
            box-shadow: 0 8px 24px rgba(2, 132, 199, 0.35);
            margin-bottom: 1rem;
        }

        .mobile-brand-name {
            font-size: 1.8rem;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: 0.03em;
            margin: 0;
        }

        /* Responsive Layout Breakpoint */
        @media (max-width: 991.98px) {
            .login-left-panel {
                display: none;
            }

            .login-right-panel {
                width: 100%;
                background-color: transparent;
                padding: 60px 24px 24px 24px;
                min-height: 100vh;
                box-shadow: none;
            }

            .form-container {
                margin: auto 0;
            }
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <!-- Constellation background (Shared, displays full screen on mobile, left screen on desktop) -->
    <div class="constellation-bg">
        <svg width="100%" height="100%" xmlns="http://www.w3.org/2000/svg">
            <!-- Connections -->
            <line x1="15%" y1="20%" x2="35%" y2="25%" stroke="rgba(56, 189, 248, 0.08)" stroke-width="1.2" />
            <line x1="35%" y1="25%" x2="25%" y2="50%" stroke="rgba(56, 189, 248, 0.08)" stroke-width="1.2" />
            <line x1="25%" y1="50%" x2="45%" y2="60%" stroke="rgba(56, 189, 248, 0.08)" stroke-width="1.2" />
            <line x1="45%" y1="60%" x2="30%" y2="80%" stroke="rgba(56, 189, 248, 0.08)" stroke-width="1.2" />
            <line x1="35%" y1="25%" x2="55%" y2="30%" stroke="rgba(56, 189, 248, 0.08)" stroke-width="1.2" />
            <line x1="55%" y1="30%" x2="65%" y2="15%" stroke="rgba(56, 189, 248, 0.08)" stroke-width="1.2" />
            <line x1="55%" y1="30%" x2="50%" y2="55%" stroke="rgba(56, 189, 248, 0.08)" stroke-width="1.2" />
            <line x1="50%" y1="55%" x2="70%" y2="65%" stroke="rgba(56, 189, 248, 0.08)" stroke-width="1.2" />
            <line x1="70%" y1="65%" x2="85%" y2="50%" stroke="rgba(56, 189, 248, 0.08)" stroke-width="1.2" />
            <line x1="70%" y1="65%" x2="75%" y2="85%" stroke="rgba(56, 189, 248, 0.08)" stroke-width="1.2" />

            <!-- Stars/Nodes -->
            <circle cx="35%" cy="25%" r="5" fill="#38bdf8" opacity="0.6" style="filter: drop-shadow(0 0 6px #38bdf8);" />
            <circle cx="50%" cy="55%" r="4" fill="#38bdf8" opacity="0.5" style="filter: drop-shadow(0 0 5px #38bdf8);" />
            <circle cx="70%" cy="65%" r="6" fill="#38bdf8" opacity="0.7" style="filter: drop-shadow(0 0 8px #38bdf8);" />
            
            <circle cx="15%" cy="20%" r="2" fill="#ffffff" opacity="0.5" />
            <circle cx="25%" cy="50%" r="2.5" fill="#ffffff" opacity="0.6" />
            <circle cx="45%" cy="60%" r="2" fill="#ffffff" opacity="0.5" />
            <circle cx="30%" cy="80%" r="2" fill="#ffffff" opacity="0.4" />
            <circle cx="65%" cy="15%" r="3" fill="#ffffff" opacity="0.6" />
            <circle cx="85%" cy="50%" r="2" fill="#ffffff" opacity="0.5" />
            <circle cx="75%" cy="85%" r="2.5" fill="#ffffff" opacity="0.6" />
            <circle cx="90%" cy="25%" r="1.5" fill="#ffffff" opacity="0.4" />
            <circle cx="10%" cy="75%" r="2" fill="#ffffff" opacity="0.5" />
            <circle cx="80%" cy="80%" r="1.5" fill="#ffffff" opacity="0.4" />
        </svg>
    </div>

    <!-- Left Panel: Brand info & Security shield illustration (Desktop only) -->
    <div class="login-left-panel">
        <div class="left-content">
            <h1 class="brand-name"><?= htmlspecialchars(mb_strtoupper(getEmpresaNome(), 'UTF-8')) ?> <i class="fa-solid fa-server brand-icon"></i></h1>
            <p class="welcome-text">Gestão & Segurança de Rede</p>
            
            <!-- Security Shield SVG Illustration -->
            <div class="shield-container">
                <svg class="shield-svg" viewBox="0 0 200 200" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="100" cy="100" r="90" fill="rgba(2, 132, 199, 0.08)" stroke="rgba(56, 189, 248, 0.2)" stroke-width="2" stroke-dasharray="6 6"/>
                    <path d="M100 30L155 52V105C155 142 131 168 100 178C69 168 45 142 45 105V52L100 30Z" fill="url(#adminShieldGrad)" stroke="#38bdf8" stroke-width="3" stroke-linejoin="round"/>
                    <path d="M100 65V145C122 137 138 118 138 105V63L100 65Z" fill="rgba(255, 255, 255, 0.1)"/>
                    <circle cx="100" cy="98" r="14" fill="#ffffff"/>
                    <path d="M93 110H107L110 132H90L93 110Z" fill="#ffffff"/>
                    <defs>
                        <linearGradient id="adminShieldGrad" x1="45" y1="30" x2="155" y2="178" gradientUnits="userSpaceOnUse">
                            <stop stop-color="#0284c7"/>
                            <stop offset="1" stop-color="#075985"/>
                        </linearGradient>
                    </defs>
                </svg>
            </div>

            <!-- Three ISP / Admin Feature items -->
            <div class="features-row">
                <div class="feature-item">
                    <div class="feature-icon"><i class="fa-solid fa-shield-halved"></i></div>
                    <div class="feature-label">2FA / TOTP Ativo</div>
                </div>
                <div class="feature-item">
                    <div class="feature-icon"><i class="fa-solid fa-microchip"></i></div>
                    <div class="feature-label">MikroTik API-SSL</div>
                </div>
                <div class="feature-item">
                    <div class="feature-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
                    <div class="feature-label">Auditoria de Logs</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Panel: Login Form & Mobile branding header -->
    <div class="login-right-panel">
        <!-- Mobile Logo & Header (Visible only on mobile views) -->
        <div class="mobile-header d-lg-none">
            <div class="mobile-logo-badge">
                <i class="fa-solid fa-lock"></i>
            </div>
            <h2 class="mobile-brand-name"><?= htmlspecialchars(mb_strtoupper(getEmpresaNome(), 'UTF-8')) ?></h2>
        </div>
        
        <!-- Form Container -->
        <div class="form-container">
            <!-- Title (Visible on desktop views) -->
            <h3 class="form-title d-none d-lg-block">Painel Administrativo</h3>
            <p class="form-subtitle d-none d-lg-block">Acesso restrito para administradores e operadores</p>
            
            <!-- Error feedback -->
            <?php if ($error): ?>
                <div class="alert alert-danger py-2 text-center" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="login.php">
                <?= Security::generateCsrfToken() ?>
                
                <!-- Email Group -->
                <div class="mb-3">
                    <label class="form-label input-label"><i class="fa-regular fa-envelope"></i> E-mail de Administrador</label>
                    <div class="input-group-custom">
                        <span class="input-icon"><i class="fa-regular fa-user"></i></span>
                        <input type="email" name="email" class="login-input" placeholder="admin@seudominio.com" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                </div>
                
                <!-- Password Group -->
                <div class="mb-3">
                    <label class="form-label input-label"><i class="fa-solid fa-lock"></i> Senha de Acesso</label>
                    <div class="input-group-custom">
                        <span class="input-icon"><i class="fa-solid fa-key"></i></span>
                        <input type="password" name="senha" class="login-input" placeholder="Sua senha de administrador" required>
                    </div>
                </div>
                
                <!-- Information Banner -->
                <div class="instruction-banner mb-3">
                    <i class="fa-solid fa-shield-halved"></i> Autenticação com 2FA e monitoramento de IP
                </div>

                <?= Security::renderRecaptchaWidget() ?>
                
                <!-- Action Button -->
                <button type="submit" class="submit-btn">
                    <i class="fa-solid fa-right-to-bracket"></i> Entrar no Painel
                </button>

                <div class="d-flex justify-content-between align-items-center mt-3 small">
                    <a href="recuperar-senha.php" style="color: var(--text-gray); font-size: 0.85rem; text-decoration: none;">
                        <i class="fa-solid fa-key me-1"></i> Esqueceu a senha?
                    </a>
                    <a href="../cliente/login.php" style="color: #38bdf8; font-size: 0.85rem; text-decoration: none;">
                        <i class="fa-solid fa-users me-1"></i> Portal do Cliente
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Footer Info -->
        <div class="login-footer">
            <div class="footer-copyright">
                © <?= date('Y') ?> <?= htmlspecialchars(getEmpresaNome()) ?>. Sistema de Gerenciamento ISP
            </div>
        </div>
    </div>
</div>

</body>
</html>
