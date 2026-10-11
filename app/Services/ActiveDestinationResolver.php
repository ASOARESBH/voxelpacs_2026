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
    public const PERSISTED_DESTINATION_NOT_FOUND = 'PERSISTED_DESTINATION_NOT_FOUND';
    public const PERSISTED_DESTINATION_DISABLED = 'PERSISTED_DESTINATION_DISABLED';
    public const DESTINATION_SCOPE_MISMATCH = 'DESTINATION_SCOPE_MISMATCH';
    public const AUTOMATIC_TRIGGER_DISABLED = 'AUTOMATIC_TRIGGER_DISABLED';

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

    /** @return array<string,mixed> */
    public function resolveAutomaticProductionDestination(int $tenantId, int $destinationId, string $transport): array
    {
        $transport = trim($transport);
        if ($tenantId <= 0 || $destinationId <= 0 || $transport === '') {
            throw new ActiveDestinationResolutionException(self::INVALID_DESTINATION_SCOPE);
        }

        Logger::info('DESTINATION_RESOLUTION_START', [
            'tenant_id' => $tenantId,
            'transport' => $transport,
            'environment' => 'producao',
            'destination_id' => $destinationId,
            'dispatch_mode' => 'automatic_production',
        ]);

        $destination = $this->repository->findDestination($destinationId, $tenantId, true);
        if ($destination === null) {
            throw new ActiveDestinationResolutionException(self::PERSISTED_DESTINATION_NOT_FOUND);
        }
        if ((int) ($destination['enabled'] ?? 0) !== 1) {
            throw new ActiveDestinationResolutionException(self::PERSISTED_DESTINATION_DISABLED);
        }
        if ((string) ($destination['ambiente'] ?? '') !== 'producao'
            || (string) ($destination['transport'] ?? '') !== $transport) {
            throw new ActiveDestinationResolutionException(self::DESTINATION_SCOPE_MISMATCH);
        }
        if ((int) ($destination['disparar_na_liberacao'] ?? 0) !== 1) {
            throw new ActiveDestinationResolutionException(self::AUTOMATIC_TRIGGER_DISABLED);
        }

        Logger::info('DESTINATION_RESOLUTION_SUCCESS', [
            'tenant_id' => $tenantId,
            'transport' => $transport,
            'environment' => 'producao',
            'destination_id' => $destinationId,
            'dispatch_mode' => 'automatic_production',
            'binding' => 'persisted_destination_scope',
        ]);

        return $destination;
    }
}
