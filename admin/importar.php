<?php
require_once __DIR__ . '/header.php';

$db = Database::getInstance();
$msgSuccess = '';
$msgError = '';

$mkApi = new MikrotikAPI();
$secretsMikrotik = $mkApi->getSecrets();
$planosDB = $db->query("SELECT * FROM planos")->fetchAll();

function extrairBaseProfile($profile) {
    $profile = trim($profile);
    if (empty($profile)) return '';
    $partes = explode('/', $profile);
    return $partes[0];
}

// Processar Importação em Lote - MESMA LÓGICA VISUAL, MAS DETECÇÃO CORRIGIDA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'importar_selecionados') {
    $selecionados = $_POST['import_users'] ?? [];
    $importados = 0;

    if (!empty($selecionados)) {
        foreach ($selecionados as $username) {
            $secretTarget = null;
            foreach ($secretsMikrotik as $s) {
                if (($s['name'] ?? '') === $username) {
                    $secretTarget = $s;
                    break;
                }
            }

            if ($secretTarget) {
                $user = sanitize($secretTarget['name']);
                $pass = $secretTarget['password'] ?? '123456';
                $profile = $secretTarget['profile'] ?? 'default';

                // === LÓGICA 100% AUTOMÁTICA CORRIGIDA ===
                // Não precisa mudar profile no MikroTik, trata /Pago = /Espera = /Aviso = /Bloqueado pela base
                $planoId = null;
                $baseSecret = extrairBaseProfile($profile);

                foreach ($planosDB as $pl) {
                    // Compara base de todos os 3 profiles do plano
                    $basesPlano = [
                        extrairBaseProfile($pl['profile_mikrotik'] ?? ''),
                        extrairBaseProfile($pl['profile_aviso'] ?? ''),
                        extrairBaseProfile($pl['profile_bloqueado'] ?? '')
                    ];
                    foreach ($basesPlano as $basePlano) {
                        if (empty($basePlano)) continue;
                        // Se base igual: S1-R.NORMAL-01 == S1-R.NORMAL-01 (independente de /Pago ou /Espera)
                        if ($basePlano === $baseSecret) {
                            $planoId = $pl['id'];
                            break 2;
                        }
                    }
                }

                // Fallback por prefixo S1, S2 se ainda não achou
                if (!$planoId && preg_match('/^(S\d+)/', $baseSecret, $m)) {
                    $grupo = $m[1];
                    foreach ($planosDB as $pl) {
                        if (($pl['prefixo_grupo'] ?? '') === $grupo) {
                            // Pega primeiro do grupo como fallback, ou tenta match por tipo
                            if (empty($planoId)) $planoId = $pl['id'];
                            if (strpos($profile, $pl['prefixo_grupo']) !== false && stripos($pl['nome'], substr($baseSecret, 3)) !== false) {
                                $planoId = $pl['id'];
                                break;
                            }
                        }
                    }
                }

                // Detectar dia de vencimento a partir do profile (ex: S1-R.NORMAL-20/Espera -> 20)
                $vencimentoDia = 10;
                if (preg_match('/-(\d{1,2})(?:\/|$)/', $profile, $matches)) {
                    $vencimentoDia = (int)$matches[1];
                }

                $cpfFicticio = '999' . sprintf('%08d', rand(10000000, 99999999));
                $waFicticio = '829' . sprintf('%08d', rand(10000000, 99999999));

                try {
                    $stmtIns = $db->prepare("
                        INSERT INTO clientes (nome, cpf_cnpj, whatsapp, pppoe_usuario, pppoe_senha, plano_id, status, vencimento_dia, sincronizado_mikrotik) 
                        VALUES (?, ?, ?, ?, ?, ?, 'ativo', ?, 1)
                        ON DUPLICATE KEY UPDATE pppoe_senha = VALUES(pppoe_senha), plano_id = COALESCE(VALUES(plano_id), plano_id), vencimento_dia = VALUES(vencimento_dia), sincronizado_mikrotik = 1
                    ");
                    $stmtIns->execute([$user, $cpfFicticio, $waFicticio, $user, $pass, $planoId, $vencimentoDia]);
                    $importados++;
                } catch (Exception $e) {
                }
            }
        }
        $msgSuccess = "{$importados} assinantes importados/sincronizados com plano detectado automaticamente!";
    } else {
        $msgError = "Selecione ao menos um usuário para importar.";
    }
}

$usuariosExistentes = $db->query("SELECT pppoe_usuario FROM clientes")->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="text-white fw-bold mb-1"><i class="fa-solid fa-cloud-arrow-down text-info me-2"></i>Importação Automática do MikroTik</h4>
        <p class="text-secondary small mb-0">Importe os usuários PPPoE/Hotspot diretamente do seu RouterOS para a base de dados.</p>
    </div>
    <a href="clientes.php" class="btn btn-outline-light">
        <i class="fa-solid fa-arrow-left me-1"></i> Voltar para Clientes
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

<form method="POST" action="">
    <input type="hidden" name="action" value="importar_selecionados">
    
    <div class="card-custom mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="text-white fw-bold mb-0"><i class="fa-solid fa-list-check me-2 text-info"></i>Secrets Encontrados no RouterOS</h5>
            <button type="submit" class="btn btn-primary-custom">
                <i class="fa-solid fa-download me-1"></i> Importar Selecionados para o Banco
            </button>
        </div>

        <div class="table-responsive">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th width="40"><input type="checkbox" id="selectAll"></th>
                        <th>Usuário PPPoE (Name)</th>
                        <th>Senha</th>
                        <th>Profile Atual no RouterOS</th>
                        <th>Comentário</th>
                        <th>Status no Sistema</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($secretsMikrotik)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-secondary">
                                <i class="fa-solid fa-plug-circle-xmark fs-3 text-warning mb-2 d-block"></i>
                                Nenhum secret encontrado no MikroTik ou erro de conexão. Verifique o menu Roteadores.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($secretsMikrotik as $sec): ?>
                            <?php 
                            $name = sanitize($sec['name'] ?? '');
                            $jaExiste = in_array($name, $usuariosExistentes);
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="import_users[]" value="<?= $name ?>" class="user-checkbox" <?= $jaExiste ? '' : 'checked' ?>>
                                </td>
                                <td><code class="text-info fw-bold"><?= $name ?></code></td>
                                <td><code><?= sanitize($sec['password'] ?? '******') ?></code></td>
                                <td>
                                    <span class="badge bg-dark border border-secondary"><?= sanitize($sec['profile'] ?? 'default') ?></span>
                                </td>
                                <td class="text-secondary small"><?= sanitize($sec['comment'] ?? '-') ?></td>
                                <td>
                                    <?php if ($jaExiste): ?>
                                        <span class="badge bg-success bg-opacity-20 text-success border border-success"><i class="fa-solid fa-check me-1"></i>Já Cadastrado</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning bg-opacity-20 text-warning border border-warning"><i class="fa-solid fa-plus me-1"></i>Pronto p/ Importar</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = selectAll.checked);
        });
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
