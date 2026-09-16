-- ==========================================================
-- SCRIPT DE CORREÇÃO: AUTO_INCREMENT DAS TABELAS
-- Projeto MikroTik Pay / Spaço Nett
-- Execute na aba 'SQL' do phpMyAdmin no cPanel
-- ==========================================================

-- NOTA: Se você recebeu o erro '#1068 - Multiple primary key defined', 
-- significa que a chave primária (PRIMARY KEY) JÁ EXISTE na tabela!
-- Portanto, basta apenas ativar o AUTO_INCREMENT com os comandos abaixo:

-- 1. Ativar AUTO_INCREMENT nas tabelas principais:
ALTER TABLE `logs` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `administradores` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `clientes` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `faturas` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `historico_pagamentos` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `mikrotik_payment_history` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `planos` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `roteadores` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `webhook_logs` MODIFY `id` int NOT NULL AUTO_INCREMENT;

-- 2. Configurações (garantir PK):
-- (Execute apenas se a tabela configuracoes não tiver chave primária ainda)
-- ALTER TABLE `configuracoes` ADD PRIMARY KEY (`id`);

-- 3. Modo de Teste Rápido (Login do cliente sem senha):
ALTER TABLE `security_settings` ADD COLUMN IF NOT EXISTS `client_login_no_password` tinyint DEFAULT 0;
