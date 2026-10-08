<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/Services/UserStudyScopeService.php');
$usersController = file_get_contents($root . '/app/Controllers/UsuariosController.php');
$usersView = file_get_contents($root . '/app/Views/usuarios/form.php');
$doctorsService = file_get_contents($root . '/app/Services/MedicoService.php');
$doctorsView = file_get_contents($root . '/app/Views/medicos/form.php');
$worklist = file_get_contents($root . '/app/Controllers/EstudosController.php');
$reports = file_get_contents($root . '/app/Services/ReportAccessService.php');
$download = file_get_contents($root . '/app/Controllers/DownloadLoteController.php');
$reportStudies = file_get_contents($root . '/app/Repositories/RelatorioEstudosRepository.php');
$reportDoctors = file_get_contents($root . '/app/Repositories/RelatorioProdutividadeMedicosRepository.php');
$postgres = file_get_contents($root . '/database/migrations/2026-10-08_user_study_scope_postgresql.sql');
$mysql = file_get_contents($root . '/database/migrations/2026-10-08_user_study_scope_mysql.sql');

$rules = [
    'service usa tabelas tenant-scoped' => str_contains($service, 'bi_user_study_scope_configs')
        && str_contains($service, 'bi_user_study_scope_rules'),
    'service valida instituição e modalidade no servidor' => str_contains($service, 'invalid_institution')
        && str_contains($service, 'invalid_modality')
        && str_contains($service, 'empty_scope'),
    'booleano PostgreSQL desativado é lido corretamente' => str_contains($service, 'databaseBoolean($config)')
        && str_contains($service, "['1', 't', 'true', 'yes', 'y', 'on']"),
    'curinga preserva modalidades futuras' => str_contains($service, "public const ALL_MODALITIES = '*'")
        && str_contains($service, '$modality === self::ALL_MODALITIES'),
    'política não tem bypass de perfil tenant' => !str_contains($service, 'isCurrentTenantAdmin')
        && str_contains($service, 'A política é independente do perfil'),
    'usuários carrega e salva escopo na transação' => str_contains($usersController, 'formDataWithInput(')
        && str_contains($usersController, 'saveForUser(')
        && str_contains($usersController, '$pdo->beginTransaction()'),
    'card de visibilidade aparece abaixo do vínculo' => str_contains($usersView, 'VÍNCULO COM MÉDICO')
        && str_contains($usersView, 'VISIBILIDADE DE ESTUDOS')
        && str_contains($usersView, 'data-study-scope-enabled'),
    'card é configurável por qualquer perfil' => !str_contains($usersView, 'scopeIsAdmin')
        && !str_contains($usersView, 'admin_irrestrito')
        && !preg_match('/function atualizarEscopoEstudosPorPerfil\(\).*?isAdmin/s', $usersView),
    'tela médica não envia unidades' => !str_contains($doctorsView, 'name="unidades[]"')
        && !str_contains($doctorsService, "post['unidades']")
        && str_contains($doctorsView, 'visibilidade_usuario_editar'),
    'worklist aplica política individual' => str_contains($worklist, 'appendStudyScope(')
        && str_contains($worklist, 'allowsStudy('),
    'reports e download aplicam segundo gate' => str_contains($reports, 'allowsStudy(')
        && str_contains($download, 'allowsStudy('),
    'relatórios aplicam predicado e catálogos escopados' => str_contains($reportStudies, 'appendStudyScopeNamed(')
        && str_contains($reportDoctors, 'appendStudyScopeNamed('),
    'migration PostgreSQL preserva somente médicos com unidade' => str_contains($postgres, 'INNER JOIN bi_medico_unidades mu')
        && str_contains($postgres, 'BTRIM(mu.institution_name) <>'),
    'migration MySQL preserva somente médicos com unidade' => str_contains($mysql, 'INNER JOIN bi_medico_unidades mu')
        && str_contains($mysql, 'TRIM(mu.institution_name) <>'),
];

$failures = array_keys(array_filter($rules, static fn(bool $ok): bool => !$ok));
if ($failures) {
    fwrite(STDERR, 'Regra(s) ausente(s): ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "Regressão estática do escopo individual de estudos verificada com sucesso.\n";
