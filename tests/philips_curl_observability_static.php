<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$clientPath = $root . '/app/Services/PhilipsFolderGatewayBridgeClient.php';
$loggerPath = $root . '/app/Core/Logger.php';
$envPath = $root . '/.env.example';

function expect_curl_observability(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$client = file_get_contents($clientPath);
$logger = file_get_contents($loggerPath);
$env = file_get_contents($envPath);
expect_curl_observability(is_string($client), 'Cliente Bridge ausente');
expect_curl_observability(is_string($logger), 'Logger ausente');
expect_curl_observability(is_string($env), 'Template de ambiente ausente');

$start = strpos($client, 'private function logCurlDiagnostics(');
$end = strpos($client, 'private function curlFailureCategory(', $start ?: 0);
expect_curl_observability($start !== false && $end !== false, 'Função de telemetria não localizada');
$diagnostic = substr($client, $start, $end - $start);

preg_match_all("/'([A-Z_]+)'\\s*=>/", $diagnostic, $matches);
$keys = $matches[1] ?? [];
sort($keys);
$expectedKeys = [
    'CURL_ERROR_CATEGORY',
    'CURL_ERRNO',
    'HTTP_STATUS',
    'RESPONSE_REASON_CATEGORY',
];
sort($expectedKeys);
expect_curl_observability($keys === $expectedKeys, 'Telemetria deve conter somente os quatro campos autorizados');
expect_curl_observability(str_contains($diagnostic, "getenv(self::CURL_DIAGNOSTICS_ENV) !== '1'"), 'Flag deve permanecer default-off');
expect_curl_observability(str_contains($diagnostic, 'Logger::sanitized('), 'Telemetria deve usar canal sem IP/URI');

$forbidden = [
    'job_id',
    'destination_id',
    'duration_ms',
    'primary_ip',
    'local_ip',
    'response_size_bytes',
    'curl_error_detail_sanitized',
    'REMOTE_ADDR',
    'REQUEST_URI',
    'X-VOXEL-',
    'PHILIPS_FOLDER_BRIDGE_',
    'CURL_DIAGNOSTIC_TEMP',
];
foreach ($forbidden as $needle) {
    expect_curl_observability(!str_contains($diagnostic, $needle), "Telemetria contém campo proibido: {$needle}");
}

$sanitizedStart = strpos($logger, 'public static function sanitized(');
$sanitizedEnd = strpos($logger, 'private static function write(', $sanitizedStart ?: 0);
expect_curl_observability($sanitizedStart !== false && $sanitizedEnd !== false, 'Canal sanitizado do Logger ausente');
$sanitizedLogger = substr($logger, $sanitizedStart, $sanitizedEnd - $sanitizedStart);
expect_curl_observability(!str_contains($sanitizedLogger, 'REMOTE_ADDR'), 'Canal sanitizado não pode registrar IP');
expect_curl_observability(!str_contains($sanitizedLogger, 'REQUEST_URI'), 'Canal sanitizado não pode registrar URI');
expect_curl_observability(str_contains($env, 'PHILIPS_FOLDER_CURL_DIAGNOSTICS=0'), 'Flag deve estar desligada no template');

fwrite(STDOUT, "PHILIPS_CURL_OBSERVABILITY_STATIC_OK\n");
