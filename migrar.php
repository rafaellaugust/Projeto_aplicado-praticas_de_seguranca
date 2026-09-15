<?php
/**
 * migrar.php — Execute UMA VEZ via browser para aplicar alterações no banco
 * Após executar com sucesso, APAGUE este arquivo por segurança!
 * URL: https://seusite.com/migrar.php?token=migrar2026
 */

$tokenSeguro = 'migrar2026';
if (($_GET['token'] ?? '') !== $tokenSeguro) {
    die('<h2>Acesso negado.</h2><p>Acesse: /migrar.php?token=migrar2026</p>');
}

require_once __DIR__ . '/config.php';
$db = Database::getInstance();

$resultados = [];

// ─── Função auxiliar: verifica se coluna existe na tabela ────────────────────
function colunaExiste(PDO $db, string $tabela, string $coluna, string $banco): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$banco, $tabela, $coluna]);
    return (int)$stmt->fetchColumn() > 0;
}

$banco = DB_NAME; // definido no config.php como 'spaconet_aplicacao'

// ─── 1. Adiciona coluna `descricao` em faturas ───────────────────────────────
if (!colunaExiste($db, 'faturas', 'descricao', $banco)) {
    try {
        $db->exec("ALTER TABLE `faturas` ADD COLUMN `descricao` VARCHAR(255) DEFAULT NULL AFTER `valor`");
        $resultados[] = ['ok' => true, 'msg' => 'Coluna `faturas.descricao` adicionada com sucesso.'];
    } catch (Exception $e) {
        $resultados[] = ['ok' => false, 'msg' => 'ERRO ao adicionar `faturas.descricao`: ' . $e->getMessage()];
    }
} else {
    $resultados[] = ['ok' => true, 'msg' => 'Coluna `faturas.descricao` já existia — ignorado.'];
}

// ─── 2. Adiciona coluna `meses_cobertos` em faturas ─────────────────────────
if (!colunaExiste($db, 'faturas', 'meses_cobertos', $banco)) {
    try {
        $db->exec("ALTER TABLE `faturas` ADD COLUMN `meses_cobertos` INT NOT NULL DEFAULT 1 AFTER `descricao`");
        $resultados[] = ['ok' => true, 'msg' => 'Coluna `faturas.meses_cobertos` adicionada com sucesso.'];
    } catch (Exception $e) {
        $resultados[] = ['ok' => false, 'msg' => 'ERRO ao adicionar `faturas.meses_cobertos`: ' . $e->getMessage()];
    }
} else {
    $resultados[] = ['ok' => true, 'msg' => 'Coluna `faturas.meses_cobertos` já existia — ignorado.'];
}

// ─── 3. Garante meses_cobertos = 1 em faturas existentes ────────────────────
if (colunaExiste($db, 'faturas', 'meses_cobertos', $banco)) {
    try {
        $rows = $db->exec("UPDATE `faturas` SET `meses_cobertos` = 1 WHERE `meses_cobertos` IS NULL OR `meses_cobertos` = 0");
        $resultados[] = ['ok' => true, 'msg' => "Faturas existentes atualizadas com meses_cobertos=1 ({$rows} linha(s))."];
    } catch (Exception $e) {
        $resultados[] = ['ok' => false, 'msg' => 'ERRO ao atualizar faturas: ' . $e->getMessage()];
    }
} else {
    $resultados[] = ['ok' => false, 'msg' => 'SKIP: meses_cobertos não existe, não foi possível atualizar.'];
}

// ─── 4. Expande enum status de clientes para incluir 'aviso' ────────────────
try {
    $db->exec("ALTER TABLE `clientes` MODIFY COLUMN `status` ENUM('ativo','suspenso','cancelado','aviso') DEFAULT 'ativo'");
    $resultados[] = ['ok' => true, 'msg' => "Enum `clientes.status` expandido com 'aviso'."];
} catch (Exception $e) {
    $resultados[] = ['ok' => false, 'msg' => 'ERRO no enum clientes.status: ' . $e->getMessage()];
}

// ─── Verificação final ───────────────────────────────────────────────────────
$temDescricao = colunaExiste($db, 'faturas', 'descricao', $banco);
$temMeses     = colunaExiste($db, 'faturas', 'meses_cobertos', $banco);

// Verifica enum status de clientes
$stmtEnum = $db->query("SHOW COLUMNS FROM `clientes` LIKE 'status'");
$enumRow  = $stmtEnum->fetch();
$enumOk   = $enumRow && strpos($enumRow['Type'] ?? '', 'aviso') !== false;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Migração BD — MikroPay</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{background:#0f172a;color:#e2e8f0}</style>
</head>
<body class="p-4">
<div class="container" style="max-width:700px">
    <h3 class="mb-4">⚙ Migração do Banco de Dados — MikroPay</h3>

    <?php foreach ($resultados as $r): ?>
    <div class="alert <?= $r['ok'] ? 'alert-success' : 'alert-danger' ?> py-2">
        <?= $r['ok'] ? '✅' : '❌' ?> <?= htmlspecialchars($r['msg']) ?>
    </div>
    <?php endforeach; ?>

    <hr>
    <h5>Verificação Final:</h5>
    <p><?= $temDescricao ? '✅' : '❌' ?> Coluna <code>faturas.descricao</code> <?= $temDescricao ? '<strong class="text-success">existe</strong>' : '<strong class="text-danger">NÃO existe</strong>' ?></p>
    <p><?= $temMeses ? '✅' : '❌' ?> Coluna <code>faturas.meses_cobertos</code> <?= $temMeses ? '<strong class="text-success">existe</strong>' : '<strong class="text-danger">NÃO existe</strong>' ?></p>
    <p><?= $enumOk ? '✅' : '❌' ?> Enum <code>clientes.status</code> <?= $enumOk ? '<strong class="text-success">inclui aviso</strong>' : '<strong class="text-danger">NÃO inclui aviso — rode novamente</strong>' ?></p>

    <?php if ($temDescricao && $temMeses && $enumOk): ?>
    <div class="alert alert-success mt-4">
        <strong>🎉 Migração completa!</strong> Todos os campos foram criados com sucesso.
    </div>
    <div class="alert alert-warning">
        <strong>⚠ IMPORTANTE:</strong> Apague o arquivo <code>migrar.php</code> agora por segurança!
    </div>
    <?php else: ?>
    <div class="alert alert-danger mt-4">
        <strong>❌ Migração incompleta!</strong> Recarregue a página para tentar novamente.
        <br><a href="?token=migrar2026" class="btn btn-warning btn-sm mt-2">Tentar Novamente</a>
    </div>
    <?php endif; ?>

    <div class="mt-3">
        <a href="admin/" class="btn btn-primary me-2">Admin</a>
        <a href="cliente/" class="btn btn-secondary">Portal Cliente</a>
    </div>
</div>
</body>
</html>
