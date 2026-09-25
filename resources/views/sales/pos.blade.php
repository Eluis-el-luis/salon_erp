@extends('layouts.app')

@section('content')

@php
    $cajaAbierta = \App\Models\SesionCaja::where('user_id', auth()->id())
                                          ->where('estado', 'abierta')
                                          ->exists();
@endphp

@if(!$cajaAbierta)
<div class="min-h-[65vh] flex flex-col items-center justify-center bg-gray-50 rounded-2xl border-2 border-dashed border-gray-300 mx-4 my-6">
        <div class="bg-white p-10 rounded-2xl shadow-xl text-center max-w-md w-full relative overflow-hidden">
            <!-- Barra decorativa roja arriba -->
            <div class="absolute top-0 left-0 right-0 h-2 bg-red-500"></div>
            
            <!-- Ícono de Candado -->
            <div class="w-24 h-24 bg-red-50 text-red-500 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner">
                <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
            </div>
            
            <h2 class="text-3xl font-black text-gray-900 mb-2">POS Bloqueado</h2>
            <p class="text-sm font-bold tracking-widest text-red-500 uppercase mb-4">Turno de Caja Cerrado</p>
            
            <p class="text-gray-600 mb-8 font-medium">
                Para poder procesar ventas y facturar, necesitas registrar tu fondo de caja inicial y abrir tu turno.
            </p>
            
            <a href="{{ url('/caja/arqueo') }}" class="flex items-center justify-center w-full bg-gray-900 hover:bg-gray-800 text-white font-bold py-4 px-4 rounded-xl shadow-md transition duration-200 text-lg">
                <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                Ir a Control de Caja
            </a>
        </div>
    </div>

