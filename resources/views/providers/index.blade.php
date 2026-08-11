@extends('layouts.app')

@section('content')
<div x-data="providerManager()" class="space-y-6 max-w-7xl mx-auto">
    
    <!-- Encabezado -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-extrabold text-gray-900">Directorio de Proveedores</h2>
            <p class="text-sm text-gray-500 mt-1">Administra las marcas, distribuidoras y contactos comerciales.</p>
        </div>
        <button @click="openCreateModal()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-4 rounded-lg shadow transition ease-in-out duration-150">
            + Nuevo Proveedor
        </button>
    </div>

    <!-- Tabla de Proveedores -->
    <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-black text-gray-500 uppercase tracking-wider">Empresa / Marca</th>
                        <th class="px-6 py-3 text-left text-xs font-black text-gray-500 uppercase tracking-wider">Contacto</th>
                        <th class="px-6 py-3 text-left text-xs font-black text-gray-500 uppercase tracking-wider">Teléfono / Correo</th>
                        <th class="px-6 py-3 text-center text-xs font-black text-gray-500 uppercase tracking-wider">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse ($providers as $provider)
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-bold text-gray-900">{{ $provider->name }}</div>
                            <div class="text-xs text-gray-500 mt-1 truncate max-w-xs" title="{{ $provider->address }}">{{ $provider->address ?? 'Sin dirección registrada' }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-700 font-medium">{{ $provider->contact_name ?? 'N/A' }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900">{{ $provider->phone ?? '-' }}</div>
                            <div class="text-xs text-blue-600">{{ $provider->email ?? '-' }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                            <button @click="editProvider({{ $provider }})" class="text-blue-600 hover:text-blue-900 mr-3 font-bold transition">Editar</button>
                            <button @click="deleteProvider({{ $provider->id }})" class="text-red-600 hover:text-red-900 font-bold transition">Eliminar</button>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-6 py-10 text-center text-gray-500 text-sm">
                            No tienes proveedores registrados.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL -->
    <div x-show="openModal" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div x-show="openModal" @click="openModal = false" class="fixed inset-0 transition-opacity" aria-hidden="true">
                <div class="absolute inset-0 bg-gray-900 opacity-75"></div>
            </div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <div x-show="openModal" class="relative z-20 inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <h3 class="text-lg leading-6 font-bold text-gray-900 mb-4 border-b pb-2" x-text="editMode ? 'Editar Proveedor' : 'Registrar Nuevo Proveedor'"></h3>
                    
                    <form class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="col-span-2 md:col-span-1">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Nombre de la Empresa / Marca *</label>
                            <input type="text" x-model="form.name" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500" required>
                        </div>
                        <div class="col-span-2 md:col-span-1">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Nombre del Contacto (Vendedor)</label>
                            <input type="text" x-model="form.contact_name" placeholder="Ej. Juan Pérez" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                        </div>
                        <div class="col-span-2 md:col-span-1">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Teléfono</label>
                            <input type="text" x-model="form.phone" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                        </div>
                        <div class="col-span-2 md:col-span-1">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Correo Electrónico</label>
                            <input type="email" x-model="form.email" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Dirección</label>
                            <input type="text" x-model="form.address" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Notas Adicionales</label>
                            <textarea x-model="form.notes" rows="2" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500" placeholder="Condiciones de crédito, días de entrega..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button @click="saveProvider()" type="button" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-emerald-600 text-base font-medium text-white hover:bg-emerald-700 sm:ml-3 sm:w-auto sm:text-sm">
                        Guardar
                    </button>
                    <button @click="openModal = false" type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function providerManager() {
        return {
            openModal: false,
            editMode: false, 
            editId: null,    
            
            form: {
                name: '', contact_name: '', phone: '', email: '', address: '', notes: ''
            },

            resetForm() {
                this.editMode = false;
                this.editId = null;
                this.form = {
                    name: '', contact_name: '', phone: '', email: '', address: '', notes: ''
                };
            },
            
            openCreateModal() {
                this.resetForm();
                this.openModal = true;
            },
            
            editProvider(provider) {
                this.editMode = true;
                this.editId = provider.id;
                this.form = {
                    name: provider.name || '',
                    contact_name: provider.contact_name || '',
                    phone: provider.phone || '',
                    email: provider.email || '',
                    address: provider.address || '',
                    notes: provider.notes || ''
                };
                this.openModal = true;
            },

            async saveProvider() {
                if(this.form.name === '') {
                   
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'El nombre de la empresa es obligatorio.' }));
                    return;
                }

                let url = this.editMode ? `/proveedores/${this.editId}` : '/proveedores';
                let method = this.editMode ? 'PUT' : 'POST';

                try {
                    let response = await fetch(url, {
                        method: method,
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}' 
                        },
                        body: JSON.stringify(this.form)
                    });

                    if (response.ok) {
                        this.openModal = false;
                        let msg = this.editMode ? 'Proveedor actualizado con éxito' : 'Proveedor registrado con éxito';
                        window.dispatchEvent(new CustomEvent('notify', { detail: msg }));
                        setTimeout(() => { window.location.reload(); }, 1000);
                    } else {
                        window.dispatchEvent(new CustomEvent('notify', { detail: 'Error al guardar. Verifica los datos.' }));
                    }
                } catch (error) {
                    console.error('Error:', error);
                }
            },

            deleteProvider(id) {
        
                window.dispatchEvent(new CustomEvent('open-confirm', {
                    detail: {
                        message: '¿Estás seguro de eliminar este proveedor? Asegúrate de que no tenga productos asignados.',
                        action: async () => {
                            try {
                                let response = await fetch(`/proveedores/${id}`, {
                                    method: 'DELETE',
                                    headers: {
                                        'Accept': 'application/json',
                                        'X-CSRF-TOKEN': '{{ csrf_token() }}' 
                                    }
                                });

                                if (response.ok) {
                                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Proveedor eliminado correctamente.' }));
                                    setTimeout(() => { window.location.reload(); }, 1000);
                                } else {
                                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Error al eliminar el proveedor.' }));
                                }
                            } catch (error) {
                                console.error('Error:', error);
                            }
                        }
                    }
                }));
            }
        }
    }
</script>
@endsection