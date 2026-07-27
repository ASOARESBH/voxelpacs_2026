# Servidor PACS multi-servidor: modelo N:N, roteamento por InstitutionName, sync incremental

**Data:** 2026-07-27

## Contexto — o que existia antes desta mudança

Antes desta tarefa, `bi_pacs_servidor` era uma tabela com **uma única linha fixa (`id=1`)** — não existia
conceito de "vários servidores Orthanc" em lugar nenhum do código (`servidor_id = 1` estava hardcoded em
mais de 15 pontos: `ServidorPacsController`, `EstudosController`, `EstudosRepository`, `ExamesPacsController`,
`ReportsController`, `DesktopViewerService`). Apesar disso, multi-negócio-por-servidor **já acontecia de fato
em produção**: os tenants INOVA e ORIX TELERRADIOLOGIA já dividem o mesmo Orthanc físico via duas tabelas de
"de-para" (`bi_pacs_roteamento` e `bi_negocio_institution_names`), sem nenhuma detecção de ambiguidade — a
migration `2026-07-26_migrate_institution_names.sql` já documenta um caso real de estudos que foram parar no
tenant errado por causa disso.

Havia ainda um sistema legado morto (`bi_orthanc_servidores` / `ServidorController` / rota `/servidor`,
per-tenant, migration `2026-05-11`) sem nenhuma rota ativa — não foi tocado nesta tarefa, fica documentado aqui
como referência histórica caso alguém o encontre.

## 1. Schema N:N final

### `bi_pacs_servidor` (existente, virou multi-linha de verdade)
Nenhuma mudança de PK — apenas novas colunas para suportar sincronização incremental por servidor:
`changes_cursor` (cursor do `GET /changes` do Orthanc), `sync_lock_at` (lock de concorrência do ciclo
automático), `sync_estudos_ultimo_ciclo` / `sync_nao_identificados_ultimo_ciclo` / `sync_conflitos_ultimo_ciclo`
(resumo do último ciclo, exibido no dashboard). A senha (`senha`) passou a ser gravada criptografada
(ver seção 4).

### `bi_negocio_servidor_pacs` (novo — pivot N:N)
```
id, tenant_id (FK bi_tenants), servidor_id (FK bi_pacs_servidor), ativo, criado_em, criado_por (FK bi_users)
UNIQUE (tenant_id, servidor_id)
```
Um servidor pode ter 0, 1 ou N negócios associados; um negócio pode ter 0, 1 ou N servidores associados.
As credenciais de acesso ao Orthanc continuam sendo propriedade exclusiva do **servidor**, nunca do vínculo.

### `bi_pacs_estudos` (existente, ganhou colunas de roteamento + dump completo)
`roteamento_status` (`roteado`/`nao_identificado`/`conflito`), `roteamento_candidatos` (JSON com os tenants
candidatos, só preenchido em conflito), `roteamento_resolvido_por` / `roteamento_resolvido_em` (auditoria da
resolução manual), `dicom_tags_completas` (JSON — ver seção 3).

**Decisão deliberada: não foi criada uma tabela-fila separada para "não identificados"/"conflitos".** O
schema já tinha **duas versões conflitantes** de `bi_institution_name_pendentes` em migrations diferentes,
nenhuma usada em código — exatamente o tipo de "fila esquecida" que o enunciado pede para evitar. Em vez
disso, o estado vive na própria `bi_pacs_estudos` (a mesma tabela que já é a UI principal), e a tela de
estudos apenas filtra por esse status. Um estudo nunca fica fora da tabela onde o Platform Admin já olha.

### Fonte de verdade do InstitutionName: `bi_tenant_unidades_dicom`
O motor de roteamento novo (`PacsRoutingService`) usa **exclusivamente** `bi_tenant_unidades_dicom` — a
tabela "Unidades" (CNPJ/endereço) já existente, não uma estrutura paralela nova, conforme pedido. As tabelas
antigas (`bi_pacs_roteamento`, `bi_negocio_institution_names`) continuam existindo e funcionando exatamente
como antes (tela `/platform/servidor-pacs/roteamento` intocada) — só não são mais consultadas pelo motor novo.
Para não regredir o roteamento das produções já configuradas por essas tabelas, a migration
`2026-07-27_pacs_servidores_nn_roteamento.sql` faz um **backfill** (`INSERT IGNORE`) copiando todo InstitutionName
já cadastrado nelas para `bi_tenant_unidades_dicom`, e associa ao pivot N:N os negócios que já recebiam
estudos do servidor global antes desta mudança.

