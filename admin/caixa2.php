<?php
require_once __DIR__ . '/../config.php';
if (isset($_GET['ajax']) && $_GET['ajax'] === 'buscar_perfil') {
    header('Content-Type: application/json; charset=utf-8');
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['admin_id'])) { echo json_encode(['success'=>false,'error'=>'Sessão expirada.']); exit; }
    try {
        $db = Database::getInstance();
        $clienteId = (int)($_GET['cliente_id'] ?? 0);
        $stmt = $db->prepare("SELECT c.id, c.nome, c.pppoe_usuario, c.roteador_id, p.profile_mikrotik, p.profile_bloqueado, p.profile_aviso FROM clientes c LEFT JOIN planos p ON c.plano_id = p.id WHERE c.id = ?");
        $stmt->execute([$clienteId]);
        $cli = $stmt->fetch();
        if (!$cli) throw new Exception('Cliente não encontrado');
        $mk = new MikrotikAPI($cli['roteador_id'] ?: 1);
        $profile = $mk->getClientProfile($cli['pppoe_usuario']);
        if ($profile === null) throw new Exception('Usuário '.$cli['pppoe_usuario'].' não encontrado: '.$mk->getLastError());
        $status='paid'; $lower=strtolower($profile);
        // 4 estados MikroTik: /Pago=paid_advance, /Espera=paid, /Aviso=warning, /Bloqueado=overdue
        if(strpos($lower,'bloqueado')!==false) $status='overdue';
        elseif(strpos($lower,'aviso')!==false) $status='warning';
        elseif(strpos($lower,'/pago')!==false || substr($lower,-5)==='/pago') $status='paid'; // pago adiantado
        elseif(strpos($lower,'espera')!==false) $status='paid';
        echo json_encode(['success'=>true,'usuario'=>$cli['pppoe_usuario'],'nome'=>$cli['nome'],'profile'=>$profile,'status'=>$status]);
    } catch(Exception $e){ echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
    exit;
}
require_once __DIR__ . '/header.php';
$db = Database::getInstance(); $msgSuccess=''; $msgError='';


if(isset($_GET['action']) && $_GET['action']==='sincronizar_mikrotik'){
    $mkApi=new MikrotikAPI(); $secrets=$mkApi->getSecrets(); $sincronizados=0; $faturasCriadas=0;
    if(!empty($secrets)){
        $mesAtual=(int)date('n'); $anoAtual=(int)date('Y');
        foreach($secrets as $sec){
            $user=sanitize($sec['name']??''); $profile=sanitize($sec['profile']??'');
            if(empty($user)) continue;
            $stmtC=$db->prepare("SELECT c.id, c.plano_id, c.vencimento_dia, p.valor as plano_valor FROM clientes c LEFT JOIN planos p ON c.plano_id=p.id WHERE c.pppoe_usuario=?");
            $stmtC->execute([$user]); $cli=$stmtC->fetch();
            if(!$cli) continue;
            // 4 estados: /Pago=paid_advance, /Espera=paid, /Aviso=warning, /Bloqueado=overdue
            $lower=strtolower($profile);
            $statusHistory='paid';
            if(strpos($lower,'bloqueado')!==false) $statusHistory='overdue';
            elseif(strpos($lower,'aviso')!==false) $statusHistory='warning';
            elseif(strpos($lower,'/pago')!==false || substr($lower,-5)==='/pago') $statusHistory='paid';
            elseif(strpos($lower,'espera')!==false) $statusHistory='paid';
            // Atualiza histórico
            $db->prepare("INSERT INTO mikrotik_payment_history (cliente_id, ano, mes, status) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)")->execute([$cli['id'],$anoAtual,$mesAtual,$statusHistory]);
            // Cria fatura se cliente em aviso/overdue e não tem fatura do mês
            if($statusHistory==='warning' || $statusHistory==='overdue'){
                $stmtFat=$db->prepare("SELECT id FROM faturas WHERE cliente_id=? AND YEAR(data_vencimento)=? AND MONTH(data_vencimento)=? LIMIT 1");
                $stmtFat->execute([$cli['id'],$anoAtual,$mesAtual]);
                if(!$stmtFat->fetchColumn()){
                    $diaVenc=(int)($cli['vencimento_dia']??10); $valorP=(float)($cli['plano_valor']??0);
                    $dataVenc=date('Y-m-d', mktime(0,0,0,$mesAtual,$diaVenc,$anoAtual));
                    $stFat=($statusHistory==='overdue')?'atrasado':'pendente';
                    try { $db->prepare("INSERT INTO faturas (cliente_id, plano_id, valor, data_vencimento, status, descricao, meses_cobertos) VALUES (?,?,?,?,?,?,1)")->execute([$cli['id'],$cli['plano_id'],$valorP,$dataVenc,$stFat,'Mensalidade '.date('m/Y')]); $faturasCriadas++; } catch(Exception $e){}
                }
            }
            $sincronizados++;
        }
        $msgSuccess="Sincronização concluída! {$sincronizados} clientes, {$faturasCriadas} fatura(s) criada(s).";
    } else { $msgError="Falha ao conectar no MikroTik: ".$mkApi->getLastError(); }
}


