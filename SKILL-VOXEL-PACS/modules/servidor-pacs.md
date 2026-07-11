# Módulo — Servidor PACS

## Propósito
Tela superadmin (`/platform/servidor-pacs`) para configurar o Orthanc global, sincronizar estudos DICOM, rotear InstitutionName → Negócio, e listar estudos importados.

## Arquivos principais
| Arquivo | Papel |
|---|---|
| `app/Controllers/Platform/ServidorPacsController.php` | Todo o módulo — sem Service/Repository dedicado, PDO direto |
| `app/Views/platform/servidor_pacs/index.php` | Dashboard — status Orthanc, "Roteamentos Ativos", card "InstitutionNames no PACS" |
| `app/Views/platform/servidor_pacs/roteamento.php` | Tela de gestão do de-para InstitutionName → Negócio (`bi_pacs_roteamento`) |
| `app/Views/platform/servidor_pacs/estudos.php` | Lista/filtro de estudos importados |
| `app/Views/platform/servidor_pacs/configurar.php` | Form de config do servidor Orthanc |
| `app/Services/OrthancService.php` | Cliente REST do Orthanc (ping, stats, `importAllStudies`) |

## Dependências
- Depende de: `bi_pacs_servidor`, `bi_pacs_estudos`, `bi_pacs_roteamento`, `bi_pacs_sync_log`, `bi_tenants`, `bi_negocio_institution_names` (novo, desde 2026-07-10), `OrthancService`
- Consumido por: nenhum outro módulo lê diretamente este controller — é uma tela terminal (superadmin)
- Ver `architecture/dependencias.md` para o grafo completo

## Padrões seguidos
Controller com PDO direto (sem Service/Repository) — é o padrão real observado nos controllers de `Platform/`, mesmo que `patterns/padrao-controller.md` (aspiracional) recomende Controller → Service → Repository. `EstudosController`/`EstudosService`/`EstudosRepository` são a exceção que segue o padrão em camadas — não seguido aqui para manter consistência com o restante do arquivo (`ServidorPacsController` já tinha ~700 linhas de PDO direto antes desta mudança).

## Card "InstitutionNames no PACS" (alterado em 2026-07-10)

Antes: `$institutionStats` vinha só de `GROUP BY institution_name` em `bi_pacs_estudos` — só aparecia depois de clicar "Sincronizar Estudos".

Agora: `ServidorPacsController::getInstitutionStats()` faz a união de:
- (a) `bi_pacs_estudos` (servidor_id=1) — nomes que já têm estudo sincronizado, com contagem/roteamento real
- (b) `bi_negocio_institution_names` (ativo=1) — nomes cadastrados na aba "DICOM" do formulário de Negócio, mesmo que ainda não tenham nenhum estudo

Deduplicação por `strtolower(trim(institution_name))` — mesmo critério já usado em `sincronizar()` para casar `institution_name` com `bi_pacs_roteamento`. Quando o mesmo nome existe nas duas fontes, o valor exibido é o que veio do Orthanc (fonte de verdade da tag DICOM real); o nome do Negócio só complementa a coluna "Negócio" da tabela.

Cada linha carrega `tem_estudo` (bool). A view usa isso para diferenciar visualmente:
- **Sem estudo ainda** (badge cinza, `bi_negocio_institution_names` sem nenhum estudo importado)
- **Roteado** (check verde, tem estudo e `tenant_id` preenchido)
- **Não roteado** (X vermelho, tem estudo mas sem `tenant_id`)

### Por que `bi_negocio_institution_names` e não `bi_tenant_unidades_dicom`

Existem hoje **duas** tabelas candidatas para "institution name cadastrado em Negócios" (ver `indexes/tabelas-banco.md`):
- `bi_negocio_institution_names` — ativa, usada pela aba DICOM do form de Negócio.
- `bi_tenant_unidades_dicom` — mais nova/rica, mas sem UI até esta sessão (CRUD implementado, ver `modules/negocios.md`), e é a fonte usada por `EstudosRepository::getUnidades()` (filtro do worklist `/estudos`).

Decisão explícita do usuário (2026-07-10): usar `bi_negocio_institution_names` neste card, por ser a fonte que o usuário consegue popular pela UI hoje. **Consequência**: o card "InstitutionNames no PACS" e o filtro de unidade do worklist (`/estudos`) hoje leem fontes "Negócios" diferentes. Se/quando `bi_tenant_unidades_dicom` ganhar uma UI própria e virar a fonte oficial, `getInstitutionStats()` precisa ser atualizado para usar a nova tabela (ou unir as duas) — registrar isso na próxima tarefa que tocar aqui.

## Riscos / pontos frágeis conhecidos
- `sincronizar()` só roteia automaticamente via `bi_pacs_roteamento` — não considera `bi_negocio_institution_names` nem `bi_tenant_unidades_dicom`. Cadastrar um nome em Negócios não roteia estudos automaticamente na próxima sincronização.
- Case/acentuação: nomes cadastrados manualmente em Negócios podem não bater 100% com a tag DICOM real do equipamento. O merge é case-insensitive via `strtolower(trim())`, mas não normaliza acentos — dois nomes que só diferem em acento aparecem como linhas separadas.
- `bi_institution_name_pendentes` (fila de nomes sem vínculo) existe no schema mas não é usada em nenhum código PHP — feature não implementada.

## Última análise
2026-07-10
