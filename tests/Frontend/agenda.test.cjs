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

const editable = (extra = {}) => ({
    appointment: { id: 7, client_id: 1, service_id: 2, stylists: [3, 4], appointment_date: '2026-11-13 23:50:15', notes: 'Original', status: 'pendiente', duration_minutes: 45 },
    edit_token: 'a'.repeat(64), can_edit_commercial: true,
    choices: { clients: [{ id: 1, label: 'Cliente' }], services: [{ id: 2, label: 'Servicio anterior' }], stylists: [{ id: 3, label: 'Estilista anterior' }] },
    ...extra,
});

test('editar precarga todos los datos, conserva segundos y opciones históricas', async () => {
    const model = component(async (url, options) => {
        assert.equal(url, '/api/appointments/7');
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.headers.Accept, 'application/json');
        return response(200, editable());
    });
    await model.editAppointment(7);
    assert.equal(model.editingId, 7);
    assert.equal(model.editToken, 'a'.repeat(64));
    assert.equal(model.form.appointment_date, '2026-11-13T23:50:15');
    assert.equal(model.form.notes, 'Original');
    assert.equal(model.form.stylists.join(','), '3,4');
    assert.equal(model.editChoices.services[0].label, 'Servicio anterior');
    assert.equal(model.openModal, true);
    assert.equal(model.saving, false);
});

test('guardar edición usa PUT de datos, CSRF y versión, sin estado ni duración', async () => {
    let calls = 0;
    const model = component(async (url, options) => {
        calls++;
        if (calls === 1) return response(200, editable());
        assert.equal(url, '/api/appointments/7/details');
        assert.equal(options.method, 'PUT');
        assert.equal(options.headers['X-CSRF-TOKEN'], 'fixture-csrf');
        assert.equal(options.credentials, 'same-origin');
        const body = JSON.parse(options.body);
        assert.equal(body.edit_token, 'a'.repeat(64));
        assert.equal(body.appointment_date, '2026-11-15T00:05:15');
        assert.equal(Object.hasOwn(body, 'status'), false);
        assert.equal(Object.hasOwn(body, 'duration_minutes'), false);
        return response(200, { appointment: { id: 7, appointment_date: '2026-11-15 00:05:15' } });
    });
    await model.editAppointment(7);
    model.form.appointment_date = '2026-11-15T00:05:15';
    await model.saveAppointment();
    assert.equal(calls, 2);
    assert.match(model.creationMessage(), /#7 actualizada.*15\/11\/2026.*00:05/);
    assert.equal(new URL(model.createdAppointment.url).searchParams.get('fecha'), '2026-11-15');
    assert.equal(new URL(model.createdAppointment.url).searchParams.get('filtro'), 'equipo');
    assert.equal(model.editingId, null);
    assert.equal(model.editToken, '');
    assert.equal(model.form.notes, '');
    assert.equal(model.openModal, false);
});

test('modo protegido envía únicamente notas y huella de versión', async () => {
    let calls = 0;
    const model = component(async (url, options) => {
        if (++calls === 1) return response(200, editable({ can_edit_commercial: false }));
        const body = JSON.parse(options.body);
        assert.equal(Object.keys(body).sort().join(','), 'edit_token,notes');
        assert.equal(body.notes, 'Solo notas');
        return response(200, { appointment: { id: 7, appointment_date: '2026-11-13 23:50:15' } });
    });
    await model.editAppointment(7);
    assert.equal(model.canEditCommercial, false);
    model.form.notes = 'Solo notas';
    model.form.service_id = 999; // Incluso una modificación local no altera el payload protegido.
    await model.saveAppointment();
    assert.equal(model.createdAppointment.updated, true);
});

for (const [status, data, message] of [
    [409, { trace: 'secreto' }, /cambió.*Vuelve a abrir Editar/],
    [422, { errors: { availability: ['SQL secreto'] } }, /otra cita/],
    [422, { errors: { editing: ['SQL secreto'] } }, /solo permite editar notas/],
]) {
    test(`edición rechazada ${status} conserva formulario sin falso éxito`, async () => {
        let calls = 0;
        const model = component(async () => ++calls === 1 ? response(200, editable()) : response(status, data));
        await model.editAppointment(7);
        model.form.notes = 'Conservar';
        await model.saveAppointment();
        assert.match(model.error, message);
        assert.doesNotMatch(model.error, /SQL secreto|trace|secreto/);
        assert.equal(model.form.notes, 'Conservar');
        assert.equal(model.editingId, 7);
        assert.equal(model.openModal, true);
        assert.equal(model.createdAppointment, null);
        assert.equal(calls, 2);
    });
}

for (const status of [401, 403, 419, 500]) {
    test(`error ${status} de precarga no abre un formulario incompleto`, async () => {
        const model = component(async () => response(status, { message: 'secreto', trace: 'secreto' }));
        await model.editAppointment(7);
        assert.equal(model.openModal, false);
        assert.equal(model.editingId, null);
        assert.equal(model.saving, false);
        assert.notEqual(model.error, '');
        assert.doesNotMatch(model.error, /secreto/);
    });
}

test('precarga incompleta o con ID distinto no inventa datos', async () => {
    const model = component(async () => response(200, editable({ appointment: { id: 8 } })));
    await model.editAppointment(7);
    assert.equal(model.openModal, false);
    assert.equal(model.editingId, null);
    assert.match(model.error, /versión legible/);
});

test('una carga pendiente bloquea nuevas aperturas y solicitudes duplicadas', async () => {
    let finish, calls = 0;
    const model = component(() => { calls++; return new Promise(resolve => { finish = resolve; }); });
    const first = model.editAppointment(7);
    assert.equal(model.saving, true);
    model.newAppointment();
    await model.editAppointment(8);
    await model.saveAppointment();
    assert.equal(calls, 1);
    finish(response(200, editable()));
    await first;
    assert.equal(model.editingId, 7);
    model.newAppointment();
    assert.equal(model.editingId, null);
    assert.equal(model.editToken, '');
    assert.equal(model.form.notes, '');
});
