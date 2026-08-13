<?php

declare(strict_types=1);

$raiz = dirname(__DIR__);
$access = file_get_contents($raiz . '/app/Core/Access/MedicoAccess.php');
$controller = file_get_contents($raiz . '/app/Controllers/EstudosController.php');

$regras = [
    'helper expõe Unidades autorizadas' => str_contains($access, 'public static function allowedInstitutionNames(): array'),
    'helper consulta vínculo no tenant ativo' => str_contains($access, 'FROM bi_medico_unidades')
        && str_contains($access, 'tenant_id = :tenant_id')
        && str_contains($access, 'medico_id = :medico_id'),
    'helper valida Unidade recebida' => str_contains($access, 'public static function isInstitutionAllowed(string $institutionName): bool'),
    'cache de Unidades é limpo no reset' => str_contains($access, 'self::$allowedInstitutionNames = null;'),
    'Worklist importa o helper central' => str_contains($controller, 'use App\\Core\\Access\\MedicoAccess;'),
    'URL com Unidade não autorizada é normalizada' => str_contains($controller, "!MedicoAccess::isInstitutionAllowed(\$filtros['unidade'])")
        && str_contains($controller, "\$filtros['unidade'] = '';"),
    'médico usa a fonte única de Unidades' => str_contains($controller, '$institutionNames     = MedicoAccess::allowedInstitutionNames();'),
    'dropdown médico usa a mesma fonte' => str_contains($controller, '$unidades = MedicoAccess::allowedInstitutionNames();'),
    'médico sem vínculo não recebe fallback do tenant' => substr_count($controller, "elseif (\$isMedicoFiltro) {\n                    \$cWhere[] = '1=0';") === 1
        && substr_count($controller, "elseif (\$isMedicoFiltro) {\n                    \$rWhere[] = '1=0';") === 1
        && str_contains($controller, "// Médico sem Unidade vinculada não pode herdar a visão inteira do tenant."),
];

$falhas = array_keys(array_filter($regras, static fn(bool $ok): bool => !$ok));
if ($falhas) {
    fwrite(STDERR, 'Regra(s) ausente(s): ' . implode(', ', $falhas) . PHP_EOL);
    exit(1);
}

echo "Regressão da Worklist por Unidades médicas verificada com sucesso.\n";
