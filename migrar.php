<?php
/**
 * migrar.php — Execução segura de migrações e atualizações do banco de dados
 * 
 * SEGURANÇA:
 * - Via CLI: php migrar.php
 * - Via Web: Apenas administradores autenticados com verificação de CSRF
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/src/SessionGuard.php';

// Se for execução via Web, exigir autenticação de administrador
if (PHP_SAPI !== 'cli') {
    SessionGuard::init();
    checkAdminLogin();
    Security::sendSecurityHeaders();
}

$db = Database::getInstance();
$resultados = [];
$executado = false;

// Função auxiliar: verifica se coluna existe na tabela
function colunaExiste(PDO $db, string $tabela, string $coluna, string $banco): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$banco, $tabela, $coluna]);
    return (int)$stmt->fetchColumn() > 0;
}

$banco = DB_NAME;

// Processar execução se for CLI ou se for POST com CSRF válido
if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] === 'POST' && Security::validateCsrfToken())) {
    $executado = true;

    // 1. Adiciona coluna `descricao` em faturas
    if (!colunaExiste($db, 'faturas', 'descricao', $banco)) {
        try {
            $db->exec("ALTER TABLE `faturas` ADD COLUMN `descricao` VARCHAR(255) DEFAULT NULL AFTER `valor`");
            $resultados[] = ['ok' => true, 'msg' => 'Coluna `faturas.descricao` adicionada com sucesso.'];
        } catch (Exception $e) {
            $resultados[] = ['ok' => false, 'msg' => 'ERRO ao adicionar `faturas.descricao`: ' . $e->getMessage()];
        }
    } else {
        $resultados[] = ['ok' => true, 'msg' => 'Coluna `faturas.descricao` já existia — mantida.'];
    }

    // 2. Adiciona coluna `meses_cobertos` em faturas
    if (!colunaExiste($db, 'faturas', 'meses_cobertos', $banco)) {
        try {
            $db->exec("ALTER TABLE `faturas` ADD COLUMN `meses_cobertos` INT NOT NULL DEFAULT 1 AFTER `descricao`");
            $resultados[] = ['ok' => true, 'msg' => 'Coluna `faturas.meses_cobertos` adicionada com sucesso.'];
        } catch (Exception $e) {
            $resultados[] = ['ok' => false, 'msg' => 'ERRO ao adicionar `faturas.meses_cobertos`: ' . $e->getMessage()];
        }
    } else {
        $resultados[] = ['ok' => true, 'msg' => 'Coluna `faturas.meses_cobertos` já existia — mantida.'];
    }

    // 3. Garante meses_cobertos = 1 em faturas existentes
    if (colunaExiste($db, 'faturas', 'meses_cobertos', $banco)) {
        try {
            $rows = $db->exec("UPDATE `faturas` SET `meses_cobertos` = 1 WHERE `meses_cobertos` IS NULL OR `meses_cobertos` = 0");
            $resultados[] = ['ok' => true, 'msg' => "Faturas existentes atualizadas ({$rows} linha(s))."];
        } catch (Exception $e) {
            $resultados[] = ['ok' => false, 'msg' => 'ERRO ao atualizar faturas: ' . $e->getMessage()];
        }
    }

    // 4. Expande enum status de clientes para incluir 'aviso'
    try {
        $db->exec("ALTER TABLE `clientes` MODIFY COLUMN `status` ENUM('ativo','suspenso','cancelado','aviso') DEFAULT 'ativo'");
        $resultados[] = ['ok' => true, 'msg' => "Enum `clientes.status` verificado com sucesso."];
    } catch (Exception $e) {
        $resultados[] = ['ok' => false, 'msg' => 'ERRO no enum clientes.status: ' . $e->getMessage()];
    }

    // 5. Aplicar migrações de tabelas de segurança se arquivo existir
    $securityMigration = __DIR__ . '/migrations/001_security_tables.sql';
    if (file_exists($securityMigration)) {
        try {
            $sqlContent = file_get_contents($securityMigration);
            $statements = array_filter(array_map('trim', explode(';', $sqlContent)));
            foreach ($statements as $stmtSql) {
                if (!empty($stmtSql)) {
                    $db->exec($stmtSql);
                }
            }
            $resultados[] = ['ok' => true, 'msg' => 'Tabelas de segurança (login_attempts, blocked_ips, etc.) sincronizadas com sucesso.'];
        } catch (Exception $e) {
            $resultados[] = ['ok' => false, 'msg' => 'Nota em tabelas de segurança: ' . $e->getMessage()];
        }
    }

    if (PHP_SAPI === 'cli') {
        echo "=== RESULTADO DA MIGRAÇÃO ===\n";
        foreach ($resultados as $r) {
            echo ($r['ok'] ? "[OK] " : "[ERRO] ") . $r['msg'] . "\n";
        }
        exit(0);
    }
}

// Verificação do estado atual
$temDescricao = colunaExiste($db, 'faturas', 'descricao', $banco);
$temMeses     = colunaExiste($db, 'faturas', 'meses_cobertos', $banco);
$stmtEnum     = $db->query("SHOW COLUMNS FROM `clientes` LIKE 'status'");
$enumRow      = $stmtEnum ? $stmtEnum->fetch() : null;
$enumOk       = $enumRow && strpos($enumRow['Type'] ?? '', 'aviso') !== false;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Migração Segura do Banco — MikroTik Pay</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{background:#0f172a;color:#e2e8f0}</style>
</head>
<body class="p-4">
<div class="container" style="max-width:720px">
    <div class="card bg-dark border-secondary p-4 shadow-lg rounded-3">
        <h3 class="mb-2 text-white">⚙ Migração Segura do Banco de Dados</h3>
        <p class="text-secondary small mb-4">Acesso restrito a administradores autenticados.</p>

        <?php if ($executado): ?>
            <h5 class="text-info mt-2">Resultados da Execução:</h5>
            <?php foreach ($resultados as $r): ?>
                <div class="alert <?= $r['ok'] ? 'alert-success' : 'alert-danger' ?> py-2 mb-2">
                    <?= $r['ok'] ? '✅' : '❌' ?> <?= htmlspecialchars($r['msg']) ?>
                </div>
            <?php endforeach; ?>
            <hr class="border-secondary">
        <?php endif; ?>

        <h5 class="text-light">Status da Estrutura Atual:</h5>
        <ul class="list-unstyled">
            <li><?= $temDescricao ? '✅' : '❌' ?> Coluna <code>faturas.descricao</code>: <?= $temDescricao ? '<span class="badge bg-success">Presente</span>' : '<span class="badge bg-danger">Ausente</span>' ?></li>
            <li class="mt-2"><?= $temMeses ? '✅' : '❌' ?> Coluna <code>faturas.meses_cobertos</code>: <?= $temMeses ? '<span class="badge bg-success">Presente</span>' : '<span class="badge bg-danger">Ausente</span>' ?></li>
            <li class="mt-2"><?= $enumOk ? '✅' : '❌' ?> Enum <code>clientes.status</code> com aviso: <?= $enumOk ? '<span class="badge bg-success">Configurado</span>' : '<span class="badge bg-danger">Incompleto</span>' ?></li>
        </ul>

        <div class="mt-4 pt-3 border-top border-secondary d-flex gap-2">
            <form method="POST" action="migrar.php">
                <?= Security::generateCsrfToken() ?>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-play me-1"></i> Executar Migrações Agora
                </button>
            </form>
            <a href="admin/index.php" class="btn btn-outline-secondary">Painel Admin</a>
        </div>
    </div>
</div>
</body>
</html>
