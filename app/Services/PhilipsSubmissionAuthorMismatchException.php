<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Falha fechada quando o signer congelado não corresponde à versão assinada. */
final class PhilipsSubmissionAuthorMismatchException extends RuntimeException
{
    public const CODE = 'AUTHOR_SIGNER_MISMATCH';

    public function __construct()
    {
        parent::__construct(self::CODE);
    }
}
