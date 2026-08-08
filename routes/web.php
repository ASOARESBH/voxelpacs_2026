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
Router::get('/api/pacs/estudo-copilot-status', 'EstudosController@apiEstudoCopilotStatus');

// ============================================================
// GESTÃO DE EXAMES — Pedido médico privado por estudo
// ============================================================
Router::post('/api/gestao-exames/estudos/{id}/pedido',         'GestaoExamesController@anexar');
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
Router::post('/api/medicos/{medicoId}/templates/importar', 'TemplatesController@importar');
Router::post('/api/medicos/{medicoId}/templates/{id}/excluir', 'TemplatesController@excluir');
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
// Editor de laudo (GET /reports/{study_uid})
Router::get('/reports/{study_uid}',        'ReportsController@show');

// Salvar rascunho (autosave ou manual)
Router::post('/reports/save',              'ReportsController@save');

// Assinar laudo com senha
Router::post('/reports/sign',              'ReportsController@sign');

// Histórico de versões (AJAX)
Router::get('/reports/history',            'ReportsController@history');

// Visualizar / baixar PDF
Router::get('/reports/pdf',                'ReportsController@pdf');
Router::get('/reports/assinatura-imagem',  'ReportsController@assinaturaImagem');

// Carregar template (AJAX)
Router::get('/reports/template',           'ReportsController@template');

// Assumir estudo (botão worklist, AJAX POST)
Router::post('/reports/assumir',           'ReportsController@assumir');

// Atualizar status do laudo (em_laudo, rascunho) — chamado ao abrir/fechar
Router::post('/api/reports/status',        'ReportsController@atualizarStatus');

// Liberar laudo — assina + fecha (botão Liberar)
Router::post('/api/reports/liberar',       'ReportsController@liberar');

// Buscar autotextos (AJAX)
Router::get('/api/reports/autotext',       'ReportsController@autotextSearch');

// Buscar report_id por estudo_id (usado pelo botão PDF na worklist)
Router::get('/api/reports/by-estudo',      'ReportsController@byEstudo');

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
