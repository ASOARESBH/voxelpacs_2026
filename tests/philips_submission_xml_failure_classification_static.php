<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/Services/PhilipsFolderDeliveryService.php');
$generator = file_get_contents($root . '/app/Services/PhilipsSubmissionDocumentGenerator.php');

if (!is_string($service) || !is_string($generator)) {
    fwrite(STDERR, "SOURCE_READ_FAILED\n");
    exit(1);
}

function expect_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$mapping = strpos($service, 'catch (PhilipsXmlFieldUnresolvedException $error)');
$bridgeCall = strpos($service, 'sendSubmissionPackage(');

expect_true($mapping !== false, 'submission service must classify unresolved XML fields');
expect_true(str_contains($service, "new PhilipsFolderDeliveryException('xml_field_unresolved', 'xml_field_unresolved')"), 'classification must be sanitized');
expect_true(str_contains($service, "Logger::warning('[PhilipsNonDicomDelivery] PHILIPS_XML_FIELD_UNRESOLVED'"), 'XML failure must be logged as a sanitized event');
expect_true(str_contains($service, "'stage' => 'xml_generation'"), 'XML failure log must identify the technical stage');
expect_true(str_contains($service, '\'field\' => $error->field'), 'XML failure log must identify only the technical field');
expect_true(!str_contains($service, "'payload' =>"), 'XML failure log must not include payload');
expect_true($bridgeCall !== false && $mapping < $bridgeCall, 'XML classification must occur before Bridge call');
expect_true(str_contains($generator, 'requiredText($input, \'task_patient_humanname_family\')'), 'generator must remain fail-closed outside the exception');
expect_true(str_contains($generator, 'PhilipsSubmissionHomologationPolicy::shouldOmitPatientNameComponents'), 'exception must remain policy-gated');

echo "PHILIPS_XML_FAILURE_CLASSIFICATION_STATIC=PASS\n";
