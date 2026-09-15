-- Tabela de tentativas de login (rate limiting + auditoria)
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    email_or_user VARCHAR(150),
    user_type ENUM('admin','cliente') NOT NULL,
    success TINYINT(1) DEFAULT 0,
    user_agent TEXT,
    geo_country VARCHAR(100),
    geo_city VARCHAR(100),
    device_fingerprint VARCHAR(64),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_created (ip_address, created_at),
    INDEX idx_user_created (email_or_user, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de IPs bloqueados
CREATE TABLE IF NOT EXISTS blocked_ips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL UNIQUE,
    reason VARCHAR(255),
    blocked_until DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Adicionar campos de 2FA na tabela administradores
ALTER TABLE administradores 
    ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(128) NULL,
    ADD COLUMN IF NOT EXISTS totp_enabled TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS backup_codes TEXT NULL,
    ADD COLUMN IF NOT EXISTS last_login DATETIME NULL,
    ADD COLUMN IF NOT EXISTS last_ip VARCHAR(45) NULL;

-- Tabela de dispositivos confiáveis
CREATE TABLE IF NOT EXISTS trusted_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_type ENUM('admin','cliente') NOT NULL,
    device_hash VARCHAR(64) NOT NULL,
    device_name VARCHAR(255),
    ip_address VARCHAR(45),
    trusted_until DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_device (user_id, user_type, device_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Adicionar senha ao cliente
ALTER TABLE clientes 
    ADD COLUMN IF NOT EXISTS senha VARCHAR(255) NULL AFTER pppoe_senha,
    ADD COLUMN IF NOT EXISTS primeiro_acesso TINYINT(1) DEFAULT 1;

-- Tabela de sessões ativas
CREATE TABLE IF NOT EXISTS active_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(128) NOT NULL UNIQUE,
    user_id INT NOT NULL,
    user_type ENUM('admin','cliente') NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    last_activity DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id, user_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de configurações de segurança
CREATE TABLE IF NOT EXISTS security_settings (
    id INT NOT NULL DEFAULT 1 PRIMARY KEY,
    max_login_attempts INT DEFAULT 5,
    lockout_duration_minutes INT DEFAULT 15,
    auto_block_threshold INT DEFAULT 10,
    auto_block_duration_hours INT DEFAULT 24,
    session_timeout_minutes INT DEFAULT 30,
    trusted_device_days INT DEFAULT 30,
    require_2fa_admin TINYINT(1) DEFAULT 1,
    geo_check_enabled TINYINT(1) DEFAULT 0,
    device_check_enabled TINYINT(1) DEFAULT 1,
    admin_ip_whitelist TEXT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO security_settings (id) VALUES (1);
