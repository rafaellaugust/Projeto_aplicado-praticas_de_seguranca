<?php
require_once __DIR__ . '/header.php';

$msgSuccess = '';
$msgError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'atualizar_perfil') {
    $email = sanitize($_POST['email'] ?? '');
    $whatsapp = sanitize($_POST['whatsapp'] ?? '');
    $endereco = sanitize($_POST['endereco'] ?? '');

    try {
        $stmt = $db->prepare("UPDATE clientes SET email = ?, whatsapp = ?, endereco = ? WHERE id = ?");
        $stmt->execute([$email, $whatsapp, $endereco, $_SESSION['cliente_id']]);
        $msgSuccess = "Perfil atualizado com sucesso!";
        // Rebusca
        $stmtC = $db->prepare("SELECT c.*, p.nome as plano_nome FROM clientes c LEFT JOIN planos p ON c.plano_id = p.id WHERE c.id = ?");
        $stmtC->execute([$_SESSION['cliente_id']]);
        $cliente = $stmtC->fetch();
    } catch (Exception $e) {
        $msgError = "Erro ao atualizar: ".$e->getMessage();
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="text-white fw-bold mb-1"><i class="fa-solid fa-user me-2 text-info"></i>Meu Perfil</h4>
        <p class="text-secondary small mb-0">Gerencie seus dados pessoais e de acesso.</p>
    </div>
    <a href="index.php" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-arrow-left me-1"></i> Voltar</a>
</div>

<?php if ($msgSuccess): ?><div class="alert alert-success alert-dismissible fade show"><i class="fa-solid fa-circle-check me-1"></i> <?= $msgSuccess ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($msgError): ?><div class="alert alert-danger alert-dismissible fade show"><i class="fa-solid fa-triangle-exclamation me-1"></i> <?= $msgError ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="row g-4">
    <div class="col-md-4">
        <div class="card-custom text-center">
            <i class="fa-solid fa-user-circle display-1 text-info mb-3 d-block"></i>
            <h5 class="text-white fw-bold"><?= sanitize($cliente['nome']) ?></h5>
            <p class="text-secondary small mb-2"><?= sanitize($cliente['pppoe_usuario']) ?></p>
            <span class="badge bg-dark border border-secondary"><?= sanitize($cliente['plano_nome'] ?? 'Sem Plano') ?></span>
            <hr class="border-secondary my-3">
            <p class="text-start small text-secondary mb-1"><i class="fa-solid fa-id-card me-2"></i>CPF/CNPJ: <?= sanitize($cliente['cpf_cnpj']) ?></p>
            <p class="text-start small text-secondary mb-1"><i class="fa-solid fa-wifi me-2"></i>PPPoE: <code class="text-info"><?= sanitize($cliente['pppoe_usuario']) ?></code></p>
            <p class="text-start small text-secondary mb-0"><i class="fa-solid fa-calendar me-2"></i>Vencimento dia <?= $cliente['vencimento_dia'] ?></p>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card-custom">
            <h5 class="text-white fw-bold mb-3"><i class="fa-solid fa-pen-to-square me-2 text-warning"></i>Editar Dados</h5>
            <form method="POST" action="">
                <input type="hidden" name="action" value="atualizar_perfil">
                <div class="mb-3">
                    <label class="form-label small text-secondary">Nome Completo</label>
                    <input type="text" class="form-control-custom" value="<?= sanitize($cliente['nome']) ?>" disabled>
                    <small class="text-secondary">Para alterar nome, contate o suporte.</small>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small text-secondary">E-mail *</label>
                        <input type="email" name="email" class="form-control-custom" value="<?= sanitize($cliente['email'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-secondary">WhatsApp *</label>
                        <input type="text" name="whatsapp" class="form-control-custom" value="<?= sanitize($cliente['whatsapp'] ?? '') ?>" required placeholder="55119...">
                    </div>
                </div>
                <div class="mb-3 mt-3">
                    <label class="form-label small text-secondary">Endereço</label>
                    <textarea name="endereco" class="form-control-custom" rows="2"><?= sanitize($cliente['endereco'] ?? '') ?></textarea>
                </div>
                <div class="text-end">
                    <button type="submit" class="btn btn-primary-custom"><i class="fa-solid fa-floppy-disk me-1"></i> Salvar Alterações</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
