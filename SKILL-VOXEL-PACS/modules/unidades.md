# Módulo — Unidades (`bi_unidades`)

## Propósito
CRUD de Unidades/clínicas — entidade rica com CNPJ (busca automática BrasilAPI→ReceitaWS→OpenCNPJ), endereço, contato, logo, e vínculo N:N com `bi_negocio_institution_names` (InstitutionName DICOM). Rota `/unidades`, `/unidades/nova`, `/unidades/{id}/editar`.

**Cuidado com nomenclatura de rotas**: existe um par MAIS ANTIGO e mais simples de rotas na mesma controller (`/unidades/create`, `/unidades/{id}/edit` — inglês) que NÃO é este módulo — são vestígios de uma versão anterior/mais simples de Unidade. As rotas em português (`/unidades/nova`, `/unidades/{id}/editar`) são as ativas, apontando pra `bi_unidades` (entidade rica). Confirmar sempre qual rota está em uso antes de presumir.

## Arquivos principais
| Arquivo | Papel |
|---|---|
| `app/Controllers/UnidadesController.php` | PDO direto (sem Service/Repository, mesmo padrão de `NegociosController`). `$campos` como array associativo dinâmico pra INSERT/UPDATE (`criarUnidade()`/`atualizarUnidade()`) — ao adicionar campo novo à tabela, só precisa adicionar uma entrada no array, não reescrever a query inteira. |
| `app/Views/unidades/nova.php` | Form único de create/edit (`$isEdit = $unidade !== null`), 6 cards/seções: Identificação Legal (CNPJ), Endereço, Contato, Logo, Vínculos InstitutionName DICOM, **Template de Laudo** (2026-08-11, ver `modules/report-templates.md`), Configurações/Status. |
| `database/migrations/2026-08-02_bi_unidades.sql` | Schema de `bi_unidades`. |

## Dependências
- Depende de: `bi_tenants` (tenant_id), `bi_negocio_institution_names` (vínculo N:N via `unidade_id`), `CnpjLookupService` (busca de CNPJ), `report_layout_templates` (2026-08-11).
- Consumido por: `ReportsController::pdf()` (JOIN pra resolver template visual e dados de cabeçalho/rodapé do laudo — ver `modules/report-templates.md`). Logo (`copilot_logo_url`) consumida externamente pelo VoxelCopilot.
- Ver `architecture/dependencias.md`.

## Achado — sem controle de acesso por perfil (2026-08-11, não corrigido)

`UnidadesController` não checa perfil/role em nenhum método — só `Auth::check()` (via `Router::dispatch()` global) e `TenantContext::id()`/`Auth::tenantId()` pra escopo de tenant. Qualquer usuário autenticado do tenant (médico incluso) pode criar/editar/excluir Unidade, incluindo CNPJ, endereço, logo e (desde 2026-08-11) o template de laudo. O link "Unidades" no menu (`pacs_header.php`, submenu "Cadastros") também não é condicionado por perfil. Se algum dia a regra "só Administrador edita Unidade" precisar ser real (não só "não tem link visível pro médico"), isso exige checagem explícita de perfil dentro do controller — mudança de controle de acesso mais ampla que qualquer campo específico, fora do escopo de qualquer tarefa que só mexeu num campo desta tela até agora. Ver `docs/PENDENCIAS_CONHECIDAS.md`.

## Última análise
2026-08-11
