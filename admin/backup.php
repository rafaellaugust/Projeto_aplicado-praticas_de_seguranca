<?php
require_once __DIR__ . '/../config.php';
checkAdminLogin();

$backupService = new BackupService();
$msgSuccess = '';
$msgError = '';

// Download de backup
if (isset($_GET['action']) && $_GET['action'] === 'download' && !empty($_GET['file'])) {
    $backupService->downloadBackup($_GET['file']);
    exit;
}

// Ações POST (Gerar, Salvar Agendamento, Excluir, Restaurar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'gerar_backup') {
        $compress = isset($_POST['compactar']) ? true : false;
        $res = $backupService->generateBackup('manual', $compress);
        if ($res['sucesso']) {
            $msgSuccess = "Backup gerado com sucesso! Arquivo: <strong>{$res['arquivo']}</strong> ({$res['tamanho']})";
        } else {
            $msgError = "Erro ao gerar backup: " . htmlspecialchars($res['erro'] ?? 'Erro desconhecido');
        }
    } elseif ($action === 'salvar_agendamento') {
        $ok = $backupService->saveConfig($_POST);
        if ($ok) {
            $msgSuccess = "Configurações de agendamento de backup salvas com sucesso!";
        } else {
            $msgError = "Erro ao salvar configurações de agendamento.";
        }
    } elseif ($action === 'excluir') {
        $file = $_POST['file'] ?? '';
        if ($backupService->deleteBackup($file)) {
            $msgSuccess = "Arquivo de backup <strong>" . htmlspecialchars($file) . "</strong> excluído com sucesso!";
        } else {
            $msgError = "Erro ao excluir arquivo de backup ou arquivo inválido.";
        }
    } elseif ($action === 'restaurar') {
        $file = $_POST['file'] ?? '';
        $res = $backupService->restoreBackup($file);
        if ($res['sucesso']) {
            $msgSuccess = "Banco de dados restaurado com êxito a partir de <strong>" . htmlspecialchars($file) . "</strong>!";
        } else {
            $msgError = "Erro na restauração do backup: " . htmlspecialchars($res['erro'] ?? 'Erro desconhecido');
        }
    }
}

$cfg = $backupService->getConfig();
$listaBackups = $backupService->listBackups();

require_once __DIR__ . '/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h4 class="text-white fw-bold mb-1">
            <i class="fa-solid fa-database text-warning me-2"></i>Backup do Banco de Dados (SQL)
        </h4>
        <p class="text-secondary small mb-0">
            Gerencie cópias de segurança do MySQL (<code><?= DB_NAME ?></code>), agendamentos automáticos e restaurações.
        </p>
    </div>
    <div class="d-flex gap-2">
        <form method="POST" class="d-inline">
            <input type="hidden" name="action" value="gerar_backup">
            <input type="hidden" name="compactar" value="1">
            <button type="submit" class="btn btn-warning text-dark fw-semibold" onclick="return confirm('Deseja iniciar a geração manual do backup agora?');">
                <i class="fa-solid fa-cloud-arrow-down me-1"></i> Gerar Backup Agora (.gz)
            </button>
        </form>
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

