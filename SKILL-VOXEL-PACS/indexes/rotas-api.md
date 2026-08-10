# Índice de Rotas / API

> Preencha uma linha por rota (ou por grupo de rotas do mesmo recurso) assim que ela for tocada/analisada. Não tente preencher tudo de uma vez na primeira sessão — este índice cresce organicamente conforme o projeto é explorado.

| Método | Rota | Controller/Handler | Autenticação | Propósito | Última verificação |
|---|---|---|---|---|---|
| GET | `/platform/servidor-pacs` | `Platform\ServidorPacsController@index` | Platform admin | Dashboard: status Orthanc, roteamentos ativos, card "InstitutionNames no PACS" (união estudos ∪ `bi_negocio_institution_names`) | 2026-07-10 |
| GET | `/platform/servidor-pacs/configurar` | `Platform\ServidorPacsController@configurar` | Platform admin | Form de config do servidor Orthanc | 2026-07-10 |
| POST | `/platform/servidor-pacs/salvar-config` | `Platform\ServidorPacsController@salvarConfig` | Platform admin | Salva URL/credenciais do Orthanc | 2026-07-10 |
| POST | `/platform/servidor-pacs/testar` | `Platform\ServidorPacsController@testar` | Platform admin | Ping AJAX no Orthanc, retorna JSON | 2026-07-10 |
| POST | `/platform/servidor-pacs/sincronizar` | `Platform\ServidorPacsController@sincronizar` | Platform admin | Importa estudos do Orthanc → `bi_pacs_estudos`, roteia via `bi_pacs_roteamento` (não considera `bi_negocio_institution_names`) | 2026-07-10 |
| GET | `/platform/servidor-pacs/roteamento` | `Platform\ServidorPacsController@roteamento` | Platform admin | Tela de de-para InstitutionName → Negócio (`bi_pacs_roteamento`) | 2026-07-10 |
| POST | `/platform/servidor-pacs/roteamento/salvar` | `Platform\ServidorPacsController@salvarRoteamento` | Platform admin | Cria/atualiza roteamento; aplica retroativamente aos estudos já importados | 2026-07-10 |
| POST | `/platform/servidor-pacs/roteamento/{id}/remover` | `Platform\ServidorPacsController@removerRoteamento` | Platform admin | Remove roteamento | 2026-07-10 |
| GET | `/platform/servidor-pacs/estudos` | `Platform\ServidorPacsController@estudos` | Platform admin | Lista/filtra estudos importados | 2026-07-10 |
| GET | `/platform/negocios` | `Platform\NegociosController@index` | Platform admin | Lista de Negócios (tenants) | 2026-07-10 |
| GET/POST | `/platform/negocios/create`, `/platform/negocios` | `Platform\NegociosController@create/store` | Platform admin | Cria Negócio; aba DICOM grava `institution_names` (textarea) em `bi_negocio_institution_names` | 2026-07-10 |
| GET/POST | `/platform/negocios/{id}/edit`, `/platform/negocios/{id}/update` | `Platform\NegociosController@edit/update` | Platform admin | Edita Negócio; mesma aba DICOM acima | 2026-07-10 |
| GET | `/platform/negocios/{id}/unidades` | `Platform\NegociosController@listarUnidades` | Platform admin | Lista Unidades DICOM (`bi_tenant_unidades_dicom`) de um Negócio, JSON | 2026-07-10 — **método estava ausente no controller (rota quebrada); implementado nesta sessão** |
| POST | `/platform/negocios/{id}/unidades` | `Platform\NegociosController@criarUnidade` | Platform admin | Cria Unidade DICOM, JSON | idem acima |
| GET | `/platform/negocios/{id}/unidades/{uid}` | `Platform\NegociosController@getUnidade` | Platform admin | Detalhe de uma Unidade DICOM, JSON | idem acima |
| POST | `/platform/negocios/{id}/unidades/{uid}/update` | `Platform\NegociosController@atualizarUnidade` | Platform admin | Atualiza Unidade DICOM, JSON | idem acima |
| POST | `/platform/negocios/{id}/unidades/{uid}/delete` | `Platform\NegociosController@excluirUnidade` | Platform admin | Remove Unidade DICOM, JSON | idem acima |
| POST | `/platform/negocios/{id}/logo` | `Platform\NegociosController@uploadLogo` | Platform admin | **Rota quebrada — método não existe no controller.** Encontrado mas não corrigido nesta sessão (fora do escopo autorizado); confirmar com o usuário antes de mexer | 2026-07-10 |
| POST | `/platform/negocios/{id}/enviar-token` | `Platform\NegociosController@enviarTokenAcesso` | Platform admin | **Rota quebrada — método não existe no controller.** Mesma situação acima | 2026-07-10 |
| GET/POST | `/sla-regras[...]` (create/store/edit/update/toggle) | `SlaRegrasController` | Login + `Auth::can('manage_sla_regras')` (checagem manual no construtor do controller — não há guard de role automático fora de `/platform`) | CRUD de Regras de SLA | 2026-07-18 |
| GET | `/sla-regras/execucoes` | `SlaRegrasController@execucoes` | idem acima | Histórico de remanejamentos feitos pelo robô | 2026-07-18 |
| GET | `/sla-regras/robo` | `SlaRegrasController@roboConfig` | idem acima | Tela de config do robô: URL pública, token, ativo/inativo, status do lock | 2026-07-18 |
| POST | `/sla-regras/robo/gerar-token` | `SlaRegrasController@roboGerarToken` | idem acima | Gera novo token (`bin2hex(random_bytes(24))`), invalida o anterior | 2026-07-18 |
| POST | `/sla-regras/robo/toggle` | `SlaRegrasController@roboToggle` | idem acima | Liga/desliga o robô | 2026-07-18 |
| GET | `/api/sla-regras/executar` | `SlaRoboController@executar` | **Pública** (token via query string, `hash_equals()`) — precisa estar em `App\Core\Router::$publicRoutes` **e** `public/index.php::$rotasPublicas` (duas listas independentes; achado replicado do caso de `/api/orthanc/ping`) | Dispara `SlaRulesEngineService::executarParaTodosTenants()`; chamada por cron externo (ex: cron-job.org), já que o hosting não tem crontab real | 2026-07-18 |
| GET | `/api/medicos/cep/{cep}` | `MedicosController@buscarCep` | Login normal (rota autenticada, não pública) | Busca endereço por CEP via ViaCEP (`https://viacep.com.br/ws/{cep}/json/`), usada por `fetch()` no form de Médicos para autopreencher logradouro/bairro/cidade/estado; primeira integração de CEP do projeto (mesmo padrão estrutural de `Platform\NegociosController::buscarCnpj()`, mas sem `CURLOPT_SSL_VERIFYPEER => false`) | 2026-07-19 |
| GET/POST | `/usuarios/grupos[...]` (index/novo/store/editar/atualizar/excluir) | `GruposController` | Login normal, escopado por `TenantContext::id()` | CRUD de Grupos (Fase 1 — ver `modules/grupos.md`); `excluir` é toggle de `ativo` (soft delete), não DELETE físico | 2026-08-10 |
| POST | `/usuarios/grupos/{id}/usuarios/adicionar` | `GruposController@adicionarUsuarios` | idem acima | Vincula 1+ usuários ao grupo (`usuario_ids[]`), guard `usuarioPertenceAoTenant()` contra IDOR | 2026-08-10 |
| POST | `/usuarios/grupos/{id}/usuarios/{usuario_id}/remover` | `GruposController@removerUsuario` | idem acima | Desvincula um usuário do grupo | 2026-08-10 |

## Convenções observadas (preencher conforme confirmado no código)

- Prefixo de versionamento de API: nenhum — não há versionamento de rotas (`/platform/...` direto, sem `/v1/`)
- Padrão de resposta de erro: endpoints AJAX retornam JSON `{"success": bool, "message": string}`; `Controller::json()` é o helper padrão (`http_response_code` + `json_encode` com `JSON_UNESCAPED_UNICODE`). Alguns endpoints mais antigos (`ServidorPacsController`) fazem `header()`/`echo json_encode()` manual em vez de usar `$this->json()` — inconsistência histórica, não um padrão a copiar
- Middleware de autenticação padrão: não há middleware por rota — `App\Core\Router::dispatch()` checa `Auth::check()` globalmente (exceto rotas em `$publicRoutes`)
- Middleware de permissão/ACL padrão: idem — `Router::dispatch()` bloqueia qualquer URI com prefixo `/platform` para quem não é `Auth::isPlatformAdmin()`, antes mesmo de rotear
