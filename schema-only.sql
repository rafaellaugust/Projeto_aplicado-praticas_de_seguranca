-- ============================================
-- Schema do Banco de Dados — MikroTik Pay (Spaço Nett)
-- Versão pública: apenas estrutura, SEM dados reais
-- Para repositório GitHub público
-- ============================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- ========================================
-- Tabela: administradores
-- ========================================
CREATE TABLE IF NOT EXISTS `administradores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `totp_secret` varchar(128) NULL,
  `totp_enabled` tinyint(1) DEFAULT 0,
  `backup_codes` text NULL,
  `last_login` datetime NULL,
  `last_ip` varchar(45) NULL,
  `criado_em` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Administrador padrão (senha: trocar no primeiro acesso)
INSERT INTO `administradores` (`nome`, `email`, `senha`) VALUES
('Administrador', 'admin@admin.com', '$2y$10$wT8fS03G00m.8w6/qY4l8u7Z3Zz5k4X4y6Z7W8v9U0t1S2R3Q4P5O');

-- ========================================
-- Tabela: clientes
-- ========================================
CREATE TABLE IF NOT EXISTS `clientes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(150) NOT NULL,
  `cpf_cnpj` varchar(20) NOT NULL,
  `whatsapp` varchar(20) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `endereco` text,
  `pppoe_usuario` varchar(100) NOT NULL,
  `pppoe_senha` varchar(100) NOT NULL,
  `senha` varchar(255) NULL,
  `primeiro_acesso` tinyint(1) DEFAULT 1,
  `plano_id` int DEFAULT NULL,
  `roteador_id` int DEFAULT 1,
  `status` enum('ativo','suspenso','cancelado','aviso') DEFAULT 'ativo',
  `vencimento_dia` int DEFAULT 10,
  `data_expiracao` date DEFAULT NULL,
  `sincronizado_mikrotik` tinyint(1) DEFAULT 0,
  `criado_em` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================
