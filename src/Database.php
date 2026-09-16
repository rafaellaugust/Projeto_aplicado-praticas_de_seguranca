<?php

/**
 * Singleton PDO Database Wrapper
 */
class Database {
    private static ?PDO $instance = null;

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHAR;
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ];
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                error_log('Erro de Conexão com o Banco de Dados: ' . $e->getMessage());
                if (PHP_SAPI === 'cli') {
                    die("Erro interno de conexão com o banco de dados.\n");
                }
                die("Erro interno do sistema. Tente novamente mais tarde.");
            }
        }
        return self::$instance;
    }

    public static function log($tipo, $mensagem, $detalhes = null) {
        $detalhesStr = is_array($detalhes) || is_object($detalhes) ? json_encode($detalhes, JSON_UNESCAPED_UNICODE) : $detalhes;
        
        // 1. Gravação no Banco de Dados com auto-recuperação
        try {
            $db = self::getInstance();
            try {
                $stmt = $db->prepare("INSERT INTO logs (tipo, mensagem, detalhes, criado_em) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$tipo, $mensagem, $detalhesStr]);
            } catch (Exception $e) {
                // Se falhou por causa da coluna 'id' sem valor default ou falta de auto_increment
                try {
                    $db->exec("ALTER TABLE logs ADD PRIMARY KEY (`id`)");
                } catch (Exception $e2) {}
                try {
                    $db->exec("ALTER TABLE logs MODIFY `id` int NOT NULL AUTO_INCREMENT");
                } catch (Exception $e3) {}

                // Fallback garantido: busca MAX(id) + 1 e insere explicitamente
                try {
                    $maxId = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM logs")->fetchColumn() + 1;
                    $stmtRetry = $db->prepare("INSERT INTO logs (id, tipo, mensagem, detalhes, criado_em) VALUES (?, ?, ?, ?, NOW())");
                    $stmtRetry->execute([$maxId, $tipo, $mensagem, $detalhesStr]);
                } catch (Exception $e4) {
                    error_log('Erro ao gravar log no banco: ' . $e4->getMessage());
                }
            }
        } catch (Exception $e) {
            // Ignora falhas gerais no banco para não interromper a aplicação
        }

        // 2. Gravação de Cópia em Arquivo Físico na pasta /log/
        try {
            $logDir = __DIR__ . '/../log';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            $logFile = $logDir . '/app-' . date('Y-m-d') . '.log';
            $linha = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($tipo) . '] ' . $mensagem;
            if (!empty($detalhesStr)) {
                $linha .= ' | Detalhes: ' . $detalhesStr;
            }
            $linha .= PHP_EOL;
            @file_put_contents($logFile, $linha, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            // Fallback silencioso
        }
    }
}
