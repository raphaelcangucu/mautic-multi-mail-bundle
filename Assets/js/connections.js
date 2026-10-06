(function () {
    'use strict';
    function init() {
        const form = document.getElementById('mail-connection-form');
        if (!form || form.dataset.initialized) return;
        form.dataset.initialized = '1';
        const select = form.querySelector('select[name="provider"]');
        if (select) {
            const syncProvider = function () {
                const native = select.value === 'native';
                const fallback = form.querySelector('#mail-fallback');
                if (fallback) {
                    fallback.disabled = native;
                    if (native) fallback.value = '';
                    const query = window.mQuery || window.jQuery;
                    if (query) query(fallback).trigger('chosen:updated');
                }
                const nativeHelp = form.querySelector('#mail-native-fallback-help');
                if (nativeHelp) nativeHelp.hidden = !native;
                form.querySelectorAll('[data-mail-provider]').forEach(function (section) {
                    const active = section.dataset.mailProvider === select.value;
                    section.hidden = !active;
                    section.querySelectorAll('input, select').forEach(function (field) {
                        field.disabled = !active;
                        // Do not leave typed secrets in hidden provider fields.
                        if (!active && field.type === 'password') field.value = '';
                        // Chosen keeps a separate disabled state when an inactive provider becomes active.
                        const query = window.mQuery || window.jQuery;
                        if (field.tagName === 'SELECT' && query) query(field).trigger('chosen:updated');
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
        const test = document.getElementById('mail-test-form');
        if (test) {
            const connection = test.querySelector('#mail-test-connection');
            const result = document.getElementById('mail-test-result');
            const button = test.querySelector('button[type="submit"]');
            const sync = function () {
                const option = connection.options[connection.selectedIndex];
                document.getElementById('mail-test-from').textContent = option?.dataset.from || '—';
                document.getElementById('mail-test-reply').textContent = option?.dataset.reply || '—';
                button.disabled = !connection.value;
                result.hidden = result.dataset.connection !== connection.value;
            };
            const query = window.mQuery || window.jQuery;
            if (query) query(connection).on('change.multimailtest', sync);
            else connection.addEventListener('change', sync);
            sync();
            test.addEventListener('submit', async function (event) {
                event.preventDefault();
                if (button.disabled || !test.reportValidity()) return;
                button.disabled = true;
                connection.disabled = true;
                if (query) query(connection).trigger('chosen:updated');
                result.className = 'alert alert-info mt-md mb-0';
                result.textContent = test.dataset.pending;
                result.dataset.connection = connection.value;
                result.hidden = false;
                test.setAttribute('aria-busy', 'true');
                try {
                    // FormData omits disabled fields: pin the selected connection explicitly.
                    const data = new FormData(test);
                    data.set('id', connection.value);
                    // A control named "action" can shadow HTMLFormElement.action.
                    // Read the attribute so the request always targets the actual route.
                    const response = await fetch(test.getAttribute('action'), { method: 'POST', body: data,
                        credentials: 'same-origin', headers: { 'Accept': 'application/json' }, redirect: 'error' });
                    const payload = await response.json();
                    if (typeof payload.message !== 'string') throw new Error('Invalid response');
                    result.className = 'alert ' + (payload.status === 'accepted' ? 'alert-success' : 'alert-warning') + ' mt-md mb-0';
                    const details = Array.isArray(payload.details) ? payload.details.filter(function (detail) { return typeof detail === 'string'; }) : [];
                    result.textContent = payload.message + (payload.reference ? ' · ' + payload.reference : '')
                        + (details.length ? '\n' + details.join('\n') : '');
                } catch (_) {
                    result.className = 'alert alert-warning mt-md mb-0';
                    result.textContent = test.dataset.error;
                } finally {
                    button.disabled = false;
                    connection.disabled = false;
                    test.removeAttribute('aria-busy');
                    if (query) query(connection).trigger('chosen:updated');
                }
            });
        }
    }
    window.Mautic = window.Mautic || {};
    window.Mautic.multimailconnectionsOnLoad = init;
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
