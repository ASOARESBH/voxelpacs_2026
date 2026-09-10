<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Contrato de artefato Non-DICOM por job já reservado.
 *
 * A Fase 1 fornece somente PDF. Um produtor XML só poderá ser adicionado
 * depois de contrato Philips aprovado, sem alterar fila, bridge ou transporte.
 */
interface NonDicomArtifactProducer
{
    /** @return array{type:string,storage_path:string,sha256:string,size:int,filename:string} */
    public function produce(int $jobId, string $workerId): array;
}
