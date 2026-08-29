<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$scope = getenv('VOXEL_REPORT_DELIVERY_CONTRACT_SCOPE') ?: 'full';
if (!in_array($scope, ['api', 'gateway', 'full'], true)) {
    throw new RuntimeException('invalid_contract_scope');
}

$contracts = [
    'api' => [
        'app/Repositories/ReportDeliveryRepository.php' => [
            'servidor_pacs_id = :servidor_pacs_id',
            'INNER JOIN bi_negocio_servidor_pacs n',
            'Produção automática exige um servidor PACS de origem vinculado ao negócio.',
        ],
        'app/Services/ReportDeliveryOutboxService.php' => [
            "'servidor_pacs_id' => \$servidorPacsId",
            'findActiveDestinations($tenantId, $estabelecimentoId, $issuerNormalized, $institutionName, $servidorPacsId)',
        ],
        'app/Services/ReportDeliveryGatewayBridgeClient.php' => [
            "'X-VOXEL-Tenant-ID: ' . \$tenantId",
            "'X-VOXEL-Destination-ID: ' . \$destinationId",
            "'tenant_destination'",
        ],
        'app/Repositories/ReportDeliveryWorkerRepository.php' => [
            'o.id = j.outbox_id AND o.tenant_id = j.tenant_id',
            'd.id = j.destination_id AND d.tenant_id = j.tenant_id',
            'e.servidor_id = d.servidor_pacs_id',
            'j.automatic_dispatch_date = :automatic_today',
            "d.ambiente = 'producao'",
            'd.disparar_na_liberacao = 1',
        ],
        'bin/report_delivery_worker.php' => [
            "'0008,103E=' . \$this->seriesDescription(\$configuration)",
        ],
    ],
    'gateway' => [
        'deploy/report-delivery-gateway-bridge/bridge_server.py' => [
            'BRIDGE_ALLOW_TENANT_ID',
            'BRIDGE_ALLOW_DESTINATION_ID',
            'def accepts_job(',
            'tenant_id, destination_id',
            'job_id_int, actual_hash[:16]',
        ],
    ],
];

$files = [];
if ($scope === 'api' || $scope === 'full') {
    $files += $contracts['api'];
}
if ($scope === 'gateway' || $scope === 'full') {
    $files += $contracts['gateway'];
}

foreach ($files as $relative => $needles) {
    $content = file_get_contents($root . '/' . $relative);
    if (!is_string($content)) {
        throw new RuntimeException('missing_contract_file:' . $relative);
    }
    foreach ($needles as $needle) {
        if (!str_contains($content, $needle)) {
            throw new RuntimeException('missing_contract_marker:' . $relative);
        }
    }
}

fwrite(STDOUT, "production_delivery_routing_contract_ok scope={$scope}\n");
