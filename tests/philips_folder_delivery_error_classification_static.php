<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/autoload.php';

function expect_delivery_error(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$service = file_get_contents($root . '/app/Services/PhilipsFolderDeliveryService.php');
expect_delivery_error(is_string($service), 'Philips delivery service must be readable');
expect_delivery_error(str_contains($service, 'use App\\Core\\Logger;'), 'Delivery service must import App\\Core\\Logger');
expect_delivery_error(str_contains($service, 'catch (PhilipsXmlFieldUnresolvedException $error)'), 'XML field exception must be caught explicitly');
expect_delivery_error(str_contains($service, "'field' => \$error->field"), 'Only the technical XML field may be logged');
expect_delivery_error(str_contains($service, "throw new PhilipsFolderDeliveryException('xml_field_unresolved', 'xml_field_unresolved')"), 'XML field errors must be classified sanitizably');
expect_delivery_error(class_exists('App\\Core\\Logger'), 'App\\Core\\Logger must exist');
expect_delivery_error(!class_exists('App\\Services\\Logger'), 'App\\Services\\Logger must not be resolved implicitly');

$exception = new App\Services\PhilipsXmlFieldUnresolvedException('task_modalities');
expect_delivery_error($exception->sanitizedCode() === 'PHILIPS_XML_FIELD_UNRESOLVED', 'XML field exception code must remain sanitized');
expect_delivery_error($exception->field === 'task_modalities', 'XML field exception must preserve only the technical field');

echo "PHILIPS_FOLDER_DELIVERY_ERROR_CLASSIFICATION_STATIC_OK\n";
