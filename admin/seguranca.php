<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../src/Security.php';

$db = Database::getInstance();
$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrfToken()) {
        $erro = 'Sessão expirada ou requisição inválida.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'unblock_ip') {
            $ip = $_POST['ip'] ?? '';
            if ($ip) {
                Security::unblockIp($ip);
                Database::log('admin_seguranca', "IP desbloqueado: {$ip}");
                $sucesso = "IP {$ip} desbloqueado com sucesso.";
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
        } elseif ($action === 'save_settings') {
            $max_login_attempts = (int)($_POST['max_login_attempts'] ?? 5);
            $lockout_duration_minutes = (int)($_POST['lockout_duration_minutes'] ?? 15);
            $session_timeout_minutes = (int)($_POST['session_timeout_minutes'] ?? 30);
            $trusted_device_days = (int)($_POST['trusted_device_days'] ?? 30);
            $require_2fa_admin = isset($_POST['require_2fa_admin']) ? 1 : 0;
            $geo_check_enabled = isset($_POST['geo_check_enabled']) ? 1 : 0;
            $device_check_enabled = isset($_POST['device_check_enabled']) ? 1 : 0;
            $admin_ip_whitelist = sanitize($_POST['admin_ip_whitelist'] ?? '');
            
            try {
                $stmt = $db->prepare("
                    UPDATE security_settings 
                    SET max_login_attempts = ?, lockout_duration_minutes = ?, session_timeout_minutes = ?, trusted_device_days = ?, require_2fa_admin = ?, geo_check_enabled = ?, device_check_enabled = ?, admin_ip_whitelist = ?
                ");
                $stmt->execute([
                    $max_login_attempts, $lockout_duration_minutes, $session_timeout_minutes,
                    $trusted_device_days, $require_2fa_admin, $geo_check_enabled,
                    $device_check_enabled, $admin_ip_whitelist
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
        SELECT t.*, c.nome as usuario_nome 
        FROM trusted_devices t 
        LEFT JOIN clientes c ON t.user_id = c.id AND t.user_type = 'cliente' 
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
                                <td><?= sanitize($ipb['ip_address']) ?></td>
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
