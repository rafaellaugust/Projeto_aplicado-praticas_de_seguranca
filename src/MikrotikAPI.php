<?php
/**
 * src/MikrotikAPI.php - VERSÃO FINAL CORRIGIDA
 * Usa RouterOSAPI v1.6 (Denis Basta) - Testada e estável
 * Suporte S1/S2 multi-roteador + 3 perfis (normal/aviso/bloqueado)
 */

require_once __DIR__ . '/RouterOSAPI.php'; // ou __DIR__ . '/../RouterOSAPI.php' se você colocar na raiz

class MikrotikAPI {
    private $router = null;
    private $api = null;
    private $lastError = '';
    private $routerId = 1;

    public function __construct($roteadorId = null, $ip = null, $port = null, $username = null, $password = null) {
        // Uso direto com IP
        if ($ip && $username) {
            $this->router = [
                'id' => $roteadorId ?: 1,
                'ip_host' => $ip,
                'porta_api' => $port ?: 8728,
                'usuario' => $username,
                'senha' => $password,
                'usar_ssl' => ($port == 8729) ? 1 : 0,
                'nome' => 'Direto'
            ];
            $this->routerId = $this->router['id'];
            return;
        }

        // Carrega do banco
        try {
            $db = Database::getInstance();
            if ($roteadorId) {
                $stmt = $db->prepare("SELECT * FROM roteadores WHERE id = ? LIMIT 1");
                $stmt->execute([$roteadorId]);
                $this->router = $stmt->fetch();
            }
            
            if (!$this->router) {
                $stmt = $db->query("SELECT * FROM roteadores WHERE status != 'desativado' AND ip_host IS NOT NULL AND ip_host != '' ORDER BY id ASC LIMIT 1");
                $this->router = $stmt->fetch();
            }

            if ($this->router) {
                $this->routerId = $this->router['id'];
            }
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
        }
    }

    private function getApiInstance(): RouterosAPI {
        if ($this->api) return $this->api;
        
        $api = new RouterosAPI();
        $api->debug = false;
        $api->port = (int)($this->router['porta_api'] ?? 8728);
        $api->ssl = !empty($this->router['usar_ssl']);
        $api->timeout = 5;
        $api->attempts = 2;
        $this->api = $api;
        return $api;
    }

    public function getLastError(): string {
        return $this->lastError;
    }

    public function connect(): bool {
        if (!$this->router) {
            $this->lastError = 'Roteador não configurado';
            return false;
        }

        $api = $this->getApiInstance();
        $host = $this->router['ip_host'];
        $user = $this->router['usuario'];
        $pass = $this->router['senha'];

        if ($api->connect($host, $user, $pass)) {
            return true;
        }

        $this->lastError = "Falha conectar {$host}:{$api->port} - {$api->error_str}";
        Database::log('mikrotik', $this->lastError, ['router' => $this->router['nome']]);
        return false;
    }

    public function disconnect() {
        if ($this->api) {
            $this->api->disconnect();
        }
    }

    public function comm($command, $params = []) {
        $api = $this->getApiInstance();
        if (!$api->connected && !$this->connect()) {
            return false;
        }

        $result = $api->comm($command, $params);
        if (isset($result['!trap'])) {
            $msg = $result['!trap'][0]['message'] ?? json_encode($result);
            $this->lastError = "RouterOS !trap em $command: $msg";
            Database::log('mikrotik', $this->lastError, ['cmd' => $command]);
            return false;
        }
        return $result;
    }

    public function testConnection(): bool {
        $res = $this->comm('/system/identity/print');
        $this->disconnect();
        return $res !== false;
    }

    public function getProfiles(): array {
        $profiles = $this->comm('/ppp/profile/print');
        if (empty($profiles)) {
            $profiles = $this->comm('/ip/hotspot/user/profile/print');
        }
        $this->disconnect();
        return $profiles ?: [];
    }

