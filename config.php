<?php
/**
 * Configuração Geral do Sistema & Conexão com Banco de Dados
 */

function loadEnv(string $path): void {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!array_key_exists($key, $_ENV)) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

loadEnv(__DIR__ . '/.env');

// Configurações seguras de sessão
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '1800');
session_name('MKPAY_SESSID');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Configurações do Banco de Dados MySQL / MariaDB
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'spaconet_aplicacao');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHAR', getenv('DB_CHAR') ?: 'utf8mb4');

define('TOTP_ENCRYPTION_KEY', getenv('TOTP_ENCRYPTION_KEY') ?: 'mkpay_aes256_secret_key_default_protect_v1');
define('APP_ENV', getenv('APP_ENV') ?: 'production');

// Timezone
date_default_timezone_set('America/Sao_Paulo');

// Autoload de classes do diretório /src
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/src/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// URL Base do Sistema - Permite forçar manualmente aqui caso deseje
if (!defined('CUSTOM_APP_URL')) {
    define('CUSTOM_APP_URL', ''); // Opcional: preencha caso queira forçar via arquivo (ex: 'https://mk.spaconett.com')
}

/**
 * Retorna a URL Base do Sistema de forma flexível:
 * 1. Se CUSTOM_APP_URL estiver preenchido no config.php, usa ele;
 * 2. Se houver 'app_url' configurado no Banco de Dados (admin/configuracoes.php), usa ele;
 * 3. Se for acesso web via navegador, detecta automaticamente protocolo (HTTP/HTTPS) e host atual;
 * 4. Fallback padrão seguro para scripts de linha de comando (CLI / Cron).
 */
function getSystemBaseUrl(): string {
    static $cachedUrl = null;
    if ($cachedUrl !== null) {
        return $cachedUrl;
    }

    // 1. Forçado no config.php
    if (defined('CUSTOM_APP_URL') && !empty(CUSTOM_APP_URL)) {
        $cachedUrl = rtrim(CUSTOM_APP_URL, '/');
        return $cachedUrl;
    }

    // 2. Busca na tabela de configuracoes
    try {
        if (class_exists('Database')) {
            $db = Database::getInstance();
            $dbUrl = $db->query("SELECT app_url FROM configuracoes WHERE id = 1")->fetchColumn();
            if (!empty($dbUrl)) {
                $cachedUrl = rtrim($dbUrl, '/');
                return $cachedUrl;
            }
        }
    } catch (Exception $e) {
        // Segue para detecção automática
    }

    // 3. Detecção automática pelo cabeçalho HTTP da requisição atual
    if (!empty($_SERVER['HTTP_HOST'])) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
        $protocol = $isHttps ? 'https' : 'http';
        $host = preg_replace('#^https?://#', '', $_SERVER['HTTP_HOST']);
        $cachedUrl = $protocol . '://' . rtrim($host, '/');
        return $cachedUrl;
    }

    // 4. Fallback padrão
    $cachedUrl = 'https://aplicacao.spaconett.com';
    return $cachedUrl;
}

if (!defined('BASE_URL')) {
    define('BASE_URL', getSystemBaseUrl());
}

/**
 * Retorna o Nome do Provedor / Razão Social configurado no Banco de Dados.
 * Se não configurado ou banco inacessível, usa fallback padrão.
 */
function getEmpresaNome(): string {
    static $cachedNome = null;
    if ($cachedNome !== null) {
        return $cachedNome;
    }

    try {
        if (class_exists('Database')) {
            $db = Database::getInstance();
            $nome = $db->query("SELECT empresa_nome FROM configuracoes WHERE id = 1")->fetchColumn();
            if (!empty($nome)) {
                $cachedNome = trim((string)$nome);
                return $cachedNome;
            }
        }
    } catch (\Throwable $e) {}

    $cachedNome = 'Spaço Nett';
    return $cachedNome;
}

if (!defined('EMPRESA_NOME')) {
    define('EMPRESA_NOME', getEmpresaNome());
}

/**
 * Função Auxiliar para Sanitização de Input
 */
function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Função Auxiliar para Formatar Moeda (R$)
 */
function formatMoeda($valor) {
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
}

/**
 * Função Auxiliar para Formatar Data Brasileira
 */
function formatData($dataStr) {
    if (!$dataStr) return '-';
    $timestamp = strtotime($dataStr);
    return date('d/m/Y', $timestamp);
}

/**
 * Funções de Sessão e Autenticação
 */
function checkAdminLogin() {
    if (empty($_SESSION['admin_id'])) {
        header('Location: ' . BASE_URL . '/admin/login.php');
        exit;
    }
}

function checkClienteLogin() {
    if (empty($_SESSION['cliente_id'])) {
        header('Location: ' . BASE_URL . '/cliente/login.php');
        exit;
    }
}
