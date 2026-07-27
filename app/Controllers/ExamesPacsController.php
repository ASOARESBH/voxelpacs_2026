<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\TenantContext;

/**
 * ExamesPacsController
 *
 * Módulo do PAINEL DO CLIENTE para visualização dos estudos DICOM
 * importados do servidor Orthanc e roteados para o tenant atual.
 *
 * Regra de negócio:
 *  - O cliente NÃO gerencia o servidor PACS (isso é função da plataforma).
 *  - O cliente visualiza APENAS os estudos cujo tenant_id = TenantContext::id().
 *  - O roteamento é feito via InstitutionName configurado na plataforma.
 */
class ExamesPacsController extends Controller
{
    // ----------------------------------------------------------------
    // LISTA DE EXAMES DICOM (paginada + filtros)
    // ----------------------------------------------------------------

    public function index(): void
    {
        $tenantId = TenantContext::id();
        $pdo      = Database::getInstance();

        // Filtros da URL
        $filtros = [
            'data_ini'    => $_GET['data_ini']    ?? '',
            'data_fim'    => $_GET['data_fim']     ?? '',
            'modalidade'  => $_GET['modalidade']   ?? '',
            'paciente'    => $_GET['paciente']     ?? '',
            'accession'   => $_GET['accession']    ?? '',
            'medico'      => $_GET['medico']       ?? '',
            'body_part'   => $_GET['body_part']    ?? '',
            'institution' => $_GET['institution']  ?? '',
            'page'        => max(1, (int)($_GET['page'] ?? 1)),
        ];

        $perPage = 50;
        $offset  = ($filtros['page'] - 1) * $perPage;

        // Monta WHERE dinâmico
        // FONTE ÚNICA DA VERDADE: InstitutionResolverService
        $institutionNames = InstitutionResolverService::getInstitutionNamesByTenant($tenantId);
        if (!empty($institutionNames)) {
            $placeholders = implode(',', array_fill(0, count($institutionNames), '?'));
            $where  = ["e.institution_name IN ({$placeholders})"];
            $params = $institutionNames;
        } else {
            $where  = ['e.tenant_id = :tenant_id'];
            $params = [':tenant_id' => $tenantId];
        }

        if ($filtros['data_ini']) {
            $where[]              = 'e.study_date >= :data_ini';
            $params[':data_ini']  = $filtros['data_ini'];
        }
        if ($filtros['data_fim']) {
            $where[]              = 'e.study_date <= :data_fim';
            $params[':data_fim']  = $filtros['data_fim'];
        }
        if ($filtros['modalidade']) {
            $where[]              = 'e.modalities LIKE :modalidade';
            $params[':modalidade'] = '%' . $filtros['modalidade'] . '%';
        }
        if ($filtros['paciente']) {
            $where[]              = '(e.patient_name LIKE :pac OR e.patient_id LIKE :pac2)';
            $params[':pac']       = '%' . $filtros['paciente'] . '%';
            $params[':pac2']      = '%' . $filtros['paciente'] . '%';
        }
        if ($filtros['accession']) {
            $where[]              = 'e.accession_number LIKE :accession';
            $params[':accession'] = '%' . $filtros['accession'] . '%';
        }
        if ($filtros['medico']) {
            $where[]              = '(e.referring_physician_name LIKE :med OR e.performing_physician_name LIKE :med2)';
            $params[':med']       = '%' . $filtros['medico'] . '%';
            $params[':med2']      = '%' . $filtros['medico'] . '%';
        }
        if ($filtros['body_part']) {
            $where[]              = 'e.body_part_examined LIKE :body_part';
            $params[':body_part'] = '%' . $filtros['body_part'] . '%';
        }
        if ($filtros['institution']) {
            $where[]              = 'e.institution_name = :institution';
            $params[':institution'] = $filtros['institution'];
        }

        $whereSQL = implode(' AND ', $where);

        // Total de registros
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM bi_pacs_estudos e WHERE $whereSQL");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Estudos paginados
        $sql = "
            SELECT
                e.id, e.orthanc_id, e.study_date, e.study_time,
                e.patient_id, e.patient_name, e.patient_name_display,
                e.patient_birth_date, e.patient_sex, e.patient_age,
                e.accession_number, e.study_description,
                e.modalities, e.num_series, e.num_instances,
                e.institution_name, e.body_part_examined,
                e.referring_physician_name, e.performing_physician_name,
                e.station_name, e.manufacturer, e.manufacturer_model_name,
                e.protocol_name, e.is_stable, e.last_update_orthanc,
                e.importado_em
            FROM bi_pacs_estudos e
            WHERE $whereSQL
            ORDER BY e.study_date DESC, e.study_time DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  \PDO::PARAM_INT);
        $stmt->execute();
        $estudos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Listas para filtros
        $modalidades  = $this->getDistinctValues($pdo, $tenantId, 'modalities');
        $institutions = $this->getDistinctValues($pdo, $tenantId, 'institution_name');
        $bodyParts    = $this->getDistinctValues($pdo, $tenantId, 'body_part_examined');

        // Estatísticas rápidas
        $stats = $this->getStats($pdo, $tenantId);

