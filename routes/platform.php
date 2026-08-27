<?php
use App\Core\Router;

// ============================================================
// VOXEL PACS — Rotas da Plataforma (Superadmin)
// ============================================================
Router::get('/platform/dashboard', 'Platform\PlatformDashboardController@index');

// ============================================================
// Configuração global de módulos (somente superadmin)
// ============================================================
Router::get('/platform/configuracao-modulos',                 'Platform\ModuloConfiguracoesController@index');
Router::post('/platform/configuracao-modulos/salvar',         'Platform\ModuloConfiguracoesController@salvarGlobal');
Router::post('/platform/configuracao-modulos/estudos/salvar', 'Platform\ModuloConfiguracoesController@salvarEstudos');

// ============================================================
// Negócios (Multi-Tenant)
// ============================================================
Router::get('/platform/negocios',                       'Platform\NegociosController@index');
Router::get('/platform/negocios/create',                'Platform\NegociosController@create');
Router::post('/platform/negocios',                      'Platform\NegociosController@store');
Router::get('/platform/negocios/{id}/edit',             'Platform\NegociosController@edit');
Router::post('/platform/negocios/{id}/update',          'Platform\NegociosController@update');
Router::post('/platform/negocios/{id}/suspend',         'Platform\TenantsController@suspend');
Router::post('/platform/negocios/{id}/impersonate',     'Platform\TenantsController@impersonate');
Router::get('/platform/impersonate/exit',               'Platform\TenantsController@exitImpersonate');
Router::get('/platform/api/cnpj/{cnpj}',                'Platform\NegociosController@buscarCnpj');

// Unidades DICOM (Grid CRUD)
Router::get('/platform/negocios/{id}/unidades',                        'Platform\NegociosController@listarUnidades');
Router::post('/platform/negocios/{id}/unidades',                       'Platform\NegociosController@criarUnidade');
Router::post('/platform/negocios/{id}/unidades/{uid}/update',          'Platform\NegociosController@atualizarUnidade');
Router::post('/platform/negocios/{id}/unidades/{uid}/delete',          'Platform\NegociosController@excluirUnidade');
Router::get('/platform/negocios/{id}/unidades/{uid}',                  'Platform\NegociosController@getUnidade');

// Logo upload isolado por tenant
Router::post('/platform/negocios/{id}/logo',                           'Platform\NegociosController@uploadLogo');

// Webhooks HUB
Router::post('/platform/api/negocios/{id}/webhook-hub/save',           'Platform\WebhookHubController@save');
Router::get('/platform/api/negocios/{id}/webhook-hub/health',          'Platform\WebhookHubController@healthCheck');
Router::post('/platform/api/negocios/{id}/webhook-hub/test',           'Platform\WebhookHubController@testConnection');
Router::get('/platform/api/negocios/{id}/webhook-hub/logs',            'Platform\WebhookHubController@logs');
Router::post('/platform/api/negocios/{id}/webhook-hub/retry/{evtId}',  'Platform\WebhookHubController@retryEvent');

// VOXEL Report Delivery Hub — configuração e rastreabilidade por negócio
Router::get('/platform/negocios/{id}/report-delivery',                'Platform\ReportDeliveryController@show');
Router::post('/platform/negocios/{id}/report-delivery/destinations',  'Platform\ReportDeliveryController@save');
Router::post('/platform/negocios/{id}/report-delivery/destinations/{destinationId}', 'Platform\ReportDeliveryController@save');
Router::post('/platform/negocios/{id}/report-delivery/jobs/{jobId}/retry', 'Platform\ReportDeliveryController@retry');
Router::post('/platform/negocios/{id}/report-delivery/jobs/{jobId}/recover-stale', 'Platform\ReportDeliveryController@recoverStaleProcessing');
Router::post('/platform/negocios/{id}/report-delivery/reports/enqueue', 'Platform\ReportDeliveryController@enqueueReleasedReport');
Router::post('/platform/negocios/{id}/report-delivery/reports/{reportId}/resend', 'Platform\ReportDeliveryController@resendReleasedReport');

// Imagiflow — integração de apuração por negócio (somente superadmin)
Router::get('/platform/negocios/{id}/imagiflow',          'Platform\ImagiflowIntegrationController@show');
Router::post('/platform/negocios/{id}/imagiflow/gerar',   'Platform\ImagiflowIntegrationController@generate');
Router::post('/platform/negocios/{id}/imagiflow/revogar', 'Platform\ImagiflowIntegrationController@revoke');

// Revisão humana de pixels das cópias anonimizadas do Portal (somente superadmin).
Router::get('/platform/negocios/{id}/portal-imagens', 'Platform\PortalImageReviewController@index');
Router::get('/platform/negocios/{id}/portal-imagens/{copyId}/preview', 'Platform\PortalImageReviewController@preview');
Router::post('/platform/negocios/{id}/portal-imagens/{copyId}/revisar', 'Platform\PortalImageReviewController@review');

