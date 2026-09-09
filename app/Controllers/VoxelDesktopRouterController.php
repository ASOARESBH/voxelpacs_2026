<?php
// Materialização de runtime para publicação restrita do Voxel Desktop com status autenticado de leitura.
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Repositories\VoxelDesktopRepository;
use App\Services\ReportDeliveryCryptoService;
use App\Services\VoxelDesktopArtifactService;

/** API de pull destinada exclusivamente ao Router Desktop autenticado por token tenant-scoped. */
final class VoxelDesktopRouterController extends Controller
{
    private VoxelDesktopRepository $repo;
    public function __construct(){ $this->repo=new VoxelDesktopRepository(\App\Core\Database::getInstance()); }

    /** Endpoint de leitura para o Router: autentica o par, não reivindica job e aceita destino desativado. */
    public function connectionStatus(): void
    {
        [$destination] = $this->routerContext(false);
        $this->json(['status' => (bool)$destination['enabled'] ? 'configured_enabled' : 'configured_disabled']);
    }

    public function claim(): void
    {
        [$destination,$router]=$this->routerContext();
        $job=$this->repo->claimJob((int)$destination['id'],(int)$destination['tenant_id'],$router);
        if (!$job) { $this->json(['job'=>null]); }
        $payload=json_decode((string)$job['payload_json'],true) ?: [];
        $this->json(['job'=>['id'=>(int)$job['id'],'lease_token'=>(string)$job['lease_token'],'report_version'=>(int)$job['report_version'],'profile'=>(string)$destination['profile'],'metadata'=>$payload,'document_url'=>'/api/voxel-desktop/v1/jobs/'.(int)$job['id'].'/document']]);
    }

    /** Claim exclusivo de teste manual: não consulta outbox nem cria job automático. */
    public function claimManualTest(): void
    {
        [$destination,$router]=$this->routerContext();
        if ((string)$destination['ambiente'] !== 'homologacao') $this->json(['error'=>'manual_test_homologation_only'],403);
        $test=$this->repo->claimManualTest((int)$destination['id'],(int)$destination['tenant_id'],$router);
        if (!$test) { $this->json(['test'=>null]); }
        $metadata=$this->repo->manualTestMetadata($test);
        if (!$metadata) { $this->json(['error'=>'manual_test_metadata_unavailable'],409); }
        $metadata['site_id']=(string)$destination['site_id'];
        $this->json(['test'=>['id'=>(int)$test['id'],'lease_token'=>(string)$test['lease_token'],'report_version'=>(int)$test['report_version'],'profile'=>(string)$destination['profile'],'metadata'=>$metadata,'document_url'=>'/api/voxel-desktop/v1/manual-tests/'.(int)$test['id'].'/document']]);
    }

    public function document(int $jobId): void
    {
        [$destination,$router]=$this->routerContext(); $job=$this->repo->findLeasedJob($jobId,(int)$destination['id'],(int)$destination['tenant_id'],$router);
        if (!$job || !hash_equals((string)$job['lease_token'], $this->leaseToken())) { http_response_code(404); exit; }
        $artifact=(new VoxelDesktopArtifactService())->buildForLeasedJob($job);
        $this->repo->markStatus($jobId,(int)$destination['id'],(int)$destination['tenant_id'],$router,'artifact_ready',null,null);
        header('Content-Type: application/pdf'); header('Content-Length: '.(int)$artifact['size']); header('Content-Disposition: attachment; filename="'.$artifact['filename'].'"');
        readfile($artifact['path']); exit;
    }

    public function manualTestDocument(int $testId): void
    {
        [$destination,$router]=$this->routerContext();
        if ((string)$destination['ambiente'] !== 'homologacao') { http_response_code(403); exit; }
        $test=$this->repo->findLeasedManualTest($testId,(int)$destination['id'],(int)$destination['tenant_id'],$router);
        if (!$test || !hash_equals((string)$test['lease_token'],$this->leaseToken())) { http_response_code(404); exit; }
        $artifact=(new VoxelDesktopArtifactService())->buildForManualTest($test);
        header('Content-Type: application/pdf'); header('Content-Length: '.(int)$artifact['size']); header('Content-Disposition: attachment; filename="'.$artifact['filename'].'"'); readfile($artifact['path']); exit;
    }

    public function status(int $jobId): void
    {
        [$destination,$router]=$this->routerContext(); $body=$this->body();
        if (!hash_equals($this->leaseToken(),(string)($body['lease_token'] ?? ''))) { $this->json(['error'=>'lease_invalid'],403); }
        $status=(string)($body['status'] ?? '');
        $reference=preg_replace('/[^A-Za-z0-9._-]/','',(string)($body['reference'] ?? '')) ?: null;
        $category=preg_replace('/[^a-z_]/','',(string)($body['error_category'] ?? '')) ?: null;
        if(!$this->repo->markStatus($jobId,(int)$destination['id'],(int)$destination['tenant_id'],$router,$status,$reference,$category)) $this->json(['error'=>'job_not_available'],409);
        $this->json(['ok'=>true]);
    }

    public function manualTestStatus(int $testId): void
    {
        [$destination,$router]=$this->routerContext();
        if ((string)$destination['ambiente'] !== 'homologacao') $this->json(['error'=>'manual_test_homologation_only'],403);
        $body=$this->body();
        if (!hash_equals($this->leaseToken(),(string)($body['lease_token'] ?? ''))) $this->json(['error'=>'lease_invalid'],403);
        if(!$this->repo->markManualTestStatus($testId,(int)$destination['id'],(int)$destination['tenant_id'],$router,(string)($body['status'] ?? ''))) $this->json(['error'=>'manual_test_not_available'],409);
        $this->json(['ok'=>true]);
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function routerContext(bool $requireEnabled = true): array
    {
        $router=trim((string)($_SERVER['HTTP_X_VOXEL_ROUTER_ID'] ?? '')); $site=trim((string)($_SERVER['HTTP_X_VOXEL_SITE_ID'] ?? ''));
        $authorization=(string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''); $token=str_starts_with($authorization,'Bearer ')?substr($authorization,7):'';
        if($router===''||$site===''||$token==='') $this->json(['error'=>'router_auth_required'],401);
        $destination=$this->repo->findRouterDestination($router,$site);
        if(!$destination || ($requireEnabled && !(bool)$destination['enabled'])) $this->json(['error'=>'router_destination_disabled'],403);
        try { $secret=json_decode((new ReportDeliveryCryptoService())->decrypt((string)$destination['configuration_secret']),true) ?: []; } catch (\Throwable) { $this->json(['error'=>'router_configuration_invalid'],403); }
        if(empty($secret['router_token_hash']) || !hash_equals((string)$secret['router_token_hash'],hash('sha256',$token))) $this->json(['error'=>'router_auth_invalid'],403);
        return [$destination,$router];
    }
    /** @return array<string,mixed> */ private function body(): array { $body=json_decode((string)file_get_contents('php://input'),true); return is_array($body)?$body:[]; }
    private function leaseToken(): string { return trim((string)($_SERVER['HTTP_X_VOXEL_LEASE_TOKEN'] ?? '')); }
}
