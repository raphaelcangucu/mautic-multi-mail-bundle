// Isolated JS harness for the conversational controls: synthetic DOM/HTTP, no browser, network or database.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

async function main() {
    const source = fs.readFileSync(__dirname + '/../Assets/js/connections.js', 'utf8');

    // Round trip: only for SMTP connections with IMAP, posts action=roundtrip, shows literal text.
    const handlers = {};
    const options = [{ dataset: { from: 'Macro <contato@macro.test>', reply: '', imap: '1' } }, { dataset: { from: 'API', reply: '', imap: '0' } }];
    const connection = { value: 'a'.repeat(32), selectedIndex: 0, disabled: false, options, addEventListener(name, fn) { handlers['connection:' + name] = fn; } };
    const button = { disabled: false };
    const roundTrip = { disabled: true, dataset: { pending: 'Reading the mailbox', error: 'Check before retrying' }, addEventListener(name, fn) { handlers['roundtrip:' + name] = fn; } };
    const result = { hidden: true, dataset: {}, textContent: '', className: '' };
    Object.defineProperty(result, 'innerHTML', { set() { throw new Error('Result must never use HTML'); } });
    const test = {
        dataset: { pending: 'Pending', error: 'Error' },
        getAttribute(name) { return name === 'action' ? '/s/mail-connections' : null; },
        querySelector(selector) { return selector === '#mail-test-connection' ? connection : button; },
        reportValidity() { return true; }, addEventListener(name, fn) { handlers[name] = fn; }, setAttribute() {}, removeAttribute() {}
    };
    const elements = { 'mail-connection-form': { dataset: {}, querySelector() { return null; } }, 'mail-test-form': test, 'mail-test-result': result,
        'mail-test-from': {}, 'mail-test-reply': {}, 'mail-roundtrip': roundTrip };
    let calls = 0, sent, payload = { status: 'arrived', message: 'Round trip confirmed', reference: 'abcd1234', details: ['Tested address: contato+teste1@macro.test', '<b>literal</b>'] };
    const context = {
        window: {}, document: { readyState: 'complete', getElementById: id => elements[id], querySelector: () => null },
        FormData: class { constructor() { this.values = { action: 'test' }; } set(name, value) { this.values[name] = value; } },
        fetch: async (url, options) => { ++calls; sent = options.body.values; assert.equal(url, '/s/mail-connections'); assert.equal(connection.disabled, true);
            assert.equal(roundTrip.disabled, true, 'No second round trip while one is running'); return { json: async () => payload }; }
    };
    vm.runInNewContext(source, context);
    assert.equal(roundTrip.disabled, false, 'Enabled for a connection with IMAP');
    await handlers['roundtrip:click']();
    assert.equal(calls, 1);
    assert.equal(sent.action, 'roundtrip'); assert.equal(sent.id, connection.value);
    assert.match(result.textContent, /Round trip confirmed · abcd1234/);
    assert.match(result.textContent, /<b>literal<\/b>/, 'Server text stays literal');
    assert.equal(result.className, 'alert alert-success mt-md mb-0');
    assert.equal(roundTrip.disabled, false); assert.equal(connection.disabled, false); assert.equal(button.disabled, false);
    payload = { status: 'not_arrived', message: 'Did not arrive', reference: 'x' };
    await handlers['roundtrip:click']();
    assert.equal(result.className, 'alert alert-warning mt-md mb-0');
    connection.selectedIndex = 1; handlers['connection:change']();
    assert.equal(roundTrip.disabled, true, 'API connections have no round trip');
    await handlers['roundtrip:click']();
    assert.equal(calls, 2, 'Disabled button sends nothing');

    // Provider switch never unlocks the conversational flag when the server has no Meta bundle.
    const providerHandlers = {};
    const provider = { value: 'resend', addEventListener(name, fn) { providerHandlers[name] = fn; } };
    const locked = { tagName: 'INPUT', type: 'checkbox', disabled: true, dataset: { mailLocked: '1' } };
    const host = { tagName: 'INPUT', type: 'text', disabled: true, dataset: {} };
    const sections = [{ dataset: { mailProvider: 'smtp' }, querySelectorAll: selector => selector === 'input, select' ? [locked, host] : [] }];
    const flag = { checked: false, disabled: false, addEventListener(name, fn) { providerHandlers['flag:' + name] = fn; } };
    const imapHost = { required: false };
    const form = { dataset: {}, querySelectorAll: () => sections,
        querySelector: selector => ({ 'select[name="provider"]': provider, '#mail-conversational': flag, '#mail-imap-host': imapHost })[selector] ?? null };
    vm.runInNewContext(source, { window: {}, document: { readyState: 'complete', getElementById: id => id === 'mail-connection-form' ? form : null, querySelector: () => null } });
    provider.value = 'smtp'; providerHandlers.change();
    assert.equal(host.disabled, false, 'SMTP fields enabled');
    assert.equal(locked.disabled, true, 'Locked flag stays disabled');
    assert.equal(imapHost.required, false);
    flag.checked = true; providerHandlers['flag:change']();
    assert.equal(imapHost.required, true, 'IMAP host required while the flag is on');
    console.log('PASS: round trip only with IMAP, action=roundtrip, busy guard, literal results, success/warning states, locked conversational flag across provider switches and IMAP host requirement; no browser/network/database');
}
main().catch(error => { console.error(error.message); process.exitCode = 1; });
