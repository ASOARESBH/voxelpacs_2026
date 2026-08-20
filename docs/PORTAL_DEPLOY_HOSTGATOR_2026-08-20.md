# Publicação do Portal de Resultados no HostGator

**Sistema:** VOXEL PACS 2026

**Ambiente de destino:** Produção — `https://portal.voxelpacs.com.br/`

**Banco de destino:** MySQL 5.7.44 / MariaDB compatível, hospedagem compartilhada HostGator

**Responsável pela execução:** Administrador técnico ou agente com acesso autorizado ao cPanel/FTP/SFTP e phpMyAdmin
**Data-base:** 20 de agosto de 2026

## 1. Objetivo e escopo

Este roteiro publica no ambiente de produção a versão homologada do **Portal de Resultados**. O pacote inclui a identidade visual oficial VOXEL PACS, visualização pública de PDF de laudos liberados, proteção explícita da ação **Ver imagens**, compartilhamento temporário de laudos por WhatsApp e e-mail, registro de auditoria e compatibilidade da persistência com MySQL 5.7.

> **Regra de segurança inegociável:** a funcionalidade de imagens deve permanecer bloqueada. Não habilite `PORTAL_IMAGES_ENABLED` nem `PORTAL_IMAGES_ANONYMIZED` enquanto não existir um gateway DICOMweb com anonimização validada.

O Portal usa **o mesmo codebase** do PACS. O subdomínio `portal.voxelpacs.com.br` deve apontar para o mesmo document root público do sistema principal, sem criar uma cópia parcial ou uma instalação independente.

| Item | Estado que deve existir após a publicação |
|---|---|
| URL pública | `https://portal.voxelpacs.com.br/` com certificado HTTPS válido |
| Document root do Portal | A mesma pasta `public/` usada pelo PACS principal |
| Banco de produção | Banco MySQL/MariaDB do VOXEL PACS, nunca o banco de outro sistema |
| Compartilhamento | Links opacos, individuais e temporários de 24 horas; token persistido somente como SHA-256 |
| WhatsApp | A aplicação gera a mensagem e abre o WhatsApp; o envio é confirmado manualmente pelo usuário |
| E-mail | PDF anexado apenas após confirmação explícita do destinatário e com SMTP funcional |
| Imagens | Tela de proteção; nenhuma URL DICOM, OHIF ou imagem clínica deve ser exposta |

## 2. Limites de atuação do agente executor

O agente deve obter autorização explícita antes de qualquer alteração em produção. Ele pode preparar arquivos, validar o repositório e ler o cPanel, mas não deve executar migrations, sobrescrever arquivos, editar `.env`, alterar DNS, trocar document root ou enviar e-mails de teste sem confirmação do responsável.

Credenciais, senhas SMTP, chaves privadas, tokens e dados de pacientes **não devem ser enviados por chat, incluídos em commits, compactados no release ou registrados no relatório de execução**. Os segredos devem ser preenchidos exclusivamente no cPanel, em variáveis seguras já existentes ou no arquivo `.env` de produção no servidor.

## 3. Pré-requisitos obrigatórios

Antes de publicar, confira os itens abaixo. Interrompa a operação se qualquer um deles não puder ser confirmado.

| Verificação | Como validar | Critério de aceite |
|---|---|---|
| Backup de arquivos | cPanel → **Backup** ou **Backup Wizard** | Arquivo de backup criado e local registrado |
| Backup do banco | phpMyAdmin → **Export** → método rápido/SQL | Dump do banco do VOXEL PACS concluído e guardado fora do diretório público |
| Janela de manutenção | Validar com o responsável | Publicação fora de horário crítico e com responsável disponível |
| Caminho do projeto | cPanel → File Manager / Domains | Confirmar a pasta real do PACS, normalmente `/home2/inlaud99/server.voxelpacs.com.br/` |
| Document root | cPanel → Domains → Manage | Portal aponta para `<raiz-do-projeto>/public/`, sem cópia paralela |
| HTTPS | cPanel → SSL/TLS Status ou painel HostGator | SSL ativo para `portal.voxelpacs.com.br` |
| Versão PHP | cPanel → MultiPHP Manager | PHP 8.1 ou superior, com extensões PDO MySQL, mbstring, openssl e fileinfo disponíveis |
| Dependências | `vendor/autoload.php` presente ou Composer disponível | Dependências podem ser atualizadas sem alterar `.env` |

A configuração detalhada do subdomínio já está documentada em [`configurar_portal_hostgator.md`](./configurar_portal_hostgator.md). Caso o HostGator não permita que dois domínios apontem para a mesma pasta, o agente deve solicitar ao suporte um alias/vhost para o document root do PACS; ele **não** deve duplicar a aplicação.

