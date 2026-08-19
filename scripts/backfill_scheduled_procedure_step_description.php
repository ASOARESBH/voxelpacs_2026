<?php
declare(strict_types=1);

/**
 * Backfill controlado da TAG (0040,0007) Scheduled Procedure Step Description.
 *
 * Uso:
 *   php scripts/backfill_scheduled_procedure_step_description.php --limit=50
 *   php scripts/backfill_scheduled_procedure_step_description.php --limit=50 --dry-run
 *
 * O script não toca em Study Description manual ou em descrição já preenchida.
 * Ele atualiza apenas scheduled_procedure_step_desc de estudos cuja (0008,1030)
 * está vazia e cuja (0040,0007) ainda não foi registrada.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script deve ser executado somente no CLI.\n");
    exit(1);
}

$limit = 50;
$dryRun = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($argument, '--limit=')) {
        $limit = max(1, min(250, (int) substr($argument, 8)));
    }
}

foreach (['DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SCHEMA'] as $key) {
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        $_ENV[$key] = (string) $value;
    }
}
$_ENV['DB_DRIVER'] = $_ENV['DB_DRIVER'] ?? 'pgsql';
$_ENV['DB_SCHEMA'] = $_ENV['DB_SCHEMA'] ?? 'public';

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Crypto;
use App\Core\Database;
use App\Services\OrthancService;

try {
    $pdo = Database::getInstance();
    $column = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'bi_pacs_estudos' AND column_name = 'scheduled_procedure_step_desc'")
        ->fetchColumn();
    if (!$column) {
        throw new RuntimeException('A migration scheduled_procedure_step_desc ainda não foi aplicada.');
    }

    $servers = $pdo->query('SELECT id, url, usuario, senha, timeout FROM bi_pacs_servidor WHERE ativo = 1')
        ->fetchAll(PDO::FETCH_ASSOC);
    if (!$servers) {
        throw new RuntimeException('Nenhum servidor PACS ativo foi encontrado.');
    }

    $clients = [];
    foreach ($servers as $server) {
        $clients[(int) $server['id']] = new OrthancService(
            (string) $server['url'],
            $server['usuario'] !== null ? (string) $server['usuario'] : null,
            Crypto::decrypt($server['senha'] ?? null),
            (int) ($server['timeout'] ?? 30)
        );
    }

    $stmt = $pdo->prepare(<<<'SQL'
        SELECT e.id, e.servidor_id, e.orthanc_id
        FROM bi_pacs_estudos e
        INNER JOIN bi_pacs_servidor s ON s.id = e.servidor_id AND s.ativo = 1
        WHERE COALESCE(BTRIM(e.study_description), '') = ''
          AND COALESCE(BTRIM(e.scheduled_procedure_step_desc), '') = ''
          AND e.orthanc_id IS NOT NULL
        ORDER BY e.atualizado_em DESC NULLS LAST, e.id DESC
        LIMIT :limit
    SQL);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updated = 0;
    $withoutTag = 0;
    $errors = 0;
    $errorReasons = [];
    $upsert = $pdo->prepare('UPDATE bi_pacs_estudos SET scheduled_procedure_step_desc = ?, atualizado_em = NOW() WHERE id = ?');

    foreach ($candidates as $candidate) {
        $client = $clients[(int) $candidate['servidor_id']] ?? null;
        if ($client === null) {
            $errors++;
            $errorReasons['servidor_inativo_ou_indisponivel'] = ($errorReasons['servidor_inativo_ou_indisponivel'] ?? 0) + 1;
            continue;
        }

        $result = $client->getScheduledProcedureStepDescription((string) $candidate['orthanc_id']);
        if (!($result['success'] ?? false)) {
            $errors++;
            $reason = trim((string) ($result['error'] ?? 'erro_orthanc'));
            $errorReasons[$reason] = ($errorReasons[$reason] ?? 0) + 1;
            continue;
        }
        $description = trim((string) ($result['description'] ?? ''));
        if ($description === '') {
            $withoutTag++;
            continue;
        }

        if (!$dryRun) {
            $upsert->execute([mb_substr($description, 0, 500, 'UTF-8'), (int) $candidate['id']]);
        }
        $updated++;
    }

    echo json_encode([
        'dry_run' => $dryRun,
        'limit' => $limit,
        'candidates' => count($candidates),
        'updated_or_found' => $updated,
        'without_tag' => $withoutTag,
        'errors' => $errors,
        'error_reasons' => $errorReasons,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['error' => $e->getMessage()]) . PHP_EOL);
    exit(1);
}
