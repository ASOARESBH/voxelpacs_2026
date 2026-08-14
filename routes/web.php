<?php
use App\Core\Router;

// ============================================================
// VOXEL PACS — Rotas Públicas
// ============================================================
Router::get('/login',  'AuthController@showLogin');
Router::post('/login', 'AuthController@login');
Router::get('/logout', 'AuthController@logout');
Router::get('/selecionar-empresa',  'AuthController@selectTenant');
Router::post('/selecionar-empresa', 'AuthController@doSelectTenant');

// Raiz → worklist
Router::get('/', fn() => header('Location: /estudos'));

// ============================================================
// DASHBOARD (redireciona para /estudos)
// ============================================================
Router::get('/dashboard', 'DashboardController@index');

// ============================================================
// WORKLIST — Estudos PACS (página principal)
// ============================================================
Router::get('/estudos',                'EstudosController@index');
Router::get('/gestao-exames',           'EstudosController@gestao');
Router::get('/estudos/instalar',       'EstudosController@instalar');
Router::get('/estudos/{id}/abrir',     'EstudosController@abrir');
Router::get('/estudos/{id}/abrir-radiant',      'EstudosController@abrirRadiant');
Router::get('/estudos/{id}/abrir-weasis',       'EstudosController@abrirWeasis');
Router::get('/estudos/{id}/abrir-voxel',        'EstudosController@abrirVoxelDesktop');
Router::get('/api/estudos/contadores',  'EstudosController@contadores');
Router::post('/api/estudos/assumir',    'EstudosController@assumirEstudo');
Router::post('/api/estudos/laudo-url',  'EstudosController@obterUrlLaudoAssumido');
Router::get('/api/pacs/estudo-copilot-status', 'EstudosController@apiEstudoCopilotStatus');

// ============================================================
// GESTÃO DE EXAMES — Pedido médico privado por estudo e Gerenciar
// ============================================================
Router::get('/api/gestao-exames/estudos/{id}/gerenciar',        'GestaoExamesController@gerenciarContext');
Router::get('/api/gestao-exames/descricoes-por-modalidade',     'GestaoExamesController@descricoesPorModalidade');
Router::post('/api/gestao-exames/estudos/{id}/descricao',       'GestaoExamesController@alterarDescricao');
Router::post('/api/gestao-exames/estudos/{id}/descricao/previa-lote', 'GestaoExamesController@previaDescricaoLote');
Router::post('/api/gestao-exames/estudos/{id}/descricao/lote',  'GestaoExamesController@alterarDescricaoLote');
Router::post('/api/gestao-exames/estudos/{id}/prioridade',      'GestaoExamesController@alterarPrioridade');
Router::post('/api/gestao-exames/estudos/{id}/pedido',          'GestaoExamesController@anexar');
Router::post('/api/gestao-exames/estudos/{id}/pedido/remover',  'GestaoExamesController@remover');
Router::get('/api/gestao-exames/pedidos/{id}/arquivo',         'GestaoExamesController@arquivo');

// ============================================================
// API — Download em Lote (DICOM ZIP via Orthanc)
// ============================================================
Router::post('/api/download-lote/iniciar',           'DownloadLoteController@iniciar');
Router::get('/api/download-lote/status',             'DownloadLoteController@status');
Router::get('/api/download-lote/baixar',             'DownloadLoteController@baixar');
Router::get('/api/download-lote/baixar-inteligente', 'DownloadLoteController@baixarInteligente');
// ============================================================
// VOXEL Desktop — Atualização automática e download do instalador
// GET  /api/desktop/version  — consultado pelo app ao iniciar
// GET  /desktop/download     — redireciona para o instalador mais recente
// ============================================================
Router::get('/api/desktop/version', 'DesktopController@version');
Router::get('/desktop/download',    'DesktopController@download');

// ============================================================
// AGENDAMENTOS
// ============================================================
Router::get('/agendamentos', 'AgendamentosController@index');

