const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const blade = fs.readFileSync('resources/views/sales/pos.blade.php', 'utf8');
function model(enabled, search = '', fetch = async () => { throw new Error('Unexpected request'); }) {
    const start = blade.indexOf('function posSystem()');
    const source = blade.slice(start, blade.indexOf('</script>', start));
    let counter = 0; const events = [];
    const window = { posServicios: [], posSharedContract: enabled, posSharedParticipants: [],
        crypto: { randomUUID: () => `00000000-0000-4000-8000-${String(++counter).padStart(12, '0')}` },
        location: { search, pathname: '/pos' }, history: { replaceState() {} }, dispatchEvent: e => events.push(e.detail) };
    const factory = vm.runInNewContext(source + '; posSystem;', { window, URLSearchParams, fetch,
        document: { querySelector: () => ({ getAttribute: () => 'synthetic-csrf' }) },
        CustomEvent: class { constructor(type, init) { this.detail = init.detail; } }, console, setTimeout() {} });
    return { state: factory(), events };
}
test('closed feature does not add a new contract or creation key', () => {
    const { state } = model(false); assert.deepEqual(Object.keys(state.contractPayload()), []); assert.equal(state.creationKey, null);
});
for (const n of [1, 2, 3]) test(`shared appointment with ${n} stylists is a single full-price execution`, async () => {
    const ids = Array.from({ length: n }, (_, i) => i + 1);
    const { state } = model(true, `?appointment_id=7&client_id=50&service_id=20&service_name=Synthetic&price=1000&stylist_ids=${ids.join(',')}`);
    await state.init(); assert.equal(state.cart.length, 1); assert.equal(state.cart[0].quantity, 1);
    assert.equal(state.cart[0].precio_c, 1000); assert.deepEqual(Array.from(state.cart[0].participants), ids);
    assert.equal(state.cart[0].stylist_id == null, true); assert.equal(state.calculateTotal(), '1000.00');
});
test('distinct services are not merged and preserve creation key on retry', () => {
    const { state } = model(true); const service = { id: 20, type: 'servicio', precio_c: 1000, producto: 'Synthetic' };
    state.addItem(service); state.addItem(service); assert.equal(state.cart.length, 2);
    assert.notEqual(state.cart[0].performance_key, state.cart[1].performance_key);
    const key = state.contractPayload().creation_key; assert.equal(state.contractPayload().creation_key, key);
    state.limpiarCaja(); assert.notEqual(state.contractPayload().creation_key, key);
});
test('saved proposal cannot silently remove its canonical line', () => {
    const { state, events } = model(true); state.addItem({ id: 20, type: 'servicio', precio_c: 1000 });
    state.draftId = 1; state.removeItem(0); assert.equal(state.cart.length, 1); assert.match(events.at(-1), /anula/i);
});
test('reload and emission keep canonical keys, revision and one complete zero capture', async () => {
    let body;
    const doc = { id: 1, client_id: 50, service_contract_version: 1, creation_key: 'creation-fixture', document_revision: 3, items: [{
        id: 20, type: 'servicio', quantity: 1, precio_c: 1000, performance_key: 'performance-fixture', participants: [1, 2, 3],
        consumo: [{ item_id: 30, cantidad: 0 }], participants_display: ['A','B','C'] }] };
    const { state } = model(true, '', async (url, options) => {
        if (url.endsWith('/documento')) return { ok: true, json: async () => doc };
        assert.equal(url, '/sales'); body = JSON.parse(options.body); return { ok: false, json: async () => ({ error: 'Synthetic rejection retains proposal' }) };
    });
    await state.cargarDocumento(1, false); state.payments = [{ metodo: 'efectivo', moneda: 'nio', monto: 1000, tasa: 1 }];
    await state.checkout(); assert.equal(body.document_revision, 3); assert.equal(body.creation_key, doc.creation_key);
    assert.equal(body.cart.length, 1); assert.equal(body.cart[0].performance_key, 'performance-fixture');
    assert.equal(body.cart[0].consumo[0].cantidad, 0); assert.deepEqual(body.cart[0].participants, [1,2,3]);
});
