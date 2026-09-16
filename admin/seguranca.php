<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../src/Security.php';

$db = Database::getInstance();
$erro = '';
$sucesso = '';

// Garante que as tabelas de segurança existam
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `blocked_ips` (
      `id` int NOT NULL AUTO_INCREMENT,
      `ip_address` varchar(45) NOT NULL,
      `reason` varchar(255) DEFAULT NULL,
      `blocked_until` datetime DEFAULT NULL,
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `ip_address` (`ip_address`),
      KEY `idx_ip` (`ip_address`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS `login_attempts` (
      `id` int NOT NULL AUTO_INCREMENT,
      `ip_address` varchar(45) NOT NULL,
      `email_or_user` varchar(150) DEFAULT NULL,
      `user_type` enum('admin','cliente') NOT NULL DEFAULT 'admin',
      `success` tinyint DEFAULT 0,
      `user_agent` text,
      `geo_country` varchar(100) DEFAULT NULL,
      `geo_city` varchar(100) DEFAULT NULL,
      `device_fingerprint` varchar(64) DEFAULT NULL,
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_ip_created` (`ip_address`, `created_at`),
      KEY `idx_user_created` (`email_or_user`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS `trusted_devices` (
      `id` int NOT NULL AUTO_INCREMENT,
      `user_id` int NOT NULL,
      `user_type` enum('admin','cliente') NOT NULL DEFAULT 'admin',
      `device_hash` varchar(64) NOT NULL,
      `device_name` varchar(255) DEFAULT NULL,
      `ip_address` varchar(45) DEFAULT NULL,
      `trusted_until` datetime NOT NULL,
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_user_device` (`user_id`, `user_type`, `device_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS `security_settings` (
      `id` int NOT NULL DEFAULT 1,
      `max_login_attempts` int DEFAULT 5,
      `lockout_duration_minutes` int DEFAULT 15,
      `auto_block_threshold` int DEFAULT 10,
      `auto_block_duration_hours` int DEFAULT 24,
      `session_timeout_minutes` int DEFAULT 30,
      `trusted_device_days` int DEFAULT 30,
      `require_2fa_admin` tinyint DEFAULT 1,
      `geo_check_enabled` tinyint DEFAULT 0,
      `device_check_enabled` tinyint DEFAULT 1,
      `client_login_no_password` tinyint DEFAULT 0,
      `admin_ip_whitelist` text,
      `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $db->exec("ALTER TABLE `security_settings` ADD COLUMN IF NOT EXISTS `client_login_no_password` tinyint DEFAULT 0");
    } catch (Exception $e) {}

    $db->exec("INSERT IGNORE INTO `security_settings` (`id`) VALUES (1)");
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrfToken()) {
        $erro = 'Sessão expirada ou requisição inválida.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'unblock_ip') {
            $ip = $_POST['ip'] ?? '';
            if ($ip) {
                Security::unblockIp($ip);
                Database::log('admin_seguranca', "IP desbloqueado: {$ip}", ['ip' => $ip, 'admin_id' => $_SESSION['admin_id']]);
                $sucesso = "IP {$ip} desbloqueado com sucesso.";
            }
        } elseif ($action === 'block_ip_manual') {
            $ip = trim($_POST['ip_bloquear'] ?? '');
            $motivo = trim($_POST['motivo_bloquear'] ?? 'Bloqueio manual pelo administrador');
            $horas = (int)($_POST['horas_bloquear'] ?? 24);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                try {
                    Security::blockIp($ip, $motivo, $horas > 0 ? $horas : null);
                    Database::log('admin_seguranca', "IP bloqueado manualmente: {$ip}", ['ip' => $ip, 'motivo' => $motivo, 'admin_id' => $_SESSION['admin_id']]);
                    $sucesso = "IP {$ip} bloqueado com sucesso por " . ($horas > 0 ? "{$horas}h" : "tempo indeterminado") . ".";
                } catch (Exception $e) {
                    $erro = "Erro ao bloquear IP: " . $e->getMessage();
                }
            } else {
                $erro = "Endereço IP inválido informado: {$ip}";
            }
        } elseif ($action === 'revoke_device') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                try {
                    $stmt = $db->prepare("DELETE FROM trusted_devices WHERE id = ?");
                    $stmt->execute([$id]);
                    Database::log('admin_seguranca', "Dispositivo confiável revogado (ID: {$id})");
                    $sucesso = "Dispositivo revogado com sucesso.";
                } catch (Exception $e) {
                    $erro = "Erro ao revogar dispositivo: " . $e->getMessage();
                }
            }
        } elseif ($action === 'trust_current_device') {
            if (!empty($_SESSION['admin_id'])) {
                $ok = Security::trustCurrentDevice((int)$_SESSION['admin_id'], 'admin');
                if ($ok) {
                    Database::log('admin_seguranca', "Dispositivo atual marcado como confiável pelo admin", ['admin_id' => $_SESSION['admin_id']]);
                    $sucesso = "Este dispositivo foi registrado como confiável com sucesso!";
                } else {
                    $erro = "Não foi possível registrar o dispositivo como confiável.";
                }
            }
        } elseif ($action === 'save_settings') {
            $max_login_attempts = (int)($_POST['max_login_attempts'] ?? 5);
            $lockout_duration_minutes = (int)($_POST['lockout_duration_minutes'] ?? 15);
            $session_timeout_minutes = (int)($_POST['session_timeout_minutes'] ?? 30);
            $trusted_device_days = (int)($_POST['trusted_device_days'] ?? 30);
            $require_2fa_admin = isset($_POST['require_2fa_admin']) ? 1 : 0;
            $geo_check_enabled = isset($_POST['geo_check_enabled']) ? 1 : 0;
            $device_check_enabled = isset($_POST['device_check_enabled']) ? 1 : 0;
            $client_login_no_password = isset($_POST['client_login_no_password']) ? 1 : 0;
            $admin_ip_whitelist = sanitize($_POST['admin_ip_whitelist'] ?? '');
            
            try {
                $stmt = $db->prepare("
                    UPDATE security_settings 
                    SET max_login_attempts = ?, lockout_duration_minutes = ?, session_timeout_minutes = ?, 
                        trusted_device_days = ?, require_2fa_admin = ?, geo_check_enabled = ?, 
                        device_check_enabled = ?, client_login_no_password = ?, admin_ip_whitelist = ?
                ");
                $stmt->execute([
                    $max_login_attempts, $lockout_duration_minutes, $session_timeout_minutes,
                    $trusted_device_days, $require_2fa_admin, $geo_check_enabled,
                    $device_check_enabled, $client_login_no_password, $admin_ip_whitelist
                ]);
                Database::log('admin_seguranca', "Configurações de segurança atualizadas");
                $sucesso = "Configurações salvas com sucesso.";
            } catch (Exception $e) {
                $erro = "Erro ao salvar configurações (Verifique a tabela no banco). Detalhe: " . $e->getMessage();
            }
        }
    }
}


