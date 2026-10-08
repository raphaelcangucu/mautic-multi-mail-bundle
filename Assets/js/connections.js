(function () {
    'use strict';
    function initUsage() {
        const refresh = document.getElementById('mail-usage-refresh');
        if (!refresh || refresh.dataset.initialized) return;
        refresh.dataset.initialized = '1';
        const status = document.getElementById('mail-usage-status');
        const historySelect = document.getElementById('mail-history-connection');
        const historyBody = document.getElementById('mail-history-body');
        const rows = new Map();
        document.querySelectorAll('[data-mail-connection]').forEach(function (row) { rows.set(row.dataset.mailConnection, row); });
        const unlimited = document.getElementById('mail-hourly-history').dataset.unlimited;
        function time(value) {
            const date = new Date(value);
            return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString(undefined, {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'});
        }
        function renderHistory() {
            historyBody.replaceChildren();
            const row = rows.get(historySelect.value);
            if (!row) return;
            let history;
            try { history = JSON.parse(row.dataset.hourlyHistory); } catch (_) { return; }
            if (!Array.isArray(history)) return;
            history.slice(0, 24).forEach(function (hour) {
                const tr = document.createElement('tr');
                [time(hour.utc), hour.accepted, hour.reserved, hour.uncertain, hour.rejected].forEach(function (value) {
                    const td = document.createElement('td'); td.textContent = String(value); tr.appendChild(td);
                });
                historyBody.appendChild(tr);
            });
        }
        const query = window.mQuery || window.jQuery;
        if (query) query(historySelect).on('change.multimailhistory', renderHistory);
        else historySelect.addEventListener('change', renderHistory);
        rows.forEach(function (row) {
            row.querySelectorAll('time[datetime]').forEach(function (element) { element.textContent = time(element.dateTime); });
        });
        renderHistory();
        refresh.addEventListener('click', async function () {
            if (refresh.disabled) return;
            refresh.disabled = true;
            status.textContent = refresh.dataset.pending;
            const controller = new AbortController();
            const timeout = window.setTimeout(function () { controller.abort(); }, 15000);
            try {
                const response = await fetch(refresh.dataset.url, {credentials:'same-origin', redirect:'error',
                    headers:{'Accept':'application/json'}, signal:controller.signal});
                const payload = await response.json();
                if (!response.ok || !Array.isArray(payload.connections)) throw new Error('Invalid counter response');
                payload.connections.forEach(function (connection) {
                    const row = rows.get(connection.id); const quota = connection.hourly;
                    if (!row || !quota || !['ready', 'limited', 'disabled', 'native'].includes(quota.status)
                        || !Number.isInteger(quota.used) || !Number.isInteger(quota.limit)) return;
                    row.querySelector('[data-quota-count]').textContent = quota.used + ' / ' + (quota.limit > 0 ? quota.limit : unlimited);
                    const progress = row.querySelector('[data-quota-progress]');
                    progress.style.width = (quota.limit > 0 ? Math.min(100, quota.used / quota.limit * 100) : 0) + '%';
                    progress.className = 'progress-bar' + (quota.status === 'limited' ? ' progress-bar-warning' : '');
                    const badge = row.querySelector('[data-quota-status]');
                    const key = 'label' + quota.status[0].toUpperCase() + quota.status.slice(1);
                    badge.textContent = badge.dataset[key];
                    badge.className = 'label ' + (quota.status === 'limited' ? 'label-warning' : (quota.status === 'ready' ? 'label-success' : 'label-default'));
                    const accepted = row.querySelector('[data-quota-accepted]');
                    accepted.textContent = accepted.dataset.label + ': ' + quota.accepted;
                    const retry = row.querySelector('[data-quota-retry]');
                    retry.hidden = !quota.retry_at;
                    retry.textContent = quota.retry_at ? retry.dataset.label + ': ' + time(quota.retry_at) : '';
                    row.dataset.hourlyHistory = JSON.stringify(quota.history);
                });
                renderHistory();
                status.textContent = refresh.dataset.done + ' · ' + time(payload.checked_at);
            } catch (_) { status.textContent = refresh.dataset.error; }
            finally { window.clearTimeout(timeout); refresh.disabled = false; }
        });
    }
    function init() {
        initUsage();
        const form = document.getElementById('mail-connection-form');
        if (!form || form.dataset.initialized) return;
        form.dataset.initialized = '1';
        const select = form.querySelector('select[name="provider"]');
        if (select) {
            const syncProvider = function () {
                const native = select.value === 'native';
                const capacity = form.querySelector('#mail-capacity-fields');
                if (capacity) {
                    capacity.hidden = native;
                    capacity.querySelectorAll('input').forEach(function (field) { field.disabled = native; });
                    // Preserve an explicit false value when native controls are disabled.
                    const hidden = capacity.querySelector('input[type="hidden"]');
                    if (hidden) hidden.disabled = false;
                }
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