// TROCA S1/S2 - Mantém status + sempre kick + sincroniza faturas
if($_SERVER['REQUEST_METHOD']==='POST' && $_POST['action']==='trocar_plano_rapido'){
    $clienteId=(int)$_POST['cliente_id']; $novoPlanoId=(int)$_POST['plano_id']; $mesAtual=(int)($_POST['mes'] ?? date('n')); $anoAtual=(int)($_POST['ano'] ?? date('Y'));
    $stmtP=$db->prepare("SELECT * FROM planos WHERE id=?"); $stmtP->execute([$novoPlanoId]); $planoNovo=$stmtP->fetch();
    $stmtC=$db->prepare("SELECT c.id, c.pppoe_usuario, c.roteador_id, mph.status as status_atual FROM clientes c LEFT JOIN mikrotik_payment_history mph ON mph.cliente_id=c.id AND mph.ano=? AND mph.mes=? WHERE c.id=?"); $stmtC->execute([$anoAtual,$mesAtual,$clienteId]); $cli=$stmtC->fetch();
    if($planoNovo && $cli){
        $db->prepare("UPDATE clientes SET plano_id=? WHERE id=?")->execute([$novoPlanoId,$clienteId]);
        $mkApi=new MikrotikAPI($cli['roteador_id']?:1);
        $statusAtual=$cli['status_atual']??'paid';
        $alvo=$planoNovo['profile_mikrotik'];
        if($statusAtual==='overdue') $alvo=$planoNovo['profile_bloqueado']?:$planoNovo['profile_mikrotik'];
        elseif($statusAtual==='warning' || $statusAtual==='npago') $alvo=$planoNovo['profile_aviso']?:$planoNovo['profile_mikrotik'];
        if($mkApi->changeSecretProfile($cli['pppoe_usuario'],$alvo)){
            $mkApi->disconnectActiveSession($cli['pppoe_usuario']);
            $msgSuccess="S1↔S2: '{$planoNovo['nome']}' mantendo status $statusAtual → $alvo + kick.";
        } else { $msgError="Erro MK: ".$mkApi->getLastError(); }
    }
}

