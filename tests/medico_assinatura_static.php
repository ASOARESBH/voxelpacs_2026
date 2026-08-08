<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $relative): string => file_get_contents($root . '/' . $relative);
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$repository = $read('app/Repositories/MedicoAssinaturaRepository.php');
$service = $read('app/Services/MedicoAssinaturaService.php');
$reportService = $read('app/Services/ReportService.php');
$reportsController = $read('app/Controllers/ReportsController.php');
$medicoView = $read('app/Views/medicos/form.php');
$routes = $read('routes/web.php');

$expect(str_contains($repository, 'medico_id = :medico_id AND tenant_id = :tenant_id AND ativa = 1'), 'Busca da assinatura ativa não está isolada por médico, tenant e ativa.');
$expect(str_contains($service, 'public function buscarAtiva(int $medicoId, int $tenantId)'), 'Service não expõe a busca da assinatura ativa.');
$expect(str_contains($service, 'public function listar(int $medicoId, int $tenantId)'), 'Service não expõe o estado cadastrado/inativo para diagnóstico.');
$expect(str_contains($reportService, "'medico_assinatura_inativa'"), 'ReportService não diferencia assinatura cadastrada porém inativa.');
$expect(str_contains($reportService, "'medico_nao_vinculado'"), 'ReportService não diferencia conta sem médico vinculado.');
$expect(str_contains($reportService, "'usuario_id' => \$userId") && str_contains($reportService, "'tenant_id' => \$tenantId"), 'Rastreamento de usuário e tenant não está presente na assinatura.');
$expect(str_contains($reportsController, 'medico_assinatura_inativa'), 'Controller não traduz o estado de assinatura inativa.');
$expect(str_contains($medicoView, 'assinatura_inativa') && str_contains($medicoView, 'ass-badge-inativa'), 'Cadastro do médico não mostra assinatura cadastrada porém inativa.');
$expect(str_contains($routes, "MedicoAssinaturaController@ativar"), 'Rota de ativação da assinatura não está registrada.');
$expect(str_contains($routes, "ReportsController@sign"), 'Rota de assinatura do report não está registrada.');

echo "OK: contrato de vínculo médico-assinatura validado.\n";
