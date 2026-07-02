<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Auth;

/**
 * VOXEL PACS — EstudosController
 *
 * Worklist principal: lista, busca e abertura de estudos DICOM.
 * Fonte: bi_pacs_estudos (cache do Orthanc sincronizado via /platform/servidor-pacs).
 * Abertura: gera token temporário e redireciona para OHIF Viewer.
 */
class EstudosController extends Controller
{
    public function index(): void
    {
        $pdo      = Database::getInstance();
        $tenantId = Auth::tenantId();
        $isAdmin  = Auth::isPlatformAdmin();

        $filtros = [
            'q'              => trim($_GET['q']              ?? ''),
            'paciente'       => trim($_GET['paciente']       ?? ''),
            'unidade'        => trim($_GET['unidade']        ?? ''),
            'modalidade'     => trim($_GET['modalidade']     ?? ''),
            'especialidade'  => trim($_GET['especialidade']  ?? ''),
            'situacao'       => trim($_GET['situacao']       ?? ''),
            'situacao_rapida'=> trim($_GET['situacao_rapida']?? ''),
            'dt_inicio'      => trim($_GET['dt_inicio']      ?? ''),
            'dt_fim'         => trim($_GET['dt_fim']         ?? ''),
            'ordenar'        => trim($_GET['ordenar']        ?? 'study_date'),
            'direcao'        => in_array($_GET['direcao'] ?? '', ['ASC','DESC']) ? $_GET['direcao'] : 'DESC',
            'pagina'         => max(1, (int)($_GET['pagina'] ?? 1)),
            'por_pagina'     => 25,
        ];

        if ($filtros['situacao_rapida'] !== '') {
            $filtros['situacao'] = $filtros['situacao_rapida'];
        }

        $where  = ['e.servidor_id = 1'];
        $params = [];

        if (!$isAdmin && $tenantId) {
            $where[]              = 'e.tenant_id = :tenant_id';
            $params[':tenant_id'] = $tenantId;
        } elseif (!$isAdmin && !$tenantId) {
            $where[] = '1=0';
        }

        if ($filtros['paciente'] !== '') {
            $where[]            = '(e.patient_name LIKE :pac OR e.patient_name_display LIKE :pac2)';
            $params[':pac']     = '%' . $filtros['paciente'] . '%';
            $params[':pac2']    = '%' . $filtros['paciente'] . '%';
        }
        if ($filtros['unidade'] !== '') {
            $where[]            = 'e.institution_name LIKE :unidade';
            $params[':unidade'] = '%' . $filtros['unidade'] . '%';
        }
        if ($filtros['modalidade'] !== '') {
            $where[]               = 'e.modalities LIKE :modalidade';
            $params[':modalidade'] = '%' . $filtros['modalidade'] . '%';
        }
        if ($filtros['especialidade'] !== '') {
            $where[]                  = '(e.especialidade LIKE :esp OR e.study_description LIKE :esp2)';
            $params[':esp']           = '%' . $filtros['especialidade'] . '%';
            $params[':esp2']          = '%' . $filtros['especialidade'] . '%';
        }
        if ($filtros['situacao'] !== '') {
            $where[]             = 'COALESCE(e.situacao,\'novo\') = :situacao';
            $params[':situacao'] = $filtros['situacao'];
        }
        if ($filtros['dt_inicio'] !== '') {
            $where[]              = 'e.study_date >= :dt_inicio';
            $params[':dt_inicio'] = $filtros['dt_inicio'];
        }
        if ($filtros['dt_fim'] !== '') {
            $where[]            = 'e.study_date <= :dt_fim';
            $params[':dt_fim']  = $filtros['dt_fim'];
        }
        if ($filtros['q'] !== '') {
            $where[]       = '(e.patient_name LIKE :q OR e.patient_name_display LIKE :q2 OR e.study_description LIKE :q3 OR e.accession_number LIKE :q4)';
            $params[':q']  = '%' . $filtros['q'] . '%';
            $params[':q2'] = '%' . $filtros['q'] . '%';
            $params[':q3'] = '%' . $filtros['q'] . '%';
            $params[':q4'] = '%' . $filtros['q'] . '%';
        }

        $whereStr = implode(' AND ', $where);

        $colsPermitidas = ['study_date','patient_name','institution_name','modalities','study_description','situacao'];
        $orderCol = in_array($filtros['ordenar'], $colsPermitidas) ? 'e.' . $filtros['ordenar'] : 'e.study_date';
        $orderDir = $filtros['direcao'];

        $total = 0;
        try {
            $sc = $pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos e WHERE {$whereStr}");
            $sc->execute($params);
            $total = (int)$sc->fetchColumn();
        } catch (\Throwable $ex) {
            error_log('[EstudosController::index] COUNT: ' . $ex->getMessage());
        }

        $totalPages  = max(1, (int)ceil($total / $filtros['por_pagina']));
        $currentPage = min($filtros['pagina'], $totalPages);
        $offset      = ($currentPage - 1) * $filtros['por_pagina'];

        $estudos = [];
        try {
            $sql = "
                SELECT
                    e.id, e.orthanc_id, e.study_date, e.study_time,
                    e.patient_id, e.patient_name, e.patient_name_display,
                    e.patient_sex, e.patient_age, e.patient_birth_date,
                    e.institution_name, e.modalities, e.study_description,
                    e.accession_number, e.referring_physician_name,
                    e.performing_physician_name, e.num_series, e.num_instances,
                    e.is_stable, e.study_instance_uid, e.tenant_id,
                    COALESCE(e.situacao, 'novo')    AS situacao,
                    COALESCE(e.especialidade, '')   AS especialidade,
                    COALESCE(e.orthanc_url, '')     AS orthanc_url,
                    e.importado_em, e.atualizado_em
                FROM bi_pacs_estudos e
                WHERE {$whereStr}
                ORDER BY {$orderCol} {$orderDir}
                LIMIT {$filtros['por_pagina']} OFFSET {$offset}
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $estudos = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $ex) {
            error_log('[EstudosController::index] SELECT: ' . $ex->getMessage());
        }

        $unidades = [];
        try {
            $uWhere = ["servidor_id = 1", "institution_name IS NOT NULL", "institution_name != ''"];
            if (!$isAdmin && $tenantId) $uWhere[] = 'tenant_id = ' . (int)$tenantId;
            $unidades = $pdo->query(
                "SELECT DISTINCT institution_name FROM bi_pacs_estudos WHERE " . implode(' AND ', $uWhere) . " ORDER BY institution_name"
            )->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $ex) { $unidades = []; }

        $situacoes  = ['novo','aberto','urgente','rascunho','assinado'];
        $contadores = ['novo'=>0,'aberto'=>0,'urgente'=>0,'rascunho'=>0,'assinado'=>0];
        try {
            $cWhere  = ['servidor_id = 1'];
            $cParams = [];
            if (!$isAdmin && $tenantId) { $cWhere[] = 'tenant_id = :tid'; $cParams[':tid'] = $tenantId; }
            $cStmt = $pdo->prepare("SELECT COALESCE(situacao,'novo') AS situacao, COUNT(*) AS total FROM bi_pacs_estudos WHERE " . implode(' AND ', $cWhere) . " GROUP BY situacao");
            $cStmt->execute($cParams);
            foreach ($cStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                if (isset($contadores[$r['situacao']])) $contadores[$r['situacao']] = (int)$r['total'];
            }
        } catch (\Throwable $ex) {}

        $this->view('estudos/index', compact(
            'estudos','filtros','total','totalPages','currentPage',
            'unidades','situacoes','contadores','isAdmin'
        ), 'pacs');
    }

