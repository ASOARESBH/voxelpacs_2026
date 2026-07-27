# Módulo — Servidor PACS

## Propósito
Tela superadmin (`/platform/servidor-pacs`) para gerenciar **N servidores Orthanc**, cada um associável a
**N negócios** (pivot N:N), rotear estudos por InstitutionName → Negócio automaticamente, sincronizar
incrementalmente a cada 2 minutos (robô) e listar estudos importados com filas de pendência.

**Mudança estrutural 2026-07-27**: até então `bi_pacs_servidor` era uma tabela de 1 linha fixa (`id=1`),
com `servidor_id = 1` hardcoded em ~15 lugares do código (incluindo fora deste módulo — worklist, laudos,
viewer). Ver `docs/PACS_MULTISERVIDOR_ROTEAMENTO.md` para o desenho completo e o porquê de cada decisão.

## Arquivos principais
| Arquivo | Papel |
|---|---|
| `app/Controllers/Platform/ServidorPacsController.php` | CRUD de servidores, associação N:N, sync manual, estudos/pendências — sem Service/Repository dedicado, PDO direto (mesmo padrão do resto de `Platform/`) |
| `app/Services/PacsRoutingService.php` | Motor de roteamento InstitutionName → Negócio (3 cenários: roteado/não identificado/conflito) |
| `app/Services/PacsSyncService.php` | Ciclo de sincronização incremental por servidor (cursor `/changes`, lock, upsert compartilhado com o botão manual) |
| `app/Controllers/PacsSyncRoboController.php` | Endpoint público `/api/servidor-pacs/sync-robo` (token), chamado por cron externo a cada 2 min |
| `app/Core/Crypto.php` | Criptografia AES-256-GCM das credenciais do servidor (`senha`) — infraestrutura nova, não existia antes |
| `app/Views/platform/servidor_pacs/index.php` | Lista de servidores (cards) + robô de sync automático |
| `app/Views/platform/servidor_pacs/configurar.php` | Form de um servidor + seção "Negócios Associados" (N:N) |
| `app/Views/platform/servidor_pacs/estudos.php` | Lista/filtro de estudos + seções "Não identificados"/"Conflitos" + modal de tags DICOM completas |
| `app/Views/platform/servidor_pacs/roteamento.php` | **Legado** — de-para manual antigo, intocado, não consultado pelo motor novo |
| `app/Services/OrthancService.php` | Cliente REST do Orthanc — `getChanges()`/`getSharedTags()`/`fetchAndNormalizeStudy()` adicionados nesta tarefa |

## Dependências
- Depende de: `bi_pacs_servidor` (agora multi-linha), `bi_negocio_servidor_pacs` (novo pivot N:N),
  `bi_pacs_estudos` (colunas `roteamento_*` e `dicom_tags_completas` novas), `bi_tenant_unidades_dicom`
  (fonte única de verdade do InstitutionName para o motor novo), `bi_pacs_sync_log`, `bi_pacs_sync_robo_config`
  (novo, config global do robô), `bi_tenants`, `OrthancService`
- Legado mantido mas não consultado pelo motor novo: `bi_pacs_roteamento`, `bi_negocio_institution_names`
- Consumido por: `EstudosController`/`EstudosRepository`/`ExamesPacsController`/`ReportsController`/
  `DesktopViewerService` deixaram de assumir `servidor_id = 1` — agora resolvem o servidor real do estudo
  (`bi_pacs_estudos.servidor_id`) quando precisam de credenciais/config de conexão
- Ver `architecture/dependencias.md` para o grafo completo

## Roteamento por InstitutionName (motor novo, `PacsRoutingService`)
Fonte única de verdade: **`bi_tenant_unidades_dicom`** (não `bi_negocio_institution_names`, não
`bi_pacs_roteamento`). Algoritmo por servidor+InstitutionName: busca negócios associados ao servidor via
`bi_negocio_servidor_pacs` → filtra quais têm Unidade com aquele InstitutionName (normalizado, reaproveitando
`InstitutionResolverService::normalize()`) → 0 matches = `nao_identificado`, 1 match = `roteado`, 2+ matches =
`conflito` (nunca decide sozinho, grava candidatos em `roteamento_candidatos`). Uma resolução manual
(`resolverEstudo()`) trava esse estudo contra sobrescrita por ciclos automáticos futuros
(`roteamento_resolvido_por` preenchido → `PacsSyncService::upsertEstudo()` só atualiza metadados, não roteamento).

## Sincronização automática (a cada 2 minutos, por servidor)
Mesmo padrão do robô de Regras de SLA: endpoint público `/api/servidor-pacs/sync-robo?token=...`, token
global em `bi_pacs_sync_robo_config` (singleton), chamado por cron-job.org. Usa `GET /changes?since=cursor`
(incremental, cursor salvo por servidor em `bi_pacs_servidor.changes_cursor`) em vez de `importAllStudies()`
completo. Lock de concorrência (`sync_lock_at`, expira em 10 min) evita 2 ciclos simultâneos no mesmo servidor.
Falha de ping em 1 servidor não aborta os demais do mesmo ciclo (isolamento testado).

## Padrões seguidos
Controller com PDO direto (sem Service/Repository para CRUD), mas a lógica de roteamento/sync foi extraída
para Services (`PacsRoutingService`, `PacsSyncService`) porque é reaproveitada por 2 caminhos (botão manual
e robô automático) — não seria sustentável duplicar ~120 colunas de upsert em dois lugares.

## Riscos / pontos frágeis conhecidos
- `bi_pacs_roteamento`/`bi_negocio_institution_names` continuam no schema e com UI própria, mas
  desconectados do motor de roteamento novo — cadastrar algo lá não afeta mais o roteamento automático.
  Ver `docs/PACS_MULTISERVIDOR_ROTEAMENTO.md` §1 para o backfill que preservou a continuidade dos
  roteamentos já configurados em produção (INOVA/ORIX) no momento da migração.
- `bi_orthanc_servidores`/`ServidorController` (rota `/servidor`, per-tenant) é legado morto sem rota ativa
  — não tocado, não removido.
- Sem Docker/Orthanc real disponível no ambiente de desenvolvimento local nesta sessão — validação de
  ponta a ponta foi feita com um Orthanc fake (servidor HTTP local servindo fixtures JSON), cobrindo a mesma
  superfície de API (`/system`, `/changes`, `/studies/{id}`, `/studies/{id}/series`, `/studies/{id}/shared-tags`).
  Recomenda-se validar novamente contra um Orthanc real antes do primeiro deploy em produção.

## Última análise
2026-07-27
