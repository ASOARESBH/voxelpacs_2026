<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este teste só pode ser executado no CLI.\n");
    exit(1);
}

foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $required) {
    if (getenv($required) === false || getenv($required) === '') {
        fwrite(STDERR, "Variável obrigatória ausente: {$required}\n");
        exit(2);
    }
}

$_ENV['DB_DRIVER'] = 'pgsql';
$_ENV['DB_HOST'] = (string) getenv('DB_HOST');
$_ENV['DB_PORT'] = (string) (getenv('DB_PORT') ?: '5432');
$_ENV['DB_DATABASE'] = (string) getenv('DB_DATABASE');
$_ENV['DB_USERNAME'] = (string) getenv('DB_USERNAME');
$_ENV['DB_PASSWORD'] = (string) getenv('DB_PASSWORD');
$_ENV['DB_SCHEMA'] = (string) (getenv('DB_SCHEMA') ?: 'public');

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\PacsSyncService;
use App\Services\StudyDescriptionResolver;

$pdo = Database::getInstance();
$serverId = (int) $pdo->query('SELECT id FROM bi_pacs_servidor WHERE ativo = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
$tenantId = (int) $pdo->query('SELECT id FROM bi_tenants ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($serverId <= 0 || $tenantId <= 0) {
    fwrite(STDERR, "Servidor PACS ou tenant de teste não encontrado.\n");
    exit(3);
}

$expected = 'DESCRICAO PROCEDIMENTO AGENDADO TESTE';
$orthancId = 'probe-scheduled-step-' . bin2hex(random_bytes(12));

try {
    $pdo->beginTransaction();
    $study = [
        'orthanc_id' => $orthancId,
        'study_instance_uid' => '1.2.826.0.1.3680043.10.5432.' . random_int(100000, 999999),
        'patient_name' => 'TESTE^FALLBACK',
        'patient_id' => 'TESTE-FALLBACK',
        'study_date' => date('Y-m-d'),
        'modalities' => 'CR',
        'study_description' => null,
        'institution_name' => 'TESTE FALLBACK',
    ];
    $routing = [
        'tenant_id' => $tenantId,
        'status' => 'roteado',
        'candidatos' => [],
    ];
    $tags = json_encode(['0040,0007' => $expected], JSON_THROW_ON_ERROR);
    PacsSyncService::upsertEstudo($pdo, $serverId, $study, $routing, $tags);

    $stmt = $pdo->prepare('SELECT study_description, scheduled_procedure_step_desc, requested_procedure_desc, body_part_examined FROM bi_pacs_estudos WHERE orthanc_id = ?');
    $stmt->execute([$orthancId]);
    $saved = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$saved || ($saved['scheduled_procedure_step_desc'] ?? '') !== $expected) {
        throw new RuntimeException('A tag 0040,0007 não foi persistida corretamente.');
    }
    $resolved = StudyDescriptionResolver::resolve($saved);
    if ($resolved['description'] !== $expected || $resolved['tag'] !== '(0040,0007)') {
        throw new RuntimeException('O fallback da tag 0040,0007 não foi resolvido corretamente.');
    }

    $pdo->rollBack();
    echo "OK: captura e fallback (0040,0007) aprovados em transação revertida.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "FALHOU: {$e->getMessage()}\n");
    exit(1);
}
