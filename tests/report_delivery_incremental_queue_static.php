<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$repository = (string) file_get_contents($root . '/app/Repositories/ReportDeliveryWorkerRepository.php');
$worker = (string) file_get_contents($root . '/bin/report_delivery_worker.php');
$module = (string) file_get_contents($root . '/SKILL-VOXEL-PACS/modules/report-delivery.md');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "INCREMENTAL_QUEUE_STATIC_FAIL: {$message}\n");
        exit(1);
    }
};

$expect(str_contains($repository, 'ORDER BY j.created_at ASC, j.id ASC'), 'fila automática deve processar o mais antigo primeiro');
$expect(str_contains($worker, '$this->deliver($job);') && str_contains($worker, 'while (true)'), 'worker deve retornar ao claim após cada entrega');
$expect(str_contains($repository, 'private function isAutomaticProductionSubmissionJob(array $job)'), 'identificação automática deve ser explícita');
$expect(str_contains($repository, "return 1;"), 'submission_document automático deve ter uma tentativa');
$expect(str_contains($repository, 'PHILIPS_NON_DICOM_MANUAL_RECOVERY_REQUIRED'), 'falha terminal deve gerar evento sanitizado de recuperação manual');
$expect(str_contains($repository, "'next_action' => 'manual_resend_dead_letter'"), 'evento deve apontar para o reenvio oficial');
$expect(!str_contains($repository, "'patient_name'") && !str_contains($repository, "'payload_json' =>"), 'telemetria não pode carregar PHI nem payload');
$expect(str_contains($module, 'created_at ASC, id ASC'), 'documentação deve registrar o critério FIFO');
$expect(str_contains($module, 'PHILIPS_NON_DICOM_MANUAL_RECOVERY_REQUIRED'), 'documentação deve registrar o evento de monitoramento');

fwrite(STDOUT, "REPORT_DELIVERY_INCREMENTAL_QUEUE_STATIC_OK\n");