// ============================================================
// PACS — Exames DICOM
// ============================================================
Router::get('/pacs/exames',            'ExamesPacsController@index');
Router::get('/pacs/exames/{id}',       'ExamesPacsController@show');
Router::get('/pacs/modalidades',       'ExamesPacsController@modalidades');
Router::get('/pacs',                   fn() => header('Location: /estudos'));

// ============================================================
// CADASTROS
// ============================================================
Router::get('/api/medicos/cep/{cep}',  'MedicosController@buscarCep');
Router::get('/medicos',                'MedicosController@index');
Router::get('/medicos/create',         'MedicosController@create');
Router::post('/medicos',               'MedicosController@store');
Router::get('/medicos/{id}/edit',      'MedicosController@edit');
Router::post('/medicos/{id}/update',   'MedicosController@update');
Router::post('/medicos/{id}/toggle',   'MedicosController@toggleStatus');
Router::post('/api/medicos/{id}/copilot-token',    'MedicosController@copilotToken');
Router::post('/api/medicos/{id}/workspace-laudo', 'MedicosController@toggleWorkspaceLaudo');
Router::post('/api/medicos/{id}/permissao-ver-medico-laudo', 'MedicosController@toggleVerMedicoLaudo');

// ── Templates / Máscaras de Laudo ──────────────────────────────────────────
Router::get('/api/medicos/{medicoId}/templates',           'TemplatesController@listar');
Router::post('/api/medicos/{medicoId}/templates',          'TemplatesController@salvar');
// Rotas específicas devem vir antes do alias /importar porque o Router usa first-match.
Router::post('/api/medicos/{medicoId}/templates/importar/analisar',  'TemplatesController@analisar');
Router::post('/api/medicos/{medicoId}/templates/importar/confirmar', 'TemplatesController@confirmar');
Router::post('/api/medicos/{medicoId}/templates/importar',           'TemplatesController@importar');
Router::post('/api/medicos/{medicoId}/templates/{id}/excluir', 'TemplatesController@excluir');
Router::get('/medicos/{medicoId}/mascaras/{mascaraId}/visualizar', 'TemplatesController@visualizar');
Router::get('/api/templates/buscar',                       'TemplatesController@buscar');
Router::get('/api/templates/auto',                         'TemplatesController@autoCarregar');

// ── Assinatura do Médico (aba "Assinatura" em /medicos/{id}/edit) ──────────
// Router só suporta get()/post() — nunca DELETE/PUT (ver diagnostics/pendencias-conhecidas.md).
Router::get('/medicos/{id}/assinatura/listar',          'MedicoAssinaturaController@listar');
Router::post('/medicos/{id}/assinatura/imagem/upload',  'MedicoAssinaturaController@uploadImagem');
Router::post('/medicos/{id}/assinatura/livre/salvar',   'MedicoAssinaturaController@salvarLivre');
Router::get('/medicos/{id}/assinatura/{tipo}/preview',  'MedicoAssinaturaController@preview');
Router::post('/medicos/{id}/assinatura/{tipo}/ativar',  'MedicoAssinaturaController@ativar');
Router::post('/medicos/{id}/assinatura/{tipo}/desativar','MedicoAssinaturaController@desativar');

Router::get('/unidades',               'UnidadesController@index');
Router::get('/unidades/create',        'UnidadesController@create');
Router::post('/unidades',              'UnidadesController@store');
Router::get('/unidades/{id}/edit',     'UnidadesController@edit');
Router::post('/unidades/{id}/update',  'UnidadesController@update');
Router::get('/api/unidades/cnpj',         'UnidadesController@apiCnpj');
Router::get('/api/unidades/listar',       'UnidadesController@apiListar');
Router::get('/api/unidades/info',         'UnidadesController@apiInfo');

// bi_unidades — entidade rica (CNPJ, endereço, logo)
Router::get('/unidades/nova',             'UnidadesController@novaUnidade');
Router::post('/unidades/nova',            'UnidadesController@criarUnidade');
Router::get('/unidades/{id}/editar',      'UnidadesController@editarUnidade');
Router::post('/unidades/{id}/editar',     'UnidadesController@atualizarUnidade');
Router::post('/unidades/{id}/excluir',    'UnidadesController@excluirUnidade');

