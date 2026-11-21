const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const blade = fs.readFileSync('resources/views/sales/pos.blade.php', 'utf8');

function model(search = '', fetch = async () => { throw new Error('Unexpected request'); }) {
    const start = blade.indexOf('function posSystem()');
    const source = blade.slice(start, blade.indexOf('</script>', start));
    const events = [];
    const window = { posServicios: [], location: { search, pathname: '/pos' },
        history: { replaceState() {} }, dispatchEvent: e => events.push(e.detail) };
    const factory = vm.runInNewContext(source + '; posSystem;', { window, URLSearchParams, fetch,
        CustomEvent: class { constructor(type, init) { this.detail = init.detail; } },
        document: { querySelector: () => ({ getAttribute: () => 'fixture-csrf' }) },
        console, setTimeout() {} });
    return { state: factory(), events };
}

test('POS keeps product, service, variants, whole quantities and real zero in its payload', async () => {
    const { state, events } = model('', async (url, options) => {
        assert.equal(url, '/sales');
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.headers['X-CSRF-TOKEN'], 'fixture-csrf');
        const data = JSON.parse(options.body);
        assert.equal(data.cart[0].type, 'producto');
        assert.equal(data.cart[0].quantity, 2);
        assert.equal(data.cart[1].type, 'servicio');
        assert.equal(data.cart[1].variant_id, 30);
        assert.equal(data.cart[1].consumo[0].cantidad, '0');
        assert.equal(data.discount, 10);
        assert.equal(data.payments[0].monto, 200);
        assert.equal('subtotal' in data, false);
        assert.equal('total' in data, false);
        return { ok: false, json: async () => ({ error: 'El precio no coincide con el catálogo vigente.' }) };
    });
    state.addItem({ id: 10, type: 'producto', precio_c: 10.01, producto: 'Producto' });
    state.addItem({ id: 10, type: 'producto', precio_c: 10.01, producto: 'Producto' });
    state.addItem({ id: 20, type: 'servicio', precio_c: 150.01, variant_id: 30,
        producto: 'Servicio', consumo: [{ item_id: 12, cantidad: '0' }] });
    state.discount = 10;
    state.payments = [{ metodo: 'efectivo', moneda: 'nio', monto: 200, tasa: 1 }];
    assert.equal(state.calculateSubtotal(), '170.03');
    assert.equal(state.calculateTotal(), '160.03');
    await state.checkout();
    assert.equal(state.cart.length, 2);
    assert.match(events.at(-1), /precio no coincide/);
});

test('appointment with three stylists keeps every cent and the existing line contract', async () => {
    const { state } = model('?appointment_id=60&client_id=50&service_id=20&service_name=Servicio&price=100&stylist_ids=70,71,72');
    await state.init();
    assert.equal(state.cart.length, 3);
    assert.deepEqual(JSON.parse(JSON.stringify(state.cart.map(row => row.precio_c))), [33.34, 33.33, 33.33]);
    assert.equal(state.calculateTotal(), '100.00');
    for (const row of state.cart) {
        assert.equal(row.appointment_id, 60);
        assert.equal(row.quantity, 1);
        assert.equal(row.type, 'servicio');
    }
});

test('credit collection keeps the historical document and sends only payments', async () => {
    const { state } = model('', async (url, options) => {
        assert.equal(url, '/ventas/99/cobrar');
        const data = JSON.parse(options.body);
        assert.deepEqual(Object.keys(data).sort(), ['change_currency', 'payments']);
        return { ok: false, json: async () => ({ error: 'Fixture' }) };
    });
    state.cobroId = 99;
    state.cart = [{ id: 10, type: 'producto', precio_c: 7.01, quantity: 1 }];
    state.payments = [{ metodo: 'efectivo', moneda: 'nio', monto: 7.01, tasa: 1 }];
    await state.checkout();
    assert.equal(state.cart[0].precio_c, 7.01);
});
