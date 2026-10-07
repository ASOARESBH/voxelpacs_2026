<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$service = $read('app/Services/ReportSignaturePreferenceService.php');
$users = $read('app/Controllers/UsuariosController.php');
$reports = $read('app/Controllers/ReportsController.php');
$reportService = $read('app/Services/ReportService.php');
$header = $read('app/Views/layout/reports_header.php');
$form = $read('app/Views/usuarios/form.php');
$signature = $read('public/assets/js/reports/reports-signature.js');
$mysql = $read('database/migrations/2026-10-07_report_signature_preference_mysql.sql');
$pgsql = $read('database/migrations/2026-10-07_report_signature_preference_postgresql.sql');

$expect(str_contains($service, 'bi_user_report_signature_preferences'), 'Service não usa a tabela tenant-scoped da preferência.');
$expect(str_contains($service, 'MODE_SIGN_ONLY') && str_contains($service, 'MODE_SIGN_AND_CLOSE'), 'Service não declara os dois modos restritivos.');
$expect(str_contains($service, 'effectiveMode('), 'Service não possui imposição server-side do modo.');
$expect(str_contains($service, 'SqlHelper::hasTable'), 'Service não possui fallback seguro para migration ausente.');
$expect(str_contains($users, 'report_signature_mode'), 'Cadastro de usuário não persiste o campo de preferência.');
$expect(str_contains($reports, 'effectiveMode('), 'Endpoint de assinatura não impõe a preferência do servidor.');
$expect(str_contains($reports, 'assinatura_preferencia_somente'), 'Endpoint de liberação não bloqueia a preferência Somente assinar.');
$expect(str_contains($reportService, 'ReportSignaturePreferenceService())->effectiveMode'), 'ReportService não impõe a preferência ao assinar.');
$expect(str_contains($reportService, 'ReportSignaturePreferenceService())->resolveForUser'), 'ReportService não impõe a preferência ao liberar.');
$expect(str_contains($header, 'signaturePreference'), 'Header não recebe a preferência do usuário.');
$expect(str_contains($form, 'name="report_signature_mode"') && str_contains($form, "['ambos', 'somente', 'fechar']"), 'Formulário não apresenta as três opções exclusivas.');
$expect(str_contains($signature, 'data-signature-mode') || str_contains($signature, 'signatureMode'), 'Frontend não aplica a preferência ao modal.');
$expect(str_contains($mysql, "'ambos'") && str_contains($mysql, "'somente'") && str_contains($mysql, "'fechar'"), 'Migration MySQL não restringe os modos.');
$expect(str_contains($pgsql, "'ambos'") && str_contains($pgsql, "'somente'") && str_contains($pgsql, "'fechar'"), 'Migration PostgreSQL não restringe os modos.');

fwrite(STDOUT, "REPORT_SIGNATURE_PREFERENCE_STATIC=PASS\n");