@else
<div x-data="posSystem()" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    
    <!-- COLUMNA IZQUIERDA: Catálogo de Productos y Servicios (2 columnas de ancho) -->
    <div class="lg:col-span-2 bg-white rounded-lg shadow p-6">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Catálogo Rápido / Servicios</h2>
        
        <!-- Buscador rápido -->
        <div class="mb-4">
            <input type="text" placeholder="Buscar producto o servicio por nombre o código..." 
                   class="w-full shadow border rounded-lg py-2 px-4 text-gray-700 focus:outline-none focus:ring-2 focus:ring-emerald-500">
        </div>

        <!-- Grid de Tarjetas de Artículos (Actualizado con los datos reales del Seeder) -->
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
    
            <!-- Imprimir los Servicios -->
            @foreach($servicios as $servicio)
            <div @click="addItem({ id: {{ $servicio->id }}, producto: '{{ $servicio->name }}', precio_c: {{ $servicio->price }}, type: 'servicio' })" 
                class="bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 rounded-lg p-4 cursor-pointer transition shadow-sm">
                <span class="text-xs font-semibold text-emerald-600 uppercase">Servicio</span>
                <h3 class="font-bold text-gray-800 mt-1">{{ $servicio->name }}</h3>
                <p class="text-emerald-700 font-bold mt-2">C$ {{ number_format($servicio->price, 2) }}</p>
            </div>
            @endforeach

            <!-- Imprimir los Productos Físicos -->
            @foreach($productos as $producto)
            <div @click="addItem({ id: {{ $producto->id }}, producto: '{{ $producto->producto }}', precio_c: {{ $producto->precio_c }}, type: 'producto' })" 
                class="bg-blue-50 hover:bg-blue-100 border border-blue-200 rounded-lg p-4 cursor-pointer transition shadow-sm">
                <span class="text-xs font-semibold text-blue-600 uppercase">Producto</span>
                <h3 class="font-bold text-gray-800 mt-1">{{ $producto->producto }}</h3>
                <p class="text-blue-700 font-bold mt-2">C$ {{ number_format($producto->precio_c, 2) }}</p>
                <p class="text-xs text-gray-500 mt-1">Stock: {{ $producto->existencia_actual }}</p>
            </div>
            @endforeach

        </div>
    </div>

    <!-- COLUMNA DERECHA: El Ticket de Caja / Carrito -->
    <div class="bg-white rounded-lg shadow p-6 flex flex-col justify-between">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 mb-4 border-b pb-2">Caja / Facturación</h2>
            
            <!-- Lista de items en el carrito -->
            <div class="space-y-3 mb-4 max-h-48 overflow-y-auto pr-2">
                <template x-for="(item, index) in cart" :key="index">
                    <div class="flex justify-between items-center bg-gray-50 p-3 rounded-lg border border-gray-200">
                        <div>
                            <h4 class="font-bold text-sm text-gray-800" x-text="item.producto"></h4>
                            <p class="text-xs text-gray-500" x-text="'C$ ' + item.precio_c + ' x ' + item.quantity"></p>
                        </div>
                        <div class="flex items-center space-x-3">
                            <span class="font-bold text-sm text-gray-900" x-text="'C$ ' + (item.precio_c * item.quantity).toFixed(2)"></span>
                            <button @click="removeItem(index)" class="text-red-500 hover:text-red-700 bg-red-50 px-2 py-1 rounded font-bold text-xs transition">✕</button>
                        </div>
                    </div>
                </template>

                <div x-show="cart.length === 0" class="text-center py-8 text-gray-400 text-sm border-2 border-dashed border-gray-200 rounded-lg">
                    El carrito está vacío. Haz clic en un producto o servicio.
                </div>
            </div>

            <!-- SECCIÓN DE CLIENTE Y TOTALES -->
            <div class="border-t pt-4 space-y-3">
                <div class="mb-2">
                    <label class="block text-xs font-bold text-gray-700 mb-1">Asignar a Cliente (Opcional)</label>
                    <select x-model="clientId" class="w-full text-sm shadow-sm border border-gray-300 rounded py-1.5 px-2 text-gray-700 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="" selected>-- Público en General --</option>
                        @foreach($clientes as $cliente)
                            <option value="{{ $cliente->id }}">{{ $cliente->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex justify-between text-sm text-gray-600">
                    <span>Subtotal:</span>
                    <span class="font-bold" x-text="'C$ ' + calculateSubtotal()"></span>
                </div>

                <div class="flex justify-between items-center text-sm text-gray-600">
                    <span class="font-semibold text-emerald-700">Descuento (NIO):</span>
                    <input type="number" x-model.number="discount" min="0" step="0.50" 
                           class="w-28 text-right shadow-sm border border-gray-300 rounded py-1 px-2 text-gray-800 font-bold focus:ring-2 focus:ring-emerald-500" 
                           placeholder="0.00">
                </div>

                <div class="flex justify-between text-xl font-black text-gray-900 border-t border-b py-3 my-2 bg-gray-50 px-2 rounded">
                    <span>TOTAL:</span>
                    <span class="text-emerald-600" x-text="'C$ ' + calculateTotal()"></span>
                </div>

                <!-- NUEVA SECCIÓN DE PAGO DETALLADO -->
                <div class="bg-blue-50/50 p-3 rounded-lg border border-blue-100 space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold text-gray-700 mb-1">Moneda de Pago</label>
                            <select x-model="currency" class="w-full text-sm shadow-sm border border-gray-300 rounded py-1.5 px-2 font-bold focus:ring-2 focus:ring-blue-500">
                                <option value="nio">Córdobas (C$)</option>
                                <option value="usd">Dólares (USD)</option>
                            </select>
                        </div>
                        <div x-show="currency === 'usd'">
                            <label class="block text-xs font-bold text-gray-700 mb-1">Tasa de Cambio</label>
                            <input type="number" x-model.number="exchangeRate" step="0.01" class="w-full text-sm shadow-sm border border-gray-300 rounded py-1.5 px-2 font-bold focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Destino de los Fondos (Método)</label>
                        <select x-model="paymentMethod" class="w-full text-sm shadow-sm border border-gray-300 rounded py-1.5 px-2 font-bold focus:ring-2 focus:ring-blue-500">
                            <option value="efectivo">Caja / Efectivo</option>
                            <option value="bac">Banco BAC (Tarjeta/Transferencia)</option>
                            <option value="lafise">Banco LAFISE (Tarjeta/Transferencia)</option>
                        </select>
                    </div>

                    <div x-show="currency === 'usd'" style="display: none;" class="pt-2 mt-2 border-t border-blue-200 flex justify-between text-lg font-black text-blue-700">
                        <span>Cobrar en USD:</span>
                        <span x-text="'$ ' + (calculateTotal() / (exchangeRate || 1)).toFixed(2)"></span>
                    </div>
                </div>
                
            </div>
        </div>

        <button @click="openCheckout()" :disabled="cart.length === 0" class="w-full mt-4 bg-emerald-600 hover:bg-emerald-700 disabled:bg-gray-400 text-white font-bold py-3.5 px-4 rounded-xl shadow-lg transition text-lg">
            Proceder al Pago
        </button>
    </div>

    <!-- ===== MODAL DE CHECKOUT (PAGOS MIXTOS) ===== -->
    <div x-show="checkoutOpen" style="display: none;" class="fixed inset-0 z-[90] overflow-y-auto bg-gray-900/60 backdrop-blur-sm">
        <div class="flex items-center justify-center min-h-screen px-4 py-8">
            <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden">

                <!-- Cabecera -->
                <div class="bg-emerald-800 text-white px-6 py-4 flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold">Proceder al Pago</h3>
                        <p class="text-emerald-200 text-xs">Total a cobrar: <span class="font-black">C$ <span x-text="calculateTotal()"></span></span></p>
                    </div>
                    <button @click="checkoutOpen = false" class="text-emerald-200 hover:text-white text-2xl leading-none cursor-pointer">&times;</button>
                </div>

                <div class="p-6 space-y-5">

                    <!-- Monto faltante -->
                    <div class="bg-gray-900 text-white p-4 rounded-xl flex items-center justify-between">
                        <span class="text-sm font-semibold text-gray-300">Monto faltante</span>
                        <span class="text-2xl font-black text-emerald-400">C$ <span x-text="faltante"></span></span>
                    </div>

                    <!-- Método y moneda -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label">Método de Pago</label>
                            <select x-model="curMetodo" class="input">
                                <option value="efectivo">Caja / Efectivo</option>
                                <option value="bac">Banco BAC</option>
                                <option value="lafise">Banco LAFISE</option>
                            </select>
                        </div>
                        <div>
                            <label class="label">Moneda</label>
                            <select x-model="curMoneda" class="input">
                                <option value="nio">Córdobas (C$)</option>
                                <option value="usd">Dólares (USD)</option>
                            </select>
                        </div>
                    </div>

                    <div x-show="curMoneda === 'usd'">
                        <label class="label">Tasa de Cambio</label>
                        <input type="number" x-model.number="curTasa" step="0.01" class="input">
                    </div>

                    <!-- Botones de denominación rápida -->
                    <div>
                        <label class="label">Denominaciones rápidas</label>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="d in (curMoneda === 'usd' ? denomUsd : denomNio)" :key="d">
                                <button type="button" @click="setDenom(d)"
                                        class="px-3 py-2 rounded-lg border text-sm font-bold transition cursor-pointer"
                                        :class="curMoneda === 'usd' ? 'border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100' : 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100'">
                                    <span x-text="curMoneda === 'usd' ? '$' : 'C$'"></span><span x-text="d"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- Monto del pago a agregar -->
                    <div class="flex items-end gap-2">
                        <div class="flex-1">
                            <label class="label">Monto recibido</label>
                            <input type="number" x-model.number="curMonto" step="0.01" min="0" class="input text-lg font-bold">
                        </div>
                        <button type="button" @click="addPayment()" class="btn btn-primary">Agregar Pago</button>
                    </div>
                    <p class="text-xs text-gray-400">Este pago equivale a: C$ <span x-text="valorCurPago.toFixed(2)"></span></p>

                    <!-- Pagos agregados -->
                    <div class="border border-gray-200 rounded-xl overflow-hidden">
                        <div class="bg-gray-50 px-4 py-2 text-xs font-bold text-gray-500 uppercase">Pagos Registrados</div>
                        <template x-if="payments.length > 0">
                            <div class="divide-y divide-gray-100">
                                <template x-for="(p, i) in payments" :key="i">
                                    <div class="flex items-center justify-between px-4 py-2.5 text-sm">
                                        <div>
                                            <span class="font-bold text-gray-900" x-text="p.metodo.charAt(0).toUpperCase() + p.metodo.slice(1)"></span>
                                            <span class="text-gray-400" x-text="' · ' + p.moneda.toUpperCase()"></span>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <span class="font-bold" x-text="(p.moneda === 'usd' ? '$' : 'C$') + ' ' + Number(p.monto).toFixed(2)"></span>
                                            <button @click="removePayment(i)" class="text-red-500 hover:text-red-700 font-bold cursor-pointer">&times;</button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <div x-show="payments.length === 0" class="px-4 py-6 text-center text-sm text-gray-400">Aún no agregas pagos.</div>
                    </div>

                    <!-- Vuelto -->
                    <div x-show="parseFloat(vuelto) > 0" class="bg-emerald-50 border border-emerald-200 p-4 rounded-xl flex items-center justify-between">
                        <span class="text-sm font-bold text-emerald-800">Vuelto a entregar</span>
                        <span class="text-2xl font-black text-emerald-700">C$ <span x-text="vuelto"></span></span>
                    </div>

                </div>

                <!-- Acciones -->
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex flex-row-reverse gap-3">
                    <button @click="checkout()" :disabled="payments.length === 0 || totalRecibidoNio < parseFloat(calculateTotal())"
                            class="btn btn-primary py-3 flex-1 disabled:opacity-50 disabled:cursor-not-allowed">
                        Confirmar Venta
                    </button>
                    <button @click="checkoutOpen = false" type="button" class="btn btn-secondary">Cancelar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function posSystem() {
        return {
            cart: [],
            discount: 0,
            clientId: '',
            
            // NUEVOS VALORES POR DEFECTO
            currency: 'nio',
            exchangeRate: 36.50,
            paymentMethod: 'efectivo',

            // === CHECKOUT MODAL (PAGOS MIXTOS) ===
            checkoutOpen: false,
            payments: [],
            curMetodo: 'efectivo',
            curMoneda: 'nio',
            curTasa: 36.50,
            curMonto: 0,
            denomNio: [200, 500, 1000, 2000, 5000],
            denomUsd: [5, 10, 20, 50, 100],

            get faltante() {
                return Math.max(0, (parseFloat(this.calculateTotal()) - this.totalRecibidoNio)).toFixed(2);
            },
            get totalRecibidoNio() {
                return this.payments.reduce((sum, p) => sum + (p.moneda === 'usd' ? (p.monto * p.tasa) : p.monto), 0);
            },
            get vuelto() {
                let exceso = (this.totalRecibidoNio - parseFloat(this.calculateTotal()));
                return exceso > 0 ? exceso.toFixed(2) : '0.00';
            },
            get valorCurPago() {
                return this.curMoneda === 'usd' ? (this.curMonto * this.curTasa) : this.curMonto;
            },

            openCheckout() {
                if (this.cart.length === 0) return;
                if (this.currency === 'usd' && (this.exchangeRate <= 0 || !this.exchangeRate)) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Debes ingresar una tasa de cambio válida.' }));
                    return;
                }
                this.payments = [];
                this.curMetodo = 'efectivo';
                this.curMoneda = 'nio';
                this.curTasa = this.exchangeRate || 36.50;
                this.curMonto = 0;
                this.checkoutOpen = true;
            },

            setDenom(val) {
                this.curMonto = val;
            },

            addPayment() {
                let monto = parseFloat(this.curMonto) || 0;
                if (monto <= 0) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Ingresa un monto o usa un botón de denominación.' }));
                    return;
                }
                this.payments.push({
                    metodo: this.curMetodo,
                    moneda: this.curMoneda,
                    monto: monto,
                    tasa: this.curMoneda === 'usd' ? (this.curTasa || 1) : 1
                });
                this.curMonto = 0;
            },

            removePayment(index) {
                this.payments.splice(index, 1);
            },

            init() {
                const urlParams = new URLSearchParams(window.location.search);
                const appointmentId = urlParams.get('appointment_id');

                if (appointmentId) {
                    this.clientId = urlParams.get('client_id') || '';
                    
                    this.cart.push({
                        id: parseInt(urlParams.get('service_id')),
                        producto: urlParams.get('service_name'),
                        precio_c: parseFloat(urlParams.get('price')),
                        type: 'servicio',
                        quantity: 1,
                        appointment_id: parseInt(appointmentId),
                        stylist_id: parseInt(urlParams.get('stylist_id'))
                    });

                    window.history.replaceState(null, null, window.location.pathname);
                }
            },

            addItem(product) {
                let existingItem = this.cart.find(item => item.id === product.id && item.type === product.type);
                if (existingItem) {
                    existingItem.quantity++;
                } else {
                    this.cart.push({
                        id: product.id,
                        producto: product.producto,
                        precio_c: product.precio_c,
                        type: product.type,
                        quantity: 1
                    });
                }
            },

            removeItem(index) {
                this.cart.splice(index, 1);
            },

            calculateSubtotal() {
                return this.cart.reduce((sum, item) => sum + (item.precio_c * item.quantity), 0).toFixed(2);
            },

            calculateTotal() {
                let subtotal = this.cart.reduce((sum, item) => sum + (item.precio_c * item.quantity), 0);
                let finalTotal = subtotal - (this.discount || 0);
                return finalTotal > 0 ? finalTotal.toFixed(2) : '0.00';
            },

            async checkout() {
                if (this.cart.length === 0) return;

                // Requiere al menos un pago y que el saldo esté cubierto
                if (this.payments.length === 0) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Agrega al menos un método de pago.' }));
                    return;
                }
                if (this.totalRecibidoNio < parseFloat(this.calculateTotal())) {
                    window.dispatchEvent(new CustomEvent('notify', { detail: 'Falta cubrir el monto: C$ ' + this.faltante }));
                    return;
                }

                // Pago principal (legacy) derivado del primer pago
                let primario = this.payments[0];

                try {
                    let response = await fetch('/sales', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                        },
                        body: JSON.stringify({
                            cart: this.cart,
                            discount: this.discount,
                            client_id: this.clientId !== '' ? this.clientId : null,

                            // PAGOS MIXTOS: arreglo de pagos
                            payments: this.payments.map(p => ({
                                metodo: p.metodo,
                                moneda: p.moneda,
                                monto: p.monto,
                                tasa: p.moneda === 'usd' ? (p.tasa || this.exchangeRate) : 1
                            })),

                            // Primary (compatibilidad)
                            payment_method: primario.metodo,
                            currency: primario.moneda,
                            exchange_rate: primario.moneda === 'usd' ? (primario.tasa || this.exchangeRate) : 1
                        })
                    });

                    if (response.ok) {
                        let data = await response.json();
                        window.dispatchEvent(new CustomEvent('notify', { detail: '¡Factura procesada con éxito!' }));

                        // Limpiamos la caja y cerramos el modal
                        this.cart = [];
                        this.discount = 0;
                        this.clientId = '';
                        this.currency = 'nio';
                        this.payments = [];
                        this.checkoutOpen = false;

                        window.open('/ventas/' + data.sale_id + '/ticket', '_blank');
                    } else {
                        let data = await response.json();
                        window.dispatchEvent(new CustomEvent('notify', { detail: 'Error al guardar: ' + (data.error || 'Verifica los datos.') }));
                    }
                } catch (error) {
                    console.error('Error:', error);
                }
            }
        }
    }
</script>

@endif

@endsection