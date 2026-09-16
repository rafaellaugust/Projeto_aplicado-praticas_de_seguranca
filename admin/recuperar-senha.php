<?php
/**
 * @file admin/recuperar-senha.php
 * @brief Recuperação de senha do administrador (dois passos: solicitar e-mail / redefinir senha)
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
        $erro = 'Token de segurança inválido ou sessão expirada. Tente novamente.';
    } else {
        try {
            $db = Database::getInstance();

            // Auto-assegura estrutura e AUTO_INCREMENT na tabela password_resets
            try {
                $db->exec("
                    CREATE TABLE IF NOT EXISTS `password_resets` (
                        `id` int NOT NULL AUTO_INCREMENT,
                        `email` varchar(150) NOT NULL,
                        `user_type` enum('admin','cliente') NOT NULL DEFAULT 'admin',
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
                $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);

                if (empty($email)) {
                    $erro = 'Informe um endereço de e-mail válido.';
                } else {
                    $stmt = $db->prepare('SELECT id, nome, email FROM administradores WHERE email = ? LIMIT 1');
                    $stmt->execute([$email]);
                    $admin = $stmt->fetch();

                    if ($admin) {
                        try {
                            $db->prepare("UPDATE password_resets SET used = 1 WHERE email = ? AND user_type = 'admin' AND used = 0")->execute([$admin['email']]);
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
                                VALUES (?, 'admin', ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), 0, NOW())
                            ")->execute([$admin['email'], $tokenHash]);
                        } catch (\Throwable $t) {
                            try { $db->exec("ALTER TABLE `password_resets` ADD PRIMARY KEY (`id`)"); } catch (\Throwable $t2) {}
                            try { $db->exec("ALTER TABLE `password_resets` MODIFY `id` int NOT NULL AUTO_INCREMENT"); } catch (\Throwable $t3) {}
                            $maxId = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM password_resets")->fetchColumn() + 1;
                            $db->prepare("
                                INSERT INTO password_resets (id, email, user_type, token_hash, expires_at, used, created_at)
                                VALUES (?, ?, 'admin', ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), 0, NOW())
                            ")->execute([$maxId, $admin['email'], $tokenHash]);
                        }

                        $link = BASE_URL . '/admin/recuperar-senha.php?token=' . rawurlencode($token);
                        $nomeAdmin = !empty($admin['nome']) ? (string)$admin['nome'] : 'Administrador';
                        $enviou = Security::sendPasswordResetEmail($admin['email'], $nomeAdmin, $link, 'admin');

                        Database::log('auth', "Solicitação de recuperação de senha admin: {$admin['email']}", [
                            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido',
                            'email_enviado' => $enviou ? 'sucesso' : 'falha',
                            'erro' => $enviou ? null : Security::$lastSmtpError
                        ]);

                        if ($enviou) {
                            $sucesso = 'Se o e-mail informado estiver cadastrado, as instruções e o link de recuperação foram enviados com sucesso.';
                        } else {
                            $erro = "Falha ao enviar o e-mail de recuperação: " . (Security::$lastSmtpError ?: "Erro no servidor SMTP.");
                        }
                    } else {
                        $sucesso = 'Se o e-mail informado estiver cadastrado, as instruções e o link de recuperação foram enviados com sucesso.';
                    }
                }
            } else {
                $tokenRecebido = trim($_POST['token'] ?? '');
                $novaSenha = $_POST['nova_senha'] ?? '';
                $confirmarSenha = $_POST['confirmar_senha'] ?? '';

                if (empty($tokenRecebido)) {
                    $erro = 'Token inválido ou ausente.';
                } elseif (strlen($novaSenha) < 8) {
                    $erro = 'A nova senha deve ter no mínimo 8 caracteres.';
                } elseif ($novaSenha !== $confirmarSenha) {
                    $erro = 'As senhas informadas não coincidem.';
                } else {
                    $tokenHash = hash('sha256', $tokenRecebido);

                    $stmt = $db->prepare("
                        SELECT id, email FROM password_resets
                        WHERE token_hash = ? AND user_type = 'admin' AND expires_at > NOW() AND used = 0
                        LIMIT 1
                    ");
                    $stmt->execute([$tokenHash]);
                    $reset = $stmt->fetch();

                    if (!$reset) {
                        $erro = 'O link de recuperação é inválido ou já expirou (validade: 15 minutos). Solicite um novo link.';
                    } else {
                        $novoHash = password_hash($novaSenha, PASSWORD_DEFAULT);

                        $db->prepare('UPDATE administradores SET senha = ? WHERE email = ?')->execute([$novoHash, $reset['email']]);
                        $db->prepare('UPDATE password_resets SET used = 1 WHERE id = ?')->execute([$reset['id']]);

                        Database::log('auth', "Senha de admin redefinida com sucesso: {$reset['email']}", [
                            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido',
                        ]);

                        header('Location: login.php?recuperado=1');
                        exit;
                    }
                }

                $isPasso2 = true;
                $tokenParam = $tokenRecebido ?: $tokenParam;
            }
        } catch (\Throwable $e) {
            error_log("Erro em admin/recuperar-senha.php: " . $e->getMessage());
            Database::log('erro_recuperar_senha_admin', "Exceção em admin/recuperar-senha.php: " . $e->getMessage(), [
                'email' => $_POST['email'] ?? '',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido'
            ]);
            $erro = 'Ocorreu uma falha temporária ao processar sua solicitação: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperação de Senha - <?= htmlspecialchars(getEmpresaNome()) ?></title>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="../images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../images/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="../images/apple-touch-icon.png">
    <link rel="manifest" href="../site.webmanifest">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="bg-dark text-light d-flex align-items-center min-vh-100 py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card-custom shadow-lg">
                    <div class="text-center mb-4">
                        <div class="mb-3">
                            <i class="fa-solid <?= $isPasso2 ? 'fa-key' : 'fa-lock-open' ?> fa-3x text-info"></i>
                        </div>
                        <h4 class="fw-bold text-white"><?= $isPasso2 ? 'Redefinir Senha' : 'Recuperar Acesso' ?></h4>
                        <p class="text-secondary small">Painel Administrativo — <?= htmlspecialchars(getEmpresaNome()) ?></p>
                    </div>

                    <?php if ($erro): ?>
                        <div class="alert alert-danger py-2" role="alert">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= htmlspecialchars($erro) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($sucesso): ?>
                        <div class="alert alert-success py-2" role="alert">
                            <i class="fa-solid fa-circle-check me-1"></i> <?= htmlspecialchars($sucesso) ?>
                        </div>
                        <div class="text-center mt-3">
                            <a href="login.php" class="btn btn-primary-custom w-100">
                                <i class="fa-solid fa-right-to-bracket me-2"></i>Ir para o Login
                            </a>
                        </div>
                    <?php else: ?>

                        <?php if (!$isPasso2): ?>
                            <form method="POST" action="recuperar-senha.php">
                                <?= Security::generateCsrfToken() ?>

                                <div class="mb-3">
                                    <label class="form-label small text-secondary">E-mail Cadastrado</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-dark border-secondary text-secondary">
                                            <i class="fa-solid fa-envelope"></i>
                                        </span>
                                        <input type="email" name="email" class="form-control-custom border-start-0" placeholder="admin@admin.com" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                                    </div>
                                    <small class="text-secondary d-block mt-1">O link de redefinição será enviado para este e-mail e terá validade de 15 minutos.</small>
                                </div>

                                <button type="submit" class="btn btn-primary-custom w-100 py-2">
                                    <i class="fa-solid fa-paper-plane me-2"></i>Enviar Link de Recuperação
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="POST" action="recuperar-senha.php?token=<?= rawurlencode($tokenParam) ?>">
                                <?= Security::generateCsrfToken() ?>
                                <input type="hidden" name="token" value="<?= htmlspecialchars($tokenParam) ?>">

                                <div class="mb-3">
                                    <label class="form-label small text-secondary">Nova Senha</label>
                                    <input type="password" name="nova_senha" class="form-control-custom" placeholder="Mínimo 8 caracteres" minlength="8" required autofocus>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small text-secondary">Confirmar Nova Senha</label>
                                    <input type="password" name="confirmar_senha" class="form-control-custom" placeholder="Repita a nova senha" minlength="8" required>
                                </div>

                                <button type="submit" class="btn btn-primary-custom w-100 py-2">
                                    <i class="fa-solid fa-floppy-disk me-2"></i>Salvar Nova Senha
                                </button>
                            </form>
                        <?php endif; ?>

                        <div class="text-center mt-4">
                            <a href="login.php" class="text-secondary small text-decoration-none">
                                <i class="fa-solid fa-arrow-left me-1"></i>Voltar para o login
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
