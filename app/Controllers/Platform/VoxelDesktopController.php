<?php
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
                'enabled' => false,
                'profile' => $data['profile'],
                'ambiente' => $data['ambiente'],
            ]);
            $_SESSION['success'] = t('voxel_desktop.saved_disabled');
        } catch (\Throwable $e) {
            Logger::warning('[VoxelDesktopController::save] Configuração recusada', ['tenant_id'=>$id, 'error'=>$e->getMessage()]);
            $_SESSION['error'] = t('voxel_desktop.save_error');
        }
        $this->redirect('/platform/negocios/'.$id.'/voxel-desktop');
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
        if ($name === '' || $router === '' || $site === '' || ($issuer === '' && $institution === '')) throw new DomainException('Configuração de destino incompleta.');
        if (!preg_match('/^[A-Za-z0-9._-]{3,120}$/', $router) || !preg_match('/^[A-Za-z0-9._-]{3,120}$/', $site)) throw new DomainException('Identificador do Router ou site inválido.');
        if ($profile !== 'submission_document' || $environment !== 'homologacao') throw new DomainException('O piloto aceita somente perfil submission/document em homologação.');
        $token = trim((string)($_POST['router_token'] ?? ''));
        $secret = '';
        if ($token !== '') {
            if (strlen($token) < 32) throw new DomainException('Token do Router inválido.');
            $secret = (new ReportDeliveryCryptoService())->encrypt(json_encode(['router_token_hash'=>hash('sha256',$token)], JSON_UNESCAPED_SLASHES));
        }
        return [
            'nome'=>$name, 'router_id'=>$router, 'site_id'=>$site, 'profile'=>$profile, 'ambiente'=>$environment,
            'enabled'=>0, 'disparar_na_liberacao'=>1, 'issuer_of_patient_id_normalized'=>$issuer,
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
    private function requirePlatformAdmin(): void { if (!Auth::check() || !Auth::isPlatformAdmin()) { http_response_code(403); exit; } }
}
