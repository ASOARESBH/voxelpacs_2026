<?php

declare(strict_types=1);

namespace App\Contracts;

interface PhilipsSubmissionAuthorLookup
{
    /**
     * Retorna o médico que assinou a versão clínica vinculada ao job.
     * Para a ação liberado, seleciona a versão assinado mais recente com
     * número menor ou igual ao job. O vínculo canônico é report_versions.usuario_id;
     * o id retornado é bi_medicos.id do mesmo tenant. O estado ativo atual não
     * reescreve a autoria histórica.
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
