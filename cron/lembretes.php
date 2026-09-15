<?php
/**
 * Script Cron Job de Rotina Diária de Lembretes no WhatsApp & Bloqueio Automático de Inadimplentes
 * Executar 1x ao dia: php cron/lembretes.php
 */

require_once __DIR__ . '/../config.php';

echo "=== INICIANDO CRON DE ROTINA FINANCEIRA E MIKROTIK ===\n";

$db = Database::getInstance();
$wa = new WhatsAppService();
$mkApi = new MikrotikAPI();

$mesAtual = date('n');
$anoAtual = date('Y');

// 1. Atualizar faturas vencidas para status 'atrasado'
$db->exec("UPDATE faturas SET status = 'atrasado' WHERE status = 'pendente' AND data_vencimento < CURRENT_DATE()");

// 2. Bloquear no MikroTik clientes com faturas atrasadas
$stmtOverdue = $db->query("
    SELECT f.*, c.id as cliente_id, c.pppoe_usuario, c.nome, p.profile_bloqueado 
    FROM faturas f 
    JOIN clientes c ON f.cliente_id = c.id 
    LEFT JOIN planos p ON f.plano_id = p.id 
    WHERE f.status = 'atrasado'
");
$overdueList = $stmtOverdue->fetchAll();

foreach ($overdueList as $ov) {
    // Gravar no histórico mensal
    $stmtH = $db->prepare("
        INSERT INTO mikrotik_payment_history (cliente_id, ano, mes, status) 
        VALUES (?, ?, ?, 'overdue') 
        ON DUPLICATE KEY UPDATE status = 'overdue'
    ");
    $stmtH->execute([$ov['cliente_id'], $anoAtual, $mesAtual]);

    // Alterar profile no MikroTik para Bloqueado e desconectar sessão ativa
    if (!empty($ov['profile_bloqueado']) && !empty($ov['pppoe_usuario'])) {
        $mkApi->changeSecretProfile($ov['pppoe_usuario'], $ov['profile_bloqueado']);
        $mkApi->disconnectActiveSession($ov['pppoe_usuario']);
        echo "[BLOQUEIO] Cliente {$ov['nome']} ({$ov['pppoe_usuario']}) alterado para perfil bloqueado no RouterOS.\n";
    }
}

// 3. Enviar Lembretes de Cobrança via WhatsApp
$stmtNotif = $db->query("
    SELECT f.*, c.nome, c.whatsapp 
    FROM faturas f 
    JOIN clientes c ON f.cliente_id = c.id 
    WHERE f.status IN ('pendente', 'atrasado') 
    AND f.notificado_wa = 0 
    AND f.data_vencimento <= DATE_ADD(CURRENT_DATE(), INTERVAL 3 DAY)
");

$faturasParaNotificar = $stmtNotif->fetchAll();
$enviados = 0;

foreach ($faturasParaNotificar as $fat) {
    $faturaUrl = BASE_URL . "/cliente/fatura.php?id=" . $fat['id'];
    $sucesso = $wa->sendInvoiceNotification(
        $fat['whatsapp'], 
        $fat['nome'], 
        $fat['valor'], 
        $fat['data_vencimento'], 
        $fat['pix_copia_cola'], 
        $faturaUrl
    );

    if ($sucesso) {
        $db->exec("UPDATE faturas SET notificado_wa = 1 WHERE id = " . $fat['id']);
        $enviados++;
        echo "[WHATSAPP OK] Cobrança enviada para {$fat['nome']} (Fatura #{$fat['id']})\n";
    } else {
        echo "[WHATSAPP ERRO] Falha ao enviar para {$fat['nome']}\n";
    }
}

echo "=== CRON FINALIZADO COM SUCESSO: {$enviados} lembretes enviados no WhatsApp. ===\n";
