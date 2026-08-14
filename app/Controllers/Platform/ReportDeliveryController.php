<?php

namespace App\Controllers\Platform;

use App\Core\Audit\AuditLogger;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Models\Tenant;
use App\Repositories\ReportDeliveryRepository;
use App\Services\InstitutionResolverService;
use App\Services\ReportDeliveryCryptoService;
use DomainException;
use Throwable;

/**
 * Administração do VOXEL Report Delivery Hub por negócio.
 *
 * Destinos iniciam desativados e em homologação. Nenhuma rota desta classe
 * envia laudos: ela apenas cadastra destinos e gerencia a fila auditável.
 */
class ReportDeliveryController extends Controller
{
    private ReportDeliveryRepository $repository;
    private ReportDeliveryCryptoService $crypto;
    private Tenant $tenantModel;

    /** @var array<int, string> */
    private array $transports = ['dicom_pdf', 'dicom_sr', 'hl7_oru', 'https_webhook', 'sftp'];

    public function __construct()
    {
        $this->repository = new ReportDeliveryRepository(Database::getInstance());
        $this->crypto = new ReportDeliveryCryptoService();
        $this->tenantModel = new Tenant();
    }

    public function show(int $tenantId): void
    {
        if (!$this->isPlatformAdmin()) {
            $this->redirect('/login');
        }

        $tenant = $this->tenantModel->find($tenantId);
        if (!$tenant) {
            http_response_code(404);
            echo 'Negócio não encontrado.';
            return;
        }

        $this->view('platform/negocios/report_delivery', [
            'tenant' => $tenant,
            'destinations' => $this->repository->listDestinations($tenantId),
            'jobs' => $this->repository->listJobs($tenantId),
            'stats' => $this->repository->stats($tenantId),
            'csrfToken' => $this->csrfToken(),
            'transports' => $this->transports,
            'institutionNames' => InstitutionResolverService::getInstitutionNamesByTenant($tenantId),
        ], 'platform');
    }

    public function save(int $tenantId, ?int $destinationId = null): void
    {
        if (!$this->isPlatformAdmin()) {
            $this->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }
        if (!$this->validCsrf()) {
            $this->json(['success' => false, 'message' => 'Sessão expirada. Atualize a página e tente novamente.'], 419);
        }
        if (!$this->tenantModel->find($tenantId)) {
            $this->json(['success' => false, 'message' => 'Negócio não encontrado.'], 404);
        }

        try {
            $data = $this->validatedPayload($tenantId);
            $savedId = $this->repository->saveDestination($tenantId, $destinationId, $data, (int) Auth::userId());
            AuditLogger::log('report_delivery.destination_saved', 'pacs_report_delivery_destinations', $savedId, [
                'tenant_id' => $tenantId,
                'transport' => $data['transport'],
                'ambiente' => $data['ambiente'],
                'enabled' => $data['enabled'],
                'institution_names' => $data['institution_names'],
            ]);
            $this->json([
                'success' => true,
                'message' => 'Destino salvo. Ele permanece em homologação até ser validado pelo worker.',
                'destination_id' => $savedId,
            ]);
        } catch (DomainException $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Logger::error('[ReportDeliveryController::save] Falha ao salvar destino', [
                'tenant_id' => $tenantId,
                'destination_id' => $destinationId,
                'error' => $e->getMessage(),
            ]);
            $this->json(['success' => false, 'message' => 'Não foi possível salvar o destino. Consulte os logs administrativos.'], 500);
        }
    }