// ATUALIZAR STATUS - CORRIGIDO: Sincroniza com página do cliente + lógica kick
if($_SERVER['REQUEST_METHOD']==='POST' && $_POST['action']==='atualizar_status_manual'){
    $clienteId=(int)$_POST['cliente_id']; $novoStatus=sanitize($_POST['status']??'paid'); $mes=(int)($_POST['mes']??date('n')); $ano=(int)($_POST['ano']??date('Y'));
    $stmtPrev=$db->prepare("SELECT status FROM mikrotik_payment_history WHERE cliente_id=? AND ano=? AND mes=?"); $stmtPrev->execute([$clienteId,$ano,$mes]); $statusAnterior=$stmtPrev->fetchColumn() ?: 'paid';
    // 1. Atualiza histórico MikroTik (usado pela página caixa)
    $db->prepare("INSERT INTO mikrotik_payment_history (cliente_id, ano, mes, status) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)")->execute([$clienteId,$ano,$mes,$novoStatus]);
    // 2. CORREÇÃO SINCRONIZAÇÃO: Atualiza também faturas e clientes para página do cliente ficar igual
    if($novoStatus==='paid'){
        // Se marcou como pago na caixa, marca faturas do mês como pagas na página do cliente
        $db->prepare("UPDATE faturas SET status='pago', data_pagamento=NOW() WHERE cliente_id=? AND MONTH(data_vencimento)=? AND YEAR(data_vencimento)=? AND status!='pago'")->execute([$clienteId,$mes,$ano]);
        $db->prepare("UPDATE clientes SET status='ativo' WHERE id=?")->execute([$clienteId]);
        
        try {
            $stmtN = $db->prepare("SELECT nome FROM clientes WHERE id=?");
            $stmtN->execute([$clienteId]);
            $cNome = $stmtN->fetchColumn();
            
            $stmtV = $db->prepare("SELECT p.valor FROM clientes c LEFT JOIN planos p ON c.plano_id=p.id WHERE c.id=?");
            $stmtV->execute([$clienteId]);
            $cValor = (float)($stmtV->fetchColumn() ?: 0);
            
            $tg = new TelegramService();
            $tg->notifyPaymentReceived($cNome, $cValor, 'Manual (Caixa)', 'Dinheiro/PIX Manual');
            
            $wbc = new WebhookCallbackService();
            $wbc->sendPaymentNotification($cNome, $cValor, 'Manual (Caixa)', 'Dinheiro/PIX Manual');
        } catch (Exception $e) {}
    } elseif($novoStatus==='overdue'){
        $db->prepare("UPDATE clientes SET status='suspenso' WHERE id=?")->execute([$clienteId]);
    } elseif($novoStatus==='warning' || $novoStatus==='npago'){
        $db->prepare("UPDATE clientes SET status='aviso' WHERE id=?")->execute([$clienteId]);
    }

    $stmtC=$db->prepare("SELECT c.pppoe_usuario, c.roteador_id, p.profile_mikrotik, p.profile_bloqueado, p.profile_aviso FROM clientes c LEFT JOIN planos p ON c.plano_id=p.id WHERE c.id=?"); $stmtC->execute([$clienteId]); $cliData=$stmtC->fetch();
    if($cliData){
        $mkApi=new MikrotikAPI($cliData['roteador_id']?:1);
        if($novoStatus==='paid') $alvo=$cliData['profile_mikrotik'];
        elseif($novoStatus==='overdue') $alvo=$cliData['profile_bloqueado']?:$cliData['profile_mikrotik'];
        else $alvo=$cliData['profile_aviso']?:$cliData['profile_mikrotik'];
        if($alvo && $mkApi->changeSecretProfile($cliData['pppoe_usuario'],$alvo)){
            $isBloqueioEnvolvido = ($statusAnterior==='overdue' || $novoStatus==='overdue');
            if($isBloqueioEnvolvido){
                $mkApi->disconnectActiveSession($cliData['pppoe_usuario']);
                $msgSuccess="Status $statusAnterior → $novoStatus sincronizado (caixa + cliente) = $alvo + kick.";
            } else {
                $msgSuccess="Status $statusAnterior → $novoStatus sincronizado (caixa + cliente) = $alvo SEM kick (já navegando).";
            }
        }
    }
}

