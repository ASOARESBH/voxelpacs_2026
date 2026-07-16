<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Auth;

/**
 * VOXEL PACS — EstudosController
 *
 * Worklist principal: lista, busca, filtros avançados e abertura de estudos DICOM.
 * Fonte: bi_pacs_estudos (cache do Orthanc sincronizado via /platform/servidor-pacs).
 * Abertura: redireciona diretamente para OHIF Viewer com StudyInstanceUID.
 *
 * Filtros:
 *   q             → pesquisa global (patient_name, patient_id, study_instance_uid,
 *                                    accession_number, study_description, institution_name)
 *   paciente      → patient_name LIKE
 *   periodo       → hoje|ontem|7dias|30dias|90dias|ano|todos|personalizado
 *   dt_inicio/dt_fim → usado com periodo=personalizado
 *   unidade       → institution_name LIKE
 *   modalidade    → modalities LIKE
 *   especialidade → especialidade LIKE (rótulo exibido na tela é "Solicitante" desde
 *                   2026-07-12 — a coluna bi_pacs_estudos.especialidade nunca é escrita
 *                   em nenhum fluxo, então este filtro nunca encontra nada; o que a
 *                   célula da tabela mostra de fato é o fallback referring_physician_name,
 *                   não filtrado aqui. Ver modules/worklist-estudos.md)
 *   situacao      → novo|aberto|em_laudo|rascunho|assinado|liberado
 *   prioridade    → normal|urgente|critico
 *   medico        → assumido_por LIKE
 *   ordenar       → whitelist de colunas
 *   direcao       → ASC|DESC
 *   pagina        → int
 *   por_pagina    → 25|50|100|250|0 (0=todos)
 */
class EstudosController extends Controller
{
    private const COLUNAS_ORDEM = [
        'study_date','study_time','patient_name','institution_name',
        'modalities','especialidade','prioridade','situacao',
    ];

    public function index(): void
    {
        $pdo      = Database::getInstance();
        $tenantId = Auth::tenantId();
        $isAdmin  = Auth::isPlatformAdmin();
        // Bypass total (vê todos os tenants) só para superadmin fora de impersonação.
        $bypassGlobal = $isAdmin && !Auth::isImpersonating();

        // ── Filtros ───────────────────────────────────────────────────────────────────────
        $periodo = trim($_GET['periodo'] ?? 'hoje');
        if (!in_array($periodo, ['hoje','ontem','7dias','30dias','90dias','ano','todos','personalizado'])) {
            $periodo = 'hoje';
        }

        $filtros = [
            'q'              => trim($_GET['q']             ?? ''),
            'paciente'       => trim($_GET['paciente']      ?? ''),
            'periodo'        => $periodo,
            'dt_inicio'      => trim($_GET['dt_inicio']     ?? ''),
            'dt_fim'         => trim($_GET['dt_fim']        ?? ''),
            'unidade'        => trim($_GET['unidade']       ?? ''),
            'modalidade'     => strtoupper(trim($_GET['modalidade']    ?? '')),
            'especialidade'  => trim($_GET['especialidade'] ?? ''),
            'situacao'       => trim($_GET['situacao']      ?? ''),
            'situacao_rapida'=> trim($_GET['situacao_rapida'] ?? ''),
            'prioridade'     => trim($_GET['prioridade']    ?? ''),
            'medico'         => trim($_GET['medico']        ?? ''),
            'ordenar'        => in_array($_GET['ordenar'] ?? '', self::COLUNAS_ORDEM)
                                ? $_GET['ordenar'] : 'study_date',
            'direcao'        => in_array(strtoupper($_GET['direcao'] ?? ''), ['ASC','DESC'])
                                ? strtoupper($_GET['direcao']) : 'DESC',
            'pagina'         => max(1, (int)($_GET['pagina'] ?? 1)),
            'por_pagina'     => $this->sanitizarPorPagina((int)($_GET['por_pagina'] ?? 25)),
        ];

        if ($filtros['situacao_rapida'] !== '') {
            $filtros['situacao'] = $filtros['situacao_rapida'];
        }

        // ── Calcular datas do período ──────────────────────────────────────────────────────
        $today = date('Y-m-d');
        switch ($filtros['periodo']) {
            case 'hoje':
                $filtros['dt_inicio'] = $today;
                $filtros['dt_fim']    = $today;
                break;
            case 'ontem':
                $filtros['dt_inicio'] = date('Y-m-d', strtotime('-1 day'));
                $filtros['dt_fim']    = date('Y-m-d', strtotime('-1 day'));
                break;
            case '7dias':
                $filtros['dt_inicio'] = date('Y-m-d', strtotime('-6 days'));
                $filtros['dt_fim']    = $today;
                break;
            case '30dias':
                $filtros['dt_inicio'] = date('Y-m-d', strtotime('-29 days'));
                $filtros['dt_fim']    = $today;
                break;
            case '90dias':
                $filtros['dt_inicio'] = date('Y-m-d', strtotime('-89 days'));
                $filtros['dt_fim']    = $today;
                break;
            case 'ano':
                $filtros['dt_inicio'] = date('Y-01-01');
                $filtros['dt_fim']    = $today;
                break;
            case 'todos':
                $filtros['dt_inicio'] = '';
                $filtros['dt_fim']    = '';
                break;
            // 'personalizado': usa dt_inicio e dt_fim do GET
        }

        // ── WHERE dinâmico ────────────────────────────────────────────────────────────────
        $where  = ['e.servidor_id = 1'];
        $params = [];

        if ($tenantId) {
            $where[]              = 'e.tenant_id = :tenant_id';
            $params[':tenant_id'] = $tenantId;
        } elseif (!$bypassGlobal) {
            $where[] = '1=0';
        }

        // Pesquisa global
        if ($filtros['q'] !== '') {
            $like = '%' . $filtros['q'] . '%';
            $where[] = '(e.patient_name LIKE :q1 OR e.patient_id LIKE :q2
                      OR e.study_instance_uid LIKE :q3 OR e.accession_number LIKE :q4
                      OR e.study_description LIKE :q5 OR e.institution_name LIKE :q6)';
            $params[':q1'] = $like; $params[':q2'] = $like;
            $params[':q3'] = $like; $params[':q4'] = $like;
            $params[':q5'] = $like; $params[':q6'] = $like;
        }