        $this->view('pacs_exames/index', compact(
            'estudos', 'filtros', 'total', 'perPage',
            'modalidades', 'institutions', 'bodyParts', 'stats'
        ));
    }

    // ----------------------------------------------------------------
    // DETALHE DE UM ESTUDO
    // ----------------------------------------------------------------

    public function show(int $id): void
    {
        $tenantId = TenantContext::id();
        $pdo      = Database::getInstance();

        $stmt = $pdo->prepare("
            SELECT * FROM bi_pacs_estudos
            WHERE id = :id AND tenant_id = :tenant_id
        ");
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $estudo = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$estudo) {
            $_SESSION['error'] = 'Estudo não encontrado ou sem permissão de acesso.';
            $this->redirect('/pacs/exames');
        }

        // Busca séries do Orthanc via API pública (leitura direta da tabela de config)
        $servidor = $this->getServidor($pdo, (int) $estudo['servidor_id']);
        $series   = [];
        if ($servidor && $estudo['orthanc_id']) {
            $series = $this->fetchSeriesFromOrthanc($servidor, $estudo['orthanc_id']);
        }

        $this->view('pacs_exames/show', compact('estudo', 'series', 'servidor'));
    }

    // ----------------------------------------------------------------
    // MODALIDADES PACS (resumo por modalidade do tenant)
    // ----------------------------------------------------------------

    public function modalidades(): void
    {
        $tenantId = TenantContext::id();
        $pdo      = Database::getInstance();

        $stmt = $pdo->prepare("
            SELECT
                TRIM(BOTH '\\\\' FROM m.modalidade)  AS modalidade,
                COUNT(*)                              AS total_estudos,
                MIN(e.study_date)                     AS primeira_data,
                MAX(e.study_date)                     AS ultima_data,
                COUNT(DISTINCT e.patient_id)          AS total_pacientes,
                COUNT(DISTINCT e.institution_name)    AS total_unidades,
                SUM(e.num_instances)                  AS total_imagens
            FROM bi_pacs_estudos e
            -- Expande a lista de modalidades separadas por \\
            JOIN (
                SELECT DISTINCT TRIM(value) AS modalidade
                FROM bi_pacs_estudos
                CROSS JOIN JSON_TABLE(
                    CONCAT('[\"', REPLACE(modalities, '\\\\', '\",\"'), '\"]'),
                    '$[*]' COLUMNS(value VARCHAR(32) PATH '$')
                ) jt
                WHERE tenant_id = :tid1
            ) m ON e.modalities LIKE CONCAT('%', m.modalidade, '%')
            WHERE e.tenant_id = :tid2
            GROUP BY m.modalidade
            ORDER BY total_estudos DESC
        ");
        $stmt->execute([':tid1' => $tenantId, ':tid2' => $tenantId]);
        $modalidades = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Fallback simples se JSON_TABLE não disponível (MariaDB < 10.6)
        if (empty($modalidades)) {
            $stmt2 = $pdo->prepare("
                SELECT
                    modalities                            AS modalidade,
                    COUNT(*)                              AS total_estudos,
                    MIN(study_date)                       AS primeira_data,
                    MAX(study_date)                       AS ultima_data,
                    COUNT(DISTINCT patient_id)            AS total_pacientes,
                    COUNT(DISTINCT institution_name)      AS total_unidades,
                    SUM(num_instances)                    AS total_imagens
                FROM bi_pacs_estudos
                WHERE tenant_id = :tid AND modalities IS NOT NULL
                GROUP BY modalities
                ORDER BY total_estudos DESC
            ");
            $stmt2->execute([':tid' => $tenantId]);
            $modalidades = $stmt2->fetchAll(\PDO::FETCH_ASSOC);
        }

        // Evolução mensal por modalidade (últimos 12 meses)
        $evolucaoStmt = $pdo->prepare("
            SELECT
                DATE_FORMAT(study_date, '%Y-%m') AS mes,
                modalities                        AS modalidade,
                COUNT(*)                          AS total
            FROM bi_pacs_estudos
            WHERE tenant_id = :tid
              AND study_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY mes, modalidade
            ORDER BY mes ASC
        ");
        $evolucaoStmt->execute([':tid' => $tenantId]);
        $evolucao = $evolucaoStmt->fetchAll(\PDO::FETCH_ASSOC);

        $stats = $this->getStats($pdo, $tenantId);

        $this->view('pacs_exames/modalidades', compact('modalidades', 'evolucao', 'stats'));
    }

    // ----------------------------------------------------------------
    // HELPERS PRIVADOS
    // ----------------------------------------------------------------

    private function getDistinctValues(\PDO $pdo, int $tenantId, string $col): array
    {
        $stmt = $pdo->prepare("
            SELECT DISTINCT `$col` AS val
            FROM bi_pacs_estudos
            WHERE tenant_id = ? AND `$col` IS NOT NULL AND `$col` != ''
            ORDER BY `$col`
        ");
        $stmt->execute([$tenantId]);
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'val');
    }

    private function getStats(\PDO $pdo, int $tenantId): array
    {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*)                        AS total_estudos,
                COUNT(DISTINCT patient_id)      AS total_pacientes,
                SUM(num_instances)              AS total_imagens,
                COUNT(DISTINCT modalities)      AS total_modalidades,
                COUNT(DISTINCT institution_name)AS total_unidades,
                MAX(study_date)                 AS ultimo_exame,
                SUM(CASE WHEN study_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS ultimos_30_dias
            FROM bi_pacs_estudos
            WHERE tenant_id = ?
        ");
        $stmt->execute([$tenantId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function getServidor(\PDO $pdo, int $servidorId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM bi_pacs_servidor WHERE id = ? AND ativo = 1 LIMIT 1");
        $stmt->execute([$servidorId]);
        $row  = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function fetchSeriesFromOrthanc(array $servidor, string $orthancId): array
    {
        $url  = rtrim($servidor['url'], '/') . "/studies/$orthancId/series";
        $user = $servidor['usuario'] ?? null;
        $pass = \App\Core\Crypto::decrypt($servidor['senha'] ?? null);
        $to   = (int)($servidor['timeout'] ?? 15);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $to,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        if ($user) {
            curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
        }
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$body) return [];
        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }
}
