<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../app/Services/PhilipsSubmissionPackageProducer.php');
if (!is_string($source)) {
    fwrite(STDERR, "SOURCE_READ=FAIL\n");
    exit(1);
}

$expected = "array_replace(\$pdf, ['filename' => \$pdfFilename])";
if (!str_contains($source, $expected)) {
    fwrite(STDERR, "DYNAMIC_BASENAME_OVERRIDE=FAIL\n");
    exit(1);
}
if (str_contains($source, "\$pdf + ['filename' => \$pdfFilename]")) {
    fwrite(STDERR, "LEGACY_ARRAY_UNION=FAIL\n");
    exit(1);
}

$legacy = ['filename' => 'laudo-74-v11.pdf', 'type' => 'pdf'];
$dynamic = 'VOXEL_38491_74_V11.pdf';
$merged = array_replace($legacy, ['filename' => $dynamic]);
if ($merged['filename'] !== $dynamic || $merged['type'] !== 'pdf') {
    fwrite(STDERR, "MERGE_SEMANTICS=FAIL\n");
    exit(1);
}

printf("DYNAMIC_BASENAME_OVERRIDE=PASS\n");
printf("LEGACY_ARRAY_UNION=ABSENT\n");
printf("MERGE_SEMANTICS=PASS\n");