        if ($filtros['paciente'] !== '') {
            $where[]        = 'e.patient_name LIKE :pac';
            $params[':pac'] = '%' . $filtros['paciente'] . '%';
        }
        if ($filtros['dt_inicio'] !== '') {
            $where[]              = 'e.study_date >= :dt_inicio';
            $params[':dt_inicio'] = $filtros['dt_inicio'];
        }
        if ($filtros['dt_fim'] !== '') {
            $where[]            = 'e.study_date <= :dt_fim';
            $params[':dt_fim']  = $filtros['dt_fim'];
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
            $where[]        = 'e.especialidade LIKE :esp';
            $params[':esp'] = '%' . $filtros['especialidade'] . '%';
        }
        if ($filtros['situacao'] !== '') {
            $where[]             = "COALESCE(e.situacao,'novo') = :situacao";
            $params[':situacao'] = $filtros['situacao'];
        }
        if ($filtros['prioridade'] !== '') {
            $where[]               = 'e.prioridade = :prioridade';
            $params[':prioridade'] = $filtros['prioridade'];
        }
        if ($filtros['medico'] !== '') {
            $where[]           = 'e.assumido_por LIKE :medico';
            $params[':medico'] = '%' . $filtros['medico'] . '%';
        }

        $whereStr = implode(' AND ', $where);
        $orderCol = 'e.' . $filtros['ordenar'];
        $orderDir = $filtros['direcao'];

        // ── COUNT ─────────────────────────────────────────────────────────────────────────
        $total       = 0;
        $tempoInicio = microtime(true);
        try {
            $sc = $pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos e WHERE {$whereStr}");
            $sc->execute($params);
            $total = (int)$sc->fetchColumn();
        } catch (\Throwable $ex) {
            error_log('[EstudosController::index] COUNT: ' . $ex->getMessage());
        }

        // ── Paginação ─────────────────────────────────────────────────────────────────────
        $porPagina   = $filtros['por_pagina'];
        $totalPages  = $porPagina > 0 ? max(1, (int)ceil($total / $porPagina)) : 1;
        $currentPage = min($filtros['pagina'], $totalPages);
        $offset      = $porPagina > 0 ? ($currentPage - 1) * $porPagina : 0;
        $limitClause = $porPagina > 0 ? "LIMIT {$porPagina} OFFSET {$offset}" : '';

