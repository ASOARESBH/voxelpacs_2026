<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function expect_reconciliation(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$bridge = file_get_contents($root . '/deploy/report-delivery-gateway-bridge/philips_folder_bridge.py') ?: '';
$client = file_get_contents($root . '/app/Services/PhilipsFolderGatewayBridgeClient.php') ?: '';
$service = file_get_contents($root . '/app/Services/PhilipsFolderDeliveryService.php') ?: '';
$worker = file_get_contents($root . '/bin/report_delivery_worker.php') ?: '';
$exception = file_get_contents($root . '/app/Services/PhilipsFolderDeliveryException.php') ?: '';

expect_reconciliation(str_contains($bridge, 'def do_GET(self) -> None'), 'Bridge sem rota GET de reconciliação.');
expect_reconciliation(str_contains($bridge, '/v1/philips-folder/package/([0-9]+)/state'), 'Rota de state não está limitada a package/job numérico.');
expect_reconciliation(str_contains($bridge, '"GET"'), 'Reconciliação não assina o método GET.');
expect_reconciliation(str_contains($bridge, 'state.get("state") != "delivered"'), 'State não exige delivered.');
expect_reconciliation(str_contains($bridge, 'state_identity != package_hash'), 'State não compara a package identity solicitada.');
expect_reconciliation(str_contains($bridge, 'state.get("package_verified") != "PASS"'), 'State não exige package_verified=PASS.');
expect_reconciliation(!str_contains($bridge, 'state.get("package_identity", state.get("sha256"'), 'State não pode usar SHA genérico como fallback de identity.');
expect_reconciliation(str_contains($bridge, 'event=philips_package_reconciliation'), 'Reconciliação não possui telemetria sanitizada.');
expect_reconciliation(str_contains($bridge, 'self.wfile.flush()'), 'Resposta HTTP não faz flush explícito.');
expect_reconciliation(str_contains($bridge, 'error_category=connection_closed'), 'Falha de resposta não é classificada sanitizadamente.');
expect_reconciliation(str_contains($bridge, '"tenant_id": tenant_id_header'), 'State novo não registra tenant_id.');
expect_reconciliation(str_contains($bridge, '"destination_id": destination_id_header'), 'State novo não registra destination_id.');

$reconcileStart = strpos($client, 'public function reconcileSubmissionPackage(');
$reconcileEnd = strpos($client, '/** @param array<string,mixed> $configuration', $reconcileStart ?: 0);
expect_reconciliation($reconcileStart !== false && $reconcileEnd !== false, 'Método de reconciliação do cliente ausente.');
$reconcile = substr($client, $reconcileStart, $reconcileEnd - $reconcileStart);
expect_reconciliation(str_contains($reconcile, "'/v1/philips-folder/package/' . \$jobId . '/state'"), 'Cliente não usa endpoint de state allowlisted.');
expect_reconciliation(str_contains($reconcile, "CURLOPT_CUSTOMREQUEST => 'GET'"), 'Reconciliação do cliente não é GET.');
expect_reconciliation(!str_contains($reconcile, 'CURLOPT_POST'), 'Reconciliação não pode executar POST.');
expect_reconciliation(str_contains($reconcile, 'X-VOXEL-Signature'), 'Reconciliação não envia assinatura HMAC.');
expect_reconciliation(str_contains($reconcile, 'CURLOPT_SSLCERT') && str_contains($reconcile, 'CURLOPT_SSLKEY'), 'Reconciliação não preserva mTLS.');
expect_reconciliation(str_contains($reconcile, "'state'] ?? '') !== 'delivered'"), 'Cliente não valida state delivered.');
expect_reconciliation(str_contains($reconcile, "'package_verified'] ?? '')") && str_contains($reconcile, "!== 'PASS'"), 'Cliente não valida package_verified=PASS.');

expect_reconciliation(str_contains($service, "['gateway_delivery_failed', 'gateway_invalid_response']"), 'Serviço não reconcilia somente falhas pós-envio.');
expect_reconciliation(str_contains($service, 'reconcileSubmissionPackage('), 'Serviço não chama reconciliação.');
expect_reconciliation(substr_count($service, 'sendSubmissionPackage(') === 1, 'Falha de confirmação não pode retransmitir o package.');
expect_reconciliation(str_contains($service, "'confirmation_source' => 'bridge_state'"), 'Sucesso reconciliado não é identificado.');
expect_reconciliation(str_contains($service, 'throw $error;'), 'Falha não confirmada não preserva o erro original.');
expect_reconciliation(str_contains($worker, "['confirmation_source'] = 'bridge_state'"), 'Worker não persiste a origem da confirmação.');
expect_reconciliation(str_contains($exception, 'public readonly ?string $packageIdentity'), 'Exceção não carrega package identity.');
expect_reconciliation(str_contains($exception, 'public readonly ?int $packageSize'), 'Exceção não carrega tamanho do package.');

fwrite(STDOUT, "PHILIPS_HTTP_RECONCILIATION_STATIC_OK\n");
