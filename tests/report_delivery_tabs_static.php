<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string) file_get_contents($root . '/app/Views/platform/negocios/report_delivery.php');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$expect($view !== '', 'Report Delivery view must be readable');

$tabPanes = [
    'report-delivery-config-pane' => [
        'id="destination-form"',
        'Destinos configurados',
    ],
    'report-delivery-jobs-pane' => [
        'Jobs técnicos recentes',
        'id="delivery-filter-patient"',
    ],
    'report-delivery-request-pane' => [
        'id="delivery-request-form"',
        'id="pdf-revision-form"',
        'id="manual-delivery-form"',
    ],
];

foreach ($tabPanes as $paneId => $markers) {
    $paneStart = strpos($view, 'id="' . $paneId . '"');
    $expect($paneStart !== false, "tab pane {$paneId} must exist");
    $nextPane = strpos($view, '<div class="tab-pane ', $paneStart + 1);
    $pane = substr($view, $paneStart, $nextPane === false ? null : $nextPane - $paneStart);
    foreach ($markers as $marker) {
        $expect(str_contains($pane, $marker), "{$marker} must remain inside {$paneId}");
    }
}

foreach ([
    'report-delivery-config-tab',
    'report-delivery-jobs-tab',
    'report-delivery-request-tab',
    'data-bs-toggle="tab"',
    'id="destination-form"',
    'id="delivery-request-form"',
    'id="pdf-revision-form"',
    'id="manual-delivery-form"',
    'data-request-stage="prepare"',
    'data-request-stage="approve"',
    'data-request-stage="materialize"',
    'data-request-stage="arm"',
    'const form = document.getElementById(\'destination-form\')',
    'const requestForm = document.getElementById(\'delivery-request-form\')',
    'const revisionForm = document.getElementById(\'pdf-revision-form\')',
] as $marker) {
    $expect(str_contains($view, $marker), "functional hook missing: {$marker}");
}

foreach ([
    'delivery_hub.tabs.configuracao_destinos',
    'delivery_hub.tabs.jobs_tecnicos',
    'delivery_hub.tabs.request_control_plane',
] as $key) {
    $expect(str_contains($view, "t('{$key}')"), "tab label must use translation key {$key}");
}

echo "REPORT_DELIVERY_TABS_STATIC_OK\n";