## 4. Preparação do release no repositório

A publicação deve partir da `main` atualizada, resultante do merge da `development`. O agente deve preservar `.env`, uploads, logs, sessões e qualquer configuração exclusiva de produção. A estratégia preferida é publicar o release completo do código versionado, em vez de selecionar arquivos manualmente.

Se a política de hospedagem exigir publicação incremental por FTP/SFTP ou File Manager, aplique pelo menos todos os arquivos abaixo. Eles formam o conjunto mínimo para o Portal, compartilhamento e exibição pública do PDF.

| Categoria | Arquivos obrigatórios |
|---|---|
| Núcleo e compatibilidade | `app/Core/Mailer.php`, `app/Core/View.php`, `app/Services/PortalShareService.php`, `app/Services/PatientPortalService.php` |
| Controladores e rotas | `app/Controllers/PatientPortalController.php`, `routes/portal.php` |
| Telas públicas | `app/Views/layout/portal_header.php`, `app/Views/layout/portal_footer.php`, `app/Views/portal/results.php`, `app/Views/portal/images_unavailable.php` |
| PDF público | `app/Views/reports/pdf.php` e todos os templates em `app/Views/reports/pdf/templates/` modificados no release |
| Front-end | `public/assets/css/portal.css`, `public/assets/css/mobile-responsive.css`, `public/assets/js/portal-share.js`, `public/assets/js/shared/voxel-voltar.js` |
| PWA e marca | `public/manifest.json`, `public/sw.js`, logos institucionais necessários em `public/uploads/unidades/` |
| Banco | `database/migrations/2026-08-20_portal_resultados_compartilhamento_mysql.sql` |

> **Não publique somente a migration ou somente o JavaScript.** `PortalShareService.php` precisa ser publicado junto da migration MySQL. A versão atual seleciona a sintaxe correta para MySQL 5.7 ou PostgreSQL e evita o uso de `RETURNING` e `CAST(... AS TEXT)` no banco do HostGator.

### 4.1 Dependências PHP

O envio de PDF por e-mail usa as dependências PHP do projeto. Se o `vendor/` do servidor ainda não contém `chillerlan/php-qrcode` e as demais dependências declaradas no `composer.lock`, execute no diretório raiz do projeto, usando o terminal do cPanel:

```bash
composer install --no-dev --optimize-autoloader
```

Se o plano compartilhado não disponibilizar Composer, gere o `vendor/` a partir do mesmo commit em ambiente PHP compatível e envie-o por canal seguro. Não execute `composer update` em produção e não altere as versões bloqueadas no `composer.lock`.

## 5. Banco de dados: migrations no phpMyAdmin

### 5.1 Ordem de execução

Selecione **somente** o banco do VOXEL PACS. Antes de importar, confirme visualmente o nome do banco e execute as consultas de pré-validação abaixo. Não use `INFORMATION_SCHEMA`, procedures, triggers ou recursos exclusivos de MySQL 8.

| Ordem | Arquivo | Executar quando |
|---:|---|---|
| 1 | `2026-08-16_portal_resultados_pacientes.sql` | As tabelas base do Portal ainda não existirem |
| 2 | `2026-08-16_report_templates_conteudo_livre.sql` | Ainda estiver pendente no histórico de produção |
| 3 | `2026-08-18_report_custom_templates.sql` | Ainda estiver pendente no histórico de produção |
| 4 | `2026-08-18_unidades_canais_personalizados.sql` | Ainda estiver pendente no histórico de produção |
| 5 | `2026-08-20_portal_resultados_compartilhamento_mysql.sql` | Obrigatória para os links de compartilhamento do Portal |

Execute cada arquivo individualmente em **phpMyAdmin → SQL** ou **Import** e confira o resultado antes de avançar para o próximo. Caso uma migration antiga não seja idempotente e os objetos já existam, pare e compare a estrutura antes de ignorar o erro. Não tente “corrigir” apagando tabelas de produção.

### 5.2 Pré-validação específica da nova tabela

Antes da migration de compartilhamento, execute:

```sql
SHOW TABLES LIKE 'bi_portal_share_links';
```

O resultado esperado para a primeira implantação é vazio. Em seguida, importe exatamente:

```text
database/migrations/2026-08-20_portal_resultados_compartilhamento_mysql.sql
```

A migration cria `bi_portal_share_links` com `utf8/utf8_unicode_ci`, índices para token, laudo/tenant e expiração, e não utiliza procedures, triggers ou `INFORMATION_SCHEMA`.

Depois de executar, valide:

