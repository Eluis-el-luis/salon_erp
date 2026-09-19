@extends('layouts.app')

@section('content')
<div x-data="serviceManager()" class="space-y-6">
    
    <!-- Encabezado -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h2 class="page-title">Catálogo de Servicios</h2>
            <p class="page-subtitle">Administra los servicios que ofrece el salón, sus precios y duración.</p>
        </div>
        <button @click="openCreateModal()" class="btn btn-primary">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Nuevo Servicio
        </button>
    </div>

    <!-- Tabla de Servicios -->
    <div class="card overflow-hidden">
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
                            <span class="inline-flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                {{ $servicio->duration }} min
                            </span>
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
            
            <div x-show="openModal" @click="openModal = false" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>
            
            <div x-show="openModal" class="relative bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:max-w-lg sm:w-full z-10">
                <div class="bg-white px-6 pt-5 pb-4 sm:p-6">
                    <h3 class="text-lg font-bold text-gray-900 border-b pb-2 mb-4" x-text="editMode ? 'Editar Servicio' : 'Nuevo Servicio'"></h3>
                    
                    <form class="space-y-4">
                        
                        <div>
                            <label class="label">Nombre del Servicio <span class="text-red-500">*</span></label>
                            <input type="text" x-model="form.name" class="input" placeholder="Ej. Corte de Cabello Mujer">
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="label">Precio Base (C$) <span class="text-red-500">*</span></label>
                                <input type="number" step="0.01" min="0" x-model.number="form.price" class="input" placeholder="0.00">
                            </div>
                            <div>
                                <label class="label">Duración (Minutos) <span class="text-red-500">*</span></label>
                                <select x-model.number="form.duration" class="input">
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
                            <label class="label">Descripción / Notas</label>
                            <textarea x-model="form.description" rows="2" class="input" placeholder="Ej. Incluye lavado y secado express..."></textarea>
                        </div>

                    </form>
                </div>
                
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse gap-2">
                    <button @click="saveService()" type="button" class="btn btn-primary">Guardar</button>
                    <button @click="openModal = false" type="button" class="btn btn-secondary">Cancelar</button>
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