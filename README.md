# 🌐 MikroTik Pay — Sistema de Gerenciamento ISP

![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?logo=mysql&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-7952B3?logo=bootstrap&logoColor=white)
![Nginx](https://img.shields.io/badge/Nginx-Web_Server-009639?logo=nginx&logoColor=white)
![CI/CD](https://img.shields.io/badge/CI%2FCD-GitHub_Actions-2088FF?logo=githubactions&logoColor=white)
![Security](https://img.shields.io/badge/Segurança-OWASP_Top_10-red)

## 📝 Descrição

Sistema web completo para gerenciamento de provedor de internet (ISP), desenvolvido como projeto da disciplina **Projeto Aplicado: Práticas de Mercado**. O sistema permite gerenciar clientes, faturas, cobranças via WhatsApp, integração com roteadores MikroTik e pagamentos via PIX.

Desenvolvido com foco em **Secure by Design** e **Secure by Default**, utilizando o IDE **Google Antigravity** como ferramenta de codificação assistida por IA.

## 🏗️ Stack Tecnológica

| Componente | Tecnologia |
|------------|------------|
| Backend | PHP 8.1+ (puro, sem frameworks) |
| Banco de Dados | MySQL 8.0 / MariaDB |
| Frontend | HTML5, CSS3, Bootstrap 5.3, JavaScript |
| Servidor Web | Nginx |
| Sistema Operacional | Ubuntu Server / Debian |
| CI/CD | GitHub Actions |
| IDE | Google Antigravity |
| Autenticação | Bcrypt + 2FA Multi-fator (Google Authenticator TOTP & E-mail OTP) + Google reCAPTCHA v2 |

## 📂 Estrutura do Projeto

```text
proj.mikrotik/
├── admin/              # Painel administrativo
│   ├── login.php       # Login com 2FA
│   ├── 2fa-setup.php   # Configuração Google Authenticator
│   ├── 2fa-verify.php  # Verificação TOTP
│   ├── index.php       # Dashboard
│   ├── seguranca.php   # Painel de segurança
│   ├── clientes.php    # Gestão de clientes
│   ├── faturas.php     # Gestão de faturas
│   └── ...             # Outros módulos
├── cliente/            # Portal do cliente
│   ├── login.php       # Login com senha
│   ├── index.php       # Dashboard do cliente
│   └── ...             
├── src/                # Classes PHP
│   ├── Database.php    # Singleton PDO
│   ├── Security.php    # Segurança (CSRF, TOTP, Rate Limiting)
│   ├── SessionGuard.php # Proteção de sessão
│   └── ...             
├── migrations/         # Scripts SQL de migração
├── docs/               # Documentação técnica
├── .github/workflows/  # Pipeline CI/CD
├── config.php          # Configuração (carrega .env)
├── .env.example        # Template de variáveis de ambiente
└── .gitignore          # Proteção contra vazamento de credenciais
```

## 🔒 Segurança — OWASP Top 10:2025

### A01:2025 — Broken Access Control

**A Vulnerabilidade:**
Ocorre quando as restrições sobre o que usuários autenticados podem fazer não são devidamente aplicadas. Atacantes podem explorar essas falhas para acessar funcionalidades e/ou dados de outras contas, ver arquivos sensíveis ou elevar seus privilégios (ex: de cliente para administrador).

**Como o projeto previne:**
O MikroTik Pay implementa um modelo de **Default Deny**. Todas as páginas requerem verificação explícita de sessão. O sistema utiliza a classe `SessionGuard` para gerenciar e validar sessões, prevenir sequestro de sessão e aplicar tempo de inatividade. O controle de acesso é baseado em funções (Roles), separando rigorosamente a área de clientes e administradores. Além disso, tokens CSRF são obrigatórios em todas as requisições de modificação de estado.

**Referências de Implementação:**
- Arquivo: `src/Security.php` (Geração e validação de tokens CSRF)
- Arquivo: `src/SessionGuard.php` (Validação de acesso, expiração de inatividade e binding de IP/User-Agent)

**Exemplo de Código (Proteção de Página e CSRF):**
```php
// No início de qualquer página administrativa (ex: admin/clientes.php)
require_once '../src/SessionGuard.php';
SessionGuard::requireAdmin(); // Valida sessão, IP, User-Agent e inatividade

// Geração do Token CSRF no formulário
$csrfToken = Security::generateCsrfToken();
echo '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">';

// Validação do Token no processamento
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrfToken($_POST['csrf_token'])) {
        die('Acesso negado: Token de segurança inválido.');
    }
}
```

### A02:2025 — Cryptographic Failures

**A Vulnerabilidade:**
Falhas relacionadas à proteção de dados sensíveis em repouso e em trânsito. Senhas armazenadas em texto claro, uso de algoritmos fracos (como MD5 ou SHA1), falta de HTTPS ou chaves criptográficas expostas no código fonte.

**Como o projeto previne:**
Nenhuma credencial (senhas de banco de dados, chaves de API) é armazenada no código-fonte, utilizando variáveis de ambiente via `.env`. Todas as senhas de usuários e administradores são hasheadas utilizando o algoritmo `bcrypt`. Informações sensíveis como o segredo TOTP (Google Authenticator) são armazenadas no banco de dados com criptografia bidirecional robusta (AES-256-CBC). Em trânsito, a aplicação obriga o uso de HTTPS/TLS via HSTS. Cookies de sessão são marcados como `Secure`, `HttpOnly` e `SameSite=Strict`.

**Referências de Implementação:**
- Arquivo: `src/Security.php` (Criptografia simétrica para segredos)
- Arquivo: `admin/login.php` (Verificação de hash bcrypt)
- Arquivo: `src/SessionGuard.php` (Configuração de Cookies Seguros)

**Exemplo de Código (Configuração de Cookies e Criptografia AES):**
```php
// src/SessionGuard.php - Inicialização Segura de Sessão
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => true,      // Exige HTTPS
    'httponly' => true,    // Bloqueia acesso via JavaScript (XSS)
    'samesite' => 'Strict' // Proteção adicional contra CSRF
]);
session_start();

// src/Security.php - Criptografia de dados sensíveis no BD (ex: segredo TOTP)
public static function encryptData($data) {
    $key = getenv('APP_KEY');
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}
```

### A07:2025 — Identification and Authentication Failures

**A Vulnerabilidade:**
Relacionada à gestão inadequada de sessões, senhas fracas, falta de Multi-Factor Authentication (MFA), exposição a ataques de força bruta (brute force) e stuffing de credenciais.

**Como o projeto previne:**
O sistema implementa Autenticação de Múltiplos Fatores (MFA/2FA) para contas administrativas através de TOTP (RFC 6238 - Google Authenticator), verificação em duas etapas por E-mail (OTP de 6 dígitos) e códigos de recuperação one-time. Para proteção contra bots e força bruta automatizada, o sistema integra Google reCAPTCHA v2, Rate Limiting (5 tentativas / 15 min) e auto-ban de 24 horas após 10 tentativas falhas por IP.

**Referências de Implementação:**
- Arquivo: `src/Security.php` (Rate limiting, IP Ban, Validação TOTP, E-mail 2FA e reCAPTCHA)
- Arquivo: `admin/login.php` e `admin/2fa-verify.php` (Fluxo de login de múltiplas etapas: Senha -> Google Auth / E-mail -> Dashboard)
- Arquivo: `cliente/login.php` (Login do cliente com senha obrigatória, reCAPTCHA e rate limiting)

**Exemplo de Código (Rate Limiting contra Força Bruta):**
```php
// src/Security.php - Proteção contra Brute Force
public static function checkRateLimit($ip, $username) {
    $db = Database::getInstance();
    
    // Verifica banimento de IP (10 tentativas)
    $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempt_time > (NOW() - INTERVAL 24 HOUR) AND success = 0");
    $stmt->execute([$ip]);
    if ($stmt->fetchColumn() >= 10) {
        throw new Exception("Acesso bloqueado por 24 horas devido a múltiplas tentativas falhas.");
    }
    
    // Verifica bloqueio de usuário (5 tentativas)
    $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = ? AND attempt_time > (NOW() - INTERVAL 15 MINUTE) AND success = 0");
    $stmt->execute([$username]);
    if ($stmt->fetchColumn() >= 5) {
        throw new Exception("Conta temporariamente bloqueada. Tente novamente em 15 minutos.");
    }
}
```

## 🚀 Instalação e Configuração

1. Clone o repositório para o diretório web (`/var/www/html/proj.mikrotik`)
2. Copie o template de variáveis: `cp .env.example .env`
3. Configure as variáveis no `.env` (Credenciais de DB, chaves, etc)
4. Execute os scripts SQL da pasta `migrations/` em ordem
5. Configure o Nginx (consulte `docs/server-setup.md`)
6. Obtenha e instale os certificados SSL usando o Certbot

## 🔄 CI/CD — GitHub Actions

A entrega contínua é automatizada através do GitHub Actions. O fluxo (`deploy.yml`) garante que o código seja analisado e entregue de forma segura:

1. **Trigger:** Um push na branch `main` inicia o pipeline.
2. **Security Check (Job 1):** O ambiente isolado (Ubuntu runner) analisa o código em busca de vazamentos de credenciais no código-fonte (segredos hardcoded) e verifica se arquivos sensíveis (`.env`, `*.sql`) foram comitados acidentalmente.
3. **Deploy Seguro (Job 2):** Após passar na verificação, o runner utiliza a action `appleboy/ssh-action` para acessar o servidor de produção via chave SSH privada (armazenada em GitHub Secrets).
4. **Execução Remota:** O pipeline cria um backup instantâneo da versão atual, realiza um `git pull --hard` das novidades, aplica permissões estritas de diretórios (ex: chmod 640 no .env, chown www-data) e recarrega o PHP-FPM, garantindo zero downtime visível.

## 📝 Licença

Projeto acadêmico — Projeto Aplicado: Práticas de Mercado
