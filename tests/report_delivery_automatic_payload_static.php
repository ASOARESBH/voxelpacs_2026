<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$outbox = file_get_contents($root . '/app/Services/ReportDeliveryOutboxService.php');
$workerRepository = file_get_contents($root . '/app/Repositories/ReportDeliveryWorkerRepository.php');

function expect_automatic_payload(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "AUTOMATIC_PAYLOAD_STATIC_FAIL: {$message}\n");
        exit(1);
    }
}

expect_automatic_payload(is_string($outbox), 'Outbox service must be readable.');
expect_automatic_payload(is_string($workerRepository), 'Worker repository must be readable.');

$guard = "if (\$dispatchMode === 'automatic_production') {";
$assignment = "\$payload['referring_physician_name'] = \$estudo->referring_physician_name ?? null;";
$guardPosition = strpos($outbox, $guard);
$assignmentPosition = strpos($outbox, $assignment);

expect_automatic_payload($guardPosition !== false, 'The automatic dispatch guard must remain explicit.');
expect_automatic_payload($assignmentPosition !== false, 'The automatic payload must freeze referring_physician_name.');
expect_automatic_payload($guardPosition < $assignmentPosition, 'The clinical field must be assigned only after the automatic guard.');
expect_automatic_payload(substr_count($outbox, $assignment) === 1, 'The field must have one source assignment.');
expect_automatic_payload(!str_contains($outbox, "'medico_solicitante_manual'"), 'Manual physician field must not replace the DICOM referring physician source.');
expect_automatic_payload(!str_contains($outbox, "'task_author_source'"), 'Automatic queueing must not invent task_author_source.');
expect_automatic_payload(!str_contains($outbox, "'task_author_id'"), 'Automatic queueing must not invent task_author_id.');
expect_automatic_payload(str_contains($outbox, "'schema_version' => 2"), 'The existing payload schema version must remain stable.');
expect_automatic_payload(str_contains($outbox, 'createOutboxIfAbsent('), 'Outbox idempotent creation must remain in place.');
expect_automatic_payload(str_contains($outbox, 'createJobs('), 'Job creation must remain in the existing path.');
foreach (['patient_name_family', 'patient_name_given', 'patient_name_middle', 'patient_name_source'] as $field) {
    expect_automatic_payload(str_contains($outbox, "\$payload['{$field}']"), "Automatic payload must freeze {$field} from report_versions.");
}
expect_automatic_payload(str_contains($outbox, 'loadFrozenPatientName('), 'Automatic payload must load the frozen PatientName version.');
expect_automatic_payload(str_contains($workerRepository, 'e.study_time, e.referring_physician_name, e.institution_name'), 'Worker snapshot must continue to project referring_physician_name.');

echo "REPORT_DELIVERY_AUTOMATIC_PAYLOAD_STATIC_OK\n";
