<?php
// Materialização de runtime para publicação restrita do Voxel Desktop.
declare(strict_types=1);

namespace App\Services;

use App\Repositories\VoxelDesktopRepository;
use PDO;

/** Cria somente registros internos no commit de liberação; não transmite XML ou PDF. */
final class VoxelDesktopOutboxService
{
    public function __construct(private PDO $pdo) {}

    public function queueReleasedReport(int $tenantId, int $reportId, int $estudoId, int $version, object $report, object $estudo, int $releasedBy, string $releasedAt, string $reportHash): array
    {
        if (!filter_var(getenv('VOXEL_DESKTOP_NON_DICOM_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN)) return ['created'=>false,'jobs'=>0,'reason'=>'feature_disabled'];
        $establishment=(int)($estudo->estabelecimento_id ?? $estudo->unidade_id ?? 0) ?: null;
        $issuer=DicomIssuerService::normalize(DicomIssuerService::sanitizeIssuer($estudo->issuer_of_patient_id ?? null));
        $institution=$issuer === null ? InstitutionResolverService::canonicalForTenant($tenantId,(string)($estudo->institution_name ?? '')) : null;
        $eventKey=hash('sha256',implode('|',[$tenantId,$reportId,$version,'voxel_desktop.report.released',$reportHash]));
        $payload=['schema_version'=>1,'event_type'=>'report.released','tenant_id'=>$tenantId,'estabelecimento_id'=>$establishment,'report_id'=>$reportId,'estudo_id'=>$estudoId,'report_version'=>$version,'issuer_of_patient_id_normalized'=>$issuer,'institution_name'=>$institution,'patient_id'=>(string)($estudo->patient_id ?? ''),'patient_name'=>(string)($estudo->patient_name_display ?? $estudo->patient_name ?? ''),'patient_birth_date'=>(string)($estudo->patient_birth_date ?? ''),'patient_sex'=>(string)($estudo->patient_sex ?? ''),'accession_number'=>(string)($estudo->accession_number ?? $estudo->numero_acesso ?? ''),'modality'=>(string)($estudo->modality ?? $estudo->modalidade ?? ''),'released_by'=>$releasedBy,'released_at'=>$releasedAt,'report_sha256'=>$reportHash];
        $repo=new VoxelDesktopRepository($this->pdo);
        $destinations=$repo->findEligibleDestinations($tenantId,$establishment,$issuer,$institution);
        if ($destinations === []) return ['created'=>false,'jobs'=>0,'reason'=>'no_eligible_destination'];
        $outbox=$repo->createOutboxIfAbsent($tenantId,$establishment,$reportId,$estudoId,$version,$eventKey,$payload);
        $jobs=$repo->createJobs($outbox,$tenantId,$establishment,$eventKey,$destinations);
        return ['created'=>true,'outbox_id'=>$outbox,'jobs'=>$jobs];
    }
}
