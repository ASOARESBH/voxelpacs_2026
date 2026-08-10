<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function source_file(string $relative): string
{
    global $root;
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        fwrite(STDERR, "Arquivo ausente: {$relative}\n");
        exit(1);
    }
    return (string) file_get_contents($path);
}

function require_text(string $haystack, string $needle, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        fwrite(STDERR, "FALHA: {$message}\n");
        exit(1);
    }
}

function forbid_text(string $haystack, string $needle, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        fwrite(STDERR, "FALHA: {$message}\n");
        exit(1);
    }
}

$css = source_file('public/assets/css/reports.css');
$exam = source_file('app/Views/reports/partials/_exame_card.php');
$show = source_file('app/Views/reports/show.php');
$history = source_file('app/Views/reports/partials/_historico_actions.php');
$chat = source_file('app/Views/reports/partials/_chat_card.php');
$doc = source_file('docs/REPORTS_RESPONSIVE_DESIGN.md');

require_text($css, 'grid-template-columns: minmax(360px, clamp(360px, 34vw, 520px)) minmax(0, 1fr);', 'Grade desktop fluida ausente.');
require_text($css, '@media (max-width: 980px)', 'Breakpoint de empilhamento do Report ausente.');
require_text($css, 'overflow-y: auto;', 'Rolagem geral da página ausente.');
require_text($css, '.reports-chat-messages {', 'Bloco de histórico do CHAT ausente.');
require_text($css, 'max-height: none;', 'Histórico do CHAT ainda possui limite de altura.');
require_text($css, '.reports-editor-container .ql-editor', 'Editor não recebeu dimensionamento responsivo.');
require_text($css, '.reports-card {', 'Regra de cards do Report ausente.');
require_text($css, 'overflow: visible;', 'Cards do Report ainda podem cortar conteúdo.');
require_text($css, '.rp-value--full', 'Valores longos não têm quebra explícita.');
forbid_text($css, 'grid-template-columns: 30% 70%', 'Grade rígida 30/70 ainda presente.');
forbid_text($css, '.reports-chat-messages { max-height: 300px; }', 'Breakpoint antigo continua limitando o histórico do CHAT.');
forbid_text($exam, 'substr($estudo->study_instance_uid, 0, 28)', 'Study UID ainda é abreviado no PHP.');
require_text($exam, '$studyUid = $estudo->study_instance_uid ?:', 'Study UID completo não está sendo preparado.');
require_text($exam, 'rp-value--full', 'Study UID completo não está marcado para quebra.');
require_text($show, "_chat_card.php", 'CHAT não está na composição do Report.');
require_text($show, "_historico_actions.php", 'Ações laterais não estão na composição do Report.');
require_text($history, 'id="card-historico-paciente"', 'Card de histórico sem identificador de grade.');
require_text($chat, 'chatDestinatarioGrupo', 'CHAT não possui grupo.');
require_text($doc, 'Nenhum conteúdo será escondido', 'Decisão de não supressão não está documentada.');

echo "OK: contrato responsivo do Reports validado.\n";
