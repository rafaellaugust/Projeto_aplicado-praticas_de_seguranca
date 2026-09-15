<?php

/**
 * Classe responsável por gerenciar a sessão dos usuários, protegendo
 * contra sequestro de sessão e monitorando inatividade.
 *
 * @package MikroTikPay\Security
 */
class SessionGuard
{
    /**
     * Inicializa a sessão com parâmetros seguros.
     */
    public static function init(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => $_SERVER['HTTP_HOST'] ?? '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
            session_start();
        }

        // Regenera o ID da sessão se for recém-criada
        if (!isset($_SESSION['session_initialized'])) {
            session_regenerate_id(true);
            $_SESSION['session_initialized'] = true;
        }
    }

    /**
     * Valida a integridade da sessão (anti-sequestro).
     *
     * @return bool True se válida, False se inválida
     */
    public static function validateSession(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $currentIp = $_SERVER['REMOTE_ADDR'] ?? 'Desconhecido';
        $currentAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Desconhecido';

        if (!isset($_SESSION['client_ip'])) {
            $_SESSION['client_ip'] = $currentIp;
            $_SESSION['client_agent'] = $currentAgent;
        } else {
            // Verifica se IP mudou (com cautela em redes móveis) ou User-Agent mudou
            if ($_SESSION['client_agent'] !== $currentAgent) {
                error_log("Possível sequestro de sessão detectado para IP: {$currentIp}");
                self::destroySession();
                return false;
            }
        }

        return true;
    }

    /**
     * Verifica o timeout por inatividade.
     *
     * @param int $maxIdleMinutes Minutos máximos de inatividade
     * @return bool True se ativa, False se expirou
     */
    public static function checkTimeout(int $maxIdleMinutes = 30): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $now = time();
        if (isset($_SESSION['last_activity'])) {
            $idleTime = $now - $_SESSION['last_activity'];
            if ($idleTime > ($maxIdleMinutes * 60)) {
                self::destroySession();
                return false;
            }
        }
        
        $_SESSION['last_activity'] = $now;
        return true;
    }

    /**
     * Armazena a impressão digital (fingerprint) do dispositivo na sessão.
     */
    public static function bindDevice(): void
    {
        if (class_exists('Security')) {
            $_SESSION['bound_device'] = Security::getDeviceFingerprint();
        }
    }

    /**
     * Verifica se o dispositivo atual bate com o da sessão.
     *
     * @return bool
     */
    public static function isDeviceBound(): bool
    {
        if (!isset($_SESSION['bound_device'])) {
            return false; // Não há dispositivo vinculado
        }

        if (class_exists('Security')) {
            return hash_equals($_SESSION['bound_device'], Security::getDeviceFingerprint());
        }

        return false;
    }

    /**
     * Registra a sessão atual como ativa no banco de dados.
     *
     * @param int $userId ID do usuário
     * @param string $userType Tipo de usuário (ex: admin, client)
     */
    public static function registerActiveSession(int $userId, string $userType): void
    {
        try {
            $db = Database::getInstance();
            $sessionId = session_id();
            
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = class_exists('Security') ? Security::getDeviceName() : ($_SERVER['HTTP_USER_AGENT'] ?? '');
            
            $stmt = $db->prepare('
                INSERT INTO active_sessions (session_id, user_id, user_type, ip_address, user_agent, last_activity)
                VALUES (:sessionId, :userId, :userType, :ip, :userAgent, NOW())
                ON DUPLICATE KEY UPDATE last_activity = NOW()
            ');
            
            $stmt->execute([
                ':sessionId' => $sessionId,
                ':userId' => $userId,
                ':userType' => $userType,
                ':ip' => $ip,
                ':userAgent' => substr($userAgent, 0, 255)
            ]);
            
        } catch (PDOException $e) {
            error_log('Erro ao registrar sessão ativa: ' . $e->getMessage());
        }
    }

    /**
     * Destrói a sessão atual e a remove das sessões ativas no banco de dados.
     */
    public static function destroySession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionId = session_id();
            
            try {
                $db = Database::getInstance();
                $stmt = $db->prepare('DELETE FROM active_sessions WHERE session_id = :sessionId');
                $stmt->execute([':sessionId' => $sessionId]);
            } catch (PDOException $e) {
                error_log('Erro ao remover sessão ativa: ' . $e->getMessage());
            }

            $_SESSION = [];

            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params['path'], $params['domain'],
                    $params['secure'], $params['httponly']
                );
            }

            session_destroy();
        }
    }

    /**
     * Força o logout de um usuário, removendo todas as suas sessões ativas.
     *
     * @param int $userId ID do usuário
     * @param string $userType Tipo de usuário
     */
    public static function forceLogoutUser(int $userId, string $userType): void
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare('DELETE FROM active_sessions WHERE user_id = :userId AND userType = :userType');
            $stmt->execute([
                ':userId' => $userId,
                ':userType' => $userType
            ]);
        } catch (PDOException $e) {
            error_log('Erro ao forçar logout do usuário: ' . $e->getMessage());
        }
    }
}
