<?php
require_once __DIR__ . '/header.php';

$db = Database::getInstance();
$msgSuccess = '';
$msgError = '';

try {
    $db->exec("UPDATE clientes SET whatsapp = SUBSTRING(whatsapp, 3) WHERE (LENGTH(whatsapp) = 12 OR LENGTH(whatsapp) = 13) AND whatsapp LIKE '55%'");
} catch (Exception $e) {}

// 1. Cadastrar Novo Cliente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cadastrar') {
    $nome = sanitize($_POST['nome'] ?? '');
    $cpfCnpj = sanitize($_POST['cpf_cnpj'] ?? '');
    $whatsapp = WhatsAppService::sanitizePhone($_POST['whatsapp'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $endereco = sanitize($_POST['endereco'] ?? '');
    $pppoeUsuario = sanitize($_POST['pppoe_usuario'] ?? '');
    $pppoeSenha = $_POST['pppoe_senha'] ?? '';
    $planoId = !empty($_POST['plano_id']) ? (int)$_POST['plano_id'] : null;
    $vencimentoDia = (int)($_POST['vencimento_dia'] ?? 10);
    $sincronizarMikrotik = isset($_POST['sincronizar_mikrotik']) ? 1 : 0;

    if ($nome && $cpfCnpj && $whatsapp && $pppoeUsuario) {
        try {
            $stmt = $db->prepare("
                INSERT INTO clientes (nome, cpf_cnpj, whatsapp, email, endereco, pppoe_usuario, pppoe_senha, plano_id, vencimento_dia, sincronizado_mikrotik) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nome, $cpfCnpj, $whatsapp, $email, $endereco, $pppoeUsuario, $pppoeSenha, $planoId, $vencimentoDia, $sincronizarMikrotik]);
            $clienteId = $db->lastInsertId();

            if ($sincronizarMikrotik && $planoId) {
                $stmtP = $db->prepare("SELECT profile_mikrotik FROM planos WHERE id = ?");
                $stmtP->execute([$planoId]);
                $plano = $stmtP->fetch();
                $profileName = $plano['profile_mikrotik'] ?? 'default';

                $mkApi = new MikrotikAPI();
                $mkApi->syncSecret($pppoeUsuario, $pppoeSenha, $profileName, "Cliente #{$clienteId} - {$nome}");
            }

            $msgSuccess = "Cliente {$nome} cadastrado com sucesso!";
        } catch (Exception $e) {
            $msgError = "Erro ao cadastrar cliente: " . $e->getMessage();
        }
    } else {
        $msgError = "Preencha todos os campos obrigatórios.";
    }
}