        // ── SELECT ────────────────────────────────────────────────────────────────────────
        $estudos = [];
        try {
            $sql = "
                SELECT
                    e.id,
                    e.orthanc_id,
                    e.study_date,
                    e.study_time,
                    e.patient_id,
                    e.patient_name,
                    e.patient_sex,
                    e.patient_age,
                    e.patient_birth_date,
                    e.institution_name,
                    e.modalities,
                    e.study_description,
                    e.accession_number,
                    e.referring_physician_name,
                    e.performing_physician_name,
                    e.num_series,
                    e.num_instances,
                    e.study_instance_uid,
                    e.tenant_id,
                    e.manufacturer,
                    COALESCE(e.situacao,     'novo')   AS situacao,
                    COALESCE(e.especialidade,'')       AS especialidade,
                    COALESCE(e.prioridade,   'normal') AS prioridade,
                    COALESCE(e.assumido_por, '')       AS assumido_por,
                    e.assumido_em,
                    e.laudo_assinado_em,
                    e.urgente_em,
                    e.importado_em,
                    e.atualizado_em
                FROM bi_pacs_estudos e
                WHERE {$whereStr}
                ORDER BY {$orderCol} {$orderDir}, e.study_time {$orderDir}
                {$limitClause}
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $estudos = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $ex) {
            error_log('[EstudosController::index] SELECT: ' . $ex->getMessage());
        }

        $tempoConsulta = round((microtime(true) - $tempoInicio) * 1000, 1);

        // ── Dados para selects ────────────────────────────────────────────────────────────
        $unidades = [];
        try {
            $uW = ['servidor_id = 1', "institution_name IS NOT NULL", "institution_name != ''"];
            if ($tenantId) $uW[] = 'tenant_id = ' . (int)$tenantId;
            $unidades = $pdo->query(
                "SELECT DISTINCT institution_name FROM bi_pacs_estudos WHERE " . implode(' AND ', $uW) . " ORDER BY institution_name"
            )->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $ex) { $unidades = []; }

        $medicos = [];
        try {
            $mW = ['servidor_id = 1', "assumido_por IS NOT NULL", "assumido_por != ''"];
            if ($tenantId) $mW[] = 'tenant_id = ' . (int)$tenantId;
            $medicos = $pdo->query(
                "SELECT DISTINCT assumido_por FROM bi_pacs_estudos WHERE " . implode(' AND ', $mW) . " ORDER BY assumido_por"
            )->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $ex) { $medicos = []; }

        // ── Contadores topbar ─────────────────────────────────────────────────────────────
        $contadores = ['novo'=>0,'aberto'=>0,'em_laudo'=>0,'rascunho'=>0,'assinado'=>0,'liberado'=>0,'urgente'=>0];
        try {
            $cW = ['servidor_id = 1'];
            $cP = [];
            if ($tenantId) { $cW[] = 'tenant_id = :tid'; $cP[':tid'] = $tenantId; }
            $cBase = implode(' AND ', $cW);
            $cStmt = $pdo->prepare("SELECT COALESCE(situacao,'novo') AS situacao, COUNT(*) AS total FROM bi_pacs_estudos WHERE {$cBase} GROUP BY situacao");
            $cStmt->execute($cP);
            foreach ($cStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                if (isset($contadores[$r['situacao']])) $contadores[$r['situacao']] = (int)$r['total'];
            }
            $uStmt = $pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos WHERE {$cBase} AND prioridade IN ('urgente','critico')");
            $uStmt->execute($cP);
            $contadores['urgente'] = (int)$uStmt->fetchColumn();
        } catch (\Throwable $ex) {}

        // ── Painel de resumo ──────────────────────────────────────────────────────────────
        $resumo = ['hoje'=>0,'semana'=>0,'mes'=>0,'urgentes'=>$contadores['urgente'],'total'=>0];
        try {
            $rW = ['servidor_id = 1'];
            $rP = [];
            if ($tenantId) { $rW[] = 'tenant_id = :tid3'; $rP[':tid3'] = $tenantId; }
            $rBase = implode(' AND ', $rW);

            $s = $pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos WHERE {$rBase} AND study_date = :d");
            $s->execute(array_merge($rP, [':d' => $today]));
            $resumo['hoje'] = (int)$s->fetchColumn();

            $s = $pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos WHERE {$rBase} AND study_date >= :d");
            $s->execute(array_merge($rP, [':d' => date('Y-m-d', strtotime('-6 days'))]));
            $resumo['semana'] = (int)$s->fetchColumn();

            $s = $pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos WHERE {$rBase} AND study_date >= :d");
            $s->execute(array_merge($rP, [':d' => date('Y-m-d', strtotime('-29 days'))]));
            $resumo['mes'] = (int)$s->fetchColumn();

            $s = $pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos WHERE {$rBase}");
            $s->execute($rP);
            $resumo['total'] = (int)$s->fetchColumn();
        } catch (\Throwable $ex) {
            error_log('[EstudosController::index] resumo: ' . $ex->getMessage());
        }