<div class="row g-4">
    <!-- COLUNA ESQUERDA: AGENDAMENTO AUTOMÁTICO -->
    <div class="col-lg-4">
        <div class="card-custom h-100">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="text-white fw-bold mb-0">
                    <i class="fa-solid fa-clock-rotate-left text-warning me-2"></i>Agendamento Automático
                </h5>
                <span class="badge-custom <?= !empty($cfg['backup_ativo']) ? 'badge-ativo' : 'badge-suspenso' ?>">
                    <?= !empty($cfg['backup_ativo']) ? 'Ativo' : 'Pausado' ?>
                </span>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="salvar_agendamento">

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="backup_ativo" name="backup_ativo" value="1" <?= !empty($cfg['backup_ativo']) ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="backup_ativo">Habilitar Backup Automático</label>
                    <div class="form-text text-secondary" style="font-size: 0.78rem;">O sistema gerará cópias periódicas conforme o cron configurado.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label small text-secondary">Frequência</label>
                    <select name="backup_frequencia" class="form-control-custom">
                        <option value="diario" <?= ($cfg['backup_frequencia'] === 'diario') ? 'selected' : '' ?>>Diário (Todo dia no horário)</option>
                        <option value="semanal" <?= ($cfg['backup_frequencia'] === 'semanal') ? 'selected' : '' ?>>Semanal (A cada 7 dias)</option>
                        <option value="mensal" <?= ($cfg['backup_frequencia'] === 'mensal') ? 'selected' : '' ?>>Mensal (A cada 30 dias)</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small text-secondary">Horário Previsto</label>
                    <input type="time" name="backup_hora" class="form-control-custom" value="<?= htmlspecialchars($cfg['backup_hora'] ?? '03:00') ?>" required>
                    <small class="text-secondary d-block mt-1">Recomenda-se horário de menor tráfego (ex: 03:00 da madrugada).</small>
                </div>

                <div class="mb-3">
                    <label class="form-label small text-secondary">Retenção (Manter últimos)</label>
                    <div class="d-flex align-items-center gap-2">
                        <input type="number" name="backup_manter_qtd" class="form-control-custom" min="1" max="60" value="<?= (int)($cfg['backup_manter_qtd'] ?? 7) ?>" required style="max-width: 100px;">
                        <span class="text-secondary small">backups</span>
                    </div>
                    <small class="text-secondary d-block mt-1">Backups excedentes mais antigos serão deletados automaticamente.</small>
                </div>

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="backup_compactar_gz" name="backup_compactar_gz" value="1" <?= !empty($cfg['backup_compactar_gz']) ? 'checked' : '' ?>>
                    <label class="form-check-label text-secondary small" for="backup_compactar_gz">Compactar arquivos com GZIP (.gz)</label>
                </div>

                <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" role="switch" id="backup_notificar_tg" name="backup_notificar_tg" value="1" <?= !empty($cfg['backup_notificar_tg']) ? 'checked' : '' ?>>
                    <label class="form-check-label text-secondary small" for="backup_notificar_tg">Notificar no Telegram</label>
                </div>

                <div class="p-3 rounded mb-3" style="background: var(--bg-input); border: 1px dashed var(--border-color);">
                    <div class="text-secondary small mb-1">Último backup automático:</div>
                    <div class="text-white fw-bold">
                        <?= !empty($cfg['backup_ultimo_em']) ? date('d/m/Y H:i:s', strtotime($cfg['backup_ultimo_em'])) : '<span class="text-muted">Nenhum registrado</span>' ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary-custom w-100">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Salvar Agendamento
                </button>
            </form>

            <hr style="border-color: var(--border-color); opacity: 0.3;" class="my-3">

            <div class="p-2 rounded" style="background: var(--bg-input); border: 1px solid var(--border-color); font-size: 0.75rem;">
                <div class="fw-bold text-info mb-1"><i class="fa-solid fa-terminal me-1"></i> Comando Cron (cPanel):</div>
                <code class="text-warning user-select-all d-block p-1 rounded" style="background: #000;">/usr/local/bin/php <?= realpath(__DIR__ . '/../cron/backup.php') ?: '/home/usuario/public_html/cron/backup.php' ?></code>
            </div>
        </div>
    </div>

    <!-- COLUNA DIREITA: LISTA DE BACKUPS ARMAZENADOS -->
    <div class="col-lg-8">
        <div class="card-custom">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <h5 class="text-white fw-bold mb-0">
                    <i class="fa-solid fa-folder-tree text-info me-2"></i>Backups Armazenados no Servidor
                </h5>
                <span class="badge-custom badge-pendente">
                    <?= count($listaBackups) ?> arquivo(s)
                </span>
            </div>

            <?php if (empty($listaBackups)): ?>
                <div class="text-center py-5 text-secondary">
                    <i class="fa-solid fa-box-open fa-3x mb-3" style="color: var(--border-color);"></i>
                    <p class="mb-2">Nenhum arquivo de backup encontrado na pasta <code>/backups/</code>.</p>
                    <small>Clique no botão <strong>"Gerar Backup Agora"</strong> acima para criar a primeira cópia de segurança.</small>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Arquivo</th>
                                <th>Origem</th>
                                <th>Tamanho</th>
                                <th>Data / Hora</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($listaBackups as $b): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fa-solid <?= $b['compactado'] ? 'fa-file-zipper text-warning' : 'fa-file-lines text-info' ?>"></i>
                                            <div>
                                                <span class="fw-semibold text-break" style="font-size:0.85rem;"><?= htmlspecialchars($b['nome']) ?></span>
                                                <?php if ($b['compactado']): ?>
                                                    <span class="badge-custom badge-pendente ms-1" style="font-size:0.6rem; padding: 2px 6px;">GZ</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge-custom <?= $b['tipo'] === 'Agendado' ? 'badge-ativo' : 'badge-suspenso' ?>">
                                            <i class="fa-solid <?= $b['tipo'] === 'Agendado' ? 'fa-clock' : 'fa-user' ?>"></i>
                                            <?= $b['tipo'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-secondary small fw-semibold"><?= $b['tamanho'] ?></span>
                                    </td>
                                    <td class="text-secondary small">
                                        <?= $b['data'] ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1 flex-wrap">
                                            <a href="backup.php?action=download&file=<?= urlencode($b['nome']) ?>" class="btn btn-sm btn-outline-info" title="Baixar Arquivo">
                                                <i class="fa-solid fa-download"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-warning" title="Restaurar Banco de Dados" onclick="confirmarRestauracao('<?= htmlspecialchars($b['nome']) ?>')">
                                                <i class="fa-solid fa-rotate-left"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Tem certeza que deseja excluir o backup <?= htmlspecialchars($b['nome']) ?>?');">
                                                <input type="hidden" name="action" value="excluir">
                                                <input type="hidden" name="file" value="<?= htmlspecialchars($b['nome']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir Backup">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 pt-3 text-secondary small" style="border-top: 1px solid var(--border-color);">
                <span><i class="fa-solid fa-shield-halved text-success me-1"></i> Diretório protegido via <code>.htaccess</code></span>
                <span>Banco: <strong><?= DB_NAME ?></strong></span>
            </div>
        </div>
    </div>
</div>

<!-- MODAL DE CONFIRMAÇÃO DE RESTAURAÇÃO -->
<div class="modal fade" id="modalRestore" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background: var(--bg-card); color: var(--text-primary); border: 1px solid var(--danger-red);">
            <div class="modal-header" style="border-color: var(--border-color); background: rgba(239,68,68,0.08);">
                <h5 class="modal-title fw-bold" style="color: var(--danger-red);">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i>Atenção: Restaurar Banco de Dados
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="restaurar">
                <input type="hidden" name="file" id="restoreFileName">
                <div class="modal-body">
                    <p class="mb-3">
                        Você está prestes a restaurar o banco de dados a partir do arquivo:
                        <br><strong class="text-warning" id="restoreFileDisplay"></strong>
                    </p>
                    <div class="p-3 rounded" style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); font-size: 0.88rem;">
                        <i class="fa-solid fa-circle-exclamation me-1" style="color: var(--danger-red);"></i>
                        <strong>CUIDADO:</strong> Todas as tabelas e dados atuais serão sobrescritos pelos dados contidos nesta cópia de segurança. Essa ação é irreversível.
                    </div>
                </div>
                <div class="modal-footer" style="border-color: var(--border-color);">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger fw-bold">
                        <i class="fa-solid fa-rotate-left me-1"></i> Sim, Restaurar Agora
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function confirmarRestauracao(arquivo) {
    document.getElementById('restoreFileName').value = arquivo;
    document.getElementById('restoreFileDisplay').textContent = arquivo;
    var modal = new bootstrap.Modal(document.getElementById('modalRestore'));
    modal.show();
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>