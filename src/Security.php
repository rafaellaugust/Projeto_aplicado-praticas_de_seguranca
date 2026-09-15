<?php

/**
 * Classe responsável por gerenciar a segurança da aplicação.
 * Implementa proteção CSRF, Rate Limiting, Bloqueio de IP, TOTP e mais.
 *
 * @package MikroTikPay\Security
 */
class Security
{
    /**
     * Gera um token CSRF e o armazena na sessão.
     * Retorna um campo de entrada HTML oculto.
     *
     * @return string O HTML do input hidden
     */
    public static function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            try {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
            }
        }
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Valida o token CSRF recebido via POST.
     * Registra falhas no log.
     *
     * @return bool True se válido, False caso contrário
     */
    public static function validateCsrfToken(): bool
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_POST['csrf_token']) || empty($_SESSION['csrf_token'])) {
                error_log('Falha na validação do token CSRF do IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'Desconhecido') . ' (Token ausente)');
                return false;
            }
            if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
                error_log('Falha na validação do token CSRF do IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'Desconhecido') . ' (Token inválido)');
                return false;
            }
        }
        return true;
    }

    /**
     * Verifica o limite de taxa de requisições.
     *
     * @param string $ip O endereço IP
     * @param string $action A ação sendo executada (ex: login)
     * @param int $maxAttempts Número máximo de tentativas
     * @param int $windowMinutes Janela de tempo em minutos
     * @return bool True se permitido, False se bloqueado
     */
    public static function checkRateLimit(string $ip, string $action = 'login', int $maxAttempts = 5, int $windowMinutes = 15): bool
    {
        if (self::isIpBlocked($ip)) {
            return false;
        }

        try {
            $db = Database::getInstance();
            // Verifica o total de falhas recentes do IP
            $stmt = $db->prepare('
                SELECT COUNT(id) as total 
                FROM login_attempts 
                WHERE ip_address = :ip 
                  AND success = 0 
                  AND created_at >= DATE_SUB(NOW(), INTERVAL :window MINUTE)
            ');
            $stmt->execute([
                ':ip' => $ip,
                ':window' => $windowMinutes
            ]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result && $result['total'] >= $maxAttempts) {
                return false;
            }
            return true;
        } catch (PDOException $e) {
            error_log('Erro ao verificar limite de taxa: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Registra uma tentativa de acesso (ex: login) no banco de dados.
     * Também verifica o bloqueio automático.
     *
     * @param string $ip O endereço IP
     * @param string $action Ação realizada (ex: login)
     * @param bool $success Se a tentativa foi bem-sucedida
     * @param string|null $identifier Email ou nome de usuário
     * @param string|null $userType Tipo de usuário
     */
    public static function recordAttempt(string $ip, string $action, bool $success, ?string $identifier = null, ?string $userType = null): void
    {
        try {
            $db = Database::getInstance();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $geo = self::getGeoLocation($ip);
            $fingerprint = self::getDeviceFingerprint();

            $stmt = $db->prepare('
                INSERT INTO login_attempts 
                (ip_address, email_or_user, user_type, success, user_agent, geo_country, geo_city, device_fingerprint, created_at) 
                VALUES 
                (:ip, :identifier, :userType, :success, :userAgent, :country, :city, :fingerprint, NOW())
            ');
            $stmt->execute([
                ':ip' => $ip,
                ':identifier' => $identifier,
                ':userType' => $userType,
                ':success' => $success ? 1 : 0,
                ':userAgent' => substr($userAgent, 0, 255),
                ':country' => $geo['country'] ?? null,
                ':city' => $geo['city'] ?? null,
                ':fingerprint' => $fingerprint
            ]);

            // Bloqueio automático: 10 falhas em 1 hora -> bloqueia por 24 horas
            if (!$success) {
                $stmt = $db->prepare('
                    SELECT COUNT(id) as total 
                    FROM login_attempts 
                    WHERE ip_address = :ip 
                      AND success = 0 
                      AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                ');
                $stmt->execute([':ip' => $ip]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($result && $result['total'] >= 10) {
                    self::blockIp($ip, 'Bloqueio automático por múltiplas falhas de login.', 24);
                }
            }

        } catch (PDOException $e) {
            error_log('Erro ao registrar tentativa: ' . $e->getMessage());
        }
    }

    /**
     * Verifica se o endereço IP está bloqueado.
     *
     * @param string $ip O endereço IP a verificar
     * @return bool True se bloqueado, False caso contrário
     */
    public static function isIpBlocked(string $ip): bool
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare('
                SELECT id, expires_at 
                FROM blocked_ips 
                WHERE ip_address = :ip 
                  AND (expires_at IS NULL OR expires_at > NOW())
            ');
            $stmt->execute([':ip' => $ip]);
            return (bool) $stmt->fetch();
        } catch (PDOException $e) {
            error_log('Erro ao verificar bloqueio de IP: ' . $e->getMessage());
            // Em caso de erro do banco de dados, falhamos aberto ou fechado? 
            // Para evitar travamento total, falhamos aberto (não bloqueado).
            return false;
        }
    }

    /**
     * Bloqueia um endereço IP.
     *
     * @param string $ip O endereço IP a bloquear
     * @param string $reason O motivo do bloqueio
     * @param int|null $durationHours Duração em horas (null para permanente)
     */
    public static function blockIp(string $ip, string $reason, ?int $durationHours = null): void
    {
        try {
            $db = Database::getInstance();
            // Remove bloqueios anteriores, se houver
            self::unblockIp($ip);

            $query = 'INSERT INTO blocked_ips (ip_address, reason, created_at, expires_at) VALUES (:ip, :reason, NOW(), ';
            if ($durationHours === null) {
                $query .= 'NULL)';
            } else {
                $query .= 'DATE_ADD(NOW(), INTERVAL :hours HOUR))';
            }

            $stmt = $db->prepare($query);
            $params = [
                ':ip' => $ip,
                ':reason' => $reason
            ];
            if ($durationHours !== null) {
                $params[':hours'] = $durationHours;
            }
            
            $stmt->execute($params);
        } catch (PDOException $e) {
            error_log('Erro ao bloquear IP: ' . $e->getMessage());
        }
    }

    /**
     * Desbloqueia um endereço IP.
     *
     * @param string $ip O endereço IP
     */
    public static function unblockIp(string $ip): void
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare('DELETE FROM blocked_ips WHERE ip_address = :ip');
            $stmt->execute([':ip' => $ip]);
        } catch (PDOException $e) {
            error_log('Erro ao desbloquear IP: ' . $e->getMessage());
        }
    }

    /**
     * Decodifica uma string Base32 (RFC 4648).
     */
    private static function base32Decode(string $base32): string
    {
        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32 = strtoupper($base32);
        $base32 = str_replace('=', '', $base32);
        $bits = '';
        $binaryString = '';

        for ($i = 0; $i < strlen($base32); $i++) {
            $val = strpos($base32chars, $base32[$i]);
            if ($val === false) {
                throw new Exception('Caractere Base32 inválido');
            }
            $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
        }

        for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
            $binaryString .= chr(bindec(substr($bits, $i, 8)));
        }

        return $binaryString;
    }

    /**
     * Codifica uma string em Base32 (RFC 4648).
     */
    private static function base32Encode(string $data): string
    {
        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        $base32String = '';

        for ($i = 0; $i < strlen($data); $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        for ($i = 0; $i < strlen($bits); $i += 5) {
            $chunk = substr($bits, $i, 5);
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $base32String .= $base32chars[bindec($chunk)];
        }

        $padLength = 8 - (strlen($base32String) % 8);
        if ($padLength < 8) {
            $base32String .= str_repeat('=', $padLength);
        }

        return $base32String;
    }

    /**
     * Gera um segredo TOTP (160 bits / 20 bytes) codificado em Base32.
     *
     * @return string
     */
    public static function generateTotpSecret(): string
    {
        try {
            $bytes = random_bytes(20);
        } catch (Exception $e) {
            $bytes = openssl_random_pseudo_bytes(20);
        }
        return rtrim(self::base32Encode($bytes), '=');
    }

    /**
     * Calcula o código TOTP para um dado tempo.
     *
     * @param string $secret Segredo Base32
     * @param int|null $time Timestamp (nulo para tempo atual)
     * @return string O código de 6 dígitos
     */
    public static function getTotpCode(string $secret, ?int $time = null): string
    {
        if ($time === null) {
            $time = time();
        }

        $timeInterval = floor($time / 30);
        
        // Converte o intervalo de tempo para uma string binária de 8 bytes, big-endian
        $timeBin = pack('J', $timeInterval); // 64-bit unsigned, big endian (requer PHP 5.6.3+)
        
        try {
            $secretKey = self::base32Decode($secret);
        } catch (Exception $e) {
            return '000000'; // Falha de decodificação
        }

        $hash = hash_hmac('sha1', $timeBin, $secretKey, true);
        
        // Pega os 4 bits menos significativos do último byte
        $offset = ord(substr($hash, -1)) & 0x0F;
        
        // Pega 4 bytes a partir do offset e ignora o bit de sinal
        $value = unpack('N', substr($hash, $offset, 4));
        $value = $value[1] & 0x7FFFFFFF;
        
        // Gera o código de 6 dígitos
        $code = (string) ($value % 1000000);
        
        return str_pad($code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verifica um código TOTP.
     *
     * @param string $secret Segredo Base32
     * @param string $code Código a verificar
     * @param int $window Janela de tolerância em número de intervalos de 30s
     * @return bool
     */
    public static function verifyTotpCode(string $secret, string $code, int $window = 1): bool
    {
        $currentTime = time();
        
        for ($i = -$window; $i <= $window; $i++) {
            $calculatedCode = self::getTotpCode($secret, $currentTime + ($i * 30));
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Retorna a URL do Google Charts para gerar o QR Code do TOTP.
     *
     * @param string $email O e-mail/identificador do usuário
     * @param string $secret O segredo TOTP
     * @param string $issuer O nome do emissor (ex: MikroTik Pay)
     * @return string
     */
    public static function getTotpQrCodeUrl(string $email, string $secret, string $issuer = 'MikroTik Pay'): string
    {
        $issuerEncoded = rawurlencode($issuer);
        $emailEncoded = rawurlencode($email);
        $otpauth = "otpauth://totp/{$issuerEncoded}:{$emailEncoded}?secret={$secret}&issuer={$issuerEncoded}";
        
        return 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=' . urlencode($otpauth);
    }

    /**
     * Gera códigos de backup de uso único.
     *
     * @param int $count Quantidade de códigos
     * @return array Array de códigos de backup (ex: 8 dígitos em formato amigável)
     */
    public static function generateBackupCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            try {
                $hex = bin2hex(random_bytes(4));
            } catch (Exception $e) {
                $hex = bin2hex(openssl_random_pseudo_bytes(4));
            }
            $codes[] = substr($hex, 0, 4) . '-' . substr($hex, 4, 4);
        }
        return $codes;
    }

    /**
     * Criptografa o segredo TOTP antes de salvar no banco.
     *
     * @param string $secret Segredo em texto puro
     * @return string Segredo criptografado em Base64
     */
    public static function encryptSecret(string $secret): string
    {
        if (!defined('TOTP_ENCRYPTION_KEY')) {
            throw new Exception('Chave de criptografia TOTP não configurada.');
        }
        
        $key = hash('sha256', TOTP_ENCRYPTION_KEY, true);
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        try {
            $iv = random_bytes($ivLength);
        } catch (Exception $e) {
            $iv = openssl_random_pseudo_bytes($ivLength);
        }
        
        $encrypted = openssl_encrypt($secret, 'aes-256-cbc', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Descriptografa o segredo TOTP do banco.
     *
     * @param string $encrypted Segredo criptografado em Base64
     * @return string Segredo original
     */
    public static function decryptSecret(string $encrypted): string
    {
        if (!defined('TOTP_ENCRYPTION_KEY')) {
            throw new Exception('Chave de criptografia TOTP não configurada.');
        }
        
        $data = base64_decode($encrypted);
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $ivLength);
        $ciphertext = substr($data, $ivLength);
        
        $key = hash('sha256', TOTP_ENCRYPTION_KEY, true);
        return openssl_decrypt($ciphertext, 'aes-256-cbc', $key, 0, $iv);
    }

    /**
     * Obtém o fingerprint (assinatura) do dispositivo.
     *
     * @return string Hash SHA-256
     */
    public static function getDeviceFingerprint(): string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'Unknown';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        
        // Pega o IP parcial para reduzir impacto de mudanças em DHCP
        $partialIp = substr($ip, 0, strrpos($ip, '.') ?: strlen($ip));
        
        return hash('sha256', $userAgent . $acceptLanguage . $partialIp);
    }

    /**
     * Tenta identificar o nome do dispositivo a partir do User-Agent.
     *
     * @return string Nome do dispositivo
     */
    public static function getDeviceName(): string
    {
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $os = 'Desconhecido';
        if (preg_match('/windows nt 10/i', $agent)) $os = 'Windows 10/11';
        elseif (preg_match('/windows nt 6\.3/i', $agent)) $os = 'Windows 8.1';
        elseif (preg_match('/windows nt 6\.2/i', $agent)) $os = 'Windows 8';
        elseif (preg_match('/windows nt 6\.1/i', $agent)) $os = 'Windows 7';
        elseif (preg_match('/macintosh|mac os x/i', $agent)) $os = 'Mac OS X';
        elseif (preg_match('/linux/i', $agent)) $os = 'Linux';
        elseif (preg_match('/iphone/i', $agent)) $os = 'iPhone';
        elseif (preg_match('/ipad/i', $agent)) $os = 'iPad';
        elseif (preg_match('/android/i', $agent)) $os = 'Android';

        $browser = 'Desconhecido';
        if (preg_match('/edg/i', $agent)) $browser = 'Edge';
        elseif (preg_match('/chrome/i', $agent)) $browser = 'Chrome';
        elseif (preg_match('/firefox/i', $agent)) $browser = 'Firefox';
        elseif (preg_match('/safari/i', $agent)) $browser = 'Safari';
        elseif (preg_match('/opera|opr/i', $agent)) $browser = 'Opera';

        return "{$os} - {$browser}";
    }

    /**
     * Obtém a geolocalização com base no IP.
     *
     * @param string $ip O endereço IP
     * @return array
     */
    public static function getGeoLocation(string $ip): array
    {
        // IPs privados não têm geolocalização externa
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return [];
        }

        $sessionKey = 'geo_' . md5($ip);
        if (isset($_SESSION[$sessionKey])) {
            return $_SESSION[$sessionKey];
        }

        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 2 // timeout curto para não travar o login
                ]
            ]);
            $response = @file_get_contents("http://ip-api.com/json/{$ip}?fields=country,city,status", false, $context);
            if ($response) {
                $data = json_decode($response, true);
                if (isset($data['status']) && $data['status'] === 'success') {
                    $result = [
                        'country' => $data['country'] ?? null,
                        'city' => $data['city'] ?? null
                    ];
                    $_SESSION[$sessionKey] = $result;
                    return $result;
                }
            }
        } catch (Exception $e) {
            error_log('Erro ao buscar geolocalização: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Envia todos os cabeçalhos de segurança (Security Headers).
     */
    public static function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://chart.googleapis.com; font-src 'self';");
        header("X-Frame-Options: SAMEORIGIN");
        header("X-Content-Type-Options: nosniff");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
        
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
        }
    }
}
