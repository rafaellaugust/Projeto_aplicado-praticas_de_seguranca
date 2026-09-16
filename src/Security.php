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
    /**
     * Retorna apenas a string do token CSRF da sessão.
     */
    public static function getCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            try {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
            }
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Gera um token CSRF e o armazena na sessão.
     * Retorna um campo de entrada HTML oculto.
     *
     * @return string O HTML do input hidden
     */
    public static function generateCsrfToken(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::getCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Valida o token CSRF recebido via POST ou parâmetro.
     * Registra falhas no log.
     *
     * @param string|null $token Token opcional (se nulo, busca em $_POST['csrf_token'])
     * @return bool True se válido, False caso contrário
     */
    public static function validateCsrfToken(?string $token = null): bool
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $received = $token ?? ($_POST['csrf_token'] ?? '');
            if (empty($received) || empty($_SESSION['csrf_token'])) {
                error_log('Falha na validação do token CSRF do IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'Desconhecido') . ' (Token ausente)');
                return false;
            }
            if (!hash_equals($_SESSION['csrf_token'], $received)) {
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
    public static function recordAttempt(string $ip, string $action = 'login', bool $success = false, ?string $identifier = null, ?string $userType = null): void
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
     * Retorna a URI otpauth:// padrão RFC 6238 para aplicativos TOTP.
     */
    public static function getTotpAuthUrl(string $email, string $secret, string $issuer = 'MikroTik Pay'): string
    {
        $issuerEncoded = rawurlencode($issuer);
        $emailEncoded = rawurlencode($email);
        return "otpauth://totp/{$issuerEncoded}:{$emailEncoded}?secret={$secret}&issuer={$issuerEncoded}";
    }

    /**
     * Retorna a URL para gerar o QR Code do TOTP.
     *
     * @param string $email O e-mail/identificador do usuário
     * @param string $secret O segredo TOTP
     * @param string $issuer O nome do emissor (ex: MikroTik Pay)
     * @return string
     */
    public static function getTotpQrCodeUrl(string $email, string $secret, string $issuer = 'MikroTik Pay'): string
    {
        $otpauth = self::getTotpAuthUrl($email, $secret, $issuer);
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=10&data=' . urlencode($otpauth);
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
        
        header("Content-Security-Policy: default-src 'self' data: https:; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com data:; frame-src 'self' https://www.google.com/recaptcha/ https://recaptcha.google.com/; img-src 'self' data: https: blob:;");
        header("X-Frame-Options: SAMEORIGIN");
        header("X-Content-Type-Options: nosniff");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
        
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
        }
    }

    /**
     * Renderiza o widget do Google reCAPTCHA v2 caso RECAPTCHA_SITE_KEY esteja configurado.
     */
    public static function renderRecaptchaWidget(): string
    {
        $siteKey = getenv('RECAPTCHA_SITE_KEY') ?: '';
        // Tenta buscar do banco de dados
        if (empty($siteKey)) {
            try {
                $db = Database::getInstance();
                $siteKey = $db->query("SELECT recaptcha_site_key FROM configuracoes WHERE id = 1")->fetchColumn() ?: '';
            } catch (\Exception $e) {}
        }
        if (empty($siteKey)) {
            return ''; // Se não configurado, não quebra a interface
        }
        return '
        <div class="mb-3 d-flex justify-content-center">
            <script src="https://www.google.com/recaptcha/api.js" async defer></script>
            <div class="g-recaptcha" data-sitekey="' . htmlspecialchars($siteKey, ENT_QUOTES, 'UTF-8') . '"></div>
        </div>';
    }

    /**
     * Valida a resposta do Google reCAPTCHA.
     * Retorna true se for válido ou se reCAPTCHA não estiver ativado.
     */
    public static function verifyRecaptcha(?string $recaptchaResponse, ?string $remoteIp = null): bool
    {
        $secretKey = getenv('RECAPTCHA_SECRET_KEY') ?: '';
        // Tenta buscar do banco de dados
        if (empty($secretKey)) {
            try {
                $db = Database::getInstance();
                $secretKey = $db->query("SELECT recaptcha_secret_key FROM configuracoes WHERE id = 1")->fetchColumn() ?: '';
            } catch (\Exception $e) {}
        }
        if (empty($secretKey)) {
            return true; // reCAPTCHA opcional se não configurado
        }

        if (empty($recaptchaResponse)) {
            return false;
        }

        $ip = $remoteIp ?: ($_SERVER['REMOTE_ADDR'] ?? '');
        $url = 'https://www.google.com/recaptcha/api/siteverify';
        $postData = http_build_query([
            'secret' => $secretKey,
            'response' => $recaptchaResponse,
            'remoteip' => $ip
        ]);

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n" .
                            "Content-Length: " . strlen($postData) . "\r\n",
                'content' => $postData,
                'timeout' => 5
            ]
        ];

        try {
            $context = stream_context_create($opts);
            $result = @file_get_contents($url, false, $context);
            if ($result) {
                $json = json_decode($result, true);
                return !empty($json['success']);
            }
        } catch (Exception $e) {
            error_log('Erro ao validar reCAPTCHA: ' . $e->getMessage());
        }

        return false;
    }


    /**
     * Gera um código de verificação em duas etapas por e-mail (6 dígitos).
     * Armazena na sessão com expiração de 10 minutos.
     */
    public static function generateEmailOtp(int $userId, string $userType = 'admin'): string
    {
        $code = sprintf('%06d', random_int(100000, 999999));
        $_SESSION['email_2fa_code_hash'] = hash('sha256', $code);
        $_SESSION['email_2fa_expires'] = time() + 600; // 10 minutos
        $_SESSION['email_2fa_user_id'] = $userId;
        $_SESSION['email_2fa_user_type'] = $userType;
        return $code;
    }

    /**
     * Envia o código 2FA para o e-mail do usuário.
     */
    public static function sendEmailOtp(string $toEmail, string $code, string $nomeUsuario = 'Usuário'): bool
    {
        $assunto = "Código de Segurança (2FA) - MikroTik Pay";
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=utf-8',
            'From: ' . (getenv('MAIL_FROM') ?: 'no-reply@spaconett.com'),
            'Reply-To: ' . (getenv('MAIL_FROM') ?: 'no-reply@spaconett.com'),
            'X-Mailer: PHP/' . phpversion()
        ];

        $corpo = '
        <!DOCTYPE html>
        <html>
        <head><meta charset="utf-8"></head>
        <body style="font-family: Arial, sans-serif; background:#0f172a; color:#f8fafc; padding:20px;">
            <div style="max-width:500px; margin:0 auto; background:#1e293b; border-radius:12px; padding:30px; border:1px solid #334155;">
                <h2 style="color:#0ea5e9; text-align:center; margin-top:0;">MikroTik Pay</h2>
                <p>Olá, <strong>' . htmlspecialchars($nomeUsuario, ENT_QUOTES, 'UTF-8') . '</strong>,</p>
                <p>Recebemos uma solicitação de acesso à sua conta. Utilize o código de verificação abaixo:</p>
                <div style="text-align:center; margin:25px 0;">
                    <span style="font-size:32px; font-weight:bold; letter-spacing:6px; color:#38bdf8; background:#0f172a; padding:10px 20px; border-radius:8px; border:1px dashed #0284c7;">' . $code . '</span>
                </div>
                <p style="font-size:13px; color:#94a3b8; text-align:center;">Este código é válido por <strong>10 minutos</strong>. Se não foi você quem solicitou, recomendamos alterar sua senha imediatamente.</p>
            </div>
        </body>
        </html>';

        Database::log('auth_email_2fa', "Código de 2FA por e-mail gerado para: {$toEmail}", [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'desconhecido'
        ]);

        return @mail($toEmail, $assunto, $corpo, implode("\r\n", $headers));
    }

    /**
     * Valida o código 2FA recebido por e-mail.
     */
    public static function verifyEmailOtp(string $code): bool
    {
        if (empty($_SESSION['email_2fa_code_hash']) || empty($_SESSION['email_2fa_expires'])) {
            return false;
        }

        if (time() > $_SESSION['email_2fa_expires']) {
            unset($_SESSION['email_2fa_code_hash'], $_SESSION['email_2fa_expires']);
            return false;
        }

        $inputHash = hash('sha256', trim($code));
        if (hash_equals($_SESSION['email_2fa_code_hash'], $inputHash)) {
            unset($_SESSION['email_2fa_code_hash'], $_SESSION['email_2fa_expires']);
            return true;
        }

        return false;
    }

    /**
     * Envia e-mail via SMTP autenticado usando configurações do banco ou .env.
     * Usa stream_socket_client() nativamente (sem PHPMailer) com suporte a STARTTLS.
     * Se SMTP não configurado, usa mail() nativo como fallback.
     *
     * @param string $toEmail Destinatário
     * @param string $subject Assunto
     * @param string $htmlBody Corpo HTML
     * @param string|null $fromEmail Remetente (null = usa configuração)
     * @return bool
     */
    public static function sendEmailSmtp(string $toEmail, string $subject, string $htmlBody, ?string $fromEmail = null): bool
    {
        // Carregar configurações do banco de dados (fallback para .env)
        $smtpHost    = '';
        $smtpPort    = 587;
        $smtpUser    = '';
        $smtpPass    = '';
        $mailFrom    = $fromEmail ?? (getenv('MAIL_FROM') ?: 'no-reply@spaconett.com');

        try {
            $db = Database::getInstance();
            $cfg = $db->query("SELECT smtp_host, smtp_port, smtp_user, smtp_pass, smtp_from FROM configuracoes WHERE id = 1")->fetch();
            if ($cfg) {
                $smtpHost = $cfg['smtp_host'] ?? '';
                $smtpPort = (int)($cfg['smtp_port'] ?? 587);
                $smtpUser = $cfg['smtp_user'] ?? '';
                $smtpPass = $cfg['smtp_pass'] ?? '';
                if (!empty($cfg['smtp_from'])) $mailFrom = $cfg['smtp_from'];
            }
        } catch (\Exception $e) {}

        // Fallback para .env se banco não tiver configuração
        if (empty($smtpHost)) {
            $smtpHost = getenv('SMTP_HOST') ?: '';
            $smtpPort = (int)(getenv('SMTP_PORT') ?: 587);
            $smtpUser = getenv('SMTP_USER') ?: '';
            $smtpPass = getenv('SMTP_PASS') ?: '';
        }

        // Se não há SMTP configurado, usa mail() nativo
        if (empty($smtpHost)) {
            $headers = implode("\r\n", [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=utf-8',
                'From: ' . $mailFrom,
                'Reply-To: ' . $mailFrom,
                'X-Mailer: PHP/' . phpversion()
            ]);
            return @mail($toEmail, $subject, $htmlBody, $headers);
        }

        // SMTP com stream_socket_client (suporta STARTTLS na porta 587/25)
        try {
            $useTls  = ($smtpPort === 465);
            $prefix  = $useTls ? 'ssl://' : '';
            $timeout = 10;
            $errno   = 0;
            $errstr  = '';

            $sock = @stream_socket_client("{$prefix}{$smtpHost}:{$smtpPort}", $errno, $errstr, $timeout);
            if (!$sock) {
                error_log("SMTP connect failed: {$errstr} ({$errno})");
                return false;
            }

            $read = fgets($sock, 512);
            if (strpos($read, '220') === false) { fclose($sock); return false; }

            $send = function(string $cmd) use ($sock): string {
                fwrite($sock, $cmd . "\r\n");
                return fgets($sock, 512);
            };

            $r = $send("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));

            // STARTTLS para porta 587
            if (!$useTls && $smtpPort === 587) {
                $r = $send("STARTTLS");
                if (strpos($r, '220') !== false) {
                    stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                    $r = $send("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
                }
            }

            // Login
            if (!empty($smtpUser)) {
                $r = $send("AUTH LOGIN");
                if (strpos($r, '334') === false) { fclose($sock); return false; }
                $r = $send(base64_encode($smtpUser));
                if (strpos($r, '334') === false) { fclose($sock); return false; }
                $r = $send(base64_encode($smtpPass));
                if (strpos($r, '235') === false) { fclose($sock); return false; }
            }

            $send("MAIL FROM:<{$mailFrom}>");
            $send("RCPT TO:<{$toEmail}>");
            $send("DATA");

            $msgBody = "From: {$mailFrom}\r\n";
            $msgBody .= "To: {$toEmail}\r\n";
            $msgBody .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $msgBody .= "MIME-Version: 1.0\r\n";
            $msgBody .= "Content-Type: text/html; charset=UTF-8\r\n";
            $msgBody .= "X-Mailer: MikroTikPay/1.0\r\n";
            $msgBody .= "\r\n";
            $msgBody .= $htmlBody;
            $msgBody .= "\r\n.";

            $r = $send($msgBody);
            $send("QUIT");
            fclose($sock);

            return strpos($r, '250') !== false;
        } catch (\Exception $e) {
            error_log("SMTP error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Envia e-mail de recuperação de senha com link de reset.
     *
     * @param string $toEmail E-mail do destinatário
     * @param string $nomeUsuario Nome para exibição
     * @param string $resetLink URL completa do link de reset
     * @param string $userType 'admin' ou 'cliente'
     * @return bool
     */
    public static function sendPasswordResetEmail(string $toEmail, string $nomeUsuario, string $resetLink, string $userType = 'admin'): bool
    {
        $assunto = "Recuperação de Senha — MikroTik Pay";
        $tipoLabel = $userType === 'admin' ? 'Administrador' : 'Portal do Assinante';

        $corpo = '<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; background:#0f172a; color:#f8fafc; padding:20px; margin:0;">
    <div style="max-width:500px; margin:0 auto; background:#1e293b; border-radius:12px; padding:30px; border:1px solid #334155;">
        <div style="text-align:center; margin-bottom:24px;">
            <h2 style="color:#0ea5e9; margin:0;">🔒 MikroTik Pay</h2>
            <p style="color:#94a3b8; font-size:13px; margin-top:4px;">' . htmlspecialchars($tipoLabel, ENT_QUOTES, 'UTF-8') . '</p>
        </div>
        <p>Olá, <strong>' . htmlspecialchars($nomeUsuario, ENT_QUOTES, 'UTF-8') . '</strong>,</p>
        <p>Recebemos uma solicitação de <strong>recuperação de senha</strong> para sua conta. Clique no botão abaixo para criar uma nova senha:</p>
        <div style="text-align:center; margin:28px 0;">
            <a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '" style="background:#0ea5e9; color:#fff; padding:12px 28px; border-radius:8px; text-decoration:none; font-weight:bold; font-size:16px; display:inline-block;">Redefinir Minha Senha</a>
        </div>
        <p style="font-size:13px; color:#94a3b8;">Ou copie e cole o link no seu navegador:</p>
        <p style="font-size:12px; color:#38bdf8; word-break:break-all; background:#0f172a; padding:8px; border-radius:6px;">' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '</p>
        <hr style="border-color:#334155; margin:24px 0;">
        <p style="font-size:12px; color:#64748b; text-align:center;">Este link é válido por <strong>15 minutos</strong>. Se você não solicitou a recuperação, ignore este e-mail. Sua senha <strong>não será alterada</strong>.</p>
    </div>
</body>
</html>';

        return self::sendEmailSmtp($toEmail, $assunto, $corpo);
    }
}

