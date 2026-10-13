<?php

declare(strict_types=1);

namespace App\Contracts;

interface PhilipsSubmissionAuthorLookup
{
    /**
     * Retorna o signatário congelado pela versão clínica vinculada ao job.
     * O vínculo canônico é report_versions.usuario_id; o id retornado é o
     * bi_medicos.id do médico que assinou a versão e o nome vem do cadastro
     * bi_medicos ativo do mesmo tenant.
     *
     * @return array{id:int,tenant_id:int,nome:string,ativo:int}|null
     */
    public function findSignerForReportVersion(
        int $tenantId,
        int $reportId,
        int $reportVersion,
        int $studyId
    ): ?array;
}
