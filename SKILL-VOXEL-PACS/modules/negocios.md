# Módulo — Negócios

## Propósito
CRUD superadmin (`/platform/negocios`) para os Negócios (tenants/clientes) da plataforma: dados cadastrais, contatos, plano, InstitutionNames DICOM e (schema pronto, UI pendente) Unidades DICOM por endereço/AE Title.

## Arquivos principais
| Arquivo | Papel |
|---|---|
| `app/Controllers/Platform/NegociosController.php` | Todo o módulo — PDO direto, sem Service/Repository |
| `app/Views/platform/negocios/index.php` | Listagem de Negócios |
| `app/Views/platform/negocios/form.php` | Form único de create/edit, com abas (dados, contatos, plano, DICOM) |
| `app/Models/Tenant.php`, `app/Models/TenantPlan.php` | Acesso a `bi_tenants`/`bi_plans` |

## Dependências
- Depende de: `bi_tenants`, `bi_negocio_contatos`, `bi_negocio_institution_names`, `bi_tenant_unidades_dicom`, `bi_users`, `bi_plans`
- Consumido por: `ServidorPacsController::getInstitutionStats()` (lê `bi_negocio_institution_names`, ver `modules/servidor-pacs.md`); `EstudosRepository::getUnidades()` (lê `bi_tenant_unidades_dicom`)
- Ver `architecture/dependencias.md` para o grafo completo

## Botão "Acessar como este negócio" (impersonação)

O form de impersonate em `index.php` (`POST /platform/negocios/{id}/impersonate`) não é atendido por `NegociosController` — vai para `Platform\TenantsController::impersonate()`. Fluxo completo (escopo de sessão, banner obrigatório, auditoria, propagação do `TenantContext` para Worklist/Médicos/Unidades/etc.) documentado em `architecture/auth-e-permissoes.md`, não duplicado aqui.

## Padrões seguidos
Controller com PDO direto, mesmo padrão de `ServidorPacsController` — ver `modules/servidor-pacs.md`.

## InstitutionNames — duas tabelas convivendo (ler antes de mexer aqui)

1. **`bi_negocio_institution_names`** — ativa hoje. Populada pela aba "DICOM" do form (`app/Views/platform/negocios/form.php`, textarea `institution_names`, nomes separados por vírgula). `store()`/`update()` fazem `DELETE` + `INSERT IGNORE` a cada save (substitui a lista inteira, não faz merge incremental).
2. **`bi_tenant_unidades_dicom`** — criada em `2026-07-10_negocios_unidades_dicom.sql`, pensada para substituir a tabela acima por um grid CRUD com mais campos (endereço, AE Title, código interno, status). O comentário da própria migration diz "Substitui o textarea de institution_names por grid CRUD".

### Achado desta sessão (2026-07-10): CRUD de Unidades DICOM estava quebrado

As rotas abaixo já existiam em `routes/platform.php`, mas os métodos correspondentes **não existiam** em `NegociosController` — qualquer chamada resultava em erro fatal (`call_user_func_array` em método inexistente):

```
GET  /platform/negocios/{id}/unidades           → listarUnidades
POST /platform/negocios/{id}/unidades           → criarUnidade
GET  /platform/negocios/{id}/unidades/{uid}     → getUnidade
POST /platform/negocios/{id}/unidades/{uid}/update → atualizarUnidade
POST /platform/negocios/{id}/unidades/{uid}/delete → excluirUnidade
```

**Corrigido nesta sessão**: os 5 métodos foram implementados em `NegociosController`, todos:
- JSON via `$this->json()` (padrão do Controller base, já usado em `buscarCnpj`);
- tenant-scoped (`WHERE tenant_id = ?` em toda query, para evitar IDOR — um Negócio não pode ler/editar/apagar Unidade de outro);
- prepared statements em todas as queries.

**Não incluído nesta sessão** (fora do escopo autorizado): nenhuma aba/grid na view `form.php` chama essas rotas ainda — o CRUD funciona via API, mas não há UI. Construir essa UI é a próxima etapa natural, mas não foi pedida.

### Outras rotas quebradas encontradas (mesmo padrão, não corrigidas)

Durante a investigação foram encontradas mais duas rotas no mesmo arquivo apontando para métodos inexistentes em `NegociosController` — **fora do escopo desta tarefa, não corrigidas**:

