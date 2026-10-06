// Isolated JS regression harness: synthetic DOM/HTTP, no browser, network or database.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

async function main() {
    const handlers = {};
    const connection = {
        value: 'a'.repeat(32), selectedIndex: 0, disabled: false,
        options: [{ dataset: { from: 'Sender <sender@example.com>', reply: 'reply@example.com' } }],
        addEventListener(name, fn) { handlers['connection:' + name] = fn; }
    };
    const button = { disabled: false };
    const result = { hidden: false, dataset: { connection: connection.value }, textContent: 'Recovered result', className: '' };
    Object.defineProperty(result, 'innerHTML', { set() { throw new Error('Result must never use HTML'); } });
    const mainForm = { dataset: {}, querySelector() { return null; } };
    const test = {
        // HTMLFormElement's named-property behavior: input[name=action] shadows .action.
        action: { toString() { return '[object HTMLInputElement]'; } },
        dataset: { pending: 'Pending', error: 'Check before retrying' }, busy: false,
        getAttribute(name) { return name === 'action' ? '/s/mail-connections' : null; },
        querySelector(selector) { return selector === '#mail-test-connection' ? connection : button; },
        reportValidity() { return true; },
        addEventListener(name, fn) { handlers[name] = fn; },
        setAttribute() { this.busy = true; }, removeAttribute() { this.busy = false; }
    };
    const elements = { 'mail-connection-form': mainForm, 'mail-test-form': test, 'mail-test-result': result,
        'mail-test-from': {}, 'mail-test-reply': {} };
    let payload = { status: 'accepted', message: 'Accepted', reference: '123456789abc',
        details: ['Provider HTTP response: 200', 'Resend email ID: 37e4414c-5e25-4dbc-a071-43552a4bd53b', '<img onerror=alert(1)>'] };
    let mode = 'json', calls = 0, captured, release;
    const context = {
        window: {}, document: { readyState: 'complete', getElementById: id => elements[id], querySelector: () => null },
        FormData: class {
            constructor(form) { assert.equal(form, test); assert.equal(connection.disabled, true); this.values = { action: 'test' }; }
            set(name, value) { this.values[name] = value; }
        },
        fetch: async (url, options) => {
            ++calls;
            assert.equal(url, '/s/mail-connections', 'Never send to the named input instead of the route');
            assert.equal(options.method, 'POST');
            assert.equal(options.body.values.id, connection.value, 'Disabled selection must still be sent');
            assert.equal(options.body.values.action, 'test');
            assert.equal(options.credentials, 'same-origin');
            assert.equal(options.redirect, 'error');
            captured = options;
            if (mode === 'blocked') { await new Promise(resolve => { release = resolve; }); }
            return { json: async () => {
                if (mode === 'html') throw new SyntaxError('Unexpected HTML login page');
                return payload;
            } };
        }
    };
    vm.runInNewContext(fs.readFileSync(__dirname + '/../Assets/js/connections.js', 'utf8'), context);
    assert.equal(result.hidden, false, 'Recovered result remains visible on initialization');
    const submit = () => handlers.submit({ preventDefault() {} });
    await submit();
    assert.equal(calls, 1);
    assert.equal(captured.headers.Accept, 'application/json');
    assert.match(result.textContent, /37e4414c-5e25-4dbc-a071-43552a4bd53b/);
    assert.match(result.textContent, /<img onerror=alert\(1\)>/, 'Untrusted content renders as literal text');
    assert.equal(button.disabled, false); assert.equal(connection.disabled, false); assert.equal(test.busy, false);
    mode = 'blocked';
    const inflight = submit(); await submit();
    assert.equal(calls, 2, 'Double clicks cannot dispatch a second test');
    release(); await inflight;
    mode = 'json'; payload = { status: 'invalid', message: 'Session changed' };
    await submit(); assert.equal(result.textContent, 'Session changed');
    mode = 'html'; await submit(); assert.equal(result.textContent, test.dataset.error);
    assert.equal(button.disabled, false); assert.equal(connection.disabled, false);
    connection.value = 'b'.repeat(32); handlers['connection:change']();
    assert.equal(result.hidden, true, 'Result does not follow a different connection');
    console.log('PASS: correct endpoint despite named action input, selected ID in FormData, double-submit guard, literal results, provider details, lost-response guidance and session-result visibility; no browser/network/database');
}
main().catch(error => { console.error(error.message); process.exitCode = 1; });