Router::get('/modalidades',            'ModalidadesController@index');
Router::get('/modalidades/create',     'ModalidadesController@create');
Router::post('/modalidades',           'ModalidadesController@store');
Router::get('/modalidades/{id}/edit',  'ModalidadesController@edit');
Router::post('/modalidades/{id}/update','ModalidadesController@update');

Router::get('/sla-regras',                     'SlaRegrasController@index');
Router::get('/sla-regras/create',              'SlaRegrasController@create');
Router::post('/sla-regras',                    'SlaRegrasController@store');
Router::get('/sla-regras/execucoes',           'SlaRegrasController@execucoes');
Router::get('/sla-regras/robo',                'SlaRegrasController@roboConfig');
Router::post('/sla-regras/robo/gerar-token',   'SlaRegrasController@roboGerarToken');
Router::post('/sla-regras/robo/toggle',        'SlaRegrasController@roboToggle');
Router::get('/sla-regras/{id}/edit',           'SlaRegrasController@edit');
Router::post('/sla-regras/{id}/update',        'SlaRegrasController@update');
Router::post('/sla-regras/{id}/toggle',        'SlaRegrasController@toggleStatus');

// ============================================================
// RELATÓRIOS — somente leitura, camada própria (não usa EstudosController)
// ============================================================
Router::get('/relatorios/exames',              'RelatorioEstudosController@index');
Router::get('/relatorios/exames/exportar',     'RelatorioEstudosController@exportar');
Router::get('/relatorios/sla-medicos',         'RelatorioSlaController@index');
Router::get('/relatorios/sla-medicos/exportar','RelatorioSlaController@exportar');

// ============================================================
// SISTEMA
// ============================================================
Router::get('/usuarios',               'UsuariosController@index');
Router::get('/usuarios/create',        'UsuariosController@create');
Router::post('/usuarios',              'UsuariosController@store');
Router::get('/usuarios/{id}/edit',     'UsuariosController@edit');
Router::post('/usuarios/{id}/update',  'UsuariosController@update');
Router::post('/usuarios/{id}/toggle',        'UsuariosController@toggleStatus');
Router::post('/usuarios/{id}/reenviar-link', 'UsuariosController@reenviarLink');

// ── Grupos (Sistema > Usuários > Grupos) — Fase 1: CRUD + vínculo de usuários ──
Router::get('/usuarios/grupos',                                    'GruposController@index');
Router::get('/usuarios/grupos/novo',                                'GruposController@novo');
Router::post('/usuarios/grupos',                                    'GruposController@store');
Router::get('/usuarios/grupos/{id}/editar',                         'GruposController@editar');
Router::post('/usuarios/grupos/{id}/atualizar',                     'GruposController@atualizar');
Router::post('/usuarios/grupos/{id}/excluir',                       'GruposController@excluir');
Router::post('/usuarios/grupos/{id}/usuarios/adicionar',            'GruposController@adicionarUsuarios');
Router::post('/usuarios/grupos/{id}/usuarios/{usuario_id}/remover', 'GruposController@removerUsuario');

Router::get('/configuracoes',          'ConfiguracoesController@index');
Router::post('/configuracoes/salvar',  'ConfiguracoesController@salvar');
Router::post('/configuracoes/viewer-desktop/salvar', 'ConfiguracoesController@salvarViewerDesktop');

// ============================================================
// API — Orthanc ping (público, para status na tela de login)
// ============================================================
Router::get('/api/orthanc/ping', 'PacsController@pingPublic');

// ============================================================
// API — Robô de Regras de SLA (público, protegido por token via query
// string, chamado por cron externo — ver docs/SYNC_AUTOMATICO_PACS.md)
// ============================================================
Router::get('/api/sla-regras/executar', 'SlaRoboController@executar');

// ============================================================
// API — Robô de Sincronização automática do Servidor PACS (a cada 2 min,
// público, protegido por token via query string, chamado por cron externo —
// ver docs/PACS_MULTISERVIDOR_ROTEAMENTO.md)
// ============================================================
Router::get('/api/servidor-pacs/sync-robo', 'PacsSyncRoboController@executar');

