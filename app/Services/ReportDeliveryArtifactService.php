<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\ReportDeliveryWorkerRepository;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Gera artefatos clínicos exclusivos de jobs já reservados ao worker.
 *
 * O PDF é renderizado a partir da versão imutável do laudo registrada na
 * outbox. O arquivo fica sob storage privado e nunca é exposto a usuários ou
 * destinos externos por URL.
 */
final class ReportDeliveryArtifactService
{
    private PDO $pdo;
    private ReportDeliveryWorkerRepository $workerRepository;

    public function __construct()
    {
        $this->pdo = Database::getInstance();
        $this->workerRepository = new ReportDeliveryWorkerRepository($this->pdo);
    }

    /** @return array{content:string,sha256:string,size:int,filename:string,report_id:int,study_instance_uid:string} */
    public function buildPdfForLeasedJob(int $jobId, string $workerId): array
    {
        $job = $this->workerRepository->findLeasedJobContext($jobId, $workerId);
        if (!$job) {
            throw new RuntimeException('Job não está reservado para este worker.');
        }
        if (($job['transport'] ?? '') !== 'dicom_pdf') {
            throw new RuntimeException('Artefato PDF DICOM solicitado para um transporte incompatível.');
        }

        $report = $this->loadReport((int) $job['report_id'], (int) $job['tenant_id']);
        $estudo = $this->loadStudy((int) $job['estudo_id'], (int) $job['tenant_id']);
        $report->conteudo = $this->loadVersionContent((int) $job['report_id'], (int) $job['report_version']);

        $binary = (new ReportPdfService())->renderBinary($estudo, $report);
        if (strlen($binary) < 100 || !str_starts_with($binary, '%PDF')) {
            throw new RuntimeException('Falha ao gerar PDF válido para devolutiva DICOM.');
        }

        $sha256 = hash('sha256', $binary);
        $filename = sprintf('laudo-%d-v%d.pdf', (int) $job['report_id'], (int) $job['report_version']);
        $storagePath = $this->storePrivatePdf($job, $filename, $binary);
        $this->workerRepository->recordArtifact(
            (int) $job['outbox_id'],
            (int) $job['tenant_id'],
            isset($job['estabelecimento_id']) ? (int) $job['estabelecimento_id'] : null,
            'pdf',
            $storagePath,
            $sha256,
            strlen($binary)
        );

        return [
            'content' => $binary,
            'sha256' => $sha256,
            'size' => strlen($binary),
            'filename' => $filename,
            'report_id' => (int) $job['report_id'],
            'study_instance_uid' => (string) ($estudo->study_instance_uid ?? ''),
        ];
    }

    private function loadReport(int $reportId, int $tenantId): object
    {
        $stmt = $this->pdo->prepare('SELECT * FROM reports WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $stmt->execute([':id' => $reportId, ':tenant_id' => $tenantId]);
        $report = $stmt->fetch(PDO::FETCH_OBJ);
        if (!$report) {
            throw new RuntimeException('Laudo não encontrado para o job de devolutiva.');
        }
        return $report;
    }

    private function loadStudy(int $studyId, int $tenantId): object
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bi_pacs_estudos WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $stmt->execute([':id' => $studyId, ':tenant_id' => $tenantId]);
        $study = $stmt->fetch(PDO::FETCH_OBJ);
        if (!$study) {
            throw new RuntimeException('Estudo não encontrado para o job de devolutiva.');
        }
        return $study;
    }

    private function loadVersionContent(int $reportId, int $version): string
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT secao_exame, secao_tecnica, secao_achados, secao_conclusao, secao_recomendacao
                 FROM report_versions
                 WHERE report_id = :report_id AND versao = :versao
                 LIMIT 1'
            );
            $stmt->execute([':report_id' => $reportId, ':versao' => $version]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return json_encode(['secoes' => [
                    'exame' => $row['secao_exame'] ?? '',
                    'tecnica' => $row['secao_tecnica'] ?? '',
                    'achados' => $row['secao_achados'] ?? '',
                    'conclusao' => $row['secao_conclusao'] ?? '',
                    'recomendacao' => $row['secao_recomendacao'] ?? '',
                ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            }
        } catch (Throwable) {
            // O ambiente legado usa versao_numero/conteudo; tenta abaixo.
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT conteudo FROM report_versions
                 WHERE report_id = :report_id AND versao_numero = :versao
                 LIMIT 1'
            );
            $stmt->execute([':report_id' => $reportId, ':versao' => $version]);
            $content = $stmt->fetchColumn();
            if (is_string($content) && $content !== '') {
                return $content;
            }
        } catch (Throwable) {
            // A exceção abaixo preserva o comportamento fail-closed.
        }

        throw new RuntimeException('Versão imutável do laudo não encontrada para a devolutiva.');
    }

    /** @param array<string,mixed> $job */
    private function storePrivatePdf(array $job, string $filename, string $binary): string
    {
        $basePath = defined('BASE_PATH') ? (string) BASE_PATH : dirname(__DIR__, 2);
        $directory = sprintf(
            '%s/storage/report_delivery/%d/%d',
            rtrim($basePath, '/'),
            (int) $job['tenant_id'],
            (int) $job['outbox_id']
        );
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o armazenamento privado do artefato.');
        }

        $path = $directory . '/' . $filename;
        if (file_put_contents($path, $binary, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível gravar o artefato PDF privado.');
        }
        @chmod($path, 0600);
        return $path;
    }
}
