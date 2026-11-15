const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const blade = fs.readFileSync('resources/views/sales/pos.blade.php', 'utf8');
function model() {
    const start = blade.indexOf('function posSystem()');
    const source = blade.slice(start, blade.indexOf('</script>', start));
    const events = [];
    const factory = vm.runInNewContext(source + '; posSystem;', {
        window: { posServicios: [], dispatchEvent: e => events.push(e.detail) },
        CustomEvent: class { constructor(type, init) { this.detail = init.detail; } },
        console, document: { querySelector() { return { content: 'fixture' }; } }
    });
    const state = factory();
    state.cart = [{ id: 1, type: 'servicio', consumo: null }];
    state.modalConsumo = { open: true, index: 0, filas: [
        { item_id: 10, producto: 'A', real: '0' }, { item_id: 11, producto: 'B', real: '20.01' }
    ] };
    return { state, events };
}
test('complete capture preserves explicit zero and exact decimals', () => {
    const { state, events } = model(); state.guardarConsumo();
    assert.equal(JSON.stringify(state.cart[0].consumo), '[{"item_id":10,"cantidad":"0"},{"item_id":11,"cantidad":"20.01"}]');
    assert.equal(state.modalConsumo.open, false); assert.equal(events.length, 0);
});
for (const bad of ['', null, undefined, 'x', '-1', '1.001', Infinity, '1e2']) {
    test(`invalid capture ${String(bad)} keeps input and rejects entire capture`, () => {
        const { state, events } = model(); state.modalConsumo.filas[1].real = bad; state.guardarConsumo();
        assert.equal(state.cart[0].consumo, null); assert.equal(state.modalConsumo.open, true);
        assert.equal(state.modalConsumo.filas[1].real, bad); assert.match(events[0], /B:/);
    });
}
