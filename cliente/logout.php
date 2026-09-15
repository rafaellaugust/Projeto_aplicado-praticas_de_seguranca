<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/SessionGuard.php';

SessionGuard::init();

if (!empty($_SESSION['cliente_nome'])) {
    Database::log('auth_cliente', "Cliente deslogou do portal: " . $_SESSION['cliente_nome'], [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido'
    ]);
}
SessionGuard::destroySession();
header('Location: login.php');
exit;
