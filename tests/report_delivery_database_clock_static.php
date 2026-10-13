<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$clock = (string) file_get_contents($root . '/app/Services/ReportDeliveryClock.php');
$workerRepository = (string) file_get_contents($root . '/app/Repositories/ReportDeliveryWorkerRepository.php');
$worker = (string) file_get_contents($root . '/bin/report_delivery_worker.php');
$b6 = (string) file_get_contents($root . '/bin/philips_nondicom_b6_selector.php');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "REPORT_DELIVERY_DATABASE_CLOCK_STATIC_FAIL: {$message}\n");
        exit(1);
    }
};

$expect(str_contains($clock, 'final class ReportDeliveryClock'), 'Clock class must exist.');
$expect(str_contains($clock, "SELECT CURRENT_DATE"), 'Clock must read the database calendar date.');
$expect(str_contains($clock, "DateTimeImmutable::createFromFormat('!Y-m-d', \$date)"), 'Clock must validate the database date strictly.');
$expect(str_contains($workerRepository, 'ReportDeliveryClock::currentDatabaseDate($this->pdo)'), 'Worker repository must use the shared database clock.');
$expect(str_contains($worker, '$this->repository->currentDatabaseDate()'), 'Worker executable must obtain the date from the repository.');
$expect(!str_contains($worker, "date('Y-m-d')"), 'Worker executable must not use the PHP local date.');
$expect(str_contains($b6, 'ReportDeliveryClock::currentDatabaseDate($pdo)'), 'B6 selector must use the shared database clock.');
$expect(!str_contains($b6, "date('Y-m-d')"), 'B6 selector must not use the PHP local date.');

fwrite(STDOUT, "REPORT_DELIVERY_DATABASE_CLOCK_STATIC_OK\n");
