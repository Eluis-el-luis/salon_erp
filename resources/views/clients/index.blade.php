@extends('layouts.app')

@section('content')
<div x-data="clientManager()">
    <!-- Encabezado -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h2 class="page-title">Directorio de Clientes</h2>
            <p class="page-subtitle">Gestiona los datos de contacto para agenda y cuentas por cobrar.</p>
        </div>
        <button @click="openCreateModal()" class="btn btn-primary">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Nuevo Cliente
        </button>
    </div>

    <!-- Tabla de Clientes -->
    <div class="card overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Nombre</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Teléfono</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase">Correo</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($clientes as $cliente)
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                            {{ $cliente->name }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            {{ $cliente->phone ?? 'N/A' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            {{ $cliente->email ?? 'N/A' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <button @click="editClient({{ $cliente }})" class="text-blue-600 hover:text-blue-900 mr-3">Editar</button>
                            <button @click="deleteClient({{ $cliente->id }})" class="text-red-600 hover:text-red-900">Eliminar</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-6 py-8 text-center text-gray-500 text-sm">
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
            
            <div x-show="openModal" class="relative bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:max-w-lg sm:w-full z-10">
                <div class="bg-white px-6 pt-5 pb-4 sm:p-6">
                    <h3 class="text-lg font-bold text-gray-900 border-b pb-2 mb-4" x-text="editMode ? 'Editar Cliente' : 'Registrar Nuevo Cliente'"></h3>
                    
                    <form class="space-y-4">
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
            form: { name: '', phone: '', email: '' },

            openCreateModal() {
                this.editMode = false;
                this.form = { name: '', phone: '', email: '' };
                this.openModal = true;
            },

            editClient(client) {
                this.editMode = true;
                this.editId = client.id;
                this.form = { name: client.name, phone: client.phone || '', email: client.email || '' };
                this.openModal = true;
            },

            async saveClient() {
                if(this.form.name === '') {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'El nombre es obligatorio.' }));
                    return;
                }

                let url = this.editMode ? `/clientes/${this.editId}` : '/clientes';
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
                        window.dispatchEvent(new CustomEvent('notify', { detail: '¡Cliente guardado!' }));
                        setTimeout(() => { window.location.reload(); }, 1000);
                    } else {
                        let errorMessage = data.errors ? Object.values(data.errors)[0][0] : 'Error al guardar';
                        window.dispatchEvent(new CustomEvent('notify', { detail: errorMessage }));
                    }
                } catch (error) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Error de conexión.' }));
                }
            },

            deleteClient(id) {
                window.dispatchEvent(new CustomEvent('open-confirm', { 
                    detail: { 
                        message: '¿Estás seguro de eliminar este cliente?',
                        action: async () => {
                            let response = await fetch(`/clientes/${id}`, {
                                method: 'DELETE',
                                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
                            });
                            if (response.ok) window.location.reload();
                        } 
                    } 
                }));
            }
        }
    }
</script>
@endsection