        // ── Última sincronização ───────────────────────────────────────────────────────────
        $ultimaSinc = '';
        try {
            $s = $pdo->query("SELECT MAX(importado_em) FROM bi_pacs_estudos WHERE servidor_id = 1");
            $ultimaSinc = $s->fetchColumn() ?: '';
        } catch (\Throwable $ex) {}

        $this->view('estudos/index', compact(
            'estudos','filtros','total','totalPages','currentPage',
            'unidades','medicos','contadores','resumo',
            'tempoConsulta','ultimaSinc','isAdmin'
        ), 'pacs');
    }

    public function abrir(int $id): void
    {
        $pdo      = Database::getInstance();
        $tenantId = Auth::tenantId();
        $isAdmin  = Auth::isPlatformAdmin();
        $bypassGlobal = $isAdmin && !Auth::isImpersonating();
        $estudo   = null;

        try {
            $where  = 'id = :id AND servidor_id = 1';
            $params = [':id' => $id];
            if ($tenantId) {
                $where           .= ' AND tenant_id = :tid';
                $params[':tid']   = $tenantId;
            } elseif (!$bypassGlobal) {
                $where .= ' AND 1=0';
            }
            $stmt = $pdo->prepare(
                "SELECT id, orthanc_id, study_instance_uid, patient_name, tenant_id
                 FROM bi_pacs_estudos WHERE {$where} LIMIT 1"
            );
            $stmt->execute($params);
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
            $this->renderErroViewer(422, 'Este estudo não possui StudyInstanceUID.');
            return;
        }

        $uidParaViewer = $studyUid ?: $orthancId;

        try {
            $pdo->prepare("
                INSERT INTO pacs_viewer_tokens
                    (token, estudo_id, study_instance_uid, orthanc_id, tenant_id, usuario_id, ip_origem, expires_at)
                VALUES (:token,:estudo_id,:study_uid,:orthanc_id,:tenant_id,:usuario_id,:ip,:expires_at)
            ")->execute([
                ':token'      => $this->gerarToken(),
                ':estudo_id'  => $id,
                ':study_uid'  => $uidParaViewer,
                ':orthanc_id' => $orthancId ?: null,
                ':tenant_id'  => Auth::tenantId() ?: null,
                ':usuario_id' => Auth::userId()   ?: null,
                ':ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
                ':expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour')),
            ]);
        } catch (\Throwable $ex) {
            error_log('[EstudosController::abrir] log: ' . $ex->getMessage());
        }

        $ohifBase  = rtrim(getenv('VIEWER_URL') ?: 'https://view.voxelpacs.com.br', '/');
        $viewerUrl = $ohifBase . '/viewer?StudyInstanceUIDs=' . urlencode($uidParaViewer);
        header('Location: ' . $viewerUrl, true, 302);
        exit;
    }

    public function contadores(): void
    {
        $pdo      = Database::getInstance();
        $tenantId = Auth::tenantId();
        $isAdmin  = Auth::isPlatformAdmin();
        $bypassGlobal = $isAdmin && !Auth::isImpersonating();
        $where    = ['servidor_id = 1'];
        $params   = [];

        if ($tenantId) {
            $where[]       = 'tenant_id = :tid';
            $params[':tid'] = $tenantId;
        } elseif (!$bypassGlobal) {
            $this->json(['novo'=>0,'aberto'=>0,'em_laudo'=>0,'urgente'=>0,'rascunho'=>0,'assinado'=>0,'liberado'=>0]);
            return;
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT COALESCE(situacao,'novo') AS situacao, COUNT(*) AS total
                 FROM bi_pacs_estudos WHERE " . implode(' AND ', $where) . " GROUP BY situacao"
            );
            $stmt->execute($params);
            $data = ['novo'=>0,'aberto'=>0,'em_laudo'=>0,'urgente'=>0,'rascunho'=>0,'assinado'=>0,'liberado'=>0];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                if (isset($data[$r['situacao']])) $data[$r['situacao']] = (int)$r['total'];
            }
            $u = $pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos WHERE " . implode(' AND ', $where) . " AND prioridade IN ('urgente','critico')");
            $u->execute($params);
            $data['urgente'] = (int)$u->fetchColumn();
            $this->json($data);
        } catch (\Throwable $ex) {
            $this->json(['novo'=>0,'aberto'=>0,'em_laudo'=>0,'urgente'=>0,'rascunho'=>0,'assinado'=>0,'liberado'=>0]);
        }
    }

    private function sanitizarPorPagina(int $v): int
    {
        return in_array($v, [25, 50, 100, 250, 0]) ? $v : 25;
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