// Token de acesso para admin (Etapa 4)
Router::post('/platform/negocios/{id}/enviar-token',                   'Platform\NegociosController@enviarTokenAcesso');

// Redirecionamentos de compatibilidade
Router::get('/platform/tenants',                        'Platform\TenantsController@redirectToNegocios');
Router::get('/platform/tenants/create',                 'Platform\TenantsController@redirectToNegocios');
Router::get('/platform/tenants/{id}/edit',              'Platform\TenantsController@redirectToNegocios');

// ============================================================
// Planos
// ============================================================
Router::get('/platform/plans',                          'Platform\PlansController@index');
Router::get('/platform/plans/create',                   'Platform\PlansController@create');
Router::post('/platform/plans',                         'Platform\PlansController@store');
Router::get('/platform/plans/{id}/edit',                'Platform\PlansController@edit');
Router::post('/platform/plans/{id}/update',             'Platform\PlansController@update');

// ============================================================
// Relatórios da Plataforma
// ============================================================
Router::get('/platform/reports',                        'Platform\PlatformReportsController@index');
Router::get('/platform/reports/exportar',               'Platform\PlatformReportsController@exportar');

// ============================================================
// Servidor PACS (N:N — vários servidores Orthanc, cada um associável a N negócios)
// ============================================================
Router::get('/platform/servidor-pacs',                              'Platform\ServidorPacsController@index');
Router::get('/platform/servidor-pacs/novo',                         'Platform\ServidorPacsController@novoServidor');
Router::post('/platform/servidor-pacs/criar',                       'Platform\ServidorPacsController@criarServidor');
Router::post('/platform/servidor-pacs/sync-robo/gerar-token',       'Platform\ServidorPacsController@syncRoboGerarToken');
Router::post('/platform/servidor-pacs/sync-robo/toggle',            'Platform\ServidorPacsController@syncRoboToggle');
Router::get('/platform/servidor-pacs/roteamento',                   'Platform\ServidorPacsController@roteamento');
Router::post('/platform/servidor-pacs/roteamento/salvar',           'Platform\ServidorPacsController@salvarRoteamento');
Router::post('/platform/servidor-pacs/roteamento/{id}/remover',     'Platform\ServidorPacsController@removerRoteamento');
Router::get('/platform/servidor-pacs/estudos',                      'Platform\ServidorPacsController@estudos');
Router::post('/platform/servidor-pacs/estudos/{id}/resolver',       'Platform\ServidorPacsController@resolverEstudo');
Router::get('/platform/servidor-pacs/estudos/{id}/tags',            'Platform\ServidorPacsController@tagsEstudo');
Router::get('/platform/servidor-pacs/{id}/configurar',              'Platform\ServidorPacsController@configurar');
Router::post('/platform/servidor-pacs/{id}/salvar-config',          'Platform\ServidorPacsController@salvarConfig');
Router::post('/platform/servidor-pacs/{id}/testar',                 'Platform\ServidorPacsController@testar');
Router::post('/platform/servidor-pacs/{id}/sincronizar',            'Platform\ServidorPacsController@sincronizar');
Router::post('/platform/servidor-pacs/{id}/negocios/associar',      'Platform\ServidorPacsController@associarNegocio');
Router::post('/platform/servidor-pacs/{id}/negocios/{tenantId}/desassociar', 'Platform\ServidorPacsController@desassociarNegocio');

// ============================================================
// Conectores de Comunicação Globais (somente superadmin)
// ============================================================
Router::get('/platform/conectores',                    'Platform\ConectoresController@index');
Router::get('/platform/conectores/whatsapp',           'Platform\ConectoresController@whatsapp');
Router::post('/platform/conectores/whatsapp/salvar',   'Platform\ConectoresController@salvarWhatsapp');
Router::post('/platform/conectores/whatsapp/testar',   'Platform\ConectoresController@testarWhatsapp');
Router::get('/platform/conectores/telegram',           'Platform\ConectoresController@telegram');
Router::post('/platform/conectores/telegram/salvar',   'Platform\ConectoresController@salvarTelegram');
Router::post('/platform/conectores/telegram/testar',   'Platform\ConectoresController@testarTelegram');
Router::get('/platform/conectores/logs',               'Platform\ConectoresController@logs');

// ============================================================
// VOXEL Copilot — Integração sistêmica por negócio
// ============================================================
Router::get( '/platform/negocios/{id}/copilot',                                'Platform\CopilotIntegracaoController@show');
Router::post('/platform/negocios/{id}/copilot/gerar-codigo',                   'Platform\CopilotIntegracaoController@gerarCodigo');
Router::post('/platform/negocios/{id}/copilot/medico/{mid}/gerar-token',       'Platform\CopilotIntegracaoController@gerarTokenMedico');
Router::post('/platform/negocios/{id}/copilot/medico/{mid}/revogar',           'Platform\CopilotIntegracaoController@revogarTokenMedico');
Router::get( '/platform/api/negocios/{id}/copilot/status',                     'Platform\CopilotIntegracaoController@apiStatus');
