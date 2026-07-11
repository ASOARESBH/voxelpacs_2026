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

## Última análise
2026-07-10
