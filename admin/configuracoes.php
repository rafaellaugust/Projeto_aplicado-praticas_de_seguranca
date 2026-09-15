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
} catch (Exception $e) {}

// Testar Credenciais do Mercado Pago — via AJAX (ajax=1) para não travar a página
if (isset($_GET['ajax']) && $_GET['ajax'] === 'testar_mp') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $gateway = new PaymentGateway();
        $resMP   = $gateway->testToken(); // método correto em PaymentGateway.php
        echo json_encode($resMP);
    } catch (Exception $e) {
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
            echo json_encode(['success' => true, 'message' => "Webhook OK! Resposta HTTP {$httpCode} recebida."]);
        } else {
            echo json_encode(['success' => false, 'message' => "Erro de conexão HTTP {$httpCode}. Retorno: " . substr($response, 0, 150)]);
        }
    } catch (Exception $e) {
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

    try {
        $stmt = $db->prepare("
            UPDATE configuracoes SET 
                empresa_nome = ?, empresa_cnpj = ?, empresa_telefone = ?, app_url = ?,
                wa_api_url = ?, wa_session = ?, wa_token = ?,
                telegram_bot_token = ?, telegram_chat_id = ?, telegram_ativo = ?,
                gateway_provider = ?, mp_public_key = ?, mp_access_token = ?, mp_client_id = ?, mp_client_secret = ?, mp_sandbox = ?,
                pix_chave_estatica = ?, pix_nome_beneficiario = ?, pix_cidade_beneficiario = ?,
                webhook_secret = ?,
                webhook_callback_url = ?, webhook_callback_ativo = ?
            WHERE id = 1
        ");
        $stmt->execute([
            $empresaNome, $empresaCnpj, $empresaTelefone, $appUrl,
            $waApiUrl, $waSession, $waToken,
            $telegramToken, $telegramChatId, $telegramAtivo,
            $gatewayProvider, $mpPublicKey, $mpAccessToken, $mpClientId, $mpClientSecret, $mpSandbox,
            $pixChaveEstatica, $pixNomeBeneficiario, $pixCidadeBeneficiario,
            $webhookSecret,
            $webhookCallbackUrl, $webhookCallbackAtivo
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

        $msgSuccess = "Todas as configurações foram salvas com sucesso!";
    } catch (Exception $e) {
        $msgError = "Erro ao salvar configurações: " . $e->getMessage();
    }
}


// Testar Telegram
if (isset($_GET['testar_telegram']) && $_GET['testar_telegram'] === '1') {
    $tg = new TelegramService();
    $res = $tg->sendMessage("🔔 <b>TESTE DE INTEGRAÇÃO TELEGRAM</b>\n\nSua plataforma MikroTik Pay está conectada com sucesso ao Telegram Bot!");
    if ($res) {
        $msgSuccess = "Mensagem de teste enviada com sucesso no Telegram!";
    } else {
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
                $msgSuccess = "Conexão com a API OK! Mensagem de teste enviada para o WhatsApp da Empresa (" . $telefoneDestino . ").";
            } else {
                $msgError = "API do WhatsApp respondeu, mas falhou ao enviar a mensagem. Verifique se o número da empresa (" . $telefoneDestino . ") está correto e ativo no WhatsApp.";
            }
        } else {
            $msgSuccess = "Conexão com a API do WhatsApp estabelecida com sucesso! (Cadastre um telefone em 'Dados da Empresa' para receber uma mensagem de teste no seu celular).";
        }
    } else {
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
                        <?php if (!empty($config['wa_api_url'])): ?>
                            <a href="<?= sanitize($config['wa_api_url']) ?>" target="_blank" class="btn btn-sm btn-outline-info" title="Abrir Painel de Status /whatsapp/"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Painel API</a>
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
                    <?php if (!empty($config['wa_api_url'])): ?>
                        <a href="<?= sanitize($config['wa_api_url']) ?>" target="_blank" class="btn btn-outline-success btn-sm"><i class="fa-brands fa-whatsapp me-1"></i>Painel /whatsapp/</a>
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
</script>
<?php require_once __DIR__ . '/footer.php'; ?>

