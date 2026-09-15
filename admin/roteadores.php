<?php
require_once __DIR__ . '/header.php';

$db = Database::getInstance();
$msgSuccess = '';
$msgError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrfToken()) {
        $msgError = "Token de segurança inválido ou sessão expirada.";
    } else {
        $nome = sanitize($_POST['nome'] ?? 'MikroTik Principal');
        $ipHost = sanitize($_POST['ip_host'] ?? '');
        $portaApi = (int)($_POST['porta_api'] ?? 8728);
        $usuario = sanitize($_POST['usuario'] ?? '');
        $senha = $_POST['senha'] ?? '';
        $usarSsl = isset($_POST['usar_ssl']) ? 1 : 0;

        if ($ipHost && $usuario) {
            $stmt = $db->prepare("
                UPDATE roteadores SET nome = ?, ip_host = ?, porta_api = ?, usuario = ?, senha = ?, usar_ssl = ? 
                WHERE id = 1
            ");
            $stmt->execute([$nome, $ipHost, $portaApi, $usuario, $senha, $usarSsl]);
            $msgSuccess = "Configurações do MikroTik salvas com sucesso!";
        } else {
            $msgError = "Preencha o IP/Host e o Usuário de acesso.";
        }
    }
}

// Teste de conexão dinâmico se solicitado
$testeResultado = null;
if (isset($_GET['testar']) && $_GET['testar'] === '1') {
    $mkApi = new MikrotikAPI();
    $testeResultado = $mkApi->testConnection();
}

$stmtR = $db->query("SELECT * FROM roteadores WHERE id = 1");
$router = $stmtR->fetch();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="text-white fw-bold mb-1"><i class="fa-solid fa-server text-info me-2"></i>Conexão MikroTik RouterOS</h4>
        <p class="text-secondary small mb-0">Configure os parâmetros de API para conectar a plataforma ao seu roteador principal.</p>
    </div>
    <a href="roteadores.php?testar=1" class="btn btn-outline-info">
        <i class="fa-solid fa-plug me-1"></i> Testar Conexão Agora
    </a>
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

<?php if ($testeResultado !== null): ?>
    <?php if ($testeResultado): ?>
        <div class="alert alert-success d-flex align-items-center gap-2 mb-4" role="alert">
            <i class="fa-solid fa-circle-check fs-4"></i>
            <div>
                <strong>Conexão Estabelecida com Sucesso!</strong><br>
                O sistema conseguiu autenticar na API Socket do MikroTik RouterOS.
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 mb-4" role="alert">
            <i class="fa-solid fa-circle-xmark fs-4"></i>
            <div>
                <strong>Falha na Conexão com o MikroTik!</strong><br>
                Verifique se o serviço IP -> API está ativado no Winbox, se o IP/Porta estão corretos e se a senha está certa.
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="row">
    <div class="col-md-8">
        <div class="card-custom">
            <form method="POST" action="">
                <?= Security::generateCsrfToken() ?>
                <div class="mb-3">
                    <label class="form-label small text-secondary">Nome do Roteador</label>
                    <input type="text" name="nome" class="form-control-custom" value="<?= sanitize($router['nome'] ?? 'MikroTik Principal') ?>">
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-8">
                        <label class="form-label small text-secondary">Endereço IP ou Host (Domain / DDNS) *</label>
                        <input type="text" name="ip_host" class="form-control-custom" required value="<?= sanitize($router['ip_host'] ?? '192.168.88.1') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-secondary">Porta da API *</label>
                        <input type="number" name="porta_api" class="form-control-custom" required value="<?= (int)($router['porta_api'] ?? 8728) ?>">
                        <small class="text-secondary">Padrão: 8728 (Sem SSL) ou 8729 (SSL)</small>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small text-secondary">Usuário da API RouterOS *</label>
                        <input type="text" name="usuario" class="form-control-custom" required value="<?= sanitize($router['usuario'] ?? 'admin') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-secondary">Senha da API</label>
                        <input type="password" name="senha" class="form-control-custom" value="<?= sanitize($router['senha'] ?? '') ?>">
                    </div>
                </div>
                <div class="mb-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="usar_ssl" id="sslSwitch" value="1" <?= !empty($router['usar_ssl']) ? 'checked' : '' ?>>
                        <label class="form-check-label text-secondary" for="sslSwitch">Conexão Segura via SSL (API-SSL porta 8729)</label>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary-custom">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Salvar Configurações do MikroTik
                </button>
            </form>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card-custom bg-dark border-secondary">
            <h6 class="text-info fw-bold mb-3"><i class="fa-solid fa-circle-info me-2"></i>Instruções de Configuração no MikroTik</h6>
            <ol class="text-secondary small ps-3">
                <li class="mb-2">Acesse seu MikroTik via Winbox.</li>
                <li class="mb-2">Vá no menu <strong>IP -> Services</strong>.</li>
                <li class="mb-2">Certifique-se de que o serviço <strong>api</strong> esteja <code>enabled</code> na porta 8728.</li>
                <li class="mb-2">Vá em <strong>System -> Users</strong> e certifique-se de que o usuário pertença ao grupo <code>full</code> ou tenha permissões de <code>api, read, write</code>.</li>
                <li>Note: A plataforma adiciona/edita os PPPoE secrets e lê os profiles sem interferir no modo de bloqueio e datas já em funcionamento no seu RouterOS!</li>
            </ol>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
