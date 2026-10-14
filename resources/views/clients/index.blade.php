@extends('layouts.app')

@section('content')
<div x-data="clientManager()">
    <!-- Encabezado -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h2 class="page-title">Clientes</h2>
            <p class="page-subtitle">Directorio comercial con grupos de fidelización, límites de crédito y cuenta por cobrar (CxC).</p>
        </div>
        <button @click="openCreateModal()" class="btn btn-primary">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Nuevo Cliente
        </button>
    </div>

    <!-- Tabla de Clientes con métricas AR -->
    <div class="card overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Cliente</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Grupo</th>
                    <th scope="col" class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Citas</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Saldo Pendiente (CxC)</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Crédito</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($clientes as $cliente)
                    @php
                        $saldo = (float) ($cliente->saldo_pendiente ?? 0);
                        $limite = $cliente->credit_limit;
                    @endphp
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4">
                            <a href="{{ url('/clientes/' . $cliente->id) }}" class="text-sm font-bold text-gray-900 hover:text-emerald-700 transition">
                                {{ $cliente->name }}
                            </a>
                            <div class="text-xs text-gray-500">{{ $cliente->phone ?? 'Sin teléfono' }} · {{ $cliente->email ?? 'Sin correo' }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @if ($cliente->grupo)
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-indigo-100 text-indigo-800">
                                    {{ $cliente->grupo->name }}
                                    @if ((float) $cliente->grupo->discount_percent > 0)
                                        · {{ number_format($cliente->grupo->discount_percent, 0) }}% dscto
                                    @endif
                                </span>
                            @else
                                <span class="text-xs text-gray-400 italic">Sin grupo</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-600">
                            {{ $cliente->citas_count ?? 0 }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold @if($saldo > 0) text-amber-600 @else text-gray-400 @endif">
                            @if ($saldo > 0)
                                C$ {{ number_format($saldo, 2) }}
                            @else
                                C$ 0.00
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-xs text-gray-600">
                            @if ($limite !== null)
                                <span class="font-semibold text-gray-800">C$ {{ number_format($limite, 2) }}</span>
                                <span class="text-gray-400">· {{ $cliente->credit_days }} días</span>
                            @else
                                <span class="text-gray-400 italic">Sin límite</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <a href="{{ url('/clientes/' . $cliente->id) }}" class="text-emerald-600 hover:text-emerald-900 mr-3 font-semibold">Ver 360</a>
                            <button @click="editClient(@json($cliente))" class="text-blue-600 hover:text-blue-900 mr-3">Editar</button>
                            <button @click="deleteClient({{ $cliente->id }})" class="text-red-600 hover:text-red-900">Eliminar</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-gray-500 text-sm">
                            No hay clientes registrados en el directorio.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- MODAL DE CLIENTE -->
    <div x-show="openModal" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
        <div class="flex items-center justify-center min-h-screen px-4 text-center">
            <div x-show="openModal" @click="openModal = false" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>

            <div x-show="openModal" class="relative bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:max-w-xl sm:w-full z-10">
                <div class="bg-white px-6 pt-5 pb-4 sm:p-6">
                    <h3 class="text-lg font-bold text-gray-900 border-b pb-2 mb-4" x-text="editMode ? 'Editar Cliente' : 'Registrar Nuevo Cliente'"></h3>

                    <form class="space-y-4" @submit.prevent="saveClient()">
                        <div>
                            <label class="label">Nombre Completo <span class="text-red-500">*</span></label>
                            <input type="text" x-model="form.name" class="input">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="label">Teléfono</label>
                                <input type="text" x-model="form.phone" class="input">
                            </div>
                            <div>
                                <label class="label">Correo Electrónico</label>
                                <input type="email" x-model="form.email" class="input">
                            </div>
                        </div>
                        <div>
                            <label class="label">Dirección</label>
                            <input type="text" x-model="form.address" class="input" placeholder="Opcional">
                        </div>

                        <!-- GRUPO DE FIDELIZACIÓN -->
                        <div class="bg-indigo-50/60 border border-indigo-100 rounded-xl p-4 space-y-3">
                            <div class="flex items-center justify-between">
                                <label class="label mb-0">Grupo de Fidelización</label>
                                <button type="button" @click="nuevoGrupoAbierto = !nuevoGrupoAbierto" class="text-xs font-bold text-indigo-600 hover:text-indigo-800">
                                    + Nuevo grupo
                                </button>
                            </div>
                            <select x-model="form.customer_group_id" class="input">
                                <option value="">-- Sin grupo (Regular) --</option>
                                <template x-for="grupo in grupos" :key="grupo.id">
                                    <option :value="grupo.id" x-text="grupo.name + (parseFloat(grupo.discount_percent) > 0 ? ' (' + parseFloat(grupo.discount_percent) + '% dscto.)' : '')"></option>
                                </template>
                            </select>

                            <div x-show="nuevoGrupoAbierto" style="display: none;" class="grid grid-cols-2 gap-3">
                                <input type="text" x-model="grupoNuevo.name" class="input" placeholder="Nombre del grupo (Ej. VIP)">
                                <input type="number" step="0.01" min="0" max="100" x-model.number="grupoNuevo.discount_percent" class="input" placeholder="% Descuento">
                                <button type="button" @click="crearGrupo()" class="btn btn-secondary col-span-2 py-2">Guardar grupo</button>
                            </div>
                        </div>

                        <!-- LÍMITE DE CRÉDITO (CxC) -->
                        <div class="bg-amber-50/60 border border-amber-100 rounded-xl p-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="label">Límite de Crédito (C$)</label>
                                    <input type="number" step="0.01" min="0" x-model.number="form.credit_limit" class="input" placeholder="Vacío = sin límite">
                                </div>
                                <div>
                                    <label class="label">Días de Crédito</label>
                                    <input type="number" min="0" max="365" x-model.number="form.credit_days" class="input" placeholder="0">
                                </div>
                            </div>
                            <p class="text-[11px] text-amber-700 italic mt-2">
                                El POS bloquea facturación a crédito si se excede este límite o si el cliente tiene facturas vencidas (emisión + días de crédito).
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="label">Cumpleaños (fidelización)</label>
                                <input type="date" x-model="form.birthday" class="input">
                            </div>
                            <div>
                                <label class="label">Notas Comerciales</label>
                                <input type="text" x-model="form.notes" class="input" placeholder="Alergias, preferencias...">
                            </div>
                        </div>
                    </form>
                </div>

                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse gap-2">
                    <button @click="saveClient()" type="button" class="btn btn-primary">Guardar</button>
                    <button @click="openModal = false" type="button" class="btn btn-secondary">Cancelar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function clientManager() {
        return {
            openModal: false,
            editMode: false,
            editId: null,
            grupos: @json($grupos),
            nuevoGrupoAbierto: false,
            grupoNuevo: { name: '', discount_percent: 0 },

            formBase() {
                return {
                    name: '', phone: '', email: '', address: '',
                    customer_group_id: '', credit_limit: '', credit_days: 0,
                    birthday: '', notes: ''
                };
            },
            form: { name: '', phone: '', email: '', address: '', customer_group_id: '', credit_limit: '', credit_days: 0, birthday: '', notes: '' },

            openCreateModal() {
                this.editMode = false;
                this.editId = null;
                this.form = this.formBase();
                this.nuevoGrupoAbierto = false;
                this.openModal = true;
            },

            editClient(client) {
                this.editMode = true;
                this.editId = client.id;
                this.form = {
                    name: client.name,
                    phone: client.phone || '',
                    email: client.email || '',
                    address: client.address || '',
                    customer_group_id: client.customer_group_id || '',
                    credit_limit: client.credit_limit === null || client.credit_limit === undefined ? '' : client.credit_limit,
                    credit_days: client.credit_days || 0,
                    birthday: client.birthday || '',
                    notes: client.notes || ''
                };
                this.nuevoGrupoAbierto = false;
                this.openModal = true;
            },

            async crearGrupo() {
                if (!this.grupoNuevo.name) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Escribe el nombre del grupo.' }));
                    return;
                }

                try {
                    let response = await fetch('/clientes/grupos', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify(this.grupoNuevo)
                    });

                    let data = await response.json();

                    if (response.ok) {
                        this.grupos.push(data.grupo);
                        this.form.customer_group_id = data.grupo.id;
                        this.grupoNuevo = { name: '', discount_percent: 0 };
                        this.nuevoGrupoAbierto = false;
                        window.dispatchEvent(new CustomEvent('notify', { detail: '¡Grupo creado y asignado!' }));
                    } else {
                        let errorMessage = data.errors ? Object.values(data.errors)[0][0] : (data.error || 'Error al crear el grupo.');
                        window.dispatchEvent(new CustomEvent('notify', { detail: errorMessage }));
                    }
                } catch (error) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Error de conexión.' }));
                }
            },

            async saveClient() {
                if (this.form.name === '') {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'El nombre es obligatorio.' }));
                    return;
                }

                let url = this.editMode ? `/clientes/${this.editId}` : '/clientes';
                let method = this.editMode ? 'PUT' : 'POST';

                // Normalizar vacíos a null para el backend
                let payload = { ...this.form };
                ['customer_group_id', 'credit_limit', 'birthday'].forEach(function (campo) {
                    if (payload[campo] === '' || payload[campo] === undefined) payload[campo] = null;
                });

                try {
                    let response = await fetch(url, {
                        method: method,
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify(payload)
                    });

                    let data = await response.json();

                    if (response.ok) {
                        window.dispatchEvent(new CustomEvent('notify', { detail: '¡Cliente guardado!' }));
                        setTimeout(() => { window.location.reload(); }, 1000);
                    } else {
                        let errorMessage = data.errors ? Object.values(data.errors)[0][0] : (data.error || 'Error al guardar');
                        window.dispatchEvent(new CustomEvent('notify', { detail: errorMessage }));
                    }
                } catch (error) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Error de conexión.' }));
                }
            },

            deleteClient(id) {
                window.dispatchEvent(new CustomEvent('open-confirm', {
                    detail: {
                        message: '¿Eliminar este cliente? Solo es posible si no tiene facturas ni citas registradas.',
                        action: async () => {
                            let response = await fetch(`/clientes/${id}`, {
                                method: 'DELETE',
                                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
                            });
                            if (response.ok) window.location.reload();
                            else {
                                let data = await response.json().catch(() => ({}));
                                window.dispatchEvent(new CustomEvent('notify', { detail: data.error || 'No se pudo eliminar el cliente.' }));
                            }
                        }
                    }
                }));
            }
        }
    }
</script>
@endsection
