<?php
require_once __DIR__ . '/header.php';

$config = $db->query("SELECT empresa_nome, empresa_telefone, empresa_cnpj FROM configuracoes WHERE id = 1")->fetch();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="text-white fw-bold mb-1"><i class="fa-solid fa-headset me-2 text-success"></i>Suporte Técnico</h4>
        <p class="text-secondary small mb-0">Precisa de ajuda? Entre em contato conosco.</p>
    </div>
    <a href="index.php" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-arrow-left me-1"></i> Dashboard</a>
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card-custom text-center py-4">
            <i class="fa-brands fa-whatsapp display-1 text-success mb-3 d-block"></i>
            <h5 class="text-white fw-bold">WhatsApp</h5>
            <p class="text-secondary small">Atendimento rápido via WhatsApp</p>
            <?php $wa = preg_replace('/[^0-9]/','', $cliente['whatsapp'] ?? ''); ?>
            <a href="https://wa.me/<?= $config['empresa_telefone'] ? preg_replace('/[^0-9]/','',$config['empresa_telefone']) : '5511999999999' ?>?text=Olá, sou <?= urlencode($cliente['nome']) ?> (<?= $cliente['pppoe_usuario'] ?>) e preciso de suporte." target="_blank" class="btn btn-success rounded-3 px-4"><i class="fa-brands fa-whatsapp me-2"></i> Chamar no WhatsApp</a>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card-custom">
            <h5 class="text-white fw-bold mb-3"><i class="fa-solid fa-circle-info me-2 text-info"></i>Seus Dados para Suporte</h5>
            <p class="small text-secondary mb-1"><strong class="text-white">Nome:</strong> <?= sanitize($cliente['nome']) ?></p>
            <p class="small text-secondary mb-1"><strong class="text-white">Usuário PPPoE:</strong> <code class="text-info"><?= sanitize($cliente['pppoe_usuario']) ?></code></p>
            <p class="small text-secondary mb-1"><strong class="text-white">Plano:</strong> <?= sanitize($cliente['plano_nome'] ?? '-') ?> (<?= sanitize($cliente['velocidade_down'] ?? '') ?>)</p>
            <p class="small text-secondary mb-1"><strong class="text-white">Vencimento:</strong> Dia <?= $cliente['vencimento_dia'] ?> - Expira em <?= $cliente['data_expiracao'] ? formatData($cliente['data_expiracao']) : 'N/A' ?></p>
            <hr class="border-secondary">
            <p class="small text-secondary mb-1"><strong class="text-white">Provedor:</strong> <?= sanitize($config['empresa_nome'] ?? 'Provedor') ?></p>
            <p class="small text-secondary mb-0"><strong class="text-white">Telefone:</strong> <?= sanitize($config['empresa_telefone'] ?? '-') ?></p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
