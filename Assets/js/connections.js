(function () {
    'use strict';
    function init() {
        const form = document.getElementById('mail-connection-form');
        if (!form || form.dataset.initialized) return;
        form.dataset.initialized = '1';
        const select = form.querySelector('select[name="provider"]');
        if (select) {
            const syncProvider = function () {
                form.querySelectorAll('[data-mail-provider]').forEach(function (section) {
                    const active = section.dataset.mailProvider === select.value;
                    section.hidden = !active;
                    section.querySelectorAll('input, select').forEach(function (field) {
                        field.disabled = !active;
                        // Do not leave typed secrets in hidden provider fields.
                        if (!active && field.type === 'password') field.value = '';
                    });
                    if (active) {
                        section.querySelectorAll('.chosen-container').forEach(function (chosen) { chosen.style.width = '100%'; });
                    }
                });
            };
            // Mautic's Chosen controls dispatch jQuery changes, rather than native DOM changes.
            const query = window.mQuery || window.jQuery;
            if (query) query(select).on('change.multimailconnections', syncProvider);
            else select.addEventListener('change', syncProvider);
            syncProvider();
        }
        const remove = document.querySelector('[data-mail-remove]');
        if (remove) remove.addEventListener('submit', function (event) {
            if (!window.confirm('Remover esta conexão e suas credenciais salvas?')) event.preventDefault();
        });
    }
    window.Mautic = window.Mautic || {};
    window.Mautic.multimailconnectionsOnLoad = init;
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
