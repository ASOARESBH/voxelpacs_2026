<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Mailer;
use App\Core\SqlHelper;
use App\Core\TenantContext;
use App\Core\Audit\AuditLogger;

/**
 * UsuariosController — Módulo de Usuários do Negócio (tenant)
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
        'admin'      => ['estudos','agendamentos','imagens_dicom','medicos','usuarios','configuracoes','relatorios','sla','financeiro'],
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

        $this->view('usuarios/form', [
            'usuario'      => null,
            'modulosAtivos'=> [],
            'relatorioModulos' => [],
            'medicos'      => $medicos,
            'modulos'      => self::MODULOS,
            'modPadrao'    => self::MODULOS_PADRAO,
            'relatorioSubmodulos' => self::RELATORIO_SUBMODULOS,
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

        $pdo      = Database::getInstance();
        $tenantId = TenantContext::id();

        $email    = strtolower(trim($_POST['email']   ?? ''));
        $name     = trim($_POST['name']               ?? '');
        $perfil   = $_POST['perfil']                  ?? 'viewer';
        $medicoId = (int)($_POST['medico_id']         ?? 0);
        $modulos  = $_POST['modulos']                 ?? (self::MODULOS_PADRAO[$perfil] ?? []);
        $relatorioModulos = $_POST['relatorio_modulos'] ?? [];

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
            $check = $pdo->prepare("SELECT id FROM bi_users WHERE email = ?");
            $check->execute([$email]);
            $userId = (int)$check->fetchColumn();

            if ($userId) {
                $chkTenant = $pdo->prepare(
                    "SELECT id FROM bi_user_tenants WHERE user_id = ? AND tenant_id = ?"
                );
                $chkTenant->execute([$userId, $tenantId]);
                if ($chkTenant->fetchColumn()) {
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

            if ($medicoId > 0) {
                $this->vincularMedico($pdo, $medicoId, $userId, $tenantId);
            }

            if (!$this->enviarLinkCriarSenha($pdo, $userId, $tenantId, $email, $name)) {
                Logger::warning("[UsuariosController::store] conta criada, mas o SMTP recusou o convite para user_id={$userId}");
            }

            Logger::info("[UsuariosController::store] user_id={$userId} tenant_id={$tenantId} perfil={$perfil}");
            $this->redirect('/usuarios?sucesso=usuario_criado');

        } catch (\Throwable $e) {
            Logger::error('[UsuariosController::store] ' . $e->getMessage());
            $this->redirect('/usuarios/create?error=erro_interno');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FORMULÁRIO EDITAR
    // ─────────────────────────────────────────────────────────────────────────
    public function edit(int $id): void
    {
        if (!$this->requireUserManagement()) return;

        $pdo      = Database::getInstance();
        $tenantId = TenantContext::id();

        try {
            $stmt = $pdo->prepare("
                SELECT u.id, u.name, u.email, u.status, u.created_at, u.ultimo_login,
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

            $this->view('usuarios/form', [
                'usuario'      => $usuario,
                'modulosAtivos'=> $modulosAtivos,
                'relatorioModulos' => $relatorioModulos,
                'medicos'      => $medicos,
                'modulos'      => self::MODULOS,
                'modPadrao'    => self::MODULOS_PADRAO,
                'relatorioSubmodulos' => self::RELATORIO_SUBMODULOS,
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

        $pdo      = Database::getInstance();
        $tenantId = TenantContext::id();
        if (!$this->usuarioPertenceAoTenant($pdo, $id, $tenantId)) {
            $this->redirect('/usuarios?error=nao_encontrado');
            return;
        }

        $name     = trim($_POST['name']       ?? '');
        $perfil   = $_POST['perfil']          ?? 'viewer';
        $medicoId = (int)($_POST['medico_id'] ?? 0);
        $modulos  = $_POST['modulos']         ?? [];
        $relatorioModulos = $_POST['relatorio_modulos'] ?? [];

        if (!in_array($perfil, ['admin','medico','secretaria','analista','viewer'])) {
            $perfil = 'viewer';
        }

        try {
            if ($name) {
                $pdo->prepare("UPDATE bi_users SET name = ? WHERE id = ?")->execute([$name, $id]);
            }

            $pdo->prepare(
                "UPDATE bi_user_tenants SET perfil = ? WHERE user_id = ? AND tenant_id = ?"
            )->execute([$perfil, $id, $tenantId]);

            $this->salvarPermissoes($pdo, $id, $tenantId, $modulos);
            $this->salvarPermissoesRelatorios($pdo, $id, $tenantId, $relatorioModulos, in_array('relatorios', $modulos, true));

            // Remove vínculo anterior com outro médico
            $pdo->prepare(
                "UPDATE bi_medicos SET usuario_id = NULL
                 WHERE tenant_id = ? AND usuario_id = ? AND id != ?"
            )->execute([$tenantId, $id, $medicoId ?: 0]);

            if ($medicoId > 0) {
                $this->vincularMedico($pdo, $medicoId, $id, $tenantId);
            }

            Logger::info("[UsuariosController::update] user_id={$id} tenant_id={$tenantId} perfil={$perfil}");
            $this->redirect('/usuarios?sucesso=usuario_atualizado');

        } catch (\Throwable $e) {
            Logger::error('[UsuariosController::update] ' . $e->getMessage());
            $this->redirect('/usuarios/' . $id . '/edit?error=erro_interno');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TOGGLE STATUS
    // ─────────────────────────────────────────────────────────────────────────
    public function toggleStatus(int $id): void
    {
        if (!$this->requireUserManagement()) return;

        $pdo      = Database::getInstance();
        $tenantId = TenantContext::id();

        if ($id === Auth::userId()) {
            $this->redirect('/usuarios?error=nao_pode_desativar_proprio');
            return;
        }

        try {
            $pdo->prepare(
                "UPDATE bi_user_tenants SET ativo = CASE WHEN ativo = 1 THEN 0 ELSE 1 END
                 WHERE user_id = ? AND tenant_id = ?"
            )->execute([$id, $tenantId]);
        } catch (\Throwable $e) {
            Logger::error('[UsuariosController::toggleStatus] ' . $e->getMessage());
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
            if ($user) {
                if (!$this->enviarLinkCriarSenha($pdo, $id, $tenantId, $user['email'], $user['name'])) {
                    Logger::warning("[UsuariosController::reenviarLink] SMTP recusou o convite para user_id={$id}");
                }
            }
        } catch (\Throwable $e) {
            Logger::error('[UsuariosController::reenviarLink] ' . $e->getMessage());
        }

        $this->redirect('/usuarios?sucesso=link_reenviado');
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

    private function enviarLinkCriarSenha(\PDO $pdo, int $userId, int $tenantId, string $email, string $name): bool
    {
        try {
            $pdo->prepare(
                "UPDATE bi_tenant_access_tokens SET usado = 1
                 WHERE user_id = ? AND tenant_id = ? AND usado = 0 AND tipo = 'criar_senha'"
            )->execute([$userId, $tenantId]);

            $token = bin2hex(random_bytes(32));
            $pdo->prepare(
                "INSERT INTO bi_tenant_access_tokens
                    (user_id, tenant_id, token, tipo, usado, expires_at)
                 VALUES (?,?,?,'criar_senha',0,?)"
            )->execute([
                $userId,
                $tenantId,
                $token,
                date('Y-m-d H:i:s', strtotime('+48 hours')),
            ]);

            $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'server.voxelpacs.com.br');
            $link    = $baseUrl . '/acesso/criar-senha/' . $token;

            $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;">'
                . '<h2 style="color:#0a1628;">Bem-vindo ao VOXEL PACS</h2>'
                . '<p>Olá, <strong>' . htmlspecialchars($name) . '</strong>!</p>'
                . '<p>Sua conta foi criada no VOXEL PACS. Clique no botão abaixo para definir sua senha:</p>'
                . '<p style="text-align:center;margin:2rem 0;">'
                . '<a href="' . htmlspecialchars($link) . '" '
                . 'style="background:#4fc3f7;color:#0a1628;padding:.75rem 2rem;border-radius:8px;text-decoration:none;font-weight:700;">'
                . 'Criar minha senha</a></p>'
                . '<p style="color:#64748b;font-size:.85rem;">Link válido por 48 horas. Use apenas uma vez.</p>'
                . '</div>';

            if (Mailer::send($email, 'Acesso ao VOXEL PACS — Crie sua senha', $html)) {
                Logger::info("[UsuariosController::enviarLinkCriarSenha] SMTP aceitou o convite para user_id={$userId}");
                return true;
            }

            Logger::warning("[UsuariosController::enviarLinkCriarSenha] SMTP recusou o convite para user_id={$userId}");
            return false;

        } catch (\Throwable $e) {
            Logger::error('[UsuariosController::enviarLinkCriarSenha] ' . $e->getMessage());
            return false;
        }
    }
}
