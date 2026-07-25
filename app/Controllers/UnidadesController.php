<?php
/**
 * UnidadesController — Gestão de Unidades (bi_negocio_institution_names)
 * Unidades são identificadas pelo InstitutionName DICOM (0008,0080).
 * Aparecem automaticamente quando um estudo novo chega.
 * institution_name é somente leitura — admin completa dados complementares.
 */
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TenantContext;
use App\Services\CnpjLookupService;

class UnidadesController extends Controller
{
    private const UPLOAD_BASE   = __DIR__ . '/../../public/uploads/unidades';
    private const UPLOAD_MAX_MB = 2;
    private const LOGO_TYPES    = ['image/png', 'image/jpeg', 'image/svg+xml'];
    private const LOGO_EXTS     = ['png', 'jpg', 'jpeg', 'svg'];

    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
    }

    // INDEX
    public function index(): void
    {
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
                       AND e.institution_name = n.institution_name) AS total_estudos
                FROM bi_negocio_institution_names n
                WHERE n.tenant_id = ?
                ORDER BY n.institution_name ASC
            ");
            $stmt->execute([$tenantId]);
            $unidades = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Logger::error('[UnidadesController::index] ' . $e->getMessage(), ['tenant_id' => $tenantId]);
            $unidades = [];
        }

        $this->view('unidades/index', compact('unidades'));
    }

    // EDIT
    public function edit(int $id): void
    {
        $tenantId = TenantContext::id() ?? Auth::tenantId();
        $unidade  = $this->findOrFail($id, $tenantId);
        $this->view('unidades/edit', compact('unidade'));
    }

    // UPDATE
    public function update(int $id): void
    {
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
        $tenantId = TenantContext::id() ?? Auth::tenantId();
        header('Content-Type: application/json');
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
                        AND n.institution_name = e.institution_name
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