    public function abrir(int $id): void
    {
        $pdo    = Database::getInstance();
        $estudo = null;

        try {
            $stmt = $pdo->prepare(
                "SELECT id, orthanc_id, study_instance_uid, patient_name, tenant_id
                 FROM bi_pacs_estudos WHERE id = :id LIMIT 1"
            );
            $stmt->execute([':id' => $id]);
            $estudo = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $ex) {
            error_log('[EstudosController::abrir] ' . $ex->getMessage());
        }

        if (!$estudo) {
            $this->renderErroViewer(404, 'Estudo não encontrado ou sem permissão de acesso.');
            return;
        }

        $studyUid  = $estudo['study_instance_uid'] ?? '';
        $orthancId = $estudo['orthanc_id']         ?? '';

        if (empty($studyUid) && empty($orthancId)) {
            $this->renderErroViewer(422, 'Este estudo não possui StudyInstanceUID. Não é possível abrir no viewer DICOM.');
            return;
        }

        $uidParaViewer = $studyUid ?: $orthancId;
        $token         = $this->gerarToken();
        $expiresAt     = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $tenantId      = Auth::tenantId();
        $usuarioId     = Auth::userId();
        $ipOrigem      = $_SERVER['REMOTE_ADDR'] ?? null;

        try {
            $pdo->prepare("
                INSERT INTO pacs_viewer_tokens
                    (token, estudo_id, study_instance_uid, orthanc_id, tenant_id, usuario_id, ip_origem, expires_at)
                VALUES (:token,:estudo_id,:study_uid,:orthanc_id,:tenant_id,:usuario_id,:ip,:expires_at)
            ")->execute([
                ':token'      => $token,
                ':estudo_id'  => $id,
                ':study_uid'  => $uidParaViewer,
                ':orthanc_id' => $orthancId ?: null,
                ':tenant_id'  => $tenantId  ?: null,
                ':usuario_id' => $usuarioId ?: null,
                ':ip'         => $ipOrigem,
                ':expires_at' => $expiresAt,
            ]);
        } catch (\Throwable $ex) {
            error_log('[EstudosController::abrir] Token: ' . $ex->getMessage());
            $token = null;
        }

