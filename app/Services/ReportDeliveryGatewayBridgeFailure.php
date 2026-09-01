<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Falha sanitizada retornada pela bridge privada, sem resposta ou dados DICOM brutos. */
final class ReportDeliveryGatewayBridgeFailure extends RuntimeException
{
    /** @param array<string,string> $metadata */
    public function __construct(public readonly string $stage, public readonly array $metadata = [])
    {
        parent::__construct($stage);
    }
}
