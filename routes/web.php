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
Router::get('/estudos/{id}/abrir',     'EstudosController@abrir');
Router::get('/estudos/{id}/abrir-radiant', 'EstudosController@abrirRadiant');
Router::get('/estudos/{id}/abrir-weasis',  'EstudosController@abrirWeasis');
Router::get('/api/estudos/contadores', 'EstudosController@contadores');

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

Router::get('/unidades',               'UnidadesController@index');
Router::get('/unidades/create',        'UnidadesController@create');
Router::post('/unidades',              'UnidadesController@store');
Router::get('/unidades/{id}/edit',     'UnidadesController@edit');
Router::post('/unidades/{id}/update',  'UnidadesController@update');

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

// Carregar template (AJAX)
Router::get('/reports/template',           'ReportsController@template');

// Assumir estudo (botão worklist, AJAX POST)
Router::post('/reports/assumir',           'ReportsController@assumir');

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
