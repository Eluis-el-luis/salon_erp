@extends('layouts.app')

@section('content')
<!-- El contenedor principal define el "estado" de Alpine con x-data -->
<div x-data="appointmentManager()">
    
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h2 class="page-title">Agenda del Día</h2>
            <p class="page-subtitle">Citas programadas y atención del equipo.</p>
        </div>
        <button @click="openModal = true" class="btn btn-primary">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Nueva Cita
        </button>
    </div>

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
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        
                            @if($cita->status === 'pendiente')
                                <button @click="completeAppointment({{ $cita->id }})" class="text-emerald-600 hover:text-emerald-900 font-bold mr-3 transition duration-150">
                                    ✓ Completar
                                </button>
                            @endif

                            @if($cita->status !== 'cancelada' && $cita->status !== 'completada')
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
                            No hay citas agendadas para hoy.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- MODAL DE NUEVA CITA -->
    <div x-show="openModal" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            
            <div x-show="openModal" @click="openModal = false" class="fixed inset-0 transition-opacity" aria-hidden="true">
                <div class="absolute inset-0 bg-gray-900 opacity-75"></div>
            </div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <div x-show="openModal" class="relative z-20 inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">Agendar Nueva Cita</h3>
                    
                    <form>
                        <!-- Reemplaza tus inputs actuales por estos selects -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Cliente</label>
                        <select x-model.number="form.client_id" class="w-full border-gray-300 rounded-md shadow-sm focus:border-emerald-500 focus:ring-emerald-500" required>
                            <option value="">Seleccione un cliente...</option>
                            @foreach($clientes as $cliente)
                                <option value="{{ $cliente->id }}">{{ $cliente->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Servicio</label>
                            <select x-model.number="form.service_id" class="w-full border-gray-300 rounded-md shadow-sm focus:border-emerald-500 focus:ring-emerald-500" required>
                                <option value="">Seleccione el servicio...</option>
                                @foreach($servicios as $servicio)
                                    <option value="{{ $servicio->id }}">{{ $servicio->name }} (C$ {{ number_format($servicio->price, 2) }})</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Estilista(s) Asignado(s)</label>
                            <p class="text-xs text-gray-500 mb-2">Mantén presionada la tecla Ctrl (o Cmd en Mac) para seleccionar varios.</p>
                            <select multiple x-model="form.stylists" class="w-full h-24 border-gray-300 rounded-md shadow-sm focus:border-emerald-500 focus:ring-emerald-500 text-sm p-2" required>
                                @foreach($estilistas as $estilista)
                                    <option value="{{ $estilista->id }}">{{ $estilista->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Fecha y Hora</label>
                            <input type="datetime-local" x-model="form.appointment_date" class="w-full border-gray-300 rounded-md shadow-sm focus:border-emerald-500 focus:ring-emerald-500" required>
                        </div>
                    </form>
                </div>
                
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <!-- Conectamos el botón de guardar a nuestra función saveAppointment() -->
                    <button @click="saveAppointment()" type="button" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-emerald-600 text-base font-medium text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 sm:ml-3 sm:w-auto sm:text-sm">
                        Guardar Cita
                    </button>
                    <button @click="openModal = false" type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>



<script>
    function appointmentManager() {
        return {
            openModal: false,
            form: {
                client_id: '', service_id: '', stylists: [], appointment_date: '', duration_minutes: 45
            },
            
            async saveAppointment() {
                
                try {
                    let response = await fetch('/api/appointments', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify(this.form)
                    });
                    if (response.ok) {
                        window.dispatchEvent(new CustomEvent('notify', { detail: '¡Cita agendada correctamente!' }));
                        this.openModal = false;
                        setTimeout(() => { window.location.reload(); }, 1000);
                    } else {
                        window.dispatchEvent(new CustomEvent('notify', { detail: 'Error: Revisa los campos.' }));
                    }
                } catch (error) { console.error(error); }
            },

            // --- FLUXO DE COMPLETAR CITA ---
            completeAppointment(id) {
                
                window.dispatchEvent(new CustomEvent('open-confirm', { 
                    detail: { 
                        message: '¿Estás seguro de marcar esta cita como completada?',
                        action: () => this.processComplete(id) 
                    } 
                }));
            },

            async processComplete(id) {
                
                try {
                    let response = await fetch(`/api/appointments/${id}`, {
                        method: 'PUT',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ status: 'completada' })
                    });
                    if (response.ok) {
                        window.dispatchEvent(new CustomEvent('notify', { detail: '¡Cita marcada como completada!' }));
                        setTimeout(() => { window.location.reload(); }, 1000);
                    }
                } catch (error) { console.error(error); }
            },

            // --- FLUXO DE FACTURAR CITA ---
            billAppointment(id) {
                // 1. Disparamos el modal global
                window.dispatchEvent(new CustomEvent('open-confirm', { 
                    detail: { 
                        message: '¿Deseas generar la factura para esta cita y enviarla a caja?',
                        action: () => this.processBill(id) 
                    } 
                }));
            },

            async processBill(id) {
                
                try {
                    let response = await fetch(`/sales/bill-appointment/${id}`, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                        },
                        body: JSON.stringify({ discount: 0, payment_method: 'efectivo', currency: 'cordoba', exchange_rate: 36.80 })
                    });
                    if (response.ok) {
                        window.dispatchEvent(new CustomEvent('notify', { detail: '¡Factura generada y enviada a caja!' }));
                        setTimeout(() => { window.location.reload(); }, 1000);
                    }
                } catch (error) { console.error(error); }
            }
        }
    }
</script>
@endsection