```sql
SHOW CREATE TABLE bi_portal_share_links;
SELECT COUNT(*) AS total_registros FROM bi_portal_share_links;
SELECT channel, COUNT(*) AS qtd_links
FROM bi_portal_share_links
GROUP BY channel;
```

O `SHOW CREATE TABLE` deve confirmar os índices `uq_portal_share_token`, `idx_portal_share_report` e `idx_portal_share_expiry`. A contagem inicial pode ser zero.

## 6. Configuração de produção

Edite o arquivo `.env` **somente no servidor de produção** e preserve todos os valores existentes. Não envie, faça download para chat ou comite esse arquivo.

| Variável | Ação exigida |
|---|---|
| `DB_DRIVER` | Manter como `mysql` no HostGator enquanto o Portal usar o banco MySQL de produção |
| `DB_SCHEMA` | Não preencher para MySQL, salvo orientação já existente na infraestrutura |
| `MAIL_HOST` | Configurar o host SMTP contratado/fornecido |
| `MAIL_PORT` | Configurar a porta SMTP autorizada pelo provedor |
| `MAIL_USERNAME` | Configurar exclusivamente no servidor |
| `MAIL_PASSWORD` | Configurar exclusivamente no servidor; nunca registrar em logs ou mensagens |
| `MAIL_FROM` | Remetente institucional válido e autenticado |
| `MAIL_FROM_NAME` | `VOXEL PACS` ou a identificação institucional aprovada |
| `PORTAL_IMAGES_ENABLED` | Manter `false` |
| `PORTAL_IMAGES_ANONYMIZED` | Manter `false` |

Após editar, confirme que o `.env` não ficou acessível por HTTP e que as permissões continuam restritas ao usuário do serviço. Se o envio de e-mail não puder ser testado de forma segura, mantenha a feature de e-mail disponível apenas após a configuração SMTP ser concluída; links do WhatsApp continuam exigindo confirmação manual do paciente.

## 7. Sequência segura de publicação

1. Obtenha confirmação do responsável para iniciar a mudança e registre o horário.
2. Gere os backups de arquivos e banco e confirme que eles podem ser restaurados.
3. Confirme o document root e o HTTPS do subdomínio, sem modificar DNS caso já estejam corretos.
4. Coloque o release da `main` no diretório raiz do projeto, preservando `.env`, `storage/`, `public/uploads/` e permissões existentes.
5. Execute `composer install --no-dev --optimize-autoloader` somente se necessário.
6. Importe as migrations pendentes, terminando pela migration MySQL do compartilhamento.
7. Limpe somente os caches permitidos pelo ambiente. Se houver cache de CDN/Cloudflare, faça purge da versão estática para evitar JavaScript e CSS antigos.
8. Teste em janela anônima e em celular, sem utilizar dados de pacientes reais quando houver massa de homologação disponível.
9. Solicite autorização para realizar um único envio de teste por e-mail a endereço controlado. Não dispare para pacientes durante a validação.
10. Registre o resultado, os horários, o commit publicado, as migrations executadas e as evidências sem anexar dados clínicos ou segredos.

## 8. Plano de validação pós-publicação

| Caso de teste | Procedimento | Resultado esperado |
|---|---|---|
| Identidade visual | Abrir `https://portal.voxelpacs.com.br/` em janela anônima | Logo oficial VOXEL PACS; sem sidebar ou login interno do PACS |
| Acesso do paciente | Usar massa aprovada ou paciente autorizado | Fluxo confirma instituição e exibe somente exames do próprio escopo |
| Laudo liberado | Abrir um resultado com status `liberado` | Ação **Ver laudo** abre o PDF público sem botão Worklist |
| Laudo não liberado | Consultar exame ainda não liberado | Nenhum PDF ou link público é disponibilizado |
| Imagens | Acionar **Ver imagens** | Exibe a mensagem de proteção; não abre OHIF, DICOMweb nem URL clínica |
| WhatsApp | Informar número válido, confirmar e gerar link | Mensagem é preparada com URL temporária; o usuário confirma o envio no WhatsApp |
| E-mail | Informar endereço de teste autorizado e confirmar | E-mail chega com PDF anexo e link temporário; nenhum destinatário é persistido em texto puro |
| Link compartilhado | Abrir `/compartilhado/{token}` em sessão anônima | PDF acessível apenas enquanto válido; sem menus internos |
| Auditoria | Consultar `bi_portal_share_links` após teste | Token somente como hash, destinatário mascarado, canal e contador registrados |
| Responsividade | Repetir tela de resultados em celular | Ações e cartões permanecem utilizáveis sem rolagem horizontal indevida |

Para auditoria sem revelar informações clínicas, a consulta pode ser feita limitando as colunas necessárias:

