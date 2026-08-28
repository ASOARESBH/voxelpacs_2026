<?php

namespace App\Controllers\Platform;

use App\Core\Access\ModuleAccess;
use App\Core\Access\ModuleCatalog;
use App\Core\Audit\AuditLogger;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\SqlHelper;
use App\Core\SystemConfig;
use App\Services\WorklistPreferenceService;

/** Controle global da aplicação, disponível exclusivamente ao superadmin. */
final class ModuloConfiguracoesController extends Controller
{
    private const REFRESH_DEFAULT_SECONDS = 60;
    private const REFRESH_MIN_SECONDS = 15;
    private const REFRESH_MAX_SECONDS = 600;

    public function index(): void
    {
        if (!$this->requirePlatformAdmin()) {
            return;
        }

        $refresh = $this->refreshConfig();
        $worklistDefaults = (new WorklistPreferenceService())->globalDefaults(true);
        $this->view('platform/configuracao_modulos/index', [
            'modules' => ModuleCatalog::all(),
            'states' => ModuleAccess::states(),
            'refresh' => $refresh,
            'refreshMin' => self::REFRESH_MIN_SECONDS,
            'refreshMax' => self::REFRESH_MAX_SECONDS,
            'worklistDefaults' => $worklistDefaults,
            'csrfToken' => $this->csrfToken(),
        ], 'platform');
    }

    public function salvarGlobal(): void
    {
        if (!$this->requirePlatformAdmin() || !$this->validCsrf()) {
            return;
        }

        $submitted = (array) ($_POST['global'] ?? []);
        $pdo = Database::getInstance();
        $sql = SqlHelper::isPostgres()
            ? 'INSERT INTO bi_system_module_config (module_key, globally_enabled, updated_by_user_id, updated_at) VALUES (?, ?, ?, NOW()) ON CONFLICT (module_key) DO UPDATE SET globally_enabled = EXCLUDED.globally_enabled, updated_by_user_id = EXCLUDED.updated_by_user_id, updated_at = NOW()'
            : 'INSERT INTO bi_system_module_config (module_key, globally_enabled, updated_by_user_id, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE globally_enabled = VALUES(globally_enabled), updated_by_user_id = VALUES(updated_by_user_id), updated_at = NOW()';
        $statement = $pdo->prepare($sql);

        foreach (ModuleCatalog::all() as $key => $_module) {
            $before = ModuleAccess::globalEnabled($key);
            $after = isset($submitted[$key]) && (string) $submitted[$key] === '1';
            if ($before === $after) {
                continue;
            }
            $statement->execute([$key, $after ? 1 : 0, Auth::userId()]);
            AuditLogger::logChange('plataforma.modulo_global_alterado', 'modulo', null,
                ['module_key' => $key, 'enabled' => $before], ['module_key' => $key, 'enabled' => $after], null, 'sistema');
        }

        $_SESSION['success'] = t('config_modulos.salvo');
        $this->redirect('/platform/configuracao-modulos');
    }

    public function salvarEstudos(): void
    {
        if (!$this->requirePlatformAdmin() || !$this->validCsrf()) {
            return;
        }

        $before = $this->refreshConfig();
        $preferenceService = new WorklistPreferenceService();
        $worklistBefore = $preferenceService->globalDefaults(true);
        $after = [
            'ativo' => isset($_POST['estudos_auto_refresh_ativo']),
            'segundos' => $this->normalizeSeconds($_POST['estudos_auto_refresh_segundos'] ?? self::REFRESH_DEFAULT_SECONDS),
        ];
        SystemConfig::setMany([
            'estudos_auto_refresh_ativo' => $after['ativo'] ? '1' : '0',
            'estudos_auto_refresh_segundos' => (string) $after['segundos'],
        ] + $preferenceService->globalValues((array) ($_POST['worklist'] ?? [])), Auth::userId());
        AuditLogger::logChange('plataforma.estudos_refresh_alterado', 'configuracao_global', null,
            $before, $after, null, 'sistema');
        AuditLogger::logChange('plataforma.estudos_ordenacao_alterada', 'configuracao_global', null,
            $worklistBefore, $preferenceService->globalDefaults(true), null, 'sistema');

        $_SESSION['success'] = t('config_modulos.salvo');
        $this->redirect('/platform/configuracao-modulos');
    }

    /** @return array{ativo: bool, segundos: int} */
    private function refreshConfig(): array
    {
        $stored = SystemConfig::getMany(['estudos_auto_refresh_ativo', 'estudos_auto_refresh_segundos']);
        return [
            'ativo' => ($stored['estudos_auto_refresh_ativo'] ?? '1') !== '0',
            'segundos' => $this->normalizeSeconds($stored['estudos_auto_refresh_segundos'] ?? self::REFRESH_DEFAULT_SECONDS),
        ];
    }

    private function normalizeSeconds(mixed $value): int
    {
        $seconds = filter_var($value, FILTER_VALIDATE_INT);
        if ($seconds === false) {
            return self::REFRESH_DEFAULT_SECONDS;
        }
        return max(self::REFRESH_MIN_SECONDS, min(self::REFRESH_MAX_SECONDS, (int) $seconds));
    }

    private function requirePlatformAdmin(): bool
    {
        if (Auth::check() && Auth::isPlatformAdmin()) {
            return true;
        }
        Logger::warning('Tentativa negada em Configuração Global de Módulos', ['user_id' => Auth::userId()]);
        $this->redirect('/login');
        return false;
    }

    private function validCsrf(): bool
    {
        $expected = (string) ($_SESSION['csrf_token'] ?? '');
        $provided = (string) ($_POST['_csrf_token'] ?? '');
        if ($expected !== '' && $provided !== '' && hash_equals($expected, $provided)) {
            return true;
        }
        $_SESSION['error'] = t('config_modulos.erro.sessao_expirada');
        $this->redirect('/platform/configuracao-modulos');
        return false;
    }
}
