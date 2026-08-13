<?php

declare(strict_types=1);

$raiz = dirname(__DIR__);
$controller = file_get_contents($raiz . '/app/Controllers/EstudosController.php');
$view = file_get_contents($raiz . '/app/Views/estudos/index.php');

$regras = [
    'dropdown usa bi_medicos como fonte oficial' => str_contains($controller, 'Fonte: bi_medicos (cadastro oficial)'),
    'médico restrito é resolvido pelo helper central' => str_contains($controller, 'MedicoAccess::isRestricted()')
        && str_contains($controller, 'MedicoAccess::currentMedicoId()'),
    'médico restrito recebe lista unitária' => str_contains($controller, "\$medicos = [[\n                        'id'")
        && str_contains($controller, "'nome' => \$meRow['nome']"),
    'demais perfis preservam todos os médicos do tenant' => str_contains($controller, 'Admin, superadmin, analista e viewer preservam a visão completa.')
        && str_contains($controller, 'SELECT id, nome FROM bi_medicos WHERE tenant_id = ? AND ativo = 1 ORDER BY nome'),
    'filtro consulta o nome gravado no estudo' => str_contains($controller, "e.assumido_por LIKE ?")
        && str_contains($controller, "\$params[] = '%' . \$filtros['medico'] . '%';"),
    'assumir estudo grava nome do médico' => str_contains($controller, 'assumido_por (nome médico)')
        && str_contains($controller, 'assumido_por           = ?'),
    'view envia o nome selecionado' => str_contains($view, 'value="<?= htmlspecialchars($nomeMed) ?>"'),
];

$falhas = array_keys(array_filter($regras, static fn(bool $ok): bool => !$ok));
if ($falhas) {
    fwrite(STDERR, 'Regra(s) ausente(s): ' . implode(', ', $falhas) . PHP_EOL);
    exit(1);
}

echo "Regressão do filtro Médico responsável verificada com sucesso.\n";
