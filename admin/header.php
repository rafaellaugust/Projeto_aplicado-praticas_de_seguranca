<?php
require_once __DIR__ . '/../config.php';
checkAdminLogin();

// Proteções de sessão
if (!SessionGuard::validateSession() || !SessionGuard::checkTimeout(30)) {
    SessionGuard::destroySession();
    header('Location: login.php?expired=1');
    exit;
}

// Headers de segurança HTTP
Security::sendSecurityHeaders();

$dbConfig = Database::getInstance();
$waConfig = $dbConfig->query("SELECT wa_api_url FROM configuracoes WHERE id = 1")->fetch();
$waConfigured = !empty($waConfig['wa_api_url']);

$mikrotikApi = new MikrotikAPI();
$mikrotikOnline = $mikrotikApi->testConnection();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Administrativo - <?= htmlspecialchars(getEmpresaNome()) ?></title>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="../images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="../images/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="../images/apple-touch-icon.png">
    <link rel="manifest" href="../site.webmanifest">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .navbar-custom .nav-link {
            white-space: nowrap;
            font-size: 0.9rem;
            padding: 2px 0px !important;
            border-radius: 6px;
            transition: background 0.2s;
        }
        .navbar-custom .nav-link:hover { background: rgba(255,255,255,0.08); }
        .navbar-custom .nav-link i { width: 18px; text-align: center; }
        .status-stack .badge {
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 6px;
            font-weight: 600;
        }
        .badge-ativo { background: rgba(34,197,94,0.15); color: #22c55e; border: 1px solid rgba(34,197,94,0.3); }
        .badge-atrasado { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }
        .badge-pendente { background: rgba(234,179,8,0.15); color: #eab308; border: 1px solid rgba(234,179,8,0.3); }

        /* BOTÃO ICON ADM - SÓ ICONE */
        .admin-icon-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.15);
            color: #fff;
            transition: all 0.2s;
        }
        .admin-icon-btn:hover {
            background: rgba(255,255,255,0.15);
            border-color: rgba(255,255,255,0.25);
            color: #fff;
        }
        .admin-icon-btn i { font-size: 1rem; }
        .navbar-custom .dropdown-menu {
            position: absolute !important;
            right: 0 !important;
            left: auto !important;
            top: 100% !important;
            margin-top: 10px !important;
            min-width: 200px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.6);
            border: 1px solid rgba(255,255,255,0.1);
            z-index: 1050;
        }
        .navbar-custom { overflow: visible !important; }
        .container-fluid { overflow: visible !important; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-xl navbar-custom sticky-top py-2">
    <div class="container-fluid px-3">
        <a class="navbar-brand brand-title d-flex align-items-center gap-2 me-3" href="index.php">
            <i class="fa-solid fa-network-wired text-info"></i> <?= htmlspecialchars(getEmpresaNome()) ?>
        </a>
        <button class="navbar-toggler text-white border-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar">
            <i class="fa-solid fa-bars"></i>
        </button>
        <div class="collapse navbar-collapse" id="adminNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-xl-0 gap-1">
                <li class="nav-item"><a class="nav-link text-light fw-medium" href="index.php"><i class="fa-solid fa-chart-line me-1 text-info"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link text-light fw-medium" href="caixa.php"><i class="fa-solid fa-cash-register me-1 text-success"></i> Caixa</a></li>
                <li class="nav-item"><a class="nav-link text-light fw-medium" href="historico.php"><i class="fa-solid fa-calendar-days me-1 text-warning"></i> Histórico</a></li>
                <li class="nav-item"><a class="nav-link text-light fw-medium" href="clientes.php"><i class="fa-solid fa-users me-1 text-info"></i> Clientes</a></li>
                <li class="nav-item"><a class="nav-link text-light fw-medium" href="planos.php"><i class="fa-solid fa-cubes me-1 text-warning"></i> Planos</a></li>
                <li class="nav-item"><a class="nav-link text-light fw-medium" href="faturas.php"><i class="fa-solid fa-file-invoice-dollar me-1 text-success"></i> Faturas</a></li>
                <li class="nav-item"><a class="nav-link text-light fw-medium" href="importar.php"><i class="fa-solid fa-cloud-arrow-down me-1 text-info"></i> Importar</a></li>
                <li class="nav-item"><a class="nav-link text-light fw-medium" href="roteadores.php"><i class="fa-solid fa-server me-1 text-warning"></i> MK</a></li>
                <li class="nav-item"><a class="nav-link text-light fw-medium" href="disparos_whatsapp.php"><i class="fa-brands fa-whatsapp me-1 text-success"></i> Disparos WA</a></li>
                <li class="nav-item"><a class="nav-link text-light fw-medium" href="configuracoes.php"><i class="fa-solid fa-sliders me-1 text-info"></i> Config</a></li>
                <li class="nav-item"><a class="nav-link text-light fw-medium" href="logs.php"><i class="fa-solid fa-clipboard-list me-1 text-warning"></i> Logs</a></li>
                <li class="nav-item"><a class="nav-link text-light fw-medium" href="seguranca.php"><i class="fa-solid fa-shield-halved me-1 text-danger"></i> Segurança</a></li>
             </ul>

            <div class="d-flex align-items-center gap-3 ms-xl-3">
                <div class="status-stack d-flex flex-column gap-1 align-items-end">
                    <span class="badge <?= $mikrotikOnline ? 'badge-ativo' : 'badge-atrasado' ?>">
                        <i class="fa-solid fa-server me-1"></i> MK: <?= $mikrotikOnline ? 'Online' : 'Offline' ?>
                    </span>
                    <span class="badge <?= $waConfigured ? 'badge-ativo' : 'badge-pendente' ?>">
                        <i class="fa-brands fa-whatsapp me-1"></i> WA: <?= $waConfigured ? 'Ativo' : 'Não Configurado' ?>
                    </span>
                </div>

                <!-- SÓ BOTÃO COM ICONE ADM -->
                <div class="dropdown admin-dropdown">
                    <button class="admin-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?= sanitize($_SESSION['admin_nome'] ?? 'Administrador') ?>">
                        <i class="fa-solid fa-user-gear"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li><h6 class="dropdown-header text-white"><i class="fa-solid fa-user me-2 text-info"></i><?= sanitize($_SESSION['admin_nome'] ?? 'Administrador') ?></h6><small class="text-secondary px-3 d-block" style="margin-top:-8px; font-size:0.7rem;">Administrador do sistema</small></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="minha-conta.php"><i class="fa-solid fa-user-pen me-2 text-info"></i> Minha Conta</a></li>
                        <li><a class="dropdown-item" href="configuracoes.php"><i class="fa-solid fa-gear me-2"></i> Ajustes</a></li>
                        <li><a class="dropdown-item" href="roteadores.php"><i class="fa-solid fa-server me-2"></i> Roteadores</a></li>
                        <li><a class="dropdown-item" href="backup.php"><i class="fa-solid fa-database me-2 text-warning"></i> Backup do Banco (SQL)</a></li>
                        <li><a class="dropdown-item" href="logs.php"><i class="fa-solid fa-clipboard-list me-2 text-info"></i> Auditoria &amp; Logs</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i> Sair</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid py-4 px-4">
