# Sincronização Automática do Servidor PACS (Ping Agendado via cron-job.org)

**Data da atualização:** 2026-07-02

> ⚠️ **AVISO — documento desatualizado (verificado em 2026-07-08):** um merge posterior (commit `b20630f`, "Módulo Estudos v4") reverteu silenciosamente esta funcionalidade sem mencionar isso na mensagem do commit. Hoje, no código atual, **não existem** a rota `/api/servidor-pacs/cron-ping`, o método `PacsController::cronPing()`, nem os endpoints `gerarTokenCron()`/`execucoesCron()` em `ServidorPacsController`. As colunas de banco (`sync_auto_ativo`, `sync_cron_token` etc.) e a tabela `bi_pacs_sync_execucoes` continuam existindo no schema, mas não são mais lidas/escritas por nenhum Controller. Se o cron-job.org ainda estiver configurado apontando para essa URL, ele está recebendo 404. Ver `md/MANUAL_TECNICO.md` §4.1 e §14 (item P1-14) para detalhes. Este arquivo é mantido como referência histórica de como a funcionalidade foi implementada, caso a equipe decida restaurá-la.

---

## O que foi implementado

Foi adicionada, dentro de **Plataforma → Servidor PACS → Configurar**
(`/platform/servidor-pacs/configurar`), uma regra configurável de
**ping automático**: o superadmin escolhe de quanto em quanto tempo o
VOXEL PACS deve verificar se o servidor Orthanc está disponível — de
**1 minuto até 1440 minutos (24 horas)**.

