<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'migration' => 'database/migrations/2026-08-16_portal_resultados_pacientes.sql',
    'postgres_migration' => 'database/migrations/2026-08-20_portal_resultados_pacientes_postgresql.sql',
    'host' => 'app/Core/PortalHost.php',
    'session' => 'app/Core/PatientPortalSession.php',
    'service' => 'app/Services/PatientPortalService.php',
    'controller' => 'app/Controllers/PatientPortalController.php',
    'reports' => 'app/Controllers/ReportsController.php',
    'pdf_view' => 'app/Views/reports/pdf.php',
    'pdf_moderno' => 'app/Views/reports/pdf/templates/_moderno_lateral.php',
    'router' => 'app/Core/Router.php',
    'index' => 'public/index.php',
    'routes' => 'routes/portal.php',
    'login' => 'app/Views/portal/login.php',
    'challenge' => 'app/Views/portal/challenge.php',
    'results' => 'app/Views/portal/results.php',
];
foreach ($files as $name => $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) { fwrite(STDERR, "FALHOU: $name ausente\n"); exit(1); }
    $source[$name] = (string) file_get_contents($path);
}

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void { if (!$condition) $failures[] = $message; };

$expect(str_contains($source['migration'], 'bi_portal_login_attempts') && str_contains($source['migration'], 'bi_portal_challenges') && str_contains($source['migration'], 'bi_portal_sessions'), 'Migration MySQL do Portal não cria estruturas isoladas de tentativa, desafio e sessão.');
$expect(str_contains($source['postgres_migration'], 'CREATE TABLE IF NOT EXISTS bi_portal_login_attempts') && str_contains($source['postgres_migration'], 'TIMESTAMPTZ') && str_contains($source['postgres_migration'], 'CREATE INDEX IF NOT EXISTS'), 'Migration PostgreSQL do Portal não está idempotente ou não preserva expiração com fuso horário.');
$expect(!str_contains($source['migration'], 'patient_name_normalized'), 'Migration persiste nome normalizado fora da sessão PHP, contrariando minimização de dados.');
$expect(str_contains($source['host'], 'portal.voxelpacs.com.br') && str_contains($source['index'], 'PortalHost::isPortal()'), 'Subdomínio do Portal não é separado do despacho interno por host.');
$expect(str_contains($source['router'], 'if (PortalHost::isPortal()) return true'), 'Router ainda força autenticação interna no host do Portal.');
$expect(str_contains($source['controller'], "redirect('/resultados')") && str_contains($source['controller'], "redirect('/')") && !str_contains($source['controller'], "redirect('/portal/"), 'Controlador do Portal ainda redireciona para prefixos incompatíveis com o subdomínio dedicado.');
$expect(str_contains($source['service'], 'FAILURE_LIMIT = 5') && str_contains($source['service'], 'BLOCK_MINUTES = 5') && str_contains($source['service'], 'FAILURE_WINDOW_MINUTES = 15'), 'Rate limit aprovado não está configurado no serviço.');
$expect(str_contains($source['service'], 'SqlHelper::futureTimestamp') && str_contains($source['service'], 'RANDOM()') && str_contains($source['service'], 'DATE_SUB(NOW()'), 'Portal não mantém as expressões temporais e de seleção compatíveis com PostgreSQL e MySQL.');
$expect(str_contains($source['service'], 'buildOptions') && str_contains($source['service'], 'Sem correspondência, todas as opções são') && str_contains($source['service'], 'shuffle($options)'), 'Etapa obrigatória de instituição não protege contra oracle de existência.');
$expect(str_contains($source['service'], "report_status'] ?? '') === 'liberado'") && str_contains($source['service'], 'public_token'), 'Histórico do Portal não restringe abertura a laudos liberados com token opaco.');
$expect(!str_contains($source['results'], 'estudo_id') && str_contains($source['results'], '/laudo/'), 'View do Portal expõe ID sequencial de estudo ou não usa rota opaca de laudo.');
$expect(str_contains($source['reports'], 'releasedReportByToken') && str_contains($source['reports'], 'portal_patient_pdf') && str_contains($source['reports'], "'portalPatientPdf' => \$portalPatientPdf"), 'Renderer existente de PDF não valida escopo de paciente antes de servir o laudo.');
$expect(str_contains($source['pdf_view'], '$portalPatientPdf = !empty($portalPatientPdf)') && str_contains($source['pdf_moderno'], 'if (!$portalPatientPdf)'), 'Template de PDF ainda expõe a navegação interna da Worklist no Portal público.');
$expect(str_contains($source['routes'], "Router::get('/imagens/{token}'") && str_contains($source['results'], 'Ver imagens') && str_contains($source['controller'], 'PORTAL_IMAGES_ANONYMIZED') && str_contains($source['controller'], 'releasedReportByToken'), 'Visualização de imagens não está protegida por sessão, token opaco e anonimização explícita.');
$expect(str_contains($source['login'], 'nome_completo') && str_contains($source['login'], 'data_nascimento') && str_contains($source['login'], 'name="sexo"'), 'Formulário inicial não contém os três campos de identidade especificados.');
$expect(str_contains($source['challenge'], 'challenge_token') && str_contains($source['challenge'], 'institution_name'), 'Desafio institucional não preserva token opaco e opção selecionada.');

if ($failures) { fwrite(STDERR, "FALHOU:\n- " . implode("\n- ", $failures) . "\n"); exit(1); }
echo "PATIENT_PORTAL_STATIC_OK\n";
