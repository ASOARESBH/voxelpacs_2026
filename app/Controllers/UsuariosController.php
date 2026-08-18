<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Mailer;
use App\Core\SqlHelper;
use App\Core\TenantContext;

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

    // ─────────────────────────────────────────────────────────────────────────
    // LISTAGEM
    // ─────────────────────────────────────────────────────────────────────────
    public function index(): void
    {
        $tenantId = TenantContext::id();
        $pdo      = Database::getInstance();
        $usuarios = [];

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
                        m.crm      AS medico_crm
                    FROM bi_users u
                    INNER JOIN bi_user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = ?
                    LEFT  JOIN bi_medicos m ON m.tenant_id = ? AND m.usuario_id = u.id AND m.ativo = 1
                    ORDER BY {$perfilOrderSql}, u.name ASC
                ");
                $stmt->execute([$tenantId, $tenantId]);
                $usuarios = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                Logger::error('[UsuariosController::index] ' . $e->getMessage());
            }
        }

        $sucesso = $_GET['sucesso'] ?? '';
        $error   = $_GET['error']   ?? '';

        $this->view('usuarios/index', compact('usuarios','sucesso','error'), 'pacs');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FORMULÁRIO NOVO
    // ─────────────────────────────────────────────────────────────────────────
    public function create(): void
    {
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
            'medicos'      => $medicos,
            'modulos'      => self::MODULOS,
            'modPadrao'    => self::MODULOS_PADRAO,
            'title'        => 'Novo Usuário',
            'error'        => $_GET['error'] ?? '',
        ], 'pacs');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SALVAR NOVO
    // ─────────────────────────────────────────────────────────────────────────
    public function store(): void
    {
        $pdo      = Database::getInstance();
        $tenantId = TenantContext::id();

        $email    = strtolower(trim($_POST['email']   ?? ''));
        $name     = trim($_POST['name']               ?? '');
        $perfil   = $_POST['perfil']                  ?? 'viewer';
        $medicoId = (int)($_POST['medico_id']         ?? 0);
        $modulos  = $_POST['modulos']                 ?? (self::MODULOS_PADRAO[$perfil] ?? []);

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

            if ($medicoId > 0) {
                $this->vincularMedico($pdo, $medicoId, $userId, $tenantId);
            }

            $this->enviarLinkCriarSenha($pdo, $userId, $tenantId, $email, $name);

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
                'medicos'      => $medicos,
                'modulos'      => self::MODULOS,
                'modPadrao'    => self::MODULOS_PADRAO,
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
        $pdo      = Database::getInstance();
        $tenantId = TenantContext::id();

        $name     = trim($_POST['name']       ?? '');
        $perfil   = $_POST['perfil']          ?? 'viewer';
        $medicoId = (int)($_POST['medico_id'] ?? 0);
        $modulos  = $_POST['modulos']         ?? [];

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
    // REENVIAR LINK DE ACESSO
    // ─────────────────────────────────────────────────────────────────────────
    public function reenviarLink(int $id): void
    {
        $pdo      = Database::getInstance();
        $tenantId = TenantContext::id();

        try {
            $stmt = $pdo->prepare("SELECT name, email FROM bi_users WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($user) {
                $this->enviarLinkCriarSenha($pdo, $id, $tenantId, $user['email'], $user['name']);
            }
        } catch (\Throwable $e) {
            Logger::error('[UsuariosController::reenviarLink] ' . $e->getMessage());
        }

        $this->redirect('/usuarios?sucesso=link_reenviado');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS PRIVADOS
    // ─────────────────────────────────────────────────────────────────────────
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

    private function enviarLinkCriarSenha(\PDO $pdo, int $userId, int $tenantId, string $email, string $name): void
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

            Mailer::send($email, 'Acesso ao VOXEL PACS — Crie sua senha', $html);
            Logger::info("[UsuariosController::enviarLinkCriarSenha] user_id={$userId} email={$email}");

        } catch (\Throwable $e) {
            Logger::error('[UsuariosController::enviarLinkCriarSenha] ' . $e->getMessage());
        }
    }
}
