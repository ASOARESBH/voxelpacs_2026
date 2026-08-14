<?php

namespace App\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Auth;
use App\Core\Logger;
use App\Repositories\ReportRepository;
use App\Repositories\ViewerMeasurementRepository;

/**
 * Consome snapshots do VOXEL VIEW para o laudário sem criar um segundo estado
 * no viewer. O browser seleciona IDs; o texto clínico é sempre reconstruído
 * no servidor a partir dos snapshots autorizados.
 */
class ReportMeasurementService
{
    private const ALLOWED_SECTIONS = ['exame', 'tecnica', 'achados', 'conclusao', 'recomendacao'];

    private ReportRepository $reportRepository;
    private ViewerMeasurementRepository $measurementRepository;

    public function __construct()
    {
        $this->reportRepository = new ReportRepository();
        $this->measurementRepository = new ViewerMeasurementRepository();
    }

    public function listAvailable(int $reportId): array
    {
        $report = $this->findAuthorizedReport($reportId);
        if (!$report) {
            return ['ok' => false, 'error' => 'report_nao_encontrado_ou_nao_autorizado'];
        }

        try {
            return [
                'ok' => true,
                'report_id' => (int) $report->id,
                'readonly' => $this->isReadonly($report),
                'measurements' => $this->measurementRepository->listActiveMeasurementsForReport($report),
            ];
        } catch (\Throwable $e) {
            Logger::error('ReportMeasurementService: falha ao listar medições', [
                'report_id' => $reportId,
                'user_id' => Auth::userId(),
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'falha_ao_listar_medidas'];
        }
    }

    public function insert(int $reportId, array $measurementIds, string $section): array
    {
        $report = $this->findAuthorizedReport($reportId);
        if (!$report) {
            return ['ok' => false, 'error' => 'report_nao_encontrado_ou_nao_autorizado'];
        }
        if ($this->isReadonly($report) || !$this->currentUserOwnsStudyLock($report)) {
            return ['ok' => false, 'error' => 'report_somente_leitura'];
        }
        if (!in_array($section, self::ALLOWED_SECTIONS, true)) {
            return ['ok' => false, 'error' => 'secao_destino_invalida'];
        }

        $measurementIds = array_values(array_unique(array_filter(array_map('intval', $measurementIds))));
        if (!$measurementIds || count($measurementIds) > 25) {
            return ['ok' => false, 'error' => 'selecao_de_medidas_invalida'];
        }

        try {
            $measurements = $this->measurementRepository->findActiveMeasurementsByIdsForReport($report, $measurementIds);
            if (count($measurements) !== count($measurementIds)) {
                Logger::warning('ReportMeasurementService: seleção contém medida de outro escopo', [
                    'report_id' => $reportId,
                    'requested_ids' => $measurementIds,
                    'found_count' => count($measurements),
                ]);
                return ['ok' => false, 'error' => 'medida_nao_encontrada_ou_nao_autorizada'];
            }

            $content = json_decode($report->conteudo, true) ?: [];
            $content['secoes'] = array_merge([
                'exame' => '',
                'tecnica' => '',
                'achados' => '',
                'conclusao' => '',
                'recomendacao' => '',
            ], $content['secoes'] ?? []);
            $content['meta'] = is_array($content['meta'] ?? null) ? $content['meta'] : [];

            $newLines = [];
            $insertedMeasurements = [];
            $this->measurementRepository->beginTransaction();

            foreach ($measurements as $measurement) {
                if ($this->measurementRepository->usageExists(
                    (int) $report->id,
                    (int) $measurement['id'],
                    (string) $measurement['payload_hash'],
                    $section
                )) {
                    continue;
                }

                $text = $this->formatMeasurementText($measurement);
                $newLines[] = '<p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>';
                $this->measurementRepository->createUsage(
                    (int) $report->id,
                    $measurement,
                    $report->tenant_id === null ? null : (int) $report->tenant_id,
                    (int) $report->estudo_id,
                    $section,
                    $text,
                    (int) Auth::userId()
                );
                $insertedMeasurements[] = [
                    'id' => (int) $measurement['id'],
                    'text' => $text,
                ];
            }

            if (!$newLines) {
                $this->measurementRepository->rollBack();
                return [
                    'ok' => true,
                    'inserted' => 0,
                    'message' => 'As medidas selecionadas já foram inseridas nesta seção.',
                    'secoes' => $content['secoes'],
                ];
            }

            $content['secoes'][$section] .= implode('', $newLines);
            $content['meta']['ultima_edicao_por'] = Auth::userId();
            $content['meta']['ultima_edicao_em'] = date('Y-m-d H:i:s');
            $content['meta']['ultima_insercao_medidas_em'] = date('Y-m-d H:i:s');

            // Captura a próxima versão antes do UPDATE, que incrementa versao_atual.
            $version = $this->reportRepository->proximaVersao((int) $report->id);
            $this->reportRepository->atualizarConteudo((int) $report->id, $content, 'rascunho');
            $this->reportRepository->marcarHeartbeat((int) $report->estudo_id);
            $this->reportRepository->atualizarSituacaoEstudo((int) $report->estudo_id, 'rascunho');

            $this->reportRepository->createVersion(
                (int) $report->id,
                $content,
                'medidas_inseridas',
                (int) Auth::userId(),
                $version
            );
            $this->measurementRepository->commit();

            AuditLogger::log('report.medidas_inseridas', 'reports', (int) $report->id, [
                'estudo_id' => (int) $report->estudo_id,
                'secao' => $section,
                'measurement_ids' => array_column($insertedMeasurements, 'id'),
                'quantidade' => count($insertedMeasurements),
            ]);
            $this->reportRepository->logAction(
                (int) $report->id,
                (int) $report->estudo_id,
                (int) ($report->tenant_id ?? 0),
                (int) Auth::userId(),
                Auth::user()?->name ?? '',
                'medidas_inseridas',
                count($insertedMeasurements) . ' medida(s) inserida(s) na seção ' . $section
            );

            return [
                'ok' => true,
                'inserted' => count($insertedMeasurements),
                'secoes' => $content['secoes'],
                'measurements' => $insertedMeasurements,
            ];
        } catch (\Throwable $e) {
            $this->measurementRepository->rollBack();
            Logger::error('ReportMeasurementService: falha ao inserir medidas', [
                'report_id' => $reportId,
                'measurement_ids' => $measurementIds,
                'user_id' => Auth::userId(),
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => 'falha_ao_inserir_medidas'];
        }
    }

    private function findAuthorizedReport(int $reportId): ?object
    {
        if ($reportId <= 0 || !Auth::check()) {
            return null;
        }

        $report = $this->reportRepository->findReportById($reportId);
        if (!$report) {
            return null;
        }

        if (!Auth::isPlatformAdmin()) {
            $tenantId = Auth::tenantId();
            if ($tenantId === null || $report->tenant_id === null || (int) $report->tenant_id !== $tenantId) {
                return null;
            }
        }

        return $report;
    }

    private function isReadonly(object $report): bool
    {
        return in_array((string) $report->situacao, ['assinado', 'liberado'], true);
    }

    private function currentUserOwnsStudyLock(object $report): bool
    {
        $study = $this->reportRepository->findEstudoById((int) $report->estudo_id);
        if (!$study) {
            return false;
        }

        $ownerId = isset($study->usuario_responsavel_id) ? (int) $study->usuario_responsavel_id : 0;
        return $ownerId === 0 || $ownerId === (int) Auth::userId();
    }

    private function formatMeasurementText(array $measurement): string
    {
        $text = trim((string) $measurement['display_value']) . ' — ' . trim((string) $measurement['tool_name']);
        $label = trim((string) ($measurement['label'] ?? ''));
        if ($label !== '') {
            $text .= ' (' . $label . ')';
        }

        return $text;
    }
}
