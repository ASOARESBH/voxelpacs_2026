<?php

declare(strict_types=1);

$raiz = dirname(__DIR__);
$controller = file_get_contents($raiz . '/app/Controllers/MedicosController.php');
$service = file_get_contents($raiz . '/app/Services/MedicoService.php');
$view = file_get_contents($raiz . '/app/Views/medicos/form.php');

$regras = [
    'controller restringe a superadmin ou role admin' => str_contains($controller, 'Auth::isPlatformAdmin()')
        && str_contains($controller, "Auth::user()?->role === 'admin'"),
    'update encaminha autorização ao serviço' => str_contains($controller, 'atualizar($id, $_POST, $tenantId, $this->podeGerenciarUnidades())'),
    'cadastro encaminha autorização ao serviço' => str_contains($controller, 'cadastrar($_POST, $tenantId, $this->podeGerenciarUnidades())'),
    'serviço condiciona a sincronização no update' => str_contains($service, 'if ($podeGerenciarUnidades)')
        && str_contains($service, 'sincronizarUnidades($id, $tenantId, $unidades)'),
    'serviço nega sincronização por padrão' => str_contains($service, 'bool $podeGerenciarUnidades = false'),
    'view desabilita os checkboxes sem autorização' => str_contains($view, '!$podeGerenciarUnidades ? \'disabled\' : \'\''),
    'view informa a restrição ao usuário' => str_contains($view, 'Somente um administrador pode alterar as unidades vinculadas.'),
];

$falhas = array_keys(array_filter($regras, static fn(bool $ok): bool => !$ok));
if ($falhas) {
    fwrite(STDERR, 'Regra(s) ausente(s): ' . implode(', ', $falhas) . PHP_EOL);
    exit(1);
}

echo "Regressão de autorização médico–Unidade verificada com sucesso.\n";
