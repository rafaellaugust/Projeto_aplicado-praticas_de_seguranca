<?php
/**
 * Redirecionador Inteligente da Raiz
 * 
 * Se o cliente já estiver autenticado, vai direto para a central do cliente.
 * Se o administrador estiver autenticado, vai para o painel admin.
 * Caso contrário, vai para o portal de login do assinante.
 */
require_once __DIR__ . '/config.php';

if (!empty($_SESSION['cliente_id'])) {
    header('Location: ' . BASE_URL . '/cliente/index.php');
    exit;
}

if (!empty($_SESSION['admin_id'])) {
    header('Location: ' . BASE_URL . '/admin/index.php');
    exit;
}

header('Location: ' . BASE_URL . '/cliente/login.php');
exit;
