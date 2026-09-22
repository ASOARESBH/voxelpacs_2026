<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$worker = file_get_contents($root . '/app/Repositories/ReportDeliveryWorkerRepository.php');
if (!is_string($worker)) {
    fwrite(STDERR, "FAIL: worker source unavailable\n");
    exit(1);
}

function expect_flag_contract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

expect_flag_contract(
    substr_count($worker, '$requestSelect = \'o.delivery_request_id\';') === 4,
    'todas as consultas do Worker devem carregar o vínculo do Outbox'
);
expect_flag_contract(
    !str_contains($worker, 'NULL AS delivery_request_id'),
    'o Worker não pode mascarar Request vinculada como NULL'
);
expect_flag_contract(
    substr_count($worker, '$this->requestsFeatureEnabled()') >= 3,
    'a flag deve ser centralizada no Worker'
);
expect_flag_contract(
    str_contains($worker, 'private function linkedRequestFailureCode(array $job): ?string'),
    'claim deve classificar falha da Request antes do processamento'
);
expect_flag_contract(
    str_contains($worker, "return 'feature_disabled';"),
    'flag desligada deve falhar fechado para job vinculado'
);
expect_flag_contract(
    str_contains($worker, "last_error_code = :error_code, last_error_stage = 'claim'"),
    'falha de claim deve sincronizar o código na Delivery Request'
);
expect_flag_contract(
    str_contains($worker, 'private function requestsFeatureEnabled(): bool'),
    'leitura da flag deve ter método único'
);
expect_flag_contract(
    substr_count($worker, 'failUnclaimedRequestJob($job, $claimFailureCode)') === 2,
    'claim automático e one-shot devem usar a mesma sincronização fail-closed'
);
expect_flag_contract(
    str_contains($worker, "Logger::warning('[ReportDeliveryWorker] Claim de Request vinculada bloqueado'")
        && str_contains($worker, "'reason_category' => $failureCode"),
    'bloqueio de claim deve gerar telemetria técnica sanitizada'
);

fwrite(STDOUT, "report_delivery_request_flag_static: PASS\n");
