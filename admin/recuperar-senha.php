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
        $db = Database::getInstance();

        if (!$isPasso2) {
            $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);

            if (empty($email)) {
                $erro = 'Informe um endereço de e-mail válido.';
            } else {
                $stmt = $db->prepare('SELECT id, nome, email FROM administradores WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                $admin = $stmt->fetch();

                if ($admin) {
                    $db->prepare("UPDATE password_resets SET used = 1 WHERE email = ? AND user_type = 'admin' AND used = 0")->execute([$admin['email']]);

                    try {
                        $token = bin2hex(random_bytes(32));
                    } catch (Exception $e) {
                        $token = bin2hex(openssl_random_pseudo_bytes(32));
                    }
                    $tokenHash = hash('sha256', $token);

                    $db->prepare("
                        INSERT INTO password_resets (email, user_type, token_hash, expires_at, used, created_at)
                        VALUES (?, 'admin', ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), 0, NOW())
                    ")->execute([$admin['email'], $tokenHash]);

                    $link = BASE_URL . '/admin/recuperar-senha.php?token=' . rawurlencode($token);
                    Security::sendPasswordResetEmail($admin['email'], $admin['nome'], $link, 'admin');

                    Database::log('auth', "Solicitação de recuperação de senha admin: {$admin['email']}", [
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido',
                    ]);
                }
                $sucesso = 'Se o e-mail informado estiver cadastrado, as instruções e o link de recuperação foram enviados com sucesso.';
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
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperação de Senha - MikroTik Pay</title>
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
                        <p class="text-secondary small">Painel Administrativo — Spaço Nett</p>
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
