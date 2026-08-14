<?php

declare(strict_types=1);

$raiz = dirname(__DIR__);
$css = file_get_contents($raiz . '/public/assets/css/reports.css');
$show = file_get_contents($raiz . '/app/Views/reports/show.php');
$measurements = file_get_contents($raiz . '/app/Views/reports/partials/_measurements_card.php');
$chat = file_get_contents($raiz . '/app/Views/reports/partials/_chat_card.php');

$ordemEsperada = [
    "_paciente_card.php",
    "_exame_card.php",
    "_measurements_card.php",
    "_chat_card.php",
    "_peer_review_card.php",
    "_historico_actions.php",
];
$indices = array_map(static fn(string $partial): int => strpos($show, $partial), $ordemEsperada);
$ordemCorreta = !in_array(false, $indices, true);
if ($ordemCorreta) {
    $anterior = -1;
    foreach ($indices as $indice) {
        if ($indice <= $anterior) {
            $ordemCorreta = false;
            break;
        }
        $anterior = $indice;
    }
}

$regras = [
    'coluna lateral usa flex vertical' => str_contains($css, '.reports-col-left {')
        && str_contains($css, 'display: flex;')
        && str_contains($css, 'flex-direction: column;'),
    'grid de duas colunas dos cards foi removido' => !str_contains($css, '@media (min-width: 1181px)')
        && !str_contains($css, 'grid-template-columns: repeat(2, minmax(0, 1fr));'),
    'cards diretos ocupam largura integral' => str_contains($css, '.reports-col-left > .reports-card,')
        && str_contains($css, '.reports-col-left > .reports-card-actions')
        && str_contains($css, 'width: 100%;'),
    'layout principal mantém breakpoint responsivo' => str_contains($css, '@media (max-width: 980px)')
        && str_contains($css, 'grid-template-columns: 1fr;'),
    'ordem clínica confirmada é preservada' => $ordemCorreta,
    'medidas usa card padrão' => str_contains($measurements, 'class="pacs-card reports-card viewer-measurements-card"')
        && str_contains($measurements, 'class="pacs-card-header viewer-measurements-header"')
        && str_contains($measurements, 'class="pacs-card-body reports-card-body viewer-measurements-body"'),
    'chat usa card padrão' => str_contains($chat, 'class="pacs-card reports-card reports-chat-card"')
        && str_contains($chat, 'class="pacs-card-header reports-chat-header reports-chat-toggle"')
        && str_contains($chat, 'class="pacs-card-body reports-card-body reports-chat-body"'),
];

$falhas = array_keys(array_filter($regras, static fn(bool $ok): bool => !$ok));
if ($falhas) {
    fwrite(STDERR, 'Regra(s) ausente(s): ' . implode(', ', $falhas) . PHP_EOL);
    exit(1);
}

echo "Regressão do layout vertical da coluna lateral de Laudo verificada com sucesso.\n";
