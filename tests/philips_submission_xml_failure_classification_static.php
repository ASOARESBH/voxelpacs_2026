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

$mapping = strpos($service, 'catch (PhilipsXmlFieldUnresolvedException)');
$bridgeCall = strpos($service, 'sendSubmissionPackage(');

expect_true($mapping !== false, 'submission service must classify unresolved XML fields');
expect_true(str_contains($service, "new PhilipsFolderDeliveryException('xml_field_unresolved', 'xml_field_unresolved')"), 'classification must be sanitized');
expect_true($bridgeCall !== false && $mapping < $bridgeCall, 'XML classification must occur before Bridge call');
expect_true(str_contains($generator, 'requiredText($input, \'task_patient_humanname_family\')'), 'generator must remain fail-closed outside the exception');
expect_true(str_contains($generator, 'PhilipsSubmissionHomologationPolicy::shouldOmitPatientNameComponents'), 'exception must remain policy-gated');

echo "PHILIPS_XML_FAILURE_CLASSIFICATION_STATIC=PASS\n";
