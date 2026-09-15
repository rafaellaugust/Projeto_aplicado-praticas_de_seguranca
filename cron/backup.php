<?php
/**
 * Script de Execução Agendada de Backup SQL (Cron Job)
 * 
 * Pode ser executado via CLI (linha de comando / cron do cPanel)
 * Exemplo de cron diário no cPanel:
 * 0 3 * * * /usr/local/bin/php /home/usuario/public_html/cron/backup.php >/dev/null 2>&1
 * 
 * Ou chamado via requisição web protegida:
 * https://seudominio.com/cron/backup.php?key=spaconett_backup
 */

require_once __DIR__ . '/../config.php';

// Se acessado via navegador web, exigir token simples para segurança
if (php_sapi_name() !== 'cli') {
    $tokenInformado = $_GET['key'] ?? '';
    // Aceita chave segura ou checagem de admin logado via sessão
    if ($tokenInformado !== 'spaconett_backup' && empty($_SESSION['admin_id'])) {
        http_response_code(403);
        die(json_encode(['error' => 'Acesso não autorizado ao cron de backup.']));
    }
    header('Content-Type: application/json; charset=utf-8');
}

echo "=== ROTINA DE BACKUP AUTOMÁTICO DO BANCO DE DADOS ===\n";
echo "Data/Hora: " . date('Y-m-d H:i:s') . "\n";

$backupService = new BackupService();
$res = $backupService->runScheduledBackup();

if ($res['executado']) {
    echo "Status: SUCESSO!\n";
    echo "Arquivo: " . ($res['detalhes']['arquivo'] ?? '') . "\n";
    echo "Tamanho: " . ($res['detalhes']['tamanho'] ?? '') . "\n";
} else {
    echo "Status: IGNORADO / NÃO EXECUTADO\n";
    echo "Motivo: " . ($res['motivo'] ?? ($res['detalhes']['erro'] ?? 'Condição de agendamento não satisfeita')) . "\n";
}

echo "=== FIM DA ROTINA ===\n";