## 2. Algoritmo de roteamento (`App\Services\PacsRoutingService::resolveTenant`)

Entrada: `servidor_id` + `InstitutionName` (tag DICOM 0008,0080) do estudo importado.

1. Busca os negócios **ativos** associados àquele servidor (`bi_negocio_servidor_pacs`).
2. Se nenhum negócio está associado ao servidor → `nao_identificado` (nada a checar).
3. Entre esses negócios, busca em `bi_tenant_unidades_dicom` quais têm uma Unidade com aquele
   InstitutionName — comparação normalizada (case/acento-insensitive, reaproveitando
   `InstitutionResolverService::normalize()` já existente, não duplicada).
4. **0 negócios batem** → `nao_identificado`. Estudo é importado e fica visível na fila de pendências,
   nunca invisível.
5. **Exatamente 1 negócio bate** → `roteado`, `tenant_id` preenchido normalmente.
6. **2+ negócios batem** (mesma InstitutionName cadastrada em mais de um negócio do mesmo servidor) →
   `conflito`. O sistema **não decide sozinho** — grava os candidatos em `roteamento_candidatos` e aparece
   na seção "Conflitos" da tela de estudos para o Platform Admin resolver manualmente.

Uma resolução manual (`ServidorPacsController::resolverEstudo`) grava `roteamento_resolvido_por`/`_em`.
A partir daí, `PacsSyncService::upsertEstudo()` **nunca mais sobrescreve** o roteamento daquele estudo em
ciclos futuros (só atualiza metadados/tags) — validado explicitamente (ver seção de testes).

## 3. Tags DICOM completas: JSON, não tabela EAV

Decisão: coluna `bi_pacs_estudos.dicom_tags_completas` (LONGTEXT/JSON), alimentada por
`GET /studies/{id}/shared-tags` (mais completo que o `MainDicomTags` usado nas colunas estruturadas — inclui
qualquer tag compartilhada por todas as instâncias do estudo, não só o subconjunto que o Orthanc indexa).

**Por que não uma tabela genérica `(estudo_id, tag_group, tag_element, tag_name, tag_value)`:**
- As ~120 tags mais usadas em filtro/ordenação do worklist já são colunas estruturadas dedicadas — isso não
  muda, continua sendo o caminho de performance.
- O caso de uso do dump completo é **"ver tudo de um estudo específico"** (tela de detalhe/modal), não
  "buscar estudos por uma tag DICOM arbitrária qualquer" — não há requisito nem indício de uso para o
  segundo caso hoje.
- Uma tabela EAV com uma linha por tag por estudo multiplicaria o volume de linhas por ~80-150x em relação a
  `bi_pacs_estudos` (validado no teste: um único estudo já gera dezenas de tags mesmo num fixture mínimo de
  teste) sem nenhum ganho de consulta, já que a busca é sempre "todas as tags de 1 estudo", um único
  `SELECT` indexado por `id` de qualquer forma.
- Se um dia surgir a necessidade real de busca por tag específica, JSON em MySQL 5.7+/MariaDB ainda permite
  `JSON_EXTRACT`/`JSON_CONTAINS` — não fecha a porta, só não paga o custo de armazenamento antecipadamente.

O endpoint `GET /platform/servidor-pacs/estudos/{id}/tags` busca esse JSON sob demanda (lazy, só quando o
Platform Admin abre o modal "Ver tags"), para não inflar a listagem paginada com o dump completo de até 50
estudos por vez.

## 4. Credenciais criptografadas (`App\Core\Crypto`)

Não existia nenhum helper de criptografia no projeto — todo campo "criptografado" (`bi_pacs_servidor.senha`,
`bi_orthanc_servidores.senha`, `business_webhook_hub_configs.jwt_secret`) tinha só um comentário de coluna
aspiracional, valor real sempre em texto puro. `App\Core\Crypto` (AES-256-GCM via `openssl_encrypt`, chave
derivada por HKDF do `APP_SECRET` já existente em `.env`, nunca lido antes) resolve isso para o servidor PACS.
Migração suave: valores novos gravados com prefixo `enc:v1:`; leitura decripta só se tiver esse prefixo, senão
trata como texto legado — sem quebrar o servidor Hetzner já configurado em produção, sem script de migração
arriscado.

