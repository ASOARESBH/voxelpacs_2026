<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Auth;
use App\Services\DesktopViewerService;
use App\Services\InstitutionResolverService;

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
        // Padrão: 30dias (não "hoje") para mostrar dados relevantes ao abrir o módulo
        $periodo = trim($_GET['periodo'] ?? '30dias');
        if (!in_array($periodo, ['hoje','ontem','7dias','30dias','90dias','ano','todos','personalizado'])) {
            $periodo = '30dias';
        }

        $filtros = [
            'q'              => trim($_GET['q']             ?? ''),
            'paciente'       => trim($_GET['paciente']      ?? ''),
            'periodo'        => $periodo,
            'dt_inicio'      => trim($_GET['dt_inicio']     ?? ''),
            'dt_fim'         => trim($_GET['dt_fim']        ?? ''),
            'unidade'        => trim($_GET['unidade']       ?? ''),
            'modalidade'     => strtoupper(trim($_GET['modalidade']    ?? '')),
            'modalidades'    => array_values(array_filter(array_map(
                                    function($m){ return strtoupper(trim($m)); },
                                    (array)($_GET['modalidades'] ?? [])
                                ))),  // multi-seleção
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

        // ── Resolução de InstitutionNames (fonte única da verdade multi-tenant) ──────────
        // Retorna array de nomes de unidades vinculadas ao tenant.
        // Usado tanto no WHERE principal quanto nos contadores/resumo para consistência.
        $institutionNames     = [];
        $usaInstitutionFilter = false;
        if ($tenantId && !$bypassGlobal) {
            $institutionNames     = InstitutionResolverService::getInstitutionNamesByTenant($tenantId);
            $usaInstitutionFilter = true;
        }

        // ── WHERE dinâmico ────────────────────────────────────────────────────────────────
        // REGRA CRÍTICA: TODOS os parâmetros são posicionais (?) — nunca misturar com :nome
        $where  = ['e.servidor_id = 1'];
        $params = [];

        if ($usaInstitutionFilter) {
            if (!empty($institutionNames)) {
                // Filtro por InstitutionName: fonte única da verdade para multi-tenant
                $placeholders = implode(',', array_fill(0, count($institutionNames), '?'));
                $where[]      = "e.institution_name IN ({$placeholders})";
                foreach ($institutionNames as $iName) {
                    $params[] = $iName;
                }
            } else {
                // Tenant sem InstitutionNames cadastradas — fallback por tenant_id
                $where[]  = 'e.tenant_id = ?';
                $params[] = $tenantId;
                error_log('[EstudosController::index] Tenant ' . $tenantId . ' sem InstitutionNames — fallback tenant_id');
            }
        } elseif (!$bypassGlobal) {
            // Sem tenant e sem bypass: não mostra nada
            $where[] = '1=0';
        }

        // Pesquisa global (6 campos) — todos com parâmetros posicionais
        if ($filtros['q'] !== '') {
            $like    = '%' . $filtros['q'] . '%';
            $where[] = '(e.patient_name LIKE ? OR e.patient_id LIKE ?
                      OR e.study_instance_uid LIKE ? OR e.accession_number LIKE ?
                      OR e.study_description LIKE ? OR e.institution_name LIKE ?)';
            for ($i = 0; $i < 6; $i++) {
                $params[] = $like;
            }
        }

        if ($filtros['paciente'] !== '') {
            $where[]  = 'e.patient_name LIKE ?';
            $params[] = '%' . $filtros['paciente'] . '%';
        }
        if ($filtros['dt_inicio'] !== '') {
            $where[]  = 'e.study_date >= ?';
            $params[] = $filtros['dt_inicio'];
        }
        if ($filtros['dt_fim'] !== '') {
            $where[]  = 'e.study_date <= ?';
            $params[] = $filtros['dt_fim'];
        }
        // Filtro de unidade: match exato (não LIKE) pois o valor vem do dropdown
        // e já está no conjunto de InstitutionNames do tenant
        if ($filtros['unidade'] !== '') {
            $where[]  = 'e.institution_name = ?';
            $params[] = $filtros['unidade'];
        }
        // Filtro de modalidade: multi-seleção via modalidades[] (OR entre selecionadas)
        // Fallback: campo legado 'modalidade' (single) para compatibilidade
        $modsAtivas = $filtros['modalidades'];
        if (empty($modsAtivas) && $filtros['modalidade'] !== '') {
            $modsAtivas = [$filtros['modalidade']];
        }
        if (!empty($modsAtivas)) {
            $modClauses = [];
            foreach ($modsAtivas as $m) {
                $modClauses[] = 'e.modalities LIKE ?';
                $params[]     = '%' . $m . '%';
            }
            $where[] = '(' . implode(' OR ', $modClauses) . ')';
        }
        if ($filtros['especialidade'] !== '') {
            // Busca em especialidade e também em referring_physician_name
            $like    = '%' . $filtros['especialidade'] . '%';
            $where[] = '(e.especialidade LIKE ? OR e.referring_physician_name LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        if ($filtros['situacao'] !== '') {
            $where[]  = "COALESCE(e.situacao,'novo') = ?";
            $params[] = $filtros['situacao'];
        }
        if ($filtros['prioridade'] !== '') {
            $where[]  = 'e.prioridade = ?';
            $params[] = $filtros['prioridade'];
        }
        if ($filtros['medico'] !== '') {
            $where[]  = 'e.assumido_por LIKE ?';
            $params[] = '%' . $filtros['medico'] . '%';
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
                    COALESCE(e.dicom_priority, '')     AS dicom_priority,
                    COALESCE(e.assumido_por, '')       AS assumido_por,
                    e.assumido_em,
                    e.laudo_assinado_em,
                    e.urgente_em,
                    e.importado_em,
                    e.atualizado_em,
                    COALESCE(e.recebido_em, e.importado_em) AS recebido_em,
                    COALESCE(e.body_part_examined, '')          AS body_part_examined,
                    COALESCE(e.requested_procedure_desc, '')    AS requested_procedure_desc
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
        // Fonte primária: bi_negocio_institution_names (tabela oficial de unidades por tenant)
        $unidades = [];
        try {
            $uSql = "SELECT institution_name FROM bi_negocio_institution_names WHERE ativo = 1 AND institution_name IS NOT NULL AND institution_name != ''";
            if ($tenantId) $uSql .= ' AND tenant_id = ' . (int)$tenantId;
            $uSql .= ' ORDER BY institution_name';
            $unidades = $pdo->query($uSql)->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $ex) { $unidades = []; }
        // Fallback: institution_names dos estudos PACS (para tenants sem cadastro ainda)
        if (empty($unidades)) {
            try {
                $uW = ["institution_name IS NOT NULL", "institution_name != ''"];
                if ($tenantId) $uW[] = 'tenant_id = ' . (int)$tenantId;
                $unidades = $pdo->query(
                    "SELECT DISTINCT institution_name FROM bi_pacs_estudos WHERE " . implode(' AND ', $uW) . " ORDER BY institution_name"
                )->fetchAll(\PDO::FETCH_COLUMN);
            } catch (\Throwable $ex) { $unidades = []; }
        }

        // ── Médicos para o dropdown ───────────────────────────────────────────────────────
        // Fonte: bi_medicos (cadastro oficial) — não mais DISTINCT assumido_por dos estudos.
        // Regra de visibilidade:
        //   - Médico logado (usuario_id = Auth::userId() em bi_medicos): vê APENAS o próprio nome
        //   - Admin/analista/viewer: vê todos os médicos ativos do tenant
        $medicos          = []; // array de ['id'=>int, 'nome'=>string]
        $medicoLogadoId   = null;  // id em bi_medicos do usuário logado (se for médico)
        $medicoLogadoNome = null;  // nome do médico logado
        $isMedicoLogado   = false; // flag: usuário logado é um médico cadastrado
        try {
            if ($tenantId) {
                $userId = Auth::userId();
                // Verifica se o usuário logado é um médico cadastrado neste tenant
                if ($userId) {
                    $stmtMe = $pdo->prepare(
                        "SELECT id, nome FROM bi_medicos WHERE tenant_id = ? AND usuario_id = ? AND ativo = 1 LIMIT 1"
                    );
                    $stmtMe->execute([(int)$tenantId, (int)$userId]);
                    $meRow = $stmtMe->fetch(\PDO::FETCH_ASSOC);
                    if ($meRow) {
                        $isMedicoLogado   = true;
                        $medicoLogadoId   = (int)$meRow['id'];
                        $medicoLogadoNome = $meRow['nome'];
                    }
                }
                // Todos os perfis vêem todos os médicos ativos do tenant.
                // O médico logado tem seu nome pré-selecionado por conveniência,
                // mas pode alterar o filtro livremente (sem restrição de visibilidade).
                $stmtAll = $pdo->prepare(
                    "SELECT id, nome FROM bi_medicos WHERE tenant_id = ? AND ativo = 1 ORDER BY nome"
                );
                $stmtAll->execute([(int)$tenantId]);
                $medicos = $stmtAll->fetchAll(\PDO::FETCH_ASSOC);
                // Pré-seleciona o próprio médico apenas se nenhum filtro foi aplicado
                // (conveniência — o usuário pode limpar e buscar qualquer médico)
                if ($isMedicoLogado && $filtros['medico'] === '') {
                    $filtros['medico'] = $medicoLogadoNome;
                }
            } elseif ($bypassGlobal) {
                // Superadmin sem impersonação: lista todos os médicos de todos os tenants
                $medicos = $pdo->query(
                    "SELECT id, nome FROM bi_medicos WHERE ativo = 1 ORDER BY nome"
                )->fetchAll(\PDO::FETCH_ASSOC);
            }
        } catch (\Throwable $ex) {
            error_log('[EstudosController::index] medicos: ' . $ex->getMessage());
            $medicos = [];
        }

        // ── Contadores topbar (usa InstitutionNames para consistência com a tabela) ───────
        $contadores = ['novo'=>0,'aberto'=>0,'em_laudo'=>0,'rascunho'=>0,'assinado'=>0,'liberado'=>0,'urgente'=>0];
        try {
            $cWhere  = ['servidor_id = 1'];
            $cParams = [];
            if ($usaInstitutionFilter) {
                if (!empty($institutionNames)) {
                    $cPh      = implode(',', array_fill(0, count($institutionNames), '?'));
                    $cWhere[] = "institution_name IN ({$cPh})";
                    foreach ($institutionNames as $iName) { $cParams[] = $iName; }
                } else {
                    $cWhere[]  = 'tenant_id = ?';
                    $cParams[] = $tenantId;
                }
            } elseif (!$bypassGlobal) {
                $cWhere[] = '1=0';
            }
            $cBase = implode(' AND ', $cWhere);

            $cStmt = $pdo->prepare("SELECT COALESCE(situacao,'novo') AS situacao, COUNT(*) AS total FROM bi_pacs_estudos WHERE {$cBase} GROUP BY situacao");
            $cStmt->execute($cParams);
            foreach ($cStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                if (isset($contadores[$r['situacao']])) $contadores[$r['situacao']] = (int)$r['total'];
            }
            $uStmt = $pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos WHERE {$cBase} AND prioridade IN ('urgente','critico')");
            $uStmt->execute($cParams);
            $contadores['urgente'] = (int)$uStmt->fetchColumn();
        } catch (\Throwable $ex) {
            error_log('[EstudosController::index] contadores: ' . $ex->getMessage());
        }

        // ── Painel de resumo (usa InstitutionNames para consistência com a tabela) ────────
        $resumo = ['hoje'=>0,'semana'=>0,'mes'=>0,'urgentes'=>$contadores['urgente'],'total'=>0];
        try {
            $rWhere  = ['servidor_id = 1'];
            $rBase_p = []; // params base sem data
            if ($usaInstitutionFilter) {
                if (!empty($institutionNames)) {
                    $rPh      = implode(',', array_fill(0, count($institutionNames), '?'));
                    $rWhere[] = "institution_name IN ({$rPh})";
                    foreach ($institutionNames as $iName) { $rBase_p[] = $iName; }
                } else {
                    $rWhere[]  = 'tenant_id = ?';
                    $rBase_p[] = $tenantId;
                }
            } elseif (!$bypassGlobal) {
                $rWhere[] = '1=0';
            }
            $rBase = implode(' AND ', $rWhere);

            $s = $pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos WHERE {$rBase} AND study_date = ?");
            $s->execute(array_merge($rBase_p, [$today]));
            $resumo['hoje'] = (int)$s->fetchColumn();

            $s = $pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos WHERE {$rBase} AND study_date >= ?");
            $s->execute(array_merge($rBase_p, [date('Y-m-d', strtotime('-6 days'))]));
            $resumo['semana'] = (int)$s->fetchColumn();

            $s = $pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos WHERE {$rBase} AND study_date >= ?");
            $s->execute(array_merge($rBase_p, [date('Y-m-d', strtotime('-29 days'))]));
            $resumo['mes'] = (int)$s->fetchColumn();

            $s = $pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos WHERE {$rBase}");
            $s->execute($rBase_p);
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

        // Impede que o browser (BFCache) sirva esta página do cache ao navegar
        // de volta — garante que filtros sempre reflitam a URL atual.
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        $this->view('estudos/index', compact(
            'estudos','filtros','total','totalPages','currentPage',
            'unidades','medicos','contadores','resumo',
            'tempoConsulta','ultimaSinc','isAdmin','isMedicoLogado',
            'modsAtivas'
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

    // =========================================================================
    // Abertura em visualizadores desktop (RadiAnt / Weasis)
    // Complementa abrir() (OHIF) acima — não altera nada do fluxo existente.
    // =========================================================================

    public function abrirRadiant(int $id): void
    {
        $this->abrirDesktop($id, 'radiant');
    }

    public function abrirWeasis(int $id): void
    {
        $this->abrirDesktop($id, 'weasis');
    }

    private function abrirDesktop(int $id, string $viewer): void
    {
        $inicio   = microtime(true);
        $tenantId = Auth::tenantId();
        $service  = new DesktopViewerService();

        $contexto = [
            'tenant_id'  => $tenantId,
            'study_id'   => $id,
            'viewer'     => $viewer,
            'usuario_id' => Auth::userId(),
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];

        // ── RBAC — mesma permissão que já governa o módulo Estudos ──────────
        if (!Auth::can('view_exames')) {
            $service->registrarAcesso($contexto + [
                'status'        => 'negado',
                'mensagem_erro' => 'Usuário sem permissão view_exames.',
            ]);
            $this->renderErroViewer(403, 'Você não tem permissão para visualizar este estudo.');
            return;
        }

        // ── Busca do estudo — mesmo escopo tenant-aware usado em abrir() ────
        $pdo          = Database::getInstance();
        $isAdmin      = Auth::isPlatformAdmin();
        $bypassGlobal = $isAdmin && !Auth::isImpersonating();
        $estudo       = null;

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
                "SELECT id, orthanc_id, study_instance_uid, patient_id, patient_name,
                        accession_number, tenant_id
                 FROM bi_pacs_estudos WHERE {$where} LIMIT 1"
            );
            $stmt->execute($params);
            $estudo = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $ex) {
            error_log("[EstudosController::abrirDesktop:{$viewer}] " . $ex->getMessage());
        }

        if (!$estudo) {
            $service->registrarAcesso($contexto + [
                'status'        => 'erro',
                'mensagem_erro' => 'Estudo não encontrado ou sem permissão de acesso.',
            ]);
            $this->renderErroViewer(404, 'Estudo não encontrado ou sem permissão de acesso.');
            return;
        }

        $contexto['patient_id']         = $estudo['patient_id']         ?? null;
        $contexto['study_instance_uid'] = $estudo['study_instance_uid'] ?? null;
        $contexto['accession_number']   = $estudo['accession_number']   ?? null;

        if (empty($estudo['study_instance_uid'])) {
            $service->registrarAcesso($contexto + [
                'status'        => 'erro',
                'mensagem_erro' => 'Este estudo não possui StudyInstanceUID.',
            ]);
            $this->renderErroViewer(422, 'Este estudo não possui StudyInstanceUID.');
            return;
        }

        // ── Resolve config (tenant > servidor PACS global) e valida ─────────
        $config = $service->resolverConfig($tenantId, $viewer);
        if (!$service->validarConfig($config)) {
            $service->registrarAcesso($contexto + [
                'status'        => 'erro',
                'mensagem_erro' => 'Configuração de conexão DICOM não encontrada para este tenant/viewer.',
            ]);
            $this->renderErroViewer(
                500,
                'Este visualizador ainda não foi configurado para a sua empresa. Peça ao administrador para configurar em Configurações › Visualizadores Desktop.'
            );
            return;
        }

        $launcherUri = $viewer === 'radiant'
            ? $service->gerarLauncherRadiant($estudo, $config)
            : $service->gerarLauncherWeasis($estudo, $config);

        $service->registrarAcesso($contexto + [
            'status'            => 'sucesso',
            'tempo_execucao_ms' => (int) round((microtime(true) - $inicio) * 1000),
        ]);

        $this->renderLauncherDesktop($viewer, $launcherUri);
    }

    /**
     * Página intermediária que tenta abrir o protocolo do viewer desktop e,
     * caso a aba continue visível após um pequeno intervalo (heurística
     * padrão de mercado para detectar handler de protocolo ausente), mostra
     * uma mensagem amigável com link de download do visualizador.
     */
    private function renderLauncherDesktop(string $viewer, string $launcherUri): void
    {
        $nomeViewer   = $viewer === 'radiant' ? 'RadiAnt Viewer' : 'Weasis Viewer';
        $downloadUrl  = $viewer === 'radiant'
            ? 'https://www.radiantviewer.com/download/'
            : 'https://weasis.org/en/download/';
        $iconSrc      = $viewer === 'radiant'
            ? '/assets/img/icon-radiant.ico'
            : '/assets/img/icon-weasis.svg';
        ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOXEL PACS — Abrindo <?= htmlspecialchars($nomeViewer) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #0d1117; color: #e6edf3;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh;
        }
        .card {
            background: #161b22; border: 1px solid #30363d; border-radius: 12px;
            padding: 2.5rem 3rem; max-width: 480px; width: 90%; text-align: center;
        }
        .icon { font-size: 3rem; margin-bottom: 1rem; }
        .icon img { width: 56px; height: 56px; object-fit: contain; }
        h1 { font-size: 1.3rem; margin-bottom: .75rem; color: #f0f6fc; }
        p  { font-size: .92rem; color: #8b949e; line-height: 1.6; }
        .btn {
            display: inline-block; margin-top: 1.25rem; padding: .6rem 1.4rem;
            background: #1a56db; color: #fff; border-radius: 8px;
            text-decoration: none; font-weight: 600; font-size: .85rem;
        }
        #fallback { display: none; }
    </style>
</head>
<body>
    <div class="card" id="tentando">
        <div class="icon"><img src="<?= htmlspecialchars($iconSrc) ?>" alt="<?= htmlspecialchars($nomeViewer) ?>"></div>
        <h1>Abrindo no <?= htmlspecialchars($nomeViewer) ?>…</h1>
        <p>Se nada acontecer em alguns segundos, o <?= htmlspecialchars($nomeViewer) ?> pode não estar instalado neste computador.</p>
    </div>
    <div class="card" id="fallback">
        <div class="icon">⚠️</div>
        <h1><?= htmlspecialchars($nomeViewer) ?> não encontrado</h1>
        <p>Não conseguimos detectar o <?= htmlspecialchars($nomeViewer) ?> instalado. Deseja instalar?</p>
        <a class="btn" href="<?= htmlspecialchars($downloadUrl) ?>" target="_blank" rel="noopener">Download</a>
    </div>
    <script>
        window.location.href = <?= json_encode($launcherUri) ?>;
        setTimeout(function () {
            if (!document.hidden) {
                document.getElementById('tentando').style.display = 'none';
                document.getElementById('fallback').style.display = 'block';
            }
        }, 1500);
    </script>
</body>
</html>
        <?php
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
            $where[]  = 'tenant_id = ?';
            $params[] = $tenantId;
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

    // ─────────────────────────────────────────────────────────────────────────
    // ASSUMIR ESTUDO — endpoint AJAX exclusivo para médicos
    // POST /api/estudos/assumir   body: { estudo_id: int }
    // Muda situacao: novo|aberto → a_laudar
    // Registra assumido_em, assumido_por (nome médico), usuario_responsavel_id
    // ─────────────────────────────────────────────────────────────────────────
    public function assumirEstudo(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!Auth::check()) {
            echo json_encode(['ok' => false, 'msg' => 'Não autenticado.']);
            return;
        }

        $input    = json_decode(file_get_contents('php://input'), true) ?? [];
        $estudoId = (int)($input['estudo_id'] ?? 0);
        $userId   = Auth::userId();
        $tenantId = Auth::tenantId();
        $isAdmin  = Auth::isPlatformAdmin();

        if (!$estudoId) {
            echo json_encode(['ok' => false, 'msg' => 'ID inválido.']);
            return;
        }

        $pdo = Database::getInstance();

        try {
            // Verifica se o usuário é médico cadastrado neste tenant
            $nomeMedico = null;
            if ($tenantId) {
                $stmtMed = $pdo->prepare(
                    'SELECT id, nome FROM bi_medicos WHERE tenant_id = ? AND usuario_id = ? AND ativo = 1 LIMIT 1'
                );
                $stmtMed->execute([$tenantId, $userId]);
                $medico = $stmtMed->fetch(\PDO::FETCH_ASSOC);
                if ($medico) {
                    $nomeMedico = $medico['nome'];
                }
            }

            // Admins também podem assumir (sem restrição de médico)
            if (!$nomeMedico && !$isAdmin) {
                echo json_encode(['ok' => false, 'msg' => 'Apenas médicos podem assumir estudos.']);
                return;
            }

            if (!$nomeMedico) {
                $u = Auth::user();
                $nomeMedico = $u->nome ?? $u->name ?? 'Admin';
            }

            // Verifica se o estudo pertence ao tenant e está em estado assumível
            $tWhere = $tenantId ? 'AND tenant_id = ?' : '';
            $tParam = $tenantId ? [$estudoId, $tenantId] : [$estudoId];

            $stmtCheck = $pdo->prepare(
                "SELECT id, situacao, assumido_por FROM bi_pacs_estudos WHERE id = ? {$tWhere} LIMIT 1"
            );
            $stmtCheck->execute($tParam);
            $estudo = $stmtCheck->fetch(\PDO::FETCH_ASSOC);

            if (!$estudo) {
                echo json_encode(['ok' => false, 'msg' => 'Estudo não encontrado.']);
                return;
            }

            $sit = $estudo['situacao'] ?? 'novo';
            if (!in_array($sit, ['novo', 'aberto', ''])) {
                echo json_encode([
                    'ok'       => false,
                    'msg'      => 'Este estudo já foi assumido ou está em outro estado (' . strtoupper(str_replace('_',' ',$sit)) . ').',
                    'situacao' => $sit,
                ]);
                return;
            }

            // Atualiza o estudo
            $pdo->prepare(
                "UPDATE bi_pacs_estudos SET
                    situacao               = 'a_laudar',
                    assumido_por           = ?,
                    assumido_em            = NOW(),
                    usuario_responsavel_id = ?
                 WHERE id = ?"
            )->execute([$nomeMedico, $userId, $estudoId]);

            \App\Core\Logger::info("[EstudosController::assumirEstudo] estudo_id={$estudoId} medico={$nomeMedico} user_id={$userId}");

            echo json_encode([
                'ok'           => true,
                'msg'          => 'Estudo assumido com sucesso.',
                'situacao'     => 'a_laudar',
                'assumido_por' => $nomeMedico,
                'assumido_em'  => date('Y-m-d H:i:s'),
            ]);

        } catch (\Throwable $e) {
            \App\Core\Logger::error('[EstudosController::assumirEstudo] ' . $e->getMessage());
            echo json_encode(['ok' => false, 'msg' => 'Erro interno. Tente novamente.']);
        }
    }

    /**
     * Mapeia o valor bruto da tag DICOM (0040,1003) ScheduledProcedureStepPriority
     * para o label de exibição na worklist.
     *
     * @param  string|null $dicomValue  Valor bruto do banco (ex: 'STAT', 'HIGH', 'ROUTINE', ...)
     * @param  string      $lang        Idioma do tenant ('pt_BR', 'en', 'es')
     * @return array{label: string, css: string, key: string}
     */
    public static function mapPriorityLabel(?string $dicomValue, string $lang = 'pt_BR'): array
    {
        $val = strtoupper(trim((string)$dicomValue));

        // Mapeamento DICOM → chave interna (fail-safe: qualquer valor não mapeado → rotina)
        $map = [
            'STAT'    => 'emergencia',
            'HIGH'    => 'urgencia',
            'ROUTINE' => 'rotina',
            'MEDIUM'  => 'rotina',
            'LOW'     => 'ambulatorial',
        ];
        $key = $map[$val] ?? 'rotina';

        // Traduções por idioma
        $labels = [
            'pt_BR' => [
                'emergencia'   => 'Emergência',
                'urgencia'     => 'Urgência',
                'rotina'       => 'Rotina',
                'ambulatorial' => 'Ambulatorial',
            ],
            'en' => [
                'emergencia'   => 'Emergency',
                'urgencia'     => 'Urgent',
                'rotina'       => 'Routine',
                'ambulatorial' => 'Outpatient',
            ],
            'es' => [
                'emergencia'   => 'Emergencia',
                'urgencia'     => 'Urgente',
                'rotina'       => 'Rutina',
                'ambulatorial' => 'Ambulatorio',
            ],
        ];

        // Classe CSS por prioridade
        $css = [
            'emergencia'   => 'wl-prio-emergencia',   // vermelho  #DC2626
            'urgencia'     => 'wl-prio-urgencia',     // laranja   #F97316
            'rotina'       => 'wl-prio-rotina',       // azul      #3B82F6
            'ambulatorial' => 'wl-prio-ambulatorial', // verde     #22C55E
        ];

        $langKey = isset($labels[$lang]) ? $lang : 'pt_BR';

        return [
            'label' => $labels[$langKey][$key],
            'css'   => $css[$key],
            'key'   => $key,
        ];
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
