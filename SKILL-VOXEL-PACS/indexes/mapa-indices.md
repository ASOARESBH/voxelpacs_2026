# Mapa de Índices — "Onde fica X"

> **Como usar este arquivo:** é a primeira parada para qualquer tarefa de localização. Cada linha aponta para o caminho real no repositório. Se uma linha estiver `[A preencher]`, isso significa que ninguém documentou esse caminho ainda — faça a busca dirigida uma vez, encontre, **e preencha a linha** antes de seguir. Isso é o que torna a próxima consulta instantânea.

> **Regra de ouro:** se você está prestes a rodar `find` ou `ls -R` na raiz do projeto, pare — isso quase sempre significa que deveria estar consultando (ou preenchendo) este arquivo.

## Índice geral

| O que procuro | Onde fica (caminho real) | Observações |
|---|---|---|
| Telas / Views | `app/Views/` (ex: `app/Views/platform/servidor_pacs/*.php`, `app/Views/platform/negocios/*.php`) | Templates PHP puro, renderizados por `App\Core\View::render()` a partir de `$this->view()` no Controller |
| Controllers | `app/Controllers/` e `app/Controllers/Platform/` (área superadmin) | Não há camada Service/Repository obrigatória — a maioria faz PDO direto, incluindo `EstudosController` (worklist `/estudos`, PDO 100% inline, ver `modules/worklist-estudos.md`). **Correção 2026-07-11**: `ReportsController` é quem usa `App\Services\EstudosService` + `App\Repositories\EstudosRepository` — não o `EstudosController`, ao contrário do que este índice dizia antes. Os dois implementam consultas de estudos em paralelo/duplicadas, não a mesma camada. `MedicosController` (`/medicos`) **é** a implementação de referência Controller→Service→Repository (fina, sem SQL) — ver `modules/medicos.md`. **Atenção a nomes parecidos**: `RelatorioEstudosController`/`RelatorioSlaController` (`/relatorios/*`, módulo novo) vs. `RelatoriosController`/`ExamesController` (código morto do legado "VOXEL B.I", sem rota, schema `bi_exames` diferente) — ver `modules/relatorios.md` |
| Services | `app/Services/` (ex: `OrthancService.php`, `EstudosService.php`, `MedicoService.php`) | Só existe onde a lógica é complexa o bastante para justificar; nem todo controller tem Service. `EstudosService` é usado por `ReportsController`, não pelo worklist principal |
| Repositories | `app/Repositories/` (ex: `EstudosRepository.php`) | Usado por `ReportsController`. Ver `EstudosRepository::getUnidades()` — já implementa o padrão de união Estudos ∪ Negócios usado como referência na tarefa de `servidor-pacs` |
| Models / Entities | `app/Models/` (ex: `Tenant.php`, `TenantPlan.php`, `User.php`) | Ativos simples de acesso a `bi_tenants`/afins, não são um ORM completo |
| Rotas de API | `routes/platform.php` (área `/platform/*`, superadmin) e demais arquivos em `routes/` | Ver `indexes/rotas-api.md` |
| Migrations | `database/migrations/*.sql` | Nomeadas `YYYY-MM-DD_descricao.sql`; várias usam procedure `vp_add_col` para `ALTER TABLE` idempotente (MySQL 5.7/MariaDB, sem suporte nativo a `ADD COLUMN IF NOT EXISTS`) |
| Queries SQL manuais / Views de banco | Direto nos Controllers/Repositories via PDO (`Database::getInstance()`), sempre prepared statements | Não há Query Builder/ORM — ver `patterns/padrao-sql.md` |
| Componentes de frontend | `public/assets/js/`, views usam Bootstrap + jQuery/fetch simples | |
| Layouts / Templates de página | `app/Views/layout/` (ex: `platform_header.php`) | Layout `'platform'` é passado como 3º arg de `$this->view()` |
| Permissões / ACL / Roles | `App\Core\Router::dispatch()` — guarda `/platform/*` com `Auth::isPlatformAdmin()`; demais rotas exigem `Auth::check()` | Ver `architecture/auth-e-permissoes.md` |
| Integrações externas (RIS, HIS, Orthanc, HL7) | `app/Services/OrthancService.php` (Orthanc REST) | HL7 não confirmado ainda — não localizado nesta sessão |
| Constantes / Enums | `app/Config/` (ex: `SlaConfig.php`) | |
| Helpers / Utilitários | `[A preencher]` | |
| Middlewares | Não há camada de middleware separada — checagens ficam em `App\Core\Router::dispatch()` | N/A — não existe no projeto como camada própria |
| Eventos / Listeners | `[A preencher]` | Nada encontrado nesta sessão — projeto parece não ter barramento de eventos |
| Filas (queue definitions) | `[A preencher]` | N/A aparente — sincronização Orthanc é síncrona via HTTP request (`ServidorPacsController::sincronizar()`), não fila assíncrona |
| Workers / Jobs assíncronos | N/A — não existe no projeto (sync é request-driven, ver `sincronizar()`) | |
| Configuração (env, config files) | `.env` (ver `.env.example`), `app/Config/` | |
| Autenticação (login, JWT, OAuth) | `App\Core\Auth` (usado em `Router::dispatch()`) | Detalhe completo não analisado nesta sessão |
| Upload de arquivos / DICOM ingest | Ingest via `OrthancService::importAllStudies()`, chamado por `ServidorPacsController::sincronizar()` | Grava em `bi_pacs_estudos` |
| Logs / Auditoria | `error_log()` direto (prefixo `[PACS]`, `[NegociosController::...]`) + `App\Core\Logger` (erros não tratados no Router) | Não há tabela de auditoria dedicada confirmada |
| Assets estáticos (imagens, fontes, ícones) | `public/assets/` | |
| Testes automatizados | `[A preencher]` | Nenhum diretório de testes localizado nesta sessão |
| Viewer DICOM / integração OHIF | `app/Views/estudos/viewer.php` (mencionado no grep de `institution_name`) | Não aprofundado nesta sessão |
| Integração Orthanc (REST/DICOMweb/plugins) | `app/Services/OrthancService.php` | REST API do Orthanc; consumido por `ServidorPacsController` |
| Parsers/handlers HL7 (ADT, ORM, ORU) | `[A preencher]` | Não localizado nesta sessão |
| Bibliotecas de terceiros relevantes | Bootstrap + Font Awesome (frontend), sem framework PHP (arquitetura própria em `App\Core\*`) | |

## Como preencher este índice corretamente

1. Ao localizar algo pela primeira vez, anote o **caminho real** (não um padrão genérico tipo "em algum lugar em app/").
2. Se a categoria tiver múltiplos locais (ex: Services divididos por domínio), liste os subcaminhos principais em vez de um só, ou aponte para um arquivo dedicado em `modules/`.
3. Se a categoria não existir no projeto (ex: não há fila), marque como `N/A — não existe no projeto` em vez de deixar `[A preencher]` — isso evita que alguém procure de novo achando que falta preencher.
4. Links cruzados: quando o caminho for grande/complexo o suficiente para merecer explicação própria, aponte aqui para `modules/<nome>.md` ou `architecture/<arquivo>.md` em vez de tentar caber tudo nesta tabela.

## Índices especializados

Para domínios com muitos subitens, use arquivos dedicados em vez de inchar esta tabela:

- `indexes/rotas-api.md` — lista completa de rotas/endpoints com método, controller e propósito.
- `indexes/tabelas-banco.md` — lista de tabelas com propósito e model/entity correspondente.
- `indexes/eventos-filas.md` — mapa completo de eventos disparados, quem escuta, e quais filas processam o quê.
