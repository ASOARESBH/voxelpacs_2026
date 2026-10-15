<?php

declare(strict_types=1);

use App\Core\Database;
use App\Repositories\PhilipsSubmissionAuthorRepository;
use App\Services\PhilipsSubmissionAuthorResolver;
require dirname(__DIR__) . '/app/autoload.php';

const PROBE_NAME = 'philips_author_signer';

function failProbe(string $reason, ?string $sqlState = null): never
{
    printf("PROBE=%s\nMODE=READ_ONLY\nRESULT=FAIL\nREASON=%s\n", PROBE_NAME, $reason);
    printf("SQLSTATE=%s\nSIGNER_PATH=UNRESOLVED\nSIGNER_FOUND=NO\nSIGNER_ID=UNRESOLVED\nDUPLICATE=UNKNOWN\nDATABASE_CHANGED=NO\n", $sqlState ?? 'NONE');
    exit(1);
}

function argumentValue(array $arguments, string $name): ?string
{
    $prefix = $name . '=';
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, $prefix)) {
            return substr($argument, strlen($prefix));
        }
    }
    return null;
}

function positiveArgument(?string $value): ?int
{
    if ($value === null || preg_match('/^[1-9][0-9]*$/', trim($value)) !== 1) {
        return null;
    }
    $integer = (int) trim($value);
    return $integer > 0 ? $integer : null;
}

function sqlState(Throwable $error): string
{
    $code = $error instanceof PDOException ? (string) $error->getCode() : '';
    return preg_match('/^[0-9A-Z]{5}$/', $code) === 1 ? $code : 'UNKNOWN';
}

function signerPath(PDO $pdo, int $tenantId, int $reportId, int $reportVersion, int $studyId): string
{
    $statement = $pdo->prepare(<<<'SQL'
SELECT rv.acao
  FROM reports r
 INNER JOIN bi_pacs_estudos e
         ON e.id = r.estudo_id
        AND e.tenant_id = r.tenant_id
 INNER JOIN report_versions rv
         ON rv.report_id = r.id
        AND rv.versao = :report_version
 WHERE r.id = :report_id
   AND r.tenant_id = :tenant_id
   AND r.estudo_id = :study_id
 LIMIT 2
SQL);
    $statement->execute([
        'tenant_id' => $tenantId,
        'report_id' => $reportId,
        'report_version' => $reportVersion,
        'study_id' => $studyId,
    ]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 1) {
        return 'UNRESOLVED';
    }
    return match ((string) ($rows[0]['acao'] ?? '')) {
        'assinado' => 'SELF',
        'liberado' => 'PRIOR_ASSINADO',
        default => 'UNRESOLVED',
    };
}

function duplicateState(PDO $pdo, int $tenantId, int $reportId, int $reportVersion, int $studyId, string $path): string
{
    if (!in_array($path, ['SELF', 'PRIOR_ASSINADO'], true)) {
        return 'UNKNOWN';
    }
    if ($path === 'PRIOR_ASSINADO') {
        $versionStatement = $pdo->prepare(<<<'SQL'
SELECT rv.versao
  FROM reports r
 INNER JOIN bi_pacs_estudos e
         ON e.id = r.estudo_id
        AND e.tenant_id = r.tenant_id
 INNER JOIN report_versions rv
         ON rv.report_id = r.id
 WHERE r.id = :report_id
   AND r.tenant_id = :tenant_id
   AND r.estudo_id = :study_id
   AND rv.acao = 'assinado'
   AND rv.versao <= :report_version
 ORDER BY rv.versao DESC
 LIMIT 1
SQL);
        $versionStatement->execute([
            'tenant_id' => $tenantId,
            'report_id' => $reportId,
            'report_version' => $reportVersion,
            'study_id' => $studyId,
        ]);
        $signerVersion = (int) $versionStatement->fetchColumn();
        if ($signerVersion <= 0) {
            return 'NO';
        }
    } else {
        $signerVersion = $reportVersion;
    }

    $statement = $pdo->prepare(<<<'SQL'
SELECT COUNT(*)
  FROM reports r
 INNER JOIN bi_pacs_estudos e
         ON e.id = r.estudo_id
        AND e.tenant_id = r.tenant_id
 INNER JOIN report_versions rv
         ON rv.report_id = r.id
        AND rv.versao = :signer_version
 INNER JOIN bi_medicos m
         ON m.usuario_id = rv.usuario_id
        AND m.tenant_id = r.tenant_id
 WHERE r.id = :report_id
   AND r.tenant_id = :tenant_id
   AND r.estudo_id = :study_id
   AND rv.usuario_id IS NOT NULL
   AND rv.usuario_id > 0
   AND rv.acao = 'assinado'
SQL);
    $statement->execute([
        'tenant_id' => $tenantId,
        'report_id' => $reportId,
        'signer_version' => $signerVersion,
        'study_id' => $studyId,
    ]);
    return ((int) $statement->fetchColumn()) > 1 ? 'YES' : 'NO';
}

