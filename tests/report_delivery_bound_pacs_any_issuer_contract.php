<?php

declare(strict_types=1);

$repository = file_get_contents(__DIR__ . '/../app/Repositories/ReportDeliveryRepository.php');
if ($repository === false) {
    fwrite(STDERR, "repository_unavailable\n");
    exit(1);
}

$required = [
    "accept_any_issuer_from_bound_pacs",
    "d.servidor_pacs_id = :servidor_pacs_id",
    "d.tenant_id = :tenant_id",
    "d.enabled = 1",
    "d.disparar_na_liberacao = 1",
];
foreach ($required as $marker) {
    if (!str_contains($repository, $marker)) {
        fwrite(STDERR, "required_route_guard_missing\n");
        exit(2);
    }
}

if (!str_contains($repository, "ds.id IS NOT NULL OR (d.configuration_json::jsonb ->> 'accept_any_issuer_from_bound_pacs') = 'true'")) {
    fwrite(STDERR, "issuer_policy_guard_missing\n");
    exit(3);
}

fwrite(STDOUT, "report_delivery_bound_pacs_any_issuer_contract_ok\n");
