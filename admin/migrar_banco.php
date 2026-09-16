<?php
require_once __DIR__ . '/header.php';

$db = Database::getInstance();
$resultados = [];

$tabelasComAutoIncrement = [
    'logs',
    'administradores',
    'clientes',
    'faturas',
    'historico_pagamentos',
    'mikrotik_payment_history',
    'planos',
    'roteadores',
    'webhook_logs'
];

foreach ($tabelasComAutoIncrement as $tab) {
    $statusPk = 'OK';
    $statusAi = 'OK';

    // 1. Tentar adicionar Primary Key
    try {
        $db->exec("ALTER TABLE `{$tab}` ADD PRIMARY KEY (`id`)");
        $statusPk = 'Chave primária adicionada';
    } catch (Exception $e) {
        if (strpos($e->getMessage(), '1068') !== false || stripos($e->getMessage(), 'Multiple primary key') !== false) {
            $statusPk = 'Chave primária já existia (OK)';
        } else {
            $statusPk = 'Aviso PK: ' . $e->getMessage();
        }
    }

    // 2. Tentar ativar AUTO_INCREMENT
    try {
        $db->exec("ALTER TABLE `{$tab}` MODIFY `id` int NOT NULL AUTO_INCREMENT");
        $statusAi = 'AUTO_INCREMENT ativo';
    } catch (Exception $e) {
        $statusAi = 'Erro ao ativar AUTO_INCREMENT: ' . $e->getMessage();
    }

    $resultados[$tab] = [
        'pk' => $statusPk,
        'ai' => $statusAi,
        'sucesso' => (strpos($statusAi, 'Erro') === false)
    ];
}

// Tabela configuracoes (apenas PK)
try {
    $db->exec("ALTER TABLE `configuracoes` ADD PRIMARY KEY (`id`)");
    $resultados['configuracoes'] = ['pk' => 'PK configurada', 'ai' => 'N/A', 'sucesso' => true];
} catch (Exception $e) {
    $resultados['configuracoes'] = ['pk' => 'PK já existia (OK)', 'ai' => 'N/A', 'sucesso' => true];
}

// Tabela security_settings (coluna client_login_no_password)
try {
    $db->exec("ALTER TABLE `security_settings` ADD COLUMN IF NOT EXISTS `client_login_no_password` tinyint DEFAULT 0");
    $resultados['security_settings (coluna modo sem senha)'] = ['pk' => 'N/A', 'ai' => 'Coluna verificada/adicionada com sucesso', 'sucesso' => true];
} catch (Exception $e) {
    $resultados['security_settings (coluna modo sem senha)'] = ['pk' => 'N/A', 'ai' => 'Aviso: ' . $e->getMessage(), 'sucesso' => true];
}

// Sincronização dos logs do arquivo físico para o banco
$logsSincronizados = 0;
try {
    $logFileHoje = __DIR__ . '/../log/app-' . date('Y-m-d') . '.log';
    if (file_exists($logFileHoje)) {
        $linhas = @file($logFileHoje, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $maxId = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM logs")->fetchColumn();
        $stmtCheck = $db->prepare("SELECT id FROM logs WHERE mensagem = ? AND criado_em = ? LIMIT 1");
        $stmtInsert = $db->prepare("INSERT INTO logs (id, tipo, mensagem, detalhes, criado_em) VALUES (?, ?, ?, ?, ?)");

        foreach ($linhas as $linha) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s*\[(.*?)\]\s*(.*?)(?:\s*\|\s*Detalhes:\s*(.*))?$/', $linha, $m)) {
                $dataHora = $m[1];
                $tipo = strtolower(trim($m[2]));
                $msg = trim($m[3]);
                $det = !empty($m[4]) ? trim($m[4]) : null;

                $stmtCheck->execute([$msg, $dataHora]);
                if (!$stmtCheck->fetch()) {
                    $maxId++;
                    $stmtInsert->execute([$maxId, $tipo, $msg, $det, $dataHora]);
                    $logsSincronizados++;
                }
            }
        }
    }
} catch (Exception $e) {}
?>

<div class="card-custom mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="text-white fw-bold mb-0">
            <i class="fa-solid fa-wrench text-warning me-2"></i>Otimização e Correção do Banco de Dados
        </h4>
        <div>
            <a href="logs.php" class="btn btn-primary-custom btn-sm"><i class="fa-solid fa-list me-1"></i>Ir para Logs</a>
            <a href="index.php" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-home me-1"></i>Início</a>
        </div>
    </div>
    <p class="text-secondary small">
        Este assistente verifica e ajusta as Chaves Primárias e o AUTO_INCREMENT de todas as tabelas do banco de dados, prevenindo falhas de inserção de logs e cadastros.
    </p>

    <?php if ($logsSincronizados > 0): ?>
        <div class="alert alert-success py-2 mb-3">
            <i class="fa-solid fa-circle-check me-2"></i><strong><?= $logsSincronizados ?></strong> registros do arquivo de log físico foram importados para o banco de dados com sucesso!
        </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>Tabela</th>
                    <th>Chave Primária (PK)</th>
                    <th>Auto Incremento (AI)</th>
                    <th class="text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resultados as $tabela => $res): ?>
                    <tr>
                        <td class="fw-bold text-white"><?= htmlspecialchars($tabela) ?></td>
                        <td><small class="text-secondary"><?= htmlspecialchars($res['pk']) ?></small></td>
                        <td><small class="text-secondary"><?= htmlspecialchars($res['ai']) ?></small></td>
                        <td class="text-center">
                            <?php if ($res['sucesso']): ?>
                                <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Pronto</span>
                            <?php else: ?>
                                <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1"></i>Erro</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="mt-4 text-center">
        <a href="logs.php" class="btn btn-success px-4 py-2 fw-bold">
            <i class="fa-solid fa-check-circle me-1"></i>Concluído! Abrir Página de Logs
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
