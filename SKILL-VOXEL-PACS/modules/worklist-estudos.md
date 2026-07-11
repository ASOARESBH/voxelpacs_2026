# Módulo — Worklist de Estudos

## Propósito
Tela principal do sistema (`/estudos`) — lista, busca, filtra e abre (via OHIF Viewer) os estudos DICOM importados do Orthanc. É a tela padrão de trabalho do usuário final (médico/técnico), diferente do `servidor-pacs` (só superadmin).

## Arquivos principais
| Arquivo | Papel |
|---|---|
| `app/Controllers/EstudosController.php` | Todo o módulo — PDO direto (filtros, paginação, contadores, painel de resumo, abertura no viewer). **Não usa Service/Repository** apesar de existirem `EstudosService`/`EstudosRepository` no projeto — esses são usados por `ReportsController` (módulo de laudos), uma implementação paralela/duplicada de consulta de estudos, não a mesma camada |
| `app/Views/estudos/index.php` | Tabela do worklist — filtros, badges de situação/prioridade/modalidade, paginação |
| `app/Services/OrthancService.php` | Fonte dos dados — `importAllStudies()`/`normalizeStudy()` traduzem o JSON do Orthanc para as colunas de `bi_pacs_estudos` |

## Dependências
- Depende de: `bi_pacs_estudos` (fonte de tudo que a tela mostra), `App\Core\Auth` (escopo por tenant)
- Consumido por: nenhum outro módulo depende do `EstudosController` diretamente
- Ver `architecture/dependencias.md` para o grafo completo

## Padrões seguidos
Controller com PDO direto — mesmo padrão de `ServidorPacsController`/`NegociosController` (ver `patterns/padrao-controller.md`).

## Coluna "M" (Modalidade) — bug corrigido em 2026-07-11

**Sintoma**: coluna "M" da tabela (`app/Views/estudos/index.php:244,311-314`) sempre mostrava "—", para todo estudo, em qualquer instalação.

**Causa raiz**: não era bug de exibição. A SELECT do `EstudosController` já buscava `e.modalities`, e a view já sabia dividir/renderizar múltiplas modalidades (`explode('\\', $e['modalities'])` + um badge por modalidade, sem necessidade de indicador "+N" — já cobria estudo multi-modalidade). O problema estava na **origem do dado**: `OrthancService::normalizeStudy()` lia `MainDicomTags['ModalitiesInStudy']` do `GET /studies/{id}` do Orthanc — campo que **nunca existe ali**, porque `Modality (0008,0060)` é atributo de **Series**, não de Study, e `ModalitiesInStudy` é um tag computado (só via `?requestedTags`, recurso do Orthanc ≥ 1.11.0, não assumível em toda instalação). Resultado: `modalities` sempre `NULL` em `bi_pacs_estudos`, para 100% dos estudos, desde sempre.

**Correção**: `OrthancService::fetchModalitiesInStudy(string $studyId)` (novo método privado) faz `GET /studies/{id}/series` — que já retorna cada Series expandida com `MainDicomTags.Modality` — e agrega os valores distintos, na ordem em que aparecem, unidos por `\` (mesmo separador da tag DICOM `ModalitiesInStudy` e já usado pela view). Chamado uma vez por estudo dentro de `importAllStudies()`, resultado passado para `normalizeStudy($study, $modalities)`.

Ver critério de agregação multi-modalidade em `memory/regras-de-negocio.md`.

## Riscos / pontos frágeis conhecidos
- **Custo de sync**: a correção acima dobra o número de requisições HTTP ao Orthanc durante `ServidorPacsController::sincronizar()` (1 requisição extra por estudo, `GET /studies/{id}/series`). Só ocorre na ação manual "Sincronizar Estudos" (admin), não afeta o carregamento de `/estudos` (que continua sendo 1 SELECT). Para volumes muito grandes de estudos, isso pode alongar o tempo de sync — `sincronizar()` já tem `set_time_limit(300)`, mas não há retry/backoff se o Orthanc responder lento nessa chamada extra.
- Não validado contra um Orthanc real neste ambiente (sem acesso de rede) — implementação seguida estritamente da documentação oficial do Orthanc (REST API book + cheat sheet). Validar com "Sincronizar Estudos" + inspeção visual da coluna "M" após deploy.
- `bi_pacs_estudos` só é corrigida retroativamente para estudos já importados no **próximo clique manual** de "Sincronizar Estudos" (o UPDATE dinâmico já reescreve todas as colunas, incluindo `modalities`, para estudos existentes) — não há backfill automático/migration.
- Lista de modalidades dos filtros do topo (`CR, CT, CTG, DO...`) é hardcoded em `app/Views/estudos/index.php:188`, não vem do banco nem de `bi_pacs_estudos.modalities` — pode ficar desatualizada se o Orthanc trouxer uma modalidade fora dessa lista (o filtro simplesmente não teria botão para ela, mas a coluna "M" mostraria normalmente).

## Última análise
2026-07-11