```sql
SELECT id, report_id, tenant_id, channel, recipient_hint, expires_at,
       used_at, revoked_at, access_count, created_at
FROM bi_portal_share_links
ORDER BY id DESC
LIMIT 10;
```

Nunca copie o token opaco, conteúdo do laudo, e-mail completo, telefone completo ou dados do paciente para tickets ou mensagens.

## 9. Diagnóstico rápido

| Sintoma | Causa provável | Ação segura |
|---|---|---|
| `Table ... bi_portal_share_links doesn't exist` | Migration MySQL não foi importada no banco correto | Confirmar banco selecionado e importar a migration de 20/08/2026 |
| Erro próximo a `RETURNING` | Código PostgreSQL incompleto ou arquivo de serviço desatualizado | Publicar `app/Services/PortalShareService.php` do release atual |
| Erro próximo a `CAST(... AS TEXT)` | Arquivo de serviço incompatível com MySQL | Publicar o mesmo arquivo atualizado; não alterar SQL manualmente em produção |
| Modal de compartilhar não abre | CSS/JS antigo em cache ou arquivos ausentes | Confirmar `portal-share.js`, `portal.css`, `portal_footer.php` e limpar cache de CDN/navegador |
| E-mail não é enviado | SMTP ausente, credencial inválida ou bloqueio do provedor | Validar configuração SMTP no servidor e log técnico sem expor segredo |
| Portal abre o PACS interno | Host não está sendo reconhecido como Portal ou document root aponta para código antigo | Confirmar DNS, vhost, deploy completo e `app/Core/PortalHost.php` no release |
| Imagens são abertas | Variáveis de segurança alteradas indevidamente | Definir ambos os flags de imagens como `false`, invalidar cache e interromper testes |
| Erro 404 em PDF compartilhado | Rotas públicas antigas ou rewrite ausente | Publicar `routes/portal.php`, confirmar `.htaccess` existente e document root `/public` |

## 10. Rollback

O rollback precisa restaurar **código e banco de forma coerente**. Antes de iniciar, o agente deve avisar o responsável e preservar os logs técnicos da ocorrência.

1. Interrompa os testes públicos do Portal e registre o horário.
2. Restaure os arquivos do release anterior a partir do backup ou da tag/commit conhecido como estável.
3. Limpe o cache de CDN/PWA para evitar JavaScript de versões diferentes.
4. Se a tabela `bi_portal_share_links` não tiver registros reais, a reversão opcional é:

   ```sql
   DROP TABLE IF EXISTS bi_portal_share_links;
   ```

5. **Não execute o `DROP TABLE`** se existirem links ou registros de auditoria usados. Nesse caso, preserve a tabela e apenas reverta o código após decisão do responsável técnico e jurídico.
6. Valide a página inicial do Portal e o login do PACS principal. Se necessário, restaure o dump do banco gerado antes da mudança.

> O rollback não exige mudança de DNS nem de document root se esses itens já apontavam corretamente para o mesmo codebase. Evite alterar infraestrutura para corrigir um erro de aplicação.

## 11. Entregáveis que o agente executor deve devolver

Ao concluir, o agente deve entregar um relatório curto contendo o commit ou identificador do release publicado, horário de início e fim, nome das migrations executadas, status de cada item de validação, confirmação de que as flags de imagens permaneceram desabilitadas e referência ao backup. O relatório não pode conter senhas, tokens, chaves, e-mails completos, telefones completos, PDFs clínicos ou dados de pacientes.

| Entregável | Formato esperado |
|---|---|
| Release publicado | Hash curto do commit `main` ou nome do ZIP aplicado |
| Banco | Lista das migrations executadas e resultado das consultas de validação |
| Portal | URL verificada e estado do HTTPS |
| Compartilhamento | Resultado de um teste controlado de WhatsApp e, se aprovado, um e-mail de teste |
| Segurança | Confirmação de `PORTAL_IMAGES_ENABLED=false` e `PORTAL_IMAGES_ANONYMIZED=false` |
| Reversão | Local do backup e instrução de rollback disponível |

## Referências

[1] [Guia interno de configuração do subdomínio do Portal](./configurar_portal_hostgator.md)

[2] [Migration MySQL de compartilhamento do Portal](../database/migrations/2026-08-20_portal_resultados_compartilhamento_mysql.sql)

[3] [HostGator — Alterações de document root](https://www.hostgator.com/help/article/document-root-changes)

[4] [HostGator — Criação de subdomínio e document root](https://www.hostgator.com/help/article/please-read-before-creating-a-subdomain)

[5] [HostGator — Ativação de SSL gratuito](https://www.hostgator.com/help/article/hostgator-free-ssl)
