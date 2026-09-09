<?php
// Materialização de runtime para publicação restrita do Voxel Desktop com identificadores administrativos livres.
declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Audit\AuditLogger;
use App\Repositories\VoxelDesktopRepository;
use App\Services\ReportDeliveryCryptoService;
use App\Services\DicomIssuerService;
use App\Services\VoxelDesktopManualTestService;
use DomainException;

/** Control-plane superadmin do Router Desktop; não inicia transmissões nem executa jobs. */
final class VoxelDesktopController extends Controller
{
    public function show(int $id): void
    {
        $this->requirePlatformAdmin();
        $tenant = $this->tenant($id);
        $repo = new VoxelDesktopRepository(Database::getInstance());
        $this->view('platform/negocios/voxel_desktop', [
            'title' => t('voxel_desktop.title'),
            'tenant' => $tenant,
            'destinations' => $repo->listDestinations($id),
            'technicalLogs' => $repo->technicalEvents($id),
            'manualTests' => $repo->listManualTests($id),
            'csrfToken' => $this->csrfToken(),
        ], 'platform');
    }

    public function save(int $id, ?int $destinationId = null): void
    {
        $this->requirePlatformAdmin();
        $this->tenant($id);
        if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['_csrf_token'] ?? ''))) {
            $_SESSION['error'] = t('voxel_desktop.csrf_error');
            $this->redirect('/platform/negocios/'.$id.'/voxel-desktop');
        }
        try {
            $data = $this->payload();
            $saved = (new VoxelDesktopRepository(Database::getInstance()))->saveDestination($id, $destinationId, $data, (int)Auth::userId());
            AuditLogger::log('voxel_desktop.destination.save', 'pacs_voxel_desktop_destinations', $saved, [
                'tenant_id' => $id,
                'result' => 'saved_disabled',
                'enabled' => false,
                'profile' => $data['profile'],
                'ambiente' => $data['ambiente'],
            ]);
            $_SESSION['success'] = t('voxel_desktop.saved_disabled');
        } catch (DomainException $e) {
            $code = $e->getMessage();
            AuditLogger::log('voxel_desktop.destination.rejected', 'pacs_voxel_desktop_destinations', $destinationId, [
                'tenant_id' => $id,
                'reason_code' => $code,
                'enabled' => false,
            ], $id, 'platform');
            $_SESSION['error'] = $this->messageFor($code);
        } catch (\Throwable $e) {
            Logger::warning('[VoxelDesktopController::save] Configuração recusada', ['tenant_id'=>$id, 'reason'=>'technical_failure']);
            AuditLogger::log('voxel_desktop.destination.failed', 'pacs_voxel_desktop_destinations', $destinationId, [
                'tenant_id' => $id,
                'reason_code' => 'technical_failure',
                'enabled' => false,
            ], $id, 'platform');
            $_SESSION['error'] = t('voxel_desktop.save_error');
        }
        $this->redirect('/platform/negocios/'.$id.'/voxel-desktop');
    }

    public function activate(int $tenantId, int $destinationId): void
    {
        $this->requirePlatformAdmin(); $this->tenant($tenantId); $this->assertCsrf();
        try {
            if ((string)($_POST['confirm_activation'] ?? '') !== '1') throw new DomainException('activation_confirmation_required');
            $repo=new VoxelDesktopRepository(Database::getInstance()); $destination=$repo->findDestination($tenantId,$destinationId,true);
            if (!$destination) throw new DomainException('destination_not_found');
            if ((string)$destination['ambiente']==='producao' && (string)($_POST['confirm_production_activation'] ?? '') !== '1') throw new DomainException('production_confirmation_required');
            $repo->setDestinationEnabled($tenantId,$destinationId,true);
            AuditLogger::log('voxel_desktop.destination.activated','pacs_voxel_desktop_destinations',$destinationId,['tenant_id'=>$tenantId,'ambiente'=>(string)$destination['ambiente'],'automatic_release'=>false]);
            $_SESSION['success']=t('voxel_desktop.activated');
        } catch (DomainException $e) { $_SESSION['error']=$this->messageFor($e->getMessage()); }
        catch (\Throwable $e) { Logger::warning('[VoxelDesktopController::activate] Falha técnica', ['tenant_id'=>$tenantId,'destination_id'=>$destinationId,'reason'=>'technical_failure']); $_SESSION['error']=t('voxel_desktop.action_error'); }
        $this->redirect('/platform/negocios/'.$tenantId.'/voxel-desktop');
    }

    public function deactivate(int $tenantId, int $destinationId): void
    {
        $this->requirePlatformAdmin(); $this->tenant($tenantId); $this->assertCsrf();
        try {
            if ((string)($_POST['confirm_deactivation'] ?? '') !== '1') throw new DomainException('deactivation_confirmation_required');
            $repo=new VoxelDesktopRepository(Database::getInstance());
            $repo->setDestinationEnabled($tenantId,$destinationId,false);
            AuditLogger::log('voxel_desktop.destination.deactivated','pacs_voxel_desktop_destinations',$destinationId,['tenant_id'=>$tenantId,'automatic_release'=>false]);
            $_SESSION['success']=t('voxel_desktop.deactivated');
        } catch (DomainException $e) { $_SESSION['error']=$this->messageFor($e->getMessage()); }
        catch (\Throwable $e) { Logger::warning('[VoxelDesktopController::deactivate] Falha técnica', ['tenant_id'=>$tenantId,'destination_id'=>$destinationId,'reason'=>'technical_failure']); $_SESSION['error']=t('voxel_desktop.action_error'); }
        $this->redirect('/platform/negocios/'.$tenantId.'/voxel-desktop');
    }

    public function prepareManualTest(int $tenantId): void
    {
        $this->requirePlatformAdmin(); $this->tenant($tenantId); $this->assertCsrf();
        try {
            if ((string)($_POST['confirm_single_manual_test'] ?? '') !== '1') throw new DomainException('manual_test_confirmation_required');
            $destinationId=(int)($_POST['destination_id'] ?? 0); $token=strtolower(trim((string)($_POST['report_public_token'] ?? '')));
            $result=(new VoxelDesktopManualTestService(Database::getInstance()))->prepare($tenantId,$destinationId,$token,(int)Auth::userId());
            AuditLogger::log('voxel_desktop.manual_test.prepared','pacs_voxel_desktop_manual_tests',(int)$result['test_id'],['tenant_id'=>$tenantId,'destination_id'=>$destinationId,'expires_at'=>$result['expires_at'],'automatic_release'=>false]);
            $_SESSION['success']=t('voxel_desktop.manual_test_prepared');
        } catch (DomainException $e) { $_SESSION['error']=$this->messageFor($e->getMessage()); }
        catch (\Throwable $e) { Logger::warning('[VoxelDesktopController::prepareManualTest] Falha técnica', ['tenant_id'=>$tenantId,'reason'=>'technical_failure']); $_SESSION['error']=t('voxel_desktop.action_error'); }
        $this->redirect('/platform/negocios/'.$tenantId.'/voxel-desktop');
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        $name = trim((string)($_POST['nome'] ?? ''));
        $router = trim((string)($_POST['router_id'] ?? ''));
        $site = trim((string)($_POST['site_id'] ?? ''));
        $issuer = DicomIssuerService::normalize(trim((string)($_POST['issuer_of_patient_id_normalized'] ?? '')));
        $institution = trim((string)($_POST['institution_name'] ?? ''));
        $profile = (string)($_POST['profile'] ?? 'submission_document');
        $environment = (string)($_POST['ambiente'] ?? 'homologacao');
        if ($name === '' || $router === '' || $site === '' || ($issuer === '' && $institution === '')) throw new DomainException('destination_incomplete');
        if (mb_strlen($router) > 120 || mb_strlen($site) > 120) throw new DomainException('identifier_too_long');
        if ($profile !== 'submission_document' || !in_array($environment,['homologacao','producao'],true)) throw new DomainException('unsupported_profile');
        $token = trim((string)($_POST['router_token'] ?? ''));
        $secret = '';
        if ($token !== '') {
            if (strlen($token) < 32) throw new DomainException('invalid_router_token');
            $secret = (new ReportDeliveryCryptoService())->encrypt(json_encode(['router_token_hash'=>hash('sha256',$token)], JSON_UNESCAPED_SLASHES));
        }
        return [
            'nome'=>$name, 'router_id'=>$router, 'site_id'=>$site, 'profile'=>$profile, 'ambiente'=>$environment,
            'enabled'=>0, 'disparar_na_liberacao'=>0, 'issuer_of_patient_id_normalized'=>$issuer,
            'institution_name'=>$issuer === '' ? $institution : '', 'configuration_json'=>json_encode(['api_version'=>'v1'], JSON_UNESCAPED_SLASHES),
            'configuration_secret'=>$secret, 'timeout_seconds'=>30, 'max_attempts'=>4, 'estabelecimento_id'=>null,
        ];
    }

    /** @return array<string,mixed> */
    private function tenant(int $id): array
    {
        $stmt=Database::getInstance()->prepare('SELECT id, nome, status FROM bi_tenants WHERE id=:id LIMIT 1');
        $stmt->execute([':id'=>$id]); $tenant=$stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$tenant) { http_response_code(404); exit; }
        return $tenant;
    }
    private function assertCsrf(): void { if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''),(string)($_POST['_csrf_token'] ?? ''))) throw new DomainException('csrf_error'); }
    private function messageFor(string $code): string { return t('voxel_desktop.error.' . $code); }
    private function requirePlatformAdmin(): void { if (!Auth::check() || !Auth::isPlatformAdmin() || Auth::isImpersonating()) { http_response_code(403); exit; } }
}
