<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function mustContain(string $needle, string $content, string $message): void
{
    if (strpos($content, $needle) === false) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

function mustNotContain(string $needle, string $content, string $message): void
{
    if (strpos($content, $needle) !== false) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

$postgres = file_get_contents($root . '/database/migrations/2026-09-21_report_versions_pdf_snapshot_postgresql.sql');
$mysql = file_get_contents($root . '/database/migrations/2026-09-21_report_versions_pdf_snapshot_mysql.sql');
$repo = file_get_contents($root . '/app/Repositories/ReportRepository.php');
$service = file_get_contents($root . '/app/Services/ReportService.php');
$context = file_get_contents($root . '/app/Services/ReportPdfDeliveryContextService.php');
$artifact = file_get_contents($root . '/app/Services/ReportDeliveryArtifactService.php');
$snapshot = file_get_contents($root . '/app/Services/ReportVersionPdfSnapshotService.php');
$controller = file_get_contents($root . '/app/Controllers/ReportsController.php');

mustContain('ADD COLUMN IF NOT EXISTS corpo_laudo TEXT', $postgres, 'PostgreSQL deve congelar o corpo livre na report_version.');
mustContain('pdf_snapshot_sha256 CHAR(64)', $postgres, 'PostgreSQL deve guardar a integridade do PDF.');
mustContain('report_versions_pdf_snapshot_immutable', $postgres, 'PostgreSQL deve ter função de imutabilidade.');
mustContain('OLD.corpo_laudo IS DISTINCT FROM NEW.corpo_laudo', $postgres, 'Conteúdo versionado deve ser imutável após assinatura.');
mustContain('ADD COLUMN `corpo_laudo` MEDIUMTEXT', $mysql, 'MySQL deve congelar o corpo livre na report_version.');
mustContain('trg_report_versions_pdf_snapshot_immutable', $mysql, 'MySQL deve proteger o snapshot.');

mustContain('corpo_laudo)', $repo, 'Repository deve persistir o corpo livre.');
mustContain("'corpo_laudo' => \$corpoLaudo", $repo, 'Repository deve enviar o corpo como parâmetro.');
mustContain('persistPdfSnapshotForVersion', $service, 'Assinatura/liberação devem criar o snapshot.');
mustContain('ReportPdfDeliveryContextService($pdo)', $service, 'Snapshot deve usar o contexto visual existente.');
mustContain('ReportVersionPdfSnapshotService($pdo)', $service, 'Snapshot deve ser persistido pelo serviço dedicado.');

mustContain('rv.corpo_laudo', $context, 'Contexto deve ler corpo da versão.');
mustContain('r.tenant_id = :tenant_id', $context, 'Contexto deve manter isolamento por tenant.');
mustContain('Versão visual do PDF sem conteúdo clínico válido.', $context, 'Contexto deve falhar fechado sem conteúdo.');

mustContain('ReportVersionPdfSnapshotService($this->pdo)', $artifact, 'Delivery deve ler o snapshot canônico.');
mustContain('readForJob($job)', $artifact, 'Delivery deve usar a versão do job.');
mustNotContain('renderSnapshotBinary($visualContext)', $artifact, 'Delivery não deve regenerar PDF a partir do estado atual.');

mustContain('INNER JOIN reports r ON r.id = rv.report_id AND r.tenant_id = :tenant_id', $snapshot, 'Leitura do snapshot deve ser tenant-scoped.');
mustContain('hash_equals($expectedHash, strtolower($hash))', $snapshot, 'Leitura deve validar SHA-256.');
mustContain("!str_starts_with(\$content, '%PDF')", $snapshot, 'Leitura deve validar assinatura PDF.');
mustContain('storage/report_versions/%d/%d', $snapshot, 'Arquivo deve ficar no storage privado do tenant/report.');

mustContain('readLatestForReport', $controller, 'Viewer deve buscar o snapshot da versão liberada.');
mustContain('PDF canônico indisponível.', $controller, 'Viewer deve falhar fechado sem snapshot.');
mustContain("Content-Disposition: ' . (\$download ? 'attachment' : 'inline')", $controller, 'Viewer/download deve servir o PDF binário canônico.');

fwrite(STDOUT, "Snapshot PDF canônico, conteúdo versionado, isolamento e fail-closed verificados.\n");