- `POST /platform/negocios/{id}/logo` → `uploadLogo` (método ausente)
- `POST /platform/negocios/{id}/enviar-token` → `enviarTokenAcesso` (método ausente)

Ambas vieram do mesmo commit (`4a9f931 feat(negocios): implementa módulo completo de Unidades DICOM e Token de Acesso`) — parece um deploy incompleto (migration + rotas commitadas, controller não). Reportado ao usuário; decidir separadamente se/quando corrigir.

## Riscos / pontos frágeis conhecidos
- Duas tabelas de InstitutionNames convivendo sem uma ser oficialmente deprecada — ver seção acima. Qualquer nova feature que precise "o InstitutionName cadastrado no Negócio" deve confirmar qual das duas é a fonte esperada antes de assumir.
- `store()`/`update()` fazem `DELETE FROM bi_negocio_institution_names WHERE tenant_id = ?` seguido de re-insert — se o form for submetido vazio no campo `institution_names`, todos os nomes cadastrados do tenant são apagados silenciosamente.
- `uploadLogo` e `enviarTokenAcesso` quebrados (ver acima).
- **`app/Views/platform/negocios/index.php` já foi vítima 3x do mesmo bug de fetch mode** (500 "Cannot use object of type stdClass as array") — ver seção abaixo. Ao tocar neste arquivo, NUNCA usar `$n['campo']` ou `$n->x ?? $n['x']`; usar sempre acesso de objeto puro (`$n->campo ?? default`).

### Hotfix 2026-07-15: regressão do bug stdClass-como-array (3ª ocorrência)

**Causa raiz real**: não foi a Fase 2 (Regra de SLA) nem `App\Core\Model` — `NegociosController::index()` usa PDO puro (`Database::getInstance()->query(...)->fetchAll()`), sem passar por `App\Core\Model`. A hipótese de regressão via `App\Core\Model`/SLA foi verificada e descartada (`git log -- app/Core/Model.php` não mostra nenhum commit tocando fetch mode; a Fase 2/SLA não altera `App\Core\Model`'s comportamento de fetch).

O culpado real: o commit `727fc7f` ("feat: worklist estudos v3, OHIF viewer, ViewerToken, Platform controllers, migrations") **sobrescreveu por completo** `app/Views/platform/negocios/index.php`, revertendo uma correção que já tinha sido aplicada e validada nos commits `4f4b6b4`/`bb539f7` (sessão anterior). O código voltou ao padrão quebrado `$n->x ?? $n['x'] ?? ...`, que é fatal em PHP 8 quando `$n` é `stdClass` (não implementa `ArrayAccess`) — e `app/Core/Database.php:23` configura o PDO **globalmente** com `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ`, então `$n` é sempre objeto nesta query (não passa por `FETCH_ASSOC`). O `??` não protege contra esse erro porque ele só suprime "propriedade/chave ausente", não "tipo errado para o operador `[]`". Bônus: o típo `plan_nome` (view) vs `plano_nome` (alias real da query) também tinha voltado.

**Padrão de regressão**: isso já é a 3ª vez que esse exato bug aparece neste arquivo (`4f4b6b4`→corrigido, `727fc7f`→regrediu, `091fa2b`→corrigiu só `form.php`/`edit()` e não olhou `index.php`, agora 2026-07-15→corrigido de novo). Causa provável: commits de feature grandes/gerados que reescrevem o arquivo inteiro em vez de editar incrementalmente, apagando fixes anteriores. Ao mexer neste arquivo no futuro, confirmar que o diff é incremental, não uma reescrita completa do arquivo.

**Correção aplicada**: voltou ao padrão de acesso de objeto puro (igual ao fix validado em `bb539f7`) e corrigiu o typo `plan_nome` → `plano_nome`. Escopo mínimo — não tocou `App\Core\Model`, `form.php` nem nenhuma outra tela.

**Mesmo padrão encontrado em outros módulos, fora do escopo deste hotfix (não corrigido)**: `app/Views/platform/plans/index.php` e `app/Views/platform/plans/form.php` também têm `$n->x ?? $n['x']`. Mesma classe de bug, módulo diferente — avaliar separadamente.

## Última análise
2026-07-15
