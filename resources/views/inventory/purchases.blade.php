@extends('layouts.app')

@section('content')
<div x-data="purchaseSystem()" class="max-w-7xl mx-auto space-y-6">

    <div class="flex justify-between items-center bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
        <div>
            <h2 class="page-title">Ingreso de Mercadería</h2>
            <p class="text-gray-500 text-sm mt-1">Factura de proveedor con IVA de compra y gastos de envío (landed cost). Actualiza inventario, kárdex, lotes, CxP y contabilidad.</p>
        </div>
        <a href="{{ url('/inventario') }}" class="text-emerald-600 hover:text-emerald-800 font-bold text-sm transition">
            ← Volver al Inventario
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- COLUMNA IZQUIERDA: Datos de la Factura -->
        <div class="lg:col-span-1 bg-white rounded-2xl shadow-sm border border-gray-200 p-6 space-y-5 h-fit">
            <h3 class="text-lg font-bold text-gray-800 border-b pb-2">Datos de la Factura</h3>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">Proveedor *</label>
                <select x-model="providerId" @change="syncProveedor()" class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">Seleccione un proveedor...</option>
                    @foreach($proveedores as $proveedor)
                        <option value="{{ $proveedor->id }}" data-credit-days="{{ $proveedor->credit_days }}" data-saldo="{{ $proveedor->saldo_pendiente }}">{{ $proveedor->name }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-400 mt-1" x-show="proveedorSeleccionado">
                    Crédito a <span x-text="creditDays"></span> días · Saldo actual: C$ <span x-text="saldoProveedor.toFixed(2)"></span>
                </p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Factura Nº</label>
                    <input type="text" x-model="numeroDocumento" placeholder="F-001234" class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-emerald-500">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Fecha</label>
                    <input type="date" x-model="fechaFactura" class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-emerald-500">
                </div>
            </div>

            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">Condición de Pago *</label>
                <select x-model="condicionPago" class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="credito">Al Crédito (Generar CxP)</option>
                    <option value="contado">Al Contado (Pago inmediato)</option>
                </select>
            </div>

            <div x-show="condicionPago === 'contado'" x-transition>
                <label class="block text-sm font-bold text-gray-700 mb-1">Origen del Dinero</label>
                <select x-model="metodoPagoContado" class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="efectivo">Caja Chica / Efectivo</option>
                    <option value="banco">Transferencia Bancaria</option>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Descuento (C$)</label>
                    <input type="number" x-model.number="descuentoGlobal" min="0" step="0.01" class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-emerald-500">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Envío / Flete (C$)</label>
                    <input type="number" x-model.number="gastosEnvio" min="0" step="0.01" class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-emerald-500">
                    <p class="text-xs text-gray-400 mt-1">Se capitaliza al costo (landed).</p>
                </div>
            </div>

            <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 mt-4 space-y-1 text-sm">
                <div class="flex justify-between text-gray-600"><span>Subtotal</span><span x-text="'C$ ' + calcularSubtotal().toFixed(2)"></span></div>
                <div class="flex justify-between text-red-500" x-show="descuentoGlobal > 0"><span>Descuento</span><span x-text="'- C$ ' + Number(descuentoGlobal).toFixed(2)"></span></div>
                <div class="flex justify-between text-gray-600" x-show="gastosEnvio > 0"><span>Envío</span><span x-text="'+ C$ ' + Number(gastosEnvio).toFixed(2)"></span></div>
                <div class="flex justify-between text-gray-600"><span>IVA Crédito Fiscal</span><span x-text="'C$ ' + calcularImpuestos().toFixed(2)"></span></div>
                <div class="flex justify-between font-black text-lg text-emerald-600 border-t pt-2 mt-2"><span>TOTAL FACTURA</span><span x-text="'C$ ' + calcularTotal().toFixed(2)"></span></div>
            </div>

            <p class="text-xs text-gray-400">El asiento contable será: <strong>DEBE</strong> Inventario + IVA Crédito Fiscal · <strong>HABER</strong> <span x-text="condicionPago === 'credito' ? 'Proveedores (CxP)' : (metodoPagoContado === 'banco' ? 'Banco' : 'Caja')"></span>.</p>

            <button @click="submitCompra()" :disabled="isSubmitting" class="w-full bg-gray-900 hover:bg-gray-800 text-white font-bold py-3 px-4 rounded-lg shadow transition disabled:opacity-50 mt-2">
                <span x-show="!isSubmitting">Guardar Ingreso</span>
                <span x-show="isSubmitting">Procesando...</span>
            </button>
        </div>

        <!-- COLUMNA DERECHA: Detalle de Artículos -->
        <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between border-b pb-2 mb-4">
                <h3 class="text-lg font-bold text-gray-800">Detalle de Artículos</h3>
                <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                    <input type="checkbox" x-model="ivaPorDefecto" @change="aplicarIvaATodas()" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                    ¿La factura trae IVA 15%?
                </label>
            </div>
            
            <!-- Buscador para agregar al carrito -->
            <div class="flex flex-wrap gap-3 mb-6 bg-gray-50 p-4 rounded-lg border border-gray-200">
                <div class="flex-1 min-w-48">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Producto</label>
                    <select x-model="selectedItem" class="w-full text-sm border-gray-300 rounded-md focus:ring-emerald-500">
                        <option value="">Buscar producto...</option>
                        @foreach($articulos as $articulo)
                            <option value="{{ $articulo->id }}" data-nombre="{{ $articulo->producto }}" data-costo="{{ $articulo->costo_actual ?: $articulo->precio_c }}" data-fractionable="{{ $articulo->is_fractionable ? 1 : 0 }}" data-medida="{{ $articulo->unit_measure }}" data-volumen="{{ $articulo->total_volume }}">
                                {{ $articulo->producto }} (Stock: {{ $articulo->existencia_actual }} @if($articulo->costo_actual) · Costo: C$ {{ number_format($articulo->costo_actual, 2) }} @endif)
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="w-20">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Cant.</label>
                    <input type="number" x-model.number="itemQty" min="0.0001" step="1" class="w-full text-sm border-gray-300 rounded-md focus:ring-emerald-500" placeholder="1">
                </div>
                <div class="w-28">
                    <label class="block text-xs font-bold text-gray-600 mb-1">Costo U. (C$)</label>
                    <input type="number" x-model.number="itemCost" min="0" step="0.01" class="w-full text-sm border-gray-300 rounded-md focus:ring-emerald-500" placeholder="0.00">
                </div>
                <div class="flex items-end">
                    <button @click="addItemToCart()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-4 rounded-md shadow transition">
                        Añadir
                    </button>
                </div>
            </div>

            <!-- Tabla del Carrito de Compras -->
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-3 py-2 text-left font-bold text-gray-600">Producto</th>
                            <th class="px-2 py-2 text-center font-bold text-gray-600 w-24">Cant.</th>
                            <th class="px-2 py-2 text-center font-bold text-gray-600 w-28">Costo U.</th>
                            <th class="px-2 py-2 text-center font-bold text-gray-600 w-20">IVA %</th>
                            <th class="px-2 py-2 text-center font-bold text-gray-600 w-24">Lote</th>
                            <th class="px-2 py-2 text-center font-bold text-gray-600 w-36">Vence</th>
                            <th class="px-2 py-2 text-right font-bold text-gray-600">Costo Landed</th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <template x-for="(item, index) in cart" :key="index">
                            <tr class="hover:bg-gray-50">
                                <td class="px-3 py-2 font-medium text-gray-900">
                                    <span x-text="item.name"></span>
                                    <div class="text-xs text-gray-400" x-show="item.cantidad > 0" x-text="landedInfo(item)"></div>
                                </td>
                                <td class="px-2 py-2"><input type="number" x-model.number="item.cantidad" min="0.0001" class="w-full border-gray-200 rounded-md text-sm text-center"></td>
                                <td class="px-2 py-2"><input type="number" x-model.number="item.costo" min="0" step="0.01" class="w-full border-gray-200 rounded-md text-sm text-center"></td>
                                <td class="px-2 py-2"><input type="number" x-model.number="item.impuesto" min="0" max="100" step="0.01" class="w-full border-gray-200 rounded-md text-sm text-center"></td>
                                <td class="px-2 py-2"><input type="text" x-model="item.lote" class="w-full border-gray-200 rounded-md text-sm" placeholder="L2510"></td>
                                <td class="px-2 py-2"><input type="date" x-model="item.vencimiento" class="w-full border-gray-200 rounded-md text-sm"></td>
                                <td class="px-3 py-2 text-right font-bold text-emerald-700" x-text="'C$ ' + costoFinalLinea(item).toFixed(2)"></td>
                                <td class="px-2 py-2 text-right">
                                    <button @click="removeItem(index)" class="text-red-500 hover:text-red-700 font-bold">✕</button>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="cart.length === 0">
                            <td colspan="8" class="px-4 py-8 text-center text-gray-400">
                                No has agregado productos a la compra. El costo sugerido es el promedio de lotes vigente.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function purchaseSystem() {
    return {
        providerId: '',
        numeroDocumento: '',
        fechaFactura: new Date().toISOString().slice(0, 10),
        condicionPago: 'credito',
        metodoPagoContado: 'efectivo',
        descuentoGlobal: 0,
        gastosEnvio: 0,
        ivaPorDefecto: false,
        creditDays: 30,
        saldoProveedor: 0,
        proveedorSeleccionado: false,
        
        selectedItem: '',
        itemQty: 1,
        itemCost: '',
        
        cart: [],
        isSubmitting: false,

        syncProveedor() {
            let selectEl = document.querySelector('select[x-model="providerId"]');
            let option = selectEl.options[selectEl.selectedIndex];
            this.creditDays = parseInt(option.getAttribute('data-credit-days')) || 30;
            this.saldoProveedor = parseFloat(option.getAttribute('data-saldo')) || 0;
            this.proveedorSeleccionado = this.providerId !== '';
        },

        addItemToCart() {
            if (!this.selectedItem || this.itemQty <= 0 || this.itemCost === '' || this.itemCost < 0) {
                alert('Por favor complete el producto, la cantidad y el costo unitario.');
                return;
            }

            let selectEl = document.querySelector('select[x-model="selectedItem"]');
            let option = selectEl.options[selectEl.selectedIndex];

            let existente = this.cart.find(l => l.id === parseInt(this.selectedItem));
            if (existente) {
                existente.cantidad += parseInt(this.itemQty);
                existente.costo = parseFloat(this.itemCost);
            } else {
                this.cart.push({
                    id: parseInt(this.selectedItem),
                    name: option.getAttribute('data-nombre'),
                    cantidad: parseFloat(this.itemQty),
                    costo: parseFloat(this.itemCost),
                    impuesto: this.ivaPorDefecto ? 15 : 0,
                    lote: '',
                    vencimiento: '',
                    is_fractionable: option.getAttribute('data-fractionable') === '1',
                    medida: option.getAttribute('data-medida') || 'ml',
                    volumen: parseFloat(option.getAttribute('data-volumen')) || 0
                });
            }

            this.selectedItem = '';
            this.itemQty = 1;
            this.itemCost = '';
        },

        aplicarIvaATodas() {
            this.cart.forEach(l => l.impuesto = this.ivaPorDefecto ? 15 : 0);
        },

        removeItem(index) {
            this.cart.splice(index, 1);
        },

        calcularSubtotal() {
            return this.cart.reduce((total, item) => total + (Number(item.cantidad) * Number(item.costo)), 0);
        },

        calcularImpuestos() {
            return this.cart.reduce((total, item) => total + (Number(item.cantidad) * Number(item.costo) * Number(item.impuesto) / 100), 0);
        },

        baseImponible() {
            return Math.max(0, this.calcularSubtotal() - Number(this.descuentoGlobal) + Number(this.gastosEnvio));
        },

        calcularTotal() {
            return this.baseImponible() + this.calcularImpuestos();
        },

        factorLanded() {
            let subtotal = this.calcularSubtotal();
            return subtotal > 0 ? this.baseImponible() / subtotal : 0;
        },

        // Costo final por unidad con descuento + envío repartidos (landed cost)
        costoFinalLinea(item) {
            let factor = this.factorLanded();
            return Number(item.cantidad) * Number(item.costo) * factor / Number(item.cantidad);
        },

        landedInfo(item) {
            let costoFinal = this.costoFinalLinea(item);
            if (item.is_fractionable && item.volumen > 0) {
                return 'Landed: C$ ' + costoFinal.toFixed(4) + ' / und = C$ ' + (costoFinal / item.volumen).toFixed(6) + ' / ' + item.medida;
            }
            return 'Costo final landed: C$ ' + costoFinal.toFixed(4);
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
            if (this.baseImponible() <= 0) {
                alert('El descuento no puede superar el subtotal de la compra.');
                return;
            }

            this.isSubmitting = true;

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
                        condicion_pago: this.condicionPago,
                        metodo_pago_contado: this.metodoPagoContado,
                        numero_documento: this.numeroDocumento || null,
                        fecha_factura: this.fechaFactura || null,
                        descuento: Number(this.descuentoGlobal),
                        gastos_envio: Number(this.gastosEnvio),
                        articulos: this.cart.map(l => ({
                            id: l.id,
                            cantidad: Number(l.cantidad),
                            costo: Number(l.costo),
                            impuesto_porcentaje: Number(l.impuesto),
                            lote: l.lote || null,
                            fecha_vencimiento: l.vencimiento || null
                        }))
                    })
                });

                let data = await response.json().catch(() => ({}));

                if (response.ok) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Compra registrada: kárdex, lotes y contabilidad actualizados.' }));
                    setTimeout(() => window.location.href = '/compras/' + data.factura_id, 900);
                } else {
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
