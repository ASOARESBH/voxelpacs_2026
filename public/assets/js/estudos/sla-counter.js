/**
 * VOXEL PACS — SLA Counter (Fase 1)
 * Motor único de contadores de SLA da Worklist de Estudos.
 * Um único setInterval global atualiza todos os badges [data-sla-origin]
 * a cada 1s — nunca cria um timer por linha (necessário para 10k+ estudos).
 * Não faz nenhuma chamada AJAX: todo cálculo é feito localmente a partir
 * dos timestamps enviados pelo backend.
 */
(function (global) {
    'use strict';

    var DEFAULT_THRESHOLDS = { verdeMaxMin: 30, amareloMaxMin: 120, laranjaMaxMin: 360 };
    var SLA_CLASSES = ['sla-verde', 'sla-amarelo', 'sla-laranja', 'sla-vermelho'];

    var thresholds = DEFAULT_THRESHOLDS;
    var clockOffsetMs = 0;
    var elements = [];
    var timerId = null;

    function pad2(n) {
        return n < 10 ? '0' + n : String(n);
    }

    function classify(minutes) {
        if (minutes <= thresholds.verdeMaxMin) return 'sla-verde';
        if (minutes <= thresholds.amareloMaxMin) return 'sla-amarelo';
        if (minutes <= thresholds.laranjaMaxMin) return 'sla-laranja';
        return 'sla-vermelho';
    }

    function formatDuration(totalMinutes) {
        var mins = Math.max(0, Math.floor(totalMinutes));
        var days = Math.floor(mins / 1440);
        if (days >= 1) {
            var hours = Math.floor((mins % 1440) / 60);
            return days + 'd ' + pad2(hours) + 'h';
        }
        var hh = Math.floor(mins / 60);
        var mm = mins % 60;
        return pad2(hh) + ':' + pad2(mm);
    }

    function nowMs() {
        return Date.now() + clockOffsetMs;
    }

    function render(el) {
        var origin = el.getAttribute('data-sla-origin');
        if (!origin) return;
        var originMs = Date.parse(origin);
        if (isNaN(originMs)) return;

        var diffMinutes = (nowMs() - originMs) / 60000;
        var cls = classify(diffMinutes);

        for (var i = 0; i < SLA_CLASSES.length; i++) {
            el.classList.remove(SLA_CLASSES[i]);
        }
        el.classList.add(cls);
        el.textContent = formatDuration(diffMinutes);
    }

    function tick() {
        for (var i = 0; i < elements.length; i++) {
            render(elements[i]);
        }
    }

    // Reindexa os badges presentes no DOM. Seguro/barato de chamar de novo
    // (ex.: depois que "Assumir" injeta um novo badge de SLA Médico na linha).
    function rescan() {
        elements = Array.prototype.slice.call(document.querySelectorAll('[data-sla-origin]'));
        tick();
    }

    function init(opts) {
        opts = opts || {};
        if (opts.thresholds) thresholds = opts.thresholds;
        if (opts.serverNow) clockOffsetMs = (opts.serverNow * 1000) - Date.now();

        rescan();

        if (timerId) clearInterval(timerId);
        timerId = setInterval(tick, 1000);
    }

    global.SlaCounter = { init: init, rescan: rescan };
})(window);
