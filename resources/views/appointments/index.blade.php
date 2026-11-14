@extends('layouts.app')

@section('content')
<!-- El contenedor principal define el "estado" de Alpine con x-data -->
<div x-data="appointmentManager(@js($fechaSeleccionada), @js(['clients' => $clientes->modelKeys(), 'services' => $servicios->modelKeys(), 'stylists' => $estilistas->modelKeys()]))">
    
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h2 class="page-title">Agenda — {{ \Carbon\Carbon::parse($fechaSeleccionada)->format('d/m/Y') }}</h2>
            <p class="page-subtitle">Citas programadas y atención del equipo. Horario del salón.</p>
        </div>
        <button @click="newAppointment()" :disabled="saving" class="btn btn-primary disabled:opacity-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Nueva Cita
        </button>
    </div>

    <form method="GET" action="{{ url('/agenda') }}" class="flex flex-wrap items-end gap-3 mb-4">
        @foreach(request()->except('fecha', 'page') as $key => $value)
            @if(is_scalar($value))
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach
        <div>
            <label for="agenda-fecha" class="block text-sm font-medium text-gray-700">Día seleccionado</label>
            <input id="agenda-fecha" type="date" name="fecha" value="{{ $fechaSeleccionada }}" required class="border-gray-300 rounded-md">
        </div>
        <button type="submit" class="btn btn-primary">Consultar</button>
        <a href="{{ request()->fullUrlWithoutQuery(['fecha', 'page']) }}" class="btn">Hoy</a>
    </form>

    <div x-cloak x-show="createdAppointment" role="status" aria-live="polite" class="mb-4 rounded-lg bg-emerald-50 p-4 text-emerald-900">
        <p x-text="creationMessage()"></p>
        <a :href="createdAppointment?.url" class="font-bold underline">Ver el día de la cita</a>
    </div>
    <p x-cloak x-show="error" x-text="error" role="alert" class="mb-4 text-red-700"></p>

    <!-- Tabla de Citas -->
    <div class="card overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hora</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cliente</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Servicio</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estilista</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
            <!-- Iteramos sobre las citas reales -->
                @forelse ($citas as $cita)
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            <!-- Usamos Carbon para formatear la hora (ej: 14:00) -->
                            {{ \Carbon\Carbon::parse($cita->appointment_date)->format('H:i') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                            <!-- Accedemos a la relación del cliente -->
                            {{ $cita->cliente->name }} <br>
                            <span class="text-xs text-gray-500">{{ $cita->cliente->phone ?? 'Sin teléfono' }}</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 font-medium">
                            {{ $cita->servicio->name ?? 'Servicio no encontrado' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 font-medium">
                            @foreach($cita->estilistas as $estilista)
                                <span class="bg-gray-100 px-2 py-1 rounded text-xs block mb-1">{{ $estilista->name }}</span>
                            @endforeach
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <!-- Lógica visual de colores según el estado -->
                            @if($cita->status === 'pendiente')
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                    Pendiente
                                </span>
                            @elseif($cita->status === 'completada')
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                    Completada
                                </span>
                            @elseif($cita->status === 'cancelada')
                                <span class="text-sm text-gray-600">Cancelada</span>
                            @elseif($cita->status === 'no_asistio')
                                <span class="text-sm text-gray-600">No asistió</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <button type="button" :disabled="saving" @click="editAppointment({{ $cita->id }})" class="btn mr-2 disabled:opacity-50">Editar</button>
                            @if($cita->status === 'pendiente')
                                <button type="button" :disabled="saving" @click="completeAppointment({{ $cita->id }})" class="text-emerald-600 hover:text-emerald-900 font-bold mr-3 transition duration-150 disabled:opacity-50">
                                    ✓ Completar
                                </button>
                                <button type="button" :disabled="saving" @click="changeAppointmentStatus({{ $cita->id }}, 'cancelada')" class="btn mr-2 disabled:opacity-50">Cancelar</button>
                                <button type="button" :disabled="saving" @click="changeAppointmentStatus({{ $cita->id }}, 'no_asistio')" class="btn mr-2 disabled:opacity-50">No asistió</button>
                            @elseif(in_array($cita->status, ['cancelada', 'no_asistio']))
                                <button type="button" :disabled="saving" @click="changeAppointmentStatus({{ $cita->id }}, 'pendiente')" class="btn disabled:opacity-50">Reactivar</button>
                            @endif

                            @if($cita->status === 'pendiente')
                                @php
                                    // Preparamos los IDs para enviarlos al POS
                                    $stylistIds = $cita->estilistas->pluck('id')->join(',');
                                @endphp
                                <a href="{{ url('/pos') }}?appointment_id={{ $cita->id }}&service_id={{ $cita->service_id }}&service_name={{ urlencode($cita->servicio->name) }}&price={{ $cita->servicio->price }}&client_id={{ $cita->client_id }}&stylist_ids={{ $stylistIds }}" 
                                class="inline-block text-blue-600 hover:text-blue-900 font-bold transition duration-150">
                                    Facturar
                                </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <!-- Mensaje por defecto si la base de datos está vacía para el día de hoy -->
                    <tr>
                        <td colspan="6" class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500">
                            No hay citas agendadas para el {{ \Carbon\Carbon::parse($fechaSeleccionada)->format('d/m/Y') }}.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Creación y edición comparten formulario; estado se modifica por separado. -->
    <div x-show="openModal" @keydown.escape.window="if (!saving) openModal = false" role="dialog" aria-modal="true" aria-labelledby="appointment-modal-title" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            
            <div x-show="openModal" @click="if (!saving) openModal = false" class="fixed inset-0 transition-opacity" aria-hidden="true">
                <div class="absolute inset-0 bg-gray-900 opacity-75"></div>
            </div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <div x-show="openModal" class="relative z-20 inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <h3 id="appointment-modal-title" x-text="editingId ? (canEditCommercial ? 'Editar cita #' : 'Editar notas de cita #') + editingId : 'Agendar Nueva Cita'" class="text-lg leading-6 font-medium text-gray-900 mb-4"></h3>
                    <p x-show="editingId && !canEditCommercial" class="mb-3 text-sm text-gray-600">Por su estado o vínculo con una venta, esta cita solo permite editar notas. La reactivación se realiza por separado.</p>
                    <p x-show="error" x-text="error" role="alert" class="mb-3 text-red-700"></p>
                    
                    <form id="appointment-form" @submit.prevent="saveAppointment()">
                        <!-- Reemplaza tus inputs actuales por estos selects -->
                    <div class="mb-4">
                        <label for="appointment-client" class="block text-sm font-medium text-gray-700 mb-2">Cliente</label>
                        <select id="appointment-client" x-ref="client" :disabled="saving || (editingId && !canEditCommercial)" x-model.number="form.client_id" class="w-full border-gray-300 rounded-md shadow-sm focus:border-emerald-500 focus:ring-emerald-500" required>
                            <option value="">Seleccione un cliente...</option>
                            @foreach($clientes as $cliente)
                                <option value="{{ $cliente->id }}">{{ $cliente->name }}</option>
                            @endforeach
                            <template x-for="choice in editChoices.clients" :key="choice.id"><option :value="choice.id" x-text="choice.label"></option></template>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label for="appointment-service" class="block text-sm font-medium text-gray-700 mb-2">Servicio</label>
                            <select id="appointment-service" :disabled="saving || (editingId && !canEditCommercial)" x-model.number="form.service_id" class="w-full border-gray-300 rounded-md shadow-sm focus:border-emerald-500 focus:ring-emerald-500" required>
                                <option value="">Seleccione el servicio...</option>
                                @foreach($servicios as $servicio)
                                    <option value="{{ $servicio->id }}">{{ $servicio->name }} (C$ {{ number_format($servicio->price, 2) }})</option>
                                @endforeach
                                <template x-for="choice in editChoices.services" :key="choice.id"><option :value="choice.id" x-text="choice.label"></option></template>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label for="appointment-stylists" class="block text-sm font-medium text-gray-700 mb-1">Estilista(s) Asignado(s)</label>
                            <p class="text-xs text-gray-500 mb-2">Mantén presionada la tecla Ctrl (o Cmd en Mac) para seleccionar varios.</p>
                            <select id="appointment-stylists" :disabled="saving || (editingId && !canEditCommercial)" multiple x-model="form.stylists" class="w-full h-24 border-gray-300 rounded-md shadow-sm focus:border-emerald-500 focus:ring-emerald-500 text-sm p-2" required>
                                @foreach($estilistas as $estilista)
                                    <option value="{{ $estilista->id }}">{{ $estilista->name }}</option>
                                @endforeach
                                <template x-for="choice in editChoices.stylists" :key="choice.id"><option :value="choice.id" x-text="choice.label"></option></template>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label for="appointment-date" class="block text-sm font-medium text-gray-700 mb-2">Fecha y Hora</label>
                            <input id="appointment-date" :disabled="saving || (editingId && !canEditCommercial)" type="datetime-local" step="1" x-model="form.appointment_date" class="w-full border-gray-300 rounded-md shadow-sm focus:border-emerald-500 focus:ring-emerald-500" required>
                        </div>
                        <div class="mb-4">
                            <label for="appointment-notes" class="block text-sm font-medium text-gray-700 mb-2">Notas</label>
                            <textarea id="appointment-notes" x-ref="notes" :disabled="saving" x-model="form.notes" class="w-full border-gray-300 rounded-md shadow-sm focus:border-emerald-500 focus:ring-emerald-500" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <!-- Conectamos el botón de guardar a nuestra función saveAppointment() -->
                    <button form="appointment-form" :disabled="saving" type="submit" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-emerald-600 text-base font-medium text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 sm:ml-3 sm:w-auto sm:text-sm disabled:opacity-50">
                        <span x-text="saving ? 'Guardando…' : (editingId ? 'Guardar cambios' : 'Crear cita')"></span>
                    </button>
                    <button @click="openModal = false" :disabled="saving" type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm disabled:opacity-50">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>



<script>
    function appointmentManager(selectedDate, catalogIds = { clients: [], services: [], stylists: [] }) {
        return {
            openModal: false,
            saving: false,
            error: '',
            createdAppointment: null,
            editingId: null,
            editToken: '',
            canEditCommercial: true,
            editChoices: { clients: [], services: [], stylists: [] },
            form: {
                client_id: '', service_id: '', stylists: [], appointment_date: '', notes: ''
            },
            
            resetForm() {
                this.form = { client_id: '', service_id: '', stylists: [], appointment_date: '', notes: '' };
                this.editingId = null;
                this.editToken = '';
                this.canEditCommercial = true;
                this.editChoices = { clients: [], services: [], stylists: [] };
            },

            newAppointment() {
                if (this.saving) return;
                this.resetForm();
                this.error = '';
                this.openModal = true;
                this.focusEditor();
            },

            focusEditor() {
                this.$nextTick?.(() => this.$refs?.[this.canEditCommercial ? 'client' : 'notes']?.focus());
            },

            async editAppointment(id) {
                if (this.saving) return;
                this.saving = true;
                this.error = '';
                this.openModal = false;
                try {
                    const response = await fetch(`/api/appointments/${id}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                    const data = await response.json().catch(() => null);
                    if (!response.ok) { this.error = this.responseError(response.status, data); return; }
                    const a = data?.appointment;
                    if (a?.id !== id || !Array.isArray(a.stylists) || !/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/.test(a.appointment_date || '') || !/^[a-f0-9]{64}$/.test(data?.edit_token || '')) {
                        this.error = 'No se pudo cargar una versión legible de la cita. Vuelve a abrir Editar.';
                        return;
                    }
                    this.resetForm();
                    this.editingId = id;
                    this.editToken = data.edit_token;
                    this.canEditCommercial = data.can_edit_commercial === true;
                    const loadedForm = { client_id: Number(a.client_id), service_id: Number(a.service_id), stylists: a.stylists.map(String), appointment_date: a.appointment_date.replace(' ', 'T'), notes: a.notes ?? '' };
                    for (const type of ['clients', 'services', 'stylists']) {
                        const known = (catalogIds[type] || []).map(String);
                        this.editChoices[type] = (data.choices?.[type] || []).filter(choice => !known.includes(String(choice.id)));
                    }
                    this.openModal = true;
                    // Renderizar primero opciones históricas que ya no están en el catálogo activo.
                    if (typeof this.$nextTick === 'function') await this.$nextTick();
                    this.form = loadedForm;
                    this.focusEditor();
                } catch {
                    this.error = 'Fallo de red: no se pudo cargar la cita. Vuelve a abrir Editar.';
                } finally { this.saving = false; }
            },

            creationMessage() {
                if (!this.createdAppointment) return '';
                const { id, date, time, updated } = this.createdAppointment;
                const [year, month, day] = date.split('-');
                return `Cita #${id} ${updated ? 'actualizada' : 'guardada'} para el ${day}/${month}/${year} a las ${time}. Puedes consultar su día aunque no sea el seleccionado (${selectedDate}).`;
            },

            responseError(status, data) {
                if (status === 422) {
                    if (data?.errors?.availability) return 'Uno de los estilistas ya tiene otra cita en ese horario. Elige otro horario o estilista.';
                    if (data?.errors?.financial_link) return 'La cita tiene una venta vigente o pendiente de aprobación. No puede liberar su horario desde agenda.';
                    if (data?.errors?.editing) return 'Esta cita solo permite editar notas. Vuelve a abrir Editar para consultar su estado actual.';
                    const labels = { client_id: 'cliente', service_id: 'servicio', stylists: 'estilistas', appointment_date: 'fecha y hora', duration_minutes: 'duración', notes: 'notas', status: 'estado', fecha: 'día seleccionado' };
                    const fields = [...new Set(Object.keys(data?.errors || {}).map(key => labels[key.split('.')[0]]).filter(Boolean))];
                    return 'Hay errores de validación.' + (fields.length ? ` Revisa: ${fields.join(', ')}.` : ' Revisa los datos de la cita.');
                }
                return ({
                    401: 'No has iniciado sesión. Inicia sesión antes de continuar.',
                    403: 'Acceso denegado. No tienes permiso para esta operación.',
                    419: 'La sesión o el token de seguridad expiró. Recarga la página antes de continuar.',
                    409: 'La cita cambió desde que abriste el formulario. Vuelve a abrir Editar para cargar la versión actual.',
                    500: 'El servidor no pudo completar la operación. Comprueba la agenda antes de reintentar.'
                })[status] || 'No se pudo confirmar la operación. Comprueba la agenda antes de reintentar.';
            },

            async saveAppointment() {
                if (this.saving) return;
                this.saving = true;
                this.error = '';
                try {
                    const updated = this.editingId !== null;
                    const body = updated ? { ...(this.canEditCommercial ? this.form : { notes: this.form.notes }), edit_token: this.editToken } : this.form;
                    let response = await fetch(updated ? `/api/appointments/${this.editingId}/details` : '/api/appointments', {
                        method: updated ? 'PUT' : 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json', 'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify(body)
                    });
                    const data = await response.json().catch(() => null);
                    if (response.ok) {
                        const appointment = data?.appointment;
                        // DATETIME local guardado: extraer componentes, sin convertir a UTC.
                        const saved = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(?::\d{2})?$/.exec(appointment?.appointment_date || '');
                        if (!Number.isInteger(appointment?.id) || !saved || (updated && appointment.id !== this.editingId)) {
                            this.error = 'El servidor respondió sin una confirmación legible. Comprueba la agenda antes de reintentar.';
                            return;
                        }
                        const url = new URL(window.location.href);
                        url.pathname = '/agenda';
                        url.searchParams.set('fecha', saved[1]);
                        url.searchParams.delete('page');
                        url.hash = '';
                        this.createdAppointment = { id: appointment.id, date: saved[1], time: saved[2], url: url.href, updated };
                        this.openModal = false;
                        this.resetForm();
                    } else {
                        this.error = this.responseError(response.status, data);
                    }
                } catch {
                    this.error = 'Fallo de red: no se pudo confirmar la operación. Comprueba la agenda antes de reintentar.';
                } finally {
                    this.saving = false;
                }
            },

            // --- FLUXO DE COMPLETAR CITA ---
            completeAppointment(id) {
                this.changeAppointmentStatus(id, 'completada');
            },

            changeAppointmentStatus(id, status) {
                if (this.saving) return;
                const messages = {
                    completada: '¿Marcar esta cita como completada?',
                    cancelada: '¿Cancelar esta cita y liberar su horario?',
                    no_asistio: '¿Registrar que no asistió y liberar su horario?',
                    pendiente: '¿Reactivar esta cita? Se comprobará otra vez la disponibilidad.'
                };
                if (!messages[status]) return;
                window.dispatchEvent(new CustomEvent('open-confirm', { 
                    detail: { 
                        message: messages[status],
                        action: () => this.processComplete(id, status)
                    } 
                }));
            },

            async processComplete(id, status = 'completada') {
                if (this.saving) return;
                this.saving = true;
                this.error = '';
                try {
                    let response = await fetch(`/api/appointments/${id}`, {
                        method: 'PUT',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json', 'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify({ status })
                    });
                    if (response.ok) {
                        window.dispatchEvent(new CustomEvent('notify', { detail: 'Estado de la cita actualizado.' }));
                        setTimeout(() => { window.location.reload(); }, 1000);
                    } else {
                        this.error = this.responseError(response.status, await response.json().catch(() => null));
                    }
                } catch {
                    this.error = 'Fallo de red: no se pudo confirmar la operación. Comprueba la agenda antes de reintentar.';
                } finally {
                    this.saving = false;
                }
            },

        }
    }
</script>
@endsection
