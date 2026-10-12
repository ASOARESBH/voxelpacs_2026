# Mapa de Índices — "Onde fica X"

> **Como usar este arquivo:** é a primeira parada para qualquer tarefa de localização. Cada linha aponta para o caminho real no repositório. Se uma linha estiver `[A preencher]`, isso significa que ninguém documentou esse caminho ainda — faça a busca dirigida uma vez, encontre, **e preencha a linha** antes de seguir. Isso é o que torna a próxima consulta instantânea.

> **Regra de ouro:** se você está prestes a rodar `find` ou `ls -R` na raiz do projeto, pare — isso quase sempre significa que deveria estar consultando (ou preenchendo) este arquivo.

## Índice geral

| O que procuro | Onde fica (caminho real) | Observações |
|---|---|---|
| Telas / Views | `app/Views/` (ex: `app/Views/platform/servidor_pacs/*.php`, `app/Views/platform/negocios/*.php`) | Templates PHP puro, renderizados por `App\Core\View::render()` a partir de `$this->view()` no Controller |
| Controllers | `app/Controllers/` e `app/Controllers/Platform/` (área superadmin) | Não há camada Service/Repository obrigatória — a maioria faz PDO direto, incluindo `EstudosController` (worklist `/estudos`, PDO 100% inline, ver `modules/worklist-estudos.md`) e `UsuariosController` (`/usuarios`, cadastro de usuários). **Correção 2026-07-11**: `ReportsController` é quem usa `App\Services\EstudosService` + `App\Repositories\EstudosRepository` — não o `EstudosController`, ao contrário do que este índice dizia antes. Os dois implementam consultas de estudos em paralelo/duplicadas, não a mesma camada. `MedicosController` (`/medicos`) **é** a implementação de referência Controller→Service→Repository (fina, sem SQL) — ver `modules/medicos.md`. `GruposController` (`/usuarios/grupos`, aba "Grupos" dentro de Usuários) segue o mesmo padrão de `MedicosController` — ver `modules/grupos.md`. **Atenção a nomes parecidos**: `RelatorioEstudosController`/`RelatorioSlaController` (`/relatorios/*`, módulo novo) vs. `RelatoriosController`/`ExamesController` (código morto do legado "VOXEL B.I", sem rota, schema `bi_exames` diferente) — ver `modules/relatorios.md`. `ReportsController` (`/reports/{studyUid}`, editor de laudo) usa Service/Repository (`ReportService`/`ReportRepository`) — ver `modules/reports.md`. `UnidadesController` (`/unidades`, entidade rica `bi_unidades`) — ver `modules/unidades.md` |
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
| Configuração (env, config files) | `.env` (ver `.env.example`), `app/Config/ReportDeliveryRuntimeConfig.php`, `scripts/configure-report-delivery-runtime.sh`, `bin/philips_nondicom_production_diagnostic.php` | Flags do Report Delivery são lidas centralmente; o diagnóstico Philips Non-DICOM é somente leitura, fail-closed e não reinicia serviços |
| Backup/restore de release | `scripts/release_backup.sh`, `scripts/provision-release-backup.sh`, `ops/sudoers/voxelpacs-release-backup`, `ops/deploy/README_ROOT_ONLY_RELEASE_BACKUP.md` | Mecanismo root-only independente do deploy; SHA explícito, paths fixos, manifest/checksum e restore-test isolado |
| Baseline inicial de produção | `scripts/capture-production-baseline.sh`, `docs/PRODUCTION_BASELINE_ADOPTION_2026-10-10.md`, `tests/deploy/production_baseline_capture_static.php`, `tests/deploy/production_baseline_capture_isolated.sh` | Captura read-only, sem segredos/persistência/dados clínicos; produz também `composer-profile.tsv` sanitizado e usa `--require-complete` para falhar fechado diante de permissões insuficientes |
| Provisionamento root-controlled do baseline | `scripts/provision-production-baseline-capture.sh`, `ops/deploy/voxelpacs-production-baseline-capture`, `ops/sudoers/voxelpacs-production-baseline-capture`, `docs/technical/PRODUCTION_BASELINE_CAPTURE.md`, `tests/deploy/production_baseline_provisioner_static.sh`, `tests/deploy/production_baseline_provisioner_upgrade_isolated.sh` | Instalação no-replace e upgrade controlado; o upgrade exige proveniência histórica dos três blobs, owner/mode seguros, staging no filesystem de destino e rollback de falha parcial; allowlist fixa para somente `capture`; não altera aplicação, banco, Worker, Bridge ou SMB |
| Validação versionada do drift do runtime | `scripts/validate-runtime-drift.sh`, `ops/deploy/voxelpacs-runtime-drift-manifest-reader`, `ops/deploy/voxelpacs-runtime-drift-gate`, `scripts/provision-runtime-drift-manifest-reader.sh`, `ops/sudoers/voxelpacs-runtime-drift-manifest-reader`, `docs/technical/RUNTIME_DRIFT_VALIDATION.md`, `docs/technical/RUNTIME_DRIFT_MANIFEST_READER.md`, `tests/deploy/runtime_drift_manifest_reader_static.sh`, `tests/deploy/runtime_drift_manifest_reader_isolated.sh` | Comparação read-only entre artefato, runtime e baseline; o leitor root-controlled valida o manifesto protegido e o gate integra a saída ao comparador; aceita somente extras baseline-matched e metadata Composer explicitamente classificado |
| Auditoria root-controlled de blob de runtime | `ops/deploy/voxelpacs-runtime-blob-auditor`, `scripts/provision-runtime-blob-auditor.sh`, `ops/sudoers/voxelpacs-runtime-blob-auditor`, `docs/technical/RUNTIME_BLOB_AUDITOR.md` | Somente leitura; alvo fixo `bin/philips_nondicom_production_diagnostic.php`; compara runtime com backup root-only e falha fechado em divergência |
| Auditoria root-controlled do metadata Composer | `ops/deploy/voxelpacs-composer-drift-auditor`, `scripts/provision-composer-drift-auditor.sh`, `ops/sudoers/voxelpacs-composer-drift-auditor`, `docs/technical/COMPOSER_DRIFT_AUDITOR.md`, `tests/deploy/composer_drift_auditor_static.sh`, `tests/deploy/composer_drift_auditor_isolated.sh` | Somente leitura; alvo fixo `vendor/composer/installed.php`; compara artefato, runtime e baseline sem expor hashes de conteúdo; não altera produção |
| Auditoria root-controlled do perfil Composer | `ops/deploy/voxelpacs-composer-metadata-profile-auditor`, `scripts/build-composer-metadata-profile.sh`, `scripts/provision-composer-metadata-profile-auditor.sh`, `ops/sudoers/voxelpacs-composer-metadata-profile-auditor`, `docs/technical/COMPOSER_METADATA_PROFILE_AUDITOR.md`, `tests/deploy/composer_metadata_profile_auditor_static.sh`, `tests/deploy/composer_metadata_profile_auditor_isolated.sh`, `tests/deploy/composer_metadata_profile_provisioner_isolated.sh` | Somente leitura no auditor; parser fail-closed para referências ambíguas e pacotes duplicados; provisioner exige remoto canônico/origin-main, aprovação root-only independente, diretórios seguros e instalação isolada sem sobrescrita; baseline sem sidecar verificável permanece `INCOMPLETE`; não altera drift gate ou produção |
| Aprovação root-controlled do perfil Composer | `scripts/provision-composer-profile-approval.sh`, `docs/technical/COMPOSER_PROFILE_APPROVAL.md`, `tests/deploy/composer_profile_approval_static.sh`, `tests/deploy/composer_profile_approval_isolated.sh` | Interface fixa `inspect`/`dry-run`/`apply` para `/etc/voxelpacs/voxelpacs-composer-profile-approved-main-sha`; prova checkout limpo, `origin/main` e remoto canônico; não sobrescreve âncora existente, reconcilia GID/mode somente no `apply` e não executa captura ou drift |
| Helper root-controlled da flag SMB read-only Non-DICOM | `ops/deploy/voxelpacs-philips-nondicom-smb-readonly-flag`, `scripts/provision-philips-nondicom-smb-readonly-flag.sh`, `ops/sudoers/voxelpacs-philips-nondicom-smb-readonly-flag`, `docs/technical/PHILIPS_NON_DICOM_SMB_READONLY_FLAG_HELPER.md` | Escopo fixo na única flag de teste; backup root-only, dry-run, apply, status e rollback; não acessa Worker, Bridge, SMB, banco ou DICOM |
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
