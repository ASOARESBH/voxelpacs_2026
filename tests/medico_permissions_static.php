<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        throw new RuntimeException('Arquivo ausente: ' . $relative);
    }
    return (string) file_get_contents($path);
};

$access = $read('app/Core/Access/MedicoAccess.php');
$medicosController = $read('app/Controllers/MedicosController.php');
$medicoService = $read('app/Services/MedicoService.php');
$medicoRepository = $read('app/Repositories/MedicoRepository.php');
$templates = $read('app/Controllers/TemplatesController.php');
$assinaturas = $read('app/Controllers/MedicoAssinaturaController.php');
$unidades = $read('app/Controllers/UnidadesController.php');
$header = $read('app/Views/layout/pacs_header.php');
$medicosView = $read('app/Views/medicos/index.php');
$moduleDoc = $read('SKILL-VOXEL-PACS/modules/medicos.md');
$authDoc = $read('SKILL-VOXEL-PACS/architecture/auth-e-permissoes.md');

$expect(str_contains($access, 'public static function currentMedicoId(): ?int'), 'MedicoAccess não expõe o médico vinculado atual.');
$expect(str_contains($access, "if (\$perfil !== 'medico')"), 'MedicoAccess não restringe por perfil médico.');
$expect(str_contains($access, "['admin', 'administrador']"), 'MedicoAccess não preserva administrador do tenant.');
$expect(str_contains($access, 'self::$restricted = true;') && str_contains($access, 'self::$medicoId = 0;'), 'MedicoAccess não falha fechado ao resolver vínculo médico.');

$expect(str_contains($medicoRepository, 'AND m.id = :only_medico_id'), 'Listagem de médicos não suporta filtro pelo próprio ID.');
$expect(str_contains($medicoService, '?int $onlyMedicoId = null'), 'Serviço de médicos não propaga o filtro de próprio ID.');
$expect(str_contains($medicosController, 'MedicoAccess::isRestricted() ? MedicoAccess::currentMedicoId() : null'), 'MedicosController::index não restringe a listagem do médico vinculado.');
$expect(substr_count($medicosController, 'guardOwnRecordOrDeny($id);') >= 3, 'Guards de edição, atualização e status do próprio médico estão incompletos.');
$expect(substr_count($medicosController, 'denyIfRestricted();') >= 2, 'Guard de criação de médico está incompleto.');
$expect(str_contains($medicosController, 'guardOwnRecordJsonOrDeny($id)'), 'APIs de médico por ID não possuem guard JSON de posse.');

$expect(str_contains($templates, 'guardOwnMedicoOrDeny') && substr_count($templates, 'guardOwnMedicoOrDeny($medicoId)') >= 6, 'Templates ainda permitem bypass por medicoId de outro usuário.');
$expect(str_contains($assinaturas, 'MedicoAccess::isRestricted() && MedicoAccess::currentMedicoId() !== $medicoId'), 'Assinaturas médicas não validam a posse do cadastro.');

$expect(str_contains($unidades, 'private function denyIfRestricted(): void'), 'UnidadesController não tem guard central para médico restrito.');
foreach (['index', 'edit', 'update', 'novaUnidade', 'criarUnidade', 'editarUnidade', 'atualizarUnidade', 'excluirUnidade'] as $method) {
    $expect(
        str_contains($unidades, "function {$method}")
            && preg_match('/function ' . preg_quote($method, '/') . '[^{]*\\{\\s*\\$this->denyIfRestricted\\(\\);/s', $unidades) === 1,
        "UnidadesController::{$method} não bloqueia médico restrito."
    );
}
$expect(str_contains($unidades, '$authenticatedByIntegration') && str_contains($unidades, 'MedicoAccess::isRestricted()'), 'apiInfo não diferencia integração Bearer válida de sessão médica restrita.');

$expect(str_contains($header, '<?php if (!$medicoRestrito): ?>') && str_contains($header, 'href="/unidades"'), 'Sidebar ainda renderiza Unidades para médico restrito.');
$expect(str_contains($medicosView, "!\\App\\Core\\Access\\MedicoAccess::isRestricted()"), 'View de médicos ainda oferece criação para médico restrito.');
$expect(str_contains($moduleDoc, 'Correção de acesso por médico vinculado — 2026-08-13'), 'Documentação do módulo Médico não foi atualizada.');
$expect(str_contains($authDoc, 'MedicoAccess — escopo de cadastro médico (2026-08-13)'), 'Documentação de autorização não registra a nova aplicação.');
$expect(str_contains($authDoc, 'Gaps conhecidos fora do escopo'), 'Gaps de autorização fora do escopo não foram documentados.');

if ($failures) {
    fwrite(STDERR, "FALHOU:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: permissões de médico restrito validadas estaticamente.\n";
