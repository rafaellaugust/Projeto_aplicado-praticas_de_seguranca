# 🔧 Guia Completo de Configuração do Servidor

Este documento detalha o provisionamento e configuração de um servidor seguro para hospedar a aplicação **MikroTik Pay**, cumprindo os requisitos acadêmicos da disciplina.

---

## 1. Criação da VM (Oracle Cloud Free Tier / AWS / GCP)

1. Acesse o console da sua nuvem escolhida (ex: Oracle Cloud OCI).
2. Crie uma nova Instância Compute (VM).
3. **Imagem/SO:** Ubuntu Server 24.04 LTS.
4. **Shape/Hardware:** Ampere A1 (ARM) ou AMD EPYC Micro (x86_64).
5. **Rede:** Atribua um IP público fixo.
6. **Chaves SSH:** Gere ou faça upload da sua chave pública SSH na interface de criação da VM.

---

## 2. Acesso SSH Seguro

Primeiramente, acesse a VM com o usuário padrão (geralmente `ubuntu`):
```bash
ssh -i ~/.ssh/sua_chave_privada ubuntu@IP_DO_SERVIDOR
```

### Configurando o Serviço SSH (Hardening)

Edite as configurações do daemon SSH:
```bash
sudo nano /etc/ssh/sshd_config
```

Certifique-se de que as seguintes linhas estão configuradas desta forma:
```ini
PermitRootLogin no
PasswordAuthentication no
PubkeyAuthentication yes
X11Forwarding no
```

Reinicie o serviço para aplicar:
```bash
sudo systemctl restart ssh
```

---

## 3. Firewall UFW (Uncomplicated Firewall)

Habilite e configure o firewall para permitir apenas o essencial:
```bash
# Permitir SSH (se houver mudança de porta, altere aqui)
sudo ufw allow 22/tcp

# Permitir HTTP e HTTPS
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Habilitar o firewall
sudo ufw enable

# Verificar status
sudo ufw status verbose
```

---

## 4. Fail2Ban contra Ataques de Força Bruta

Instale o Fail2Ban para bloquear IPs que tentam descobrir senhas:
```bash
sudo apt update
sudo apt install fail2ban -y
```

Crie o arquivo de configuração local para proteger o SSH:
```bash
sudo cp /etc/fail2ban/jail.conf /etc/fail2ban/jail.local
sudo nano /etc/fail2ban/jail.local
```

Encontre a seção `[sshd]` e adicione/ajuste:
```ini
[sshd]
enabled = true
port    = ssh
logpath = %(sshd_log)s
backend = %(sshd_backend)s
maxretry = 4
bantime = 86400
findtime = 3600
```
*Isto bloqueia por 24 horas (86400s) o IP que errar 4 vezes em 1 hora.*

Reinicie e ative:
```bash
sudo systemctl restart fail2ban
sudo systemctl enable fail2ban
sudo fail2ban-client status sshd
```

---

## 5. Instalação do Nginx e Configuração

Instale o servidor web:
```bash
sudo apt install nginx -y
```

### Configuração do Virtual Host
Crie o arquivo do site:
```bash
sudo nano /etc/nginx/sites-available/mikrotikpay
```

**Configuração inicial (nginx.conf do site):**
```nginx
server {
    listen 80;
    server_name seudominio.com.br IP_DO_SERVIDOR;
    root /var/www/html/proj.mikrotik;
    index index.php index.html;

    # Proteção para pastas sensíveis e arquivos ocultos
    location ~ /\. {
        deny all;
    }
    location ^~ /src/ {
        deny all;
    }
    location ^~ /migrations/ {
        deny all;
    }
    location ~ \.env$ {
        deny all;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock; # Ajuste para a sua versão do PHP
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

Habilite o site e teste a configuração:
```bash
sudo ln -s /etc/nginx/sites-available/mikrotikpay /etc/nginx/sites-enabled/
sudo unlink /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

---

## 6. Instalação do PHP 8.1+ e Extensões

```bash
sudo apt install software-properties-common -y
sudo add-apt-repository ppa:ondrej/php
sudo apt update

sudo apt install php8.1-fpm php8.1-mysql php8.1-curl php8.1-mbstring php8.1-xml php8.1-bcmath php8.1-zip -y
```
*(Nota: Substitua 8.1 pela versão desejada, como 8.3 se preferir e suportar).*

---

## 7. Instalação e Configuração Segura do MySQL 8.0

Instale o MySQL/MariaDB:
```bash
sudo apt install mysql-server -y
```

Execute o script de segurança interativo:
```bash
sudo mysql_secure_installation
```
*(Responda "Y" para Validate Password, remova anonymous users, disable root login remoto, remova test database).*

Acesse o MySQL para criar o banco:
```bash
sudo mysql -u root -p
```

```sql
CREATE DATABASE mikrotik_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mikrotik_user'@'localhost' IDENTIFIED BY 'UmaSenhaForteAqui!';
GRANT ALL PRIVILEGES ON mikrotik_db.* TO 'mikrotik_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

---

## 8 e 9. Certbot (Let's Encrypt), SSL/TLS e Redirecionamento HTTPS

Instale o Certbot:
```bash
sudo apt install certbot python3-certbot-nginx -y
```

Obtenha o certificado SSL (substitua pelo seu domínio/IP que o DNS já aponta):
```bash
sudo certbot --nginx -d seudominio.com.br
```
O Certbot perguntará se você quer redirecionar automaticamente tráfego HTTP para HTTPS. **Escolha a opção de redirecionamento (Redirect)**. O Nginx será configurado automaticamente.

### Teste de Renovação Automática
```bash
sudo certbot renew --dry-run
```

---

## 10. Testes de SSL/TLS (Segurança)

Após instalar o certificado, teste o nível e a força da criptografia do seu servidor em:

1. **SSL Labs:** [https://www.ssllabs.com/ssltest/](https://www.ssllabs.com/ssltest/)
   - Objetivo: Obter nota **A** ou **A+** (ativando HSTS).
2. **SSL.org Checker:** [https://ssl.org/ssl-checker/](https://ssl.org/ssl-checker/)
3. **DigiCert PQC Checker (Post-Quantum Cryptography):** Para análises avançadas do handshake TLS.

---

## 11. Integração com GitHub Actions (CI/CD)

Para o pipeline definido em `.github/workflows/deploy.yml` funcionar, você precisa:

1. **Criar um usuário de deploy no servidor:**
```bash
sudo adduser deploy
sudo usermod -aG www-data deploy
```

2. **Gerar uma chave SSH no seu computador (NÃO no servidor):**
```bash
ssh-keygen -t ed25519 -C "deploy@github-actions"
```

3. **Colocar a chave pública no servidor:**
Copie o conteúdo de `id_ed25519.pub` para `/home/deploy/.ssh/authorized_keys` no servidor.

4. **Configurar as Permissões no Servidor:**
O usuário de deploy precisará rodar `sudo systemctl reload php-fpm`. Edite o arquivo sudoers:
```bash
sudo visudo
```
Adicione:
```
deploy ALL=(ALL) NOPASSWD: /usr/bin/systemctl reload php8.1-fpm, /usr/bin/systemctl reload nginx, /usr/bin/cp, /usr/bin/chown, /usr/bin/chmod
```

5. **Configurar GitHub Secrets:**
No GitHub, vá em **Settings > Secrets and variables > Actions**. Crie:
- `SERVER_HOST`: O IP público do servidor
- `SERVER_USER`: `deploy`
- `SSH_PRIVATE_KEY`: Cole o conteúdo da sua chave privada (`id_ed25519`).
- `SERVER_PORT`: `22` (Opcional, o padrão é 22)
