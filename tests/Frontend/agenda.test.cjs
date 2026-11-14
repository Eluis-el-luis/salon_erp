// H14-A: ejecuta el componente real con dobles HTTP/DOM; sin Laravel ni SQL.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const blade = fs.readFileSync(path.join(__dirname, '../../resources/views/appointments/index.blade.php'), 'utf8');
const script = blade.match(/<script>([\s\S]*?)<\/script>/)[1];
function component(fetch) {
    const events = [];
    const window = { dispatchEvent: event => events.push(event), location: { href: 'http://salon.test/agenda?fecha=2026-11-12&filtro=equipo', reload() {} } };
    const document = { querySelector: () => ({ content: 'fixture-csrf' }) };
    const factory = vm.runInNewContext(script + '; appointmentManager;', { window, document, fetch, URL,
        CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init.detail; } }, setTimeout() {} });
    const model = factory('2026-11-12');
    model.newAppointment();
    model.form = { client_id: 1, service_id: 2, stylists: [3], appointment_date: '2026-11-13T23:50', duration_minutes: 45 };
    model.testEvents = events;
    return model;
}
const response = (status, data) => ({ ok: status >= 200 && status < 300, status, json: async () => data });

test('confirma datos guardados, conserva fecha local y ofrece navegación sin recarga', async () => {
    const model = component(async (url, options) => {
        assert.equal(url, '/api/appointments');
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.headers['X-CSRF-TOKEN'], 'fixture-csrf');
        return response(201, { appointment: { id: 56, appointment_date: '2026-11-14 00:05:00' } });
    });
    await model.saveAppointment();
    assert.match(model.creationMessage(), /#56.*14\/11\/2026.*00:05/);
    const link = new URL(model.createdAppointment.url);
    assert.equal(link.searchParams.get('fecha'), '2026-11-14');
    assert.equal(link.searchParams.get('filtro'), 'equipo');
    assert.equal(model.openModal, false);
    assert.equal(model.form.appointment_date, '');
    assert.equal(model.form.stylists.length, 0);
    assert.equal(model.saving, false);
    model.form.client_id = 99;
    model.newAppointment();
    assert.equal(model.form.client_id, '');
    assert.equal(model.openModal, true);
});

for (const [status, message] of [[422, /validación.*servicio/], [401, /iniciado sesión/], [403, /Acceso denegado/], [419, /expiró/], [500, /servidor/]]) {
    test(`muestra error seguro y diferenciado ${status}`, async () => {
        const model = component(async () => response(status, { errors: { service_id: ['SQL secreto'] }, trace: 'TRAZA SECRETA', message: 'TRAZA SECRETA' }));
        await model.saveAppointment();
        assert.match(model.error, message);
        assert.doesNotMatch(model.error, /SQL secreto|TRAZA SECRETA/);
        assert.equal(model.createdAppointment, null);
        assert.equal(model.openModal, true);
        assert.equal(model.form.client_id, 1);
        assert.equal(model.saving, false);
    });
}

test('red incierta no afirma que la cita no se guardó ni reintenta', async () => {
    let calls = 0;
    const model = component(async () => { calls++; throw new Error('secreto'); });
    await model.saveAppointment();
    assert.match(model.error, /Fallo de red.*Comprueba la agenda/);
    assert.doesNotMatch(model.error, /secreto/);
    assert.equal(calls, 1);
    assert.equal(model.form.client_id, 1);
    assert.equal(model.saving, false);
});

test('respuesta ilegible no inventa ID ni fecha ni repite solicitud', async () => {
    const model = component(async () => ({ ok: true, status: 200, json: async () => { throw new Error('HTML'); } }));
    await model.saveAppointment();
    assert.equal(model.createdAppointment, null);
    assert.match(model.error, /sin una confirmación legible/);
});

test('doble clic durante la petición no duplica el alta', async () => {
    let finish;
    let calls = 0;
    const model = component(() => { calls++; return new Promise(resolve => { finish = resolve; }); });
    const first = model.saveAppointment();
    await model.saveAppointment();
    assert.equal(calls, 1);
    finish(response(201, { appointment: { id: 57, appointment_date: '2026-11-13 12:00:00' } }));
    await first;
    assert.equal(model.saving, false);
});

test('explica conflicto sin mostrar mensajes SQL del servidor', async () => {
    const model = component(async () => response(422, { errors: { availability: ['SQL secreto'] } }));
    await model.saveAppointment();
    assert.match(model.error, /estilistas.*otra cita.*otro horario/);
    assert.doesNotMatch(model.error, /SQL secreto/);
});

test('explica bloqueo financiero y no propone anular documentos automáticamente', async () => {
    const model = component(async () => response(422, { errors: { financial_link: ['TRAZA'] } }));
    await model.saveAppointment();
    assert.match(model.error, /venta vigente.*No puede liberar/);
    assert.doesNotMatch(model.error, /TRAZA/);
});

test('nuevo formulario no fija una duración arbitraria', () => {
    const model = component(async () => response(201, {}));
    model.newAppointment();
    assert.equal(Object.hasOwn(model.form, 'duration_minutes'), false);
});

test('reactivación requiere confirmación y conserva CSRF y sesión', async () => {
    const model = component(async (url, options) => {
        assert.equal(url, '/api/appointments/7');
        assert.equal(options.method, 'PUT');
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.headers['X-CSRF-TOKEN'], 'fixture-csrf');
        assert.equal(JSON.parse(options.body).status, 'pendiente');
        return response(200, {});
    });
    model.changeAppointmentStatus(7, 'pendiente');
    assert.match(model.testEvents[0].detail.message, /Reactivar.*disponibilidad/);
    await model.testEvents[0].detail.action();
    assert.equal(model.testEvents[1].type, 'notify');
    assert.equal(model.saving, false);
});

test('reactivación con conflicto muestra el rechazo y no confirma éxito', async () => {
    const model = component(async () => response(422, { errors: { availability: ['conflict'] } }));
    await model.processComplete(7, 'pendiente');
    assert.match(model.error, /otra cita/);
    assert.equal(model.testEvents.length, 0);
    assert.equal(model.saving, false);
});