-- Tabela: configuracoes
-- ========================================
CREATE TABLE IF NOT EXISTS `configuracoes` (
  `id` int NOT NULL DEFAULT 1,
  `empresa_nome` varchar(150) DEFAULT 'Meu Provedor ISP',
  `empresa_cnpj` varchar(20) DEFAULT '',
  `empresa_telefone` varchar(20) DEFAULT '',
  `wa_api_url` varchar(255) DEFAULT 'http://localhost:21465',
  `wa_session` varchar(100) DEFAULT 'default',
  `wa_token` varchar(255) DEFAULT '',
  `wa_status` tinyint(1) DEFAULT 0,
  `telegram_bot_token` varchar(255) DEFAULT '',
  `telegram_chat_id` varchar(100) DEFAULT '',
  `telegram_ativo` tinyint(1) DEFAULT 0,
  `gateway_provider` varchar(50) DEFAULT 'mercadopago',
  `mp_public_key` varchar(255) DEFAULT '',
  `mp_access_token` varchar(255) DEFAULT '',
  `mp_client_id` varchar(100) DEFAULT '',
  `mp_client_secret` varchar(255) DEFAULT '',
  `mp_sandbox` tinyint(1) DEFAULT 0,
  `asaas_api_key` varchar(255) DEFAULT '',
  `pix_chave_estatica` varchar(255) DEFAULT '',
  `pix_nome_beneficiario` varchar(100) DEFAULT '',
  `pix_cidade_beneficiario` varchar(100) DEFAULT '',
  `webhook_secret` varchar(100) DEFAULT '',
  `atualizado_em` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `webhook_callback_url` varchar(255) DEFAULT NULL,
  `webhook_callback_ativo` tinyint(1) DEFAULT 0,
  `wa_disparo_hora` varchar(5) DEFAULT '08:00',
  `wa_disparo_delay` int DEFAULT 5,
  `wa_disparo_ativo` tinyint(1) DEFAULT 0,
  `wa_disparo_dias` int DEFAULT 3,
  `wa_ultimo_disparo` date DEFAULT NULL,
  `wa_modelo_mensagem` text,
  `wa_delay_pix` int DEFAULT 2,
  `wa_modelo_confirmacao` text,
  `wa_aviso_ativo` tinyint(1) DEFAULT 1,
  `wa_aviso_tolerancia` int DEFAULT 5,
  `wa_modelo_aviso` text,
  `app_url` varchar(255) DEFAULT '',
  `backup_ativo` tinyint(1) DEFAULT 1,
  `backup_hora` varchar(5) DEFAULT '03:00',
  `backup_frequencia` varchar(20) DEFAULT 'diario',
  `backup_manter_qtd` int DEFAULT 7,
  `backup_notificar_tg` tinyint(1) DEFAULT 1,
  `backup_compactar_gz` tinyint(1) DEFAULT 1,
  `backup_ultimo_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `configuracoes` (`id`, `empresa_nome`) VALUES (1, 'Meu Provedor ISP');

-- ========================================
-- Tabela: faturas
-- ========================================
CREATE TABLE IF NOT EXISTS `faturas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cliente_id` int NOT NULL,
  `plano_id` int DEFAULT NULL,
  `valor` decimal(10,2) NOT NULL,
  `descricao` varchar(255) DEFAULT NULL,
  `meses_cobertos` int NOT NULL DEFAULT 1,
  `data_vencimento` date NOT NULL,
  `data_pagamento` datetime DEFAULT NULL,
  `status` enum('pendente','pago','atrasado','cancelado') DEFAULT 'pendente',
  `forma_pagamento` varchar(50) DEFAULT 'PIX',
  `pix_txid` varchar(100) DEFAULT NULL,
  `pix_copia_cola` text,
  `qr_code` text,
  `pix_qr_code_base64` longtext,
  `gateway_id` varchar(100) DEFAULT NULL,
  `mp_payment_id` varchar(100) DEFAULT NULL,
  `gateway_status` varchar(50) DEFAULT 'pending',
  `expiration_date` datetime DEFAULT NULL,
  `external_reference` varchar(255) DEFAULT NULL,
  `notificado_wa` tinyint(1) DEFAULT 0,
  `criado_em` datetime DEFAULT CURRENT_TIMESTAMP,
  `notificado_aviso` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_cliente` (`cliente_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================
-- Tabela: historico_pagamentos
-- ========================================
CREATE TABLE IF NOT EXISTS `historico_pagamentos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cliente_id` int NOT NULL,
  `fatura_id` int DEFAULT NULL,
  `valor` decimal(10,2) NOT NULL,
  `forma_pagamento` varchar(50) DEFAULT 'PIX',
  `data_pagamento` datetime DEFAULT CURRENT_TIMESTAMP,
  `comprovante_ref` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================
-- Tabela: logs
-- ========================================
CREATE TABLE IF NOT EXISTS `logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tipo` varchar(50) NOT NULL,
  `mensagem` text NOT NULL,
  `detalhes` text,
  `criado_em` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_criado` (`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================
-- Tabela: mikrotik_payment_history
-- ========================================
CREATE TABLE IF NOT EXISTS `mikrotik_payment_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cliente_id` int NOT NULL,
  `ano` int NOT NULL,
  `mes` int NOT NULL,
  `status` enum('paid','warning','overdue','npago') DEFAULT 'paid',
  `valor` decimal(10,2) DEFAULT 0.00,
  `data_atualizacao` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cliente_periodo` (`cliente_id`, `ano`, `mes`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================
-- Tabela: planos
-- ========================================
CREATE TABLE IF NOT EXISTS `planos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `prefixo_grupo` varchar(20) DEFAULT 'S1',
  `profile_mikrotik` varchar(100) NOT NULL,
  `profile_aviso` varchar(100) DEFAULT NULL,
  `profile_bloqueado` varchar(100) NOT NULL,
  `velocidade_down` varchar(50) DEFAULT '40M',
  `velocidade_up` varchar(50) DEFAULT '20M',
  `valor` decimal(10,2) NOT NULL DEFAULT 0.00,
  `dias_validade` int NOT NULL DEFAULT 30,
  `descricao` text,
  `criado_em` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================
-- Tabela: roteadores
-- ========================================
CREATE TABLE IF NOT EXISTS `roteadores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `ip_host` varchar(100) NOT NULL,
  `porta_api` int DEFAULT 8728,
  `usuario` varchar(100) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `usar_ssl` tinyint(1) DEFAULT 0,
  `status` varchar(20) DEFAULT 'desconectado',
  `criado_em` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================
-- Tabela: webhook_logs
-- ========================================
CREATE TABLE IF NOT EXISTS `webhook_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tipo` varchar(50) DEFAULT 'webhook',
  `mensagem` text,
  `dados` longtext,
  `criado_em` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================
-- Tabela: whatsapp_disparos
-- ========================================
CREATE TABLE IF NOT EXISTS `whatsapp_disparos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fatura_id` int NOT NULL,
  `cliente_id` int NOT NULL,
  `nome_cliente` varchar(150) DEFAULT '',
  `whatsapp` varchar(20) DEFAULT '',
  `tipo` varchar(50) DEFAULT 'cobranca',
  `status` enum('enviado','falhou') DEFAULT 'falhou',
  `erro_msg` text,
  `criado_em` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fatura` (`fatura_id`),
  KEY `idx_cliente` (`cliente_id`),
  KEY `idx_criado` (`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================
-- Tabelas de Segurança (do migrations/001_security_tables.sql)
-- ========================================

-- Tentativas de login (rate limiting + auditoria)
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `ip_address` varchar(45) NOT NULL,
  `email_or_user` varchar(150),
  `user_type` enum('admin','cliente') NOT NULL,
  `success` tinyint(1) DEFAULT 0,
  `user_agent` text,
  `geo_country` varchar(100),
  `geo_city` varchar(100),
  `device_fingerprint` varchar(64),
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ip_created` (`ip_address`, `created_at`),
  INDEX `idx_user_created` (`email_or_user`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- IPs bloqueados
CREATE TABLE IF NOT EXISTS `blocked_ips` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `ip_address` varchar(45) NOT NULL UNIQUE,
  `reason` varchar(255),
  `blocked_until` datetime NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ip` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Dispositivos confiáveis
CREATE TABLE IF NOT EXISTS `trusted_devices` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `user_id` int NOT NULL,
  `user_type` enum('admin','cliente') NOT NULL,
  `device_hash` varchar(64) NOT NULL,
  `device_name` varchar(255),
  `ip_address` varchar(45),
  `trusted_until` datetime NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_device` (`user_id`, `user_type`, `device_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sessões ativas
CREATE TABLE IF NOT EXISTS `active_sessions` (
  `id` int AUTO_INCREMENT PRIMARY KEY,
  `session_id` varchar(128) NOT NULL UNIQUE,
  `user_id` int NOT NULL,
  `user_type` enum('admin','cliente') NOT NULL,
  `ip_address` varchar(45),
  `user_agent` text,
  `last_activity` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user` (`user_id`, `user_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Configurações de segurança
CREATE TABLE IF NOT EXISTS `security_settings` (
  `id` int NOT NULL DEFAULT 1 PRIMARY KEY,
  `max_login_attempts` int DEFAULT 5,
  `lockout_duration_minutes` int DEFAULT 15,
  `auto_block_threshold` int DEFAULT 10,
  `auto_block_duration_hours` int DEFAULT 24,
  `session_timeout_minutes` int DEFAULT 30,
  `trusted_device_days` int DEFAULT 30,
  `require_2fa_admin` tinyint(1) DEFAULT 1,
  `geo_check_enabled` tinyint(1) DEFAULT 0,
  `device_check_enabled` tinyint(1) DEFAULT 1,
  `admin_ip_whitelist` text NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `security_settings` (`id`) VALUES (1);

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
