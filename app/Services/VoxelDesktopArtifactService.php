<?php
// Materialização de runtime para publicação restrita do Voxel Desktop.
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\VoxelDesktopRepository;
use PDO;
use RuntimeException;

/** Gera uma única cópia PDF privada da versão liberada já vinculada ao job Voxel Desktop. */
final class VoxelDesktopArtifactService
{
    private PDO $pdo;
    private VoxelDesktopRepository $repo;
    public function __construct(){ $this->pdo=Database::getInstance(); $this->repo=new VoxelDesktopRepository($this->pdo); }
    /** @return array{path:string,sha256:string,size:int,filename:string} */
    public function buildForLeasedJob(array $job): array
    {
        $artifact=$this->repo->findArtifact((int)$job['outbox_id'],(int)$job['tenant_id']);
        if($artifact && is_file((string)$artifact['storage_path'])) return ['path'=>(string)$artifact['storage_path'],'sha256'=>(string)$artifact['sha256'],'size'=>(int)$artifact['file_size_bytes'],'filename'=>'report-'.$job['report_id'].'-v'.$job['report_version'].'.pdf'];
        $reportStmt=$this->pdo->prepare('SELECT * FROM reports WHERE id=:id AND tenant_id=:tenant_id LIMIT 1');
        $reportStmt->execute([':id'=>(int)$job['report_id'],':tenant_id'=>(int)$job['tenant_id']]); $report=$reportStmt->fetch(PDO::FETCH_OBJ);
        $studyStmt=$this->pdo->prepare('SELECT * FROM bi_pacs_estudos WHERE id=:id AND tenant_id=:tenant_id LIMIT 1');
        $studyStmt->execute([':id'=>(int)$job['estudo_id'],':tenant_id'=>(int)$job['tenant_id']]); $study=$studyStmt->fetch(PDO::FETCH_OBJ);
        if(!$report || !$study) throw new RuntimeException('Artefato Voxel Desktop sem laudo ou estudo no mesmo tenant.');
        $content=false;
        try {
            $versionStmt=$this->pdo->prepare('SELECT secao_exame, secao_tecnica, secao_achados, secao_conclusao, secao_recomendacao FROM report_versions WHERE report_id=:report_id AND versao=:version LIMIT 1');
            $versionStmt->execute([':report_id'=>(int)$job['report_id'],':version'=>(int)$job['report_version']]);
            $row=$versionStmt->fetch(PDO::FETCH_ASSOC);
            if($row) $content=json_encode(['secoes'=>['exame'=>$row['secao_exame'] ?? '','tecnica'=>$row['secao_tecnica'] ?? '','achados'=>$row['secao_achados'] ?? '','conclusao'=>$row['secao_conclusao'] ?? '','recomendacao'=>$row['secao_recomendacao'] ?? '']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) { /* fallback legado abaixo */ }
        if(!is_string($content) || $content==='') {
            try { $versionStmt=$this->pdo->prepare('SELECT conteudo FROM report_versions WHERE report_id=:report_id AND versao_numero=:version LIMIT 1'); $versionStmt->execute([':report_id'=>(int)$job['report_id'],':version'=>(int)$job['report_version']]); $content=$versionStmt->fetchColumn(); } catch (\Throwable) { $content=false; }
        }
        if(!is_string($content) || $content==='') throw new RuntimeException('Versão imutável indisponível para Voxel Desktop.');
        $report->conteudo=$content; $binary=(new ReportPdfService())->renderBinary($study,$report);
        if(strlen($binary)<100 || !str_starts_with($binary,'%PDF')) throw new RuntimeException('Artefato PDF Voxel Desktop inválido.');
        $base=(defined('BASE_PATH')?(string)BASE_PATH:dirname(__DIR__,2)).'/storage/voxel_desktop/'.(int)$job['tenant_id'].'/'.(int)$job['outbox_id'];
        if(!is_dir($base) && !mkdir($base,0700,true) && !is_dir($base)) throw new RuntimeException('Armazenamento privado Voxel Desktop indisponível.');
        $filename='report-'.(int)$job['report_id'].'-v'.(int)$job['report_version'].'.pdf'; $path=$base.'/'.$filename;
        if(file_put_contents($path,$binary,LOCK_EX)===false) throw new RuntimeException('Não foi possível gravar o artefato Voxel Desktop.');
        @chmod($path,0600); $sha=hash('sha256',$binary); $this->repo->recordArtifact((int)$job['outbox_id'],(int)$job['tenant_id'],$path,$sha,strlen($binary));
        return ['path'=>$path,'sha256'=>$sha,'size'=>strlen($binary),'filename'=>$filename];
    }
}
