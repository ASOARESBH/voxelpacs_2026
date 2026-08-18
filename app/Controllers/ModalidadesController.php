<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\SqlHelper;
use App\Core\TenantContext;

/**
 * ModalidadesController
 * Exibe as modalidades DICOM recebidas pela unidade via PACS (bi_pacs_estudos).
 */
class ModalidadesController extends Controller
{
    public function index(): void
    {
        $tenantId = TenantContext::id();
        $pdo      = Database::getInstance();

        $modalidades = [];
        $resumo      = ['total_estudos' => 0, 'total_pacientes' => 0, 'total_modalidades' => 0];
        $evolucaoPorMod = [];

        if ($tenantId) {
            $mesSql = SqlHelper::dateFormat('study_date', '%Y-%m');

            // Modalidades vindas do PACS (DICOM)
            $stmt = $pdo->prepare("
                SELECT
                    modalities AS sigla,
                    modalities AS nome,
                    COUNT(*) AS total_estudos,
                    COUNT(DISTINCT patient_id) AS total_pacientes,
                    MIN(study_date) AS primeira_data,
                    MAX(study_date) AS ultima_data,
                    COUNT(DISTINCT institution_name) AS unidades,
                    ROUND(AVG(num_instances), 1) AS media_imagens,
                    SUM(num_instances) AS total_imagens,
                    COUNT(DISTINCT {$mesSql}) AS meses_ativos
                FROM bi_pacs_estudos
                WHERE tenant_id = ?
                  AND modalities IS NOT NULL
                  AND modalities != ''
                GROUP BY modalities
                ORDER BY total_estudos DESC
            ");
            $stmt->execute([$tenantId]);
            $modalidades = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Evolução mensal por modalidade (últimos 6 meses)
            $inicioSql = SqlHelper::isPostgres()
                ? "CURRENT_DATE - INTERVAL '6 months'"
                : 'DATE_SUB(CURDATE(), INTERVAL 6 MONTH)';
            $stmtEvo = $pdo->prepare("
                SELECT
                    modalities,
                    {$mesSql} AS periodo,
                    COUNT(*) AS total
                FROM bi_pacs_estudos
                WHERE tenant_id = ?
                  AND study_date >= {$inicioSql}
                  AND modalities IS NOT NULL
                GROUP BY modalities, {$mesSql}
                ORDER BY modalidades, periodo
            ");
            $stmtEvo->execute([$tenantId]);
            $evoRaw = $stmtEvo->fetchAll(\PDO::FETCH_ASSOC);

            // Organiza evolução por modalidade
            foreach ($evoRaw as $r) {
                $evolucaoPorMod[$r['modalities']][$r['periodo']] = (int) $r['total'];
            }

            // Resumo geral
            $stmtRes = $pdo->prepare("
                SELECT
                    COUNT(*) AS total_estudos,
                    COUNT(DISTINCT patient_id) AS total_pacientes,
                    COUNT(DISTINCT modalities) AS total_modalidades
                FROM bi_pacs_estudos
                WHERE tenant_id = ?
            ");
            $stmtRes->execute([$tenantId]);
            $resumo = $stmtRes->fetch(\PDO::FETCH_ASSOC) ?: $resumo;
        }

        $this->view('modalidades/index', compact('modalidades', 'resumo', 'evolucaoPorMod'));
    }
}
