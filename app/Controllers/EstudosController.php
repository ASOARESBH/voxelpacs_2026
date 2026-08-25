<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\SqlHelper;
use App\Core\Auth;
use App\Core\Access\MedicoAccess;
use App\Services\DesktopViewerService;
use App\Services\InstitutionResolverService;
use App\Services\GrupoModalidadeService;
use App\Services\PedidoMedicoService;

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
 *   paciente      → patient_name normalizado por termos
 *   periodo       → hoje|ontem|7dias|30dias|90dias|ano|todos|personalizado
 *   dt_inicio/dt_fim → usado com periodo=personalizado
 *   unidade       → institution_name LIKE
 *   modalidade    → modalities LIKE
 *   especialidade → solicitante normalizado por termos (especialidade e
 *                   referring_physician_name)
 *   situacao      → novo|aberto|pendente|a_laudar|em_laudo|rascunho|assinado|liberado|peer_review
 *   prioridade    → normal|urgente|critico
 *   medico        → assumido_por normalizado por termos
 *   ordenar       → whitelist de colunas
 *   direcao       → ASC|DESC
 *   pagina        → int
 *   por_pagina    → 25|50|100|250|0 (0=todos)
 */
class EstudosController extends Controller
{
    private const COLUNAS_ORDEM = [
        'study_date','study_time','patient_name','institution_name',
        'modalities','especialidade','prioridade','situacao','study_description',
    ];

    /**
     * Define uma única vez a coorte clínica visível na Worklist.
     *
     * O mesmo escopo é usado pela tabela, pelos resumos e pelo endpoint dos
     * badges para impedir que um contador represente estudos fora do resultado
     * que o médico efetivamente pode abrir.
     *
     * @return array{where: string[], params: array<int, mixed>, institutionNames: string[], usaInstitutionFilter: bool, isMedicoFiltro: bool}
     */
    private function resolverEscopoWorklist(?int $tenantId, bool $bypassGlobal, int $usuarioLogadoId): array
    {
        $where                = ['1=1'];
        $params               = [];
        $institutionNames     = [];
        $usaInstitutionFilter = false;
        $isMedicoFiltro       = MedicoAccess::isRestricted();

        if ($tenantId && !$bypassGlobal) {
            $institutionNames     = InstitutionResolverService::getInstitutionNamesByTenant($tenantId);
            $usaInstitutionFilter = true;
        }

        if ($isMedicoFiltro) {
            $institutionNames     = MedicoAccess::allowedInstitutionNames();
            $usaInstitutionFilter = true;
        }

        if ($usaInstitutionFilter) {
            if (!empty($institutionNames)) {
                $placeholders = implode(',', array_fill(0, count($institutionNames), '?'));
                $where[]      = "e.institution_name IN ({$placeholders})";
                foreach ($institutionNames as $institutionName) {
                    $params[] = $institutionName;
                }
            } elseif ($isMedicoFiltro) {
                // Médico sem Unidade vinculada não pode herdar a visão do tenant.
                $where[] = '1=0';
            } else {
                // Preserva o fallback histórico para tenant sem InstitutionName.
                $where[]  = 'e.tenant_id = ?';
                $params[] = $tenantId;
            }
        } elseif (!$bypassGlobal) {
            $where[] = '1=0';
        }

        // Posse exclusiva: médico vê a fila livre e os estudos que assumiu.
        if ($isMedicoFiltro && $usuarioLogadoId > 0) {
            $where[]  = "(COALESCE(e.situacao, 'novo') IN ('novo', 'aberto') OR e.usuario_responsavel_id = ?)";
            $params[] = $usuarioLogadoId;
        }

        // Tenant admins respeitam as regras do próprio negócio. Somente o
        // superadmin fora de impersonação mantém bypass do escopo clínico.
        $modalityScope = ['restricted' => false, 'modalities' => []];
        if ($tenantId && !$bypassGlobal && $usuarioLogadoId > 0) {
            $modalidadeService = new GrupoModalidadeService();
            $modalityScope = $modalidadeService->scopeForUser($usuarioLogadoId, $tenantId);
            $modalidadeService->appendStudyScope($where, $params, $modalityScope);
        }

        return compact('where', 'params', 'institutionNames', 'usaInstitutionFilter', 'isMedicoFiltro', 'modalityScope');
    }

    private function podeAbrirPorModalidade(array $estudo, ?int $tenantId, bool $bypassGlobal): bool
    {
        if (!$tenantId || $bypassGlobal || !(int) Auth::userId()) return true;
        $service = new GrupoModalidadeService();
        $scope = $service->scopeForUser((int) Auth::userId(), (int) $tenantId);
        return $service->allowsStoredModalities((string) ($estudo['modalities'] ?? ''), $scope);
    }

    /**
     * Acrescenta termos normalizados à cláusula WHERE sem interpolar dados do
     * usuário. Cada termo deve ocorrer em algum campo informado, enquanto os
     * termos entre si são combinados com AND.
     *
     * @param list<string> $where
     * @param list<mixed> $params
     * @param list<string> $fields
     */
    private function aplicarBuscaNormalizada(array &$where, array &$params, string $consulta, array $fields): void
    {
        $tokens = SqlHelper::searchTokens($consulta);
        if ($tokens === [] || $fields === []) {
            return;
        }

        $normalizedFields = array_map(
            static fn (string $field): string => SqlHelper::normalizedSearchExpression($field),
            $fields
        );

        foreach ($tokens as $token) {
            $where[] = '(' . implode(' OR ', array_map(
                static fn (string $field): string => "{$field} LIKE ?",
                $normalizedFields
            )) . ')';

            foreach ($normalizedFields as $_) {
                $params[] = '%' . $token . '%';
            }
        }
    }

    public function index(): void
    {
        $this->renderWorklist(false);
    }

    /** Worklist administrativa: mesmos filtros e estudos, sem abrir/laudar. */
    public function gestao(): void
    {
        $this->renderWorklist(true);
    }

