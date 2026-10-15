<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/autoload.php';

$clock = (string) file_get_contents($root . '/app/Services/ReportDeliveryClock.php');
$workerRepository = (string) file_get_contents($root . '/app/Repositories/ReportDeliveryWorkerRepository.php');
$deliveryRepository = (string) file_get_contents($root . '/app/Repositories/ReportDeliveryRepository.php');
$outbox = (string) file_get_contents($root . '/app/Services/ReportDeliveryOutboxService.php');
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "REPORT_DELIVERY_CLINICAL_CALENDAR_STATIC_FAIL: {$message}\n");
        exit(1);
    }
};

$expect(str_contains($clock, "America/Sao_Paulo"), 'Clinical timezone must be explicit.');
$expect(str_contains($workerRepository, 'expireAutomaticJobsBefore'), 'Worker must retain automatic expiration.');
$expect(str_contains($workerRepository, 'currentClinicalDate'), 'Expiration and claim must use the clinical clock.');
$expect(str_contains($workerRepository, 'automatic_dispatch_date < :current_date'), 'Expiration must compare against the clinical date.');
$expect(str_contains($deliveryRepository, 'ReportDeliveryClock::currentClinicalDate($this->pdo)'), 'Manual resend paths must use the clinical clock.');
$expect(str_contains($deliveryRepository, ':automatic_dispatch_date'), 'Automatic resend must bind the clinical date.');
$expect(str_contains($outbox, 'ReportDeliveryClock::CLINICAL_TIMEZONE'), 'Release must derive the clinical date in BRT.');

$reflection = new ReflectionClass(App\Services\ReportDeliveryOutboxService::class);
$service = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('clinicalDate');
$method->setAccessible(true);
$expect($method->invoke($service, '2026-10-15 22:30:00') === '2026-10-15', '22:30 BRT release must remain eligible on the same clinical day.');
$expect($method->invoke($service, '2026-10-15T22:30:00') === '2026-10-15', 'ISO timestamp without timezone must remain on the local clinical date.');
$expect($method->invoke($service, '2026-10-16T01:30:00+00:00') === '2026-10-15', 'Explicit UTC timestamp must convert to the BRT clinical date.');
$expect($method->invoke($service, '2026-10-16T03:00:00+00:00') === '2026-10-16', 'Timestamp after midnight BRT must use the next clinical day.');

fwrite(STDOUT, "REPORT_DELIVERY_CLINICAL_CALENDAR_STATIC_PASS\n");
