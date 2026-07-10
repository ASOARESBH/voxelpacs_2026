# Guia de Deploy - VOXEL B.I na HostGator (cPanel)

Este guia descreve os passos para instalar o VOXEL B.I em uma hospedagem compartilhada HostGator com cPanel.

## 1. Preparação do Ambiente (cPanel)

1. Acesse o seu cPanel.
2. Vá em **Gerenciador de Arquivos**.
3. Navegue até a pasta raiz do seu domínio (geralmente `public_html` ou uma pasta específica do subdomínio).

## 2. Upload dos Arquivos

1. Compacte todos os arquivos do projeto VOXEL B.I em um arquivo `.zip`.
2. No Gerenciador de Arquivos do cPanel, clique em **Carregar** e envie o arquivo `.zip`.
3. Após o upload, clique com o botão direito no arquivo `.zip` e selecione **Extrair**.
4. Certifique-se de que o arquivo `.htaccess` na raiz do projeto foi extraído (ele é oculto por padrão, ative a visualização de arquivos ocultos nas configurações do Gerenciador de Arquivos).

## 3. Configuração do Banco de Dados (MariaDB)

1. No cPanel, vá em **Bancos de Dados MySQL**.
2. Crie um novo banco de dados (ex: `seuusuario_voxelbi`).
3. Crie um novo usuário MySQL e gere uma senha forte.
4. Adicione o usuário ao banco de dados criado, concedendo **Todos os Privilégios**.
5. Vá em **phpMyAdmin** no cPanel.
6. Selecione o banco de dados criado e importe o arquivo `database/migrations/2026-01-01_bi_multitenant_schema.sql`.

## 4. Configuração do Arquivo .env

1. No Gerenciador de Arquivos, renomeie o arquivo `.env.example` para `.env`.
2. Edite o arquivo `.env` e preencha as informações do banco de dados:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://seu-dominio.com.br

DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=seuusuario_voxelbi
DB_USERNAME=seuusuario_user
DB_PASSWORD=sua_senha_forte
```

## 5. Permissões de Pastas

Para que o sistema funcione corretamente, algumas pastas precisam de permissões de escrita (755):

1. No Gerenciador de Arquivos, clique com o botão direito na pasta `storage` e selecione **Change Permissions**.
2. Defina as permissões para `755` (User: Read/Write/Execute, Group: Read/Execute, World: Read/Execute).
3. Faça o mesmo para as subpastas `storage/logs` e `storage/uploads`.

## 6. Versão do PHP

1. No cPanel, vá em **Selecionar Versão do PHP** (ou MultiPHP Manager).
2. Certifique-se de que a versão do PHP está definida para **8.1** ou superior.
3. Ative as seguintes extensões (se não estiverem ativas):
   - `pdo_mysql`
   - `mbstring`
   - `json`
   - `fileinfo`
   - `gd` ou `imagick`
   - `zip`

## 7. Acesso ao Sistema

Acesse o seu domínio no navegador. O sistema deve carregar a tela de login.

**Credenciais Padrão:**
- **E-mail:** admin@voxel.com.br
- **Senha:** Admin@2026

> **IMPORTANTE:** Altere a senha do administrador imediatamente após o primeiro login.

## Estrutura de Roteamento (Como funciona no cPanel)

O sistema foi adaptado para não exigir acesso SSH ou configurações complexas de VirtualHost:

1. O `.htaccess` na raiz redireciona silenciosamente todas as requisições para a pasta `public/`.
2. O `.htaccess` dentro da pasta `public/` redireciona tudo para o `index.php`.
3. O `app/autoload.php` customizado carrega as classes sem precisar rodar o `composer install` no servidor.
4. As sessões são salvas na pasta `storage/sessions` para evitar problemas de permissão no diretório temporário padrão do servidor compartilhado.
