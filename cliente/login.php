<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/SessionGuard.php';

SessionGuard::init();
Security::sendSecurityHeaders();

$erro = '';
$sucessoMsg = isset($_GET['recuperado']) ? 'Sua senha foi redefinida com sucesso! Faça login com sua nova credencial.' : '';

$db = Database::getInstance();
$semSenhaAtivado = false;
try {
    $semSenhaAtivado = (bool)$db->query("SELECT client_login_no_password FROM security_settings LIMIT 1")->fetchColumn();
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrfToken()) {
        $erro = 'Sessão expirada ou requisição inválida. Tente novamente.';
    } elseif (!Security::verifyRecaptcha($_POST['g-recaptcha-response'] ?? '')) {
        $erro = 'Falha na verificação do reCAPTCHA. Confirme que você não é um robô.';
    } else {
        $loginInput = sanitize($_POST['login_input'] ?? '');
        $senha = $_POST['senha'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';

        if (Security::isIpBlocked($ip)) {
            $erro = 'Seu IP está bloqueado por motivos de segurança.';
        } elseif (!Security::checkRateLimit($ip, 'login', 5, 15)) {
            $erro = 'Muitas tentativas. Tente novamente em 15 minutos.';
        } elseif ($loginInput && ($senha || $semSenhaAtivado)) {
            $cleanInput = preg_replace('/[^0-9]/', '', $loginInput);
            $waClean = (strlen($cleanInput) >= 10 && substr($cleanInput, 0, 2) === '55') ? substr($cleanInput, 2) : $cleanInput;

            $stmt = $db->prepare("
                SELECT * FROM clientes 
                WHERE email = ? 
                   OR pppoe_usuario = ? 
                   OR cpf_cnpj = ? 
                   OR REPLACE(REPLACE(REPLACE(cpf_cnpj, '.', ''), '-', ''), '/', '') = ?
                   OR whatsapp = ? 
                   OR whatsapp = ? 
                   OR (LENGTH(?) >= 8 AND SUBSTRING(whatsapp, -8) = SUBSTRING(?, -8))
                LIMIT 1
            ");
            $stmt->execute([
                $loginInput,
                $loginInput,
                $loginInput,
                $cleanInput ?: $loginInput,
                $loginInput,
                $waClean ?: $loginInput,
                $cleanInput ?: '0',
                $cleanInput ?: '0'
            ]);
            $cliente = $stmt->fetch();

            if ($cliente) {
                $senhaValida = false;
                
                // 1. Modo de Teste Rápido (Segurança Reduzida)
                if ($semSenhaAtivado) {
                    $senhaValida = true;
                }
                // 2. Primeiro acesso ou senha não definida (usa CPF)
                elseif ($cliente['senha'] === null || $cliente['primeiro_acesso'] == 1) {
                    $cpfClienteLimpo = preg_replace('/[^0-9]/', '', $cliente['cpf_cnpj']);
                    if ($senha === $cpfClienteLimpo || $senha === $cliente['cpf_cnpj']) {
                        $senhaValida = true;
                        
                        // Atualiza para não ser mais primeiro acesso
                        $stmtUpdate = $db->prepare("UPDATE clientes SET primeiro_acesso = 0 WHERE id = ?");
                        $stmtUpdate->execute([$cliente['id']]);
                        
                        $_SESSION['aviso_senha'] = true;
                    } else {
                        $erro = 'Primeiro acesso: Sua senha padrão é o seu CPF (somente números).';
                        Security::recordAttempt($ip, 'login', false, $loginInput, 'cliente');
                    }
                } 
                // 3. Verificação normal de senha cadastrada na plataforma
                else {
                    if (password_verify($senha, $cliente['senha'])) {
                        $senhaValida = true;
                    } else {
                        $erro = 'Senha incorreta. Caso tenha esquecido, utilize o link de recuperação abaixo.';
                        Security::recordAttempt($ip, 'login', false, $loginInput, 'cliente');
                    }
                }

                if ($senhaValida) {
                    $_SESSION['cliente_id'] = $cliente['id'];
                    $_SESSION['cliente_nome'] = $cliente['nome'];
                    $_SESSION['cliente_pppoe'] = $cliente['pppoe_usuario'];

                    SessionGuard::registerActiveSession($cliente['id'], 'cliente');
                    Security::recordAttempt($ip, 'login', true, $loginInput, 'cliente');
                    Security::trustCurrentDevice((int)$cliente['id'], 'cliente');

                    Database::log('auth_cliente', "Cliente logou no portal: {$cliente['nome']} ({$cliente['pppoe_usuario']})" . ($semSenhaAtivado ? " [Modo Sem Senha]" : ""), [
                        'cliente_id' => $cliente['id'],
                        'ip' => $ip,
                        'modo' => $semSenhaAtivado ? 'sem_senha' : 'com_senha'
                    ]);

                    header('Location: index.php');
                    exit;
                }
            } else {
                Security::recordAttempt($ip, 'login', false, $loginInput, 'cliente');
                Database::log('auth_cliente_falha', "Tentativa de login no portal cliente falhou: {$loginInput}", [
                    'ip' => $ip
                ]);
                $erro = 'Assinante não encontrado com os dados informados (E-mail, Telefone ou CPF).';
            }
        } else {
            $erro = 'Informe seu usuário e senha.';
        }
    }
}

$bgImage = '';
$allowedExtensions = ['png', 'jpg', 'jpeg', 'webp'];
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
    <title>Portal do Assinante - Spaço Nett</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0f101a;
            --bg-panel: #131420;
            --bg-input: #1f2030;
            --border-color: #2f324d;
            --text-gray: #8c9ab8;
            --primary-orange: #f59e0b;
            --hover-orange: #d97706;
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
            background: <?= $bgImage ? "url('" . $bgImage . "')" : "radial-gradient(circle at 30% 30%, #152546 0%, #0d1222 100%)" ?>;
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
            font-size: 3.5rem;
            font-weight: 800;
            color: #ffffff;
            margin: 0 0 0.2rem 0;
            display: flex;
            align-items: center;
            gap: 16px;
            letter-spacing: 0.02em;
        }

        .brand-wifi-icon {
            font-size: 2.8rem;
            color: #ffffff;
            transform: rotate(45deg);
        }

        .welcome-text {
            font-size: 1.85rem;
            color: #ffffff;
            font-weight: 500;
            margin: 0 0 2rem 0;
            opacity: 0.95;
        }

        /* Rocket container and animation */
        .rocket-container {
            width: 260px;
            height: 260px;
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 2rem;
        }

        .rocket-svg {
            width: 100%;
            height: 100%;
            filter: drop-shadow(0 15px 30px rgba(56, 189, 248, 0.2));
            animation: floatRocket 4s ease-in-out infinite;
        }

        @keyframes floatRocket {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-12px); }
            100% { transform: translateY(0px); }
        }

        /* Features row */
        .features-row {
            display: flex;
            justify-content: center;
            gap: 40px;
            width: 100%;
            margin-top: 1rem;
        }

        .feature-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            width: 110px;
        }

        .feature-icon {
            font-size: 1.6rem;
            color: #ffffff;
            margin-bottom: 0.6rem;
            opacity: 0.95;
        }

        .feature-label {
            font-size: 0.8rem;
            color: #ffffff;
            opacity: 0.75;
            font-weight: 500;
            line-height: 1.2;
        }

        /* Right Panel - Solid dark panel */
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
            box-shadow: -10px 0 30px rgba(0, 0, 0, 0.25);
        }

        /* Form styling */
        .form-container {
            width: 100%;
            max-width: 360px;
            margin: auto 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-title {
            font-size: 2rem;
            font-weight: 700;
            color: #ffffff;
            text-align: center;
            margin-bottom: 2.5rem;
            letter-spacing: 0.01em;
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
            border-color: var(--primary-orange);
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
        }

        .input-icon {
            color: var(--primary-orange);
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
            color: #555875;
        }

        /* Brown instructions banner */
        .instruction-banner {
            background-color: #2b2520;
            border: 1px solid #44362a;
            border-radius: 12px;
            color: var(--primary-orange);
            padding: 14px;
            font-size: 0.85rem;
            font-weight: 600;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .instruction-banner i {
            color: var(--primary-orange);
            font-size: 1rem;
        }

        /* Submit Button */
        .submit-btn {
            width: 100%;
            background-color: var(--primary-orange);
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
            transition: background-color 0.2s, transform 0.1s, box-shadow 0.2s;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.2);
        }

        .submit-btn:hover {
            background-color: var(--hover-orange);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.3);
        }

        .submit-btn:active {
            transform: scale(0.98);
        }

        /* Error Banner styling */
        .alert-danger {
            background-color: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
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

        .footer-whatsapp {
            color: var(--text-gray);
            font-size: 0.85rem;
            margin-bottom: 0.6rem;
            font-weight: 500;
        }

        .footer-copyright {
            color: #4a5168;
            font-size: 0.75rem;
            font-weight: 500;
        }

        /* Mobile Header */
        .mobile-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 1rem;
            z-index: 2;
        }

        .mobile-logo-badge {
            width: 76px;
            height: 76px;
            background-color: var(--primary-orange);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 34px;
            color: #ffffff;
            box-shadow: 0 8px 24px rgba(245, 158, 11, 0.25);
            margin-bottom: 1.2rem;
        }

        .mobile-brand-name {
            font-size: 2rem;
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

    <!-- Left Panel: Brand info & rocket (Desktop only) -->
    <div class="login-left-panel">
        <div class="left-content">
            <h1 class="brand-name">SPAÇO NETT <i class="fa-solid fa-wifi brand-wifi-icon"></i></h1>
            <p class="welcome-text">Bem vindo</p>
            
            <!-- Rocket illustration SVG -->

            <!-- Three feature icons -->
            <div class="features-row">
                <div class="feature-item">
                    <div class="feature-icon"><i class="fa-solid fa-bolt"></i></div>
                    <div class="feature-label">Alta Velocidade</div>
                </div>
                <div class="feature-item">
                    <div class="feature-icon"><i class="fa-solid fa-shield-halved"></i></div>
                    <div class="feature-label">Conexão Segura</div>
                </div>
                <div class="feature-item">
                    <div class="feature-icon"><i class="fa-solid fa-headset"></i></div>
                    <div class="feature-label">Suporte Rápido</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Panel: Login form & Mobile branding header -->
    <div class="login-right-panel">
        <!-- Mobile Logo & Header (Visible only on mobile views) -->
        <div class="mobile-header d-lg-none">
            <div class="mobile-logo-badge">
                <i class="fa-solid fa-wifi"></i>
            </div>
            <h2 class="mobile-brand-name">SPAÇO NETT</h2>
        </div>
        
        <!-- Form Container -->
        <div class="form-container">
            <!-- Title (Visible only on desktop views) -->
            <h3 class="form-title d-none d-lg-block">Área do Cliente</h3>
            
            <!-- Success feedback -->
            <?php if ($sucessoMsg): ?>
                <div class="alert alert-success py-2 text-center" role="alert" style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399; font-size: 0.85rem; border-radius: 10px; margin-bottom: 1.5rem;">
                    <i class="fa-solid fa-circle-check me-1"></i> <?= $sucessoMsg ?>
                </div>
            <?php endif; ?>

            <!-- Error feedback -->
            <?php if ($erro): ?>
                <div class="alert alert-danger py-2 text-center" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= $erro ?>
                </div>
            <?php endif; ?>

            <?php if ($semSenhaAtivado): ?>
                <div class="p-2 mb-3 rounded text-center" style="background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.3); color: #fbbf24; font-size: 0.82rem;">
                    <i class="fa-solid fa-flask me-1"></i> <strong>Modo de Teste Ativo:</strong> Digite apenas seu E-mail, Telefone ou CPF para acessar (senha desnecessária).
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <?= Security::generateCsrfToken() ?>
                
                <!-- Form Group -->
                <div class="mb-3">
                    <label class="form-label input-label"><i class="fa-regular fa-envelope"></i> Email / Telefone / CPF</label>
                    <div class="input-group-custom">
                        <span class="input-icon"><i class="fa-regular fa-user"></i></span>
                        <input type="text" name="login_input" class="login-input" placeholder="Email, telefone ou CPF" required>
                    </div>
                </div>
                
                <!-- Password Group -->
                <div class="mb-3" <?= $semSenhaAtivado ? 'style="display:none;"' : '' ?>>
                    <label class="form-label input-label"><i class="fa-solid fa-lock"></i> Senha</label>
                    <div class="input-group-custom">
                        <span class="input-icon"><i class="fa-solid fa-key"></i></span>
                        <input type="password" name="senha" class="login-input" placeholder="Sua senha" <?= $semSenhaAtivado ? '' : 'required' ?>>
                    </div>
                    <small class="text-muted mt-2 d-block text-center" style="font-size: 0.8rem;">Primeiro acesso? Sua senha padrão é o seu CPF.</small>
                </div>
                
                <!-- Information Banner -->
                <div class="instruction-banner mb-3">
                    <i class="fa-solid fa-hand-pointer"></i> Use o email, telefone ou CPF cadastrado
                </div>

                <?= Security::renderRecaptchaWidget() ?>
                
                <!-- Action Button -->
                <button type="submit" class="submit-btn">
                    <i class="fa-solid fa-right-to-bracket"></i> Acessar Minha Conta
                </button>

                <div class="text-center mt-3">
                    <a href="recuperar-senha.php" style="color: var(--text-gray); font-size: 0.85rem; text-decoration: none;">
                        <i class="fa-solid fa-key me-1"></i> Esqueceu sua senha?
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Footer Info -->
        <div class="login-footer">
            <div class="footer-whatsapp">
                WhatsApp &nbsp;|&nbsp; (82) 9.9933 - 4425
            </div>
            <div class="footer-copyright">
                © 2026 Spaço Nett. Sistema de Gerenciamento
            </div>
        </div>
    </div>
</div>

</body>
</html>
