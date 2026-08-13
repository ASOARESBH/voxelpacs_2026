<?php

declare(strict_types=1);

$raiz = dirname(__DIR__);
$controller = file_get_contents($raiz . '/app/Controllers/EstudosController.php');
$view = file_get_contents($raiz . '/app/Views/estudos/index.php');

$regras = [
    'situacao principal tem precedência sobre atalho' => str_contains(
        $controller,
        "if (\$filtros['situacao'] === '' && \$filtros['situacao_rapida'] !== '')"
    ),
    'sobrescrita incondicional foi removida' => !str_contains(
        $controller,
        "if (\$filtros['situacao_rapida'] !== '') {\n            \$filtros['situacao'] = \$filtros['situacao_rapida'];"
    ),
    'select principal limpa o atalho rápido' => str_contains(
        $view,
        "onchange=\"this.form.elements['situacao_rapida'].value='';\""
    ),
    'atalho rápido continua sincronizando select principal' => str_contains(
        $view,
        "onchange=\"document.getElementById('selectSituacao').value=this.value;\""
    ),
    'auto-submit de selects permanece ativo' => str_contains(
        $view,
        "form.querySelectorAll('select:not([name=\"periodo\"]), input[type=\"date\"]')"
    ),
    'opções clínicas atuais continuam disponíveis' => str_contains($view, 'value="pendente"')
        && str_contains($view, 'value="a_laudar"')
        && str_contains($view, 'value="peer_review"'),
];

$falhas = array_keys(array_filter($regras, static fn(bool $ok): bool => !$ok));
if ($falhas) {
    fwrite(STDERR, 'Regra(s) ausente(s): ' . implode(', ', $falhas) . PHP_EOL);
    exit(1);
}

echo "Regressão da sincronização de Situação da Worklist verificada com sucesso.\n";
