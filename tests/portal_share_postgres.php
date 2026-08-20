<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$required = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SCHEMA'];
foreach ($required as $key) {
    $value = getenv($key);
    if ($value === false || $value === '') {
        fwrite(STDERR, "Variável obrigatória ausente: {$key}" . PHP_EOL);
        exit(2);
    }
    $_ENV[$key] = $value;
}
$_ENV['DB_DRIVER'] = 'pgsql';
$_SERVER['HTTP_HOST'] = 'portal-homolog.127.0.0.1.nip.io';
$_SERVER['HTTPS'] = 'off';

$root = dirname(__DIR__);
foreach ([
    'app/Core/Logger.php', 'app/Core/PostgresPdo.php', 'app/Core/Database.php', 'app/Core/SqlHelper.php',
    'app/Core/PortalHost.php', 'app/Core/PatientPortalSession.php', 'app/Core/Auth.php', 'app/Core/TenantContext.php',
    'app/Core/Audit/AuditLogger.php', 'app/Core/Mailer.php', 'app/Services/PatientPortalService.php',
    'app/Services/PortalShareService.php',
] as $file) {
    require $root . '/' . $file;
}

$normalize = static function (string $value): string {
    $value = str_replace('^', ' ', trim($value));
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = preg_replace('/[^A-Za-z0-9]+/', ' ', $value) ?? '';
    return strtoupper(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
};

try {
    $pdo = \App\Core\Database::getInstance();
    if (!(new \App\Core\SqlHelper())::hasTable($pdo, 'bi_portal_share_links')) {
        throw new RuntimeException('Tabela de compartilhamento do Portal não foi criada.');
    }
    $candidate = $pdo->query(
        "SELECT r.public_token, r.id AS report_id, e.tenant_id, e.patient_name, e.patient_name_display,
                e.patient_birth_date, e.patient_sex
         FROM reports r
         INNER JOIN bi_pacs_estudos e ON e.id = r.estudo_id AND e.tenant_id = r.tenant_id
         WHERE CAST(r.situacao AS TEXT) = 'liberado' AND r.public_token IS NOT NULL
         ORDER BY r.id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$candidate) {
        throw new RuntimeException('Não há laudo liberado disponível para a regressão de compartilhamento.');
    }
    $patientName = (string) ($candidate['patient_name'] ?: $candidate['patient_name_display']);
    $scope = [
        'tenant_id' => (int) $candidate['tenant_id'],
        'patient_name_normalized' => $normalize($patientName),
        'patient_birth_date' => (string) $candidate['patient_birth_date'],
        'patient_sex' => (string) $candidate['patient_sex'],
        'identity_hash' => hash('sha256', 'portal-share-regression'),
    ];

    $pdo->beginTransaction();
    try {
        $service = new \App\Services\PortalShareService($pdo);
        $created = $service->createWhatsappLink((string) $candidate['public_token'], $scope, '5531999999999', '127.0.0.1');
        if (!str_contains($created['whatsapp_url'] ?? '', 'wa.me/5531999999999') || !str_contains($created['url'] ?? '', '/compartilhado/')) {
            throw new RuntimeException('Link de WhatsApp temporário não foi criado no formato esperado.');
        }
        $rawToken = basename(parse_url((string) $created['url'], PHP_URL_PATH) ?: '');
        $shared = $service->sharedReportByToken($rawToken);
        if (!$shared || (int) $shared['report']['report_id'] !== (int) $candidate['report_id']) {
            throw new RuntimeException('O link temporário não resolve o laudo liberado correto.');
        }
        $count = (int) $pdo->query('SELECT COUNT(*) FROM bi_portal_share_links')->fetchColumn();
        if ($count < 1) {
            throw new RuntimeException('A trilha de compartilhamento não foi persistida na transação de teste.');
        }
    } finally {
        $pdo->rollBack();
    }
    echo "PORTAL_SHARE_POSTGRES_OK\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Falha no compartilhamento PostgreSQL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
