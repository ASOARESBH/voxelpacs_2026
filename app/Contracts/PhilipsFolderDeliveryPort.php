<?php

declare(strict_types=1);

namespace App\Contracts;

interface PhilipsFolderDeliveryPort
{
    /** @return array<string,mixed> */
    public function deliver(array $job, array $configuration, array $payload, array $artifact): array;

    /** @return array<string,mixed> */
    public function deliverNonDicomPdf(
        array $job,
        array $configuration,
        array $payload,
        array $artifact,
        string $encryptedSecret
    ): array;

    /** @return array<string,mixed> */
    public function deliverNonDicomSubmissionPackage(
        array $job,
        array $configuration,
        array $payload,
        string $encryptedSecret,
        string $workerId
    ): array;
}