Como a aplicação roda em hospedagem compartilhada (sem acesso a
`crontab`, conforme `DEPLOY_HOSTGATOR.md`), o agendamento em si é feito
por um serviço externo gratuito, o **[cron-job.org](https://cron-job.org)**,
que chama periodicamente uma URL pública e protegida por token. Cada
chamada é registrada em um histórico de execuções, exibido na própria
tela de configuração, para acompanhar se o ping foi realmente
executado e qual foi o resultado.

---

## Componentes adicionados

### 1. Banco de dados

Migração: `database/migrations/2026-07-02_pacs_sync_agendado.sql`

- Novas colunas em `bi_pacs_servidor`:
  | Coluna | Tipo | Descrição |
  |---|---|---|
  | `sync_auto_ativo` | TINYINT(1) | Liga/desliga o ping automático |
  | `sync_intervalo_minutos` | INT | Intervalo desejado, em minutos (1–1440) |
  | `sync_cron_token` | VARCHAR(64) | Token secreto que autentica a chamada do cron externo |
  | `sync_ultima_execucao` | DATETIME | Data/hora da última execução automática recebida |

- Nova tabela `bi_pacs_sync_execucoes` — histórico de cada chamada recebida
  (data/hora, origem, sucesso/falha, tempo de resposta em ms, mensagem, IP de origem).

**Aplicar a migração** (phpMyAdmin ou CLI, mesmo processo dos demais arquivos em `database/migrations/`):

```bash
mysql -u usuario -p nome_do_banco < database/migrations/2026-07-02_pacs_sync_agendado.sql
```

### 2. Backend

- `app/Controllers/Platform/ServidorPacsController.php`
  - `configurar()` — agora também carrega as últimas 20 execuções do cron.
  - `salvarConfig()` — passa a salvar `sync_auto_ativo` e `sync_intervalo_minutos` junto com a conexão.
  - `gerarTokenCron()` *(novo)* — gera um novo token aleatório (AJAX, `POST /platform/servidor-pacs/cron/gerar-token`).
  - `execucoesCron()` *(novo)* — retorna o histórico em JSON para atualização da tabela sem recarregar a página (`GET /platform/servidor-pacs/cron/execucoes`).

- `app/Controllers/PacsController.php`
  - `cronPing()` *(novo)* — endpoint **público** chamado pelo cron-job.org:
    `GET /api/servidor-pacs/cron-ping?token=SEU_TOKEN`.
    Valida o token (`hash_equals`), verifica se o ping automático está ativo,
    executa o ping no Orthanc (mesma lógica do botão "Testar Conexão"),
    atualiza `status_ping`/`ultimo_ping`/`sync_ultima_execucao` e grava o
    resultado em `bi_pacs_sync_execucoes`.

- Rotas:
  - `routes/platform.php` — `cron/gerar-token` e `cron/execucoes` (autenticadas, área da plataforma).
  - `routes/web.php` — `/api/servidor-pacs/cron-ping` (pública).
  - `public/index.php` e `app/Core/Router.php` — a nova URL pública foi
    incluída nas listas de rotas que não exigem sessão/login, para que o
    cron-job.org consiga chamá-la de fora do sistema. (Aproveitou-se para
    corrigir a mesma lacuna em `/api/orthanc/ping`, que também é pública
    mas não estava na lista usada pelo `Router` para liberar o acesso sem
    login.)

### 3. Interface

`app/Views/platform/servidor_pacs/configurar.php` ganhou dois novos cards:

- **"Sincronização Automática (Ping Agendado)"** — campo numérico de
  intervalo (1 a 1440 minutos) com atalhos rápidos (1, 5, 15, 30 min,
  1h, 6h, 12h, 24h), interruptor de ativação, URL pronta para colar no
  cron-job.org (com botão de copiar) e botão para gerar/renovar o token.
  Um accordion (`<details>`) explica o passo a passo de configuração no
  cron-job.org.
- **"Histórico de Execuções"** — tabela com as últimas 20 chamadas
  recebidas (data/hora, origem, sucesso/falha, tempo de resposta), com
  botão de atualização via AJAX. Esse é o "acompanhamento" de que a ação
  automática foi de fato executada.

---

## Como configurar (passo a passo)

1. Acesse `/platform/servidor-pacs/configurar`.
2. No card **Sincronização Automática**, defina o intervalo desejado
   (ex.: 15 minutos) e marque **Ativar**.
3. Clique em **Salvar Configurações** (parte de baixo do formulário
   principal) para gravar o intervalo e a ativação.
4. Clique em **Gerar Token** para criar a URL segura de chamada.
5. Copie a URL exibida (botão de copiar ao lado do campo).
6. Crie uma conta gratuita em **https://cron-job.org**.
7. Clique em **Create cronjob**:
   - **URL:** cole a URL copiada no passo 5.
   - **Método:** GET.
   - **Execution schedule:** configure o mesmo intervalo escolhido no
     passo 2 (ex.: "every 15 minutes"). O plano gratuito do
     cron-job.org permite intervalos a partir de 1 minuto.
8. Salve o cronjob no cron-job.org.
9. Volte para `/platform/servidor-pacs/configurar` e use o botão de
   atualizar no card **Histórico de Execuções** para confirmar que as
   chamadas estão chegando.

> Sempre que o token for renovado (botão **Gerar Token**), é preciso
> atualizar a URL cadastrada no cron-job.org — o token antigo deixa de
> ser aceito imediatamente.

---

## Segurança

- O endpoint `/api/servidor-pacs/cron-ping` é público (sem exigir
  login), pois o cron-job.org não consegue autenticar via sessão. A
  proteção é o **token** de 48 caracteres hexadecimais (`sync_cron_token`),
  comparado com `hash_equals()` para evitar timing attacks.
- Sem um token configurado, o endpoint responde `403` e não executa
  nenhuma ação.
- O ping automático só é executado se `sync_auto_ativo = 1`; desativar
  a chave na tela de configuração interrompe o processamento mesmo que
  o cron-job.org continue chamando a URL.
- A ação executada pelo cron é apenas um **ping/health-check** (idêntico
  ao botão "Testar Conexão"), não uma sincronização completa de estudos —
  a importação de estudos continua sendo feita manualmente pelo botão
  "Sincronizar" existente na tela.

---

## Referência rápida da API

| Rota | Método | Autenticação | Descrição |
|---|---|---|---|
| `/platform/servidor-pacs/cron/gerar-token` | POST | Sessão (platform) | Gera novo token e invalida o anterior |
| `/platform/servidor-pacs/cron/execucoes` | GET | Sessão (platform) | Lista as últimas 20 execuções em JSON |
| `/api/servidor-pacs/cron-ping?token=...` | GET | Token via query string | Executa o ping agendado (chamado pelo cron-job.org) |