// NOVO: PAGAR MÚLTIPLOS MESES - Cliente pode pagar mais de um mês se preferir
if($_SERVER['REQUEST_METHOD']==='POST' && $_POST['action']==='pagar_multiplos_meses'){
    $clienteId=(int)$_POST['cliente_id']; $meses=(int)($_POST['quantidade_meses']??1); $mesInicio=(int)($_POST['mes']??date('n')); $anoInicio=(int)($_POST['ano']??date('Y'));
    if($meses<1) $meses=1; if($meses>12) $meses=12;
    $mes=$mesInicio; $ano=$anoInicio; $pagos=0;
    for($i=0;$i<$meses;$i++){
        $db->prepare("INSERT INTO mikrotik_payment_history (cliente_id, ano, mes, status) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)")->execute([$clienteId,$ano,$mes,'paid']);
        $db->prepare("UPDATE faturas SET status='pago', data_pagamento=NOW() WHERE cliente_id=? AND MONTH(data_vencimento)=? AND YEAR(data_vencimento)=? AND status!='pago'")->execute([$clienteId,$mes,$ano]);
        $mes++; if($mes>12){ $mes=1; $ano++; }
        $pagos++;
    }
    // Aplica profile NORMAL e kick uma vez
    $stmtC=$db->prepare("SELECT c.pppoe_usuario, c.roteador_id, p.profile_mikrotik, c.nome, p.valor FROM clientes c LEFT JOIN planos p ON c.plano_id=p.id WHERE c.id=?"); $stmtC->execute([$clienteId]); $cliData=$stmtC->fetch();
    if($cliData){
        $mkApi=new MikrotikAPI($cliData['roteador_id']?:1);
        if($mkApi->changeSecretProfile($cliData['pppoe_usuario'],$cliData['profile_mikrotik'])){
            $mkApi->disconnectActiveSession($cliData['pppoe_usuario']);
        }
        $db->prepare("UPDATE clientes SET status='ativo' WHERE id=?")->execute([$clienteId]);
        
        try {
            $valorTotalMulti = (float)($cliData['valor'] ?? 0) * $pagos;
            $tg = new TelegramService();
            $tg->notifyPaymentReceived($cliData['nome'], $valorTotalMulti, 'Manual (Multi-meses: ' . $pagos . 'x)', 'Dinheiro/PIX Manual');
            
            $wbc = new WebhookCallbackService();
            $wbc->sendPaymentNotification($cliData['nome'], $valorTotalMulti, 'Manual (Multi-meses: ' . $pagos . 'x)', 'Dinheiro/PIX Manual');
        } catch (Exception $e) {}
    }
    $msgSuccess="$pagos meses pagos! Cliente liberado com profile NORMAL + kick. Caixa e cliente sincronizados.";
}

