<?php

declare(strict_types=1);

namespace App\Contracts;

interface PhilipsSubmissionAuthorLookup
{
    /**
     * Retorna somente um médico ativo do tenant informado.
     *
     * @return array{id:int,tenant_id:int,nome:string,ativo:int}|null
     */
    public function findActiveBiMedico(int $tenantId, int $authorId): ?array;
}