## 5. Sincronização automática a cada 2 minutos

`App\Services\PacsSyncService::executarParaTodosServidores()`, disparado por
`GET /api/servidor-pacs/sync-robo?token=...` — mesmo padrão já validado do robô de Regras de SLA
(`SlaRoboController`): endpoint público, token comparado com `hash_equals()`, chamado por um cron externo
(cron-job.org), já que esta hospedagem compartilhada não tem crontab real.

Por ciclo: itera todos os servidores `ativo=1`; por servidor, tenta obter um lock (`sync_lock_at`, evita 2
ciclos simultâneos no mesmo servidor — expira sozinho após 10 minutos, cobre travamento por crash); faz
`ping()` e, se falhar, marca offline e **passa para o próximo servidor sem lançar exceção** (isolamento
validado — ver testes); pagina `GET /changes?since=cursor` até `Done=true`, salvando o cursor a cada página
processada (idempotência: um crash no meio do ciclo não reprocessa o que já avançou); para cada
`NewStudy`/`StableStudy` de tipo `Study`, busca o estudo completo + shared-tags, aplica
`PacsRoutingService::resolveTenant()` e faz upsert em `bi_pacs_estudos`.

O botão manual "Sincronizar agora" (`ServidorPacsController::sincronizar`) continua existindo como
complemento síncrono (full-resync via `/studies`, não incremental) — útil para forçar uma resincronização
completa fora do ciclo de 2 minutos; ambos os caminhos compartilham a mesma lógica de upsert/roteamento
(`PacsSyncService::upsertEstudo`), para nunca haver dois comportamentos de roteamento divergentes.

## Validação executada

Sem acesso a Docker neste ambiente para subir um Orthanc real, a validação foi feita com um Orthanc "fake"
(servidor HTTP local em PHP servindo fixtures JSON para `/system`, `/changes`, `/studies/{id}`,
`/studies/{id}/series`, `/studies/{id}/shared-tags`) — cobre exatamente a mesma superfície de API que
`OrthancService` consome, validando a lógica real de ponta a ponta:

| Cenário pedido | Resultado observado |
|---|---|
| Servidor com 2 negócios, cada um com InstitutionName próprio | 1 servidor, 1 chamada a `/changes` no log; `CLINICA UM`→tenant 1 roteado corretamente |
| InstitutionName sem unidade cadastrada em nenhum negócio associado | `roteamento_status=nao_identificado`, `tenant_id=NULL`, aparece na fila |
| 2 negócios com a mesma InstitutionName no mesmo servidor | `roteamento_status=conflito`, `roteamento_candidatos` com os 2 nomes |
| Negócio com 2 servidores diferentes | Cursores evoluíram independentemente (`changes_cursor=3` e `=1`); ambos sincronizados na mesma chamada |
| Servidor offline (URL inacessível) | Isolado (`status=offline`, erro de conexão), demais servidores do mesmo ciclo processados normalmente |
| Rodar o ciclo 2x seguidas | 2ª rodada: `novos=0` em todos os servidores — cursor evitou reprocessamento |
| Resolução manual de conflito, depois novo evento do mesmo estudo no Orthanc | `tenant_id`/`roteamento_status` preservados da resolução manual; só metadados/tags atualizados |
| Isolamento multi-tenant | `WHERE tenant_id=1` e `WHERE tenant_id=2` não se sobrepõem; estudo `nao_identificado` não aparece em nenhum dos dois |
| Tags DICOM completas | `dicom_tags_completas` bate exatamente com o `shared-tags` retornado pelo Orthanc fake |

## Legado — não tocado nesta tarefa

- `bi_orthanc_servidores` / `ServidorController` / rota `/servidor`: sistema per-tenant anterior ao modelo
  global, sem rota ativa hoje. Não migrado, não removido.
- `bi_pacs_roteamento` / tela `/platform/servidor-pacs/roteamento`: de-para manual antigo, continua
  funcionando exatamente como antes, mas não é mais consultado pelo motor de roteamento novo.
- `bi_negocio_institution_names`: idem — só consultado pelo card antigo "InstitutionNames no PACS", que foi
  removido do dashboard nesta reforma (substituído pela visão N:N por servidor).
- `bi_institution_name_pendentes` (as duas versões): permanecem no schema, continuam não usadas por nenhum
  código — decisão explícita de não ressuscitá-las (ver seção 1).