$filterStatus=sanitize($_GET['status']??''); $filterPlano=(int)($_GET['plano_id']??0); $filterBusca=sanitize($_GET['busca']??''); $filterMes=(int)($_GET['mes']??date('n')); $filterAno=(int)($_GET['ano']??date('Y')); $limitPerPage=(int)($_GET['per_page']??25); $page=(int)($_GET['page']??1); $offset=($page-1)*$limitPerPage;
$whereClause="WHERE 1=1"; $params=[];
if($filterPlano>0){ $whereClause.=" AND c.plano_id=?"; $params[]=$filterPlano; }
if(!empty($filterBusca)){ if(is_numeric($filterBusca)){ $whereClause.=" AND (c.id=? OR c.nome LIKE ? OR c.pppoe_usuario LIKE ?)"; $params[]=(int)$filterBusca; $params[]="%$filterBusca%"; $params[]="%$filterBusca%"; } else { $whereClause.=" AND (c.nome LIKE ? OR c.pppoe_usuario LIKE ? OR c.cpf_cnpj LIKE ?)"; $params[]="%$filterBusca%"; $params[]="%$filterBusca%"; $params[]="%$filterBusca%"; } }
if(!empty($filterStatus)){ $whereClause.=" AND mph.status=?"; $params[]=$filterStatus; }
$planosDisponiveis=$db->query("SELECT * FROM planos ORDER BY nome ASC")->fetchAll();
$mesesNomes=[1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro'];
$sql="SELECT c.*, p.nome as plano_nome, p.valor as plano_valor, p.velocidade_down, mph.status as history_status FROM clientes c LEFT JOIN planos p ON c.plano_id=p.id LEFT JOIN mikrotik_payment_history mph ON mph.cliente_id=c.id AND mph.ano=? AND mph.mes=? $whereClause ORDER BY c.id DESC LIMIT $limitPerPage OFFSET $offset";
$stmt=$db->prepare($sql); $stmt->execute(array_merge([$filterAno,$filterMes],$params)); $clientesLista=$stmt->fetchAll();
$stmtCount=$db->prepare("SELECT COUNT(*) FROM clientes c LEFT JOIN mikrotik_payment_history mph ON mph.cliente_id=c.id AND mph.ano=? AND mph.mes=? $whereClause"); $stmtCount->execute(array_merge([$filterAno,$filterMes],$params)); $totalRegistros=$stmtCount->fetchColumn();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h4 class="text-white fw-bold mb-1"><i class="fa-solid fa-cash-register text-success me-2"></i>Caixa & Finanças - <?= $mesesNomes[$filterMes] ?>/<?= $filterAno ?></h4><p class="text-secondary small mb-0">Filtrados: <?= $totalRegistros ?> | Status sincronizado caixa ↔ cliente</p></div>
    <a href="caixa.php?action=sincronizar_mikrotik&mes=<?= $filterMes ?>&ano=<?= $filterAno ?>" class="btn btn-outline-info"><i class="fa-solid fa-rotate me-1"></i> Sincronizar com MikroTik</a>
</div>
<?php if($msgSuccess): ?><div class="alert alert-success alert-dismissible fade show"><?= $msgSuccess ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if($msgError): ?><div class="alert alert-danger alert-dismissible fade show"><?= $msgError ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<div class="card-custom mb-4">
    <form method="GET" action="caixa.php" class="row g-2">
        <div class="col-md-2"><select name="status" class="form-control-custom"><option value="">Todos status</option><option value="paid" <?= $filterStatus=='paid'?'selected':'' ?>>Pago</option><option value="warning" <?= $filterStatus=='warning'?'selected':'' ?>>Aviso</option><option value="overdue" <?= $filterStatus=='overdue'?'selected':'' ?>>Atraso</option><option value="npago" <?= $filterStatus=='npago'?'selected':'' ?>>Não Pago</option></select></div>
        <div class="col-md-2"><select name="plano_id" class="form-control-custom"><option value="0">Todos planos</option><?php foreach($planosDisponiveis as $pl): ?><option value="<?= $pl['id'] ?>" <?= $filterPlano==$pl['id']?'selected':'' ?>><?= htmlspecialchars($pl['nome']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><input type="text" name="busca" class="form-control-custom" placeholder="Nome, usuário, CPF" value="<?= htmlspecialchars($filterBusca) ?>"></div>
        <div class="col-md-1"><select name="mes" class="form-control-custom"><?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $filterMes==$m?'selected':'' ?>><?= $m ?></option><?php endfor; ?></select></div>
        <div class="col-md-1"><select name="ano" class="form-control-custom"><?php for($a=date('Y');$a>=2023;$a--): ?><option value="<?= $a ?>" <?= $filterAno==$a?'selected':'' ?>><?= $a ?></option><?php endfor; ?></select></div>
        <div class="col-md-1"><select name="per_page" class="form-control-custom"><option value="25" <?= $limitPerPage==25?'selected':'' ?>>25</option><option value="50" <?= $limitPerPage==50?'selected':'' ?>>50</option><option value="100" <?= $limitPerPage==100?'selected':'' ?>>100</option></select></div>
        <div class="col-md-2 d-flex gap-1"><button class="btn btn-primary-custom flex-fill">Filtrar</button><a href="caixa.php" class="btn btn-outline-light">Limpar</a></div>
    </form>
</div>
<div class="card-custom">
    <div class="table-responsive">
        <table class="table-custom">
            <thead><tr><th># ID</th><th>CLIENTE</th><th>PLANO ATUAL</th><th>VALOR</th><th>STATUS MÊS</th><th>TROCA RÁPIDA - EQUIVALENTE S1/S2</th><th>AÇÕES</th></tr></thead>
            <tbody>
                <?php foreach($clientesLista as $c):
                    $statusBadge='bg-secondary'; $statusText='Sem Registro';
                    if(($c['history_status']??'')==='paid'){ $statusBadge='bg-success'; $statusText='Pago'; }
                    elseif(($c['history_status']??'')==='warning'){ $statusBadge='bg-warning text-dark'; $statusText='Aviso'; }
                    elseif(($c['history_status']??'')==='overdue'){ $statusBadge='bg-danger'; $statusText='Atraso'; }
                    elseif(($c['history_status']??'')==='npago'){ $statusBadge='bg-dark border'; $statusText='Não Pago'; }
                    $planoAtual=null; foreach($planosDisponiveis as $pp){ if($pp['id']==$c['plano_id']){ $planoAtual=$pp; break; } }
                    $equivalentes=[]; $s1=null; $s2=null;
                    if($planoAtual){
                        $vel=$planoAtual['velocidade_down'] ?? 0;
                        foreach($planosDisponiveis as $pl){ if(($pl['velocidade_down'] ?? 0)!=$vel) continue; $upper=strtoupper($pl['nome']); if(strpos($upper,'S1-')===0 && !$s1) $s1=$pl; if(strpos($upper,'S2-')===0 && !$s2) $s2=$pl; }
                        if(!$s1){ foreach($planosDisponiveis as $pl){ if(stripos($pl['nome'],'S1-40Megas-01')!==false){ $s1=$pl; break; } } }
                        if(!$s2){ foreach($planosDisponiveis as $pl){ if(stripos($pl['nome'],'S2-40Megas-01')!==false){ $s2=$pl; break; } } }
                        if($s1) $equivalentes[]=$s1; if($s2) $equivalentes[]=$s2;
                    }
                    if(empty($equivalentes)) $equivalentes=array_slice($planosDisponiveis,0,2);
                ?>
                <tr>
                    <td>#<?= $c['id'] ?></td>
                    <td><strong class="text-white"><?= htmlspecialchars($c['pppoe_usuario']) ?></strong><br><code class="text-info small"><?= htmlspecialchars($c['pppoe_usuario']) ?> R<?= $c['roteador_id'] ?></code></td>
                    <td><span class="badge bg-dark border border-secondary text-info" style="font-size:0.72rem"><?= htmlspecialchars($c['plano_nome'] ?? 'S1-40Megas-10') ?></span><br><small class="text-secondary">40M</small></td>
                    <td class="fw-bold text-success">R$ <?= number_format($c['plano_valor'] ?? 50, 2, ',', '.') ?></td>
                    <td><span class="badge <?= $statusBadge ?>" style="font-size:0.75rem; padding:6px 12px; border-radius:20px"><?= $statusText ?></span></td>
                    <td><div class="d-flex flex-column gap-1"><?php foreach($equivalentes as $plItem): $isAtual=$c['plano_id']==$plItem['id']; $btnClass=$isAtual?'btn-info':'btn-outline-secondary'; ?><form method="POST" action="caixa.php" class="m-0"><input type="hidden" name="action" value="trocar_plano_rapido"><input type="hidden" name="cliente_id" value="<?= $c['id'] ?>"><input type="hidden" name="plano_id" value="<?= $plItem['id'] ?>"><input type="hidden" name="mes" value="<?= $filterMes ?>"><input type="hidden" name="ano" value="<?= $filterAno ?>"><button type="submit" class="btn btn-xs w-100 <?= $btnClass ?> <?= $isAtual?'fw-bold':'' ?>" style="font-size:0.72rem; padding:3px 8px; border-radius:6px"><?= htmlspecialchars($plItem['nome']) ?><?= $isAtual?' ●':'' ?></button></form><?php endforeach; ?></div></td>
                    <td>
                        <div class="d-flex gap-1">
                            <button type="button" class="btn btn-sm btn-outline-info" onclick="abrirModalStatus(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['nome'])) ?>', '<?= $c['history_status'] ?? 'paid' ?>')" title="Alterar Status"><i class="fa-solid fa-pen-to-square"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-warning" onclick="abrirModalMultiMeses(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['nome'])) ?>')" title="Pagar múltiplos meses"><i class="fa-solid fa-calendar-check"></i></button>
                            <a href="historico.php?cliente_id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-light"><i class="fa-solid fa-clock-rotate-left"></i></a>
                            <a href="faturas.php?cliente_id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-success"><i class="fa-solid fa-file-invoice-dollar"></i></a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Status -->
<div class="modal fade" id="modalAtualizarStatus" tabindex="-1"><div class="modal-dialog"><div class="modal-content bg-dark text-light border-secondary"><div class="modal-header border-secondary"><h5 class="modal-title">Atualizar Status - <span id="modalClienteNome" class="text-info"></span></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form method="POST" action=""><input type="hidden" name="action" value="atualizar_status_manual"><input type="hidden" name="cliente_id" id="modalClienteId"><input type="hidden" name="mes" value="<?= $filterMes ?>"><input type="hidden" name="ano" value="<?= $filterAno ?>"><div class="modal-body"><select name="status" id="modalStatusSelect" class="form-control-custom"><option value="paid">Pago - Sincroniza caixa↔cliente</option><option value="warning">Aviso</option><option value="overdue">Atraso/Bloqueado</option><option value="npago">Não Pago/Espera</option></select><div id="mkProfileResult" class="mt-2 text-secondary small">Clique em Buscar do MikroTik</div><small class="text-secondary">Ao mudar para Pago, faturas do mês na página do cliente também ficam pagas.</small></div><div class="modal-footer border-secondary justify-content-between"><button type="button" class="btn btn-outline-info" onclick="buscarDoMikrotik()">Buscar do MikroTik</button><button type="submit" class="btn btn-primary-custom">Salvar e Sincronizar</button></div></form></div></div></div>

<!-- Modal Múltiplos Meses - NOVO -->
<div class="modal fade" id="modalMultiMeses" tabindex="-1"><div class="modal-dialog"><div class="modal-content bg-dark text-light border-secondary"><div class="modal-header border-secondary"><h5 class="modal-title">Pagar Múltiplos Meses - <span id="modalMultiClienteNome" class="text-info"></span></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form method="POST" action=""><input type="hidden" name="action" value="pagar_multiplos_meses"><input type="hidden" name="cliente_id" id="modalMultiClienteId"><input type="hidden" name="mes" value="<?= $filterMes ?>"><input type="hidden" name="ano" value="<?= $filterAno ?>"><div class="modal-body"><label class="form-label">Quantidade de meses para pagar:</label><select name="quantidade_meses" class="form-control-custom"><option value="1">1 mês</option><option value="2">2 meses</option><option value="3">3 meses</option><option value="6">6 meses</option><option value="12">12 meses</option></select><small class="text-secondary d-block mt-2">Cliente pode pagar mais de um mês se preferir. Todos os meses serão marcados como pagos e sincronizados com a página do cliente.</small></div><div class="modal-footer border-secondary"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-warning fw-bold">Pagar Múltiplos Meses</button></div></form></div></div></div>

<script>
let clienteIdAtual=0;
function abrirModalStatus(id,nome,status){clienteIdAtual=id;document.getElementById('modalClienteId').value=id;document.getElementById('modalClienteNome').innerText=nome;document.getElementById('modalStatusSelect').value=status;new bootstrap.Modal(document.getElementById('modalAtualizarStatus')).show();}
function abrirModalMultiMeses(id,nome){document.getElementById('modalMultiClienteId').value=id;document.getElementById('modalMultiClienteNome').innerText=nome;new bootstrap.Modal(document.getElementById('modalMultiMeses')).show();}
function buscarDoMikrotik(){fetch(`caixa.php?ajax=buscar_perfil&cliente_id=${clienteIdAtual}`).then(r=>r.json()).then(d=>{document.getElementById('mkProfileResult').innerHTML=d.success?d.usuario+' - '+d.profile:d.error});}
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
