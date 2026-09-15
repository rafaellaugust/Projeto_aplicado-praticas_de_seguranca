<?php
/**
 * @file admin/logout.php
 * @brief Script para logout de administrador
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/SessionGuard.php';
require_once __DIR__ . '/../src/Security.php';

SessionGuard::init();

if (!empty($_SESSION['admin_nome'])) {
    Database::log('auth', "Logout efetuado pelo administrador: " . $_SESSION['admin_nome'], [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido'
    ]);
}

SessionGuard::destroySession();
header('Location: login.php');
exit;
