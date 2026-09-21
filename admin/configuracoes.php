<?php
require_once __DIR__ . '/../config.php';
checkAdminLogin();

// Garante que as colunas de webhook callback existam
try {
    $db = Database::getInstance();
    $stmtCCheck = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'configuracoes' AND COLUMN_NAME = 'webhook_callback_url'");
    $stmtCCheck->execute([DB_NAME]);
    if ((int)$stmtCCheck->fetchColumn() === 0) {
        $db->exec("ALTER TABLE `configuracoes` ADD COLUMN `webhook_callback_url` VARCHAR(255) DEFAULT NULL");
        $db->exec("ALTER TABLE `configuracoes` ADD COLUMN `webhook_callback_ativo` TINYINT(1) DEFAULT 0");
    }

    $stmtAppCheck = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'configuracoes' AND COLUMN_NAME = 'app_url'");
    $stmtAppCheck->execute([DB_NAME]);
    if ((int)$stmtAppCheck->fetchColumn() === 0) {
        $db->exec("ALTER TABLE `configuracoes` ADD COLUMN `app_url` VARCHAR(255) DEFAULT 'https://aplicacao.spaconett.com'");
    }

    // Colunas SMTP
    $smtpCheck = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'configuracoes' AND COLUMN_NAME = 'smtp_host'");
    $smtpCheck->execute([DB_NAME]);
    if ((int)$smtpCheck->fetchColumn() === 0) {
        $db->exec("ALTER TABLE `configuracoes` ADD COLUMN `smtp_host` VARCHAR(255) DEFAULT NULL");
        $db->exec("ALTER TABLE `configuracoes` ADD COLUMN `smtp_port` SMALLINT DEFAULT 587");
        $db->exec("ALTER TABLE `configuracoes` ADD COLUMN `smtp_user` VARCHAR(255) DEFAULT NULL");
        $db->exec("ALTER TABLE `configuracoes` ADD COLUMN `smtp_pass` VARCHAR(255) DEFAULT NULL");
        $db->exec("ALTER TABLE `configuracoes` ADD COLUMN `smtp_from` VARCHAR(255) DEFAULT NULL");
    }

    // Colunas reCAPTCHA
    $rcCheck = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'configuracoes' AND COLUMN_NAME = 'recaptcha_site_key'");
    $rcCheck->execute([DB_NAME]);
    if ((int)$rcCheck->fetchColumn() === 0) {
        $db->exec("ALTER TABLE `configuracoes` ADD COLUMN `recaptcha_site_key` VARCHAR(255) DEFAULT NULL");
        $db->exec("ALTER TABLE `configuracoes` ADD COLUMN `recaptcha_secret_key` VARCHAR(255) DEFAULT NULL");
    }

    // Tabela password_resets
    $db->exec("CREATE TABLE IF NOT EXISTS `password_resets` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `email` VARCHAR(150) NOT NULL,
        `user_type` ENUM('admin','cliente') NOT NULL DEFAULT 'admin',
        `token_hash` VARCHAR(64) NOT NULL,
        `expires_at` DATETIME NOT NULL,
        `used` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_token` (`token_hash`),
        KEY `idx_email_type` (`email`, `user_type`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

} catch (Exception $e) {}


// Testar Credenciais do Mercado Pago — via AJAX (ajax=1) para não travar a página
if (isset($_GET['ajax']) && $_GET['ajax'] === 'testar_mp') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $gateway = new PaymentGateway();
        $resMP   = $gateway->testToken(); // método correto em PaymentGateway.php
        $statusMsg = !empty($resMP['success']) ? 'Conexão com Mercado Pago validada com sucesso' : 'Falha na conexão com Mercado Pago: ' . ($resMP['message'] ?? 'Erro desconhecido');
        Database::log('gateway', $statusMsg, [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'admin_id' => $_SESSION['admin_id'] ?? null,
            'resultado' => $resMP
        ]);
        echo json_encode($resMP);
    } catch (Exception $e) {
        Database::log('gateway_erro', "Exceção ao testar Mercado Pago: " . $e->getMessage(), [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'admin_id' => $_SESSION['admin_id'] ?? null
        ]);
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// Testar Webhook Callback — via AJAX
if (isset($_GET['ajax']) && $_GET['ajax'] === 'testar_webhook_callback') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $db = Database::getInstance();
        $url = $db->query("SELECT webhook_callback_url FROM configuracoes WHERE id = 1")->fetchColumn();
        if (empty($url)) {
            Database::log('webhook', "Tentativa de teste de Webhook Callback sem URL cadastrada", [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'admin_id' => $_SESSION['admin_id'] ?? null
            ]);
            echo json_encode(['success' => false, 'message' => 'Nenhuma URL de Webhook cadastrada.']);
            exit;
        }
        $payload = [
            'event' => 'payment.test',
            'message' => 'Teste de conexão do webhook callback do MikroPay concluído com sucesso!',
            'text' => 'Teste de conexão do webhook callback do MikroPay concluído com sucesso!',
            'data_teste' => date('Y-m-d H:i:s')
        ];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'User-Agent: MikroPay-Webhook-Test/1.0']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode >= 200 && $httpCode < 300) {
            Database::log('webhook_callback', "Teste de Webhook Callback enviado com sucesso para {$url} (HTTP {$httpCode})", [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'url' => $url,
                'http_code' => $httpCode
            ]);
            echo json_encode(['success' => true, 'message' => "Webhook OK! Resposta HTTP {$httpCode} recebida."]);
        } else {
            Database::log('webhook_callback', "Falha no teste de Webhook Callback para {$url} (HTTP {$httpCode})", [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'url' => $url,
                'http_code' => $httpCode,
                'resposta' => substr($response, 0, 200)
            ]);
            echo json_encode(['success' => false, 'message' => "Erro de conexão HTTP {$httpCode}. Retorno: " . substr($response, 0, 150)]);
        }
    } catch (Exception $e) {
        Database::log('webhook_callback', "Exceção no teste de Webhook Callback: " . $e->getMessage(), [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Testar SMTP — via AJAX
if (isset($_GET['ajax']) && $_GET['ajax'] === 'testar_smtp') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $db = Database::getInstance();
        $cfg = $db->query("SELECT smtp_host, smtp_port, smtp_user, smtp_pass, smtp_from, empresa_nome FROM configuracoes WHERE id = 1")->fetch();
        $toEmail = $cfg['smtp_from'] ?? (getenv('MAIL_FROM') ?: '');
        if (empty($toEmail)) {
            Database::log('smtp', "Tentativa de teste SMTP sem e-mail remetente configurado", [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'admin_id' => $_SESSION['admin_id'] ?? null
            ]);
            echo json_encode(['success' => false, 'message' => 'Configure um e-mail remetente (SMTP From) antes de testar.']);
            exit;
        }
        $empresa = getEmpresaNome();
        $corpo = '<div style="font-family:Arial; padding:20px; background:#1e293b; color:#f8fafc; border-radius:8px;"><h3 style="color:#0ea5e9;">✅ Teste SMTP — ' . htmlspecialchars($empresa) . '</h3><p>Conexão SMTP configurada com sucesso! Esta é uma mensagem de teste enviada pelo painel.</p></div>';
        $ok = Security::sendEmailSmtp($toEmail, "✅ Teste SMTP — {$empresa}", $corpo);
        if ($ok) {
            Database::log('smtp', "Teste de envio de e-mail SMTP enviado com sucesso para {$toEmail}", [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'admin_id' => $_SESSION['admin_id'] ?? null,
                'destinatario' => $toEmail,
                'host' => $cfg['smtp_host'] ?? '',
                'porta' => $cfg['smtp_port'] ?? 587
            ]);
        } else {
            Database::log('smtp_erro', "Falha no teste de envio de e-mail SMTP para {$toEmail}: " . (Security::$lastSmtpError ?: "Credenciais rejeitadas"), [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'admin_id' => $_SESSION['admin_id'] ?? null,
                'destinatario' => $toEmail,
                'host' => $cfg['smtp_host'] ?? '',
                'porta' => $cfg['smtp_port'] ?? 587,
                'erro' => Security::$lastSmtpError ?: 'Erro desconhecido'
            ]);
        }
        echo json_encode([
            'success' => $ok,
            'message' => $ok ? "E-mail de teste enviado para {$toEmail} com sucesso!" : ("Falha ao enviar e-mail: " . (Security::$lastSmtpError ?: "Verifique as credenciais SMTP."))
        ]);
    } catch (Exception $e) {
        Database::log('smtp_erro', "Exceção no teste de envio SMTP: " . $e->getMessage(), [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'admin_id' => $_SESSION['admin_id'] ?? null
        ]);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

require_once __DIR__ . '/header.php';

$db = Database::getInstance();
$msgSuccess = '';
$msgError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $empresaNome = sanitize($_POST['empresa_nome'] ?? 'Meu Provedor ISP');
    $empresaCnpj = sanitize($_POST['empresa_cnpj'] ?? '');
    $empresaTelefone = sanitize($_POST['empresa_telefone'] ?? '');
    $appUrl = rtrim(filter_var($_POST['app_url'] ?? '', FILTER_SANITIZE_URL), '/');
    if (empty($appUrl)) {
        $appUrl = BASE_URL;
    }

    $waApiUrl = sanitize($_POST['wa_api_url'] ?? '');
    $waSession = sanitize($_POST['wa_session'] ?? 'default');
    $waToken = sanitize($_POST['wa_token'] ?? '');

    $telegramToken = sanitize($_POST['telegram_bot_token'] ?? '');
    $telegramChatId = sanitize($_POST['telegram_chat_id'] ?? '');
    $telegramAtivo = isset($_POST['telegram_ativo']) ? 1 : 0;

    $gatewayProvider = sanitize($_POST['gateway_provider'] ?? 'mercadopago');
    $mpPublicKey = sanitize($_POST['mp_public_key'] ?? '');
    $mpAccessToken = sanitize($_POST['mp_access_token'] ?? '');
    $mpClientId = sanitize($_POST['mp_client_id'] ?? '');
    $mpClientSecret = sanitize($_POST['mp_client_secret'] ?? '');
    $mpSandbox = isset($_POST['mp_sandbox']) ? 1 : 0;

    $pixChaveEstatica = sanitize($_POST['pix_chave_estatica'] ?? '');
    $pixNomeBeneficiario = sanitize($_POST['pix_nome_beneficiario'] ?? '');
    $pixCidadeBeneficiario = sanitize($_POST['pix_cidade_beneficiario'] ?? '');
    $webhookSecret = sanitize($_POST['webhook_secret'] ?? 'secret123');

    $webhookCallbackUrl = sanitize($_POST['webhook_callback_url'] ?? '');
    $webhookCallbackAtivo = isset($_POST['webhook_callback_ativo']) ? 1 : 0;

    // SMTP
    $smtpHost = sanitize($_POST['smtp_host'] ?? '');
    $smtpPort = (int)($_POST['smtp_port'] ?? 587);
    $smtpUser = sanitize($_POST['smtp_user'] ?? '');
    $smtpPass = $_POST['smtp_pass'] ?? '';  // senha não precisa de htmlspecialchars
    $smtpFrom = sanitize($_POST['smtp_from'] ?? '');

    // reCAPTCHA
    $recaptchaSiteKey   = sanitize($_POST['recaptcha_site_key'] ?? '');
    $recaptchaSecretKey = sanitize($_POST['recaptcha_secret_key'] ?? '');

    try {
        $stmt = $db->prepare("
            UPDATE configuracoes SET 
                empresa_nome = ?, empresa_cnpj = ?, empresa_telefone = ?, app_url = ?,
                wa_api_url = ?, wa_session = ?, wa_token = ?,
                telegram_bot_token = ?, telegram_chat_id = ?, telegram_ativo = ?,
                gateway_provider = ?, mp_public_key = ?, mp_access_token = ?, mp_client_id = ?, mp_client_secret = ?, mp_sandbox = ?,
                pix_chave_estatica = ?, pix_nome_beneficiario = ?, pix_cidade_beneficiario = ?,
                webhook_secret = ?,
                webhook_callback_url = ?, webhook_callback_ativo = ?,
                smtp_host = ?, smtp_port = ?, smtp_user = ?, smtp_pass = ?, smtp_from = ?,
                recaptcha_site_key = ?, recaptcha_secret_key = ?
            WHERE id = 1
        ");
        $stmt->execute([
            $empresaNome, $empresaCnpj, $empresaTelefone, $appUrl,
            $waApiUrl, $waSession, $waToken,
            $telegramToken, $telegramChatId, $telegramAtivo,
            $gatewayProvider, $mpPublicKey, $mpAccessToken, $mpClientId, $mpClientSecret, $mpSandbox,
            $pixChaveEstatica, $pixNomeBeneficiario, $pixCidadeBeneficiario,
            $webhookSecret,
            $webhookCallbackUrl, $webhookCallbackAtivo,
            $smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpFrom,
            $recaptchaSiteKey, $recaptchaSecretKey
        ]);

        // Sincronizar php_base_url no config.json do whatsapp-api
        if (!empty($appUrl)) {
            $configJsonPath = __DIR__ . '/../whatsapp-api/config.json';
            if (file_exists($configJsonPath)) {
                try {
                    $waCfg = json_decode(file_get_contents($configJsonPath), true);
                    if (is_array($waCfg)) {
                        $waCfg['php_base_url'] = $appUrl;
                        file_put_contents($configJsonPath, json_encode($waCfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    }
                } catch (Exception $e) {}
            }
        }

        Database::log('sistema', "Configurações gerais do sistema atualizadas por " . ($_SESSION['admin_nome'] ?? 'Admin'), [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'admin_id' => $_SESSION['admin_id'] ?? null
        ]);

        $msgSuccess = "Todas as configurações foram salvas com sucesso!";
    } catch (Exception $e) {
        $msgError = "Erro ao salvar configurações: " . $e->getMessage();
    }
}


// Testar Telegram
if (isset($_GET['testar_telegram']) && $_GET['testar_telegram'] === '1') {
    $tg = new TelegramService();
    $res = $tg->sendMessage("🔔 <b>TESTE DE INTEGRAÇÃO TELEGRAM</b>\n\nSua plataforma " . htmlspecialchars(getEmpresaNome()) . " está conectada com sucesso ao Telegram Bot!");
    if ($res) {
        Database::log('telegram', "Mensagem de teste do Telegram enviada com sucesso", [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'admin_id' => $_SESSION['admin_id'] ?? null
        ]);
        $msgSuccess = "Mensagem de teste enviada com sucesso no Telegram!";
    } else {
        Database::log('telegram_erro', "Falha ao enviar mensagem de teste no Telegram", [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'admin_id' => $_SESSION['admin_id'] ?? null
        ]);
        $msgError = "Falha ao enviar mensagem no Telegram. Verifique o Token do Bot e o Chat ID.";
    }
}

// Testar WhatsApp
if (isset($_GET['testar_whatsapp']) && $_GET['testar_whatsapp'] === '1') {
    $wa = new WhatsAppService();
    $cfgTemp = $db->query("SELECT empresa_telefone FROM configuracoes WHERE id = 1")->fetch();
    $telefoneDestino = $cfgTemp['empresa_telefone'] ?? '';
    
    if ($wa->checkStatus()) {
        if (!empty($telefoneDestino)) {
            $msg = "🔔 *TESTE DE INTEGRAÇÃO WHATSAPP*\n\nSeu sistema de cobrança está conectado com sucesso ao WhatsApp!";
            $res = $wa->sendTextMessage($telefoneDestino, $msg);
            if ($res) {
                Database::log('whatsapp', "Mensagem de teste do WhatsApp enviada com sucesso para {$telefoneDestino}", [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'destino' => $telefoneDestino
                ]);
                $msgSuccess = "Conexão com a API OK! Mensagem de teste enviada para o WhatsApp da Empresa (" . $telefoneDestino . ").";
            } else {
                Database::log('whatsapp_erro', "Falha ao enviar mensagem de teste do WhatsApp para {$telefoneDestino}", [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'destino' => $telefoneDestino
                ]);
                $msgError = "API do WhatsApp respondeu, mas falhou ao enviar a mensagem. Verifique se o número da empresa (" . $telefoneDestino . ") está correto e ativo no WhatsApp.";
            }
        } else {
            Database::log('whatsapp', "Conexão com API do WhatsApp testada com sucesso (sem telefone destino)", [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
            $msgSuccess = "Conexão com a API do WhatsApp estabelecida com sucesso! (Cadastre um telefone em 'Dados da Empresa' para receber uma mensagem de teste no seu celular).";
        }
    } else {
        Database::log('whatsapp_erro', "Falha ao conectar com API do WhatsApp no teste de status", [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
        $msgError = "Falha ao conectar na API do WhatsApp. Verifique se a URL da API está correta, se o Token é válido e se você escaneou o QR Code.";
    }
}

$stmtConfig = $db->query("SELECT * FROM configuracoes WHERE id = 1");
$config = $stmtConfig->fetch();

$webhookUrlFull = BASE_URL . "/webhook/mercadopago.php";
$macroDroidUrlFull = BASE_URL . "/payment/pix.php?client_name=NOME_DO_CLIENTE";
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="text-white fw-bold mb-1"><i class="fa-solid fa-sliders text-info me-2"></i>Configurações do Sistema</h4>
        <p class="text-secondary small mb-0">Gerencie as credenciais oficiais do Mercado Pago, WhatsApp API, Telegram e MacroDroid.</p>
    </div>
</div>

<?php if ($msgSuccess): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-circle-check me-1"></i> <?= $msgSuccess ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($msgError): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= $msgError ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<form method="POST" action="">
    <div class="row g-4">
        <!-- 1. PAINEL EXCLUSIVO: CREDENCIAIS DO MERCADO PAGO -->
        <div class="col-md-12">
            <div class="card-custom border-start border-warning border-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="text-white fw-bold mb-0">
                        <i class="fa-solid fa-credit-card text-warning me-2"></i>Credenciais Oficiais do Mercado Pago (PIX API)
                    </h5>
                    <button type="button" class="btn btn-sm btn-outline-warning" onclick="testarMP()" id="btnTestarMP">
                        <i class="fa-solid fa-bolt me-1"></i> Testar Credenciais
                    </button>
                </div>
                <div id="resultadoTesteMP" class="mb-3" style="display:none"></div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small text-secondary">Public Key (APP_USR-...)</label>
                        <input type="text" name="mp_public_key" class="form-control-custom font-monospace" value="<?= sanitize($config['mp_public_key'] ?? '') ?>" placeholder="APP_USR-xxxxxx-xxxxxx...">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-secondary">Access Token (APP_USR-...)</label>
                        <input type="password" name="mp_access_token" class="form-control-custom font-monospace" value="<?= sanitize($config['mp_access_token'] ?? '') ?>" placeholder="APP_USR-xxxxxx-xxxxxx...">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small text-secondary">Client ID (Opcional)</label>
                        <input type="text" name="mp_client_id" class="form-control-custom" value="<?= sanitize($config['mp_client_id'] ?? '') ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small text-secondary">Client Secret (Opcional)</label>
                        <input type="password" name="mp_client_secret" class="form-control-custom" value="<?= sanitize($config['mp_client_secret'] ?? '') ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="mp_sandbox" id="chkSandbox" value="1" <?= !empty($config['mp_sandbox']) ? 'checked' : '' ?>>
                            <label class="form-check-label text-warning small fw-bold" for="chkSandbox">Modo Sandbox</label>
                        </div>
                    </div>
                </div>

                <div class="p-3 rounded bg-dark border border-secondary">
                    <label class="form-label text-info fw-bold mb-1"><i class="fa-solid fa-link me-1"></i>URL do Webhook IPN Mercado Pago</label>
                    <input type="text" class="form-control-custom text-warning font-monospace" readonly value="<?= $webhookUrlFull ?>">
                    <small class="text-secondary mt-1 d-block">Cadastre essa URL na sua conta do Mercado Pago para receber notificações de pagamentos PIX em tempo real.</small>
                </div>
            </div>
        </div>

        <!-- 2. DADOS DA EMPRESA -->
        <div class="col-md-6">
            <div class="card-custom h-100">
                <h5 class="text-white fw-bold mb-3"><i class="fa-solid fa-building text-info me-2"></i>Dados da Empresa</h5>
                <div class="mb-3">
                    <label class="form-label small text-secondary">Nome do Provedor / Razão Social</label>
                    <input type="text" name="empresa_nome" class="form-control-custom" value="<?= sanitize($config['empresa_nome'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary">CNPJ</label>
                    <input type="text" name="empresa_cnpj" class="form-control-custom" value="<?= sanitize($config['empresa_cnpj'] ?? '') ?>" placeholder="00.000.000/0001-00">
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary">Telefone / WhatsApp de Suporte</label>
                    <input type="text" name="empresa_telefone" class="form-control-custom" value="<?= sanitize($config['empresa_telefone'] ?? '') ?>" placeholder="82999334425">
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary">Domínio / URL Base do Sistema</label>
                    <input type="url" name="app_url" class="form-control-custom" value="<?= sanitize(!empty($config['app_url']) ? $config['app_url'] : BASE_URL) ?>" placeholder="https://aplicacao.spaconett.com">
                    <small class="text-secondary d-block mt-1">
                        <i class="fa-solid fa-circle-info text-info me-1"></i> Se você mudar o domínio do sistema (ex: para <code>https://mk.spaconett.com</code>), basta alterar aqui. Todos os links de faturas do WhatsApp, agendador e webhooks se adaptarão automaticamente.
                    </small>
                </div>
            </div>
        </div>

        <!-- 3. API WHATSAPP OPENWA -->
        <div class="col-md-6">
            <div class="card-custom h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="text-white fw-bold mb-0"><i class="fa-brands fa-whatsapp text-success me-2"></i>API WhatsApp (Baileys)</h5>
                    <div class="d-flex gap-2">
                        <?php 
                        $waUrlWithToken = !empty($config['wa_api_url']) ? rtrim($config['wa_api_url'], '/') . (!empty($config['wa_token']) ? '/?token=' . urlencode($config['wa_token']) : '/') : '';
                        if (!empty($waUrlWithToken)): ?>
                            <a href="<?= sanitize($waUrlWithToken) ?>" target="_blank" class="btn btn-sm btn-outline-info" title="Abrir Painel de Status /whatsapp/"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Painel API</a>
                        <?php endif; ?>
                        <a href="configuracoes.php?testar_whatsapp=1" class="btn btn-sm btn-outline-success">Testar WhatsApp</a>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary">URL da API OpenWA / Baileys</label>
                    <input type="text" name="wa_api_url" class="form-control-custom" value="<?= sanitize($config['wa_api_url'] ?? 'http://localhost:21465') ?>">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small text-secondary">Sessão</label>
                        <input type="text" name="wa_session" class="form-control-custom" value="<?= sanitize($config['wa_session'] ?? 'default') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small text-secondary">Bearer Token (Secret)</label>
                        <input type="password" name="wa_token" class="form-control-custom" value="<?= sanitize($config['wa_token'] ?? '') ?>">
                    </div>
                </div>
                <div class="mt-2 d-flex gap-2">
                    <a href="disparos_whatsapp.php" class="btn btn-outline-info flex-grow-1 btn-sm"><i class="fa-solid fa-paper-plane me-2"></i>Configurar Agendador & Disparos</a>
                    <?php if (!empty($waUrlWithToken)): ?>
                        <a href="<?= sanitize($waUrlWithToken) ?>" target="_blank" class="btn btn-outline-success btn-sm"><i class="fa-brands fa-whatsapp me-1"></i>Painel /whatsapp/</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 4. TELEGRAM BOT & WEBHOOK CALLBACK (Esquerda) -->
        <div class="col-md-6">
            <!-- 4. TELEGRAM BOT -->
            <div class="card-custom mb-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="text-white fw-bold mb-0"><i class="fa-brands fa-telegram text-info me-2"></i>Notificações Telegram Bot</h5>
                    <a href="configuracoes.php?testar_telegram=1" class="btn btn-sm btn-outline-info">Testar Bot</a>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary">Telegram Bot Token</label>
                    <input type="password" name="telegram_bot_token" class="form-control-custom" value="<?= sanitize($config['telegram_bot_token'] ?? '') ?>" placeholder="123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ">
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary">Chat ID / Grupo ID</label>
                    <input type="text" name="telegram_chat_id" class="form-control-custom" value="<?= sanitize($config['telegram_chat_id'] ?? '') ?>" placeholder="-100123456789">
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="telegram_ativo" id="chkTg" value="1" <?= !empty($config['telegram_ativo']) ? 'checked' : '' ?>>
                    <label class="form-check-label text-secondary" for="chkTg">Ativar avisos de pagamentos no Telegram</label>
                </div>
            </div>

            <!-- 4.5. WEBHOOK CALLBACK EXTERNO (POST) -->
            <div class="card-custom">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="text-white fw-bold mb-0"><i class="fa-solid fa-link text-warning me-2"></i>Webhook Callback Externo (POST)</h5>
                    <button type="button" class="btn btn-sm btn-outline-warning" onclick="testarWebhookCallback()" id="btnTestarWebhook">Testar Webhook</button>
                </div>
                <div id="resultadoWebhookCallback" class="mb-3" style="display:none"></div>
                <div class="mb-3">
                    <label class="form-label small text-secondary">URL de Destino para POST (Ex: MacroDroid, Webhook customizado)</label>
                    <input type="url" name="webhook_callback_url" class="form-control-custom font-monospace" value="<?= sanitize($config['webhook_callback_url'] ?? '') ?>" placeholder="https://exemplo.com/webhook/post">
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="webhook_callback_ativo" id="chkWebhookCallback" value="1" <?= !empty($config['webhook_callback_ativo']) ? 'checked' : '' ?>>
                    <label class="form-check-label text-secondary" for="chkWebhookCallback">Ativar Webhook Callback (POST no pagamento)</label>
                </div>
                <small class="text-secondary d-block mt-3">
                    <i class="fa-solid fa-circle-info me-1 text-info"></i>
                    Envia um POST JSON para a URL configurada sempre que um pagamento for aprovado (via caixa ou Mercado Pago).
                </small>
            </div>
        </div>

        <!-- 5. MACRODROID & CHAVE PIX ESTATICA -->
        <div class="col-md-6">
            <div class="card-custom h-100">
                <h5 class="text-white fw-bold mb-3"><i class="fa-solid fa-robot text-warning me-2"></i>MacroDroid & Chave PIX Estática</h5>

                <label class="form-label small text-secondary fw-bold">URLs para o MacroDroid (Geração de PIX):</label>
                <div class="mb-2">
                    <small class="text-info">Por nome do cliente:</small>
                    <input type="text" class="form-control-custom text-warning font-monospace mb-1" readonly
                           value="<?= BASE_URL ?>/payment/pix.php?client_name=NOME_DO_CLIENTE">
                </div>
                <div class="mb-3">
                    <small class="text-info">Por usuário PPPoE (mais preciso):</small>
                    <input type="text" class="form-control-custom text-warning font-monospace mb-1" readonly
                           value="<?= BASE_URL ?>/payment/pix.php?pppoe=USUARIO_PPPOE">
                </div>

                <label class="form-label small text-secondary fw-bold">URLs para o MacroDroid (Consulta de Status/Pagamento):</label>
                <div class="mb-2">
                    <small class="text-info">Por nome do cliente:</small>
                    <input type="text" class="form-control-custom text-warning font-monospace mb-1" readonly
                           value="<?= BASE_URL ?>/payment/pix.php?client_name=NOME_DO_CLIENTE&action=consultar">
                </div>
                <div class="mb-3">
                    <small class="text-info">Por usuário PPPoE:</small>
                    <input type="text" class="form-control-custom text-warning font-monospace mb-1" readonly
                           value="<?= BASE_URL ?>/payment/pix.php?pppoe=USUARIO_PPPOE&action=consultar">
                </div>

                <small class="text-secondary d-block mb-3">
                    <i class="fa-solid fa-circle-info me-1 text-info"></i>
                    A geração retorna <code>qr_code</code> (copia e cola). A consulta retorna o status completo do cliente e da fatura do mês atual em JSON.
                </small>

                <div class="mb-3">
                    <label class="form-label small text-secondary">Chave PIX Estática (Fallback sem MP)</label>
                    <input type="text" name="pix_chave_estatica" class="form-control-custom"
                           value="<?= sanitize($config['pix_chave_estatica'] ?? '') ?>" placeholder="financeiro@spaconett.com">
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small text-secondary">Nome Beneficiário</label>
                        <input type="text" name="pix_nome_beneficiario" class="form-control-custom"
                               value="<?= sanitize($config['pix_nome_beneficiario'] ?? '') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small text-secondary">Cidade</label>
                        <input type="text" name="pix_cidade_beneficiario" class="form-control-custom"
                               value="<?= sanitize($config['pix_cidade_beneficiario'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- 6. CONFIGURAÇÕES DE E-MAIL (SMTP) -->
        <div class="col-md-6">
            <div class="card-custom h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="text-white fw-bold mb-0"><i class="fa-solid fa-envelope text-info me-2"></i>E-mail SMTP (Recuperação de Senha / 2FA)</h5>
                    <button type="button" class="btn btn-sm btn-outline-info" onclick="testarSmtp()" id="btnTestarSmtp">
                        <i class="fa-solid fa-paper-plane me-1"></i> Testar Envio
                    </button>
                </div>
                <div id="resultadoSmtp" class="mb-3" style="display:none"></div>
                <div class="mb-3">
                    <label class="form-label small text-secondary">Servidor SMTP (Host)</label>
                    <input type="text" name="smtp_host" class="form-control-custom" value="<?= sanitize($config['smtp_host'] ?? '') ?>" placeholder="smtp.gmail.com ou smtp.spaconett.com">
                    <small class="text-secondary d-block mt-1">Deixe em branco para usar o <code>mail()</code> nativo do servidor.</small>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <label class="form-label small text-secondary">Porta</label>
                        <input type="number" name="smtp_port" class="form-control-custom" value="<?= (int)($config['smtp_port'] ?? 587) ?>" placeholder="587">
                        <small class="text-secondary" style="font-size:10px;">587=TLS / 465=SSL</small>
                    </div>
                    <div class="col-8">
                        <label class="form-label small text-secondary">E-mail Remetente (From)</label>
                        <input type="email" name="smtp_from" class="form-control-custom" value="<?= sanitize($config['smtp_from'] ?? '') ?>" placeholder="no-reply@spaconett.com">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary">Usuário SMTP</label>
                    <input type="text" name="smtp_user" class="form-control-custom" value="<?= sanitize($config['smtp_user'] ?? '') ?>" placeholder="ex: spaconet@spaconett.com ou seu-email@gmail.com">
                    <small class="text-secondary d-block mt-1"><i class="fa-solid fa-circle-info text-info me-1"></i>Para cPanel / Webmail próprio: digite o <strong>endereço de e-mail completo</strong> (ex: <code>spaconet@spaconett.com</code>), e não apenas o usuário do cPanel.</small>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary">Senha SMTP (App Password)</label>
                    <input type="password" name="smtp_pass" class="form-control-custom" value="<?= sanitize($config['smtp_pass'] ?? '') ?>" placeholder="••••••••••••">
                    <small class="text-secondary d-block mt-1"><i class="fa-solid fa-circle-info text-warning me-1"></i>Para Gmail: use <a href="https://myaccount.google.com/apppasswords" target="_blank" class="text-info">App Password</a> (não a senha da conta).</small>
                </div>
            </div>
        </div>

        <!-- 7. GOOGLE reCAPTCHA v2 -->
        <div class="col-md-6">
            <div class="card-custom h-100">
                <h5 class="text-white fw-bold mb-3"><i class="fa-brands fa-google text-success me-2"></i>Google reCAPTCHA v2</h5>
                <p class="text-secondary small mb-3">
                    O reCAPTCHA protege as páginas de login (admin e cliente) contra bots e ataques de força bruta automatizados.
                    Obtenha as chaves em <a href="https://www.google.com/recaptcha/admin/create" target="_blank" class="text-info">google.com/recaptcha</a>.
                </p>
                <div class="mb-3">
                    <label class="form-label small text-secondary">Site Key (Chave Pública)</label>
                    <input type="text" name="recaptcha_site_key" class="form-control-custom font-monospace" value="<?= sanitize($config['recaptcha_site_key'] ?? '') ?>" placeholder="6LcXXXXXXXXXXXXXXXXXXXXXXXXXXXX">
                    <small class="text-secondary d-block mt-1">Usada no HTML da página (visível ao usuário).</small>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary">Secret Key (Chave Secreta)</label>
                    <input type="password" name="recaptcha_secret_key" class="form-control-custom font-monospace" value="<?= sanitize($config['recaptcha_secret_key'] ?? '') ?>" placeholder="6LcXXXXXXXXXXXXXXXXXXXXXXXXXXXX">
                    <small class="text-secondary d-block mt-1">Usada no servidor para validar as respostas.</small>
                </div>
                <div class="alert alert-info py-2 mb-0">
                    <i class="fa-solid fa-circle-info me-1"></i>
                    <strong>Como funciona:</strong> Se as chaves estiverem configuradas, o widget aparece automaticamente nas páginas de login. Se estiverem vazias, o reCAPTCHA é ignorado.
                </div>
            </div>
        </div>

        <div class="col-12 text-end">
            <button type="submit" class="btn btn-primary-custom btn-lg">
                <i class="fa-solid fa-floppy-disk me-2"></i> Salvar Todas as Configurações
            </button>
        </div>
    </div>
</form>


<script>
async function testarMP() {
    const btn = document.getElementById('btnTestarMP');
    const res = document.getElementById('resultadoTesteMP');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Testando...';
    res.style.display = 'none';
    try {
        const r = await fetch('configuracoes.php?ajax=testar_mp');
        const d = await r.json();
        res.style.display = 'block';
        res.innerHTML = d.success
            ? '<div class="alert alert-success py-2 mb-0"><i class="fa-solid fa-circle-check me-2"></i><strong>Token OK!</strong> ' + d.message + '</div>'
            : '<div class="alert alert-danger py-2 mb-0"><i class="fa-solid fa-triangle-exclamation me-2"></i><strong>Falhou:</strong> ' + d.message + '</div>';
    } catch(e) {
        res.style.display = 'block';
        res.innerHTML = '<div class="alert alert-danger py-2 mb-0">Erro de conexão: ' + e.message + '</div>';
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-bolt me-1"></i> Testar Credenciais';
}

async function testarWebhookCallback() {
    const btn = document.getElementById('btnTestarWebhook');
    const res = document.getElementById('resultadoWebhookCallback');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Testando...';
    res.style.display = 'none';
    try {
        const r = await fetch('configuracoes.php?ajax=testar_webhook_callback');
        const d = await r.json();
        res.style.display = 'block';
        res.innerHTML = d.success
            ? '<div class="alert alert-success py-2 mb-0"><i class="fa-solid fa-circle-check me-2"></i><strong>Sucesso!</strong> ' + d.message + '</div>'
            : '<div class="alert alert-danger py-2 mb-0"><i class="fa-solid fa-triangle-exclamation me-2"></i><strong>Erro:</strong> ' + d.message + '</div>';
    } catch(e) {
        res.style.display = 'block';
        res.innerHTML = '<div class="alert alert-danger py-2 mb-0">Erro de conexão: ' + e.message + '</div>';
    }
    btn.disabled = false;
    btn.innerHTML = 'Testar Webhook';
}

async function testarSmtp() {
    const btn = document.getElementById('btnTestarSmtp');
    const res = document.getElementById('resultadoSmtp');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Enviando...';
    res.style.display = 'none';
    try {
        const r = await fetch('configuracoes.php?ajax=testar_smtp');
        const d = await r.json();
        res.style.display = 'block';
        res.innerHTML = d.success
            ? '<div class="alert alert-success py-2 mb-0"><i class="fa-solid fa-circle-check me-2"></i><strong>Enviado!</strong> ' + d.message + '</div>'
            : '<div class="alert alert-danger py-2 mb-0"><i class="fa-solid fa-triangle-exclamation me-2"></i><strong>Falhou:</strong> ' + d.message + '</div>';
    } catch(e) {
        res.style.display = 'block';
        res.innerHTML = '<div class="alert alert-danger py-2 mb-0">Erro de conexão: ' + e.message + '</div>';
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i> Testar Envio';
}
</script>
<?php require_once __DIR__ . '/footer.php'; ?>


