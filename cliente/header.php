<?php
require_once __DIR__ . '/../config.php';
checkClienteLogin();

// Proteções de sessão
if (!SessionGuard::validateSession() || !SessionGuard::checkTimeout(30)) {
    SessionGuard::destroySession();
    header('Location: login.php?expired=1');
    exit;
}

// Headers de segurança HTTP
Security::sendSecurityHeaders();

$db = Database::getInstance();
$stmtC = $db->prepare("
    SELECT c.*, p.nome as plano_nome, p.valor as plano_valor, p.velocidade_down, p.velocidade_up 
    FROM clientes c 
    LEFT JOIN planos p ON c.plano_id = p.id 
    WHERE c.id = ?
");
$stmtC->execute([$_SESSION['cliente_id']]);
$cliente = $stmtC->fetch();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal do Assinante - <?= sanitize($cliente['nome']) ?></title>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="../images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../images/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="../images/apple-touch-icon.png">
    <link rel="manifest" href="../site.webmanifest">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-custom sticky-top">
    <div class="container">
        <a class="navbar-brand brand-title d-flex align-items-center gap-2" href="index.php">
            <i class="fa-solid fa-wifi text-info"></i> Portal do Assinante
        </a>
        <div class="d-flex align-items-center gap-3 ms-auto">
            <span class="text-light small d-none d-md-inline">
                Olá, <strong><?= sanitize($cliente['nome']) ?></strong>
            </span>
            <a href="logout.php" class="btn btn-outline-danger btn-sm">
                <i class="fa-solid fa-right-from-bracket me-1"></i> Sair
            </a>
        </div>
    </div>
</nav>

<div class="container py-4">