    public function getSecrets(): array {
        $secrets = $this->comm('/ppp/secret/print');
        $hotspot = $this->comm('/ip/hotspot/user/print');
        $all = [];

        if (is_array($secrets)) {
            foreach ($secrets as $s) {
                $s['tipo'] = 'pppoe';
                $all[] = $s;
            }
        }
        if (is_array($hotspot)) {
            foreach ($hotspot as $h) {
                $h['tipo'] = 'hotspot';
                $all[] = $h;
            }
        }
        $this->disconnect();
        return $all;
    }

    public function getClientProfile(string $username): ?string {
        $ppp = $this->comm('/ppp/secret/print', ['?name' => $username]);
        if (!empty($ppp) && isset($ppp[0]['profile'])) {
            $this->disconnect();
            return $ppp[0]['profile'];
        }
        $hs = $this->comm('/ip/hotspot/user/print', ['?name' => $username]);
        if (!empty($hs) && isset($hs[0]['profile'])) {
            $this->disconnect();
            return $hs[0]['profile'];
        }
        $this->disconnect();
        return null;
    }

    public function changeSecretProfile(string $username, string $newProfile): bool {
        if (empty($username) || empty($newProfile)) {
            $this->lastError = 'Username ou profile vazio';
            return false;
        }

        $ppp = $this->comm('/ppp/secret/print', ['?name' => $username]);
        if (!empty($ppp) && isset($ppp[0]['.id'])) {
            $res = $this->comm('/ppp/secret/set', ['.id' => $ppp[0]['.id'], 'profile' => $newProfile]);
            $this->disconnect();
            if ($res === false) return false;
            Database::log('mikrotik', "PPPoE $username -> $newProfile [R{$this->routerId}]");
            return true;
        }

        $hs = $this->comm('/ip/hotspot/user/print', ['?name' => $username]);
        if (!empty($hs) && isset($hs[0]['.id'])) {
            $res = $this->comm('/ip/hotspot/user/set', ['.id' => $hs[0]['.id'], 'profile' => $newProfile]);
            $this->disconnect();
            if ($res === false) return false;
            Database::log('mikrotik', "Hotspot $username -> $newProfile [R{$this->routerId}]");
            return true;
        }

        $this->lastError = "Usuário $username não encontrado";
        $this->disconnect();
        return false;
    }

    public function disconnectActiveSession(string $username): bool {
        if (empty($username)) return false;
        
        $pppActive = $this->comm('/ppp/active/print', ['?name' => $username]);
        if (!empty($pppActive)) {
            foreach ($pppActive as $act) {
                if (isset($act['.id'])) {
                    $this->comm('/ppp/active/remove', ['.id' => $act['.id']]);
                }
            }
        }

        $hsActive = $this->comm('/ip/hotspot/active/print', ['?user' => $username]);
        if (!empty($hsActive)) {
            foreach ($hsActive as $act) {
                if (isset($act['.id'])) {
                    $this->comm('/ip/hotspot/active/remove', ['.id' => $act['.id']]);
                }
            }
        }

        $this->disconnect();
        return true;
    }

    public function syncSecret($username, $password, $profile = 'default', $comment = 'MikroPay') {
        $existing = $this->comm('/ppp/secret/print', ['?name' => $username]);
        if (!empty($existing)) {
            $this->comm('/ppp/secret/set', ['.id' => $existing[0]['.id'], 'password' => $password, 'profile' => $profile, 'comment' => $comment]);
        } else {
            $this->comm('/ppp/secret/add', ['name' => $username, 'password' => $password, 'profile' => $profile, 'service' => 'pppoe', 'comment' => $comment]);
        }
        $this->disconnect();
        return true;
    }

    public function removeSecret($username) {
        $existing = $this->comm('/ppp/secret/print', ['?name' => $username]);
        if (!empty($existing)) {
            $this->comm('/ppp/secret/remove', ['.id' => $existing[0]['.id']]);
        }
        $hs = $this->comm('/ip/hotspot/user/print', ['?name' => $username]);
        if (!empty($hs)) {
            $this->comm('/ip/hotspot/user/remove', ['.id' => $hs[0]['.id']]);
        }
        $this->disconnect();
        return true;
    }
}
