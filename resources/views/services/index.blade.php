@extends('layouts.app')

@section('content')
<div x-data="serviceManager()" class="space-y-6">
    
    <!-- Encabezado -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-800">Catálogo de Servicios</h2>
            <p class="text-sm text-gray-500">Administra los servicios que ofrece el salón, sus precios y duración.</p>
        </div>
        <button @click="openCreateModal()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-4 rounded shadow transition">
            + Nuevo Servicio
        </button>
    </div>

    <!-- Tabla de Servicios -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Servicio</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Duración Est.</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Precio Base</th>
                    <th scope="col" class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase">Estado</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($servicios as $servicio)
                    <tr class="hover:bg-gray-50 transition" :class="!{{ $servicio->is_active ? 'true' : 'false' }} ? 'opacity-60 bg-gray-50' : ''">
                        <td class="px-6 py-4">
                            <div class="text-sm font-bold text-gray-900">{{ $servicio->name }}</div>
                            <div class="text-xs text-gray-500">{{ $servicio->description ?? 'Sin descripción' }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 font-medium">
                            ⏱️ {{ $servicio->duration }} min
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-emerald-600">
                            C$ {{ number_format($servicio->price, 2) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            @if($servicio->is_active)
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Activo</span>
                            @else
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Inactivo</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            @if($servicio->is_active)
                                <button @click="editService({{ $servicio }})" class="text-blue-600 hover:text-blue-900 mr-3 transition">Editar</button>
                                <button @click="deleteService({{ $servicio->id }})" class="text-red-600 hover:text-red-900 transition">Dar de baja</button>
                            @else
                                <span class="text-gray-400 text-xs italic">No disponible</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-gray-500 text-sm">
                            No hay servicios registrados. ¡Comienza agregando el primero!
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- MODAL PARA CREAR/EDITAR SERVICIO -->
    <div x-show="openModal" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
        <div class="flex items-center justify-center min-h-screen px-4 text-center">
            
            <div x-show="openModal" @click="openModal = false" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
            
            <div x-show="openModal" class="relative bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:max-w-lg sm:w-full z-10">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <h3 class="text-lg font-bold text-gray-900 border-b pb-2 mb-4" x-text="editMode ? 'Editar Servicio' : 'Nuevo Servicio'"></h3>
                    
                    <form class="space-y-4">
                        
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Nombre del Servicio <span class="text-red-500">*</span></label>
                            <input type="text" x-model="form.name" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500" placeholder="Ej. Corte de Cabello Mujer">
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Precio Base (C$) <span class="text-red-500">*</span></label>
                                <input type="number" step="0.01" min="0" x-model.number="form.price" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500" placeholder="0.00">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Duración (Minutos) <span class="text-red-500">*</span></label>
                                <select x-model.number="form.duration" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                                    <option value="15">15 min</option>
                                    <option value="30">30 min</option>
                                    <option value="45">45 min</option>
                                    <option value="60">1 hora</option>
                                    <option value="90">1.5 horas</option>
                                    <option value="120">2 horas</option>
                                    <option value="180">3 horas</option>
                                    <option value="240">4+ horas</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Descripción / Notas</label>
                            <textarea x-model="form.description" rows="2" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500" placeholder="Ej. Incluye lavado y secado express..."></textarea>
                        </div>

                    </form>
                </div>
                
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button @click="saveService()" type="button" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-emerald-600 text-base font-bold text-white hover:bg-emerald-700 sm:ml-3 sm:w-auto sm:text-sm transition">
                        Guardar
                    </button>
                    <button @click="openModal = false" type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-bold text-gray-700 hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm transition">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function serviceManager() {
        return {
            openModal: false,
            editMode: false,
            editId: null,
            
            form: {
                name: '',
                price: '',
                duration: 30, // Por defecto 30 minutos
                description: ''
            },

            openCreateModal() {
                this.editMode = false;
                this.editId = null;
                this.form = { name: '', price: '', duration: 30, description: '' };
                this.openModal = true;
            },

            editService(service) {
                this.editMode = true;
                this.editId = service.id;
                this.form = { 
                    name: service.name, 
                    price: service.price, 
                    duration: service.duration, 
                    description: service.description || '' 
                };
                this.openModal = true;
            },

            async saveService() {
                if(this.form.name === '' || this.form.price === '') {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Nombre y Precio son obligatorios.' }));
                    return;
                }

                let url = this.editMode ? `/servicios/${this.editId}` : '/servicios';
                let method = this.editMode ? 'PUT' : 'POST';

                try {
                    let response = await fetch(url, {
                        method: method,
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
                        window.dispatchEvent(new CustomEvent('notify', { detail: this.editMode ? '¡Servicio actualizado!' : '¡Servicio registrado con éxito!' }));
                        this.openModal = false;
                        setTimeout(() => { window.location.reload(); }, 1000);
                    } else {
                        let errorMessage = data.errors ? Object.values(data.errors)[0][0] : 'Error al guardar los datos.';
                        window.dispatchEvent(new CustomEvent('notify', { detail: errorMessage }));
                    }
                } catch (error) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Error de conexión con el servidor.' }));
                }
            },

            deleteService(id) {
                window.dispatchEvent(new CustomEvent('open-confirm', { 
                    detail: { 
                        message: '¿Estás seguro de dar de baja este servicio? Ya no aparecerá en la caja para facturar.',
                        action: async () => {
                            let response = await fetch(`/servicios/${id}`, {
                                method: 'DELETE',
                                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
                            });
                            if (response.ok) {
                                window.dispatchEvent(new CustomEvent('notify', { detail: '¡Servicio dado de baja!' }));
                                setTimeout(() => { window.location.reload(); }, 1000);
                            }
                        } 
                    } 
                }));
            }
        }
    }
</script>
@endsection