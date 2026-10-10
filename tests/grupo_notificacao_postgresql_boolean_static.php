<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if ($content === false) {
        throw new RuntimeException("Arquivo ausente: {$path}");
    }
    return $content;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$repository = $read('app/Repositories/GrupoNotificacaoRepository.php');
$service = $read('app/Services/GrupoNotificacaoService.php');
$module = $read('SKILL-VOXEL-PACS/modules/usuarios.md');
$ci = $read('.github/workflows/ci.yml');

$booleanBindings = [
    [':ativo', "['ativo']"],
    [':email', "['canal_email']"],
    [':whatsapp', "['canal_whatsapp']"],
    [':telegram', "['canal_telegram']"],
];
foreach ($booleanBindings as [$parameter, $configKey]) {
    $expected = "bindValue('{$parameter}', !empty(\$config{$configKey}), PDO::PARAM_BOOL);";
    $assert(str_contains($repository, $expected), "Binding booleano ausente para {$parameter}.");
}

$assert(str_contains($repository, "\$stmt->bindValue(':tenant_id', \$tenantId, PDO::PARAM_INT);"), 'Tenant deve permanecer bindado como inteiro.');
$assert(str_contains($repository, "\$stmt->bindValue(':grupo_id', \$groupId, PDO::PARAM_INT);"), 'Grupo deve permanecer bindado como inteiro.');
$assert(str_contains($repository, '$stmt->execute();'), 'Statement configurado deve ser executado após os bindings.');
$assert(!preg_match('/prepare\(\$sql\)->execute\(\[/', $repository), 'O SQL de configuração não pode voltar ao execute associativo sem tipos.');

$assert(str_contains($service, "'boolean_binding' => 'PDO::PARAM_BOOL'"), 'O diagnóstico deve registrar a estratégia de binding.');
$assert(str_contains($service, "'transaction' => 'committed'"), 'O diagnóstico deve registrar commit concluído.');
$assert(str_contains($service, "'transaction' => 'rolled_back'"), 'O diagnóstico deve registrar rollback após falha.');
$assert(str_contains($service, "'exception_class' => get_class(\$e)"), 'O diagnóstico deve registrar a classe da exceção.');
$assert(str_contains($module, 'PDO::PARAM_BOOL'), 'O contrato de binding deve estar documentado no módulo.');
$assert(str_contains($ci, 'php tests/grupo_notificacao_postgresql_boolean_static.php'), 'A regressão deve ser executada no CI.');

echo "grupo_notificacao_postgresql_boolean_static=ok\n";
