# Módulo — Tenants / Multi-tenancy / Impersonação

## Propósito
Como o VOXEL PACS decide "de qual Negócio é este dado" em cada requisição, e como um superadmin pode temporariamente "entrar" num Negócio específico (impersonação) para ver o sistema do ponto de vista daquele tenant.

## Arquivos principais
| Arquivo | Papel |
|---|---|
| `app/Core/Auth.php` | `tenantId()`, `isPlatformAdmin()`, `isImpersonating()` — fonte de verdade lida da sessão |
| `app/Core/TenantContext.php` | Cache estático por request, alimentado só por `TenantMiddleware` |
| `app/Middlewares/TenantMiddleware.php` | Resolve `TenantContext` a partir da sessão; roda em toda rota exceto pública e `/platform/*` |
| `app/Controllers/Platform/TenantsController.php` | `impersonate()`/`exitImpersonate()` — entrada e saída da impersonação |
| `app/Core/Router.php` | Guard inline que bloqueia `/platform/*` para não-superadmin (não é um Middleware de fato, é inline em `dispatch()`) |

## Dois padrões de tenant-scoping coexistindo (ler antes de mexer em filtro de tenant em qualquer Controller)

1. **Padrão TenantContext**: Controller/Model lê `TenantContext::id()`/`isSet()`, que só é populado por `TenantMiddleware`. Usado por `App\Core\Model` (base de `Configuracao`, `Exame`, `Importacao`, `Medico`, `PacsConexao`, `Report`, `Unidade`), `ExamesPacsController`, `ModalidadesController`, `ImportacaoController`, `MedicosController`, `UsuariosController`, `ServidorController`, `ReportRepository`, `KpiService`.
2. **Padrão Auth direto**: Controller lê `Auth::tenantId()`/`Auth::isPlatformAdmin()` sem passar por `TenantContext`. Usado por `EstudosController` (Worklist) e `ReportsController`.

Uma correção de tenant-scoping num Controller do padrão 1 **não** propaga automaticamente para um Controller do padrão 2, e vice-versa — sempre confirmar qual padrão o Controller em questão usa antes de generalizar uma correção. Ver `architecture/auth-e-permissoes.md` para o histórico completo da correção de 2026-07-15 que afetou os dois padrões.

## Impersonação — resumo (detalhe completo em `architecture/auth-e-permissoes.md`)

- Escopo: por sessão PHP, nunca permanente. Encerra sozinha no logout; reversível a qualquer momento via `/platform/impersonate/exit`.
- `$_SESSION['original_user']` é setado em `impersonate()` mas **nunca é usado para trocar identidade** — `$_SESSION['user']` não muda durante a impersonação (só `tenant_id` muda). É estado morto, só limpo em `exitImpersonate()`; não existe "restaurar usuário original" de fato porque o usuário nunca deixou de ser ele mesmo.
- Auditado na entrada (`impersonate`) e na saída (`exit_impersonate`) via `AuditLogger`.
- Banner obrigatório "Visualizando como: X" + "Sair da Impersonação" em qualquer layout que sirva tela tenant-scoped (hoje: `pacs_header.php`).
- Fonte única de verdade para "está impersonando": `Auth::isImpersonating()` (adicionado 2026-07-15) — usado tanto por `TenantMiddleware` quanto por `EstudosController`, para não duplicar essa inferência com sinais diferentes em lugares diferentes.

## `Platform\ServidorPacsController` fica FORA do escopo de tenant, por design

É o painel global do superadmin (roteamento InstitutionName → Negócio, sync do Orthanc, log de sincronização) — inerentemente cross-tenant, não faz sentido "ver só o Negócio impersonado" aqui. Está sob `/platform/*`, que nunca passa por `TenantMiddleware`. Confirmado explicitamente como decisão consciente em 2026-07-15, não um gap pendente.

## O que NÃO existe ainda (não presumir em tarefas futuras)

**Atualizado em 2026-07-18**: o vínculo médico → unidade descrito abaixo como inexistente (confirmado 2026-07-15) **passou a existir** com o módulo Regras de SLA — ver `modules/sla-regras.md`. Hoje existe `bi_medico_unidades` (N:N, por `institution_name`, populado em `/medicos/{id}/edit`), usado para filtrar candidatos elegíveis quando uma regra de SLA restringe o remanejamento a uma unidade. **Importante**: esse vínculo ainda não é usado como controle de *acesso* (não restringe o que um médico enxerga na worklist) — só serve como filtro de elegibilidade para o robô de Regras de SLA escolher médico. Um médico sem nenhuma unidade vinculada continua tendo acesso normal à worklist inteira do tenant; só fica de fora de regras que tenham `filtro_institution_name` preenchido.

O filtro de tenant desta tarefa (`tenant_id` em `bi_pacs_estudos`, `TenantContext`/`Auth::tenantId()`) foi mantido como uma camada só de "qual Negócio" — uma camada futura de "quais Unidades dentro do Negócio" pode ser adicionada por cima (ex: mais uma condição `AND institution_name IN (...)` no `WHERE` já existente) sem precisar refazer o que existe hoje.

## Última análise
2026-07-15
