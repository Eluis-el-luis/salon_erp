@extends('layouts.app')

@section('content')
<div x-data="purchaseSystem()" class="max-w-7xl mx-auto space-y-6">

    <div class="flex justify-between items-center bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
        <div>
            <h2 class="text-3xl font-extrabold text-gray-900">Ingreso de Mercadería</h2>
            <p class="text-gray-500 text-sm mt-1">Registra compras, alimenta el inventario y genera CxP automáticamente.</p>
        </div>
        <a href="{{ url('/inventario') }}" class="text-emerald-600 hover:text-emerald-800 font-bold text-sm transition">
            ← Volver al Inventario
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- COLUMNA IZQUIERDA: Datos de la Factura y Proveedor -->
        <div class="lg:col-span-1 bg-white rounded-2xl shadow-sm border border-gray-200 p-6 space-y-5 h-fit">
            <h3 class="text-lg font-bold text-gray-800 border-b pb-2">Datos de la Compra</h3>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">Proveedor *</label>
                <select x-model="providerId" class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">Seleccione un proveedor...</option>
                    @foreach($proveedores as $proveedor)
                        <option value="{{ $proveedor->id }}">{{ $proveedor->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">Condición de Pago *</label>
                <select x-model="tipoPago" class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="credito">Al Crédito (Generar CxP)</option>
                    <option value="contado">Al Contado (Pago inmediato)</option>
                </select>
            </div>

            <!-- Se muestra solo si es al contado -->
            <div x-show="tipoPago === 'contado'" x-transition>
                <label class="block text-sm font-bold text-gray-700 mb-1">Origen del Dinero</label>
                <select x-model="metodoPagoContado" class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="efectivo">Caja Chica / Efectivo</option>
                    <option value="banco">Transferencia Bancaria</option>
                </select>
            </div>
            
            <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 mt-6">
                <p class="text-sm text-gray-500 font-bold mb-1">TOTAL FACTURA</p>
                <p class="text-3xl font-black text-emerald-600" x-text="'C$ ' + calculateTotal()"></p>
            </div>

            <button @click="submitCompra()" :disabled="isSubmitting" class="w-full bg-gray-900 hover:bg-gray-800 text-white font-bold py-3 px-4 rounded-lg shadow transition disabled:opacity-50 mt-4">
                <span x-show="!isSubmitting">Guardar Ingreso</span>
                <span x-show="isSubmitting">Procesando...</span>
            </button>
        </div>

        <!-- COLUMNA DERECHA: Detalle de Productos -->
        <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-bold text-gray-800 border-b pb-2 mb-4">Detalle de Artículos</h3>
            
            <!-- Buscador para agregar al carrito -->
            <div class="flex space-x-3 mb-6 bg-gray-50 p-4 rounded-lg border border-gray-200">
                <div class="flex-1">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Producto</label>
                    <select x-model="selectedItem" class="w-full text-sm border-gray-300 rounded-md focus:ring-emerald-500">
                        <option value="">Buscar producto...</option>
                        @foreach($articulos as $articulo)
                            <option value="{{ $articulo->id }}" data-name="{{ $articulo->producto }}">
                                {{ $articulo->codigo }} - {{ $articulo->producto }} (Stock: {{ $articulo->existencia_actual }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="w-24">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Cant.</label>
                    <input type="number" x-model.number="itemQty" min="1" class="w-full text-sm border-gray-300 rounded-md focus:ring-emerald-500" placeholder="1">
                </div>
                <div class="w-32">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Costo Unit.</label>
                    <input type="number" x-model.number="itemCost" min="0.01" step="0.01" class="w-full text-sm border-gray-300 rounded-md focus:ring-emerald-500" placeholder="0.00">
                </div>
                <div class="flex items-end">
                    <button @click="addItemToCart()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-4 rounded-md shadow transition">
                        Añadir
                    </button>
                </div>
            </div>

            <!-- Tabla del Carrito de Compras -->
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-4 py-2 text-left font-bold text-gray-600">Producto</th>
                        <th class="px-4 py-2 text-center font-bold text-gray-600">Cantidad</th>
                        <th class="px-4 py-2 text-right font-bold text-gray-600">Costo U.</th>
                        <th class="px-4 py-2 text-right font-bold text-gray-600">Subtotal</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <template x-for="(item, index) in cart" :key="index">
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900" x-text="item.name"></td>
                            <td class="px-4 py-3 text-center text-gray-600" x-text="item.cantidad"></td>
                            <td class="px-4 py-3 text-right text-gray-600" x-text="'C$ ' + item.costo.toFixed(2)"></td>
                            <td class="px-4 py-3 text-right font-bold text-gray-900" x-text="'C$ ' + (item.cantidad * item.costo).toFixed(2)"></td>
                            <td class="px-4 py-3 text-right">
                                <button @click="removeItem(index)" class="text-red-500 hover:text-red-700 font-bold">✕</button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="cart.length === 0">
                        <td colspan="5" class="px-4 py-8 text-center text-gray-400">
                            No has agregado productos a la compra.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function purchaseSystem() {
    return {
        providerId: '',
        tipoPago: 'credito',
        metodoPagoContado: 'efectivo',
        
        selectedItem: '',
        itemQty: 1,
        itemCost: '',
        
        cart: [],
        isSubmitting: false,

        addItemToCart() {
            if (!this.selectedItem || this.itemQty <= 0 || !this.itemCost) {
                alert('Por favor complete el producto, cantidad y costo unitario.');
                return;
            }

            // Obtener el nombre del producto del select
            let selectEl = document.querySelector('select[x-model="selectedItem"]');
            let itemName = selectEl.options[selectEl.selectedIndex].getAttribute('data-name');

            this.cart.push({
                id: parseInt(this.selectedItem),
                name: itemName,
                cantidad: parseInt(this.itemQty),
                costo: parseFloat(this.itemCost)
            });

            // Limpiar los campos
            this.selectedItem = '';
            this.itemQty = 1;
            this.itemCost = '';
        },

        removeItem(index) {
            this.cart.splice(index, 1);
        },

        calculateTotal() {
            return this.cart.reduce((total, item) => total + (item.cantidad * item.costo), 0).toFixed(2);
        },

        async submitCompra() {
            if (!this.providerId) {
                alert('Debe seleccionar un proveedor.');
                return;
            }
            if (this.cart.length === 0) {
                alert('Debe agregar al menos un producto a la compra.');
                return;
            }

            this.isSubmitting = true;
            let total = this.calculateTotal();

            try {
                let response = await fetch('/inventario/comprar', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    },
                    body: JSON.stringify({
                        provider_id: this.providerId,
                        tipo_pago: this.tipoPago,
                        metodo_pago_contado: this.metodoPagoContado,
                        monto_total: parseFloat(total),
                        articulos: this.cart
                    })
                });

                if (response.ok) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Compra registrada correctamente.' }));
                    // Limpiar formulario
                    this.cart = [];
                    this.providerId = '';
                    
                    // Opcional: Redirigir al inventario o a cuentas por pagar después de 1 segundo
                    setTimeout(() => window.location.href = '/inventario', 1500);
                } else {
                    let data = await response.json();
                    alert('Error: ' + (data.error || 'No se pudo guardar la compra.'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Ocurrió un error en la conexión.');
            } finally {
                this.isSubmitting = false;
            }
        }
    }
}
</script>
@endsection