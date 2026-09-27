<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/Controllers/Platform/ReportDeliveryController.php');
$repository = (string) file_get_contents($root . '/app/Repositories/ReportDeliveryRepository.php');
$view = (string) file_get_contents($root . '/app/Views/platform/negocios/report_delivery.php');
$contract = (string) file_get_contents($root . '/docs/PHILIPS_SUBMISSION_DOCUMENT_CONTRACT.md');

foreach ([
    'public function findTenantPacsServer(int $tenantId, int $serverId): ?array',
    'bsp.tenant_id = :tenant_id',
    "':servidor_id' => \$serverId",
] as $needle) {
    if (!str_contains($repository, $needle)) {
        throw new RuntimeException('Consulta tenant-scoped do servidor PACS ausente.');
    }
}

foreach ([
    "'task_site_id'] = \$serverName",
    "findTenantPacsServer(\$tenantId, \$serverPacsId)",
    "'servidor_pacs_id' => \$serverPacsId",
] as $needle) {
    if (!str_contains($controller, $needle)) {
        throw new RuntimeException('Derivação autoritativa do SITE_ID ausente no controller.');
    }
}

foreach ([
    "t('philips_non_dicom.tenant_label')",
    'id="nondicom-tenant-context"',
    'id="nondicom-task-site-id"',
    'readonly',
    'const serverPacsNames =',
    'function syncSiteIdFromServer()',
    'serverPacs.addEventListener(\'change\', syncSiteIdFromServer)',
] as $needle) {
    if (!str_contains($view, $needle)) {
        throw new RuntimeException('Contexto tenant/servidor PACS ausente na UI.');
    }
}

foreach ([
    'philips_non_dicom.task_site_id_help',
    'philips_non_dicom.task_site_id_server_title',
    'philips_non_dicom.tenant_label',
    'philips_non_dicom.tenant_help',
] as $key) {
    foreach (['pt_BR', 'en', 'es'] as $locale) {
        $translations = require $root . '/lang/' . $locale . '.php';
        if (!array_key_exists($key, $translations)) {
            throw new RuntimeException("Chave i18n ausente: {$locale}.{$key}");
        }
    }
}

foreach ([
    '`task_site_id` | Nome do servidor PACS ativo',
    'O tenant não é gravado como um campo clínico do XML',
] as $needle) {
    if (!str_contains($contract, $needle)) {
        throw new RuntimeException('Contrato Philips não documenta a origem do SITE_ID/tenant.');
    }
}

fwrite(STDOUT, "REPORT_DELIVERY_TENANT_SITE_CONTEXT_STATIC_OK\n");