    public function retry(int $tenantId, int $jobId): void
    {
        if (!$this->isPlatformAdmin()) {
            $this->json(['success' => false, 'message' => 'Sem permissão.'], 403);
        }
        if (!$this->validCsrf()) {
            $this->json(['success' => false, 'message' => 'Sessão expirada.'], 419);
        }

        try {
            $queued = $this->repository->retryJob($jobId, $tenantId);
            if (!$queued) {
                $this->json(['success' => false, 'message' => 'Somente jobs com falha podem ser reprocessados.'], 422);
            }
            AuditLogger::log('report_delivery.job_requeued', 'pacs_report_delivery_jobs', $jobId, ['tenant_id' => $tenantId]);
            $this->json(['success' => true, 'message' => 'Job reenfileirado para o worker.']);
        } catch (Throwable $e) {
            Logger::error('[ReportDeliveryController::retry] Falha ao reprocessar job', [
                'tenant_id' => $tenantId,
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            $this->json(['success' => false, 'message' => 'Não foi possível reenfileirar o job.'], 500);
        }
    }

    /** @return array<string, mixed> */
    private function validatedPayload(int $tenantId): array
    {
        $name = trim((string) ($_POST['nome'] ?? ''));
        $transport = (string) ($_POST['transport'] ?? '');
        $environment = (string) ($_POST['ambiente'] ?? 'homologacao');
        $configuration = trim((string) ($_POST['configuration_json'] ?? ''));
        $secret = trim((string) ($_POST['configuration_secret'] ?? ''));
        $enabled = !empty($_POST['enabled']) ? 1 : 0;
        $requestedInstitutions = $_POST['institution_names'] ?? [];
        $requestedInstitutions = is_array($requestedInstitutions) ? $requestedInstitutions : [];
        $institutionNames = [];
        foreach ($requestedInstitutions as $requestedInstitution) {
            $canonical = InstitutionResolverService::canonicalForTenant($tenantId, (string) $requestedInstitution);
            if ($canonical === null) {
                throw new DomainException('Selecione apenas InstitutionNames ativos deste negócio.');
            }
            $institutionNames[$canonical] = $canonical;
        }

        if (!$institutionNames) {
            throw new DomainException('Selecione ao menos um PACS de origem (InstitutionName) para o destino.');
        }

        if ($name === '' || mb_strlen($name) > 120) {
            throw new DomainException('Informe um nome de destino de até 120 caracteres.');
        }
        if (!in_array($transport, $this->transports, true)) {
            throw new DomainException('Tipo de transporte inválido.');
        }
        if (!in_array($environment, ['homologacao', 'producao'], true)) {
            throw new DomainException('Ambiente inválido.');
        }
        if ($enabled && $environment !== 'homologacao') {
            throw new DomainException('Por segurança, destinos de produção só podem ser habilitados após homologação controlada.');
        }
        if ($configuration === '') {
            $configuration = '{}';
        }
        $decoded = json_decode($configuration, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new DomainException('A configuração pública deve ser um JSON válido.');
        }
        $this->validateTransportConfiguration($transport, $decoded);
        if ($secret !== '') {
            $decodedSecret = json_decode($secret, true);
            if (!is_array($decodedSecret) || json_last_error() !== JSON_ERROR_NONE) {
                throw new DomainException('A configuração sensível deve ser um JSON válido.');
            }
            $secret = $this->crypto->encrypt($secret);
        }

        return [
            'nome' => $name,
            'transport' => $transport,
            'ambiente' => $environment,
            'enabled' => $enabled,
            'disparar_na_liberacao' => !empty($_POST['disparar_na_liberacao']) ? 1 : 0,
            'configuration_json' => json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'configuration_secret' => $secret,
            'timeout_seconds' => max(5, min(120, (int) ($_POST['timeout_seconds'] ?? 30))),
            'max_attempts' => max(1, min(10, (int) ($_POST['max_attempts'] ?? 5))),
            'institution_names' => array_values($institutionNames),
        ];
    }

    /** @param array<string,mixed> $configuration */
    private function validateTransportConfiguration(string $transport, array $configuration): void
    {
        $host = trim((string) ($configuration['host'] ?? ''));
        $port = (int) ($configuration['port'] ?? 0);
        $validHost = $host !== '' && strlen($host) <= 253 && preg_match('/^[a-zA-Z0-9.:-]+$/', $host);
        $validPort = $port >= 1 && $port <= 65535;

        if (in_array($transport, ['dicom_pdf', 'dicom_sr', 'hl7_oru', 'sftp'], true) && (!$validHost || !$validPort)) {
            throw new DomainException('Informe um endereço de servidor e uma porta válida para o destino.');
        }

        if (in_array($transport, ['dicom_pdf', 'dicom_sr'], true)) {
            foreach (['called_ae' => 'AE Title do PACS cliente', 'calling_ae' => 'AE Title do VOXEL PACS'] as $field => $label) {
                $value = trim((string) ($configuration[$field] ?? ''));
                if ($value === '' || strlen($value) > 16 || !preg_match('/^[a-zA-Z0-9 _.-]+$/', $value)) {
                    throw new DomainException("{$label} deve ter até 16 caracteres alfanuméricos.");
                }
            }
            return;
        }

        if ($transport === 'hl7_oru') {
            foreach (['sending_application', 'sending_facility', 'receiving_application', 'receiving_facility'] as $field) {
                $value = trim((string) ($configuration[$field] ?? ''));
                if ($value === '' || strlen($value) > 180) {
                    throw new DomainException('Preencha os identificadores de aplicação e instituição do HIS/RIS.');
                }
            }
            return;
        }

        if ($transport === 'https_webhook') {
            $url = trim((string) ($configuration['url'] ?? ''));
            if (!filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
                throw new DomainException('Informe uma URL HTTPS válida para o endpoint do cliente.');
            }
            if (!in_array((string) ($configuration['auth_type'] ?? 'none'), ['none', 'bearer'], true)) {
                throw new DomainException('Tipo de autenticação HTTPS inválido.');
            }
            return;
        }

        if ($transport === 'sftp') {
            $protocol = (string) ($configuration['protocol'] ?? 'sftp');
            $directory = trim((string) ($configuration['remote_directory'] ?? ''));
            $username = trim((string) ($configuration['username'] ?? ''));
            if (!in_array($protocol, ['sftp', 'ftps'], true) || $directory === '' || $directory[0] !== '/' || $username === '') {
                throw new DomainException('Informe protocolo seguro, pasta remota iniciando com / e usuário do destino.');
            }
        }
    }

    private function isPlatformAdmin(): bool
    {
        return Auth::check() && Auth::isPlatformAdmin();
    }

    private function validCsrf(): bool
    {
        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        $provided = (string) ($_POST['_csrf_token'] ?? '');

        return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }
}
