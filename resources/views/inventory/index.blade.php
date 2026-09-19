@extends('layouts.app')

@section('content')
<!-- Contenedor principal de Alpine.js -->
<div x-data="inventoryManager()" class="space-y-6 max-w-7xl mx-auto">
    
    <!-- Encabezado -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="page-title">Control de Inventario</h2>
            <p class="text-sm text-gray-500 mt-1">Gestión detallada de productos, existencias y ubicaciones.</p>
        </div>
        <button @click="openCreateModal()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-4 rounded-lg shadow transition ease-in-out duration-150">
            + Nuevo Producto
        </button>
    </div>

    <!-- Nueva Tabla Detallada de Productos -->
    <div class="bg-white rounded-xl shadow-md border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-black text-gray-500 uppercase tracking-wider">Código / Ubicación</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-black text-gray-500 uppercase tracking-wider">Producto y Clasificación</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-black text-gray-500 uppercase tracking-wider">Precio (C$ / USD)</th>
                        <th scope="col" class="px-6 py-3 text-center text-xs font-black text-gray-500 uppercase tracking-wider">Nivel de Stock</th>
                        <th scope="col" class="px-6 py-3 text-center text-xs font-black text-gray-500 uppercase tracking-wider">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse ($articulos as $articulo)
                    <tr class="hover:bg-gray-50 transition duration-150">
                        
                        <!-- Código y Ubicación -->
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-bold text-gray-900 font-mono">{{ $articulo->codigo ?? 'SIN CÓDIGO' }}</div>
                            <div class="text-xs text-gray-500 mt-1 flex items-center">
                                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                {{ $articulo->ubicacion ?? 'Sin asignar' }}
                            </div>
                        </td>

                        <!-- Producto, Marca y Categoría -->
                        <td class="px-6 py-4">
                            <div class="text-sm font-black text-gray-900">{{ $articulo->producto }}</div>
                            <div class="text-xs text-gray-500 mt-1 flex space-x-2">
                                <span class="bg-gray-100 px-2 py-0.5 rounded text-gray-600">{{ $articulo->marca ?? 'Genérico' }}</span>
                                <span class="bg-emerald-50 text-emerald-700 px-2 py-0.5 rounded border border-emerald-100">{{ $articulo->categoria ?? 'General' }}</span>
                            </div>
                        </td>

                        <!-- Precios -->
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <div class="text-sm font-bold text-emerald-600">C$ {{ number_format($articulo->precio_c, 2) }}</div>
                            <div class="text-xs text-gray-500">$ {{ number_format($articulo->precio_usd, 2) }}</div>
                        </td>

                        <!-- Control de Stock -->
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <div class="flex flex-col items-center justify-center">
                                @if($articulo->existencia_actual <= $articulo->stock_min)
                                    <span class="px-3 py-1 inline-flex text-sm leading-5 font-black rounded-full bg-red-100 text-red-700 border border-red-200" title="¡Stock Crítico!">
                                        ⚠ {{ $articulo->existencia_actual }} und.
                                    </span>
                                @else
                                    <span class="px-3 py-1 inline-flex text-sm leading-5 font-bold rounded-full bg-green-100 text-green-800">
                                        {{ $articulo->existencia_actual }} und.
                                    </span>
                                @endif
                                <span class="text-[10px] text-gray-400 mt-1 font-bold">Mínimo: {{ $articulo->stock_min }}</span>
                            </div>
                        </td>

                        <!-- Acciones conectadas a Alpine -->
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                            <button @click="editProduct({{ $articulo }})" class="text-blue-600 hover:text-blue-900 mr-3 font-bold transition duration-150">Editar</button>
                            <button @click="deleteProduct({{ $articulo->id }})" class="text-red-600 hover:text-red-900 font-bold transition duration-150">Eliminar</button>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-6 py-10 text-center text-gray-500 text-sm">
                            No hay productos registrados en el inventario.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL DE NUEVO PRODUCTO (Tu código de modal se mantiene exactamente igual aquí) -->
    <div x-show="openModal" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div x-show="openModal" @click="openModal = false" class="fixed inset-0 transition-opacity" aria-hidden="true">
                <div class="absolute inset-0 bg-gray-900 opacity-75"></div>
            </div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <div x-show="openModal" class="relative z-20 inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <h3 class="text-lg leading-6 font-bold text-gray-900 mb-4 border-b pb-2">Registrar Nuevo Producto</h3>
                    <form class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Código y Producto (Igual) -->
                        <div class="col-span-2 md:col-span-1">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Código de Barras</label>
                            <input type="text" x-model="form.codigo" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                        </div>
                        <div class="col-span-2 md:col-span-1">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Nombre del Producto *</label>
                            <input type="text" x-model="form.producto" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500" required>
                        </div>
                        
                        <!-- Categoría y Marca (Igual) -->
                        <div class="col-span-2 md:col-span-1">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Categoría</label>
                            <input type="text" x-model="form.categoria" placeholder="Ej. Cabello" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                        </div>
                        <div class="col-span-2 md:col-span-1">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Marca</label>
                            <input type="text" x-model="form.marca" placeholder="Ej. Versum" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                        </div>

                        <!-- NUEVO: Proveedor -->
                        <div class="col-span-2">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Proveedor (Opcional)</label>
                            <select x-model="form.provider_id" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                                <option value="">Sin proveedor asignado</option>
                                @foreach($proveedores as $proveedor)
                                    <option value="{{ $proveedor->id }}">{{ $proveedor->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- NUEVO: Toggle de Fraccionamiento (Líquidos) -->
                        <div class="col-span-2 bg-blue-50 p-4 rounded-lg border border-blue-100">
                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" x-model="form.is_fractionable" class="form-checkbox h-5 w-5 text-emerald-600 rounded">
                                <span class="ml-2 font-bold text-sm text-blue-900">Control de Líquidos / Gramos (Producto Fraccionable)</span>
                            </label>
                            
                            <!-- Estos campos solo aparecen si el checkbox está marcado -->
                            <div x-show="form.is_fractionable" class="grid grid-cols-3 gap-4 mt-4" style="display: none;">
                                <div>
                                    <label class="block text-xs font-bold text-gray-700 mb-1">Unidad</label>
                                    <select x-model="form.unit_measure" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500 bg-white">
                                        <option value="ml">Mililitros (ml)</option>
                                        <option value="oz">Onzas (oz)</option>
                                        <option value="gr">Gramos (gr)</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-700 mb-1">Volumen Total</label>
                                    <input type="number" step="0.01" x-model.number="form.total_volume" @input="form.current_volume = form.total_volume" placeholder="Ej. 1000" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-700 mb-1">Volumen Actual</label>
                                    <input type="number" step="0.01" x-model.number="form.current_volume" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500 bg-gray-100">
                                </div>
                            </div>
                            <p x-show="form.is_fractionable" class="text-xs text-blue-600 mt-2 italic">Al facturar servicios ligados a este producto, se descontarán mililitros de aquí en lugar de botellas enteras.</p>
                        </div>

                        <!-- Precios y Stock (Igual) -->
                        <div class="col-span-2 grid grid-cols-2 gap-4 mt-2 border-t pt-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Precio (C$)</label>
                                <input type="number" step="0.01" x-model.number="form.precio_c" @input="calcularDolares()" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Precio ($ USD)</label>
                                <input type="number" step="0.01" x-model.number="form.precio_usd" @input="calcularCordobas()" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                            </div>
                        </div>
                        <div class="col-span-2 grid grid-cols-3 gap-4 mt-2">
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Stock Actual (Botellas)</label>
                                <input type="number" x-model.number="form.existencia_actual" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Stock Mínimo</label>
                                <input type="number" x-model.number="form.stock_min" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1">Ubicación</label>
                                <input type="text" x-model="form.ubicacion" placeholder="Ej. stock 1" class="w-full shadow-sm border rounded py-2 px-3 text-gray-700 focus:ring-emerald-500">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button @click="saveProduct()" type="button" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-emerald-600 text-base font-medium text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 sm:ml-3 sm:w-auto sm:text-sm">
                        Guardar Producto
                    </button>
                    <button @click="openModal = false" type="button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-emerald-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Script de Alpine.js (Exactamente como lo tenías) -->
<script>
    function inventoryManager() {
        return {
            openModal: false,
            editMode: false, 
            editId: null,    
            
            form: {
                codigo: '', producto: '', categoria: '', marca: '', ubicacion: '',
                type: 'producto_venta', precio_c: 0, precio_usd: 0, existencia_actual: 0, stock_min: 5,
                provider_id: '', is_fractionable: false, unit_measure: 'ml', total_volume: 0, current_volume: 0
            },
            
            tasaCambio: 37, 

            calcularDolares() {
                if(this.form.precio_c > 0) {
                    this.form.precio_usd = (this.form.precio_c / this.tasaCambio).toFixed(2);
                } else {
                    this.form.precio_usd = 0;
                }
            },

            calcularCordobas() {
                if(this.form.precio_usd > 0) {
                    this.form.precio_c = (this.form.precio_usd * this.tasaCambio).toFixed(2);
                } else {
                    this.form.precio_c = 0;
                }
            },

            resetForm() {
                this.editMode = false;
                this.editId = null;
                this.form = {
                    codigo: '', producto: '', categoria: '', marca: '', ubicacion: '',
                    type: 'producto_venta', precio_c: 0, precio_usd: 0, existencia_actual: 0, stock_min: 5,
                    provider_id: '', is_fractionable: false, unit_measure: 'ml', total_volume: 0, current_volume: 0
                };
            },
            
            openCreateModal() {
                this.resetForm();
                this.openModal = true;
            },
            
            editProduct(product) {
                this.editMode = true;
                this.editId = product.id;
                this.form = {
                    codigo: product.codigo || '',
                    producto: product.producto || '',
                    categoria: product.categoria || '',
                    marca: product.marca || '',
                    ubicacion: product.ubicacion || '',
                    type: product.type || 'producto_venta',
                    precio_c: product.precio_c || 0,
                    precio_usd: product.precio_usd || 0,
                    existencia_actual: product.existencia_actual || 0,
                    stock_min: product.stock_min || 5,
                    provider_id: product.provider_id || '',
                    is_fractionable: product.is_fractionable == 1,
                    unit_measure: product.unit_measure || 'ml', 
                    total_volume: product.total_volume || 0,
                    current_volume: product.current_volume || 0
                };
                this.openModal = true;
            },

            async saveProduct() {
                if(this.form.producto === '') {
                    alert('El nombre del producto es obligatorio.');
                    return;
                }

                let url = this.editMode ? `/inventario/${this.editId}` : '/inventario';
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
                        let msg = this.editMode ? '¡Producto actualizado con éxito!' : '¡Producto registrado exitosamente!';
                        window.dispatchEvent(new CustomEvent('notify', { detail: msg }));
                        this.openModal = false;
                        setTimeout(() => { window.location.reload(); }, 1000);
                    } else {
                        let data = await response.json();
                        alert('Error al guardar. Revisa la consola.');
                        console.log(data);
                    }
                } catch (error) {
                    console.error('Error de conexión:', error);
                }
            },

            async deleteProduct(id) {
                if(!confirm('¿Estás seguro de eliminar este producto del inventario? Esta acción no se puede deshacer.')) return;

                try {
                    let response = await fetch(`/inventario/${id}`, {
                        method: 'DELETE',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}' 
                        }
                    });

                    if (response.ok) {
                        window.dispatchEvent(new CustomEvent('notify', { detail: '¡Producto eliminado del sistema!' }));
                        setTimeout(() => { window.location.reload(); }, 1000);
                    } else {
                        alert('Error al eliminar el producto.');
                    }
                } catch (error) {
                    console.error('Error de conexión:', error);
                }
            }
        }
    }
</script>
@endsection