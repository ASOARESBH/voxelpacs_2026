<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class ActiveDestinationResolutionException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
