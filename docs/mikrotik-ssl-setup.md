# 🔒 Guia de Configuração: Conexão Segura SSL/TLS com MikroTik RouterOS

Este documento explica como habilitar e autenticar a comunicação via **SSL/TLS criptografado** entre o sistema web **MikroTik Pay** e o roteador **MikroTik RouterOS**, eliminando o envio de credenciais e comandos em texto puro na rede.

---

## 📌 1. Por que usar SSL na API do MikroTik?

* **Porta padrão da API (8728):** Comunicação sem criptografia. Senhas e dados trafegam em texto puro.
* **Porta segura da API-SSL (8729):** Toda a sessão é criptografada via SSL/TLS utilizando chaves assimétricas e certificados, garantindo confidencialidade e integridade dos comandos enviados pelo painel.

---

## 🛠 2. Passo a Passo no RouterOS (Terminal do MikroTik ou Winbox)

Acesse o Terminal do seu MikroTik (via Winbox ou SSH) e execute os seguintes comandos:

### Passo 2.1: Criar e Assinar o Certificado da CA (Autoridade Certificadora Local)
```routeros
# 1. Cria a Autoridade Certificadora (CA)
/certificate add name=CA-MikroTik common-name="MikroTik Root CA" days-valid=3650 key-usage=key-cert-sign,crl-sign

# 2. Assina a CA
/certificate sign CA-MikroTik
```

### Passo 2.2: Criar e Assinar o Certificado para o Serviço API-SSL
Substitua `192.168.88.1` pelo IP ou DNS do seu MikroTik (ex: seu DDNS ou IP público):
```routeros
# 3. Cria o certificado do servidor da API
/certificate add name=API-SSL-Cert common-name="192.168.88.1" days-valid=3650 key-usage=digital-signature,key-encipherment,tls-server

# 4. Assina o certificado usando a CA criada
/certificate sign API-SSL-Cert ca=CA-MikroTik
```

### Passo 2.3: Ativar o Serviço `api-ssl` na Porta 8729
```routeros
# 5. Configura e habilita o serviço api-ssl associando o certificado gerado
/ip service set api-ssl certificate=API-SSL-Cert port=8729 disabled=no

# 6. (Opcional - Recomendado) Desativa a API sem criptografia na porta 8728
/ip service set api disabled=yes
```

### Passo 2.4: Criar Usuário Dedicado para a API com Permissões Restritas
```routeros
# 7. Cria um grupo ou utiliza o grupo de API
/user group add name=grupo_api policy=api,read,write,!local,!telnet,!ssh,!ftp,!reboot,!policy,!test,!winbox,!password,!sniff,!sensitive

# 8. Cria o usuário exclusivo para o sistema web
/user add name=user_api group=grupo_api password="SUA_SENHA_FORTE_AQUI"
```

---

## 🖥 3. Configuração no Painel do MikroTik Pay

1. Acesse o painel de administração em: `https://seu-dominio-ou-ip/admin/roteadores.php`
2. Preencha os campos:
   * **Nome do Roteador:** `MikroTik Principal`
   * **IP ou Host:** Endereço IP ou hostname do roteador (ex: `192.168.88.1` ou `meu-roteador.sn.mynetname.net`)
   * **Porta da API:** Altere para `8729`
   * **Usuário:** O usuário criado (ex: `user_api`)
   * **Senha:** A senha do usuário
   * **Conexão Segura via SSL:** Marque a caixa seletora **`[x] Conexão Segura via SSL (API-SSL porta 8729)`**
3. Clique em **Salvar Configurações do MikroTik**.
4. Em seguida, clique em **Testar Conexão Agora**.
5. Uma mensagem verde indicará:
   > `Conexão Estabelecida com Sucesso! O sistema conseguiu autenticar na API Socket do MikroTik RouterOS via SSL.`

---

## 🔍 4. Como o Código PHP Trata a Conexão Segura

* Na classe [`src/MikrotikAPI.php`](file:///c:/Users/SpacoNett/Documents/proj.mikrotik/src/MikrotikAPI.php):
  O parâmetro `usar_ssl` ativa o modo seguro no driver RouterOS:
  ```php
  $api->port = (int)$this->router['porta_api']; // 8729
  $api->ssl  = !empty($this->router['usar_ssl']); // true
  ```
* Na classe [`src/RouterOSAPI.php`](file:///c:/Users/SpacoNett/Documents/proj.mikrotik/src/RouterOSAPI.php):
  O protocolo de socket é aberto dinamicamente através de `ssl://`:
  ```php
  $PROTOCOL = ($this->ssl ? 'ssl://' : '');
  $socket = stream_socket_client($PROTOCOL . $ip . ':' . $this->port, ...);
  ```