$arguments = array_slice($argv ?? [], 1);
if (!in_array('--read-only', $arguments, true)) {
    failProbe('read_only_required');
}
$tenantId = positiveArgument(argumentValue($arguments, '--tenant-id'));
$jobId = positiveArgument(argumentValue($arguments, '--job-id'));
if ($tenantId === null || $jobId === null) {
    failProbe('tenant_and_job_required');
}

$pdo = null;
try {
    $pdo = Database::getInstance();
    $pdo->beginTransaction();
    $pdo->exec('SET TRANSACTION READ ONLY');

    $jobStatement = $pdo->prepare(<<<'SQL'
SELECT j.id,
       j.tenant_id,
       o.report_id,
       o.report_version,
       o.estudo_id,
       o.payload_json
  FROM pacs_report_delivery_jobs j
 INNER JOIN pacs_report_delivery_outbox o
         ON o.id = j.outbox_id
        AND o.tenant_id = j.tenant_id
 WHERE j.id = :job_id
   AND j.tenant_id = :tenant_id
 LIMIT 1
SQL);
    $jobStatement->execute(['job_id' => $jobId, 'tenant_id' => $tenantId]);
    $job = $jobStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($job)) {
        $pdo->rollBack();
        failProbe('job_not_found');
    }

    $reportId = (int) ($job['report_id'] ?? 0);
    $reportVersion = (int) ($job['report_version'] ?? 0);
    $studyId = (int) ($job['estudo_id'] ?? 0);
    $path = signerPath($pdo, $tenantId, $reportId, $reportVersion, $studyId);
    $duplicate = duplicateState($pdo, $tenantId, $reportId, $reportVersion, $studyId, $path);
    $payload = json_decode((string) ($job['payload_json'] ?? ''), true);
    $payload = is_array($payload) ? $payload : [];
    $context = [
        'tenant_id' => $tenantId,
        'report_id' => $reportId,
        'report_version' => $reportVersion,
        'estudo_id' => $studyId,
    ];
    $repository = new PhilipsSubmissionAuthorRepository($pdo);
    $resolver = new PhilipsSubmissionAuthorResolver($repository);
    $resolved = $resolver->resolveForNoSendDiagnostic($payload, [], $context, []);
    $found = ($resolved['task_author_id_resolution'] ?? '') === 'SIGNER_ID'
        && ($resolved['author_source'] ?? '') === PhilipsSubmissionAuthorResolver::SOURCE_SIGNER;
    $failureState = $repository->lastFailureSqlState();
    $pdo->rollBack();

    printf("PROBE=%s\nMODE=READ_ONLY\nRESULT=%s\nTENANT_ID=%d\nJOB_ID=%d\n", PROBE_NAME, $found ? 'PASS' : 'FAIL', $tenantId, $jobId);
    printf("SIGNER_PATH=%s\nSIGNER_FOUND=%s\nSIGNER_ID=%s\nDUPLICATE=%s\nSQLSTATE=%s\nDATABASE_CHANGED=NO\n", $path, $found ? 'YES' : 'NO', $found ? 'REAL' : 'UNRESOLVED', $duplicate, $failureState ?? 'NONE');
    exit($found && $duplicate === 'NO' && $failureState === null ? 0 : 1);
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    failProbe('probe_query_failed', sqlState($error));
}
