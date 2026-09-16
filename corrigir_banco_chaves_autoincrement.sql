-- ==========================================================
-- SCRIPT DE CORREÇÃO: PRIMARY KEYS E AUTO_INCREMENT
-- Projeto MikroTik Pay / Spaço Nett
-- Execute este script na aba 'SQL' do phpMyAdmin no cPanel
-- ==========================================================

-- 1. TABELA logs (Corrige o erro onde novas movimentações não apareciam)
ALTER TABLE `logs` ADD PRIMARY KEY (`id`);
ALTER TABLE `logs` MODIFY `id` int NOT NULL AUTO_INCREMENT;

-- 2. TABELA administradores
ALTER TABLE `administradores` ADD PRIMARY KEY (`id`);
ALTER TABLE `administradores` MODIFY `id` int NOT NULL AUTO_INCREMENT;

-- 3. TABELA clientes
ALTER TABLE `clientes` ADD PRIMARY KEY (`id`);
ALTER TABLE `clientes` MODIFY `id` int NOT NULL AUTO_INCREMENT;

-- 4. TABELA configuracoes
ALTER TABLE `configuracoes` ADD PRIMARY KEY (`id`);

-- 5. TABELA faturas
ALTER TABLE `faturas` ADD PRIMARY KEY (`id`);
ALTER TABLE `faturas` MODIFY `id` int NOT NULL AUTO_INCREMENT;

-- 6. TABELA historico_pagamentos
ALTER TABLE `historico_pagamentos` ADD PRIMARY KEY (`id`);
ALTER TABLE `historico_pagamentos` MODIFY `id` int NOT NULL AUTO_INCREMENT;

-- 7. TABELA mikrotik_payment_history
ALTER TABLE `mikrotik_payment_history` ADD PRIMARY KEY (`id`);
ALTER TABLE `mikrotik_payment_history` MODIFY `id` int NOT NULL AUTO_INCREMENT;

-- 8. TABELA planos
ALTER TABLE `planos` ADD PRIMARY KEY (`id`);
ALTER TABLE `planos` MODIFY `id` int NOT NULL AUTO_INCREMENT;

-- 9. TABELA roteadores
ALTER TABLE `roteadores` ADD PRIMARY KEY (`id`);
ALTER TABLE `roteadores` MODIFY `id` int NOT NULL AUTO_INCREMENT;

-- 10. TABELA webhook_logs
ALTER TABLE `webhook_logs` ADD PRIMARY KEY (`id`);
ALTER TABLE `webhook_logs` MODIFY `id` int NOT NULL AUTO_INCREMENT;

-- 11. TABELA security_settings (Modo de teste rápido para login sem senha)
ALTER TABLE `security_settings` ADD COLUMN IF NOT EXISTS `client_login_no_password` tinyint DEFAULT 0;
