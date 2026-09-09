<?php
// Materialização de runtime do catálogo Downloads para publicação restrita.
namespace App\Controllers\Platform;

use App\Core\Audit\AuditLogger;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Logger;
use App\Services\DesktopDownloadService;
use DomainException;
use Throwable;

final class DownloadsController extends Controller
{
    private DesktopDownloadService $service;

    public function __construct() { $this->service = new DesktopDownloadService(); }

    public function index(): void
    {
        if (!$this->authorize()) return;
        $this->view('platform/downloads/index', ['title' => t('downloads.title'), 'packages' => $this->service->repository()->all(), 'csrfToken' => $_SESSION['csrf_token'] ?? ''], 'platform');
    }

    public function create(): void
    {
        if (!$this->authorize()) return;
        $this->view('platform/downloads/form', ['title' => t('downloads.new'), 'csrfToken' => $_SESSION['csrf_token'] ?? ''], 'platform');
    }

    public function store(): void
    {
        if (!$this->authorize() || !$this->csrf()) return;
        try {
            $id = $this->service->upload($_FILES['package'] ?? [], $_POST, (int) Auth::userId());
            $this->auditPackage('downloads.package_uploaded', $id);
            $_SESSION['success'] = t('downloads.saved_draft');
            $this->redirect('/platform/downloads/' . $id . '/statistics');
        } catch (DomainException $e) {
            $_SESSION['error'] = $this->messageFor($e->getMessage());
            $this->redirect('/platform/downloads/new');
        } catch (Throwable $e) {
            Logger::error('[DownloadsController::store] upload failed');
            $_SESSION['error'] = t('downloads.save_error');
            $this->redirect('/platform/downloads/new');
        }
    }

    public function publish(int $id): void
    {
        if (!$this->authorize() || !$this->csrf()) return;
        try { $this->service->publish($id, (int) Auth::userId()); $this->auditPackage('downloads.package_published', $id); $_SESSION['success'] = t('downloads.published'); }
        catch (DomainException $e) { $_SESSION['error'] = $this->messageFor($e->getMessage()); }
        catch (Throwable $e) { Logger::warning('[DownloadsController::publish] refused', ['package_id' => $id]); $_SESSION['error'] = t('downloads.action_error'); }
        $this->redirect('/platform/downloads');
    }

    public function archive(int $id): void
    {
        if (!$this->authorize() || !$this->csrf()) return;
        try { $this->service->archive($id, (int) Auth::userId()); $this->auditPackage('downloads.package_archived', $id); $_SESSION['success'] = t('downloads.archived'); }
        catch (DomainException $e) { $_SESSION['error'] = $this->messageFor($e->getMessage()); }
        catch (Throwable $e) { Logger::warning('[DownloadsController::archive] refused', ['package_id' => $id]); $_SESSION['error'] = t('downloads.action_error'); }
        $this->redirect('/platform/downloads');
    }

    public function stats(int $id): void
    {
        if (!$this->authorize()) return;
        $package = $this->service->repository()->find($id);
        if (!$package) { $_SESSION['error'] = t('downloads.not_found'); $this->redirect('/platform/downloads'); return; }
        $this->view('platform/downloads/stats', ['title' => t('downloads.statistics'), 'package' => $package, 'summary' => $this->service->repository()->stats($id)], 'platform');
    }

    private function authorize(): bool
    {
        if (Auth::check() && Auth::isPlatformAdmin() && !Auth::isImpersonating()) return true;
        $this->redirect('/login');
        return false;
    }

    private function csrf(): bool
    {
        if (hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['_csrf_token'] ?? ''))) return true;
        $_SESSION['error'] = t('downloads.csrf_error');
        $this->redirect('/platform/downloads');
        return false;
    }

    private function messageFor(string $code): string
    {
        return t('downloads.error.' . $code);
    }

    private function auditPackage(string $action, int $id): void
    {
        $package = $this->service->repository()->find($id);
        if (!$package) return;
        AuditLogger::log($action, 'bi_desktop_release_packages', $id, [
            'version' => (string) ($package['version_name'] ?? ''),
            'platform' => (string) ($package['platform'] ?? ''),
            'channel' => (string) ($package['channel'] ?? ''),
            'status' => (string) ($package['status'] ?? ''),
            'checksum_sha256' => (string) ($package['checksum_sha256'] ?? ''),
            'size_bytes' => (int) ($package['size_bytes'] ?? 0),
        ], null, 'platform');
    }
}
