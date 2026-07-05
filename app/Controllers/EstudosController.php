<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Auth;
use App\Services\EstudosService;

class EstudosController extends Controller
{
    private const COLUNAS_ORDEM = [
        'study_date','study_time','patient_name','institution_name',
        'modalities','especialidade','prioridade','situacao',
    ];

    private EstudosService $estudosService;

    public function __construct()
    {
        $this->estudosService = new EstudosService();
    }

    public function index(): void
    {
        $tenantId = Auth::tenantId();
        $isAdmin  = Auth::isPlatformAdmin();

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

        $tempoInicio = microtime(true);

        // Obter dados através do Service
        $estudosData = $this->estudosService->getEstudosData($filtros, $tenantId, $isAdmin);
        $selectData = $this->estudosService->getSelectData($tenantId, $isAdmin);
        $dashboardData = $this->estudosService->getDashboardData($tenantId, $isAdmin, $today);

        $tempoConsulta = round((microtime(true) - $tempoInicio) * 1000, 1);

        $this->view('estudos/index', [
            'estudos'       => $estudosData['estudos'],
            'total'         => $estudosData['total'],
            'totalPages'    => $estudosData['totalPages'],
            'currentPage'   => $estudosData['currentPage'],
            'filtros'       => $filtros,
            'unidades'      => $selectData['unidades'],
            'medicos'       => $selectData['medicos'],
            'especialidades'=> $selectData['especialidades'],
            'contadores'    => $dashboardData['contadores'],
            'resumo'        => $dashboardData['resumo'],
            'ultimaSinc'    => $dashboardData['ultimaSinc'],
            'tempoConsulta' => $tempoConsulta,
            'isAdmin'       => $isAdmin
        ], 'pacs');
    }

    public function abrir(int $id): void
    {
        $tenantId = Auth::tenantId();
        $isAdmin  = Auth::isPlatformAdmin();

        $estudo = $this->estudosService->getEstudoForViewer($id, $tenantId, $isAdmin);

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
            $pdo = Database::getInstance();
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
        $tenantId = Auth::tenantId();
        $isAdmin  = Auth::isPlatformAdmin();

        if (!$isAdmin && !$tenantId) {
            $this->json(['novo'=>0,'aberto'=>0,'em_laudo'=>0,'urgente'=>0,'rascunho'=>0,'assinado'=>0,'liberado'=>0]);
            return;
        }

        $dashboardData = $this->estudosService->getDashboardData($tenantId, $isAdmin, date('Y-m-d'));
        $this->json($dashboardData['contadores']);
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