// Queries with silent fail if tables don't exist yet
try {
    $tentativas_24h = $db->query("SELECT COUNT(*) FROM login_attempts WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn() ?: 0;
    $falhas_24h = $db->query("SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn() ?: 0;
    $ips_bloqueados_count = $db->query("SELECT COUNT(*) FROM blocked_ips WHERE blocked_until IS NULL OR blocked_until > NOW()")->fetchColumn() ?: 0;
    $dispositivos_count = $db->query("SELECT COUNT(*) FROM trusted_devices WHERE trusted_until > NOW()")->fetchColumn() ?: 0;
} catch (Exception $e) { $tentativas_24h = $falhas_24h = $ips_bloqueados_count = $dispositivos_count = 0; }

try {
    $login_attempts = $db->query("SELECT * FROM login_attempts ORDER BY created_at DESC LIMIT 50")->fetchAll();
} catch (Exception $e) { $login_attempts = []; }

try {
    $blocked_ips = $db->query("SELECT * FROM blocked_ips WHERE blocked_until IS NULL OR blocked_until > NOW() ORDER BY created_at DESC")->fetchAll();
} catch (Exception $e) { $blocked_ips = []; }

try {
    $trusted_devices = $db->query("
        SELECT t.*, 
               CASE 
                   WHEN t.user_type = 'admin' THEN COALESCE(a.nome, 'Administrador') 
                   ELSE COALESCE(c.nome, 'Cliente') 
               END as usuario_nome 
        FROM trusted_devices t 
        LEFT JOIN clientes c ON t.user_id = c.id AND t.user_type = 'cliente' 
        LEFT JOIN administradores a ON t.user_id = a.id AND t.user_type = 'admin' 
        WHERE t.trusted_until > NOW() 
        ORDER BY t.created_at DESC
    ")->fetchAll();
} catch (Exception $e) { $trusted_devices = []; }

try {
    $settings = $db->query("SELECT * FROM security_settings LIMIT 1")->fetch() ?: [];
} catch (Exception $e) { $settings = []; }

$s = array_merge([
    'max_login_attempts' => 5,
    'lockout_duration_minutes' => 15,
    'session_timeout_minutes' => 30,
    'trusted_device_days' => 30,
    'require_2fa_admin' => 0,
    'geo_check_enabled' => 0,
    'device_check_enabled' => 0,
    'client_login_no_password' => 0,
    'admin_ip_whitelist' => ''
], (array)$settings);

?>

<?php if ($erro): ?>
    <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= $erro ?></div>
<?php endif; ?>
<?php if ($sucesso): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= $sucesso ?></div>
<?php endif; ?>

<!-- Resumo de Segurança -->
<div class="row g-4 mb-4">
    <div class="col-12">
        <h6 class="text-white fw-bold mb-0"><i class="fa-solid fa-shield-halved text-info me-2"></i>Resumo de Segurança</h6>
    </div>

    <div class="col-md-3">
        <div class="card-custom h-100" style="border-left: 4px solid #00bcd4; display: flex; flex-direction: column; justify-content: space-between;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Tentativas de Login (24h)</div>
                    <div class="stat-value text-info fs-3 fw-bold"><?= $tentativas_24h ?></div>
                </div>
                <div style="background: rgba(0, 188, 212, 0.12); color: #00bcd4; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center;" class="rounded-circle">
                    <i class="fa-solid fa-right-to-bracket fs-4"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-custom h-100" style="border-left: 4px solid #ef4444; display: flex; flex-direction: column; justify-content: space-between;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Falhas de Login (24h)</div>
                    <div class="stat-value text-danger fs-3 fw-bold"><?= $falhas_24h ?></div>
                </div>
                <div style="background: rgba(239, 68, 68, 0.12); color: #ef4444; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center;" class="rounded-circle">
                    <i class="fa-solid fa-triangle-exclamation fs-4"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-custom h-100" style="border-left: 4px solid #f59e0b; display: flex; flex-direction: column; justify-content: space-between;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">IPs Bloqueados</div>
                    <div class="stat-value text-warning fs-3 fw-bold"><?= $ips_bloqueados_count ?></div>
                </div>
                <div style="background: rgba(245, 158, 11, 0.12); color: #f59e0b; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center;" class="rounded-circle">
                    <i class="fa-solid fa-ban fs-4"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card-custom h-100" style="border-left: 4px solid #10b981; display: flex; flex-direction: column; justify-content: space-between;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-label">Dispositivos Confiáveis</div>
                    <div class="stat-value text-success fs-3 fw-bold"><?= $dispositivos_count ?></div>
                </div>
                <div style="background: rgba(16, 185, 129, 0.12); color: #10b981; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center;" class="rounded-circle">
                    <i class="fa-solid fa-laptop-code fs-4"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- IPs Bloqueados -->
    <div class="col-md-6 mb-4">
        <div class="card-custom h-100">
            <h5 class="mb-3 text-white fw-bold"><i class="fa-solid fa-ban me-2 text-warning"></i>IPs Bloqueados</h5>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>IP</th>
                            <th>Motivo</th>
                            <th>Até</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($blocked_ips)): ?>
                            <tr><td colspan="4" class="text-center text-secondary">Nenhum IP bloqueado.</td></tr>
                        <?php else: foreach ($blocked_ips as $ipb): ?>
                            <tr>
                                <td class="font-monospace small text-danger fw-bold"><?= sanitize($ipb['ip_address']) ?></td>
                                <td><small class="text-secondary"><?= sanitize($ipb['reason']) ?></small></td>
                                <td><small><?= $ipb['blocked_until'] ? date('d/m/Y H:i', strtotime($ipb['blocked_until'])) : 'Permanente' ?></small></td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <?= Security::generateCsrfToken() ?>
                                        <input type="hidden" name="action" value="unblock_ip">
                                        <input type="hidden" name="ip" value="<?= sanitize($ipb['ip_address']) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success" onclick="return confirm('Desbloquear este IP?');" title="Desbloquear"><i class="fa-solid fa-unlock"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Formulário Bloquear IP Manualmente -->
            <div class="mt-3 pt-3" style="border-top: 1px solid var(--border-color);">
                <form method="POST" class="row g-2 align-items-end">
                    <?= Security::generateCsrfToken() ?>
                    <input type="hidden" name="action" value="block_ip_manual">
                    <div class="col-md-5">
                        <label class="form-label small text-secondary mb-1">Bloquear IP Manualmente</label>
                        <input type="text" name="ip_bloquear" class="form-control-custom" placeholder="Ex: 192.168.1.50" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-secondary mb-1">Duração</label>
                        <select name="horas_bloquear" class="form-control-custom">
                            <option value="1">1 hora</option>
                            <option value="6">6 horas</option>
                            <option value="24" selected>24 horas (1 dia)</option>
                            <option value="168">7 dias</option>
                            <option value="0">Permanente</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-outline-danger w-100">
                            <i class="fa-solid fa-ban me-1"></i>Bloquear
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Dispositivos Confiáveis -->
    <div class="col-md-6 mb-4">
        <div class="card-custom h-100">
            <h5 class="mb-3 text-white fw-bold"><i class="fa-solid fa-laptop-code me-2 text-info"></i>Dispositivos Confiáveis</h5>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Usuário</th>
                            <th>Dispositivo/IP</th>
                            <th>Até</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($trusted_devices)): ?>
                            <tr><td colspan="4" class="text-center text-secondary">Nenhum dispositivo confiável.</td></tr>
                        <?php else: foreach ($trusted_devices as $dev): ?>
                            <tr>
                                <td><?= sanitize($dev['usuario_nome'] ?: 'Admin') ?> (<?= sanitize($dev['user_type']) ?>)</td>
                                <td>
                                    <small><?= sanitize($dev['device_name'] ?? $dev['device_hash']) ?></small><br>
                                    <small class="text-secondary">IP: <?= sanitize($dev['ip_address']) ?></small>
                                </td>
                                <td><small><?= date('d/m/Y', strtotime($dev['trusted_until'])) ?></small></td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <?= Security::generateCsrfToken() ?>
                                        <input type="hidden" name="action" value="revoke_device">
                                        <input type="hidden" name="id" value="<?= $dev['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Revogar dispositivo?');" title="Revogar"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Botão Confiar no Dispositivo Atual -->
            <div class="mt-3 pt-3" style="border-top: 1px solid var(--border-color);">
                <form method="POST" class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                    <?= Security::generateCsrfToken() ?>
                    <input type="hidden" name="action" value="trust_current_device">
                    <div>
                        <div class="small fw-bold text-white"><i class="fa-solid fa-shield-check text-success me-1"></i>Computador/Navegador Atual</div>
                        <div class="small text-secondary">Salvar este dispositivo como confiável (dispensa 2FA por <?= $s['trusted_device_days'] ?> dias)</div>
                    </div>
                    <button type="submit" class="btn btn-outline-success btn-sm text-nowrap">
                        <i class="fa-solid fa-laptop-medical me-1"></i>Confiar Neste Dispositivo
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Últimas Tentativas de Login -->
<div class="card-custom mb-4">
    <h5 class="mb-3 text-white fw-bold"><i class="fa-solid fa-list-ul me-2 text-primary"></i>Últimas Tentativas de Login</h5>
    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>Data/Hora</th>
                    <th>Tipo</th>
                    <th>Usuário</th>
                    <th>IP / Local</th>
                    <th>Dispositivo</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($login_attempts)): ?>
                    <tr><td colspan="6" class="text-center text-secondary">Nenhuma tentativa registrada.</td></tr>
                <?php else: foreach ($login_attempts as $la): ?>
                    <tr>
                        <td><small><?= date('d/m/Y H:i:s', strtotime($la['created_at'])) ?></small></td>
                        <td><span class="badge bg-secondary"><?= sanitize($la['user_type']) ?></span></td>
                        <td><?= sanitize($la['email_or_user']) ?></td>
                        <td>
                            <small><?= sanitize($la['ip_address']) ?></small><br>
                            <?php if ($la['geo_city'] || $la['geo_country']): ?>
                                <small class="text-secondary"><?= sanitize($la['geo_city'] . ', ' . $la['geo_country']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><small class="text-secondary d-inline-block text-truncate" style="max-width: 150px;" title="<?= sanitize($la['user_agent']) ?>"><?= sanitize($la['user_agent']) ?></small></td>
                        <td>
                            <?php if ($la['success']): ?>
                                <span class="badge bg-success">Sucesso</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Falha</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Configurações de Segurança -->
<div class="card-custom mb-4">
    <h5 class="mb-3 text-white fw-bold"><i class="fa-solid fa-gear me-2 text-secondary"></i>Configurações de Segurança</h5>
    <form method="POST">
        <?= Security::generateCsrfToken() ?>
        <input type="hidden" name="action" value="save_settings">
        
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label text-secondary small">Máx. Tentativas de Login</label>
                <input type="number" name="max_login_attempts" class="form-control bg-dark text-white border-secondary" value="<?= $s['max_login_attempts'] ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label text-secondary small">Duração do Bloqueio (minutos)</label>
                <input type="number" name="lockout_duration_minutes" class="form-control bg-dark text-white border-secondary" value="<?= $s['lockout_duration_minutes'] ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label text-secondary small">Timeout da Sessão (minutos)</label>
                <input type="number" name="session_timeout_minutes" class="form-control bg-dark text-white border-secondary" value="<?= $s['session_timeout_minutes'] ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label text-secondary small">Validade Dispositivo Confiável (dias)</label>
                <input type="number" name="trusted_device_days" class="form-control bg-dark text-white border-secondary" value="<?= $s['trusted_device_days'] ?>" required>
            </div>
            
            <div class="col-md-12 mt-4">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" role="switch" name="require_2fa_admin" id="req2fa" value="1" <?= $s['require_2fa_admin'] ? 'checked' : '' ?>>
                    <label class="form-check-label text-white" for="req2fa">Exigir 2FA para Administradores</label>
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" role="switch" name="geo_check_enabled" id="geocheck" value="1" <?= $s['geo_check_enabled'] ? 'checked' : '' ?>>
                    <label class="form-check-label text-white" for="geocheck">Ativar Verificação Geográfica (Aviso em novo estado/país)</label>
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" role="switch" name="device_check_enabled" id="devcheck" value="1" <?= $s['device_check_enabled'] ? 'checked' : '' ?>>
                    <label class="form-check-label text-white" for="devcheck">Ativar Verificação de Dispositivo (Aviso em novo navegador/aparelho)</label>
                </div>
                <div class="form-check form-switch mt-3 p-3 rounded" style="background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25);">
                    <input class="form-check-input ms-0 me-2" type="checkbox" role="switch" name="client_login_no_password" id="clilogin" value="1" <?= $s['client_login_no_password'] ? 'checked' : '' ?>>
                    <label class="form-check-label text-warning fw-bold" for="clilogin">
                        <i class="fa-solid fa-flask me-1"></i>Modo de Teste Rápido: Permitir Acesso do Cliente Sem Senha
                    </label>
                    <div class="small text-secondary mt-1">
                        Quando ativado, o assinante pode acessar a Central do Assinante informando apenas seu E-mail, Telefone (WhatsApp) ou CPF, sem necessidade de senha. Ideal para homologação e testes ágeis da plataforma.
                    </div>
                </div>
            </div>
            
            <div class="col-md-12 mt-3">
                <label class="form-label text-secondary small">IPs Liberados (Admin) - Um por linha</label>
                <textarea name="admin_ip_whitelist" class="form-control bg-dark text-white border-secondary" rows="3"><?= sanitize($s['admin_ip_whitelist']) ?></textarea>
            </div>
        </div>
        
        <div class="mt-4">
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-2"></i>Salvar Configurações</button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
