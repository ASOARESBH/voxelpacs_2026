<?php

declare(strict_types=1);

$path = dirname(__DIR__) . '/app/Repositories/ReportDeliveryRepository.php';
$source = (string) file_get_contents($path);

if (!str_contains($source, ':patient_display_like') || !str_contains($source, ':patient_raw_like')) {
    throw new RuntimeException('A busca de paciente do Delivery Hub não possui placeholders separados.');
}

if (substr_count($source, ':patient_like') > 0) {
    throw new RuntimeException('A busca de paciente do Delivery Hub ainda reutiliza o placeholder incompatível.');
}

foreach ([
    "bindValue(':patient_display_like'",
    "bindValue(':patient_raw_like'",
    'WHERE r.tenant_id = :tenant_id',
    "r.situacao = 'liberado'",
] as $contract) {
    if (!str_contains($source, $contract)) {
        throw new RuntimeException('Contrato de listagem do Delivery Hub ausente.');
    }
}

foreach ([
    'function listTenantPacsServers(int $tenantId): array',
    'FROM bi_pacs_servidor s',
    'INNER JOIN bi_negocio_servidor_pacs bsp',
    'bsp.tenant_id = :tenant_id',
    'bsp.ativo = 1',
    'SELECT s.id, s.nome',
] as $contract) {
    if (!str_contains($source, $contract)) {
        throw new RuntimeException('Contrato tenant-scoped de servidores PACS ausente no Delivery Hub.');
    }
}

fwrite(STDOUT, "REPORT_DELIVERY_REPOSITORY_STATIC_OK\n");
