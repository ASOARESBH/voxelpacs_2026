<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\SqlHelper;
use App\Core\TenantContext;
use App\Core\Audit\AuditLogger;
use App\Core\Access\ViewerAccess;
use App\Core\Access\ViewerRegistry;
use App\Services\UserAccessMailService;
use App\Services\UserEmailChangeService;
use App\Services\WorklistPreferenceService;

/**
 * UsuariosController — Módulo de Usuários do Negócio (tenant), incluindo restrições opt-out de visualizadores.
 *
 * Perfis disponíveis (bi_user_tenants.perfil):
 *   admin      → acesso total ao negócio
 *   medico     → acessa worklist/laudos; vê apenas seus próprios estudos
 *   secretaria → acessa worklist e agendamentos; sem laudos/financeiro
 *   analista   → leitura de todos os módulos
 *   viewer     → somente leitura básica
 */
class UsuariosController extends Controller
{
    private const MODULOS = [
        'estudos'       => ['label' => 'Estudos / Worklist',  'icon' => 'fa-list-check'],
        'gestao_exames' => ['label' => 'Gestão de Exames',     'icon' => 'fa-clipboard-list'],
        'agendamentos'  => ['label' => 'Agendamentos',        'icon' => 'fa-calendar-days'],
        'imagens_dicom' => ['label' => 'Imagens DICOM',       'icon' => 'fa-x-ray'],
        'medicos'       => ['label' => 'Médicos',             'icon' => 'fa-user-doctor'],
        'usuarios'      => ['label' => 'Usuários',            'icon' => 'fa-users'],
        'configuracoes' => ['label' => 'Configurações',       'icon' => 'fa-gear'],
        'relatorios'    => ['label' => 'Relatórios',          'icon' => 'fa-chart-bar'],
        'sla'           => ['label' => 'SLA / Regras',        'icon' => 'fa-stopwatch'],
        'financeiro'    => ['label' => 'Financeiro',          'icon' => 'fa-dollar-sign'],
    ];

    private const MODULOS_PADRAO = [
        'admin'      => ['estudos','gestao_exames','agendamentos','imagens_dicom','medicos','usuarios','configuracoes','relatorios','sla','financeiro'],
        'medico'     => ['estudos','imagens_dicom'],
        'secretaria' => ['estudos','agendamentos','imagens_dicom'],
        'analista'   => ['estudos','agendamentos','imagens_dicom','medicos','relatorios','sla'],
        'viewer'     => ['estudos'],
    ];

