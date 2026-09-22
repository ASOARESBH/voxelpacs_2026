<?php

declare(strict_types=1);

// Materialização de runtime Philips Folder: categorias sanitizadas de falha.

namespace App\Services;

use RuntimeException;

/**
 * Falha classificada do transporte Philips Folder.
 * A categoria é segura para telemetria e nunca contém endpoint, caminho ou segredo.
 */
final class PhilipsFolderDeliveryException extends RuntimeException
{
    public function __construct(
        public readonly string $stage,
        public readonly ?string $reasonCategory = null
    ) {
        parent::__construct($stage);
    }
}
