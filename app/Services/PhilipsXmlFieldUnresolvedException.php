<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/** Falha de contrato sem transportar valor clínico ou configuração sensível. */
final class PhilipsXmlFieldUnresolvedException extends InvalidArgumentException
{
    public const CODE_NAME = 'PHILIPS_XML_FIELD_UNRESOLVED';

    public function __construct(
        public readonly string $field,
        private readonly string $sanitizedCodeValue = self::CODE_NAME
    )
    {
        if (preg_match('/^[a-z0-9_]{1,80}$/', $field) !== 1) {
            throw new InvalidArgumentException('Campo técnico inválido.');
        }
        if (preg_match('/^[A-Z0-9_]{1,80}$/', $sanitizedCodeValue) !== 1) {
            throw new InvalidArgumentException('Código técnico inválido.');
        }
        parent::__construct($sanitizedCodeValue);
    }

    public function sanitizedCode(): string
    {
        return $this->sanitizedCodeValue;
    }
}
