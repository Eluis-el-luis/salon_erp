@extends('layouts.app')

@section('content')
<div x-data="clientManager()">
    <!-- Encabezado -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-800">Directorio de Clientes</h2>
            <p class="text-sm text-gray-500">Gestiona los datos de contacto para agenda y cuentas por cobrar.</p>
        </div>
        <button @click="openCreateModal()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-4 rounded shadow transition">
            + Nuevo Cliente
        </button>
    </div>

    <!-- Tabla de Clientes -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
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
            <div x-show="openModal" @click="openModal = false" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
            
            <div x-show="openModal" class="relative bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:max-w-lg sm:w-full z-10">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <h3 class="text-lg font-bold text-gray-900 border-b pb-2 mb-4" x-text="editMode ? 'Editar Cliente' : 'Registrar Nuevo Cliente'"></h3>
                    
                    <form class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Nombre Completo <span class="text-red-500">*</span></label>
                            <input type="text" x-model="form.name" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Teléfono</label>
                                <input type="text" x-model="form.phone" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Correo Electrónico</label>
                                <input type="email" x-model="form.email" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                            </div>
                        </div>
                    </form>
                </div>
                
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button @click="saveClient()" type="button" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-emerald-600 text-base font-bold text-white hover:bg-emerald-700 sm:ml-3 sm:w-auto sm:text-sm">
                        Guardar
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