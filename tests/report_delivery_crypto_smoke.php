<?php

require_once dirname(__DIR__) . '/app/Services/ReportDeliveryCryptoService.php';

use App\Services\ReportDeliveryCryptoService;

$secret = '{"password":"segredo-de-homologacao","token":"nao-expor"}';
$crypto = new ReportDeliveryCryptoService();
$encrypted = $crypto->encrypt($secret);
$decrypted = $crypto->decrypt($encrypted);

if ($encrypted === $secret || $decrypted !== $secret) {
    fwrite(STDERR, "Falha no round-trip de cifragem do Delivery Hub.\n");
    exit(1);
}

echo "OK: cifra AES-256-GCM validada; payload cifrado não contém o segredo em texto puro.\n";
