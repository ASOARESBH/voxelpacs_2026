<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = file_get_contents($root . '/app/Controllers/MedicosController.php');
$service = file_get_contents($root . '/app/Services/MedicoService.php');
$view = file_get_contents($root . '/app/Views/medicos/form.php');

$rules = [
    'controller não encaminha mais autorização de unidades' => !str_contains($controller, 'podeGerenciarUnidades')
        && str_contains($controller, 'atualizar($id, $_POST, $tenantId)')
        && str_contains($controller, 'cadastrar($_POST, $tenantId)'),
    'serviço não sincroniza unidades a partir do POST' => !str_contains($service, "post['unidades']")
        && !str_contains($service, 'sincronizarUnidades($id, $tenantId, $unidades)'),
    'view não possui checkboxes de unidades' => !str_contains($view, 'name="unidades[]"')
        && !str_contains($view, 'Unidades (InstitutionName DICOM)'),
    'view orienta configuração pelo usuário' => str_contains($view, 'visibilidade_usuario_ajuda')
        && str_contains($view, '/usuarios/'),
];

$failures = array_keys(array_filter($rules, static fn(bool $ok): bool => !$ok));
if ($failures) {
    fwrite(STDERR, 'Regra(s) ausente(s): ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "Regressão da fonte de visibilidade médico–usuário verificada com sucesso.\n";