    private const RELATORIO_SUBMODULOS = [
        'sla_medicos'       => ['label' => 'SLA Médicos',           'icon' => 'fa-gauge-high'],
        'auditoria_acesso'  => ['label' => 'Auditoria de Acesso',  'icon' => 'fa-right-to-bracket'],
        'auditoria_estudos' => ['label' => 'Gestão de Estudos',    'icon' => 'fa-clipboard-list'],
        'auditoria_clinica' => ['label' => 'Auditoria Clínica',    'icon' => 'fa-stethoscope'],
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // LISTAGEM
    // ─────────────────────────────────────────────────────────────────────────
    public function index(): void
    {
        $this->csrfToken();
        $tenantId = TenantContext::id();
        $pdo      = Database::getInstance();
        $usuarios = [];
        $canManageUsuarios = Auth::canManageTenantUsers();

        if ($tenantId) {
            try {
                $perfilOrderSql = SqlHelper::isPostgres()
                    ? "CASE ut.perfil
                           WHEN 'admin' THEN 1
                           WHEN 'medico' THEN 2
                           WHEN 'secretaria' THEN 3
                           WHEN 'analista' THEN 4
                           WHEN 'viewer' THEN 5
                           ELSE 99
                       END"
                    : "FIELD(ut.perfil,'admin','medico','secretaria','analista','viewer')";
                $stmt = $pdo->prepare("
                    SELECT
                        u.id,
                        u.name,
                        u.email,
                        u.status,
                        u.created_at,
                        u.ultimo_login,
                        ut.perfil,
                        ut.ativo   AS tenant_ativo,
                        m.id       AS medico_id,
                        m.nome     AS medico_nome,
                        m.crm      AS medico_crm,
                        COALESCE(tf.email_enabled, FALSE) AS two_factor_email_enabled
                    FROM bi_users u
                    INNER JOIN bi_user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = ?
                    LEFT  JOIN bi_medicos m ON m.tenant_id = ? AND m.usuario_id = u.id AND m.ativo = 1
                    LEFT  JOIN bi_user_two_factor_settings tf ON tf.tenant_id = ut.tenant_id AND tf.user_id = u.id
                    WHERE (? = 1 OR u.id = ?)
                    ORDER BY {$perfilOrderSql}, u.name ASC
                ");
                $stmt->execute([$tenantId, $tenantId, $canManageUsuarios ? 1 : 0, Auth::userId() ?: 0]);
                $usuarios = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                Logger::error('[UsuariosController::index] ' . $e->getMessage());
            }
        }

        $sucesso = $_GET['sucesso'] ?? '';
        $error   = $_GET['error']   ?? '';

        $this->view('usuarios/index', compact('usuarios','sucesso','error','canManageUsuarios'), 'pacs');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FORMULÁRIO NOVO
    // ─────────────────────────────────────────────────────────────────────────
    public function create(): void
    {
        if (!$this->requireUserManagement()) return;
        $this->csrfToken();

        $tenantId = TenantContext::id();
        $pdo      = Database::getInstance();
        $medicos  = [];

        if ($tenantId) {
            try {
                $stmt = $pdo->prepare(
                    "SELECT id, nome, crm FROM bi_medicos
                     WHERE tenant_id = ? AND ativo = 1 AND (usuario_id IS NULL OR usuario_id = 0)
                     ORDER BY nome"
                );
                $stmt->execute([$tenantId]);
                $medicos = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                Logger::error('[UsuariosController::create] medicos: ' . $e->getMessage());
            }
        }

        $viewerStates = ViewerAccess::statesForUser(0, (int) $tenantId, 'viewer');
        $this->view('usuarios/form', [
            'usuario'      => null,
            'modulosAtivos'=> [],
            'relatorioModulos' => [],
            'worklistPreference' => array_merge((new WorklistPreferenceService())->globalDefaults(false), ['enabled' => false, 'source' => 'global']),
            'medicos'      => $medicos,
            'modulos'      => self::MODULOS,
            'modPadrao'    => self::MODULOS_PADRAO,
            'relatorioSubmodulos' => self::RELATORIO_SUBMODULOS,
            'viewerCatalog' => ViewerRegistry::all(),
            'viewerStates' => $viewerStates,
            'title'        => 'Novo Usuário',
            'error'        => $_GET['error'] ?? '',
        ], 'pacs');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SALVAR NOVO
    // ─────────────────────────────────────────────────────────────────────────
    public function store(): void
    {
        if (!$this->requireUserManagement()) return;
        if (!$this->validCsrfPost()) {
            $this->redirect('/usuarios/create?error=erro_interno');
            return;
        }

        $pdo      = Database::getInstance();
        $tenantId = TenantContext::id();

        $email    = strtolower(trim($_POST['email']   ?? ''));
        $name     = trim($_POST['name']               ?? '');
        $perfil   = $_POST['perfil']                  ?? 'viewer';
        $medicoId = (int)($_POST['medico_id']         ?? 0);
        $modulos  = $_POST['modulos']                 ?? (self::MODULOS_PADRAO[$perfil] ?? []);
        $relatorioModulos = $_POST['relatorio_modulos'] ?? [];
        $visualizadores = isset($_POST['visualizadores_present'])
            ? (array) ($_POST['visualizadores'] ?? [])
            : array_keys(ViewerRegistry::all());

        if (!in_array($perfil, ['admin','medico','secretaria','analista','viewer'])) {
            $perfil = 'viewer';
        }

        if (!$email || !$name || !$tenantId) {
            $this->redirect('/usuarios/create?error=campos_obrigatorios');
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->redirect('/usuarios/create?error=email_invalido');
            return;
        }

        try {
            $pdo->beginTransaction();
            $check = $pdo->prepare("SELECT id FROM bi_users WHERE LOWER(email) = LOWER(?)");
            $check->execute([$email]);
            $userId = (int)$check->fetchColumn();

            if ($userId) {
                $chkTenant = $pdo->prepare(
                    "SELECT id FROM bi_user_tenants WHERE user_id = ? AND tenant_id = ?"
                );
                $chkTenant->execute([$userId, $tenantId]);
                if ($chkTenant->fetchColumn()) {
                    $pdo->rollBack();
                    $this->redirect('/usuarios/create?error=email_ja_cadastrado');
                    return;
                }
                $pdo->prepare(
                    "INSERT INTO bi_user_tenants (user_id, tenant_id, perfil, ativo) VALUES (?,?,?,1)"
                )->execute([$userId, $tenantId, $perfil]);
            } else {
                $senhaTemp = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                $pdo->prepare(
                    "INSERT INTO bi_users (name, email, password, role, status, created_at)
                     VALUES (?,?,?,'viewer','ativo',NOW())"
                )->execute([$name, $email, $senhaTemp]);
                $userId = (int)$pdo->lastInsertId();

                $pdo->prepare(
                    "INSERT INTO bi_user_tenants (user_id, tenant_id, perfil, ativo) VALUES (?,?,?,1)"
                )->execute([$userId, $tenantId, $perfil]);
            }

            $this->salvarPermissoes($pdo, $userId, $tenantId, $modulos);
            $this->salvarPermissoesRelatorios($pdo, $userId, $tenantId, $relatorioModulos, in_array('relatorios', $modulos, true));
            $this->salvarVisualizadores($pdo, $userId, (int) $tenantId, (array) $visualizadores, $perfil, 'viewer');

            if ($medicoId > 0) {
                $this->vincularMedico($pdo, $medicoId, $userId, $tenantId);
            }

            $pdo->commit();

            $mailResult = (new UserAccessMailService())->sendInvitation($pdo, $userId, $tenantId, $email, $name);
            AuditLogger::log('usuario.criado', 'bi_users', $userId, [
                'tenant_id' => $tenantId,
                'perfil' => $perfil,
                'invitation_result' => $mailResult['ok'] ? 'accepted' : 'failed',
            ], (int) $tenantId, 'acesso');

            Logger::info("[UsuariosController::store] user_id={$userId} tenant_id={$tenantId} perfil={$perfil}");
            $this->redirect('/usuarios?sucesso=' . ($mailResult['ok'] ? 'usuario_criado' : 'usuario_criado_email_falhou'));

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error('[UsuariosController::store] falha controlada', ['error_class' => get_class($e)]);
            $this->redirect('/usuarios/create?error=erro_interno');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FORMULÁRIO EDITAR
    // ─────────────────────────────────────────────────────────────────────────
    public function edit(int $id): void
    {
        if (!$this->requireUserManagement()) return;
        $this->csrfToken();

        $pdo      = Database::getInstance();
        $tenantId = TenantContext::id();

        try {
            $stmt = $pdo->prepare("
                SELECT u.id, u.name, u.email, u.email_pendente, u.email_pendente_solicitada_em,
                       u.role, u.status, u.created_at, u.ultimo_login,
                       ut.perfil, ut.ativo AS tenant_ativo,
                       m.id   AS medico_id,
                       m.nome AS medico_nome,
                       m.crm  AS medico_crm
                FROM bi_users u
                INNER JOIN bi_user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = ?
                LEFT  JOIN bi_medicos m ON m.tenant_id = ? AND m.usuario_id = u.id AND m.ativo = 1
                WHERE u.id = ?
            ");
            $stmt->execute([$tenantId, $tenantId, $id]);
            $usuario = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$usuario) {
                $this->redirect('/usuarios?error=nao_encontrado');
                return;
            }

            $stmtMod = $pdo->prepare(
                "SELECT modulo FROM bi_user_permissoes WHERE user_id = ? AND tenant_id = ?"
            );
            $stmtMod->execute([$id, $tenantId]);
            $modulosAtivos = $stmtMod->fetchAll(\PDO::FETCH_COLUMN);

            $stmtRel = $pdo->prepare('SELECT report_key FROM bi_user_report_permissions WHERE user_id = ? AND tenant_id = ?');
            $stmtRel->execute([$id, $tenantId]);
            $relatorioModulos = $stmtRel->fetchAll(\PDO::FETCH_COLUMN);

            $stmtMed = $pdo->prepare(
                "SELECT id, nome, crm FROM bi_medicos
                 WHERE tenant_id = ? AND ativo = 1
                   AND (usuario_id IS NULL OR usuario_id = 0 OR usuario_id = ?)
                 ORDER BY nome"
            );
            $stmtMed->execute([$tenantId, $id]);
            $medicos = $stmtMed->fetchAll(\PDO::FETCH_ASSOC);
            $worklistPreference = (new WorklistPreferenceService())->resolveForUser(
                $id,
                (int) $tenantId,
                ($usuario['perfil'] ?? '') === 'medico'
            );
            if (($worklistPreference['source'] ?? '') !== 'usuario') {
                $worklistPreference['enabled'] = false;
            }
            $viewerStates = ViewerAccess::statesForUser(
                (int) $id,
                (int) $tenantId,
                (string) ($usuario['perfil'] ?? 'viewer'),
                (string) ($usuario['role'] ?? '')
            );

            $this->view('usuarios/form', [
                'usuario'      => $usuario,
                'modulosAtivos'=> $modulosAtivos,
                'relatorioModulos' => $relatorioModulos,
                'worklistPreference' => $worklistPreference,
                'medicos'      => $medicos,
                'modulos'      => self::MODULOS,
                'modPadrao'    => self::MODULOS_PADRAO,
                'relatorioSubmodulos' => self::RELATORIO_SUBMODULOS,
                'viewerCatalog' => ViewerRegistry::all(),
                'viewerStates' => $viewerStates,
                'title'        => 'Editar Usuário',
                'error'        => $_GET['error'] ?? '',
            ], 'pacs');

        } catch (\Throwable $e) {
            Logger::error('[UsuariosController::edit] ' . $e->getMessage());
            $this->redirect('/usuarios?error=erro_interno');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ATUALIZAR
    // ─────────────────────────────────────────────────────────────────────────
    public function update(int $id): void
    {
        if (!$this->requireUserManagement()) return;
        if (!$this->validCsrfPost()) {
            $this->redirect('/usuarios/' . $id . '/edit?error=erro_interno');
            return;
        }

        $pdo      = Database::getInstance();
        $tenantId = TenantContext::id();
        if (!$this->usuarioPertenceAoTenant($pdo, $id, $tenantId)) {
            $this->redirect('/usuarios?error=nao_encontrado');
            return;
        }

        $name     = trim($_POST['name']       ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $perfil   = $_POST['perfil']          ?? 'viewer';
        $medicoId = (int)($_POST['medico_id'] ?? 0);
        $modulos  = $_POST['modulos']         ?? [];
        $relatorioModulos = $_POST['relatorio_modulos'] ?? [];
        $visualizadores = isset($_POST['visualizadores_present'])
            ? (array) ($_POST['visualizadores'] ?? [])
            : array_keys(ViewerRegistry::all());

        if (!in_array($perfil, ['admin','medico','secretaria','analista','viewer'])) {
            $perfil = 'viewer';
        }
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255)) {
            $this->redirect('/usuarios/' . $id . '/edit?error=email_invalido');
            return;
        }

        try {
            $stmtRole = $pdo->prepare('SELECT role, email FROM bi_users WHERE id = ? LIMIT 1');
            $stmtRole->execute([$id]);
            $target = $stmtRole->fetch(\PDO::FETCH_ASSOC) ?: [];
            $targetRole = (string) ($target['role'] ?? '');
            $currentEmail = strtolower(trim((string) ($target['email'] ?? '')));
            $visualizadoresAntes = ViewerAccess::disabledKeysForUser($id, (int) $tenantId);
            if ($name) {
                $pdo->prepare("UPDATE bi_users SET name = ? WHERE id = ?")->execute([$name, $id]);
            }

            $pdo->prepare(
                "UPDATE bi_user_tenants SET perfil = ? WHERE user_id = ? AND tenant_id = ?"
            )->execute([$perfil, $id, $tenantId]);

            $this->salvarPermissoes($pdo, $id, $tenantId, $modulos);
            $this->salvarPermissoesRelatorios($pdo, $id, $tenantId, $relatorioModulos, in_array('relatorios', $modulos, true));
            $visualizadoresDepois = $this->salvarVisualizadores(
                $pdo, $id, (int) $tenantId, (array) $visualizadores, $perfil, $targetRole
            );
            if ($visualizadoresAntes !== $visualizadoresDepois) {
                AuditLogger::log(
                    'auth.visualizadores_usuario_atualizados',
                    'bi_user_viewers',
                    $id,
                    ['visualizadores_desabilitados_antes' => $visualizadoresAntes, 'visualizadores_desabilitados_depois' => $visualizadoresDepois],
                    (int) $tenantId,
                    'acesso'
                );
            }
            $worklistPreference = (new WorklistPreferenceService())->saveForUser(
                $id,
                (int) $tenantId,
                (array) ($_POST['worklist_preferences'] ?? []),
                $perfil === 'medico',
                Auth::userId()
            );
            AuditLogger::log(
                'usuario.worklist_preferencia_atualizada',
                'bi_user_worklist_preferences',
                $id,
                ['tenant_id' => (int) $tenantId, 'source' => $worklistPreference['source']],
                (int) $tenantId
            );

            // Remove vínculo anterior com outro médico
            $pdo->prepare(
                "UPDATE bi_medicos SET usuario_id = NULL
                 WHERE tenant_id = ? AND usuario_id = ? AND id != ?"
            )->execute([$tenantId, $id, $medicoId ?: 0]);

            if ($medicoId > 0) {
                $this->vincularMedico($pdo, $medicoId, $id, $tenantId);
            }

            $emailResult = null;
            if ($email !== '' && $email !== $currentEmail) {
                $emailResult = (new UserEmailChangeService())->request(
                    $id,
                    (int) $tenantId,
                    $email,
                    Auth::isPlatformAdmin()
                );
                if (!$emailResult['ok'] && empty($emailResult['pending'])) {
                    $this->redirect('/usuarios/' . $id . '/edit?error=' . urlencode((string) ($emailResult['error'] ?? 'erro_interno')));
                    return;
                }
            }

            Logger::info("[UsuariosController::update] user_id={$id} tenant_id={$tenantId} perfil={$perfil}");
            if ($emailResult !== null && !$emailResult['ok'] && !empty($emailResult['pending'])) {
                $this->redirect('/usuarios?sucesso=email_alteracao_nao_enviada');
                return;
            }
            $this->redirect('/usuarios?sucesso=' . ($emailResult !== null && $emailResult['ok'] ? 'email_alteracao_solicitada' : 'usuario_atualizado'));

        } catch (\Throwable $e) {
            Logger::error('[UsuariosController::update] falha controlada', ['error_class' => get_class($e)]);
            $this->redirect('/usuarios/' . $id . '/edit?error=erro_interno');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TOGGLE STATUS
    // ─────────────────────────────────────────────────────────────────────────
    public function toggleStatus(int $id): void
    {
        if (!$this->requireUserManagement()) return;
        if (!$this->validCsrfPost()) {
            $this->redirect('/usuarios?error=erro_interno');
            return;
        }

        $pdo      = Database::getInstance();
        $tenantId = TenantContext::id();

        if ($id === Auth::userId()) {
            $this->redirect('/usuarios?error=nao_pode_desativar_proprio');
            return;
        }

        try {
            $current = $pdo->prepare('SELECT ativo FROM bi_user_tenants WHERE user_id = ? AND tenant_id = ? LIMIT 1');
            $current->execute([$id, $tenantId]);
            $next = !(bool) $current->fetchColumn();
            $pdo->prepare(
                "UPDATE bi_user_tenants SET ativo = ?
                 WHERE user_id = ? AND tenant_id = ?"
            )->execute([$next ? 1 : 0, $id, $tenantId]);
            AuditLogger::log(
                $next ? 'usuario.ativado' : 'usuario.desativado',
                'bi_user_tenants',
                $id,
                ['tenant_id' => $tenantId, 'ativo' => $next],
                (int) $tenantId,
                'acesso'
            );
        } catch (\Throwable $e) {
            Logger::error('[UsuariosController::toggleStatus] falha controlada', ['error_class' => get_class($e)]);
        }

        $this->redirect('/usuarios');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TOGGLE 2F POR E-MAIL — tenant-scoped e protegido por CSRF
    // ─────────────────────────────────────────────────────────────────────────
    public function toggleTwoFactor(int $id): void
    {
        if (!$this->requireUserManagement()) return;
        if (!$this->validCsrfPost()) {
            $this->redirect('/usuarios?error=erro_interno');
            return;
        }

        $tenantId = TenantContext::id();
        $pdo = Database::getInstance();
        if (!$tenantId || !$this->usuarioPertenceAoTenant($pdo, $id, $tenantId)) {
            $this->redirect('/usuarios?error=nao_encontrado');
            return;
        }

        try {
            $stmt = $pdo->prepare('SELECT COALESCE(email_enabled, FALSE) FROM bi_user_two_factor_settings WHERE tenant_id = ? AND user_id = ?');
            $stmt->execute([$tenantId, $id]);
            $enabled = (bool) $stmt->fetchColumn();
            $next = !$enabled;
            $pdo->prepare('INSERT INTO bi_user_two_factor_settings (tenant_id, user_id, email_enabled, changed_by_user_id) VALUES (?,?,?,?) ON CONFLICT (tenant_id, user_id) DO UPDATE SET email_enabled = EXCLUDED.email_enabled, changed_by_user_id = EXCLUDED.changed_by_user_id, updated_at = NOW()')
                ->execute([$tenantId, $id, $next, Auth::userId()]);

            AuditLogger::log($next ? 'usuario.2fa_habilitado' : 'usuario.2fa_desabilitado', 'bi_users', $id, ['tenant_id' => $tenantId], $tenantId);
            Logger::info('[UsuariosController::toggleTwoFactor] configuração atualizada', ['user_id' => $id, 'tenant_id' => $tenantId, 'enabled' => $next]);
            $this->redirect('/usuarios?sucesso=' . ($next ? '2fa_habilitado' : '2fa_desabilitado'));
        } catch (\Throwable $e) {
            Logger::error('[UsuariosController::toggleTwoFactor] ' . $e->getMessage());
            $this->redirect('/usuarios?error=erro_interno');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REENVIAR LINK DE ACESSO
    // ─────────────────────────────────────────────────────────────────────────
    public function reenviarLink(int $id): void
    {
        if (!$this->requireUserManagement()) return;
        if (!$this->validCsrfPost()) {
            $this->redirect('/usuarios?error=erro_interno');
            return;
        }

        $pdo      = Database::getInstance();
        $tenantId = TenantContext::id();

        try {
            $stmt = $pdo->prepare(
                "SELECT u.name, u.email
                 FROM bi_users u
                 INNER JOIN bi_user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = ?
                 WHERE u.id = ?"
            );
            $stmt->execute([$tenantId, $id]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$user) {
                $this->redirect('/usuarios?error=nao_encontrado');
                return;
            }
            $result = (new UserAccessMailService())->sendInvitation($pdo, $id, (int) $tenantId, (string) $user['email'], (string) $user['name']);
            AuditLogger::log('usuario.link_reenviado', 'bi_users', $id, [
                'tenant_id' => $tenantId,
                'result' => $result['ok'] ? 'accepted' : 'failed',
            ], (int) $tenantId, 'acesso');
            $this->redirect('/usuarios?' . ($result['ok'] ? 'sucesso=link_reenviado' : 'error=link_nao_enviado'));
            return;
        } catch (\Throwable $e) {
            Logger::error('[UsuariosController::reenviarLink] falha controlada', ['error_class' => get_class($e)]);
            $this->redirect('/usuarios?error=link_nao_enviado');
            return;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS PRIVADOS
    // ─────────────────────────────────────────────────────────────────────────
    private function requireUserManagement(): bool
    {
        if (Auth::canManageTenantUsers()) {
            return true;
        }

        Logger::error('[UsuariosController] Operação administrativa negada', [
            'user_id' => Auth::userId(),
            'tenant_id' => TenantContext::id(),
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
        ]);
        $this->redirect('/usuarios?error=acesso_negado');
        return false;
    }

    private function usuarioPertenceAoTenant(\PDO $pdo, int $userId, ?int $tenantId): bool
    {
        if (!$tenantId) {
            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT 1 FROM bi_user_tenants WHERE user_id = ? AND tenant_id = ? LIMIT 1'
        );
        $stmt->execute([$userId, $tenantId]);
        return (bool) $stmt->fetchColumn();
    }

    private function validCsrfPost(): bool
    {
        $csrf = (string) ($_POST['_csrf_token'] ?? '');
        return $csrf !== '' && !empty($_SESSION['csrf_token']) && hash_equals((string) $_SESSION['csrf_token'], $csrf);
    }

    private function salvarPermissoes(\PDO $pdo, int $userId, int $tenantId, array $modulos): void
    {
        $pdo->prepare(
            "DELETE FROM bi_user_permissoes WHERE user_id = ? AND tenant_id = ?"
        )->execute([$userId, $tenantId]);

        if (empty($modulos)) return;

        $sql = \App\Core\SqlHelper::isPostgres()
            ? 'INSERT INTO bi_user_permissoes (user_id, tenant_id, modulo) VALUES (?,?,?) ON CONFLICT DO NOTHING'
            : 'INSERT IGNORE INTO bi_user_permissoes (user_id, tenant_id, modulo) VALUES (?,?,?)';
        $ins = $pdo->prepare($sql);
        foreach ($modulos as $modulo) {
            $modulo = trim((string)$modulo);
            if ($modulo === '' || !isset(self::MODULOS[$modulo])) continue;
            $ins->execute([$userId, $tenantId, $modulo]);
        }
    }

    private function salvarPermissoesRelatorios(\PDO $pdo, int $userId, int $tenantId, array $chaves, bool $relatoriosAtivo): void
    {
        $pdo->prepare('DELETE FROM bi_user_report_permissions WHERE user_id = ? AND tenant_id = ?')->execute([$userId, $tenantId]);
        if (!$relatoriosAtivo) return;
        $insert = $pdo->prepare('INSERT INTO bi_user_report_permissions (tenant_id, user_id, report_key, granted_by_user_id) VALUES (?,?,?,?) ON CONFLICT (tenant_id, user_id, report_key) DO NOTHING');
        foreach ($chaves as $chave) {
            $chave = (string) $chave;
            if (!isset(self::RELATORIO_SUBMODULOS[$chave])) continue;
            $insert->execute([$tenantId, $userId, $chave, Auth::userId()]);
        }
    }

    /** @return string[] Chaves desabilitadas persistidas após o salvamento. */
    private function salvarVisualizadores(
        \PDO $pdo,
        int $userId,
        int $tenantId,
        array $selecionados,
        string $perfil,
        ?string $role
    ): array {
        // A migration é aditiva; enquanto não aplicada, preserva o comportamento
        // legado sem impedir o cadastro ou a edição de usuários.
        // Não permita que um search_path legado silencie uma exceção individual.
        if (!ViewerAccess::restrictionStoreAvailable($pdo)) return [];
        $selecionados = array_values(array_unique(array_filter(
            array_map('strval', $selecionados),
            static fn (string $key): bool => ViewerRegistry::has($key)
        )));
        $privilegiado = ViewerAccess::isPrivilegedTarget($perfil, $role);
        if ($privilegiado) {
            $desabilitados = [];
        } else {
            $estados = ViewerAccess::statesForUser($userId, $tenantId, $perfil, $role);
            $editaveis = array_keys(array_filter(
                $estados,
                static fn (array $estado): bool => !empty($estado['editable'])
            ));
            $atuais = ViewerAccess::disabledKeysForUser($userId, $tenantId);
            // Itens cinza não são enviados pelo browser. Preservamos sua regra
            // anterior para que a indisponibilidade de infraestrutura do tenant
            // nunca cause mudança implícita na política individual.
            $preservados = array_values(array_diff($atuais, $editaveis));
            $desabilitados = array_values(array_unique(array_merge(
                $preservados,
                array_values(array_diff($editaveis, $selecionados))
            )));
        }

        // Modelo opt-out: apagar a configuração anterior restaura o padrão
        // habilitado; gravamos somente as exceções explicitamente desmarcadas.
        $table = ViewerAccess::restrictionStoreTable();
        $pdo->prepare("DELETE FROM {$table} WHERE user_id = ? AND tenant_id = ?")
            ->execute([$userId, $tenantId]);
        if (!$desabilitados) return [];

        $sql = SqlHelper::isPostgres()
            ? "INSERT INTO {$table} (user_id, tenant_id, viewer_key, habilitado, updated_by_user_id) VALUES (?,?,?,?,?) ON CONFLICT (user_id, tenant_id, viewer_key) DO UPDATE SET habilitado = EXCLUDED.habilitado, updated_by_user_id = EXCLUDED.updated_by_user_id, updated_at = NOW()"
            : "INSERT INTO {$table} (user_id, tenant_id, viewer_key, habilitado, updated_by_user_id) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE habilitado = VALUES(habilitado), updated_by_user_id = VALUES(updated_by_user_id), updated_at = CURRENT_TIMESTAMP";
        $insert = $pdo->prepare($sql);
        foreach ($desabilitados as $viewerKey) {
            $insert->execute([$userId, $tenantId, $viewerKey, 0, Auth::userId()]);
        }
        return $desabilitados;
    }

    private function vincularMedico(\PDO $pdo, int $medicoId, int $userId, int $tenantId): void
    {
        $stmt = $pdo->prepare(
            "SELECT id FROM bi_medicos WHERE id = ? AND tenant_id = ? AND ativo = 1"
        );
        $stmt->execute([$medicoId, $tenantId]);
        if (!$stmt->fetchColumn()) return;

        $pdo->prepare(
            "UPDATE bi_medicos SET usuario_id = ? WHERE id = ? AND tenant_id = ?"
        )->execute([$userId, $medicoId, $tenantId]);
    }

}
