<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/autoload.php';

use App\Services\DeliveryRequestIdentity;
use App\Services\ReportDeliveryRequestService;

function expect_request(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$uuidA = '11111111-1111-4111-8111-111111111111';
$uuidB = '22222222-2222-4222-8222-222222222222';
$base = [
    'request_uuid' => $uuidA,
    'tenant_id' => 2,
    'report_id' => 74,
    'report_version' => 11,
    'destination_id' => 6,
    'delivery_profile' => 'submission_document',
    'dispatch_mode' => 'manual_homologation',
    'snapshot_digest' => hash('sha256', 'synthetic-snapshot'),
    'destination_config_digest' => hash('sha256', 'synthetic-destination'),
];

expect_request(DeliveryRequestIdentity::assertUuidV4($uuidA) === $uuidA, 'UUID v4 should be accepted');
try {
    DeliveryRequestIdentity::assertUuidV4('not-a-uuid');
    expect_request(false, 'Invalid UUID must fail closed');
} catch (DomainException) {
}

$requestKeyA = DeliveryRequestIdentity::requestKey($base);
$requestKeyARepeat = DeliveryRequestIdentity::requestKey($base);
$requestKeyB = DeliveryRequestIdentity::requestKey(array_replace($base, ['request_uuid' => $uuidB]));
$activeA = DeliveryRequestIdentity::activeIdentityKey($base);
$activeARepeat = DeliveryRequestIdentity::activeIdentityKey(array_replace($base, ['request_uuid' => $uuidB]));
$activeTenant = DeliveryRequestIdentity::activeIdentityKey(array_replace($base, ['tenant_id' => 3]));
$activeVersion = DeliveryRequestIdentity::activeIdentityKey(array_replace($base, ['report_version' => 12]));

expect_request(strlen($requestKeyA) === 64 && strlen($activeA) === 64, 'Identity keys must be SHA-256');
expect_request($requestKeyA === $requestKeyARepeat, 'request_key must be deterministic');
expect_request($requestKeyA !== $requestKeyB, 'request_uuid must distinguish request_key');
expect_request($activeA === $activeARepeat, 'active identity must ignore request_uuid');
expect_request($activeA !== $activeTenant, 'active identity must include tenant');
expect_request($activeA !== $activeVersion, 'active identity must include explicit report version');

$serviceReflection = new ReflectionClass(ReportDeliveryRequestService::class);
$serviceWithoutConstructor = $serviceReflection->newInstanceWithoutConstructor();
$uuidMethod = $serviceReflection->getMethod('newUuidV4');
$uuidMethod->setAccessible(true);
$generatedUuids = [];
for ($i = 0; $i < 8; $i++) {
    $generated = (string) $uuidMethod->invoke($serviceWithoutConstructor);
    DeliveryRequestIdentity::assertUuidV4($generated);
    $generatedUuids[$generated] = true;
}
expect_request(count($generatedUuids) === 8, 'Recovery UUIDs must be server-generated and non-repeating');

$sameParametersMethod = $serviceReflection->getMethod('sameRequestParameters');
$sameParametersMethod->setAccessible(true);
$syntheticRequest = [
    'request_uuid' => $uuidA,
    'report_id' => 74,
    'report_version' => 11,
    'destination_id' => 6,
    'delivery_profile' => 'submission_document',
    'dispatch_mode' => 'manual_homologation',
    'request_reason' => 'motivo sintético',
];
$syntheticInput = array_replace($syntheticRequest, ['request_reason' => '  motivo sintético  ']);
expect_request(
    $sameParametersMethod->invoke($serviceWithoutConstructor, $syntheticRequest, $syntheticInput, $uuidA) === true,
    'UUID replay must accept equivalent normalized request parameters'
);
expect_request(
    $sameParametersMethod->invoke($serviceWithoutConstructor, $syntheticRequest, array_replace($syntheticInput, ['request_reason' => 'outro motivo']), $uuidA) === false,
    'UUID replay must reject a different request reason'
);

$syntheticReport = [
    'estudo_id' => 9,
    'report_version_row_id' => 10,
    'situacao' => 'liberado',
    'liberado_em' => '2026-09-18 00:00:00',
    'secao_conclusao' => 'synthetic',
    'accession_number' => 'SYNTHETIC',
];
$snapshotDigestA = DeliveryRequestIdentity::snapshotDigest(2, 74, 11, $syntheticReport);
$snapshotDigestB = DeliveryRequestIdentity::snapshotDigest(2, 74, 11, array_replace($syntheticReport, ['secao_conclusao' => 'changed']));
expect_request($snapshotDigestA !== $snapshotDigestB, 'Snapshot digest must change when versioned content changes');
$snapshotDigestC = DeliveryRequestIdentity::snapshotDigest(2, 74, 11, array_replace($syntheticReport, ['referring_physician_name' => 'Doctor^One']));
expect_request($snapshotDigestA !== $snapshotDigestC, 'Snapshot digest must change when Referring Physician changes');
$syntheticDestination = [
    'id' => 6,
    'tenant_id' => 2,
    'nome' => 'synthetic',
    'transport' => 'philips_non_dicom',
    'ambiente' => 'homologacao',
    'enabled' => 1,
    'disparar_na_liberacao' => 0,
    'configuration_json' => '{"delivery_profile":"submission_document","configuration_secret":"opaque"}',
    'institution_names' => '',
    'issuers' => '',
];
expect_request(
    DeliveryRequestIdentity::destinationDigest($syntheticDestination)
        === DeliveryRequestIdentity::destinationDigest($syntheticDestination),
    'Destination digest must be deterministic'
);

$service = file_get_contents($root . '/app/Services/ReportDeliveryRequestService.php');
$snapshotService = file_get_contents($root . '/app/Services/ReportDeliveryRequestSnapshotService.php');
$repository = file_get_contents($root . '/app/Repositories/ReportDeliveryRequestRepository.php');
$controller = file_get_contents($root . '/app/Controllers/Platform/ReportDeliveryRequestController.php');
$worker = file_get_contents($root . '/app/Repositories/ReportDeliveryWorkerRepository.php');
$migration = file_get_contents($root . '/database/migrations/2026-09-18_report_delivery_requests_postgresql.sql');
$overrideMigration = file_get_contents($root . '/database/migrations/2026-09-19_report_delivery_request_patient_name_overrides_postgresql.sql');
$overrideService = file_get_contents($root . '/app/Services/ReportDeliveryRequestPatientNameOverrideService.php');
$routes = file_get_contents($root . '/routes/platform.php');
$env = file_get_contents($root . '/.env.example');

foreach ([$service, $snapshotService, $repository, $controller, $worker, $migration, $overrideMigration, $overrideService, $routes, $env] as $content) {
    expect_request(is_string($content), 'Expected Delivery Request file must be readable');
}

foreach (['prepare', 'prepareRecovery', 'approve', 'materialize', 'arm', 'cancel', 'expire', 'get'] as $method) {
    expect_request(str_contains($service, "public function {$method}"), "Service method {$method} missing");
}
foreach (['prepared', 'approved', 'materialized', 'armed', 'processing', 'delivered', 'failed', 'cancelled', 'expired'] as $state) {
    expect_request(str_contains($migration, "'{$state}'"), "State {$state} missing from migration");
}
foreach (['request_uuid', 'request_key', 'active_identity_key', 'report_version', 'destination_id', 'delivery_profile', 'dispatch_mode'] as $marker) {
    expect_request(str_contains($migration, $marker), "Migration marker missing: {$marker}");
}
expect_request(str_contains($repository, "'submission_document', 'queued', :idempotency_key, NULL, NULL)"), 'Materialized job must remain queued and ineligible');
expect_request(str_contains($repository, 'pacs_report_delivery_requests'), 'Repository must use the request table');
expect_request(str_contains($worker, "o.delivery_request_id IS NULL OR dr.status = 'armed'"), 'Worker must require armed request');
expect_request(!str_contains($worker, 'j.' . 'delivery_request_id'), 'Worker must not assume a request column on jobs');
expect_request(str_contains($worker, 'linkedRequestFailureCode') && str_contains($worker, 'configuration_drift'), 'Worker must fail closed on request drift');
expect_request(str_contains($worker, "return 'feature_disabled';"), 'Feature OFF must fail closed and synchronize linked requests');
expect_request(str_contains($worker, 'if ($requestId <= 0)'), 'Feature OFF must keep historical jobs without a request independent');
expect_request(str_contains($worker, 'ON dr.id = o.delivery_request_id'), 'Worker request join must use the outbox linkage');
expect_request(str_contains($repository, '$terminalTimestamp = $status ==='), 'Terminal Request timestamp must be selected without duplicate assignments');
expect_request(!str_contains($repository, '{$column} = NOW(), updated_at = NOW()'), 'Failed Request must not assign updated_at twice');
expect_request(str_contains($repository, 'tableExists'), 'Optional selector tables must not be assumed present');
expect_request(str_contains($worker, "'VOXEL_REPORT_DELIVERY_REQUESTS_ENABLED'"), 'Worker feature flag guard missing');
expect_request(str_contains($service, 'findByRequestUuid') && str_contains($service, 'return $this->publicRequest($existing)'), 'Same request UUID must replay the existing request');
expect_request(str_contains($service, "\$request['request_reason'] ?? ''") && str_contains($service, '$inputReason'), 'UUID replay must compare the normalized request reason');
expect_request(str_contains($service, 'DeliveryRequestIdentity::authorizedSnapshotDigest'), 'Authorized snapshot digest must use the shared canonical helper');
expect_request(str_contains($service, 'DeliveryRequestIdentity::authorizedSnapshotDigest'), 'Authorized digest must include request-scoped override data');
expect_request(str_contains($service, 'DeliveryRequestIdentity::destinationDigest'), 'Destination digest must use the shared canonical helper');
expect_request(str_contains($service, '$request[\'status\'] = self::STATUS_PREPARED;'), 'Prepare must return the persisted prepared state');
expect_request(!str_contains($service, "'authorized_snapshot_digest', 'destination_config_digest'"), 'Public request must not expose full digests');
expect_request(str_contains($snapshotService, 'hydratePayload') && str_contains($snapshotService, 'e.tenant_id = r.tenant_id'), 'Request package must hydrate only the explicit tenant-scoped snapshot');
expect_request(!str_contains($service, "'patient_name'") && !str_contains($service, "'patient_id'"), 'Request outbox payload must not copy clinical fields');
expect_request(str_contains($controller, "_csrf_token"), 'Request endpoints must enforce CSRF');
expect_request(str_contains($controller, "'confirm_prepare'"), 'Prepare must require explicit confirmation');
expect_request(str_contains($controller, "HTTP_IDEMPOTENCY_KEY"), 'Prepare must read Idempotency-Key from the header');
expect_request(str_contains($controller, 'confirm_recovery') && str_contains($controller, 'prepareRecovery'), 'Recovery endpoint must require explicit confirmation');
expect_request(str_contains($service, 'random_bytes(16)') && str_contains($service, 'recovery_request_prepared'), 'Recovery must generate a UUID and append an audit event');
expect_request(str_contains($routes, 'ReportDeliveryRequestController@recover'), 'Recovery route missing');
expect_request(!str_contains($controller, 'snapshot_digest'), 'Controller must not accept full digests for arm');
expect_request(str_contains($controller, 'Auth::isPlatformAdmin') && str_contains($controller, 'Auth::perfilAtual()'), 'Request endpoints must enforce admin authorization');
expect_request(str_contains($routes, 'ReportDeliveryRequestController@prepare'), 'Prepare route missing');
expect_request(str_contains($routes, 'ReportDeliveryRequestController@expire'), 'Expire route missing');
expect_request(str_contains($env, 'VOXEL_REPORT_DELIVERY_REQUESTS_ENABLED=false'), 'Feature flag must default OFF');

foreach (['latestVersion', 'nonce', 'time()', 'random_int', 'Bridge', 'smbclient', 'curl_exec'] as $forbidden) {
    expect_request(!str_contains($service, $forbidden), "Forbidden request behavior found: {$forbidden}");
}
expect_request(!str_contains($repository, 'pacs_report_delivery_attempts'), 'Materialize must not create attempts');
expect_request(!str_contains($repository, 'pacs_report_delivery_artifacts'), 'Materialize must not create artifacts');
expect_request(str_contains($service, 'origem histórica não reutilizada'), 'Recovery reason must state historical identity is not reused');
expect_request(!str_contains($service, 'retryManualHomologationJob'), 'Recovery service must not reuse historical jobs');
expect_request(str_contains($repository, "j.status = 'queued'") && str_contains($repository, 'j.worker_eligible_at IS NULL'), 'Materialized cancellation must require queued and ineligible job');
expect_request(str_contains($repository, "status IN ('prepared','approved','materialized')"), 'Expiration must allow only the approved pre-worker states');
expect_request(!str_contains($migration, 'tenant_id = 2') && !str_contains($migration, 'report_id = 74'), 'Migration must not embed real-case identifiers');
expect_request(str_contains($overrideMigration, 'encrypted_payload') && str_contains($overrideMigration, 'consumed_at'), 'Override migration must be encrypted and single-use');
expect_request(str_contains($overrideMigration, 'prevent_approved_patient_name_override_mutation'), 'Override migration must enforce post-approval immutability');
expect_request(str_contains($overrideService, 'operator_confirmed_homologation'), 'Override source must be explicit and homologation-only');
expect_request(str_contains($overrideService, 'applyToPayload') && str_contains($snapshotService, 'consumeOverride'), 'Snapshot must apply the override at the package boundary');
expect_request(!str_contains($overrideMigration, 'configuration_secret'), 'Override must not reuse destination credential storage');
$auditStart = strpos($service, 'private function logTransition');
$auditEnd = $auditStart === false ? false : strpos($service, 'private function publicRequest', $auditStart);
$auditBlock = $auditStart === false || $auditEnd === false ? '' : substr($service, $auditStart, $auditEnd - $auditStart);
expect_request(str_contains($auditBlock, 'override_scope_match')
    && str_contains($auditBlock, 'override_pair_valid')
    && str_contains($auditBlock, 'override_approved')
    && str_contains($auditBlock, 'override_expires_at')
    && str_contains($auditBlock, 'override_consumed'), 'Override audit must contain only sanitized lifecycle states');
expect_request(!str_contains($auditBlock, "'family'")
    && !str_contains($auditBlock, "'given'")
    && !str_contains($auditBlock, "'middle'")
    && !str_contains($auditBlock, "'patient_name'"), 'Override audit must not log clinical components');

fwrite(STDOUT, "REPORT_DELIVERY_REQUEST_STATIC_OK\n");
