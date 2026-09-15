-- =========================================================
-- MIKROTIK PAY - MIGRAÇÃO DE SEGURANÇA E TABELAS (100% COMPATÍVEL)
-- Compatível com MySQL 5.7, 8.0 e MariaDB (cPanel / phpMyAdmin)
-- =========================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. TABELA administradores: Atualização dos campos de 2FA e Auditoria
-- Se as colunas já existirem, você pode ignorar eventuais avisos
ALTER TABLE `administradores` ADD `totp_secret` VARCHAR(128) NULL;
ALTER TABLE `administradores` ADD `totp_enabled` TINYINT(1) DEFAULT 0;
ALTER TABLE `administradores` ADD `backup_codes` TEXT NULL;
ALTER TABLE `administradores` ADD `last_login` DATETIME NULL;
ALTER TABLE `administradores` ADD `last_ip` VARCHAR(45) NULL;

-- 2. TABELA clientes: Adição de Senha e Primeiro Acesso
ALTER TABLE `clientes` ADD `senha` VARCHAR(255) NULL AFTER `pppoe_senha`;
ALTER TABLE `clientes` ADD `primeiro_acesso` TINYINT(1) DEFAULT 1;

-- 3. TABELA login_attempts: Auditoria e Rate Limiting (Proteção contra Força Bruta)
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL,
    `email_or_user` VARCHAR(150) NULL,
    `user_type` ENUM('admin','cliente') NOT NULL DEFAULT 'admin',
    `success` TINYINT(1) DEFAULT 0,
    `user_agent` TEXT NULL,
    `geo_country` VARCHAR(100) NULL,
    `geo_city` VARCHAR(100) NULL,
    `device_fingerprint` VARCHAR(64) NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ip_created` (`ip_address`, `created_at`),
    INDEX `idx_user_created` (`email_or_user`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. TABELA blocked_ips: Bloqueio e Blacklist de IPs
CREATE TABLE IF NOT EXISTS `blocked_ips` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL UNIQUE,
    `reason` VARCHAR(255) NULL,
    `blocked_until` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ip` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. TABELA trusted_devices: Dispositivos Confiáveis (Lembrar por 30 dias)
CREATE TABLE IF NOT EXISTS `trusted_devices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `user_type` ENUM('admin','cliente') NOT NULL DEFAULT 'admin',
    `device_hash` VARCHAR(64) NOT NULL,
    `device_name` VARCHAR(255) NULL,
    `ip_address` VARCHAR(45) NULL,
    `trusted_until` DATETIME NOT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_device` (`user_id`, `user_type`, `device_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. TABELA active_sessions: Gerenciamento e Proteção contra Sequestro de Sessão
CREATE TABLE IF NOT EXISTS `active_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `session_id` VARCHAR(128) NOT NULL UNIQUE,
    `user_id` INT NOT NULL,
    `user_type` ENUM('admin','cliente') NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `last_activity` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user` (`user_id`, `user_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. TABELA security_settings: Configurações Globais de Políticas de Segurança
CREATE TABLE IF NOT EXISTS `security_settings` (
    `id` INT NOT NULL DEFAULT 1 PRIMARY KEY,
    `max_login_attempts` INT DEFAULT 5,
    `lockout_duration_minutes` INT DEFAULT 15,
    `auto_block_threshold` INT DEFAULT 10,
    `auto_block_duration_hours` INT DEFAULT 24,
    `session_timeout_minutes` INT DEFAULT 30,
    `trusted_device_days` INT DEFAULT 30,
    `require_2fa_admin` TINYINT(1) DEFAULT 1,
    `geo_check_enabled` TINYINT(1) DEFAULT 0,
    `device_check_enabled` TINYINT(1) DEFAULT 1,
    `admin_ip_whitelist` TEXT NULL,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inserção da configuração padrão se não existir
INSERT IGNORE INTO `security_settings` (`id`) VALUES (1);

SET FOREIGN_KEY_CHECKS = 1;
