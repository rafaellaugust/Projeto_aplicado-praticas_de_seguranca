# ☁️ Guia de Integração com a Máquina Virtual (VM na Cloud)

Este documento orienta como conectar a sua Máquina Virtual (VM) em nuvem pública (Oracle Cloud Free Tier, AWS EC2, Google Cloud Compute Engine, Azure, etc.) ao repositório no GitHub para deploy automatizado via **GitHub Actions**.

---

## 🎯 Visão Geral do Fluxo

```mermaid
sequenceDiagram
    autonumber
    participant Dev as 💻 Seu Computador
    participant GH as 📦 GitHub
    participant GA as ⚙️ GitHub Actions
    participant VM as ☁️ VM / Servidor Cloud

    Dev->>GH: git push origin main
    GH->>GA: Gatilho automático (push detectado)
    GA->>GA: Job 1: Verificação de Segurança (sem senhas expostas)
    GA->>VM: Job 2: Conexão SSH segura via Secrets
    VM->>VM: Backup prévio + git pull origin main
    VM->>VM: Aplicação de permissões + reload PHP-FPM
    VM-->>GA: Status de Sucesso (200 OK)
    GA-->>GH: Pipeline Aprovada ✅
```

---

## 🛠 Passo 1: Preparar a VM na Nuvem

1. **Criar a VM:** Crie uma instância com **Ubuntu Server 24.04 LTS** ou **Debian 12** no provedor de sua escolha (ex: Oracle Cloud Free Tier).
2. **Obter o IP Público:** Anote o IP público IPv4 da sua VM (ex: `203.0.113.50`).
3. **Instalar os Pacotes Necessários:**
   Acesse a VM via SSH:
   ```bash
   ssh ubuntu@SEU_IP_PUBLICO
   ```
   Execute a instalação dos serviços básicos:
   ```bash
   sudo apt update && sudo apt upgrade -y
   sudo apt install -y nginx mariadb-server php8.3-fpm php8.3-mysql php8.3-curl php8.3-mbstring php8.3-xml certbot python3-certbot-nginx git fail2ban ufw
   ```

---

## 🔑 Passo 2: Gerar a Chave SSH para o GitHub Actions

Para que o GitHub Actions consiga acessar a VM sem senha e fazer o deploy automático:

1. **Na VM**, gere um par de chaves dedicado:
   ```bash
   ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/github_deploy -N ""
   ```
2. **Adicione a chave pública aos acessos autorizados da VM:**
   ```bash
   cat ~/.ssh/github_deploy.pub >> ~/.ssh/authorized_keys
   chmod 600 ~/.ssh/authorized_keys
   ```
3. **Exiba a chave privada para copiar:**
   ```bash
   cat ~/.ssh/github_deploy
   ```
   *Copie todo o conteúdo exibido (incluindo `-----BEGIN OPENSSH PRIVATE KEY-----` e `-----END OPENSSH PRIVATE KEY-----`).*

---

## 🔐 Passo 3: Cadastrar as Secrets no Repositório do GitHub

1. Acesse o seu repositório no GitHub:
   👉 **[https://github.com/rafaellaugust/Projeto_aplicado-praticas_de_seguranca](https://github.com/rafaellaugust/Projeto_aplicado-praticas_de_seguranca)**
2. Vá em **Settings** (Configurações do Repositório) ➔ **Secrets and variables** ➔ **Actions**.
3. Clique no botão verde **New repository secret** e adicione as 3 variáveis:

| Nome do Secret | Valor | Descrição |
| :--- | :--- | :--- |
| `SERVER_HOST` | `SEU_IP_PUBLICO` | O IP público da sua VM (ex: `203.0.113.50`) |
| `SERVER_USER` | `ubuntu` | O usuário de acesso da VM (`ubuntu` ou `debian`) |
| `SSH_PRIVATE_KEY` | *(Cole a chave privada copiada)* | A chave privada gerada no Passo 2 |
| `SERVER_PORT` | `22` | Porta SSH (padrão: 22) |

---

## 📁 Passo 4: Fazer o Clone Inicial na VM

Na sua VM, crie o diretório do projeto e faça o primeiro clone:

```bash
# 1. Crie o diretório raiz
sudo mkdir -p /var/www/html/aplicacao
sudo chown -R $USER:$USER /var/www/html/aplicacao

# 2. Clone o repositório
git clone https://github.com/rafaellaugust/Projeto_aplicado-praticas_de_seguranca.git /var/www/html/aplicacao

# 3. Acesse a pasta
cd /var/www/html/aplicacao

# 4. Crie o arquivo .env de produção (com a senha do banco da sua VM)
cp .env.example .env
nano .env

# 5. Ajuste as permissões de segurança
sudo chown -R www-data:www-data /var/www/html/aplicacao
sudo chmod -R 755 /var/www/html/aplicacao
sudo chmod 640 /var/www/html/aplicacao/.env
```

---

## 🚀 Passo 5: Testar a Automação CI/CD

Pronto! A partir deste momento:
1. Sempre que você fizer alterações no código no seu computador e executar:
   ```bash
   git push origin main
   ```
2. O **GitHub Actions** será acionado automaticamente na aba **Actions** do seu GitHub.
3. Ele validará se há senhas vazadas e, se aprovado, conectará via SSH na sua VM, atualizará os arquivos em segundos e recarregará os serviços.
