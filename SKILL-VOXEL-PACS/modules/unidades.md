# Módulo — Unidades

## ⚠️ Dois sistemas paralelos de cadastro de Unidade — não presumir qual está em uso

Existem **dois pares completos** de rota/controller-method/view/tabela para "Unidade" na mesma `UnidadesController`, e não são a mesma coisa:

| | Sistema A — **em uso real em produção** | Sistema B — mais recente no código, sem dado real confirmado |
|---|---|---|
| Rotas | `GET/POST /unidades/{id}/edit`, `/unidades/{id}/update` | `GET /unidades/nova`, `POST /unidades/nova`, `GET/POST /unidades/{id}/editar` |
| Métodos | `UnidadesController::edit()` / `update()` | `UnidadesController::novaUnidade()` / `criarUnidade()` / `editarUnidade()` / `atualizarUnidade()` |
| View | `app/Views/unidades/edit.php` | `app/Views/unidades/nova.php` |
| Tabela | **`bi_negocio_institution_names`** — cada linha de InstitutionName DICOM ganhou colunas de CNPJ/endereço/logo/contato direto nela (migrations `2026-07-25_unidades_cnpj_endereco_logo.sql`, `2026-07-26_institution_names_complemento.sql`) | `bi_unidades` — entidade separada (`2026-08-02_bi_unidades.sql`), vinculada a N `bi_negocio_institution_names` via `unidade_id` |
| `findOrFail*` | `findOrFail()` | `findOrFailUnidade()` |

**Confirmado em 2026-08-11** contra print de produção real (`server.voxelpacs.com.br/unidades/33/edit`, ORIX TELERRADIOLOGIA) — o link do menu (`/unidades` → clique numa linha) leva pro **Sistema A**, com dado real preenchido (CNPJ, razão social, endereço, logo). Foi um erro já cometido nesta sessão: a tarefa de Template de Laudo (2026-08-11) implementou primeiro só no Sistema B, presumindo — por um comentário de código ("bi_unidades: entidade rica") e sem confirmar contra a tela real — que ele fosse o ativo. Corrigido depois no mesmo dia: o card de Template de Laudo agora existe nos dois sistemas (`unidades/edit.php` E `unidades/nova.php`), e `ReportsController::pdf()` lê das duas tabelas com `COALESCE` (prioriza `bi_negocio_institution_names`, cai pra `bi_unidades` se faltar). **Lição**: antes de editar uma tela deste projeto achando que "encontrei o form certo pela estrutura do código", confirmar contra a URL/rota que a navegação real do app usa (sidebar, listagem) — dois forms parecidos coexistindo é um padrão que já se repetiu aqui.

`unidades/index.php` lista as DUAS fontes lado a lado (`$unidades` = Sistema A, `$biUnidades` = Sistema B) — cada uma com seu próprio botão de editar apontando pra rota diferente (`/edit` vs `/editar`). Não presumir que uma suplantou a outra sem checar dado real.

## Arquivos principais
| Arquivo | Papel |
|---|---|
| `app/Controllers/UnidadesController.php` | PDO direto (sem Service/Repository, mesmo padrão de `NegociosController`). `$campos` como array associativo dinâmico pra INSERT/UPDATE — ao adicionar campo novo, só adiciona uma entrada no array, não reescreve a query inteira. Contém os dois sistemas (ver tabela acima). |
| `app/Views/unidades/edit.php` | **Form do Sistema A (ativo)**. Cards: Identificação (nome de exibição/responsável), CNPJ e Dados Fiscais, Endereço, Contato e Operação (col. esquerda) — Logo, InstitutionName DICOM (somente leitura), **Template de Laudo** (2026-08-11), Botões (col. direita, `col-lg-4`). |
| `app/Views/unidades/nova.php` | Form do Sistema B (create/edit `bi_unidades`). Cards: Identificação Legal, Endereço, Contato, Logo, Vínculos InstitutionName DICOM, **Template de Laudo** (2026-08-11), Configurações/Status. |
| `database/migrations/2026-08-02_bi_unidades.sql` | Schema de `bi_unidades` (Sistema B). |
| `database/migrations/2026-07-25_unidades_cnpj_endereco_logo.sql`, `2026-07-26_institution_names_complemento.sql` | Colunas de CNPJ/endereço/contato/logo em `bi_negocio_institution_names` (Sistema A). |

## Template de Laudo — implementado nos dois sistemas (2026-08-11)

Ver `modules/report-templates.md` para o módulo completo. Resumo: `report_layout_template_id` foi adicionado em **ambas** as tabelas (`bi_negocio_institution_names` via `2026-08-11_report_layout_template_institution_names.sql`, `bi_unidades` via `2026-08-11_report_layout_templates.sql`), e o card de seleção existe nas duas views. `ReportsController::pdf()` resolve com `COALESCE(bnin.report_layout_template_id, un.report_layout_template_id)` — funciona independente de qual sistema a unidade em questão usa.

## Dependências
- Depende de: `bi_tenants` (tenant_id), `CnpjLookupService` (busca de CNPJ), `report_layout_templates` (2026-08-11).
- Consumido por: `ReportsController::pdf()` (JOIN nas duas tabelas de Unidade pra resolver template visual e dados de cabeçalho/rodapé do laudo). Logo (`copilot_logo_url`, só Sistema B) consumida externamente pelo VoxelCopilot.
- Ver `architecture/dependencias.md`.

## Achado — sem controle de acesso por perfil (2026-08-11, não corrigido)

`UnidadesController` não checa perfil/role em nenhum método (nos dois sistemas) — só `Auth::check()` (global) e escopo de tenant. Qualquer usuário autenticado do tenant (médico incluso) pode criar/editar/excluir Unidade, incluindo CNPJ, endereço, logo e template de laudo. O link "Unidades" no menu também não é condicionado por perfil. Corrigir exige checagem explícita de perfil dentro do controller — mudança de controle de acesso mais ampla que qualquer campo específico, fora do escopo de qualquer tarefa que só mexeu num campo desta tela até agora. Ver `docs/PENDENCIAS_CONHECIDAS.md`.

## Última análise
2026-08-11
