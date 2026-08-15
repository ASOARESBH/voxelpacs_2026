/*
 * VOXEL PACS — Ditado por voz (Fase 1)
 *
 * Usa exclusivamente a Web Speech API do navegador. Nenhum áudio é enviado,
 * armazenado ou processado pelo backend do VOXEL PACS nesta entrega.
 */
window.VoxelReports = window.VoxelReports || {};
window.VoxelReports.dictation = (function () {
    let config = null;
    let recognition = null;
    let button = null;
    let status = null;
    let listening = false;
    let initialized = false;

    const COMMANDS = [
        [/\bnovo\s+par[aá]grafo\b/gi, '\n\n'],
        [/\bnova\s+linha\b/gi, '\n'],
        [/\bquebra\s+de\s+linha\b/gi, '\n'],
        [/\bponto\s+e\s+v[ií]rgula\b/gi, '; '],
        [/\bdois\s+pontos\b/gi, ': '],
        [/\babre\s+par[eê]nteses\b/gi, ' ('],
        [/\bfecha\s+par[eê]nteses\b/gi, ') '],
        [/\bponto\s+final\b/gi, '. '],
        [/\bponto\b/gi, '. '],
        [/\bv[ií]rgula\b/gi, ', '],
    ];

    function getRecognitionConstructor() {
        return window.SpeechRecognition || window.webkitSpeechRecognition || null;
    }

    function setStatus(message, type) {
        if (!status) return;
        status.textContent = message || '';
        status.className = 'dictation-status' + (type ? ` is-${type}` : '');
    }

    function setListening(active) {
        listening = active;
        if (!button) return;
        button.classList.toggle('is-recording', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
        button.querySelector('[data-dictation-label]').textContent = active ? 'Parar ditado' : 'Ditar';
        button.title = active ? 'Parar ditado' : 'Iniciar ditado por voz';
    }

    function normalizeTranscript(transcript) {
        let result = String(transcript || '').replace(/\s+/g, ' ').trim();
        COMMANDS.forEach(([pattern, replacement]) => {
            result = result.replace(pattern, replacement);
        });
        return result
            .replace(/\s+([,.;:!?])/g, '$1')
            .replace(/([,.;:!?])(?=[^\s\n])/g, '$1 ')
            .replace(/ *\n */g, '\n')
            .trim();
    }

    function insertTranscript(transcript) {
        const text = normalizeTranscript(transcript);
        if (!text) return;

        const editor = window.VoxelReports.editor;
        const quill = editor && typeof editor.getQuill === 'function' ? editor.getQuill() : null;
        if (!quill) {
            setStatus('Editor indisponível para receber o ditado.', 'error');
            return;
        }

        const range = quill.getSelection(true) || { index: quill.getLength(), length: 0 };
        const previous = range.index > 0 ? quill.getText(range.index - 1, 1) : '';
        const prefix = previous && !/\s/.test(previous) && !text.startsWith('\n') ? ' ' : '';
        const suffix = /[\s\n]$/.test(text) ? '' : ' ';
        const content = prefix + text + suffix;

        if (range.length) {
            quill.deleteText(range.index, range.length, 'user');
        }
        quill.insertText(range.index, content, 'user');
        quill.setSelection(range.index + content.length, 0, 'silent');
        setStatus('Texto inserido no laudo.', 'success');
    }

    function transcriptionError(event) {
        const messages = {
            'not-allowed': 'Permissão de microfone negada. Autorize o microfone no navegador para ditar.',
            'service-not-allowed': 'O serviço de reconhecimento de voz não está autorizado neste navegador.',
            'audio-capture': 'Nenhum microfone disponível foi encontrado.',
            network: 'Falha de rede no reconhecimento de voz. A digitação manual continua disponível.',
            'no-speech': 'Nenhuma fala reconhecida. Tente novamente.',
            aborted: '',
        };
        const message = messages[event.error] || 'Não foi possível reconhecer o ditado. Tente novamente.';
        if (message) setStatus(message, 'error');
    }

    function start() {
        if (!recognition || !button || button.disabled || listening) return;
        try {
            recognition.start();
        } catch (error) {
            setStatus('Não foi possível iniciar o microfone. Tente novamente.', 'error');
        }
    }

    function stop() {
        if (recognition && listening) recognition.stop();
    }

    function wireRecognition(Recognition) {
        recognition = new Recognition();
        recognition.lang = 'pt-BR';
        recognition.continuous = true;
        recognition.interimResults = true;
        recognition.maxAlternatives = 1;

        recognition.onstart = function () {
            setListening(true);
            setStatus('Ouvindo… fale normalmente. Diga “ponto”, “vírgula” ou “nova linha” quando necessário.', 'recording');
        };

        recognition.onresult = function (event) {
            let interim = '';
            for (let i = event.resultIndex; i < event.results.length; i += 1) {
                const result = event.results[i];
                const transcript = result[0] ? result[0].transcript : '';
                if (result.isFinal) {
                    insertTranscript(transcript);
                } else {
                    interim += transcript;
                }
            }
            if (interim) setStatus(`Ouvindo: ${interim.trim()}`, 'recording');
        };

        recognition.onerror = function (event) {
            transcriptionError(event);
        };

        recognition.onend = function () {
            const wasListening = listening;
            setListening(false);
            if (wasListening && (!status.textContent || status.classList.contains('is-recording'))) {
                setStatus('Ditado finalizado.', '');
            }
        };
    }

    function init(cfg) {
        if (initialized) return;
        initialized = true;
        config = cfg;
        button = document.getElementById('btn-dictate');
        status = document.getElementById('dictation-status');
        if (!button || config.readonly) return;

        const Recognition = getRecognitionConstructor();
        if (!Recognition) {
            button.disabled = true;
            button.title = 'Ditado por voz disponível no Google Chrome e Microsoft Edge.';
            button.setAttribute('aria-disabled', 'true');
            setStatus('Ditado por voz indisponível neste navegador. Use Google Chrome ou Microsoft Edge.', 'warning');
            return;
        }

        wireRecognition(Recognition);
        button.addEventListener('click', function () {
            if (listening) stop();
            else start();
        });
        window.addEventListener('beforeunload', stop, { once: true });
    }

    return { init, stop };
})();
