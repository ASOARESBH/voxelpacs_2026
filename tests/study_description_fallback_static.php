<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

$files = [
    'resolver' => $root . '/app/Services/StudyDescriptionResolver.php',
    'sync' => $root . '/app/Services/PacsSyncService.php',
    'orthanc' => $root . '/app/Services/OrthancService.php',
    'controller' => $root . '/app/Controllers/EstudosController.php',
    'worklist' => $root . '/app/Views/estudos/index.php',
    'reports' => $root . '/app/Controllers/ReportsController.php',
    'migration' => $root . '/database/migrations/2026-08-18_bi_pacs_estudos_scheduled_step_description_postgresql.sql',
];

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        $errors[] = "Arquivo ausente: {$name}";
    }
}

if (!$errors) {
    $resolver = file_get_contents($files['resolver']);
    $sync = file_get_contents($files['sync']);
    $orthanc = file_get_contents($files['orthanc']);
    $controller = file_get_contents($files['controller']);
    $worklist = file_get_contents($files['worklist']);
    $reports = file_get_contents($files['reports']);
    $migration = file_get_contents($files['migration']);

    foreach ([$resolver, $sync, $orthanc, $controller, $worklist, $reports, $migration] as $content) {
        if ($content === false) {
            $errors[] = 'Falha ao ler arquivo de fallback.';
            break;
        }
    }

    $expectedPriority = [
        'study_description',
        'scheduled_procedure_step_desc',
        'requested_procedure_desc',
        'body_part_examined',
    ];
    $lastPos = -1;
    foreach ($expectedPriority as $field) {
        $position = strpos($resolver, "'field' => '{$field}'");
        if ($position === false || $position <= $lastPos) {
            $errors[] = 'Prioridade incorreta no resolvedor: ' . $field;
        }
        $lastPos = $position === false ? $lastPos : $position;
    }

    foreach ([
        'ScheduledProcedureStepDescription',
        "'00400007'",
        'scheduledProcedureStepDescription',
        'hasScheduledProcedureStepDescriptionColumn',
        'getScheduledProcedureStepDescription',
    ] as $needle) {
        if (!str_contains($sync, $needle)) {
            $errors[] = 'Captura da tag 0040,0007 ausente no sincronizador: ' . $needle;
        }
    }

    if (!str_contains($orthanc, "'scheduled_procedure_step_desc'")
        || !str_contains($orthanc, 'getScheduledProcedureStepDescription')
        || !str_contains($orthanc, '/instances/')) {
        $errors[] = 'Leitura da tag 0040,0007 por MainDicomTags ou instância ausente no OrthancService.';
    }
    if (!str_contains($controller, 'scheduled_procedure_step_desc')
        || !str_contains($controller, '$hasScheduledProcedureStepDescription')) {
        $errors[] = 'Worklist não seleciona a descrição agendada opcionalmente.';
    }
    if (!str_contains($worklist, 'StudyDescriptionResolver::resolve')) {
        $errors[] = 'Célula Estudo não usa o resolvedor central.';
    }
    if (!str_contains($reports, 'scheduled_procedure_step_desc')) {
        $errors[] = 'Laudário não recebe a descrição agendada.';
    }
    if (!str_contains($migration, 'ADD COLUMN IF NOT EXISTS scheduled_procedure_step_desc')) {
        $errors[] = 'Migration PostgreSQL não cria a coluna esperada.';
    }
}

if ($errors) {
    fwrite(STDERR, "FALHOU\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "OK: fallback Study Description -> Scheduled Procedure Step Description validado.\n";