    private function renderWorklist(bool $modoGestao): void
    {
        $pdo      = Database::getInstance();
        $tenantId = Auth::tenantId();
        $isAdmin  = Auth::isPlatformAdmin()
                    || in_array(Auth::perfilAtual(), ['admin', 'administrador']);
        // Bypass total (vê todos os tenants) só para superadmin fora de impersonação.
        $bypassGlobal = Auth::isPlatformAdmin() && !Auth::isImpersonating();
        // Reutilizada pelo painel-resumo; deve existir independentemente do período filtrado.
        $today = date('Y-m-d');

        // A migration da tag (0040,0007) é incremental: durante a implantação,
        // a Worklist continua funcional e passa a usá-la assim que a coluna existir.
        $hasScheduledProcedureStepDescription = false;
        try {
            $hasScheduledProcedureStepDescription = SqlHelper::hasColumn(
                $pdo,
                'bi_pacs_estudos',
                'scheduled_procedure_step_desc'
            );
        } catch (\Throwable $ex) {
            error_log('[EstudosController::index] descricao agendada: ' . $ex->getMessage());
        }

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

        // "situacao" é a escolha explícita do select principal. O atalho rápido
        // só fornece um padrão quando o campo principal não veio no formulário;
        // assim, um valor antigo de situacao_rapida não reverte uma troca recente.
        if ($filtros['situacao'] === '' && $filtros['situacao_rapida'] !== '') {
            $filtros['situacao'] = $filtros['situacao_rapida'];
        }

        // Um parâmetro de URL não pode ampliar o escopo clínico do médico.
        // Em vez de retornar uma Worklist vazia e sem explicação, descarta uma
        // Unidade fora do vínculo e mantém a visão das Unidades autorizadas.
        if ($filtros['unidade'] !== '' && !MedicoAccess::isInstitutionAllowed($filtros['unidade'])) {
            $filtros['unidade'] = '';
        }

        // ── Calcular datas do período ──────────────────────────────────────────────────────
        [$filtros['dt_inicio'], $filtros['dt_fim']] = $this->resolverIntervaloPeriodo(
            $filtros['periodo'],
            $filtros['dt_inicio'],
            $filtros['dt_fim']
        );

        // Identidade usada na posse exclusiva do estudo. A FK operacional é
        // bi_pacs_estudos.usuario_responsavel_id -> bi_users.id.
        $usuarioLogadoId = (int) Auth::userId();
        $escopoWorklist  = $this->resolverEscopoWorklist($tenantId, $bypassGlobal, $usuarioLogadoId);
        $institutionNames     = $escopoWorklist['institutionNames'];
        $usaInstitutionFilter = $escopoWorklist['usaInstitutionFilter'];
        $isMedicoFiltro       = $escopoWorklist['isMedicoFiltro'];

        // ── WHERE dinâmico ────────────────────────────────────────────────────────────────
        // REGRA CRÍTICA: TODOS os parâmetros são posicionais (?) — nunca misturar com :nome
        $where  = $escopoWorklist['where'];
        $params = $escopoWorklist['params'];

        // Pesquisa global: cada termo é normalizado e comparado por parâmetros
        // preparados contra os campos clínicos permitidos no escopo do tenant.
        $searchFields = [
            'e.patient_name',
            'e.patient_id',
            'e.study_instance_uid',
            'e.accession_number',
            'e.study_description',
            'e.institution_name',
        ];
        if ($hasScheduledProcedureStepDescription) {
            $searchFields[] = 'e.scheduled_procedure_step_desc';
        }
        $this->aplicarBuscaNormalizada($where, $params, $filtros['q'], $searchFields);
        $this->aplicarBuscaNormalizada($where, $params, $filtros['paciente'], ['e.patient_name']);
        $campoDataPeriodo = $this->campoDataPeriodoParaSituacao($filtros['situacao']);
        if ($filtros['dt_inicio'] !== '') {
            $where[]  = $campoDataPeriodo . ' >= ?';
            $params[] = $filtros['dt_inicio'];
        }
        if ($filtros['dt_fim'] !== '') {
            $where[]  = $campoDataPeriodo . ' <= ?';
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
        // Solicitante: aceita variações de caixa, acento e composição do nome.
        $this->aplicarBuscaNormalizada(
            $where,
            $params,
            $filtros['especialidade'],
            ['e.especialidade', 'e.referring_physician_name']
        );
        if ($filtros['situacao'] !== '') {
            $where[]  = "COALESCE(e.situacao,'novo') = ?";
            $params[] = $filtros['situacao'];
        }
        if ($filtros['prioridade'] !== '') {
            $where[]  = 'e.prioridade = ?';
            $params[] = $filtros['prioridade'];
        }
        $this->aplicarBuscaNormalizada($where, $params, $filtros['medico'], ['e.assumido_por']);

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
        // O override é opcional para manter a Worklist funcional durante o deploy da migration.
        $hasPriorityOverride = false;
        try {
            $hasPriorityOverride = SqlHelper::hasColumn($pdo, 'bi_pacs_estudos', 'dicom_priority_override');
        } catch (\Throwable $ex) {
            error_log('[EstudosController::index] prioridade override: ' . $ex->getMessage());
        }
        $priorityEffectiveSql = $hasPriorityOverride
            ? "COALESCE(NULLIF(e.dicom_priority_override, ''), e.dicom_priority, 'ROUTINE')"
            : "COALESCE(e.dicom_priority, 'ROUTINE')";
        $scheduledProcedureStepDescriptionSql = $hasScheduledProcedureStepDescription
            ? "COALESCE(e.scheduled_procedure_step_desc, '')"
            : "''";

        // Migrations de pedido, chat e token público são incrementais. A ausência
        // delas não pode tornar a Worklist inteira vazia, especialmente durante
        // restaurações e homologações de uma cópia histórica do banco.
        $hasPedidos = false;
        $hasReports = false;
        $hasChats = false;
        $hasReportPublicToken = false;
        $hasReportSituacao = false;
        $hasChatStatus = false;
        try {
            $hasPedidos = SqlHelper::hasTable($pdo, 'bi_pacs_estudos_pedidos');
            $hasReports = SqlHelper::hasTable($pdo, 'reports');
            $hasChats = $hasReports && SqlHelper::hasTable($pdo, 'pacs_report_chats');
            $hasReportPublicToken = $hasReports && SqlHelper::hasColumn($pdo, 'reports', 'public_token');
            $hasReportSituacao = $hasReports && SqlHelper::hasColumn($pdo, 'reports', 'situacao');
            $hasChatStatus = $hasChats && SqlHelper::hasColumn($pdo, 'pacs_report_chats', 'status');
        } catch (\Throwable $ex) {
            Logger::warning('[EstudosController::index] joins opcionais indisponíveis', [
                'tenant_id' => $tenantId,
                'error' => $ex->getMessage(),
            ]);
        }

        $pedidoSelectSql = $hasPedidos
            ? "p.id AS pedido_id, p.nome_original AS pedido_nome_original, p.mime_type AS pedido_mime_type,
               p.tamanho_bytes AS pedido_tamanho_bytes, p.caminho_arquivo AS pedido_caminho_arquivo"
            : "NULL AS pedido_id, NULL AS pedido_nome_original, NULL AS pedido_mime_type,
               NULL AS pedido_tamanho_bytes, NULL AS pedido_caminho_arquivo";
        $reportPublicTokenSql = $hasReportPublicToken ? "COALESCE(r.public_token, '')" : "''";
        // pgloader preserva ENUMs como tipos PostgreSQL. Um COALESCE direto com
        // string vazia tenta converter '' para o ENUM e aborta a consulta; texto
        // explícito conserva o contrato da view sem afetar MySQL.
        $reportSituacaoSql = $hasReportSituacao
            ? (SqlHelper::isPostgres() ? "COALESCE(r.situacao::text, '')" : "COALESCE(r.situacao, '')")
            : "''";
        $chatStatusSql = $hasChats && $hasChatStatus
            ? (SqlHelper::isPostgres() ? "COALESCE(c.status::text, '')" : "COALESCE(c.status, '')")
            : "''";
        $reportSelectSql = $hasReports
            ? "r.id AS report_id, {$reportPublicTokenSql} AS report_public_token, {$reportSituacaoSql} AS report_situacao"
            : "NULL AS report_id, '' AS report_public_token, '' AS report_situacao";
        $chatSelectSql = "{$chatStatusSql} AS chat_status";
        $pedidoJoinSql = $hasPedidos
            ? 'LEFT JOIN bi_pacs_estudos_pedidos p ON p.estudo_id = e.id AND p.tenant_id = e.tenant_id'
            : '';
        $reportJoinSql = $hasReports
            ? 'LEFT JOIN reports r ON r.estudo_id = e.id AND r.tenant_id = e.tenant_id'
            : '';
        $chatJoinSql = $hasChats
            ? 'LEFT JOIN pacs_report_chats c ON c.report_id = r.id AND c.tenant_id = r.tenant_id'
            : '';

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
                    {$priorityEffectiveSql}            AS dicom_priority_effective,
                    COALESCE(e.assumido_por, '')       AS assumido_por,
                    e.usuario_responsavel_id,
                    e.assumido_em,
                    e.laudo_assinado_em,
                    e.urgente_em,
                    e.achado_critico_em,
                    e.achado_critico_por,
                    e.achado_critico_assunto,
                    e.importado_em,
                    e.atualizado_em,
                    COALESCE(e.recebido_em, e.importado_em) AS recebido_em,
                    COALESCE(e.body_part_examined, '')          AS body_part_examined,
                    {$scheduledProcedureStepDescriptionSql}      AS scheduled_procedure_step_desc,
                    COALESCE(e.requested_procedure_desc, '')    AS requested_procedure_desc,
                    {$pedidoSelectSql},
                    {$reportSelectSql},
                    {$chatSelectSql}
                FROM bi_pacs_estudos e
                {$pedidoJoinSql}
                {$reportJoinSql}
                {$chatJoinSql}
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
        // Médico restrito recebe diretamente as Unidades vinculadas ao seu cadastro.
        // Isso mantém dropdown e WHERE na mesma fonte e inclui Unidades recém-vinculadas,
        // mesmo antes da chegada do primeiro estudo importado.
        $unidades = [];
        if ($isMedicoFiltro) {
            $unidades = MedicoAccess::allowedInstitutionNames();
            sort($unidades, SORT_STRING);
        } else {
            // Fonte primária: bi_negocio_institution_names (unidades oficiais do tenant)
            try {
                $uSql = "SELECT institution_name FROM bi_negocio_institution_names WHERE ativo = 1 AND institution_name IS NOT NULL AND institution_name != ''";
                if ($tenantId) $uSql .= ' AND tenant_id = ' . (int)$tenantId;
                $uSql .= ' ORDER BY institution_name';
                $unidades = $pdo->query($uSql)->fetchAll(\PDO::FETCH_COLUMN);
            } catch (\Throwable $ex) { $unidades = []; }
            // Fallback: InstitutionNames dos estudos PACS (tenant sem cadastro oficial)
            if (empty($unidades)) {
                try {
                    $uW = ["institution_name IS NOT NULL", "institution_name != ''"];
                    if ($tenantId) $uW[] = 'tenant_id = ' . (int)$tenantId;
                    $unidades = $pdo->query(
                        "SELECT DISTINCT institution_name FROM bi_pacs_estudos WHERE " . implode(' AND ', $uW) . " ORDER BY institution_name"
                    )->fetchAll(\PDO::FETCH_COLUMN);
                } catch (\Throwable $ex) { $unidades = []; }
            }
        }

        // ── Médicos para o dropdown ───────────────────────────────────────────────────────
        // Fonte: bi_medicos (cadastro oficial) — não mais DISTINCT assumido_por dos estudos.
        // Regra de visibilidade:
        //   - Médico logado (usuario_id = Auth::userId() em bi_medicos): vê APENAS o próprio nome
        //   - Admin/analista/viewer: vê todos os médicos ativos do tenant
        $medicos                  = []; // array de ['id'=>int, 'nome'=>string]
        $medicoLogadoId           = null;  // id em bi_medicos do usuário logado (se for médico)
        $medicoLogadoNome         = null;  // nome do médico logado
        $meRow                    = null;  // registro do médico logado no tenant
        $isMedicoLogado           = false; // flag: usuário logado é um médico cadastrado
        $workspaceLaudoHabilitado = false; // flag: médico tem Workspace Laudo habilitado
        // Permissão de visibilidade da coluna Médico:
        //   - false (padrão): médico vê apenas o próprio nome quando é o responsável
        //   - true: médico vê o nome de qualquer médico responsável (permissão especial)
        //   - Admin/não-médico: sempre vê (sem restrição)
        $podeVerMedicoLaudo       = false;
        try {
            if ($tenantId) {
                $userId = Auth::userId();
                // Verifica se o usuário logado é um médico cadastrado neste tenant
                if ($userId) {
                    $stmtMe = $pdo->prepare(
                        "SELECT id, nome, workspace_laudo_habilitado, COALESCE(ver_medico_laudo, 0) AS ver_medico_laudo FROM bi_medicos WHERE tenant_id = ? AND usuario_id = ? AND ativo = 1 LIMIT 1"
                    );
                    $stmtMe->execute([(int)$tenantId, (int)$userId]);
                    $meRow = $stmtMe->fetch(\PDO::FETCH_ASSOC);
                    if ($meRow) {
                        $isMedicoLogado              = true;
                        $medicoLogadoId              = (int)$meRow['id'];
                        $medicoLogadoNome            = $meRow['nome'];
                        $workspaceLaudoHabilitado    = !empty($meRow['workspace_laudo_habilitado']);
                        // Permissão especial: ver nome de outros médicos na coluna Médico
                        $podeVerMedicoLaudo          = !empty($meRow['ver_medico_laudo']);
                    }
                }
                // Médico restrito só pode escolher o próprio nome. Não pré-seleciona
                // filtros['medico']: a lista vazia continua exibindo também a fila livre
                // (NOVO/ABERTO) até que o médico escolha explicitamente seu nome.
                $onlyMedicoId = MedicoAccess::isRestricted()
                    ? MedicoAccess::currentMedicoId()
                    : null;
                if ($onlyMedicoId !== null && $meRow && (int)$meRow['id'] === $onlyMedicoId) {
                    $medicos = [[
                        'id'   => (int) $meRow['id'],
                        'nome' => $meRow['nome'],
                    ]];
                } else {
                    // Admin, superadmin, analista e viewer preservam a visão completa.
                    $stmtAll = $pdo->prepare(
                        "SELECT id, nome FROM bi_medicos WHERE tenant_id = ? AND ativo = 1 ORDER BY nome"
                    );
                    $stmtAll->execute([(int)$tenantId]);
                    $medicos = $stmtAll->fetchAll(\PDO::FETCH_ASSOC);
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

        // ── Contadores do carregamento inicial (mesma coorte da tabela) ───────────────────
        $contadores = [
            'novo'=>0,'aberto'=>0,'pendente'=>0,'a_laudar'=>0,'em_laudo'=>0,
            'rascunho'=>0,'assinado'=>0,'liberado'=>0,'peer_review'=>0,'urgente'=>0,'achado_critico'=>0,
        ];
        try {
            $cWhere  = $escopoWorklist['where'];
            $cParams = $escopoWorklist['params'];
            $campoDataBadge = $this->campoDataPeriodoPorRegistro();
            if ($filtros['dt_inicio'] !== '') {
                $cWhere[]  = $campoDataBadge . ' >= ?';
                $cParams[] = $filtros['dt_inicio'];
            }
            if ($filtros['dt_fim'] !== '') {
                $cWhere[]  = $campoDataBadge . ' <= ?';
                $cParams[] = $filtros['dt_fim'];
            }
            $cBase = implode(' AND ', $cWhere);
            $cStmt = $pdo->prepare(
                "SELECT COALESCE(e.situacao,'novo') AS situacao, COUNT(*) AS total
                 FROM bi_pacs_estudos e WHERE {$cBase} GROUP BY e.situacao"
            );
            $cStmt->execute($cParams);
            foreach ($cStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                if (isset($contadores[$r['situacao']])) {
                    $contadores[$r['situacao']] = (int) $r['total'];
                }
            }
            $uStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM bi_pacs_estudos e
                 WHERE {$cBase} AND e.prioridade IN ('urgente','critico')"
            );
            $uStmt->execute($cParams);
            $contadores['urgente'] = (int) $uStmt->fetchColumn();

            $aStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM bi_pacs_estudos e
                 WHERE {$cBase} AND e.achado_critico_em IS NOT NULL"
            );
            $aStmt->execute($cParams);
            $contadores['achado_critico'] = (int) $aStmt->fetchColumn();
        } catch (\Throwable $ex) {
            error_log('[EstudosController::index] contadores: ' . $ex->getMessage());
        }

        // ── Painel de resumo (usa InstitutionNames para consistência com a tabela) ────────
        $resumo = [
            'hoje'=>0,
            'semana'=>0,
            'mes'=>0,
            'urgentes'=>$contadores['urgente'],
            'achados_criticos'=>$contadores['achado_critico'],
            'total'=>0,
        ];
        try {
            $rWhere  = ['1=1'];
            $rBase_p = []; // params base sem data
            if ($usaInstitutionFilter) {
                if (!empty($institutionNames)) {
                    $rPh      = implode(',', array_fill(0, count($institutionNames), '?'));
                    $rWhere[] = "institution_name IN ({$rPh})";
                    foreach ($institutionNames as $iName) { $rBase_p[] = $iName; }
                } elseif ($isMedicoFiltro) {
                    $rWhere[] = '1=0';
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
            $s = $pdo->query("SELECT MAX(importado_em) FROM bi_pacs_estudos");
            $ultimaSinc = $s->fetchColumn() ?: '';
        } catch (\Throwable $ex) {}

        $urlWorklist          = $modoGestao ? '/gestao-exames' : '/estudos';
        $podeGerenciarPedido  = (new PedidoMedicoService())->podeGerenciar($tenantId, $bypassGlobal);
        $csrfToken             = $this->csrfToken();

        // Impede que o browser (BFCache) sirva esta página do cache ao navegar
        // de volta — garante que filtros sempre reflitam a URL atual.
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        $this->view('estudos/index', compact(
            'estudos','filtros','total','totalPages','currentPage',
            'unidades','medicos','contadores','resumo',
            'tempoConsulta','ultimaSinc','isAdmin','isMedicoLogado','workspaceLaudoHabilitado',
            'modsAtivas','modoGestao','urlWorklist','podeGerenciarPedido','csrfToken',
            'medicoLogadoNome','podeVerMedicoLaudo','usuarioLogadoId'
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
            $where  = 'id = :id';
            $params = [':id' => $id];
            if ($tenantId) {
                $where           .= ' AND tenant_id = :tid';
                $params[':tid']   = $tenantId;
            } elseif (!$bypassGlobal) {
                $where .= ' AND 1=0';
            }
            $stmt = $pdo->prepare(
                "SELECT id, orthanc_id, study_instance_uid, patient_name, modalities, tenant_id, servidor_id
                 FROM bi_pacs_estudos WHERE {$where} LIMIT 1"
            );
            $stmt->execute($params);
            $estudo = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $ex) {
            error_log('[EstudosController::abrir] ' . $ex->getMessage());
        }

        if (!$estudo || !$this->podeAbrirPorModalidade($estudo, $tenantId, $bypassGlobal)) {
            $this->renderErroViewer(404, 'Estudo não encontrado ou sem permissão de acesso.');
            return;
        }

        $studyUid  = $estudo['study_instance_uid'] ?? '';
        $orthancId = $estudo['orthanc_id']         ?? '';

        if (empty($studyUid) && empty($orthancId)) {
            $this->renderErroViewer(422, 'Este estudo não possui StudyInstanceUID.');
            return;
        }

        // ── FIX LGPD/UID: Se o study_instance_uid for numérico (inválido como UID DICOM),
        //    busca o UID real no Orthanc via orthanc_id e atualiza o banco.
        $uidInvalido = !empty($studyUid) && preg_match('/^\d+$/', $studyUid);
        if ($uidInvalido && !empty($orthancId)) {
            try {
                $servidorStmt = $pdo->prepare("SELECT url, usuario, senha FROM bi_pacs_servidor WHERE id = ? LIMIT 1");
                $servidorStmt->execute([$estudo['servidor_id']]);
                $servidor = $servidorStmt->fetch(\PDO::FETCH_ASSOC);
                if ($servidor) {
                    $orthanc = new \App\Services\OrthancService(
                        $servidor['url'],
                        $servidor['usuario'] ?? null,
                        \App\Core\Crypto::decrypt($servidor['senha'] ?? null),
                        10
                    );
                    $studyData = $orthanc->getStudy($orthancId);
                    $uidReal   = $studyData['MainDicomTags']['StudyInstanceUID'] ?? '';
                    if (!empty($uidReal) && !preg_match('/^\d+$/', $uidReal)) {
                        // Atualiza o banco com o UID correto
                        $pdo->prepare("UPDATE bi_pacs_estudos SET study_instance_uid=? WHERE id=?")
                            ->execute([$uidReal, $id]);
                        $studyUid = $uidReal;
                        error_log("[EstudosController::abrir] UID corrigido: {$studyUid} → {$uidReal} (estudo id={$id})");
                    }
                }
            } catch (\Throwable $ex) {
                error_log('[EstudosController::abrir] Falha ao corrigir UID: ' . $ex->getMessage());
            }
        }

        $uidParaViewer = $studyUid ?: $orthancId;

        // ── Token de uso único para abertura segura (LGPD) ──────────────────────
        $token = $this->gerarToken();
        try {
            $expiresAtSql = SqlHelper::futureTimestamp('HOUR', 2);
            $pdo->prepare("
                INSERT INTO pacs_viewer_tokens
                    (token, estudo_id, study_instance_uid, orthanc_id, tenant_id, usuario_id, ip_origem, expires_at)
                VALUES (:token,:estudo_id,:study_uid,:orthanc_id,:tenant_id,:usuario_id,:ip,{$expiresAtSql})
            ")->execute([
                ':token'      => $token,
                ':estudo_id'  => $id,
                ':study_uid'  => $uidParaViewer,
                ':orthanc_id' => $orthancId ?: null,
                ':tenant_id'  => Auth::tenantId() ?: null,
                ':usuario_id' => Auth::userId()   ?: null,
                ':ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (\Throwable $ex) {
            error_log('[EstudosController::abrir] log: ' . $ex->getMessage());
            // Fallback: redireciona direto se token falhar
            $ohifBase  = rtrim(getenv('VIEWER_URL') ?: 'https://view.voxelpacs.com.br', '/');
            header('Location: ' . $ohifBase . '/viewer?StudyInstanceUIDs=' . urlencode($uidParaViewer), true, 302);
            exit;
        }

        // Redireciona para o endpoint seguro /open/{token} no PHP (Hostgator)
        // O PHP (ViewerTokenController) valida o token e redireciona para o OHIF com o UID real
        // VIEWER_ERP_URL = https://server.voxelpacs.com.br (PHP Hostgator)
        // VIEWER_URL     = https://view.voxelpacs.com.br  (OHIF VPS)
        $erpBase   = rtrim(getenv('VIEWER_ERP_URL') ?: 'https://server.voxelpacs.com.br', '/');
        $viewerUrl = $erpBase . '/open/' . urlencode($token);
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

    // =========================================================================
    // Abertura via VOXEL Desktop (protocolo voxel://)
    // GET /estudos/{id}/abrir-voxel
    //
    // Fluxo (item 7/8 da especificação VOXEL Desktop):
    //  1. Valida permissão do usuário sobre o estudo
    //  2. Gera token temporário (60 min) em pacs_viewer_tokens
    //  3. Monta URI: voxel://open?study=UID&token=TOKEN&server=URL
    //  4. Exibe página intermediária que tenta abrir o protocolo voxel://
    //     e, se não instalado, oferece download do VOXEL Desktop
    // =========================================================================
    public function abrirVoxelDesktop(int $id): void
    {
        $pdo      = Database::getInstance();
        $tenantId = Auth::tenantId();
        $userId   = Auth::userId();
        $isAdmin  = Auth::isPlatformAdmin();
        $bypassGlobal = $isAdmin && !Auth::isImpersonating();

        // ── 1. Buscar estudo e validar permissão ───────────────────────────────────
        $estudo = null;
        try {
            $where  = 'id = :id AND servidor_id = 1';
            $params = [':id' => $id];
            if ($tenantId) {
                $where         .= ' AND tenant_id = :tid';
                $params[':tid'] = $tenantId;
            } elseif (!$bypassGlobal) {
                $where .= ' AND 1=0';
            }
            $stmt = $pdo->prepare(
                "SELECT id, orthanc_id, study_instance_uid, patient_name, modalities, tenant_id
                 FROM bi_pacs_estudos WHERE {$where} LIMIT 1"
            );
            $stmt->execute($params);
            $estudo = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $ex) {
            error_log('[EstudosController::abrirVoxelDesktop] ' . $ex->getMessage());
        }

        if (!$estudo || !$this->podeAbrirPorModalidade($estudo, $tenantId, $bypassGlobal)) {
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

        // ── 2. Gerar token temporário (60 min) em pacs_viewer_tokens ──────────────
        $token = $this->gerarToken();
        try {
            $expiresAtSql = SqlHelper::futureTimestamp('HOUR', 1);
            $pdo->prepare("
                INSERT INTO pacs_viewer_tokens
                    (token, estudo_id, study_instance_uid, orthanc_id, tenant_id, usuario_id, ip_origem, expires_at)
                VALUES (:token,:estudo_id,:study_uid,:orthanc_id,:tenant_id,:usuario_id,:ip,{$expiresAtSql})
            ")->execute([
                ':token'      => $token,
                ':estudo_id'  => $id,
                ':study_uid'  => $uidParaViewer,
                ':orthanc_id' => $orthancId ?: null,
                ':tenant_id'  => $tenantId ?: null,
                ':usuario_id' => $userId   ?: null,
                ':ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (\Throwable $ex) {
            error_log('[EstudosController::abrirVoxelDesktop] token: ' . $ex->getMessage());
        }

        // ── 3. Montar URI voxel:// ──────────────────────────────────────────────────────
        $serverUrl = rtrim(getenv('APP_URL') ?: 'https://server.voxelpacs.com.br', '/');
        $voxelUri  = 'voxel://open?' . http_build_query([
            'study'  => $uidParaViewer,
            'token'  => $token,
            'server' => $serverUrl,
        ]);

        // ── 4. Página intermediária ──────────────────────────────────────────────────────────────────
        $nomePaciente = htmlspecialchars($estudo['patient_name'] ?? 'Paciente');
        $downloadUrl  = '/desktop/download';
        ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOXEL PACS — Abrindo no VOXEL Desktop</title>
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
            padding: 2.5rem 3rem; max-width: 520px; width: 90%; text-align: center;
        }
        .logo { margin-bottom: 1.25rem; }
        .logo img { width: 64px; height: 64px; object-fit: contain; }
        h1 { font-size: 1.3rem; margin-bottom: .5rem; color: #f0f6fc; }
        .paciente { font-size: .85rem; color: #8b949e; margin-bottom: 1rem; }
        p  { font-size: .92rem; color: #8b949e; line-height: 1.6; }
        .spinner { display: inline-block; width: 28px; height: 28px; border: 3px solid #30363d;
                   border-top-color: #1a56db; border-radius: 50%; animation: spin .8s linear infinite;
                   margin-bottom: 1rem; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .btn-primary {
            display: inline-block; margin-top: 1.25rem; padding: .65rem 1.5rem;
            background: #1a56db; color: #fff; border-radius: 8px;
            text-decoration: none; font-weight: 600; font-size: .88rem;
        }
        .btn-secondary {
            display: inline-block; margin-top: .75rem; padding: .55rem 1.2rem;
            background: transparent; color: #8b949e; border: 1px solid #30363d; border-radius: 8px;
            text-decoration: none; font-size: .82rem;
        }
        #fallback { display: none; }
        .token-info { font-size: .72rem; color: #484f58; margin-top: 1.5rem; }
    </style>
</head>
<body>
    <!-- Estado 1: tentando abrir o VOXEL Desktop -->
    <div class="card" id="tentando">
        <div class="logo"><img src="/assets/img/logo-voxel-pacs.png" alt="VOXEL Desktop"></div>
        <div class="spinner"></div>
        <h1>Abrindo no VOXEL Desktop…</h1>
        <p class="paciente">Paciente: <?= $nomePaciente ?></p>
        <p>Aguarde enquanto o VOXEL Desktop é iniciado.<br>O estudo será aberto automaticamente.</p>
        <p class="token-info">Token válido por 60 minutos</p>
    </div>
    <!-- Estado 2: VOXEL Desktop não detectado -->
    <div class="card" id="fallback">
        <div class="logo"><img src="/assets/img/logo-voxel-pacs.png" alt="VOXEL Desktop"></div>
        <h1>VOXEL Desktop não encontrado</h1>
        <p class="paciente">Paciente: <?= $nomePaciente ?></p>
        <p>O VOXEL Desktop não está instalado neste computador.<br>Instale para abrir exames DICOM com um clique.</p>
        <a class="btn-primary" href="<?= htmlspecialchars($downloadUrl) ?>" target="_blank">&#11015; Baixar VOXEL Desktop</a><br>
        <a class="btn-secondary" href="/estudos/<?= $id ?>/abrir" target="_blank">Abrir no navegador (OHIF)</a>
    </div>
    <script>
    (function () {
        var uri = <?= json_encode($voxelUri) ?>;
        var tentou = false;
        // Tenta abrir o protocolo voxel://
        function tentarAbrir() {
            if (tentou) return;
            tentou = true;
            var iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            document.body.appendChild(iframe);
            var detectado = false;
            var t = setTimeout(function () {
                if (!detectado) mostrarFallback();
                try { document.body.removeChild(iframe); } catch(e){}
            }, 2500);
            try {
                iframe.src = uri;
                // Se o protocolo estiver registrado, o browser não lança erro
                // Consideramos sucesso após 800ms sem mostrar fallback
                setTimeout(function () {
                    detectado = true;
                    clearTimeout(t);
                    try { document.body.removeChild(iframe); } catch(e){}
                    // Redireciona para a worklist após 4s
                    setTimeout(function () { window.location.href = '/estudos'; }, 4000);
                }, 800);
            } catch (e) {
                clearTimeout(t);
                mostrarFallback();
            }
        }
        function mostrarFallback() {
            document.getElementById('tentando').style.display = 'none';
            document.getElementById('fallback').style.display  = 'block';
        }
        tentarAbrir();
    }());
    </script>
</body>
</html>
<?php
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
            $where  = 'id = :id';
            $params = [':id' => $id];
            if ($tenantId) {
                $where           .= ' AND tenant_id = :tid';
                $params[':tid']   = $tenantId;
            } elseif (!$bypassGlobal) {
                $where .= ' AND 1=0';
            }
            $stmt = $pdo->prepare(
                "SELECT id, orthanc_id, study_instance_uid, patient_id, patient_name, modalities,
                        accession_number, tenant_id, servidor_id
                 FROM bi_pacs_estudos WHERE {$where} LIMIT 1"
            );
            $stmt->execute($params);
            $estudo = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $ex) {
            error_log("[EstudosController::abrirDesktop:{$viewer}] " . $ex->getMessage());
        }

        if (!$estudo || !$this->podeAbrirPorModalidade($estudo, $tenantId, $bypassGlobal)) {
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
        $config = $service->resolverConfig($tenantId, $viewer, isset($estudo['servidor_id']) ? (int) $estudo['servidor_id'] : null);
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
        $pdo              = Database::getInstance();
        $tenantId         = Auth::tenantId();
        $bypassGlobal     = Auth::isPlatformAdmin() && !Auth::isImpersonating();
        $usuarioLogadoId  = (int) Auth::userId();
        $escopoWorklist   = $this->resolverEscopoWorklist($tenantId, $bypassGlobal, $usuarioLogadoId);
        $where            = $escopoWorklist['where'];
        $params           = $escopoWorklist['params'];

        $periodo = trim((string) ($_GET['periodo'] ?? '30dias'));
        if (!in_array($periodo, ['hoje', 'ontem', '7dias', '30dias', '90dias', 'ano', 'todos', 'personalizado'], true)) {
            $periodo = '30dias';
        }
        [$dtInicio, $dtFim] = $this->resolverIntervaloPeriodo(
            $periodo,
            trim((string) ($_GET['dt_inicio'] ?? '')),
            trim((string) ($_GET['dt_fim'] ?? ''))
        );
        $campoDataBadge = $this->campoDataPeriodoPorRegistro();
        if ($dtInicio !== '') {
            $where[]  = $campoDataBadge . ' >= ?';
            $params[] = $dtInicio;
        }
        if ($dtFim !== '') {
            $where[]  = $campoDataBadge . ' <= ?';
            $params[] = $dtFim;
        }

        try {
            $wBase = implode(' AND ', $where);
            $stmt  = $pdo->prepare(
                "SELECT COALESCE(e.situacao,'novo') AS situacao, COUNT(*) AS total
                 FROM bi_pacs_estudos e WHERE {$wBase} GROUP BY e.situacao"
            );
            $stmt->execute($params);

            $data = [
                'novo'        => 0,
                'aberto'      => 0,
                'pendente'    => 0,
                'a_laudar'    => 0,
                'em_laudo'    => 0,
                                'rascunho'    => 0, 'assinado' => 0, 'liberado' => 0,
                'peer_review'  => 0, 'urgente'  => 0, 'achado_critico' => 0,

            ];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                if (isset($data[$r['situacao']])) {
                    $data[$r['situacao']] = (int)$r['total'];
                }
            }

            // Urgentes (prioridade)
            $u = $pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos e WHERE {$wBase} AND e.prioridade IN ('urgente','critico')");
            $u->execute($params);
            $data['urgente'] = (int)$u->fetchColumn();

            $a = $pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos e WHERE {$wBase} AND e.achado_critico_em IS NOT NULL");
            $a->execute($params);
            $data['achado_critico'] = (int)$a->fetchColumn();

            $this->json($data);
        } catch (\Throwable $ex) {
            error_log('[EstudosController::contadores] ' . $ex->getMessage());
            $this->json(['novo'=>0,'aberto'=>0,'pendente'=>0,'a_laudar'=>0,'em_laudo'=>0,'rascunho'=>0,'assinado'=>0,'liberado'=>0,'peer_review'=>0,'urgente'=>0,'achado_critico'=>0]);
        }
    }

    /** Data de referência da lista: assinatura/liberação usam a data do ato médico. */
    private function campoDataPeriodoParaSituacao(string $situacao): string
    {
        return in_array($situacao, ['assinado', 'liberado'], true)
            ? 'COALESCE(DATE(e.laudo_assinado_em), e.study_date)'
            : 'e.study_date';
    }

    /** Data de referência por registro para badges que agregam várias situações. */
    private function campoDataPeriodoPorRegistro(): string
    {
        return "CASE WHEN COALESCE(e.situacao, 'novo') IN ('assinado', 'liberado')\n"
            . 'THEN COALESCE(DATE(e.laudo_assinado_em), e.study_date) ELSE e.study_date END';
    }

    /** Retorna o mesmo intervalo de estudo usado pela lista e pelos badges. */
    private function resolverIntervaloPeriodo(string $periodo, string $dtInicio = '', string $dtFim = ''): array
    {
        $today = date('Y-m-d');
        switch ($periodo) {
            case 'hoje':
                return [$today, $today];
            case 'ontem':
                $yesterday = date('Y-m-d', strtotime('-1 day'));
                return [$yesterday, $yesterday];
            case '7dias':
                return [date('Y-m-d', strtotime('-6 days')), $today];
            case '30dias':
                return [date('Y-m-d', strtotime('-29 days')), $today];
            case '90dias':
                return [date('Y-m-d', strtotime('-89 days')), $today];
            case 'ano':
                return [date('Y-01-01'), $today];
            case 'todos':
                return ['', ''];
            default:
                return [$dtInicio, $dtFim];
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
                "SELECT id, tenant_id, situacao, assumido_por,
                        study_instance_uid, accession_number,
                        patient_name, patient_name_display, patient_id,
                        patient_birth_date, patient_sex, patient_age,
                        modalities, study_date, study_description,
                        scheduled_procedure_step_desc, requested_procedure_desc,
                        body_part_examined, institution_name, num_series, num_instances,
                        referring_physician_name, orthanc_id
                 FROM bi_pacs_estudos WHERE id = ? {$tWhere} LIMIT 1"
            );
            $stmtCheck->execute($tParam);
            $estudo = $stmtCheck->fetch(\PDO::FETCH_ASSOC);

            if (!$estudo) {
                echo json_encode(['ok' => false, 'msg' => 'Estudo não encontrado.']);
                return;
            }

            // A Worklist, a tomada de posse e o Laudário devem usar a mesma
            // autorização por InstitutionName. Antes de assumir, o médico ainda
            // não é responsável pelo estudo; por isso a checagem não exige posse.
            if (!(new \App\Services\ReportAccessService())->isStudyAllowed((object) $estudo, false)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'msg' => 'Este estudo não pertence às Unidades autorizadas para o seu perfil.']);
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

            // A tomada de posse deve ser atômica: entre a leitura acima e este
            // UPDATE outro médico pode tentar assumir o mesmo estudo. A condição
            // impede substituição silenciosa do responsável já gravado.
            // Em MySQL, o legado pode conter situacao vazia. No PostgreSQL a
            // coluna é ENUM; incluir '' no IN tenta convertê-lo para o tipo ENUM
            // e aborta toda a tomada de posse. O cast para texto preserva NULL →
            // novo sem aceitar estados fora da regra clínica.
            $whereAssumir = SqlHelper::isPostgres()
                ? "id = ? AND COALESCE(situacao::text, 'novo') IN ('novo', 'aberto') AND usuario_responsavel_id IS NULL"
                : "id = ? AND COALESCE(situacao, 'novo') IN ('novo', 'aberto', '') AND usuario_responsavel_id IS NULL";
            $paramsAssumir = [$nomeMedico, $userId, $estudoId];
            if ($tenantId) {
                $whereAssumir .= ' AND tenant_id = ?';
                $paramsAssumir[] = $tenantId;
            }
            $stmtAssumir = $pdo->prepare(
                "UPDATE bi_pacs_estudos SET
                    situacao               = 'a_laudar',
                    assumido_por           = ?,
                    assumido_em            = NOW(),
                    usuario_responsavel_id = ?
                 WHERE {$whereAssumir}"
            );
            $stmtAssumir->execute($paramsAssumir);
            if ($stmtAssumir->rowCount() !== 1) {
                \App\Core\Logger::warning('[EstudosController::assumirEstudo] posse concorrente bloqueada', [
                    'estudo_id' => $estudoId,
                    'usuario_id' => $userId,
                    'tenant_id' => $tenantId,
                ]);
                echo json_encode([
                    'ok' => false,
                    'msg' => 'Este estudo já foi assumido por outro médico. Atualize a worklist.',
                ]);
                return;
            }

            \App\Core\Logger::info("[EstudosController::assumirEstudo] estudo_id={$estudoId} medico={$nomeMedico} user_id={$userId}");

            // A URL pública do Laudário usa token opaco. Cria o report no mesmo
            // ciclo de assunção para que tanto a atualização AJAX quanto o reload
            // da Worklist encontrem report_public_token imediatamente.
            $reportService = new \App\Services\ReportService();
            $report = $reportService->getOrCreateReport((object) $estudo, (int) $userId);
            $reportUrl = $reportService->urlPublica($report);

            // ── Notifica o VoxelCopilot sobre o evento estudo.assumido ──────────
            try {
                $svc = new \App\Services\CopilotWebhookService();
                $svc->notificarEstudoAssumido(
                    (int)($tenantId ?? 0),
                    [
                        'id'                       => $estudoId,
                        'study_instance_uid'       => $estudo['study_instance_uid']       ?? '',
                        'accession_number'         => $estudo['accession_number']         ?? '',
                        'patient_name'             => $estudo['patient_name']             ?? '',
                        'patient_name_display'     => $estudo['patient_name_display']     ?? $estudo['patient_name'] ?? '',
                        'patient_id'               => $estudo['patient_id']               ?? '',
                        'patient_birth_date'       => $estudo['patient_birth_date']       ?? '',
                        'patient_sex'              => $estudo['patient_sex']              ?? '',
                        'patient_age'              => $estudo['patient_age']              ?? '',
                        'modalities'               => $estudo['modalities']               ?? '',
                        'study_date'               => $estudo['study_date']               ?? '',
                        'study_description'        => $estudo['study_description']        ?? '',
                        'institution_name'         => $estudo['institution_name']         ?? '',
                        'num_series'               => $estudo['num_series']               ?? null,
                        'num_instances'            => $estudo['num_instances']            ?? null,
                        'referring_physician_name' => $estudo['referring_physician_name'] ?? '',
                        'body_part_examined'       => $estudo['body_part_examined']       ?? '',
                        'orthanc_id'               => $estudo['orthanc_id']               ?? '',
                        'prioridade'               => 'normal',
                        'situacao'                 => 'a_laudar',
                        'assumido_em'              => date('Y-m-d H:i:s'),
                    ],
                    [
                        'id'   => $medico['id']   ?? $userId,
                        'nome' => $nomeMedico,
                        'crm'  => $medico['crm']  ?? '',
                    ]
                );
            } catch (\Throwable $webhookEx) {
                \App\Core\Logger::error('[EstudosController::assumirEstudo] Webhook Copilot falhou: ' . $webhookEx->getMessage());
            }

            echo json_encode([
                'ok'           => true,
                'msg'          => 'Estudo assumido com sucesso.',
                'situacao'     => 'a_laudar',
                'assumido_por' => $nomeMedico,
                'assumido_em'  => date('Y-m-d H:i:s'),
                'url'          => $reportUrl,
            ]);

        } catch (\Throwable $e) {
            \App\Core\Logger::error('[EstudosController::assumirEstudo] ' . $e->getMessage());
            echo json_encode(['ok' => false, 'msg' => 'Erro interno. Tente novamente.']);
        }
    }

    /**
     * Recupera a URL opaca do Laudário para um estudo já assumido pelo médico
     * atual. É usado somente como recuperação de registros assumidos antes da
     * criação obrigatória do token público na etapa de assunção.
     *
     * POST /api/estudos/laudo-url  body: { estudo_id: int }
     */
    public function obterUrlLaudoAssumido(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'msg' => 'Não autenticado.']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $estudoId = (int) ($input['estudo_id'] ?? 0);
        if ($estudoId <= 0) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'msg' => 'Estudo inválido.']);
            return;
        }

        try {
            $reportService = new \App\Services\ReportService();
            $repo = new \App\Repositories\ReportRepository();
            $estudo = $repo->findEstudoById($estudoId);

            // Retorna 404 genérico para não revelar existência fora do escopo.
            if (!$estudo || !(new \App\Services\ReportAccessService())->isStudyAllowed($estudo)) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'msg' => 'Laudo não encontrado.']);
                return;
            }

            $situacao = strtolower((string) ($estudo->situacao ?? ''));
            if (!in_array($situacao, ['a_laudar', 'em_laudo', 'rascunho'], true)) {
                http_response_code(409);
                echo json_encode(['ok' => false, 'msg' => 'Este estudo não está disponível para laudo.']);
                return;
            }

            $report = $reportService->getOrCreateReport($estudo, (int) Auth::userId());
            echo json_encode([
                'ok'  => true,
                'url' => $reportService->urlPublica($report),
            ]);
        } catch (\Throwable $e) {
            \App\Core\Logger::error('[EstudosController::obterUrlLaudoAssumido] ' . $e->getMessage(), [
                'estudo_id' => $estudoId,
                'usuario_id' => Auth::userId(),
            ]);
            http_response_code(500);
            echo json_encode(['ok' => false, 'msg' => 'Não foi possível preparar o laudo.']);
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

    // ── API: GET /api/pacs/estudo-copilot-status?estudo_id=X ───────────────────
    // Retorna o status atual do laudo no Copilot para o painel de acompanhamento
    public function apiEstudoCopilotStatus(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $estudoId = (int)($_GET['estudo_id'] ?? 0);
        if (!$estudoId) {
            echo json_encode(['ok' => false, 'erro' => 'estudo_id_obrigatorio']);
            return;
        }
        try {
            $db   = Database::getInstance();
            $stmt = $db->prepare("
                SELECT copilot_status, copilot_enviado_em, copilot_laudo_em, copilot_medico_nome
                FROM bi_pacs_estudos WHERE id = :id LIMIT 1
            ");
            $stmt->execute(['id' => $estudoId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(['ok' => false, 'erro' => 'estudo_nao_encontrado']);
                return;
            }
            // Busca últimas 5 entradas do log de sincronização
            $logs = [];
            try {
                $logStmt = $db->prepare("
                    SELECT evento, direcao, status, http_status, created_at, erro_msg
                    FROM bi_copilot_sync_log WHERE estudo_id = :eid
                    ORDER BY created_at DESC LIMIT 5
                ");
                $logStmt->execute(['eid' => $estudoId]);
                $logs = $logStmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) { /* tabela pode não existir ainda */ }

            echo json_encode([
                'ok'             => true,
                'copilot_status' => $row['copilot_status'] ?? 'nenhum',
                'enviado_em'     => $row['copilot_enviado_em'],
                'laudo_em'       => $row['copilot_laudo_em'],
                'medico_nome'    => $row['copilot_medico_nome'],
                'logs'           => $logs,
                'timestamp'      => date('H:i:s'),
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
        }
    }

    // ── PWA: página de instalação do app ────────────────────────────────────────
    public function instalar(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        $title = 'Instalar App — VOXEL PACS';
        $this->view('estudos/instalar', compact('title'), 'pacs');
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
