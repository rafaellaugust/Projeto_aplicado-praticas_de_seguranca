<?php
/**
 * @file cliente/recuperar-senha.php
 * @brief Recuperação de senha do assinante/cliente
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Security.php';

Security::sendSecurityHeaders();

$erro = '';
$sucesso = '';

$tokenParam = trim($_GET['token'] ?? '');
$isPasso2 = ($tokenParam !== '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrfToken()) {
        $erro = 'Sessão expirada ou requisição inválida. Tente novamente.';
    } else {
        try {
            $db = Database::getInstance();

        // Auto-assegura estrutura e AUTO_INCREMENT na tabela password_resets
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS `password_resets` (
                    `id` int NOT NULL AUTO_INCREMENT,
                    `email` varchar(150) NOT NULL,
                    `user_type` enum('admin','cliente') NOT NULL DEFAULT 'cliente',
                    `token_hash` varchar(64) NOT NULL,
                    `expires_at` datetime NOT NULL,
                    `used` tinyint(1) NOT NULL DEFAULT 0,
                    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_token` (`token_hash`),
                    KEY `idx_email_type` (`email`, `user_type`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        } catch (\Throwable $t) {}

        if (!$isPasso2) {
            $identificador = trim($_POST['identificador'] ?? '');

            if (empty($identificador)) {
                $erro = 'Informe seu E-mail, Telefone (WhatsApp) ou CPF cadastrado.';
            } else {
                $cleanInput = preg_replace('/[^0-9]/', '', $identificador);
                $waSuffix = (strlen($cleanInput) >= 8) ? substr($cleanInput, -8) : '';

                $sql = "
                    SELECT id, nome, email, whatsapp FROM clientes
                    WHERE email = :ident1 
                       OR pppoe_usuario = :ident2
                       OR cpf_cnpj = :ident3
                ";
                $params = [
                    ':ident1' => $identificador,
                    ':ident2' => $identificador,
                    ':ident3' => $identificador
                ];

                if (!empty($cleanInput)) {
                    $sql .= " OR REPLACE(REPLACE(REPLACE(cpf_cnpj, '.', ''), '-', ''), '/', '') = :cleanCpf";
                    $sql .= " OR REPLACE(REPLACE(REPLACE(REPLACE(whatsapp, '(', ''), ')', ''), '-', ''), ' ', '') = :cleanWa";
                    $params[':cleanCpf'] = $cleanInput;
                    $params[':cleanWa'] = $cleanInput;
                }

                if (!empty($waSuffix)) {
                    $sql .= " OR RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(whatsapp, '(', ''), ')', ''), '-', ''), ' ', ''), 8) = :waSuffix";
                    $params[':waSuffix'] = $waSuffix;
                }

                $sql .= " LIMIT 1";

                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $cliente = $stmt->fetch();

                if ($cliente) {
                    if (!empty($cliente['email'])) {
                        try {
                            $db->prepare("UPDATE password_resets SET used = 1 WHERE email = ? AND user_type = 'cliente' AND used = 0")->execute([$cliente['email']]);
                        } catch (\Throwable $t) {}

                        try {
                            $token = bin2hex(random_bytes(32));
                        } catch (\Throwable $e) {
                            $token = bin2hex(openssl_random_pseudo_bytes(32));
                        }
                        $tokenHash = hash('sha256', $token);

                        // Inserção com auto-recuperação de ID / AUTO_INCREMENT
                        try {
                            $db->prepare("
                                INSERT INTO password_resets (email, user_type, token_hash, expires_at, used, created_at)
                                VALUES (?, 'cliente', ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), 0, NOW())
                            ")->execute([$cliente['email'], $tokenHash]);
                        } catch (\Throwable $t) {
                            try { $db->exec("ALTER TABLE `password_resets` ADD PRIMARY KEY (`id`)"); } catch (\Throwable $t2) {}
                            try { $db->exec("ALTER TABLE `password_resets` MODIFY `id` int NOT NULL AUTO_INCREMENT"); } catch (\Throwable $t3) {}
                            $maxId = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM password_resets")->fetchColumn() + 1;
                            $db->prepare("
                                INSERT INTO password_resets (id, email, user_type, token_hash, expires_at, used, created_at)
                                VALUES (?, ?, 'cliente', ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), 0, NOW())
                            ")->execute([$maxId, $cliente['email'], $tokenHash]);
                        }

                        $link = BASE_URL . '/cliente/recuperar-senha.php?token=' . rawurlencode($token);
                        $nomeCliente = !empty($cliente['nome']) ? (string)$cliente['nome'] : 'Assinante';
                        $enviou = Security::sendPasswordResetEmail($cliente['email'], $nomeCliente, $link, 'cliente');

                        Database::log('auth_cliente', "Solicitação de recuperação de senha do cliente: {$cliente['email']}", [
                            'cliente_id' => $cliente['id'],
                            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido',
                            'email_enviado' => $enviou ? 'sucesso' : 'falha'
                        ]);

                        if ($enviou) {
                            $emailParts = explode('@', $cliente['email']);
                            $emailMasc = substr($emailParts[0], 0, 3) . '***@' . ($emailParts[1] ?? '');
                            $sucesso = "As instruções de redefinição de senha foram enviadas para o seu e-mail cadastrado ({$emailMasc}). Verifique sua caixa de entrada e a pasta de spam.";
                        } else {
                            $erro = "Não foi possível enviar o e-mail de recuperação: " . (Security::$lastSmtpError ?: "Falha na conexão SMTP com o servidor de e-mail.") . " Por favor, tente novamente ou entre em contato com nosso atendimento.";
                        }
                    } else {
                        $erro = 'Encontramos seu cadastro, porém você ainda não possui um e-mail cadastrado para recebimento do link de recuperação. Por favor, entre em contato com nosso atendimento via WhatsApp para cadastrar seu e-mail ou definir uma nova senha.';
                    }
                } else {
                    $sucesso = 'Se seus dados estiverem cadastrados e você possuir um e-mail em nosso sistema, as instruções foram enviadas.';
                }
            }
        } else {
            $tokenRecebido = trim($_POST['token'] ?? '');
            $novaSenha = $_POST['nova_senha'] ?? '';
            $confirmarSenha = $_POST['confirmar_senha'] ?? '';

            if (empty($tokenRecebido)) {
                $erro = 'Token de recuperação inválido ou ausente.';
            } elseif (strlen($novaSenha) < 6) {
                $erro = 'A nova senha deve ter no mínimo 6 caracteres.';
            } elseif ($novaSenha !== $confirmarSenha) {
                $erro = 'As senhas informadas não coincidem.';
            } else {
                $tokenHash = hash('sha256', $tokenRecebido);

                $stmt = $db->prepare("
                    SELECT id, email FROM password_resets
                    WHERE token_hash = ? AND user_type = 'cliente' AND expires_at > NOW() AND used = 0
                    LIMIT 1
                ");
                $stmt->execute([$tokenHash]);
                $reset = $stmt->fetch();

                if (!$reset) {
                    $erro = 'Link de recuperação expirado ou inválido. Solicite uma nova redefinição.';
                } else {
                    $novoHash = password_hash($novaSenha, PASSWORD_DEFAULT);

                    $db->prepare("UPDATE clientes SET senha = ?, primeiro_acesso = 0 WHERE email = ?")->execute([$novoHash, $reset['email']]);
                    $db->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")->execute([$reset['id']]);

                    Database::log('auth_cliente', "Senha de cliente redefinida com sucesso: {$reset['email']}", [
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido'
                    ]);

                    header('Location: login.php?recuperado=1');
                    exit;
                }
            }

            $isPasso2 = true;
            $tokenParam = $tokenRecebido ?: $tokenParam;
        }
    } catch (\Throwable $e) {
        error_log("Erro em cliente/recuperar-senha.php: " . $e->getMessage());
        Database::log('erro_recuperar_senha', "Exceção em cliente/recuperar-senha.php: " . $e->getMessage(), [
            'identificador' => $_POST['identificador'] ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido'
        ]);
        $erro = 'Ocorreu uma falha temporária ao processar sua solicitação: ' . $e->getMessage();
    }
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
    <title>Recuperar Senha - <?= htmlspecialchars(getEmpresaNome()) ?></title>
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
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 0; min-height: 100vh;
            font-family: var(--font-inter); color: #ffffff;
            background-color: var(--bg-dark); overflow-x: hidden;
        }
        .login-wrapper {
            display: flex; min-height: 100vh; width: 100%; position: relative;
            background: <?= $bgImage ? "url('" . $bgImage . "')" : "radial-gradient(circle at 30% 30%, #152546 0%, #0d1222 100%)" ?>;
            background-size: cover; background-position: center; background-repeat: no-repeat;
        }
        .login-left-panel {
            display: flex; flex-direction: column; justify-content: center; align-items: center;
            width: 55%; position: relative; background: transparent; padding: 40px; z-index: 1;
        }
        .brand-name { font-size: 3rem; font-weight: 800; color: #ffffff; margin: 0 0 0.2rem 0; }
        .welcome-text { font-size: 1.6rem; color: #ffffff; font-weight: 500; margin: 0 0 2rem 0; opacity: 0.9; }
        .login-right-panel {
            display: flex; flex-direction: column; justify-content: space-between; align-items: center;
            width: 45%; background-color: var(--bg-panel); padding: 80px 40px 40px 40px;
            position: relative; z-index: 2; box-shadow: -10px 0 30px rgba(0,0,0,.25);
        }
        .form-container { width: 100%; max-width: 360px; margin: auto 0; display: flex; flex-direction: column; justify-content: center; }
        .form-title { font-size: 1.8rem; font-weight: 700; color: #ffffff; text-align: center; margin-bottom: 2rem; }
        .input-label { font-size: .85rem; color: var(--text-gray); font-weight: 500; margin-bottom: .6rem; display: flex; align-items: center; gap: 8px; }
        .input-group-custom {
            display: flex; align-items: center; background-color: var(--bg-input);
            border: 1px solid var(--border-color); border-radius: 12px; padding: 14px 18px;
            transition: border-color .2s, box-shadow .2s;
        }
        .input-group-custom:focus-within { border-color: var(--primary-orange); box-shadow: 0 0 0 3px rgba(245,158,11,.15); }
        .input-icon { color: var(--primary-orange); font-size: 1.1rem; margin-right: 14px; display: flex; align-items: center; }
        .login-input { background: transparent; border: none; color: #ffffff; width: 100%; font-size: .95rem; outline: none; font-weight: 500; }
        .submit-btn {
            width: 100%; background-color: var(--primary-orange); color: #ffffff; border: none;
            border-radius: 12px; padding: 15px; font-size: 1rem; font-weight: 700; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            transition: background-color .2s, transform .1s, box-shadow .2s; box-shadow: 0 4px 15px rgba(245,158,11,.2);
        }
        .submit-btn:hover { background-color: var(--hover-orange); }
        @media (max-width: 991.98px) {
            .login-left-panel { display: none; }
            .login-right-panel { width: 100%; background-color: transparent; padding: 60px 24px 24px 24px; min-height: 100vh; box-shadow: none; }
        }
    </style>
</head>
<body>
<div class="login-wrapper">
    <div class="login-left-panel">
        <h1 class="brand-name"><?= htmlspecialchars(mb_strtoupper(getEmpresaNome(), 'UTF-8')) ?> <i class="fa-solid fa-wifi" style="transform:rotate(45deg);"></i></h1>
        <p class="welcome-text">Recuperação de Acesso</p>
    </div>

    <div class="login-right-panel">
        <div class="form-container">
            <h3 class="form-title"><?= $isPasso2 ? 'Nova Senha' : 'Recuperar Acesso' ?></h3>

            <?php if ($erro): ?>
                <div class="alert alert-danger py-2 text-center" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= htmlspecialchars($erro) ?>
                </div>
            <?php endif; ?>

            <?php if ($sucesso): ?>
                <div class="alert alert-success py-2 text-center" role="alert">
                    <i class="fa-solid fa-circle-check me-1"></i> <?= htmlspecialchars($sucesso) ?>
                </div>
                <div class="text-center mt-3">
                    <a href="login.php" class="submit-btn text-decoration-none">
                        <i class="fa-solid fa-arrow-left"></i> Voltar ao Login
                    </a>
                </div>
            <?php else: ?>

                <?php if (!$isPasso2): ?>
                    <form method="POST" action="recuperar-senha.php">
                        <?= Security::generateCsrfToken() ?>

                        <div class="mb-4">
                            <label class="form-label input-label">
                                <i class="fa-solid fa-user"></i> E-mail, Telefone (WhatsApp) ou CPF
                            </label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fa-regular fa-id-card"></i></span>
                                <input type="text" name="identificador" class="login-input" placeholder="Digite seu e-mail, telefone ou CPF" required autofocus value="<?= htmlspecialchars($_POST['identificador'] ?? '') ?>">
                            </div>
                            <small style="color: var(--text-gray); font-size: 0.8rem; display: block; margin-top: 6px;">
                                O link de confirmação para redefinição será enviado ao seu e-mail cadastrado.
                            </small>
                        </div>

                        <button type="submit" class="submit-btn">
                            <i class="fa-solid fa-paper-plane"></i> Enviar Link de Recuperação
                        </button>
                    </form>
                <?php else: ?>
                    <form method="POST" action="recuperar-senha.php?token=<?= rawurlencode($tokenParam) ?>">
                        <?= Security::generateCsrfToken() ?>
                        <input type="hidden" name="token" value="<?= htmlspecialchars($tokenParam) ?>">

                        <div class="mb-3">
                            <label class="form-label input-label">
                                <i class="fa-solid fa-lock"></i> Nova Senha
                            </label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fa-solid fa-key"></i></span>
                                <input type="password" name="nova_senha" class="login-input" placeholder="Mínimo 6 caracteres" minlength="6" required autofocus>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label input-label">
                                <i class="fa-solid fa-lock"></i> Confirmar Nova Senha
                            </label>
                            <div class="input-group-custom">
                                <span class="input-icon"><i class="fa-solid fa-check"></i></span>
                                <input type="password" name="confirmar_senha" class="login-input" placeholder="Repita a senha" minlength="6" required>
                            </div>
                        </div>

                        <button type="submit" class="submit-btn">
                            <i class="fa-solid fa-floppy-disk"></i> Salvar Nova Senha
                        </button>
                    </form>
                <?php endif; ?>

                <div class="text-center mt-4">
                    <a href="login.php" style="color: var(--text-gray); font-size: 0.85rem; text-decoration: none;">
                        <i class="fa-solid fa-arrow-left me-1"></i>Voltar para o login
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
