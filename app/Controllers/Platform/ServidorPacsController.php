<?php
namespace App\Controllers\Platform;

use App\Core\Controller;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\SqlHelper;
use App\Core\Auth;
use App\Services\OrthancService;
use App\Services\PacsRoutingService;
use App\Services\PacsSyncService;

/**
 * ServidorPacsController — Gerenciamento dos servidores PACS (Orthanc), N:N com Negócios
 *
 * Responsabilidades:
 *  - CRUD dos servidores Orthanc (podem ser vários, cada um compartilhado por N negócios)
 *  - Associação N:N Negócio <-> Servidor (bi_negocio_servidor_pacs)
 *  - Sincronização manual (botão) e status do robô automático (a cada 2 min)
 *  - Exibir estudos importados com filtros, incluindo filas de não identificados/conflitos
 *
 * Ver docs/PACS_MULTISERVIDOR_ROTEAMENTO.md para o desenho completo do modelo N:N
 * e do motor de roteamento por InstitutionName (App\Services\PacsRoutingService).
 */
class ServidorPacsController extends Controller
{
    // ----------------------------------------------------------------
    // LISTA DE SERVIDORES (DASHBOARD)
    // ----------------------------------------------------------------

    public function index(): void
    {
        $pdo = Database::getInstance();

        $servidores = [];
        $roboConfig = null;

        try {
            $servidores = $pdo->query("SELECT * FROM bi_pacs_servidor ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);

            $sqlContagens = SqlHelper::isPostgres()
                ? "SELECT servidor_id,
                         SUM(CASE WHEN roteamento_status = 'roteado' THEN 1 ELSE 0 END) AS roteados,
                         SUM(CASE WHEN roteamento_status = 'nao_identificado' THEN 1 ELSE 0 END) AS nao_identificados,
                         SUM(CASE WHEN roteamento_status = 'conflito' THEN 1 ELSE 0 END) AS conflitos,
                         COUNT(*) AS total
                    FROM bi_pacs_estudos GROUP BY servidor_id"
                : "SELECT servidor_id,
                         SUM(roteamento_status = 'roteado') AS roteados,
                         SUM(roteamento_status = 'nao_identificado') AS nao_identificados,
                         SUM(roteamento_status = 'conflito') AS conflitos,
                         COUNT(*) AS total
                    FROM bi_pacs_estudos GROUP BY servidor_id";
            $contagens = $pdo->query($sqlContagens)->fetchAll(\PDO::FETCH_ASSOC);
            $contagensPorServidor = [];
            foreach ($contagens as $c) {
                $contagensPorServidor[$c['servidor_id']] = $c;
            }

            $negociosStmt = $pdo->prepare("
                SELECT t.id, t.nome FROM bi_negocio_servidor_pacs nsp
                JOIN bi_tenants t ON t.id = nsp.tenant_id
                WHERE nsp.servidor_id = ? AND nsp.ativo = 1 ORDER BY t.nome
            ");

            foreach ($servidores as &$srv) {
                unset($srv['senha']); // nunca expõe credencial, nem criptografada, à view
                $c = $contagensPorServidor[$srv['id']] ?? ['roteados' => 0, 'nao_identificados' => 0, 'conflitos' => 0, 'total' => 0];
                $srv['total_estudos']      = (int) $c['total'];
                $srv['total_roteados']     = (int) $c['roteados'];
                $srv['nao_identificados']  = (int) $c['nao_identificados'];
                $srv['conflitos']          = (int) $c['conflitos'];

                $negociosStmt->execute([$srv['id']]);
                $srv['negocios'] = $negociosStmt->fetchAll(\PDO::FETCH_ASSOC);
            }
            unset($srv);

            $roboConfig = $pdo->query("SELECT * FROM bi_pacs_sync_robo_config WHERE id = 1")->fetch(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log("[PACS] Erro ao carregar dashboard de servidores: " . $e->getMessage());
        }

        $this->view('platform/servidor_pacs/index', compact('servidores', 'roboConfig'), 'platform');
    }

    // ----------------------------------------------------------------
    // ROBÔ DE SINCRONIZAÇÃO AUTOMÁTICA (global — 1 config, todos os servidores)
    // ----------------------------------------------------------------

    public function syncRoboGerarToken(): void
    {
        $token = bin2hex(random_bytes(24));
        $sql = SqlHelper::isPostgres()
            ? 'INSERT INTO bi_pacs_sync_robo_config (id, token) VALUES (1, ?)
               ON CONFLICT (id) DO UPDATE SET token = EXCLUDED.token'
            : 'INSERT INTO bi_pacs_sync_robo_config (id, token) VALUES (1, ?)
               ON DUPLICATE KEY UPDATE token = VALUES(token)';
        Database::getInstance()->prepare($sql)->execute([$token]);
        $_SESSION['success'] = 'Token gerado com sucesso.';
        $this->redirect('/platform/servidor-pacs');
    }

    public function syncRoboToggle(): void
    {
        Database::getInstance()
            ->prepare("UPDATE bi_pacs_sync_robo_config SET ativo = CASE WHEN ativo=1 THEN 0 ELSE 1 END WHERE id = 1")
            ->execute();
        $this->redirect('/platform/servidor-pacs');
    }

    // ----------------------------------------------------------------
    // CADASTRO / CONFIGURAÇÃO DE UM SERVIDOR
    // ----------------------------------------------------------------

    public function novoServidor(): void
    {
        $this->view('platform/servidor_pacs/configurar', [
            'servidor' => null,
            'negociosAssociados' => [],
            'todosNegocios' => $this->listarNegociosAtivos(Database::getInstance()),
        ], 'platform');
    }

    public function configurar(int $id): void
    {
        $pdo      = Database::getInstance();
        $servidor = $this->getServidor($pdo, $id);

        if (!$servidor) {
            $_SESSION['error'] = 'Servidor não encontrado.';
            $this->redirect('/platform/servidor-pacs');
        }

        $servidor['tem_senha'] = !empty($servidor['senha']);
        unset($servidor['senha']); // nunca envia credencial (nem criptografada) para a view

        $negociosAssociados = $pdo->prepare("
            SELECT nsp.id AS vinculo_id, t.id AS tenant_id, t.nome, t.slug
            FROM bi_negocio_servidor_pacs nsp
            JOIN bi_tenants t ON t.id = nsp.tenant_id
            WHERE nsp.servidor_id = ? AND nsp.ativo = 1 ORDER BY t.nome
        ");
        $negociosAssociados->execute([$id]);

        $this->view('platform/servidor_pacs/configurar', [
            'servidor' => $servidor,
            'negociosAssociados' => $negociosAssociados->fetchAll(\PDO::FETCH_ASSOC),
            'todosNegocios' => $this->listarNegociosAtivos($pdo),
        ], 'platform');
    }

    private function listarNegociosAtivos(\PDO $pdo): array
    {
        return $pdo->query("SELECT id, nome, slug FROM bi_tenants WHERE status != 'cancelado' ORDER BY nome")
                   ->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function criarServidor(): void
    {
        $pdo = Database::getInstance();

        $url     = rtrim(trim($_POST['url'] ?? ''), '/');
        $nome    = trim($_POST['nome'] ?? '') ?: 'Novo Servidor Orthanc';
        $user    = trim($_POST['usuario'] ?? '') ?: null;
        $senha   = trim($_POST['senha'] ?? '') ?: null;
        $timeout = max(5, min(120, (int) ($_POST['timeout'] ?? 30)));

        if (empty($url)) {
            $_SESSION['error'] = 'A URL do servidor é obrigatória.';
            $this->redirect('/platform/servidor-pacs/novo');
        }

        try {
            $pdo->prepare("
                INSERT INTO bi_pacs_servidor (nome, url, usuario, senha, timeout, ativo)
                VALUES (?, ?, ?, ?, ?, 1)
            ")->execute([$nome, $url, $user, Crypto::encrypt($senha), $timeout]);

            $_SESSION['success'] = 'Servidor cadastrado com sucesso.';
            $this->redirect('/platform/servidor-pacs');
        } catch (\Exception $e) {
            error_log("[PACS] Erro ao criar servidor: " . $e->getMessage());
            $_SESSION['error'] = 'Erro ao cadastrar servidor: ' . $e->getMessage();
            $this->redirect('/platform/servidor-pacs/novo');
        }
    }

    public function salvarConfig(int $id): void
    {
        $pdo = Database::getInstance();

        $url     = rtrim(trim($_POST['url'] ?? ''), '/');
        $nome    = trim($_POST['nome'] ?? 'Orthanc Principal');
        $user    = trim($_POST['usuario'] ?? '') ?: null;
        $senha   = trim($_POST['senha'] ?? '') ?: null;
        $timeout = max(5, min(120, (int) ($_POST['timeout'] ?? 30)));

        if (empty($url)) {
            $_SESSION['error'] = 'A URL do servidor é obrigatória.';
            $this->redirect("/platform/servidor-pacs/{$id}/configurar");
        }

        try {
            if ($senha !== null) {
                $pdo->prepare("
                    UPDATE bi_pacs_servidor
                    SET nome=?, url=?, usuario=?, senha=?, timeout=?, ativo=1, updated_at=NOW()
                    WHERE id = ?
                ")->execute([$nome, $url, $user, Crypto::encrypt($senha), $timeout, $id]);
            } else {
                $pdo->prepare("
                    UPDATE bi_pacs_servidor
                    SET nome=?, url=?, usuario=?, timeout=?, ativo=1, updated_at=NOW()
                    WHERE id = ?
                ")->execute([$nome, $url, $user, $timeout, $id]);
            }

            error_log("[PACS] Config salva: servidor_id=$id, url=$url, usuario=$user, timeout=$timeout");
            $_SESSION['success'] = 'Configurações do servidor PACS salvas com sucesso.';
        } catch (\Exception $e) {
            error_log("[PACS] Erro ao salvar config: " . $e->getMessage());
            $_SESSION['error'] = 'Erro ao salvar configurações: ' . $e->getMessage();
        }

        $this->redirect('/platform/servidor-pacs');
    }

    // ----------------------------------------------------------------
    // ASSOCIAÇÃO N:N NEGÓCIO <-> SERVIDOR
    // ----------------------------------------------------------------

    public function associarNegocio(int $servidorId): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $pdo      = Database::getInstance();
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);

        if ($tenantId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Negócio inválido.']);
            return;
        }

        try {
            $sql = SqlHelper::isPostgres()
                ? 'INSERT INTO bi_negocio_servidor_pacs (tenant_id, servidor_id, ativo, criado_por)
                   VALUES (?, ?, 1, ?)
                   ON CONFLICT (tenant_id, servidor_id) DO UPDATE SET ativo = EXCLUDED.ativo'
                : 'INSERT INTO bi_negocio_servidor_pacs (tenant_id, servidor_id, ativo, criado_por)
                   VALUES (?, ?, 1, ?)
                   ON DUPLICATE KEY UPDATE ativo = VALUES(ativo)';
            $pdo->prepare($sql)->execute([$tenantId, $servidorId, Auth::userId()]);

            echo json_encode(['success' => true, 'message' => 'Negócio associado ao servidor.']);
        } catch (\Exception $e) {
            error_log("[PACS] Erro ao associar negócio: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
        }
    }

    public function desassociarNegocio(int $servidorId, int $tenantId): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $pdo = Database::getInstance();

        try {
            $pdo->prepare("
                UPDATE bi_negocio_servidor_pacs SET ativo = 0
                WHERE servidor_id = ? AND tenant_id = ?
            ")->execute([$servidorId, $tenantId]);

            echo json_encode(['success' => true, 'message' => 'Negócio desassociado do servidor.']);
        } catch (\Exception $e) {
            error_log("[PACS] Erro ao desassociar negócio: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
        }
    }

    // ----------------------------------------------------------------
    // TESTAR CONEXÃO (AJAX / POST)
    // ----------------------------------------------------------------

    public function testar(int $id): void
    {
        @set_time_limit(120);
        @ini_set('display_errors', '0');
        header('Content-Type: application/json; charset=utf-8');
        $pdo      = Database::getInstance();
        $servidor = $this->getServidor($pdo, $id);

        if (!$servidor) {
            echo json_encode(['success' => false, 'message' => 'Servidor não configurado.']);
            return;
        }

        $orthanc = new OrthancService(
            $servidor['url'],
            $servidor['usuario'] ?? null,
            Crypto::decrypt($servidor['senha'] ?? null),
            $servidor['timeout'] ?? 30
        );

        $ping = $orthanc->ping();

        if ($ping['success']) {
            $data    = $ping['data'];
            $version = $data['Version']   ?? 'Desconhecida';
            $name    = $data['Name']      ?? 'Orthanc';
            $aet     = $data['DicomAet']  ?? '';
            $port    = $data['DicomPort'] ?? 4242;

            $stats     = $orthanc->getStatistics();
            $statsData = $stats['success'] ? $stats['data'] : [];

            try {
                $pdo->prepare("
                    UPDATE bi_pacs_servidor
                    SET status_ping='online', ultimo_ping=NOW(), versao=?, dicom_aet=?, dicom_port=?,
                        total_estudos=?, total_pacientes=?, total_series=?, total_instancias=?, disk_size_mb=?
                    WHERE id = ?
                ")->execute([
                    $version, $aet, $port,
                    $statsData['CountStudies']    ?? 0,
                    $statsData['CountPatients']   ?? 0,
                    $statsData['CountSeries']     ?? 0,
                    $statsData['CountInstances']  ?? 0,
                    $statsData['TotalDiskSizeMB'] ?? 0,
                    $id,
                ]);
            } catch (\Exception $e) {
                error_log("[PACS] Erro ao atualizar status ping: " . $e->getMessage());
            }

            echo json_encode([
                'success'  => true,
                'message'  => "Conexão bem-sucedida! {$name} v{$version} | AETitle: {$aet}:{$port}",
                'version'  => $version,
                'aet'      => $aet,
                'port'     => $port,
                'studies'  => $statsData['CountStudies']    ?? 0,
                'patients' => $statsData['CountPatients']   ?? 0,
                'disk_mb'  => $statsData['TotalDiskSizeMB'] ?? 0,
            ]);
        } else {
            try {
                $pdo->prepare("
                    UPDATE bi_pacs_servidor SET status_ping='erro', ultimo_ping=NOW(), observacoes=? WHERE id = ?
                ")->execute([$ping['error'], $id]);
            } catch (\Exception $e) {}

            echo json_encode(['success' => false, 'message' => 'Falha na conexão: ' . $ping['error']]);
        }
    }

    // ----------------------------------------------------------------
    // SINCRONIZAÇÃO MANUAL DE UM SERVIDOR (botão — complementar ao robô automático)
    // ----------------------------------------------------------------

    public function sincronizar(int $id): void
    {
        @set_time_limit(280);
        @ini_set('max_execution_time', '280');
        header('Content-Type: application/json; charset=utf-8');
        @ini_set('display_errors', '0');

        $pdo      = Database::getInstance();
        $servidor = $this->getServidor($pdo, $id);

        if (!$servidor) {
            echo json_encode(['success' => false, 'message' => 'Servidor não configurado.']);
            return;
        }

        $logId = null;
        try {
            $pdo->prepare("
                INSERT INTO bi_pacs_sync_log (servidor_id, iniciado_em, status, origem) VALUES (?, NOW(), 'em_andamento', 'manual')
            ")->execute([$id]);
            $logId = $pdo->lastInsertId();
        } catch (\Exception $e) {
            error_log("[PACS] Erro ao criar log de sync: " . $e->getMessage());
        }

        $orthanc = new OrthancService(
            $servidor['url'],
            $servidor['usuario'] ?? null,
            Crypto::decrypt($servidor['senha'] ?? null),
            $servidor['timeout'] ?? 30
        );

        $novos = 0; $atualizados = 0; $roteados = 0; $naoIdentificados = 0; $conflitos = 0; $erros = 0;

        try {
            $studies = $orthanc->importAllStudies(100);

            foreach ($studies as $study) {
                try {
                    $sharedTagsResult = $orthanc->getSharedTags($study['orthanc_id']);
                    $sharedTags = ($sharedTagsResult['success'] ?? false) && is_array($sharedTagsResult['data'] ?? null)
                        ? $sharedTagsResult['data']
                        : null;
                    $dicomTagsJson = $sharedTags !== null ? json_encode($sharedTags, JSON_UNESCAPED_UNICODE) : null;

                    if (trim((string) ($study['study_description'] ?? '')) === ''
                        && trim((string) ($study['scheduled_procedure_step_desc'] ?? '')) === '') {
                        $scheduled = $orthanc->getScheduledProcedureStepDescription($study['orthanc_id'], $sharedTags);
                        if (($scheduled['success'] ?? false) && !empty($scheduled['description'])) {
                            $study['scheduled_procedure_step_desc'] = $scheduled['description'];
                        }
                    }

                    $routing = PacsRoutingService::resolveTenant($id, $study['institution_name'] ?? null);
                    match ($routing['status']) {
                        PacsRoutingService::STATUS_ROTEADO          => $roteados++,
                        PacsRoutingService::STATUS_NAO_IDENTIFICADO => $naoIdentificados++,
                        PacsRoutingService::STATUS_CONFLITO         => $conflitos++,
                    };

                    $resultado = PacsSyncService::upsertEstudo($pdo, $id, $study, $routing, $dicomTagsJson);
                    $resultado === 'novo' ? $novos++ : $atualizados++;
                } catch (\Exception $e) {
                    $erros++;
                    error_log("[PACS] Erro ao importar estudo {$study['orthanc_id']}: " . $e->getMessage());
                }
            }

            $mensagem = "Sincronização manual: {$novos} novos, {$atualizados} atualizados, {$roteados} roteados, "
                . "{$naoIdentificados} não identificados, {$conflitos} conflitos, {$erros} erros.";
            error_log("[PACS] $mensagem");

            if ($logId) {
                $pdo->prepare("
                    UPDATE bi_pacs_sync_log SET
                        finalizado_em=NOW(), status='concluido',
                        estudos_novos=?, estudos_atualizados=?, estudos_roteados=?,
                        estudos_nao_identificados=?, estudos_conflito=?, erros=?, mensagem=?
                    WHERE id=?
                ")->execute([$novos, $atualizados, $roteados, $naoIdentificados, $conflitos, $erros, $mensagem, $logId]);
            }

            echo json_encode([
                'success'           => true,
                'message'           => $mensagem,
                'novos'             => $novos,
                'atualizados'       => $atualizados,
                'roteados'          => $roteados,
                'nao_identificados' => $naoIdentificados,
                'conflitos'         => $conflitos,
                'erros'             => $erros,
            ]);

        } catch (\Exception $e) {
            error_log("[PACS] Erro crítico na sincronização: " . $e->getMessage());

            if ($logId) {
                try {
                    $pdo->prepare("
                        UPDATE bi_pacs_sync_log SET finalizado_em=NOW(), status='erro', mensagem=? WHERE id=?
                    ")->execute([$e->getMessage(), $logId]);
                } catch (\Exception $ex) {}
            }

            echo json_encode(['success' => false, 'message' => 'Erro na sincronização: ' . $e->getMessage()]);
        }
    }

    // ----------------------------------------------------------------
    // ROTEAMENTO InstitutionName → Negócio (legado — de-para manual, ver
    // docs/PACS_MULTISERVIDOR_ROTEAMENTO.md sobre por que não foi removido
    // nem passou a ser usado pelo motor novo de roteamento por Unidades)
    // ----------------------------------------------------------------

    public function roteamento(): void
    {
        $pdo = Database::getInstance();

        $roteamentos             = [];
        $negocios                = [];
        $institutionsNaoRoteadas = [];

        try {
            $roteamentos = $pdo->query("
                SELECT r.*, t.nome as negocio_nome,
                       COUNT(e.id) as total_estudos
                FROM bi_pacs_roteamento r
                JOIN bi_tenants t ON t.id = r.tenant_id
                LEFT JOIN bi_pacs_estudos e ON e.servidor_id = r.servidor_id
                    AND e.institution_name = r.institution_name
                WHERE r.servidor_id = 1
                GROUP BY r.id
                ORDER BY r.institution_name
            ")->fetchAll(\PDO::FETCH_ASSOC);

            $negocios = $pdo->query("
                SELECT id, nome, slug FROM bi_tenants WHERE status != 'cancelado' ORDER BY nome
            ")->fetchAll(\PDO::FETCH_ASSOC);

            $roteadasNames   = array_column($roteamentos, 'institution_name');
            $allInstitutions = $pdo->query("
                SELECT DISTINCT institution_name, COUNT(*) as total
                FROM bi_pacs_estudos WHERE servidor_id = 1 AND institution_name IS NOT NULL
                GROUP BY institution_name ORDER BY total DESC
            ")->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($allInstitutions as $inst) {
                if (!in_array($inst['institution_name'], $roteadasNames)) {
                    $institutionsNaoRoteadas[] = $inst;
                }
            }
        } catch (\Exception $e) {
            error_log("[PACS] Erro ao carregar roteamento: " . $e->getMessage());
        }

        $this->view('platform/servidor_pacs/roteamento', compact(
            'roteamentos', 'negocios', 'institutionsNaoRoteadas'
        ), 'platform');
    }

    public function salvarRoteamento(): void
    {
        @ini_set('display_errors', '0');
        header('Content-Type: application/json; charset=utf-8');
        $pdo = Database::getInstance();

        $institutionName = trim($_POST['institution_name'] ?? '');
        $tenantId        = (int)($_POST['tenant_id'] ?? 0);
        $aetitle         = trim($_POST['aetitle'] ?? '') ?: null;
        $descricao       = trim($_POST['descricao'] ?? '') ?: null;

        if (empty($institutionName) || $tenantId <= 0) {
            echo json_encode(['success' => false, 'message' => 'InstitutionName e Negócio são obrigatórios.']);
            return;
        }

        try {
            $existeStmt = $pdo->prepare("
                SELECT id FROM bi_pacs_roteamento WHERE servidor_id = 1 AND institution_name = ?
            ");
            $existeStmt->execute([$institutionName]);
            $existeId = $existeStmt->fetchColumn();

            if ($existeId) {
                $pdo->prepare("
                    UPDATE bi_pacs_roteamento
                    SET tenant_id=?, aetitle=?, descricao=?, ativo=1, updated_at=NOW()
                    WHERE id=?
                ")->execute([$tenantId, $aetitle, $descricao, $existeId]);
            } else {
                $pdo->prepare("
                    INSERT INTO bi_pacs_roteamento (servidor_id, tenant_id, institution_name, aetitle, descricao)
                    VALUES (1, ?, ?, ?, ?)
                ")->execute([$tenantId, $institutionName, $aetitle, $descricao]);
            }

            $updateStmt = $pdo->prepare("
                UPDATE bi_pacs_estudos SET tenant_id = ?
                WHERE servidor_id = 1 AND institution_name = ? AND tenant_id IS NULL
            ");
            $updateStmt->execute([$tenantId, $institutionName]);
            $afetados = $updateStmt->rowCount();

            error_log("[PACS] Roteamento salvo: institution='$institutionName' → tenant_id=$tenantId, $afetados estudos roteados retroativamente");

            echo json_encode([
                'success'  => true,
                'message'  => "Roteamento salvo! {$afetados} estudos roteados retroativamente.",
                'afetados' => $afetados,
            ]);
        } catch (\Exception $e) {
            error_log("[PACS] Erro ao salvar roteamento: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
        }
    }

    public function removerRoteamento(int $id): void
    {
        @ini_set('display_errors', '0');
        header('Content-Type: application/json; charset=utf-8');
        $pdo = Database::getInstance();

        try {
            $rotStmt = $pdo->prepare("SELECT institution_name FROM bi_pacs_roteamento WHERE id = ? AND servidor_id = 1");
            $rotStmt->execute([$id]);
            $row = $rotStmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                $pdo->prepare("
                    UPDATE bi_pacs_estudos SET tenant_id = NULL
                    WHERE servidor_id = 1 AND institution_name = ?
                ")->execute([$row['institution_name']]);
            }

            $pdo->prepare("DELETE FROM bi_pacs_roteamento WHERE id = ? AND servidor_id = 1")->execute([$id]);

            echo json_encode(['success' => true, 'message' => 'Roteamento removido com sucesso.']);
        } catch (\Exception $e) {
            error_log("[PACS] Erro ao remover roteamento: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // ----------------------------------------------------------------
    // LISTA DE ESTUDOS (com filas de não identificados / conflitos)
    // ----------------------------------------------------------------

    public function estudos(): void
    {
        $pdo = Database::getInstance();

        $filtroServidor    = (int) ($_GET['servidor'] ?? 0);
        $filtroInstitution = $_GET['institution'] ?? '';
        $filtroTenant      = (int) ($_GET['tenant'] ?? 0);
        $filtroStatus      = $_GET['status'] ?? ''; // roteado|nao_identificado|conflito
        $pagina            = max(1, (int) ($_GET['pagina'] ?? 1));
        $porPagina         = 50;
        $offset            = ($pagina - 1) * $porPagina;

        $where  = ['1=1'];
        $params = [];

        if ($filtroServidor > 0) {
            $where[]  = 'servidor_id = ?';
            $params[] = $filtroServidor;
        }
        if ($filtroInstitution) {
            $where[]  = 'institution_name = ?';
            $params[] = $filtroInstitution;
        }
        if ($filtroTenant > 0) {
            $where[]  = 'tenant_id = ?';
            $params[] = $filtroTenant;
        }
        if (in_array($filtroStatus, ['roteado', 'nao_identificado', 'conflito'], true)) {
            $where[]  = 'roteamento_status = ?';
            $params[] = $filtroStatus;
        }

        $whereStr = implode(' AND ', $where);
        $estudos  = [];
        $total    = 0;
        $institutions = [];
        $negocios     = [];
        $servidores   = [];
        $naoIdentificados = [];
        $conflitos        = [];

        try {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos WHERE $whereStr");
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();

            $listStmt = $pdo->prepare("
                SELECT e.*, t.nome as negocio_nome, s.nome as servidor_nome
                FROM bi_pacs_estudos e
                LEFT JOIN bi_tenants t ON t.id = e.tenant_id
                LEFT JOIN bi_pacs_servidor s ON s.id = e.servidor_id
                WHERE $whereStr
                ORDER BY e.study_date DESC, e.importado_em DESC
                LIMIT $porPagina OFFSET $offset
            ");
            $listStmt->execute($params);
            $estudos = $listStmt->fetchAll(\PDO::FETCH_ASSOC);

            $institutions = $pdo->query("
                SELECT DISTINCT institution_name FROM bi_pacs_estudos
                WHERE institution_name IS NOT NULL ORDER BY institution_name
            ")->fetchAll(\PDO::FETCH_COLUMN);

            $negocios   = $this->listarNegociosAtivos($pdo);
            $servidores = $pdo->query("SELECT id, nome FROM bi_pacs_servidor ORDER BY nome")->fetchAll(\PDO::FETCH_ASSOC);

            // Seções de pendência — sempre visíveis, independente do filtro acima,
            // para nunca deixar um estudo "invisível" para o Platform Admin.
            $naoIdentificados = $pdo->query("
                SELECT e.*, s.nome as servidor_nome
                FROM bi_pacs_estudos e
                LEFT JOIN bi_pacs_servidor s ON s.id = e.servidor_id
                WHERE e.roteamento_status = 'nao_identificado'
                ORDER BY e.importado_em DESC LIMIT 200
            ")->fetchAll(\PDO::FETCH_ASSOC);

            $conflitos = $pdo->query("
                SELECT e.*, s.nome as servidor_nome
                FROM bi_pacs_estudos e
                LEFT JOIN bi_pacs_servidor s ON s.id = e.servidor_id
                WHERE e.roteamento_status = 'conflito'
                ORDER BY e.importado_em DESC LIMIT 200
            ")->fetchAll(\PDO::FETCH_ASSOC);

        } catch (\Exception $e) {
            error_log("[PACS] Erro ao listar estudos: " . $e->getMessage());
        }

        $totalPaginas = $porPagina > 0 ? (int) ceil($total / $porPagina) : 1;

        $this->view('platform/servidor_pacs/estudos', compact(
            'estudos', 'total', 'pagina', 'totalPaginas', 'porPagina',
            'filtroServidor', 'filtroInstitution', 'filtroTenant', 'filtroStatus',
            'institutions', 'negocios', 'servidores', 'naoIdentificados', 'conflitos'
        ), 'platform');
    }

    /**
     * Dump completo de tags DICOM de um estudo, sob demanda (modal "Ver tags").
     * Buscado lazy em vez de embutido na listagem para não inflar a página
     * com o JSON completo de até 50 estudos por vez.
     */
    public function tagsEstudo(int $id): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare("SELECT dicom_tags_completas FROM bi_pacs_estudos WHERE id = ?");
        $stmt->execute([$id]);
        $json = $stmt->fetchColumn();

        if ($json === false || $json === null) {
            echo json_encode(['success' => false, 'message' => 'Tags DICOM completas não disponíveis para este estudo.']);
            return;
        }

        echo json_encode(['success' => true, 'tags' => json_decode($json, true)], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Resolução manual de um estudo não identificado ou em conflito. Grava
     * roteamento_resolvido_por/em — a partir daqui, ciclos automáticos futuros
     * (PacsSyncService) não sobrescrevem mais essa decisão (ver upsertEstudo()).
     */
    public function resolverEstudo(int $id): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $pdo      = Database::getInstance();
        $tenantId = (int) ($_POST['tenant_id'] ?? 0);

        if ($tenantId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Selecione um negócio válido.']);
            return;
        }

        try {
            $pdo->prepare("
                UPDATE bi_pacs_estudos
                SET tenant_id = ?, roteamento_status = 'roteado', roteamento_candidatos = NULL,
                    roteamento_resolvido_por = ?, roteamento_resolvido_em = NOW()
                WHERE id = ?
            ")->execute([$tenantId, Auth::userId(), $id]);

            echo json_encode(['success' => true, 'message' => 'Estudo roteado manualmente com sucesso.']);
        } catch (\Exception $e) {
            error_log("[PACS] Erro ao resolver estudo $id: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
        }
    }

    // ----------------------------------------------------------------
    // HELPER PRIVADO
    // ----------------------------------------------------------------

    private function getServidor(\PDO $pdo, int $id): ?array
    {
        try {
            $stmt = $pdo->prepare("SELECT * FROM bi_pacs_servidor WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Exception $e) {
            error_log("[PACS] Erro ao buscar servidor: " . $e->getMessage());
            return null;
        }
    }
}
