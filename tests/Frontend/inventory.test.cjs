const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const blade = fs.readFileSync('resources/views/inventory/index.blade.php', 'utf8');
function manager(fetch = async () => ({ ok: true })) {
    const messages = [];
    const context = { fetch, alert: message => messages.push(message), console,
        window: { dispatchEvent() {}, location: { reload() {} } }, CustomEvent: function () {}, setTimeout() {} };
    vm.createContext(context);
    vm.runInContext(blade.match(/<script>([\s\S]*?)<\/script>/)[1], context);
    return { state: context.inventoryManager(), messages };
}

test('creation contains no physical quantities and capacity does not fill open volume', () => {
    const { state } = manager();
    state.openCreateModal();
    state.form.total_volume = 1000;
    assert.equal('existencia_actual' in state.form, false);
    assert.equal('current_volume' in state.form, false);
    assert.equal(state.physical.current_volume, 0);
    assert.doesNotMatch(blade, /form\.current_volume\s*=/);
    assert.doesNotMatch(blade, /x-model[^=]*="form\.(existencia_actual|current_volume)"/);
});

test('editing displays physical quantities but sends only catalog fields', async () => {
    let sent;
    const { state } = manager(async (url, options) => { sent = { url, ...options }; return { ok: true }; });
    state.editProduct({ id: 5, producto: 'Champú', type: 'producto_interno', existencia_actual: 4,
        current_volume: '200.00', total_volume: '1000.00', unit_measure: 'ml', stock_min: 0, is_fractionable: 1 });
    assert.equal(state.physical.existencia_actual, 4);
    assert.equal(state.physical.current_volume, '200.00');
    assert.equal(state.form.stock_min, 0);
    await state.saveProduct();
    assert.equal(sent.method, 'PUT');
    assert.equal(sent.url, '/inventario/5');
    assert.equal('existencia_actual' in JSON.parse(sent.body), false);
    assert.equal('current_volume' in JSON.parse(sent.body), false);
    assert.ok(sent.headers['X-CSRF-TOKEN']);
    state.openCreateModal();
    assert.equal(state.physical.existencia_actual, 0);
    assert.equal(state.physical.current_volume, 0);
});

test('validation errors are visible and retain the form', async () => {
    const { state, messages } = manager(async () => ({ ok: false, status: 422,
        json: async () => ({ errors: { total_volume: ['No se puede reinterpretar este artículo.'] } }) }));
    state.openCreateModal();
    state.form.producto = 'Champú';
    await state.saveProduct();
    assert.deepEqual(messages, ['No se puede reinterpretar este artículo.']);
    assert.equal(state.openModal, true);
});
