<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function dictationRead(string $relativePath): string
{
    global $root;
    $path = $root . '/' . $relativePath;
    if (!is_file($path)) {
        throw new RuntimeException("Arquivo ausente: {$relativePath}");
    }
    return (string) file_get_contents($path);
}

function dictationRequire(string $source, string $needle, string $message): void
{
    if (strpos($source, $needle) === false) {
        throw new RuntimeException($message);
    }
}

$header = dictationRead('app/Views/layout/reports_header.php');
$footer = dictationRead('app/Views/layout/reports_footer.php');
$main = dictationRead('public/assets/js/reports/reports-main.js');
$module = dictationRead('public/assets/js/reports/reports-dictation.js');
$css = dictationRead('public/assets/css/reports.css');
$routes = dictationRead('routes/web.php');
$documentation = dictationRead('SKILL-VOXEL-PACS/modules/ditado-voz.md');

// Integração visual e bootstrap preservam a proteção de somente leitura.
dictationRequire($header, 'id="btn-dictate"', 'Botão Ditar não foi incluído na topbar.');
dictationRequire($header, 'id="dictation-status"', 'Indicador de estado do ditado ausente.');
dictationRequire($header, 'aria-live="polite"', 'Indicador de ditado não é acessível.');
dictationRequire($header, '<?php if (!$readonly): ?>', 'Botão de ditado não está protegido pelo modo somente leitura.');
dictationRequire($footer, 'reports-dictation.js', 'Módulo de ditado não foi carregado pelo Laudário.');
dictationRequire($main, 'window.VoxelReports.dictation.init(config)', 'Módulo de ditado não é inicializado pelo bootstrap.');

// O reconhecimento é nativo, em pt-BR, e só insere resultados finais como uma ação de usuário no Quill.
dictationRequire($module, 'window.SpeechRecognition || window.webkitSpeechRecognition', 'Fallback de Web Speech API ausente.');
dictationRequire($module, "recognition.lang = 'pt-BR'", 'Ditado não está configurado para português do Brasil.');
dictationRequire($module, 'recognition.continuous = true', 'Ditado não mantém reconhecimento contínuo.');
dictationRequire($module, 'recognition.interimResults = true', 'Ditado não exibe reconhecimento provisório.');
dictationRequire($module, 'if (result.isFinal)', 'Texto provisório está sendo inserido indevidamente no laudo.');
dictationRequire($module, "quill.insertText(range.index, content, 'user')", 'Inserção não preserva o autosave como ação de usuário.');
dictationRequire($module, "quill.deleteText(range.index, range.length, 'user')", 'Seleção ativa não é substituída com evento de usuário.');
dictationRequire($module, "config.readonly", 'Módulo não respeita o modo somente leitura.');

// Pontuação e quebras de linha ficam no cliente; não há rota ou upload de áudio nesta fase.
dictationRequire($module, 'novo\\s+par[aá]grafo', 'Comando de novo parágrafo ausente.');
dictationRequire($module, 'nova\\s+linha', 'Comando de nova linha ausente.');
dictationRequire($module, 'ponto\\b', 'Comando de ponto ausente.');
dictationRequire($module, 'v[ií]rgula', 'Comando de vírgula ausente.');
if (stripos($module, 'fetch(') !== false || stripos($module, 'MediaRecorder') !== false || stripos($routes, '/reports/dictate/transcribe') !== false) {
    throw new RuntimeException('A Fase 1 de ditado não pode enviar ou persistir áudio no servidor.');
}

// Estados visuais e documentação de limites obrigatórios.
dictationRequire($css, '#btn-dictate.is-recording', 'Estado visual de gravação ausente.');
dictationRequire($css, 'prefers-reduced-motion', 'Estado de gravação não respeita redução de movimento.');
dictationRequire($documentation, 'Nenhum áudio é enviado', 'Documentação não registra a ausência de processamento no servidor.');
dictationRequire($documentation, 'Fase 2', 'Documentação não registra as condições da evolução em nuvem.');

fwrite(STDOUT, "REPORTS_DICTATION_STATIC_OK\n");