// 2. Editar Cliente Existente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'editar') {
    $id = (int)$_POST['cliente_id'];
    $nome = sanitize($_POST['nome'] ?? '');
    $cpfCnpj = sanitize($_POST['cpf_cnpj'] ?? '');
    $whatsapp = WhatsAppService::sanitizePhone($_POST['whatsapp'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $endereco = sanitize($_POST['endereco'] ?? '');
    $pppoeUsuario = sanitize($_POST['pppoe_usuario'] ?? '');
    $pppoeSenha = $_POST['pppoe_senha'] ?? '';
    $planoId = !empty($_POST['plano_id']) ? (int)$_POST['plano_id'] : null;
    $vencimentoDia = (int)($_POST['vencimento_dia'] ?? 10);
    $status = sanitize($_POST['status'] ?? 'ativo');

    if ($id && $nome) {
        try {
            // 1. Obter dados atuais do cliente ANTES de atualizar
            $stmtOld = $db->prepare("SELECT * FROM clientes WHERE id = ?");
            $stmtOld->execute([$id]);
            $cliAtual = $stmtOld->fetch(PDO::FETCH_ASSOC);

            if (!$cliAtual) {
                throw new Exception("Cliente não encontrado.");
            }

            $statusMudou  = ($cliAtual['status'] !== $status);
            $planoMudou   = ((int)$cliAtual['plano_id'] !== (int)$planoId);
            $usuarioMudou = ($cliAtual['pppoe_usuario'] !== $pppoeUsuario);
            $senhaMudou   = ($cliAtual['pppoe_senha'] !== $pppoeSenha);

            // 2. Atualizar no banco de dados
            $stmt = $db->prepare("
                UPDATE clientes SET 
                    nome = ?, cpf_cnpj = ?, whatsapp = ?, email = ?, endereco = ?, 
                    pppoe_usuario = ?, pppoe_senha = ?, plano_id = ?, vencimento_dia = ?, status = ? 
                WHERE id = ?
            ");
            $stmt->execute([$nome, $cpfCnpj, $whatsapp, $email, $endereco, $pppoeUsuario, $pppoeSenha, $planoId, $vencimentoDia, $status, $id]);

            // 3. Sincronização inteligente com o MikroTik
            $roteadorId = !empty($cliAtual['roteador_id']) ? (int)$cliAtual['roteador_id'] : 1;
            $mkApi = new MikrotikAPI($roteadorId);

            if ($statusMudou || $planoMudou) {
                // Apenas se o status ou o plano REALMENTE mudaram no formulário
                if ($planoId) {
                    $stmtP = $db->prepare("SELECT profile_mikrotik, profile_bloqueado, profile_aviso FROM planos WHERE id = ?");
                    $stmtP->execute([$planoId]);
                    $plano = $stmtP->fetch(PDO::FETCH_ASSOC);

                    if ($status === 'suspenso') {
                        $targetProfile = $plano['profile_bloqueado'] ?: 'default';
                        $mkApi->changeSecretProfile($pppoeUsuario, $targetProfile);
                        $mkApi->disconnectActiveSession($pppoeUsuario);
                    } elseif ($status === 'aviso') {
                        $targetProfile = $plano['profile_aviso'] ?: $plano['profile_mikrotik'] ?: 'default';
                        $mkApi->changeSecretProfile($pppoeUsuario, $targetProfile);
                    } else {
                        // Status mudou para ativo: tenta preservar o padrão do profile (ex: S1-40Megas-20) se estava bloqueado/aviso
                        $perfilAtualMk = $mkApi->getClientProfile($cliAtual['pppoe_usuario']);
                        if ($perfilAtualMk && (stripos($perfilAtualMk, 'bloqueado') !== false || stripos($perfilAtualMk, 'aviso') !== false)) {
                            $targetProfile = str_ireplace(
                                ['/Bloqueado', '-Bloqueado', 'Bloqueado', '/Aviso', '-Aviso', 'Aviso'],
                                ['/Espera', '-Espera', 'Espera', '/Espera', '-Espera', 'Espera'],
                                $perfilAtualMk
                            );
                        } else {
                            $targetProfile = $plano['profile_mikrotik'] ?: 'default';
                        }
                        $mkApi->changeSecretProfile($pppoeUsuario, $targetProfile);
                        if ($cliAtual['status'] === 'suspenso') {
                            $mkApi->disconnectActiveSession($pppoeUsuario);
                        }
                    }
                }
            } elseif ($usuarioMudou || $senhaMudou) {
                // Status e plano NÃO mudaram. Apenas usuário ou senha mudaram.
                // Atualiza mantendo RIGOROSAMENTE o perfil que o cliente já possui no MikroTik!
                $usuarioAntigo = $cliAtual['pppoe_usuario'];
                $perfilAtualMk = $mkApi->getClientProfile($usuarioAntigo);
                if (!$perfilAtualMk && $usuarioMudou) {
                    $perfilAtualMk = $mkApi->getClientProfile($pppoeUsuario);
                }
                if (!$perfilAtualMk && $planoId) {
                    $stmtP = $db->prepare("SELECT profile_mikrotik, profile_bloqueado FROM planos WHERE id = ?");
                    $stmtP->execute([$planoId]);
                    $plano = $stmtP->fetch(PDO::FETCH_ASSOC);
                    $perfilAtualMk = ($status === 'suspenso') ? ($plano['profile_bloqueado'] ?: 'default') : ($plano['profile_mikrotik'] ?: 'default');
                }

                if ($perfilAtualMk) {
                    if ($usuarioMudou) {
                        $mkApi->removeSecret($usuarioAntigo);
                    }
                    $mkApi->syncSecret($pppoeUsuario, $pppoeSenha, $perfilAtualMk, "Cliente #{$id} - {$nome}");
                }
            }
            // Se apenas nome, whatsapp, cpf, email, endereco ou vencimento mudaram:
            // O perfil no MikroTik NUNCA é sobrescrito! O perfil personalizado permanece 100% intacto.

            $msgSuccess = "Cliente #{$id} atualizado com sucesso!";
        } catch (Exception $e) {
            $msgError = "Erro ao atualizar cliente: " . $e->getMessage();
        }
    }
}

// 3. Excluir Cliente
if (isset($_GET['action']) && $_GET['action'] === 'excluir' && !empty($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmtC = $db->prepare("SELECT pppoe_usuario FROM clientes WHERE id = ?");
    $stmtC->execute([$id]);
    $cli = $stmtC->fetch();

    if ($cli) {
        $mkApi = new MikrotikAPI();
        $mkApi->removeSecret($cli['pppoe_usuario']);

        $stmtDel = $db->prepare("DELETE FROM clientes WHERE id = ?");
        $stmtDel->execute([$id]);
        $msgSuccess = "Cliente removido com sucesso.";
    }
}

$planos = $db->query("SELECT * FROM planos ORDER BY nome ASC")->fetchAll();

// --- CORREÇÃO FILTROS SEM MUDAR DESIGN ---
$filterBusca = sanitize($_GET['busca'] ?? '');
$filterClienteId = (int)($_GET['cliente_id'] ?? 0);

$where = "";
$params = [];
if ($filterClienteId > 0) {
    $where = "WHERE c.id = ?";
    $params[] = $filterClienteId;
} elseif (!empty($filterBusca)) {
    $where = "WHERE (c.nome LIKE ? OR c.pppoe_usuario LIKE ? OR c.cpf_cnpj LIKE ? OR c.whatsapp LIKE ?)";
    $params[] = "%$filterBusca%";
    $params[] = "%$filterBusca%";
    $params[] = "%$filterBusca%";
    $params[] = "%$filterBusca%";
}

$sqlClientes = "
    SELECT c.*, p.nome as plano_nome, p.valor as plano_valor 
    FROM clientes c 
    LEFT JOIN planos p ON c.plano_id = p.id 
    $where
    ORDER BY c.id DESC
";
if (!empty($params)) {
    $stmt = $db->prepare($sqlClientes);
    $stmt->execute($params);
    $clientes = $stmt->fetchAll();
} else {
    $clientes = $db->query($sqlClientes)->fetchAll();
}

?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="text-white fw-bold mb-1"><i class="fa-solid fa-users text-info me-2"></i>Gestão de Clientes</h4>
        <p class="text-secondary small mb-0">Cadastre, edite e sincronize os assinantes com o MikroTik RouterOS.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="importar.php" class="btn btn-outline-info">
            <i class="fa-solid fa-cloud-arrow-down me-1"></i> Importar do MikroTik
        </a>
        <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalNovoCliente">
            <i class="fa-solid fa-user-plus me-1"></i> Novo Cliente
        </button>
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

<!-- Tabela de Clientes -->
<div class="card-custom">
    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th># ID</th>
                    <th>Nome / CPF</th>
                    <th>WhatsApp</th>
                    <th>Usuário PPPoE</th>
                    <th>Plano Contratado</th>
                    <th>Vencimento</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clientes)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-secondary">Nenhum cliente cadastrado ainda.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($clientes as $c): ?>
                        <tr>
                            <td>#<?= $c['id'] ?></td>
                            <td>
                                <strong><?= sanitize($c['nome']) ?></strong><br>
                                <span class="text-secondary small">CPF: <?= sanitize($c['cpf_cnpj']) ?></span>
                            </td>
                            <td>
                                <a href="https://wa.me/<?= WhatsAppService::formatPhone($c['whatsapp']) ?>" target="_blank" class="text-success text-decoration-none">
                                    <i class="fa-brands fa-whatsapp me-1"></i><?= sanitize(WhatsAppService::sanitizePhone($c['whatsapp'])) ?>
                                </a>
                            </td>
                            <td><code class="text-info"><?= sanitize($c['pppoe_usuario']) ?></code></td>
                            <td>
                                <?= sanitize($c['plano_nome'] ?? 'Sem Plano') ?><br>
                                <small class="text-secondary"><?= formatMoeda($c['plano_valor'] ?? 0) ?></small>
                            </td>
                            <td>Dia <?= $c['vencimento_dia'] ?></td>
                            <td>
                                <span class="badge-custom badge-<?= strtolower($c['status']) ?>">
                                    <?= ucfirst($c['status']) ?>
                                </span>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-info me-1" 
                                        onclick="editarCliente(<?= htmlspecialchars(json_encode($c)) ?>)" title="Editar Cliente">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                                <a href="faturas.php?action=gerar&cliente_id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-success me-1" title="Gerar Fatura PIX">
                                    <i class="fa-solid fa-file-invoice-dollar"></i>
                                </a>
                                <a href="clientes.php?action=excluir&id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Deseja realmente excluir este cliente do sistema e do MikroTik?')" title="Excluir">
                                    <i class="fa-solid fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Cadastrar Cliente -->
<div class="modal fade" id="modalNovoCliente" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark text-light border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-plus text-info me-2"></i>Cadastrar Novo Cliente</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="cadastrar">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small text-secondary">Nome Completo *</label>
                            <input type="text" name="nome" class="form-control-custom" required placeholder="João da Silva">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-secondary">CPF / CNPJ *</label>
                            <input type="text" name="cpf_cnpj" class="form-control-custom" required placeholder="000.000.000-00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-secondary">WhatsApp (com DDD, sem 55) *</label>
                            <input type="text" name="whatsapp" class="form-control-custom" required placeholder="82999334425">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-secondary">E-mail</label>
                            <input type="email" name="email" class="form-control-custom" placeholder="cliente@email.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-secondary">Usuário PPPoE *</label>
                            <input type="text" name="pppoe_usuario" class="form-control-custom" required placeholder="joaosilva">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-secondary">Senha PPPoE *</label>
                            <input type="password" name="pppoe_senha" class="form-control-custom" required placeholder="123456">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-secondary">Plano / Profile *</label>
                            <select name="plano_id" class="form-control-custom" required>
                                <option value="">Selecione um Plano...</option>
                                <?php foreach ($planos as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= sanitize($p['nome']) ?> (<?= formatMoeda($p['valor']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-secondary">Dia de Vencimento Mensal</label>
                            <select name="vencimento_dia" class="form-control-custom">
                                <option value="1">Dia 01</option>
                                <option value="10" selected>Dia 10</option>
                                <option value="20">Dia 20</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary-custom">Salvar Cliente</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Cliente -->
<div class="modal fade" id="modalEditarCliente" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark text-light border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square text-info me-2"></i>Editar Cliente</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="editar">
                <input type="hidden" name="cliente_id" id="edit_cliente_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small text-secondary">Nome Completo *</label>
                            <input type="text" name="nome" id="edit_cli_nome" class="form-control-custom" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-secondary">CPF / CNPJ *</label>
                            <input type="text" name="cpf_cnpj" id="edit_cli_cpf" class="form-control-custom" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-secondary">WhatsApp *</label>
                            <input type="text" name="whatsapp" id="edit_cli_wa" class="form-control-custom" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-secondary">E-mail</label>
                            <input type="email" name="email" id="edit_cli_email" class="form-control-custom">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-secondary">Usuário PPPoE *</label>
                            <input type="text" name="pppoe_usuario" id="edit_cli_usuario" class="form-control-custom" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-secondary">Senha PPPoE *</label>
                            <input type="text" name="pppoe_senha" id="edit_cli_senha" class="form-control-custom" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-secondary">Plano / Profile *</label>
                            <select name="plano_id" id="edit_cli_plano" class="form-control-custom">
                                <?php foreach ($planos as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= sanitize($p['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-secondary">Status do Acesso</label>
                            <select name="status" id="edit_cli_status" class="form-control-custom">
                                <option value="ativo">Ativo (Profile Normal)</option>
                                <option value="suspenso">Suspenso (Profile Bloqueado)</option>
                                <option value="cancelado">Cancelado</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-secondary">Vencimento Mensal</label>
                            <select name="vencimento_dia" id="edit_cli_vencimento" class="form-control-custom">
                                <option value="1">Dia 01</option>
                                <option value="10">Dia 10</option>
                                <option value="20">Dia 20</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary-custom">Atualizar Assinante</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editarCliente(c) {
    document.getElementById('edit_cliente_id').value = c.id;
    document.getElementById('edit_cli_nome').value = c.nome;
    document.getElementById('edit_cli_cpf').value = c.cpf_cnpj;
    document.getElementById('edit_cli_wa').value = c.whatsapp;
    document.getElementById('edit_cli_email').value = c.email || '';
    document.getElementById('edit_cli_usuario').value = c.pppoe_usuario;
    document.getElementById('edit_cli_senha').value = c.pppoe_senha;
    document.getElementById('edit_cli_plano').value = c.plano_id;
    document.getElementById('edit_cli_status').value = c.status || 'ativo';
    document.getElementById('edit_cli_vencimento').value = c.vencimento_dia || 10;

    const modal = new bootstrap.Modal(document.getElementById('modalEditarCliente'));
    modal.show();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
