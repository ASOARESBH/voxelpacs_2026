<?php
/**
 * UnidadesController — Gestão de Unidades (bi_negocio_institution_names)
 * Unidades são identificadas pelo InstitutionName DICOM (0008,0080).
 * Aparecem automaticamente quando um estudo novo chega.
 * institution_name é somente leitura — admin completa dados complementares.
 */
namespace App\Controllers;

use App\Core\Access\MedicoAccess;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Services\CnpjLookupService;
use App\Services\ReportLayoutService;

class UnidadesController extends Controller
{
    private const UPLOAD_BASE   = __DIR__ . '/../../public/uploads/unidades';
    private const UPLOAD_MAX_MB = 2;
    private const LOGO_TYPES    = ['image/png', 'image/jpeg', 'image/svg+xml'];
    private const LOGO_EXTS     = ['png', 'jpg', 'jpeg', 'svg'];

    private \PDO $pdo;
    private ReportLayoutService $reportLayoutService;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->reportLayoutService = new ReportLayoutService();
    }

    /** Impede que médico vinculado entre na gestão de unidades por URL direta. */
    private function denyIfRestricted(): void
    {
        if (!MedicoAccess::isRestricted()) return;

        Logger::error('[UnidadesController] Tentativa de acesso à gestão de unidades por médico restrito', [
            'tenant_id' => TenantContext::id() ?? Auth::tenantId(),
            'user_id' => Auth::userId(),
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
        ]);
        http_response_code(403);
        exit('Acesso negado: médicos não possuem acesso à gestão de unidades.');
    }

    /** Versão JSON da regra para APIs administrativas de unidades. */
    private function denyIfRestrictedJson(): bool
    {
        if (!MedicoAccess::isRestricted()) return false;

        Logger::error('[UnidadesController] Tentativa de API administrativa de unidades por médico restrito', [
            'tenant_id' => TenantContext::id() ?? Auth::tenantId(),
            'user_id' => Auth::userId(),
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
        ]);
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'Acesso negado: médicos não possuem acesso à gestão de unidades.']);
        return true;
    }

    // INDEX
    public function index(): void
    {
        $this->denyIfRestricted();
        $tenantId = TenantContext::id() ?? Auth::tenantId();
        if (!$tenantId) { header('Location: /selecionar-empresa'); exit; }

        $this->sincronizarInstitutionNames($tenantId);

        try {
            // Colunas explícitas + COALESCE para colunas opcionais (migrations podem não ter sido aplicadas ainda)
            $stmt = $this->pdo->prepare("
                SELECT
                    n.id,
                    n.tenant_id,
                    n.institution_name,
                    COALESCE(n.descricao,   '')  AS descricao,
                    COALESCE(n.responsavel, '')  AS responsavel,
                    COALESCE(n.cidade,      '')  AS cidade,
                    COALESCE(n.estado,      '')  AS estado,
                    COALESCE(n.telefone,    '')  AS telefone,
                    COALESCE(n.email,       '')  AS email,
                    COALESCE(n.cnpj,        '')  AS cnpj,
                    COALESCE(n.horario,     '')  AS horario,
                    COALESCE(n.sla_minutos, 0)   AS sla_minutos,
                    COALESCE(n.ativo,       1)   AS ativo,
                    n.created_at,
                    (SELECT COUNT(*) FROM bi_pacs_estudos e
                     WHERE e.tenant_id = n.tenant_id
                       AND e.institution_name COLLATE utf8mb4_general_ci = n.institution_name COLLATE utf8mb4_general_ci) AS total_estudos
                FROM bi_negocio_institution_names n
                WHERE n.tenant_id = ?
                  AND (n.excluido_manualmente = 0 OR n.excluido_manualmente IS NULL)
                ORDER BY n.institution_name ASC
            ");
            $stmt->execute([$tenantId]);
            $unidades = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Logger::error('[UnidadesController::index] ' . $e->getMessage(), ['tenant_id' => $tenantId]);
            $unidades = [];
        }

                // ── bi_unidades: entidade rica com CNPJ, endereço, logo ─────────────
        $biUnidades = [];
        try {
            $stmt2 = $this->pdo->prepare("
                SELECT
                    u.id, u.tenant_id,
                    COALESCE(u.razao_social,  '') AS razao_social,
                    COALESCE(u.nome_fantasia, '') AS nome_fantasia,
                    COALESCE(u.cnpj,          '') AS cnpj,
                    COALESCE(u.cidade,        '') AS cidade,
                    COALESCE(u.estado,        '') AS estado,
                    COALESCE(u.logo_path,     '') AS logo_path,
                    COALESCE(u.copilot_logo_url, '') AS copilot_logo_url,
                    COALESCE(u.ativo,          1) AS ativo,
                    GROUP_CONCAT(n.institution_name ORDER BY n.institution_name SEPARATOR '|') AS institution_names
                FROM bi_unidades u
                LEFT JOIN bi_negocio_institution_names n
                    ON n.unidade_id = u.id AND n.tenant_id = u.tenant_id
                WHERE u.tenant_id = ?
                GROUP BY u.id
                ORDER BY COALESCE(u.nome_fantasia, u.razao_social) ASC
            ");
            $stmt2->execute([$tenantId]);
            $biUnidades = $stmt2->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Logger::error('[UnidadesController::index] bi_unidades: ' . $e->getMessage());
        }

        // Enriquecer institution_names com nome da bi_unidade vinculada
        $unidades = array_map(function($u) use ($biUnidades) {
            $u['bi_unidade_nome'] = null;
            foreach ($biUnidades as $bu) {
                $nomes = !empty($bu['institution_names']) ? explode('|', $bu['institution_names']) : [];
                if (in_array($u['institution_name'], array_map('trim', $nomes))) {
                    $u['bi_unidade_nome'] = $bu['nome_fantasia'] ?: $bu['razao_social'];
                    break;
                }
            }
            return $u;
        }, $unidades);

        $this->view('unidades/index', compact('unidades', 'biUnidades'));
    }
    // EDIT
    public function edit(int $id): void
    {
        $this->denyIfRestricted();
        $tenantId = TenantContext::id() ?? Auth::tenantId();
        $unidade  = $this->findOrFail($id, $tenantId);
        $templatesLaudo = $this->reportLayoutService->listarCatalogo();
        $this->view('unidades/edit', compact('unidade', 'templatesLaudo'));
    }

    // UPDATE
    public function update(int $id): void
    {
        $this->denyIfRestricted();
        $tenantId = TenantContext::id() ?? Auth::tenantId();
        $this->findOrFail($id, $tenantId);

        $campos = [
            'descricao'              => trim($_POST['descricao']              ?? ''),
            'responsavel'            => trim($_POST['responsavel']            ?? ''),
            'cnpj'                   => preg_replace('/\D/', '', $_POST['cnpj'] ?? ''),
            'razao_social'           => trim($_POST['razao_social']           ?? ''),
            'nome_fantasia'          => trim($_POST['nome_fantasia']          ?? ''),
            'logradouro'             => trim($_POST['logradouro']             ?? ''),
            'numero'                 => trim($_POST['numero']                 ?? ''),
            'complemento'            => trim($_POST['complemento']            ?? ''),
            'bairro'                 => trim($_POST['bairro']                 ?? ''),
            'cidade'                 => trim($_POST['cidade']                 ?? ''),
            'estado'                 => strtoupper(trim($_POST['estado']      ?? '')),
            'cep'                    => preg_replace('/\D/', '', $_POST['cep'] ?? ''),
            'telefone'               => trim($_POST['telefone']               ?? ''),
            'email'                  => trim($_POST['email']                  ?? ''),
            'horario'                => trim($_POST['horario']                ?? ''),
            'sla_minutos'            => (int)($_POST['sla_minutos'] ?? 0) ?: null,
            'modalidades_permitidas' => trim($_POST['modalidades_permitidas'] ?? ''),
            'observacoes'            => trim($_POST['observacoes']            ?? ''),
            'report_layout_template_id' => (int) ($_POST['report_layout_template_id'] ?? 0) ?: null,
            'ativo'                  => isset($_POST['ativo']) ? 1 : 0,
        ];

        // Upload de logo
        if (!empty($_FILES['logo']['name'])) {
            $logoResult = $this->processarLogoUpload($_FILES['logo'], $id, $tenantId);
            if (!$logoResult['ok']) {
                $_SESSION['error'] = $logoResult['msg'];
                header("Location: /unidades/{$id}/edit");
                exit;
            }
            $campos['logo_path'] = $logoResult['path'];
        }

        try {
            $set    = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($campos)));
            $params = array_values($campos);
            $params[] = $id;
            $params[] = $tenantId;

            $this->pdo->prepare("
                UPDATE bi_negocio_institution_names
                SET {$set}
                WHERE id = ? AND tenant_id = ?
            ")->execute($params);

            Logger::error('[UnidadesController::update] Unidade atualizada', ['id' => $id, 'tenant_id' => $tenantId]);
            $_SESSION['success'] = 'Unidade atualizada com sucesso!';
        } catch (\Throwable $e) {
            Logger::error('[UnidadesController::update] ERRO: ' . $e->getMessage(), ['id' => $id]);
            $_SESSION['error'] = 'Erro ao atualizar unidade. Tente novamente.';
        }

        header('Location: /unidades');
        exit;
    }

    // API: Busca CNPJ (AJAX)
    // GET /api/unidades/cnpj?cnpj=12345678000195&unit_id=5&force=0
    public function apiCnpj(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        if ($this->denyIfRestrictedJson()) return;

        $tenantId = TenantContext::id() ?? Auth::tenantId();
        if (!Auth::check() || !$tenantId) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'msg' => 'Não autenticado.']);
            return;
        }

        $cnpjRaw = preg_replace('/\D/', '', $_GET['cnpj'] ?? '');
        $force   = !empty($_GET['force']);
        $unitId  = (int)($_GET['unit_id'] ?? 0);
        $svc     = new CnpjLookupService();

        if (!$svc->validarCnpj($cnpjRaw)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'msg' => 'CNPJ inválido. Verifique o número.']);
            return;
        }

        // Verificar cache
        if (!$force && $unitId > 0) {
            try {
                $stmt = $this->pdo->prepare("
                    SELECT cnpj_cache_json, cnpj_cache_at
                    FROM bi_negocio_institution_names
                    WHERE id = ? AND tenant_id = ? AND cnpj = ? LIMIT 1
                ");
                $stmt->execute([$unitId, $tenantId, $cnpjRaw]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row && !empty($row['cnpj_cache_json']) && !empty($row['cnpj_cache_at'])) {
                    if ((time() - strtotime($row['cnpj_cache_at'])) < 86400 * 7) {
                        $cached = json_decode($row['cnpj_cache_json'], true);
                        if (is_array($cached)) {
                            $cached['_from_cache'] = true;
                            echo json_encode(['ok' => true, 'data' => $cached]);
                            return;
                        }
                    }
                }
            } catch (\Throwable $e) {
                Logger::error('[UnidadesController::apiCnpj] cache check: ' . $e->getMessage());
            }
        }

        $result = $svc->lookup($cnpjRaw);

        if ($result['ok'] && $unitId > 0) {
            try {
                $this->pdo->prepare("
                    UPDATE bi_negocio_institution_names
                    SET cnpj_cache_json = ?, cnpj_cache_at = NOW()
                    WHERE id = ? AND tenant_id = ?
                ")->execute([json_encode($result['data']), $unitId, $tenantId]);
            } catch (\Throwable $e) {
                Logger::error('[UnidadesController::apiCnpj] cache save: ' . $e->getMessage());
            }
        }

        echo json_encode($result);
    }

    // API: Listar unidades do tenant (filtro dependente)
    public function apiListar(): void
    {
        header('Content-Type: application/json');
        if ($this->denyIfRestrictedJson()) return;
        $tenantId = TenantContext::id() ?? Auth::tenantId();
        if (!$tenantId) { echo json_encode([]); exit; }
        try {
            $stmt = $this->pdo->prepare("
                SELECT institution_name AS nome
                FROM bi_negocio_institution_names
                WHERE tenant_id = ? AND ativo = 1
                ORDER BY institution_name ASC
            ");
            $stmt->execute([$tenantId]);
            echo json_encode($stmt->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            Logger::error('[UnidadesController::apiListar] ' . $e->getMessage());
            echo json_encode([]);
        }
        exit;
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    private function sincronizarInstitutionNames(int $tenantId): void
    {
        try {
            $this->pdo->prepare("
                INSERT IGNORE INTO bi_negocio_institution_names (tenant_id, institution_name, ativo, created_at)
                SELECT DISTINCT e.tenant_id, e.institution_name, 1, NOW()
                FROM bi_pacs_estudos e
                WHERE e.tenant_id = ?
                  AND e.institution_name IS NOT NULL
                  AND e.institution_name != ''
                  AND NOT EXISTS (
                      SELECT 1 FROM bi_negocio_institution_names n
                      WHERE n.tenant_id = e.tenant_id
                        AND n.institution_name COLLATE utf8mb4_general_ci = e.institution_name COLLATE utf8mb4_general_ci
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM bi_negocio_institution_names nx
                      WHERE nx.tenant_id = e.tenant_id
                        AND nx.institution_name COLLATE utf8mb4_general_ci = e.institution_name COLLATE utf8mb4_general_ci
                        AND nx.excluido_manualmente = 1
                  )
            ")->execute([$tenantId]);
        } catch (\Throwable $e) {
            Logger::error('[UnidadesController::sincronizarInstitutionNames] ' . $e->getMessage());
        }
    }

    private function processarLogoUpload(array $file, int $unitId, int $tenantId): array
    {
        $maxBytes = self::UPLOAD_MAX_MB * 1024 * 1024;
        if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'msg' => 'Erro no upload.'];
        if ($file['size'] > $maxBytes) return ['ok' => false, 'msg' => 'Logo deve ter no máximo ' . self::UPLOAD_MAX_MB . 'MB.'];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, self::LOGO_TYPES, true)) return ['ok' => false, 'msg' => 'Tipo não permitido. Use PNG, JPG ou SVG.'];

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::LOGO_EXTS, true)) return ['ok' => false, 'msg' => 'Extensão não permitida.'];

        $dir = self::UPLOAD_BASE . "/{$tenantId}/{$unitId}";
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $filename = 'logo_' . time() . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
            return ['ok' => false, 'msg' => 'Falha ao salvar o arquivo.'];
        }

        // Remover logo anterior
        try {
            $stmt = $this->pdo->prepare("SELECT logo_path FROM bi_negocio_institution_names WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmt->execute([$unitId, $tenantId]);
            $old = $stmt->fetchColumn();
            if ($old && file_exists(__DIR__ . '/../../public/' . $old)) @unlink(__DIR__ . '/../../public/' . $old);
        } catch (\Throwable $e) { /* não bloquear */ }

        return ['ok' => true, 'path' => "uploads/unidades/{$tenantId}/{$unitId}/{$filename}"];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CRUD bi_unidades — Entidade Rica (CNPJ, endereço, logo)
    // ══════════════════════════════════════════════════════════════════════════

    // GET /unidades/nova
    public function novaUnidade(): void
    {
        $this->denyIfRestricted();
        $tenantId = TenantContext::id() ?? Auth::tenantId();
        if (!$tenantId) { header('Location: /selecionar-empresa'); exit; }

        // Listar institution_names disponíveis para vínculo
        $institutionNames = $this->listarInstitutionNamesDisponiveis($tenantId);
        $templatesLaudo   = $this->reportLayoutService->listarCatalogo();
        $unidade = null;
        $this->view('unidades/nova', compact('unidade', 'institutionNames', 'templatesLaudo'));
    }

    // POST /unidades/nova
    public function criarUnidade(): void
    {
        $this->denyIfRestricted();
        $tenantId = TenantContext::id() ?? Auth::tenantId();
        if (!$tenantId) { header('Location: /selecionar-empresa'); exit; }

        $cnpj = preg_replace('/\D/', '', $_POST['cnpj'] ?? '');

        // Prevenir cadastro duplicado (mesmo CNPJ no mesmo tenant)
        if (!empty($cnpj)) {
            try {
                $stmtDup = $this->pdo->prepare("
                    SELECT id FROM bi_unidades WHERE tenant_id = ? AND cnpj = ? LIMIT 1
                ");
                $stmtDup->execute([$tenantId, $cnpj]);
                if ($stmtDup->fetchColumn()) {
                    $_SESSION['error'] = 'Já existe uma unidade cadastrada com este CNPJ.';
                    header('Location: /unidades/nova');
                    exit;
                }
            } catch (\Throwable $e) {
                Logger::error('[UnidadesController::criarUnidade] dup check: ' . $e->getMessage());
            }
        }

        $campos = [
            'tenant_id'    => $tenantId,
            'cnpj'         => $cnpj ?: null,
            'razao_social' => trim($_POST['razao_social']  ?? '') ?: null,
            'nome_fantasia'=> trim($_POST['nome_fantasia'] ?? '') ?: null,
            'cep'          => preg_replace('/\D/', '', $_POST['cep'] ?? '') ?: null,
            'logradouro'   => trim($_POST['logradouro']    ?? '') ?: null,
            'numero'       => trim($_POST['numero']        ?? '') ?: null,
            'complemento'  => trim($_POST['complemento']  ?? '') ?: null,
            'bairro'       => trim($_POST['bairro']        ?? '') ?: null,
            'cidade'       => trim($_POST['cidade']        ?? '') ?: null,
            'estado'       => strtoupper(trim($_POST['estado'] ?? '')) ?: null,
            'telefone'     => trim($_POST['telefone']      ?? '') ?: null,
            'email'        => trim($_POST['email']         ?? '') ?: null,
            'site'         => trim($_POST['site']          ?? '') ?: null,
            'observacoes'  => trim($_POST['observacoes']   ?? '') ?: null,
            'report_layout_template_id' => (int) ($_POST['report_layout_template_id'] ?? 0) ?: null,
            'ativo'        => 1,
        ];

        try {
            $cols   = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($campos)));
            $placeholders = implode(', ', array_fill(0, count($campos), '?'));
            $this->pdo->prepare("
                INSERT INTO bi_unidades ({$cols}) VALUES ({$placeholders})
            ")->execute(array_values($campos));
            $novaId = (int)$this->pdo->lastInsertId();

            // Upload de logo
            if (!empty($_FILES['logo']['name'])) {
                $logoResult = $this->processarLogoUploadUnidade($_FILES['logo'], $novaId, $tenantId);
                if ($logoResult['ok']) {
                    $logoUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/' . $logoResult['path'];
                    $this->pdo->prepare("
                        UPDATE bi_unidades SET logo_path = ?, copilot_logo_url = ? WHERE id = ? AND tenant_id = ?
                    ")->execute([$logoResult['path'], $logoUrl, $novaId, $tenantId]);
                }
            }

            // Vincular institution_names selecionados
            $instIds = array_filter(array_map('intval', $_POST['institution_names'] ?? []));
            if (!empty($instIds)) {
                $this->vincularInstitutionNames($novaId, $instIds, $tenantId);
            }

            Logger::error('[UnidadesController::criarUnidade] Unidade criada', ['id' => $novaId, 'tenant_id' => $tenantId]);
            $_SESSION['success'] = 'Unidade cadastrada com sucesso!';
        } catch (\Throwable $e) {
            Logger::error('[UnidadesController::criarUnidade] ERRO: ' . $e->getMessage());
            $_SESSION['error'] = 'Erro ao cadastrar unidade. Tente novamente.';
        }
        header('Location: /unidades');
        exit;
    }

    // GET /unidades/{id}/editar
    public function editarUnidade(int $id): void
    {
        $this->denyIfRestricted();
        $tenantId = TenantContext::id() ?? Auth::tenantId();
        $unidade  = $this->findOrFailUnidade($id, $tenantId);

        // Institution names já vinculados
        $vinculados = [];
        try {
            $stmt = $this->pdo->prepare("
                SELECT id FROM bi_negocio_institution_names
                WHERE unidade_id = ? AND tenant_id = ?
            ");
            $stmt->execute([$id, $tenantId]);
            $vinculados = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {}

        $institutionNames = $this->listarInstitutionNamesDisponiveis($tenantId, $id);
        $templatesLaudo   = $this->reportLayoutService->listarCatalogo();
        $this->view('unidades/nova', compact('unidade', 'institutionNames', 'vinculados', 'templatesLaudo'));
    }

    // POST /unidades/{id}/editar
    public function atualizarUnidade(int $id): void
    {
        $this->denyIfRestricted();
        $tenantId = TenantContext::id() ?? Auth::tenantId();
        $this->findOrFailUnidade($id, $tenantId);

        $cnpj = preg_replace('/\D/', '', $_POST['cnpj'] ?? '');

        // Prevenir CNPJ duplicado em outra unidade
        if (!empty($cnpj)) {
            try {
                $stmtDup = $this->pdo->prepare("
                    SELECT id FROM bi_unidades WHERE tenant_id = ? AND cnpj = ? AND id != ? LIMIT 1
                ");
                $stmtDup->execute([$tenantId, $cnpj, $id]);
                if ($stmtDup->fetchColumn()) {
                    $_SESSION['error'] = 'Outro cadastro já usa este CNPJ.';
                    header("Location: /unidades/{$id}/editar");
                    exit;
                }
            } catch (\Throwable $e) {}
        }

        $campos = [
            'cnpj'         => $cnpj ?: null,
            'razao_social' => trim($_POST['razao_social']  ?? '') ?: null,
            'nome_fantasia'=> trim($_POST['nome_fantasia'] ?? '') ?: null,
            'cep'          => preg_replace('/\D/', '', $_POST['cep'] ?? '') ?: null,
            'logradouro'   => trim($_POST['logradouro']    ?? '') ?: null,
            'numero'       => trim($_POST['numero']        ?? '') ?: null,
            'complemento'  => trim($_POST['complemento']  ?? '') ?: null,
            'bairro'       => trim($_POST['bairro']        ?? '') ?: null,
            'cidade'       => trim($_POST['cidade']        ?? '') ?: null,
            'estado'       => strtoupper(trim($_POST['estado'] ?? '')) ?: null,
            'telefone'     => trim($_POST['telefone']      ?? '') ?: null,
            'email'        => trim($_POST['email']         ?? '') ?: null,
            'site'         => trim($_POST['site']          ?? '') ?: null,
            'observacoes'  => trim($_POST['observacoes']   ?? '') ?: null,
            'report_layout_template_id' => (int) ($_POST['report_layout_template_id'] ?? 0) ?: null,
            'ativo'        => isset($_POST['ativo']) ? 1 : 0,
        ];

        // Upload de logo
        if (!empty($_FILES['logo']['name'])) {
            $logoResult = $this->processarLogoUploadUnidade($_FILES['logo'], $id, $tenantId);
            if (!$logoResult['ok']) {
                $_SESSION['error'] = $logoResult['msg'];
                header("Location: /unidades/{$id}/editar");
                exit;
            }
            $campos['logo_path']       = $logoResult['path'];
            $campos['copilot_logo_url'] = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/' . $logoResult['path'];
        }

        try {
            $set    = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($campos)));
            $params = array_values($campos);
            $params[] = $id;
            $params[] = $tenantId;
            $this->pdo->prepare("
                UPDATE bi_unidades SET {$set} WHERE id = ? AND tenant_id = ?
            ")->execute($params);

            // Atualizar vínculos: desvincular todos e revincular os selecionados
            $this->pdo->prepare("
                UPDATE bi_negocio_institution_names SET unidade_id = NULL
                WHERE unidade_id = ? AND tenant_id = ?
            ")->execute([$id, $tenantId]);

            $instIds = array_filter(array_map('intval', $_POST['institution_names'] ?? []));
            if (!empty($instIds)) {
                $this->vincularInstitutionNames($id, $instIds, $tenantId);
            }

            Logger::error('[UnidadesController::atualizarUnidade] Unidade atualizada', ['id' => $id]);
            $_SESSION['success'] = 'Unidade atualizada com sucesso!';
        } catch (\Throwable $e) {
            Logger::error('[UnidadesController::atualizarUnidade] ERRO: ' . $e->getMessage(), ['id' => $id]);
            $_SESSION['error'] = 'Erro ao atualizar unidade. Tente novamente.';
        }
        header('Location: /unidades');
        exit;
    }

    // POST /unidades/{id}/excluir
    public function excluirUnidade(int $id): void
    {
        $this->denyIfRestricted();
        $tenantId = TenantContext::id() ?? Auth::tenantId();
        $this->findOrFailUnidade($id, $tenantId);
        try {
            // Desvincular institution_names antes de excluir
            $this->pdo->prepare("
                UPDATE bi_negocio_institution_names SET unidade_id = NULL
                WHERE unidade_id = ? AND tenant_id = ?
            ")->execute([$id, $tenantId]);

            // Soft delete
            $this->pdo->prepare("
                UPDATE bi_unidades SET ativo = 0 WHERE id = ? AND tenant_id = ?
            ")->execute([$id, $tenantId]);

            $_SESSION['success'] = 'Unidade desativada com sucesso.';
        } catch (\Throwable $e) {
            Logger::error('[UnidadesController::excluirUnidade] ERRO: ' . $e->getMessage(), ['id' => $id]);
            $_SESSION['error'] = 'Erro ao excluir unidade.';
        }
        header('Location: /unidades');
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // API pública para VoxelCopilot
    // GET /api/unidades/info?institution_name=NOVA+IMAGEM+-+CAMBUI
    // GET /api/unidades/info?id=42
    // ══════════════════════════════════════════════════════════════════════════
    public function apiInfo(): void
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');

        // Autenticação: Bearer token ou sessão ativa
        $token = null;
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
            $token = $m[1];
        }

        $tenantId = null;
        $authenticatedByIntegration = false;
        if ($token) {
            // Validar token de integração Copilot
            try {
                $stmt = $this->pdo->prepare("
                    SELECT tenant_id FROM bi_copilot_unidades
                    WHERE token_integracao = ? AND ativo = 1 LIMIT 1
                ");
                $stmt->execute([$token]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $tenantId = (int) $row['tenant_id'];
                    $authenticatedByIntegration = true;
                }
            } catch (\Throwable $e) {
                Logger::error('[UnidadesController::apiInfo] token lookup: ' . $e->getMessage());
            }
        }

        if (!$tenantId) {
            // Fallback: sessão ativa
            $tenantId = TenantContext::id() ?? Auth::tenantId();
        }

        if (!$tenantId) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'msg' => 'Não autenticado.']);
            return;
        }

        // Sessão de médico não pode usar este endpoint como atalho para dados de
        // unidade. Integrações externas válidas usam Bearer token e seguem ativas.
        if (!$authenticatedByIntegration && Auth::check() && MedicoAccess::isRestricted()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'msg' => 'Acesso negado: médicos não possuem acesso à gestão de unidades.']);
            return;
        }

        try {
            $unidade = null;

            if (!empty($_GET['id'])) {
                $stmt = $this->pdo->prepare("
                    SELECT u.*,
                           GROUP_CONCAT(n.institution_name ORDER BY n.institution_name SEPARATOR '|') AS institution_names
                    FROM bi_unidades u
                    LEFT JOIN bi_negocio_institution_names n ON n.unidade_id = u.id AND n.tenant_id = u.tenant_id
                    WHERE u.id = ? AND u.tenant_id = ? AND u.ativo = 1
                    GROUP BY u.id
                    LIMIT 1
                ");
                $stmt->execute([(int)$_GET['id'], $tenantId]);
                $unidade = $stmt->fetch(\PDO::FETCH_ASSOC);

            } elseif (!empty($_GET['institution_name'])) {
                $instName = trim($_GET['institution_name']);
                $stmt = $this->pdo->prepare("
                    SELECT u.*,
                           GROUP_CONCAT(n.institution_name ORDER BY n.institution_name SEPARATOR '|') AS institution_names
                    FROM bi_unidades u
                    JOIN bi_negocio_institution_names n ON n.unidade_id = u.id AND n.tenant_id = u.tenant_id
                    WHERE n.institution_name COLLATE utf8mb4_general_ci = ? COLLATE utf8mb4_general_ci AND u.tenant_id = ? AND u.ativo = 1
                    GROUP BY u.id
                    LIMIT 1
                ");
                $stmt->execute([$instName, $tenantId]);
                $unidade = $stmt->fetch(\PDO::FETCH_ASSOC);
            }

            if (!$unidade) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'msg' => 'Unidade não encontrada.']);
                return;
            }

            // Formatar CEP
            $cep = $unidade['cep'] ?? '';
            if (strlen($cep) === 8) $cep = substr($cep, 0, 5) . '-' . substr($cep, 5);

            echo json_encode([
                'ok'   => true,
                'data' => [
                    'id'                => (int)$unidade['id'],
                    'razao_social'      => $unidade['razao_social']  ?? null,
                    'nome_fantasia'     => $unidade['nome_fantasia'] ?? null,
                    'cnpj'              => $unidade['cnpj']          ?? null,
                    'cep'               => $cep ?: null,
                    'logradouro'        => $unidade['logradouro']    ?? null,
                    'numero'            => $unidade['numero']        ?? null,
                    'complemento'       => $unidade['complemento']   ?? null,
                    'bairro'            => $unidade['bairro']        ?? null,
                    'cidade'            => $unidade['cidade']        ?? null,
                    'estado'            => $unidade['estado']        ?? null,
                    'telefone'          => $unidade['telefone']      ?? null,
                    'email'             => $unidade['email']         ?? null,
                    'site'              => $unidade['site']          ?? null,
                    'logo_path'         => $unidade['logo_path']     ?? null,
                    'copilot_logo_url'  => $unidade['copilot_logo_url'] ?? null,
                    'institution_names' => $unidade['institution_names'] ? explode('|', $unidade['institution_names']) : [],
                ],
            ]);
        } catch (\Throwable $e) {
            Logger::error('[UnidadesController::apiInfo] ERRO: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'msg' => 'Erro interno.']);
        }
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    private function listarInstitutionNamesDisponiveis(int $tenantId, ?int $unidadeId = null): array
    {
        try {
            // Retorna institution_names: sem vínculo OU já vinculados a esta unidade
            $stmt = $this->pdo->prepare("
                SELECT id, institution_name, unidade_id
                FROM bi_negocio_institution_names
                WHERE tenant_id = ? AND ativo = 1
                  AND (unidade_id IS NULL OR unidade_id = ?)
                ORDER BY institution_name ASC
            ");
            $stmt->execute([$tenantId, $unidadeId ?? 0]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Logger::error('[UnidadesController::listarInstitutionNamesDisponiveis] ' . $e->getMessage());
            return [];
        }
    }

    private function vincularInstitutionNames(int $unidadeId, array $instIds, int $tenantId): void
    {
        if (empty($instIds)) return;
        $placeholders = implode(',', array_fill(0, count($instIds), '?'));
        $params = array_merge([$unidadeId], $instIds, [$tenantId]);
        $this->pdo->prepare("
            UPDATE bi_negocio_institution_names
            SET unidade_id = ?
            WHERE id IN ({$placeholders}) AND tenant_id = ?
        ")->execute($params);
    }

    private function processarLogoUploadUnidade(array $file, int $unitId, int $tenantId): array
    {
        $maxBytes = self::UPLOAD_MAX_MB * 1024 * 1024;
        if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'msg' => 'Erro no upload.'];
        if ($file['size'] > $maxBytes) return ['ok' => false, 'msg' => 'Logo deve ter no máximo ' . self::UPLOAD_MAX_MB . 'MB.'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, self::LOGO_TYPES, true)) return ['ok' => false, 'msg' => 'Tipo não permitido. Use PNG, JPG ou SVG.'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::LOGO_EXTS, true)) return ['ok' => false, 'msg' => 'Extensão não permitida.'];
        $dir = self::UPLOAD_BASE . "/u/{$tenantId}/{$unitId}";
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $filename = 'logo_' . time() . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
            return ['ok' => false, 'msg' => 'Falha ao salvar o arquivo.'];
        }
        // Remover logo anterior
        try {
            $stmt = $this->pdo->prepare("SELECT logo_path FROM bi_unidades WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmt->execute([$unitId, $tenantId]);
            $old = $stmt->fetchColumn();
            if ($old && file_exists(__DIR__ . '/../../public/' . $old)) @unlink(__DIR__ . '/../../public/' . $old);
        } catch (\Throwable $e) { /* não bloquear */ }
        return ['ok' => true, 'path' => "uploads/unidades/u/{$tenantId}/{$unitId}/{$filename}"];
    }

    private function findOrFailUnidade(int $id, ?int $tenantId): array
    {
        if (!$tenantId) { header('Location: /selecionar-empresa'); exit; }
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM bi_unidades WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmt->execute([$id, $tenantId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { $row = null; }
        if (!$row) {
            $_SESSION['error'] = 'Unidade não encontrada.';
            header('Location: /unidades');
            exit;
        }
        return $row;
    }

    private function findOrFail(int $id, ?int $tenantId): array
    {
        if (!$tenantId) { header('Location: /selecionar-empresa'); exit; }
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM bi_negocio_institution_names WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmt->execute([$id, $tenantId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { $row = null; }
        if (!$row) {
            $_SESSION['error'] = 'Unidade não encontrada.';
            header('Location: /unidades');
            exit;
        }
        return $row;
    }
}