        // URL base do OHIF Viewer
        // Ordem de prioridade: VIEWER_URL > OHIF_VIEWER_URL > hardcoded
        $ohifBase = rtrim(
            getenv('VIEWER_URL') ?: (getenv('OHIF_VIEWER_URL') ?: 'https://view.voxelpacs.com.br'),
            '/'
        );

        // URL base do próprio sistema PHP para resolver o token
        // Ordem de prioridade: VIEWER_ERP_URL > host real da requisição > hardcoded
        // NÃO usa APP_URL pois pode estar desatualizado no .env de produção
        $erpBase = rtrim(
            getenv('VIEWER_ERP_URL') ?: (
                ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
                . '://' . ($_SERVER['HTTP_HOST'] ?? 'server.voxelpacs.com.br')
            ),
            '/'
        );

        if ($token) {
            // Fluxo seguro: PHP resolve o token → redireciona para OHIF
            // A rota /open/{token} é pública no Router.php (não exige autenticação)
            $viewerUrl = $erpBase . '/open/' . $token;
        } else {
            // Fallback direto: abre o OHIF com StudyInstanceUID
            $viewerUrl = $ohifBase . '/viewer?StudyInstanceUIDs=' . urlencode($uidParaViewer);
        }

        header('Location: ' . $viewerUrl, true, 302);
        exit;
    }

    public function contadores(): void
    {
        $pdo      = Database::getInstance();
        $tenantId = Auth::tenantId();
        $isAdmin  = Auth::isPlatformAdmin();

        $where  = ['servidor_id = 1'];
        $params = [];
        if (!$isAdmin && $tenantId) { $where[] = 'tenant_id = :tid'; $params[':tid'] = $tenantId; }
        elseif (!$isAdmin) { $this->json(['novo'=>0,'aberto'=>0,'urgente'=>0,'rascunho'=>0,'assinado'=>0]); return; }

        try {
            $stmt = $pdo->prepare("SELECT COALESCE(situacao,'novo') AS situacao, COUNT(*) AS total FROM bi_pacs_estudos WHERE " . implode(' AND ', $where) . " GROUP BY situacao");
            $stmt->execute($params);
            $data = ['novo'=>0,'aberto'=>0,'urgente'=>0,'rascunho'=>0,'assinado'=>0];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                if (isset($data[$r['situacao']])) $data[$r['situacao']] = (int)$r['total'];
            }
            $this->json($data);
        } catch (\Throwable $ex) {
            $this->json(['novo'=>0,'aberto'=>0,'urgente'=>0,'rascunho'=>0,'assinado'=>0]);
        }
    }

    private function gerarToken(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function renderErroViewer(int $code, string $msg): void
    {
        http_response_code($code);
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">';
        echo '<title>VOXEL PACS — Erro</title>';
        echo '<style>*{box-sizing:border-box;margin:0;padding:0}body{background:#0d1117;color:#e6edf3;font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh}.c{background:#161b22;border:1px solid #30363d;border-radius:12px;padding:2.5rem;max-width:480px;width:90%;text-align:center}h1{font-size:1.4rem;margin:.75rem 0;color:#f0f6fc}p{font-size:.9rem;color:#8b949e;margin-bottom:1.5rem}.btn{background:#4fc3f7;color:#0a1628;padding:.6rem 1.4rem;border-radius:8px;text-decoration:none;font-weight:600}</style>';
        echo '</head><body><div class="c"><div style="font-size:3rem">&#9888;&#65039;</div>';
        echo '<h1>VOXEL PACS</h1><p>' . htmlspecialchars($msg) . '</p>';
        echo '<a href="/estudos" class="btn">&#8592; Voltar</a></div></body></html>';
        exit;
    }
}
