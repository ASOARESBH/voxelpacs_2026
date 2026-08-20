<?php

use App\Core\Router;

Router::get('/', 'PatientPortalController@home');
// Compatibilidade para favoritos e links antigos; a identificação segura continua em POST.
Router::get('/identificar', 'PatientPortalController@home');
Router::post('/identificar', 'PatientPortalController@identify');
Router::post('/instituicao', 'PatientPortalController@verifyInstitution');
Router::get('/resultados', 'PatientPortalController@results');
Router::get('/laudo/{token}', 'PatientPortalController@pdf');
Router::get('/imagens/{token}', 'PatientPortalController@images');
Router::post('/compartilhar/{token}', 'PatientPortalController@share');
Router::get('/compartilhado/{token}', 'PatientPortalController@sharedPdf');
Router::post('/sair', 'PatientPortalController@logout');
