(function () {
    'use strict';

    function init() {
        document.querySelectorAll('[data-custom-channel]').forEach(function (card) {
            const toggle = card.querySelector('.custom-channel-toggle');
            const url = card.querySelector('.custom-channel-url');
            if (!toggle || !url) return;

            function refresh(moveFocus) {
                const enabled = toggle.checked;
                card.classList.toggle('opacity-50', !enabled);
                url.readOnly = !enabled;
                url.setAttribute('aria-disabled', enabled ? 'false' : 'true');
                if (enabled && moveFocus) {
                    url.focus({ preventScroll: true });
                }
            }
            toggle.addEventListener('change', function () { refresh(true); });
            refresh(false);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
