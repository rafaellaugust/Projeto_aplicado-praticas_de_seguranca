<?php

/**
 * Serviço de Backup do Banco de Dados MySQL / MariaDB (Pure PHP / PDO)
 * 
 * Funcionalidades:
 * - Dump completo estruturado (DROP TABLE, CREATE TABLE, INSERT chunked com quote seguro)
 * - Suporte opcional à compactação GZIP (.sql.gz) para economia de até 90% de espaço
 * - Descompactação e restauração segura com desativação temporária de foreign keys
 * - Listagem, download seguro e exclusão de backups em /backups/
 * - Gerenciamento de retenção (pruning/limpeza dos mais antigos)
 * - Verificação e execução de agendamento automático com notificação Telegram
 */
class BackupService {
    private PDO $db;
    private string $backupDir;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->backupDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups';
        
        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0755, true);
        }

        $this->ensureConfigColumns();
    }

    /**
     * Garante que a tabela configuracoes tenha os campos necessários de agendamento de backup
     */
    private function ensureConfigColumns(): void {
        try {
            $cols = $this->db->query("SHOW COLUMNS FROM configuracoes")->fetchAll(PDO::FETCH_COLUMN);
            $required = [
                'backup_ativo'        => "ALTER TABLE configuracoes ADD COLUMN backup_ativo TINYINT(1) DEFAULT 1",
                'backup_hora'         => "ALTER TABLE configuracoes ADD COLUMN backup_hora VARCHAR(5) DEFAULT '03:00'",
                'backup_frequencia'   => "ALTER TABLE configuracoes ADD COLUMN backup_frequencia VARCHAR(20) DEFAULT 'diario'",
                'backup_manter_qtd'   => "ALTER TABLE configuracoes ADD COLUMN backup_manter_qtd INT DEFAULT 7",
                'backup_notificar_tg' => "ALTER TABLE configuracoes ADD COLUMN backup_notificar_tg TINYINT(1) DEFAULT 1",
                'backup_compactar_gz' => "ALTER TABLE configuracoes ADD COLUMN backup_compactar_gz TINYINT(1) DEFAULT 1",
                'backup_ultimo_em'    => "ALTER TABLE configuracoes ADD COLUMN backup_ultimo_em DATETIME DEFAULT NULL"
            ];

            foreach ($required as $col => $sql) {
                if (!in_array($col, $cols, true)) {
                    $this->db->exec($sql);
                }
            }
        } catch (Exception $e) {
            Database::log('backup_error', 'Erro ao verificar colunas de backup em configuracoes: ' . $e->getMessage());
        }
    }

    /**
     * Retorna as configurações salvas de backup
     */
    public function getConfig(): array {
        try {
            $stmt = $this->db->query("
                SELECT backup_ativo, backup_hora, backup_frequencia, backup_manter_qtd, 
                       backup_notificar_tg, backup_compactar_gz, backup_ultimo_em 
                FROM configuracoes WHERE id = 1
            ");
            $config = $stmt->fetch();
            if ($config) {
                return [
                    'backup_ativo'        => (int)($config['backup_ativo'] ?? 1),
                    'backup_hora'         => $config['backup_hora'] ?? '03:00',
                    'backup_frequencia'   => $config['backup_frequencia'] ?? 'diario',
                    'backup_manter_qtd'   => (int)($config['backup_manter_qtd'] ?? 7),
                    'backup_notificar_tg' => (int)($config['backup_notificar_tg'] ?? 1),
                    'backup_compactar_gz' => (int)($config['backup_compactar_gz'] ?? 1),
                    'backup_ultimo_em'    => $config['backup_ultimo_em'] ?? null
                ];
            }
        } catch (Exception $e) {
            Database::log('backup_error', 'Erro ao ler configuracoes de backup: ' . $e->getMessage());
        }

        return [
            'backup_ativo'        => 1,
            'backup_hora'         => '03:00',
            'backup_frequencia'   => 'diario',
            'backup_manter_qtd'   => 7,
            'backup_notificar_tg' => 1,
            'backup_compactar_gz' => 1,
            'backup_ultimo_em'    => null
        ];
    }

    /**
     * Salva as configurações de agendamento de backup
     */
    public function saveConfig(array $data): bool {
        try {
            $ativo = isset($data['backup_ativo']) ? 1 : 0;
            $hora = preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $data['backup_hora'] ?? '') ? $data['backup_hora'] : '03:00';
            $freq = in_array($data['backup_frequencia'] ?? '', ['diario', 'semanal', 'mensal'], true) ? $data['backup_frequencia'] : 'diario';
            $manterQtd = max(1, min(100, (int)($data['backup_manter_qtd'] ?? 7)));
            $notificarTg = isset($data['backup_notificar_tg']) ? 1 : 0;
            $compactarGz = isset($data['backup_compactar_gz']) ? 1 : 0;

            $stmt = $this->db->prepare("
                UPDATE configuracoes 
                SET backup_ativo = ?, backup_hora = ?, backup_frequencia = ?, 
                    backup_manter_qtd = ?, backup_notificar_tg = ?, backup_compactar_gz = ? 
                WHERE id = 1
            ");
            return $stmt->execute([$ativo, $hora, $freq, $manterQtd, $notificarTg, $compactarGz]);
        } catch (Exception $e) {
            Database::log('backup_error', 'Erro ao salvar configuracoes de backup: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gera o dump completo do Banco de Dados via PDO puro
     * 
     * @param string $tipo 'manual' | 'agendado'
     * @param bool|null $compress Se null, usa a opção da tabela de configurações
     * @return array ['sucesso' => bool, 'arquivo' => string, 'tamanho' => string, 'tamanho_bytes' => int, 'erro' => ?string]
     */
    public function generateBackup(string $tipo = 'manual', ?bool $compress = null): array {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $cfg = $this->getConfig();
        if ($compress === null) {
            $compress = (bool)($cfg['backup_compactar_gz'] ?? 1);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $baseName = 'backup_' . DB_NAME . '_' . $tipo . '_' . $timestamp;
        $sqlPath = $this->backupDir . DIRECTORY_SEPARATOR . $baseName . '.sql';
        $finalPath = $compress ? $sqlPath . '.gz' : $sqlPath;

        $fp = fopen($sqlPath, 'w');
        if (!$fp) {
            return [
                'sucesso' => false,
                'arquivo' => '',
                'tamanho' => '0 B',
                'tamanho_bytes' => 0,
                'erro' => 'Não foi possível criar o arquivo de backup no diretório /backups/ (verifique permissões de escrita).'
            ];
        }

        try {
            // Cabeçalho do Backup
            fwrite($fp, "-- ============================================================\n");
            fwrite($fp, "-- BACKUP BANCO DE DADOS MIKROTIK PROVEDOR\n");
            fwrite($fp, "-- Banco: " . DB_NAME . "\n");
            fwrite($fp, "-- Host: " . DB_HOST . "\n");
            fwrite($fp, "-- Tipo: " . strtoupper($tipo) . "\n");
            fwrite($fp, "-- Data/Hora: " . date('Y-m-d H:i:s') . "\n");
            fwrite($fp, "-- Servidor PHP: " . phpversion() . "\n");
            fwrite($fp, "-- ============================================================\n\n");
            fwrite($fp, "SET FOREIGN_KEY_CHECKS = 0;\n");
            fwrite($fp, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
            fwrite($fp, "SET AUTOCOMMIT = 0;\n");
            fwrite($fp, "START TRANSACTION;\n");
            fwrite($fp, "SET NAMES " . DB_CHAR . ";\n\n");

            // Obter lista de tabelas
            $tablesStmt = $this->db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                // Estrutura da tabela
                fwrite($fp, "-- ------------------------------------------------------------\n");
                fwrite($fp, "-- Estrutura para tabela `{$table}`\n");
                fwrite($fp, "-- ------------------------------------------------------------\n");
                fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");

                $createStmt = $this->db->query("SHOW CREATE TABLE `{$table}`");
                $createRow = $createStmt->fetch(PDO::FETCH_NUM);
                $createSql = $createRow[1] ?? '';
                fwrite($fp, $createSql . ";\n\n");

                // Dados da tabela em chunks para não estourar memória
                $countStmt = $this->db->query("SELECT COUNT(*) FROM `{$table}`");
                $totalRows = (int)$countStmt->fetchColumn();

                if ($totalRows > 0) {
                    fwrite($fp, "-- Dados da tabela `{$table}` (Total: {$totalRows} registros)\n");

                    $chunkSize = 250;
                    $offset = 0;

                    while ($offset < $totalRows) {
                        $selectStmt = $this->db->query("SELECT * FROM `{$table}` LIMIT {$chunkSize} OFFSET {$offset}");
                        $rows = $selectStmt->fetchAll(PDO::FETCH_ASSOC);
                        if (empty($rows)) {
                            break;
                        }

                        $cols = array_keys($rows[0]);
                        $escapedCols = array_map(function($c) { return "`{$c}`"; }, $cols);
                        $colsList = implode(', ', $escapedCols);

                        $valuesArr = [];
                        foreach ($rows as $row) {
                            $rowValues = [];
                            foreach ($row as $val) {
                                if ($val === null) {
                                    $rowValues[] = 'NULL';
                                } else {
                                    $rowValues[] = $this->db->quote($val);
                                }
                            }
                            $valuesArr[] = '(' . implode(', ', $rowValues) . ')';
                        }

                        $insertSql = "INSERT INTO `{$table}` ({$colsList}) VALUES\n" . implode(",\n", $valuesArr) . ";\n";
                        fwrite($fp, $insertSql);

                        $offset += $chunkSize;
                    }
                    fwrite($fp, "\n");
                }
            }

            // Rodapé do Backup
            fwrite($fp, "SET FOREIGN_KEY_CHECKS = 1;\n");
            fwrite($fp, "COMMIT;\n");
            fwrite($fp, "-- ============================================================\n");
            fwrite($fp, "-- FIM DO BACKUP (" . date('Y-m-d H:i:s') . ")\n");
            fwrite($fp, "-- ============================================================\n");

            fclose($fp);

            // Compactação GZIP se ativado
            if ($compress && function_exists('gzencode')) {
                $rawContent = file_get_contents($sqlPath);
                $gzContent = gzencode($rawContent, 9);
                if ($gzContent !== false) {
                    file_put_contents($finalPath, $gzContent);
                    @unlink($sqlPath);
                } else {
                    $finalPath = $sqlPath;
                }
            }

            $tamanhoBytes = filesize($finalPath);
            $tamanhoFmt = $this->formatBytes($tamanhoBytes);
            $nomeArquivo = basename($finalPath);

            // Atualiza timestamp do último backup na tabela de configurações
            $this->db->exec("UPDATE configuracoes SET backup_ultimo_em = NOW() WHERE id = 1");

            // Notificação no Telegram se habilitado
            if (!empty($cfg['backup_notificar_tg'])) {
                $this->notifyTelegramBackupSuccess($nomeArquivo, $tamanhoFmt, $tipo);
            }

            // Aplica rotação de retenção (remove excedentes)
            $this->pruneOldBackups((int)($cfg['backup_manter_qtd'] ?? 7));

            Database::log('backup_sucesso', "Backup SQL {$tipo} gerado: {$nomeArquivo} ({$tamanhoFmt})");

            return [
                'sucesso' => true,
                'arquivo' => $nomeArquivo,
                'tamanho' => $tamanhoFmt,
                'tamanho_bytes' => $tamanhoBytes,
                'erro' => null
            ];

        } catch (Exception $e) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            if (file_exists($sqlPath)) {
                @unlink($sqlPath);
            }
            if (file_exists($finalPath)) {
                @unlink($finalPath);
            }

            $erroMsg = $e->getMessage();
            Database::log('backup_error', "Erro ao gerar backup SQL: {$erroMsg}");

            if (!empty($cfg['backup_notificar_tg'])) {
                $this->notifyTelegramBackupFailure($tipo, $erroMsg);
            }

            return [
                'sucesso' => false,
                'arquivo' => '',
                'tamanho' => '0 B',
                'tamanho_bytes' => 0,
                'erro' => $erroMsg
            ];
        }
    }

    /**
     * Lista todos os backups presentes na pasta /backups/
     */
    public function listBackups(): array {
        $backups = [];
        if (!is_dir($this->backupDir)) {
            return $backups;
        }

        $files = scandir($this->backupDir);
        foreach ($files as $f) {
            if ($f === '.' || $f === '..' || $f === '.htaccess' || $f === '.gitignore') {
                continue;
            }

            $path = $this->backupDir . DIRECTORY_SEPARATOR . $f;
            if (is_file($path) && (str_ends_with($f, '.sql') || str_ends_with($f, '.sql.gz'))) {
                $sizeBytes = filesize($path);
                $mtime = filemtime($path);
                $tipo = strpos($f, '_agendado_') !== false ? 'Agendado' : 'Manual';
                $isGz = str_ends_with($f, '.sql.gz');

                $backups[] = [
                    'nome' => $f,
                    'tipo' => $tipo,
                    'compactado' => $isGz,
                    'tamanho_bytes' => $sizeBytes,
                    'tamanho' => $this->formatBytes($sizeBytes),
                    'data_timestamp' => $mtime,
                    'data' => date('d/m/Y H:i:s', $mtime)
                ];
            }
        }

        // Ordena do mais recente para o mais antigo
        usort($backups, function ($a, $b) {
            return $b['data_timestamp'] <=> $a['data_timestamp'];
        });

        return $backups;
    }

    /**
     * Exclui um arquivo de backup com validação estrita de segurança
     */
    public function deleteBackup(string $filename): bool {
        $filename = basename($filename);
        if (!preg_match('/^backup_.*\.sql(\.gz)?$/', $filename)) {
            return false;
        }

        $path = $this->backupDir . DIRECTORY_SEPARATOR . $filename;
        if (file_exists($path) && is_file($path)) {
            $ok = @unlink($path);
            if ($ok) {
                Database::log('backup_delete', "Arquivo de backup removido: {$filename}");
            }
            return $ok;
        }

        return false;
    }

    /**
     * Transmite o arquivo de backup para download seguro pelo navegador
     */
    public function downloadBackup(string $filename): void {
        $filename = basename($filename);
        if (!preg_match('/^backup_.*\.sql(\.gz)?$/', $filename)) {
            http_response_code(400);
            die("Nome de arquivo inválido.");
        }

        $path = $this->backupDir . DIRECTORY_SEPARATOR . $filename;
        if (!file_exists($path) || !is_file($path)) {
            http_response_code(404);
            die("Arquivo de backup não encontrado.");
        }

        $mime = str_ends_with($filename, '.gz') ? 'application/gzip' : 'application/sql';
        $size = filesize($path);

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . $size);

        if (ob_get_level()) {
            ob_end_clean();
        }
        readfile($path);
        exit;
    }

    /**
     * Restaura o banco de dados a partir de um arquivo de backup
     */
    public function restoreBackup(string $filename): array {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $filename = basename($filename);
        if (!preg_match('/^backup_.*\.sql(\.gz)?$/', $filename)) {
            return ['sucesso' => false, 'erro' => 'Nome de arquivo de backup inválido.'];
        }

        $path = $this->backupDir . DIRECTORY_SEPARATOR . $filename;
        if (!file_exists($path) || !is_file($path)) {
            return ['sucesso' => false, 'erro' => 'Arquivo de backup não encontrado no servidor.'];
        }

        try {
            $sqlContent = '';
            if (str_ends_with($filename, '.gz')) {
                if (!function_exists('gzdecode')) {
                    return ['sucesso' => false, 'erro' => 'A extensão zlib do PHP não está habilitada para descompactar .gz.'];
                }
                $compressed = file_get_contents($path);
                $sqlContent = gzdecode($compressed);
                if ($sqlContent === false) {
                    return ['sucesso' => false, 'erro' => 'Falha ao descompactar o arquivo .gz. Arquivo corrompido.'];
                }
            } else {
                $sqlContent = file_get_contents($path);
            }

            if (empty(trim($sqlContent))) {
                return ['sucesso' => false, 'erro' => 'O arquivo de backup está vazio.'];
            }

            // Desativa checagem de chaves e executa script SQL
            $this->db->exec("SET FOREIGN_KEY_CHECKS = 0");
            $this->db->exec($sqlContent);
            $this->db->exec("SET FOREIGN_KEY_CHECKS = 1");

            Database::log('backup_restore', "Restauração de banco executada com sucesso a partir de {$filename}");

            // Notifica Telegram
            $cfg = $this->getConfig();
            if (!empty($cfg['backup_notificar_tg']) && class_exists('TelegramService')) {
                $tg = new TelegramService();
                $tg->notifySystemAlert(
                    "BANCO DE DADOS RESTAURADO",
                    "♻️ <b>Restauração Concluída com Sucesso!</b>\n\n" .
                    "📁 <b>Arquivo:</b> <code>{$filename}</code>\n" .
                    "👤 <b>Executado por:</b> Administrador\n" .
                    "⏰ <b>Data/Hora:</b> " . date('d/m/Y H:i:s')
                );
            }

            return ['sucesso' => true, 'erro' => null];

        } catch (Exception $e) {
            $this->db->exec("SET FOREIGN_KEY_CHECKS = 1");
            $erroMsg = $e->getMessage();
            Database::log('backup_error', "Erro na restauração de banco a partir de {$filename}: {$erroMsg}");
            return ['sucesso' => false, 'erro' => $erroMsg];
        }
    }

    /**
     * Remove backups antigos respeitando o limite de retenção
     */
    public function pruneOldBackups(int $keepCount = 7): int {
        if ($keepCount < 1) {
            $keepCount = 7;
        }

        $backups = $this->listBackups();
        if (count($backups) <= $keepCount) {
            return 0;
        }

        $excedentes = array_slice($backups, $keepCount);
        $removidos = 0;

        foreach ($excedentes as $item) {
            if ($this->deleteBackup($item['nome'])) {
                $removidos++;
            }
        }

        if ($removidos > 0) {
            Database::log('backup_prune', "Rotação de retenção: {$removidos} backups antigos foram removidos.");
        }

        return $removidos;
    }

    /**
     * Executa a checagem e disparo do backup agendado (usado pelo Cron diário)
     */
    public function runScheduledBackup(): array {
        $cfg = $this->getConfig();

        if (empty($cfg['backup_ativo'])) {
            return ['executado' => false, 'motivo' => 'Backup automático está desativado nas configurações.'];
        }

        $frequencia = $cfg['backup_frequencia'] ?? 'diario';
        $ultimoBackup = $cfg['backup_ultimo_em'];

        // Checar frequência
        if (!empty($ultimoBackup)) {
            $ultimoTs = strtotime($ultimoBackup);
            $agoraTs = time();

            if ($frequencia === 'diario') {
                // Já rodou hoje na mesma data?
                if (date('Y-m-d', $ultimoTs) === date('Y-m-d', $agoraTs)) {
                    return ['executado' => false, 'motivo' => 'O backup agendado já foi executado hoje (' . date('d/m/Y', $ultimoTs) . ').'];
                }
            } elseif ($frequencia === 'semanal') {
                // Intervalo mínimo de 7 dias
                if (($agoraTs - $ultimoTs) < (7 * 86400)) {
                    return ['executado' => false, 'motivo' => 'Intervalo semanal ainda não decorrido desde o último backup (' . date('d/m/Y', $ultimoTs) . ').'];
                }
            } elseif ($frequencia === 'mensal') {
                // Intervalo mínimo de 28 dias
                if (($agoraTs - $ultimoTs) < (28 * 86400)) {
                    return ['executado' => false, 'motivo' => 'Intervalo mensal ainda não decorrido desde o último backup (' . date('d/m/Y', $ultimoTs) . ').'];
                }
            }
        }

        // Executar o backup agendado
        $resultado = $this->generateBackup('agendado', (bool)($cfg['backup_compactar_gz'] ?? 1));

        return [
            'executado' => $resultado['sucesso'],
            'detalhes' => $resultado
        ];
    }

    /**
     * Envia alerta Telegram de sucesso
     */
    private function notifyTelegramBackupSuccess(string $arquivo, string $tamanho, string $tipo): void {
        if (!class_exists('TelegramService')) {
            return;
        }

        try {
            $tg = new TelegramService();
            $tipoFmt = ($tipo === 'agendado') ? '⏰ Automático (Agendado)' : '👤 Manual (Painel Admin)';
            $msg = "<b>💾 BACKUP SQL GERADO COM SUCESSO!</b>\n\n";
            $msg .= "📁 <b>Arquivo:</b> <code>{$arquivo}</code>\n";
            $msg .= "📊 <b>Tamanho:</b> {$tamanho}\n";
            $msg .= "⚙️ <b>Origem:</b> {$tipoFmt}\n";
            $msg .= "🗄️ <b>Banco:</b> " . DB_NAME . "\n";
            $msg .= "⏰ <b>Data/Hora:</b> " . date('d/m/Y H:i:s') . "\n";

            $tg->sendMessage($msg);
        } catch (Exception $e) {
            // Silencia falhas no telegram para não interromper backup
        }
    }

    /**
     * Envia alerta Telegram de falha
     */
    private function notifyTelegramBackupFailure(string $tipo, string $erro): void {
        if (!class_exists('TelegramService')) {
            return;
        }

        try {
            $tg = new TelegramService();
            $tipoFmt = ($tipo === 'agendado') ? 'Automático' : 'Manual';
            $msg = "<b>⚠️ ALERTA: FALHA AO GERAR BACKUP SQL!</b>\n\n";
            $msg .= "⚙️ <b>Tipo:</b> {$tipoFmt}\n";
            $msg .= "❌ <b>Erro:</b> <code>" . htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') . "</code>\n";
            $msg .= "📌 <i>Aviso: Verifique o espaço em disco ou permissões da pasta /backups/.</i>\n";
            $msg .= "⏰ <b>Data/Hora:</b> " . date('d/m/Y H:i:s') . "\n";

            $tg->sendMessage($msg);
        } catch (Exception $e) {
            // Silencia falhas no telegram
        }
    }

    /**
     * Auxiliar para formatação amigável de bytes
     */
    private function formatBytes(int $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}