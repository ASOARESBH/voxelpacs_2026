<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Repositories\ReportDeliveryRepository;

final class ActiveDestinationResolver
{
    public const NO_ACTIVE_DESTINATION = 'NO_ACTIVE_DESTINATION';
    public const MULTIPLE_ACTIVE_DESTINATIONS = 'MULTIPLE_ACTIVE_DESTINATIONS';
    public const INVALID_DESTINATION_SCOPE = 'INVALID_DESTINATION_SCOPE';

    public function __construct(private readonly ReportDeliveryRepository $repository)
    {
    }

    /** @return array<string,mixed> */
    public function resolveActiveDestination(int $tenantId, string $transport, string $environment): array
    {
        $transport = trim($transport);
        $environment = trim($environment);
        if ($tenantId <= 0 || $transport === '' || !in_array($environment, ['homologacao', 'producao'], true)) {
            Logger::warning('DESTINATION_RESOLUTION_BLOCKED', [
                'tenant_id' => $tenantId,
                'transport' => $transport,
                'environment' => $environment,
                'reason' => self::INVALID_DESTINATION_SCOPE,
            ]);
            throw new ActiveDestinationResolutionException(self::INVALID_DESTINATION_SCOPE);
        }

        Logger::info('DESTINATION_RESOLUTION_START', [
            'tenant_id' => $tenantId,
            'transport' => $transport,
            'environment' => $environment,
        ]);

        $destinations = $this->repository->findActiveDestinationsByTransportAndEnvironment(
            $tenantId,
            $transport,
            $environment
        );
        if ($destinations === []) {
            Logger::warning('DESTINATION_RESOLUTION_BLOCKED', [
                'tenant_id' => $tenantId,
                'transport' => $transport,
                'environment' => $environment,
                'reason' => self::NO_ACTIVE_DESTINATION,
            ]);
            throw new ActiveDestinationResolutionException(self::NO_ACTIVE_DESTINATION);
        }
        if (count($destinations) > 1) {
            Logger::warning('DESTINATION_RESOLUTION_BLOCKED', [
                'tenant_id' => $tenantId,
                'transport' => $transport,
                'environment' => $environment,
                'reason' => self::MULTIPLE_ACTIVE_DESTINATIONS,
            ]);
            throw new ActiveDestinationResolutionException(self::MULTIPLE_ACTIVE_DESTINATIONS);
        }

        $destination = $destinations[0];
        Logger::info('DESTINATION_RESOLUTION_SUCCESS', [
            'tenant_id' => $tenantId,
            'transport' => $transport,
            'environment' => $environment,
            'destination_id' => (int) ($destination['id'] ?? 0),
        ]);

        return $destination;
    }
}
