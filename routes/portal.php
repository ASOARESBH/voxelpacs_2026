<?php

use App\Core\Router;

Router::get('/', 'PatientPortalController@home');
Router::post('/identificar', 'PatientPortalController@identify');
Router::post('/instituicao', 'PatientPortalController@verifyInstitution');
Router::get('/resultados', 'PatientPortalController@results');
Router::get('/laudo/{token}', 'PatientPortalController@pdf');
Router::post('/sair', 'PatientPortalController@logout');
