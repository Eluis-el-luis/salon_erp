@extends('layouts.app')

@section('content')
<div x-data="employeeManager()">
    
    <!-- Encabezado -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-3xl font-extrabold text-gray-900">Gestión de Personal</h2>
            <p class="text-sm text-gray-500 mt-1">Administra roles, salarios fijos y esquemas de comisiones históricas.</p>
        </div>
        <button @click="openCreateModal()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-4 rounded-lg shadow transition">
            + Nuevo Empleado
        </button>
    </div>

    <!-- Tabla de Empleados -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-800 text-white">
                <tr>
                    <th scope="col" class="px-6 py-4 text-left text-xs font-black uppercase tracking-wider">Nombre</th>
                    <th scope="col" class="px-6 py-4 text-left text-xs font-black uppercase tracking-wider">Rol</th>
                    <th scope="col" class="px-6 py-4 text-left text-xs font-black uppercase tracking-wider">Salario Fijo</th>
                    <th scope="col" class="px-6 py-4 text-left text-xs font-black uppercase tracking-wider">Comisión (Vigente)</th>
                    <th scope="col" class="px-6 py-4 text-left text-xs font-black uppercase tracking-wider">Estado</th>
                    <th scope="col" class="px-6 py-4 text-right text-xs font-black uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200 text-sm">
                @forelse ($employees as $employee)
                    @php
                        // Obtenemos la comisión real vigente
                        $comisionServicioReal = $employee->esquemaActual ? $employee->esquemaActual->porcentaje_comision : $employee->comision_servicio;
                    @endphp
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="font-bold text-gray-900">{{ $employee->name }}</div>
                            <div class="text-xs text-gray-500">{{ $employee->email }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-3 py-1 inline-flex text-xs leading-5 font-bold rounded-full 
                                {{ $employee->role == 'admin' ? 'bg-purple-100 text-purple-800' : 
                                  ($employee->role == 'estilista' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800') }}">
                                {{ ucfirst($employee->role) }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap font-black text-emerald-600">
                            C$ {{ number_format($employee->salario_fijo, 2) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-600">
                            <span class="font-bold text-gray-900">{{ number_format($comisionServicioReal, 2) }}%</span> 
                            <span class="text-xs text-gray-400 ml-1">(Servicios)</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @if($employee->is_active)
                                <span class="text-emerald-500 font-bold text-xs bg-emerald-50 px-2 py-1 rounded">● Activo</span>
                            @else
                                <span class="text-red-500 font-bold text-xs bg-red-50 px-2 py-1 rounded">● Inactivo</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right font-medium">
                            <!-- Pasamos el valor calculado a la función editEmployee -->
                            <button @click="editEmployee({{ $employee }}, {{ $comisionServicioReal }})" class="text-blue-600 hover:text-blue-900 font-bold bg-blue-50 py-1 px-3 rounded transition mr-2">Editar</button>
                            @if($employee->is_active)
                                <button @click="deactivateEmployee({{ $employee->id }})" class="text-red-600 hover:text-red-900 font-bold bg-red-50 py-1 px-3 rounded transition">Baja</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-gray-500 text-sm font-bold">
                            No hay empleados registrados en el sistema.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- MODAL DE EMPLEADO -->
    <div x-show="openModal" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
        <div class="flex items-center justify-center min-h-screen px-4 text-center">
            <div x-show="openModal" @click="openModal = false" class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity"></div>
            
            <div x-show="openModal" class="relative bg-white rounded-xl text-left overflow-hidden shadow-2xl transform transition-all sm:max-w-2xl sm:w-full z-10 p-6">
                
                <h3 class="text-xl font-black text-gray-900 border-b pb-3 mb-5" x-text="editMode ? 'Editar Empleado' : 'Registrar Nuevo Empleado'"></h3>
                
                <form class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <!-- Datos de Acceso -->
                    <div class="col-span-2 md:col-span-1">
                        <label class="block text-xs font-bold text-gray-700 mb-1">Nombre Completo <span class="text-red-500">*</span></label>
                        <input type="text" x-model="form.name" class="w-full shadow-sm border-gray-300 rounded-lg py-2 px-3 text-gray-700 focus:ring-emerald-500 focus:border-emerald-500" placeholder="Ej. Ana Pérez">
                    </div>
                    <div class="col-span-2 md:col-span-1">
                        <label class="block text-xs font-bold text-gray-700 mb-1">Teléfono</label>
                        <input type="text" x-model="form.phone" class="w-full shadow-sm border-gray-300 rounded-lg py-2 px-3 text-gray-700 focus:ring-emerald-500 focus:border-emerald-500" placeholder="Opcional">
                    </div>
                    
                    <div class="col-span-2 md:col-span-1">
                        <label class="block text-xs font-bold text-gray-700 mb-1">Correo (Usuario) <span class="text-red-500">*</span></label>
                        <input type="email" x-model="form.email" class="w-full shadow-sm border-gray-300 rounded-lg py-2 px-3 text-gray-700 focus:ring-emerald-500 focus:border-emerald-500" placeholder="ejemplo@salon.com">
                    </div>
                    <div class="col-span-2 md:col-span-1">
                        <label class="block text-xs font-bold text-gray-700 mb-1">
                            Contraseña 
                            <span x-show="!editMode" class="text-red-500 font-normal text-[10px] ml-1">* Mín. 8 caracteres</span>
                            <span x-show="editMode" class="text-gray-400 font-normal text-[10px] ml-1">(Opcional)</span>
                        </label>
                        <input type="password" x-model="form.password" class="w-full shadow-sm border-gray-300 rounded-lg py-2 px-3 text-gray-700 focus:ring-emerald-500 focus:border-emerald-500" placeholder="••••••••">
                    </div>

                    <!-- Estructura Salarial -->
                    <div class="col-span-2 mt-2 border-t pt-5">
                        <h4 class="text-sm font-black text-emerald-700 mb-4">Estructura Salarial y Cargo</h4>
                    </div>

                    <div class="col-span-2 md:col-span-1">
                        <label class="block text-xs font-bold text-gray-700 mb-1">Rol en el Sistema <span class="text-red-500">*</span></label>
                        <select x-model="form.role" class="w-full shadow-sm border-gray-300 rounded-lg py-2 px-3 text-gray-700 focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="estilista">Estilista / Especialista</option>
                            <option value="recepcion">Recepción / Caja</option>
                            <option value="admin">Administrador</option>
                        </select>
                    </div>
                    <div class="col-span-2 md:col-span-1">
                        <label class="block text-xs font-bold text-gray-700 mb-1">Salario Fijo Mensual (C$) <span class="text-red-500">*</span></label>
                        <input type="number" step="0.01" min="0" x-model.number="form.salario_fijo" class="w-full shadow-sm border-gray-300 rounded-lg py-2 px-3 text-gray-700 focus:ring-emerald-500 focus:border-emerald-500 font-bold">
                    </div>

                    <div class="col-span-2 md:col-span-1">
                        <label class="block text-xs font-bold text-gray-700 mb-1">Comisión por Servicios (%)</label>
                        <input type="number" step="0.01" min="0" max="100" x-model.number="form.comision_servicio" class="w-full shadow-sm border-gray-300 rounded-lg py-2 px-3 text-gray-700 focus:ring-emerald-500 focus:border-emerald-500 font-bold" placeholder="Ej. 30">
                    </div>
                    <div class="col-span-2 md:col-span-1">
                        <label class="block text-xs font-bold text-gray-700 mb-1">Comisión por Productos (%)</label>
                        <input type="number" step="0.01" min="0" max="100" x-model.number="form.comision_producto" class="w-full shadow-sm border-gray-300 rounded-lg py-2 px-3 text-gray-700 focus:ring-emerald-500 focus:border-emerald-500 font-bold">
                    </div>
                </form>
                
                <div class="mt-6 flex justify-end space-x-3 pt-4 border-t">
                    <button @click="openModal = false" type="button" class="bg-gray-100 text-gray-700 font-bold py-2 px-4 rounded-lg hover:bg-gray-200 transition">
                        Cancelar
                    </button>
                    <button @click="saveEmployee()" type="button" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-6 rounded-lg shadow transition">
                        Guardar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function employeeManager() {
        return {
            openModal: false,
            editMode: false,
            editId: null,
            
            form: {
                name: '', email: '', password: '', phone: '',
                role: 'estilista', salario_fijo: 0, comision_servicio: 0, comision_producto: 0
            },

            resetForm() {
                this.editMode = false;
                this.editId = null;
                this.form = {
                    name: '', email: '', password: '', phone: '',
                    role: 'estilista', salario_fijo: 0, comision_servicio: 0, comision_producto: 0
                };
            },

            openCreateModal() {
                this.resetForm();
                this.openModal = true;
            },

            // Recibimos la comisión real como segundo parámetro
            editEmployee(employee, comisionReal) {
                this.editMode = true;
                this.editId = employee.id;
                this.form = {
                    name: employee.name,
                    email: employee.email,
                    password: '', 
                    phone: employee.phone || '',
                    role: employee.role,
                    salario_fijo: employee.salario_fijo,
                    comision_servicio: comisionReal, // Usamos la que viene del esquema
                    comision_producto: employee.comision_producto
                };
                this.openModal = true;
            },

            async saveEmployee() {
                if(this.form.name === '' || this.form.email === '') {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Nombre y correo son obligatorios.' }));
                    return;
                }

                let url = this.editMode ? `/empleados/${this.editId}` : '/empleados';
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

                    const contentType = response.headers.get("content-type");
                    if (!contentType || !contentType.includes("application/json")) {
                        window.dispatchEvent(new CustomEvent('notify', { detail: 'Error de seguridad. Recarga la página (F5).' }));
                        return;
                    }

                    let data = await response.json();

                    if (response.ok) {
                        window.dispatchEvent(new CustomEvent('notify', { detail: data.message }));
                        this.openModal = false;
                        setTimeout(() => { window.location.reload(); }, 1000);
                    } else {
                        let errorMessage = 'Error al guardar los datos.';
                        if(data.errors) {
                            errorMessage = Object.values(data.errors)[0][0]; 
                        } else if (data.message) {
                            errorMessage = data.message;
                        }
                        window.dispatchEvent(new CustomEvent('notify', { detail: errorMessage }));
                    }
                } catch (error) {
                    console.error("Error:", error);
                }
            },

            deactivateEmployee(id) {
                window.dispatchEvent(new CustomEvent('open-confirm', { 
                    detail: { 
                        message: '¿Estás seguro de dar de baja a este empleado? No podrá acceder al sistema.',
                        action: () => this.processDeactivation(id) 
                    } 
                }));
            },

            async processDeactivation(id) {
                try {
                    let response = await fetch(`/empleados/${id}`, {
                        method: 'DELETE',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        }
                    });

                    if (response.ok) {
                        window.dispatchEvent(new CustomEvent('notify', { detail: '¡Empleado dado de baja!' }));
                        setTimeout(() => { window.location.reload(); }, 1000);
                    }
                } catch (error) {
                    console.error(error);
                }
            }
        }
    }
</script>
@endsection