// ============================================================
// REPORTS — Módulo de Laudos Médicos
// ============================================================
// Rotas estáticas vêm antes das rotas parametrizadas: o Router é first-match.
Router::get('/reports/history',            'ReportsController@history');
Router::post('/reports/history/restore',   'ReportsController@restoreHistory');
Router::get('/reports/r/{token}/pdf',         'ReportsController@pdfByToken');
Router::get('/reports/r/{token}/assinatura',  'ReportsController@assinaturaImagemByToken');
Router::get('/reports/templates',          'ReportsController@templates');
Router::get('/reports/template',           'ReportsController@template');
Router::get('/reports/autotext',           'ReportsController@autotextSearch');
Router::get('/api/reports/autotext',       'ReportsController@autotextSearch');
Router::post('/reports/ai-generate',       'ReportsController@aiGenerate');
Router::post('/reports/save',              'ReportsController@save');
Router::post('/reports/sign',              'ReportsController@sign');
Router::post('/reports/assumir',           'ReportsController@assumir');
Router::post('/api/reports/status',        'ReportsController@atualizarStatus');
Router::post('/api/reports/liberar',       'ReportsController@liberar');
Router::get('/api/reports/by-estudo',      'ReportsController@byEstudo');
// Medidas capturadas do VOXEL VIEW para seleção e inserção no laudário.
Router::get('/api/reports/measurements',        'ReportMeasurementsController@index');
Router::post('/api/reports/measurements/insert', 'ReportMeasurementsController@insert');
Router::get('/api/reports/chat',             'ReportChatController@context');
Router::post('/api/reports/chat/send',       'ReportChatController@send');
Router::post('/api/reports/chat/complete',   'ReportChatController@complete');
Router::get('/api/reports/peer-review/context',  'ReportPeerReviewController@context');
Router::post('/api/reports/peer-review/open',    'ReportPeerReviewController@open');
Router::get('/api/reports/peer-review/original', 'ReportPeerReviewController@original');

// Endpoint público CORS restrito: adapter do MeasurementService no VOXEL VIEW.
Router::options('/api/viewer/measurements', 'ViewerMeasurementsController@options');
Router::post('/api/viewer/measurements',    'ViewerMeasurementsController@ingest');

// Editor de laudo por token opaco. Study UID e id sequencial não são aceitos
// em URL pública para impedir enumeração e exposição de identificadores clínicos.
Router::get('/reports/r/{token}',            'ReportsController@showByToken');

// ============================================================
// VIEWER — Abertura segura de exames via token temporário
// Rota PÚBLICA: não exige autenticação
// Fluxo: /estudos/{id}/abrir → gera token → redireciona aqui
//        → ViewerTokenController resolve token → OHIF Viewer
// ============================================================
Router::get('/open/{token}', 'ViewerTokenController@abrir');

// ============================================================
// Fluxo de Criação de Senha via Token de Acesso (Etapa 4)
// ============================================================
Router::get('/acesso/criar-senha/{token}',  'Auth\AccessTokenController@formCriarSenha');
Router::post('/acesso/criar-senha/{token}', 'Auth\AccessTokenController@salvarSenha');

// ============================================================
// Esqueci minha senha — reaproveita bi_tenant_access_tokens e o fluxo
// /acesso/criar-senha/{token} acima (tipo='redefinir_senha')
// Rotas PÚBLICAS: não exigem autenticação
// ============================================================
Router::get('/esqueci-senha',  'Auth\AccessTokenController@formEsqueciSenha');
Router::post('/esqueci-senha', 'Auth\AccessTokenController@enviarLinkRedefinicao');

// ============================================================
// VOXEL Copilot — Webhooks recebidos do Copilot (público, protegido por Bearer token)
// ============================================================
Router::post('/api/copilot/webhook/laudo-finalizado', 'CopilotWebhookController@laudoFinalizado');
Router::post('/api/copilot/webhook/evento',           'CopilotWebhookController@evento');
