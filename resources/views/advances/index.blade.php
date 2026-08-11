@extends('layouts.app')

@section('content')
<div x-data="cxcManager()" class="space-y-6">
    
    <!-- Encabezado -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-800">Cuentas por Cobrar (CXC)</h2>
            <p class="text-sm text-gray-500">Libro mayor de adelantos a colaboradores y créditos a clientes.</p>
        </div>
        <button @click="openCreateModal()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-4 rounded shadow transition">
            + Nuevo Movimiento
        </button>
    </div>

    <!-- Tabla Libro Mayor -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Fecha</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Entidad</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Detalle / Motivo</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Debe (Cargo)</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Haber (Abono)</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Acción</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($advances as $mov)
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            {{ \Carbon\Carbon::parse($mov->date)->format('d/m/Y') }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @if($mov->user_id)
                                <div class="text-sm font-bold text-blue-700">{{ $mov->user->name }}</div>
                                <div class="text-[10px] uppercase font-bold text-blue-400 bg-blue-50 inline-block px-1 rounded">Colaborador</div>
                            @elseif($mov->client_id)
                                <div class="text-sm font-bold text-purple-700">{{ $mov->client->name }}</div>
                                <div class="text-[10px] uppercase font-bold text-purple-400 bg-purple-50 inline-block px-1 rounded">Cliente</div>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-700">
                            {{ $mov->description }}
                        </td>
                        <!-- Columna DEBE -->
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-red-500 bg-red-50/30">
                            @if($mov->type == 'debe')
                                C$ {{ number_format($mov->amount, 2) }}
                            @endif
                        </td>
                        <!-- Columna HABER -->
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold text-emerald-500 bg-emerald-50/30">
                            @if($mov->type == 'haber')
                                C$ {{ number_format($mov->amount, 2) }}
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <button @click="deleteMov({{ $mov->id }})" class="text-red-400 hover:text-red-700 transition">
                                <svg class="w-5 h-5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-gray-500 text-sm">
                            No hay movimientos de cuentas por cobrar registrados.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- MODAL DE CXC -->
    <div x-show="openModal" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
        <div class="flex items-center justify-center min-h-screen px-4 text-center">
            <div x-show="openModal" @click="openModal = false" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
            
            <div x-show="openModal" class="relative bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:max-w-lg sm:w-full z-10">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <h3 class="text-lg font-bold text-gray-900 border-b pb-2 mb-4">Registrar Movimiento CXC</h3>
                    
                    <form class="space-y-4">
                        
                        <!-- Selector de Tipo de Movimiento -->
                        <div class="grid grid-cols-2 gap-4 bg-gray-50 p-2 rounded border">
                            <label class="flex items-center justify-center p-2 rounded cursor-pointer transition" :class="form.type === 'debe' ? 'bg-red-100 text-red-700 border border-red-200 font-bold' : 'text-gray-500 hover:bg-gray-100'">
                                <input type="radio" x-model="form.type" value="debe" class="sr-only">
                                <span>DEBE (Adelanto/Fiado)</span>
                            </label>
                            <label class="flex items-center justify-center p-2 rounded cursor-pointer transition" :class="form.type === 'haber' ? 'bg-emerald-100 text-emerald-700 border border-emerald-200 font-bold' : 'text-gray-500 hover:bg-gray-100'">
                                <input type="radio" x-model="form.type" value="haber" class="sr-only">
                                <span>HABER (Abono/Pago)</span>
                            </label>
                        </div>

                        <!-- Selector de Entidad (Empleado o Cliente) -->
                        <div>
                            <div class="flex space-x-4 mb-2">
                                <label class="inline-flex items-center cursor-pointer">
                                    <input type="radio" x-model="entityType" value="employee" class="text-emerald-600 focus:ring-emerald-500 border-gray-300">
                                    <span class="ml-2 text-sm font-bold text-gray-700">A un Colaborador</span>
                                </label>
                                <label class="inline-flex items-center cursor-pointer">
                                    <input type="radio" x-model="entityType" value="client" class="text-emerald-600 focus:ring-emerald-500 border-gray-300">
                                    <span class="ml-2 text-sm font-bold text-gray-700">A un Cliente</span>
                                </label>
                            </div>

                            <select x-show="entityType === 'employee'" x-model="form.user_id" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                                <option value="" disabled>Seleccione un colaborador...</option>
                                @foreach($employees as $employee)
                                    <option value="{{ $employee->id }}">{{ $employee->name }}</option>
                                @endforeach
                            </select>

                            <select x-show="entityType === 'client'" x-model="form.client_id" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                                <option value="" disabled>Seleccione un cliente...</option>
                                @foreach($clients as $client)
                                    <option value="{{ $client->id }}">{{ $client->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Fecha <span class="text-red-500">*</span></label>
                                <input type="date" x-model="form.date" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Monto (C$) <span class="text-red-500">*</span></label>
                                <input type="number" step="0.01" min="1" x-model.number="form.amount" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500" placeholder="0.00">
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Detalle / Observación <span class="text-red-500">*</span></label>
                            <input type="text" x-model="form.description" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500" placeholder="Ej. Abono a deuda, Shampoo fiado...">
                        </div>

                    </form>
                </div>
                
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button @click="saveMov()" type="button" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-emerald-600 text-base font-bold text-white hover:bg-emerald-700 sm:ml-3 sm:w-auto sm:text-sm">
                        Registrar
                    </button>
                    <button @click="openModal = false" type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-bold text-gray-700 hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function cxcManager() {
        return {
            openModal: false,
            entityType: 'employee', // Controla qué selector se muestra en pantalla
            
            form: {
                type: 'debe',
                user_id: '',
                client_id: '',
                date: '{{ date('Y-m-d') }}',
                amount: '',
                description: ''
            },

            openCreateModal() {
                this.entityType = 'employee';
                this.form.type = 'debe';
                this.form.user_id = '';
                this.form.client_id = '';
                this.form.amount = '';
                this.form.description = '';
                this.openModal = true;
            },

            async saveMov() {
                // Limpiar la entidad que NO fue seleccionada antes de enviar
                if (this.entityType === 'employee') {
                    this.form.client_id = '';
                } else {
                    this.form.user_id = '';
                }

                try {
                    let response = await fetch('/adelantos', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify(this.form)
                    });

                    let data = await response.json();

                    if (response.ok) {
                        window.dispatchEvent(new CustomEvent('notify', { detail: '¡Movimiento registrado con éxito!' }));
                        this.openModal = false;
                        setTimeout(() => { window.location.reload(); }, 1000);
                    } else {
                        let errorMessage = data.errors ? Object.values(data.errors)[0][0] : data.message;
                        window.dispatchEvent(new CustomEvent('notify', { detail: errorMessage }));
                    }
                } catch (error) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Error crítico de conexión.' }));
                }
            },

            deleteMov(id) {
                window.dispatchEvent(new CustomEvent('open-confirm', { 
                    detail: { 
                        message: '¿Estás seguro de anular este movimiento? Se creará una reversión automática para cuadrar la contabilidad.',
                        action: async () => {
                            try {
                                let response = await fetch(`/adelantos/${id}`, {
                                    method: 'DELETE',
                                    headers: { 
                                        'Accept': 'application/json', 
                                        'X-CSRF-TOKEN': '{{ csrf_token() }}' 
                                    }
                                });
                                
                                let data = await response.json();
                                
                                if (response.ok) {
                                    window.dispatchEvent(new CustomEvent('notify', { detail: data.message }));
                                    // Esperamos 2.5 segundos para que lean el mensaje antes de recargar
                                    setTimeout(() => window.location.reload(), 2500); 
                                } else {
                                    let errorMessage = data.errors ? Object.values(data.errors)[0][0] : 'Error al anular';
                                    window.dispatchEvent(new CustomEvent('notify', { detail: errorMessage }));
                                }
                            } catch(e) {
                                window.dispatchEvent(new CustomEvent('notify', { detail: 'Error crítico de conexión.' }));
                            }
                        } 
                    } 
                }));
            }
        }
    }
</script>
@endsection