<?php

declare(strict_types=1);

$raiz = dirname(__DIR__);
$card = file_get_contents($raiz . '/app/Views/reports/partials/_chat_card.php');
$css = file_get_contents($raiz . '/public/assets/css/reports.css');
$footer = file_get_contents($raiz . '/app/Views/layout/reports_footer.php');
$script = file_get_contents($raiz . '/public/assets/js/reports/reports-chat.js');
$pt = file_get_contents($raiz . '/lang/pt_BR.php');
$en = file_get_contents($raiz . '/lang/en.php');
$es = file_get_contents($raiz . '/lang/es.php');

$regras = [
    'cabeçalho do Chat é botão acessível' => str_contains($card, '<button type="button" class="pacs-card-header reports-chat-header reports-chat-toggle"'),
    'cabeçalho usa Collapse Bootstrap com alvo único' => str_contains($card, 'data-bs-toggle="collapse" data-bs-target="#chat-laudo-body"')
        && str_contains($card, 'aria-controls="chat-laudo-body"'),
    'Chat inicia recolhido sem classe show' => str_contains($card, '<div class="collapse reports-chat-collapse" id="chat-laudo-body">')
        && !str_contains($card, 'collapse show'),
    'identificadores da lógica de Chat foram preservados' => str_contains($card, 'id="reportChatForm"')
        && str_contains($card, 'id="btn-chat-send"')
        && str_contains($card, 'id="btn-chat-complete"'),
    'formulário reduzido usa um seletor único de destinatário' => str_contains($card, 'id="chatDestinatario"')
        && str_contains($card, '<optgroup')
        && !str_contains($card, 'chatDestinatarioTipo')
        && !str_contains($card, 'chatDestinatarioGrupo')
        && !str_contains($card, 'chatDestinatarioUsuario'),
    'achado crítico permanece ação explícita' => str_contains($card, 'id="btn-chat-critical"')
        && str_contains($card, 'id="chat-critical-alert"')
        && str_contains($script, 'function setCriticalMode(enabled)'),
    'botão de envio usa padrão primário' => str_contains($card, 'class="btn-pacs-primary reports-chat-send-btn" id="btn-chat-send"')
        && !str_contains($card, 'class="pacs-btn pacs-btn-primary"'),
    'botão de conclusão não depende de pacs-btn' => str_contains($card, 'class="btn-pacs-outline reports-chat-complete-btn" id="btn-chat-complete"'),
    'seta do collapse recebe transição visual' => str_contains($css, '.chat-toggle-icon { transition: transform .2s ease; }')
        && str_contains($css, '.reports-chat-toggle[aria-expanded="true"] .chat-toggle-icon { transform: rotate(180deg); }'),
    'botões do Chat têm alinhamento sem quebra' => str_contains($css, '.reports-chat-actions .btn-pacs-primary,')
        && str_contains($css, 'white-space: nowrap;'),
    'Bootstrap bundle é carregado' => str_contains($footer, 'bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'),
    'i18n expõe pluralização ao JavaScript' => str_contains($footer, "'countSingular' => t('report_chat.interacao_count_singular')")
        && str_contains($footer, "'countPlural' => t('report_chat.interacao_count_plural')"),
    'i18n expõe a ação crítica ao JavaScript' => str_contains($footer, "'criticalConfirm' => t('report_chat.confirmar_achado_critico')"),
    'todas as traduções possuem contador' => str_contains($pt, 'report_chat.interacao_count_singular')
        && str_contains($en, 'report_chat.interacao_count_singular')
        && str_contains($es, 'report_chat.interacao_count_singular'),
    'status dinâmico calcula a contagem real' => str_contains($script, 'function interactionCountText(messages)')
        && str_contains($script, 'interactionCountText(state.messages)'),
];

$falhas = array_keys(array_filter($regras, static fn(bool $ok): bool => !$ok));
if ($falhas) {
    fwrite(STDERR, 'Regra(s) ausente(s): ' . implode(', ', $falhas) . PHP_EOL);
    exit(1);
}

echo "Regressão do Chat recolhido e do botão de envio verificada com sucesso.\n";
