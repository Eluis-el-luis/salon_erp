@extends('layouts.app')

@section('title', 'Mesa de Cambio')

@section('content')
<div x-data="mesaCambio({ ubicaciones: @js($ubicaciones) })" class="max-w-7xl mx-auto space-y-6">

    <!-- Encabezado -->
    <div class="card p-6 flex flex-col md:flex-row md:justify-between md:items-center gap-4">
        <div>
            <h2 class="page-title">Mesa de Cambio (Divisas)</h2>
            <p class="page-subtitle">Compra/venta de USD con trazabilidad de bóvedas, costo promedio ponderado y tasa oficial BCN.</p>
        </div>
        <button @click="modalTasa = true" class="btn btn-secondary">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1"></path></svg>
            Registrar tasa oficial del día
        </button>
    </div>

    @if($errors->any())
        <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-lg shadow-sm">
            @foreach($errors->all() as $error)
                <p class="text-sm text-red-700 font-bold">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <!-- KPIs -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-gradient-to-br from-slate-900 to-slate-800 rounded-xl p-5 text-white shadow-lg">
            <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">USD en todas las bóvedas</p>
            <p class="text-2xl font-black text-emerald-400 mt-1">$ {{ number_format($totalUsdBovedas, 2) }}</p>
        </div>
        <div class="card p-5">
            <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Valor libro de la divisa</p>
            <p class="text-2xl font-black text-gray-900 mt-1">C$ {{ number_format($valorLibroUsd, 2) }}</p>
        </div>
        <div class="card p-5">
            <p class="text-[10px] font-bold uppercase tracking-widest text-emerald-600">Ganancia cambiaria (mes)</p>
            <p class="text-2xl font-black text-emerald-700 mt-1">C$ {{ number_format($resumen->ganancia ?? 0, 2) }}</p>
        </div>
        <div class="card p-5">
            <p class="text-[10px] font-bold uppercase tracking-widest text-red-500">Pérdida cambiaria (mes)</p>
            <p class="text-2xl font-black text-red-600 mt-1">C$ {{ number_format($resumen->perdida ?? 0, 2) }}</p>
        </div>
    </div>

    <!-- Tasa oficial del día -->
    <div class="card p-5 flex flex-col sm:flex-row sm:items-center gap-4 {{ $tasaOficial ? '' : 'border-amber-300 bg-amber-50' }}">
        <div>
            <p class="text-[10px] font-bold uppercase tracking-widest {{ $tasaOficial ? 'text-gray-400' : 'text-amber-600' }}">
                Tasa oficial {{ $tasaOficial ? '(' . \Carbon\Carbon::parse($tasaOficial->fecha)->format('d/m/Y') . ')' : 'SIN REGISTRAR HOY' }}
            </p>
            @if($tasaOficial)
                <p class="text-lg font-black text-gray-900 mt-0.5">
                    BCN compra C$ {{ number_format($tasaOficial->tasa_compra, 4) }} · vende C$ {{ number_format($tasaOficial->tasa_venta, 4) }}
                </p>
            @else
                <p class="text-sm text-amber-800 mt-0.5">Registre la tasa oficial del Banco Central para comparar el spread de cada operación.</p>
            @endif
        </div>
    </div>

    <!-- FORMULARIO DE OPERACIÓN -->
    <div class="card p-6">
        <h3 class="text-lg font-black text-gray-900 border-b pb-3 mb-5">Registrar Operación</h3>

        <form action="{{ url('/mesa-cambio/operar') }}" method="POST" class="space-y-6">
            @csrf
            <input type="hidden" name="origen_tipo" :value="origen.split(':')[0] || ''">
            <input type="hidden" name="origen_id" :value="origen.split(':')[1] || ''">
            <input type="hidden" name="destino_tipo" :value="destino.split(':')[0] || ''">
            <input type="hidden" name="destino_id" :value="destino.split(':')[1] || ''">

            <!-- Tabs -->
            <div class="flex p-1 bg-gray-100 rounded-lg">
                <label class="flex-1 text-center cursor-pointer">
                    <input type="radio" name="tipo" value="compra" x-model="tipo" class="sr-only">
                    <div class="py-2 rounded-md font-bold text-sm transition-all" :class="tipo === 'compra' ? 'bg-white shadow-sm text-emerald-700' : 'text-gray-500 hover:text-gray-700'">
                        EL SALÓN COMPRA USD
                    </div>
                </label>
                <label class="flex-1 text-center cursor-pointer">
                    <input type="radio" name="tipo" value="venta" x-model="tipo" class="sr-only">
                    <div class="py-2 rounded-md font-bold text-sm transition-all" :class="tipo === 'venta' ? 'bg-white shadow-sm text-blue-700' : 'text-gray-500 hover:text-gray-700'">
                        EL SALÓN VENDE USD
                    </div>
                </label>
            </div>

            <!-- Montos -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Monto en Dólares (USD) <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-500 font-bold">$</span>
                        <input type="number" step="0.01" min="0.01" name="monto_usd" x-model.number="montoUsd" required class="pl-8 input font-black text-lg py-3" placeholder="0.00">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Tasa de calle (negociada) <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-500 font-bold">C$</span>
                        <input type="number" step="0.0001" min="1" name="tasa_aplicada" x-model.number="tasaCalle" required class="pl-10 input font-black text-lg py-3" placeholder="36.8000">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Tasa oficial BCN</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-500 font-bold">C$</span>
                        <input type="number" step="0.0001" min="1" name="tasa_oficial" x-model.number="tasaOficial" class="pl-10 input font-black text-lg py-3" placeholder="36.5000">
                    </div>
                    <p class="text-[11px] text-gray-400 mt-1">Déjela igual a la de calle si no aplica comparación.</p>
                </div>
            </div>

            <!-- Bóvedas -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label class="label">
                        <span x-text="tipo === 'compra' ? '¿De dónde salen los CÓRDOBAS?' : '¿De dónde salen los DÓLARES?'"></span> <span class="text-red-500">*</span>
                    </label>
                    <select x-model="origen" required class="input">
                        <option value="">— Seleccione la bóveda de entrega —</option>
                        <template x-for="u in ubicaciones" :key="u.tipo + u.id">
                            <option :value="clave(u)" x-text="etiqueta(u, 'entrega')"></option>
                        </template>
                    </select>
                    <p class="text-xs mt-1" :class="origenValido ? 'text-emerald-600' : 'text-red-600 font-bold'" x-show="origen" x-cloak
                       x-text="origenValido ? ('Disponible: ' + saldoOrigen.toLocaleString('en-US', {minimumFractionDigits: 2})) : mensajeOrigen"></p>
                </div>
                <div>
                    <label class="label">
                        <span x-text="tipo === 'compra' ? '¿A dónde entran los DÓLARES?' : '¿A dónde entran los CÓRDO BAS?'"></span> <span class="text-red-500">*</span>
                    </label>
                    <select x-model="destino" required class="input">
                        <option value="">— Seleccione la bóveda de recepción —</option>
                        <template x-for="u in ubicaciones" :key="u.tipo + u.id">
                            <option :value="clave(u)" x-text="etiqueta(u, 'recepcion')"></option>
                        </template>
                    </select>
                </div>
            </div>

            <div>
                <label class="label">Referencia (nombre del cliente / operación)</label>
                <input type="text" name="referencia" maxlength="100" class="input" placeholder="Opcional: para trazabilidad de ventanilla">
            </div>

            <!-- Calculadora en vivo -->
            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 grid grid-cols-1 sm:grid-cols-3 gap-4 items-center">
                <div>
                    <p class="text-xs font-bold text-gray-500 uppercase" x-text="tipo === 'compra' ? 'El salón entrega (NIO):' : 'El salón recibe (NIO):'"></p>
                    <p class="text-2xl font-black" :class="tipo === 'compra' ? 'text-red-600' : 'text-emerald-600'">
                        C$ <span x-text="totalNio.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                    </p>
                </div>
                <div>
                    <p class="text-xs font-bold text-gray-500 uppercase">Spread vs. tasa oficial:</p>
                    <p class="text-xl font-black" :class="spreadOficial >= 0 ? (tipo === 'venta' ? 'text-emerald-600' : 'text-red-500') : (tipo === 'venta' ? 'text-red-500' : 'text-emerald-600')">
                        C$ <span x-text="Math.abs(spreadOficial).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                        <span class="text-xs font-bold" x-text="spreadOficial >= 0 ? (tipo === 'venta' ? '(a favor del salón)' : '(se paga sobre oficial)') : (tipo === 'venta' ? '(bajo la oficial)' : '(bajo la oficial)')"></span>
                    </p>
                </div>
                <div x-show="tipo === 'venta'" x-cloak>
                    <p class="text-xs font-bold text-gray-500 uppercase">Resultado contable vs. costo promedio:</p>
                    <p class="text-xl font-black" :class="diferencialVenta > 0 ? 'text-emerald-600' : (diferencialVenta < 0 ? 'text-red-600' : 'text-gray-500')">
                        <span x-text="diferencialVenta > 0 ? 'Ganancia' : (diferencialVenta < 0 ? 'Pérdida' : 'Sin diferencial')"></span>
                        C$ <span x-text="Math.abs(diferencialVenta).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                    </p>
                    <p class="text-[11px] text-gray-400" x-show="costoPromedioOrigen > 0">Costo promedio de la bóveda: C$ <span x-text="costoPromedioOrigen"></span></p>
                </div>
                <div class="sm:text-right">
                    <button type="submit" class="bg-gray-900 hover:bg-black text-white font-bold py-3 px-6 rounded-lg shadow transition disabled:opacity-50"
                            :disabled="!puedeOperar" :title="mensajeBloqueo">
                        Procesar operación
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Bóvedas actuales -->
    <div>
        <h3 class="text-xs font-black uppercase tracking-[0.2em] text-gray-400 mb-3">Bóvedas de divisas disponibles</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            @forelse($ubicaciones as $u)
                <div class="card p-5">
                    <p class="text-xs font-black text-gray-900 leading-tight">{{ $u['etiqueta'] }}</p>
                    <p class="text-[11px] text-gray-400 mb-3">{{ $u['detalle'] }}</p>
                    <div class="flex justify-between items-baseline border-t border-gray-100 pt-3">
                        <span class="text-[10px] font-bold uppercase tracking-widest text-gray-400">USD</span>
                        <span class="font-black text-gray-900">$ {{ number_format($u['usd'], 2) }}</span>
                    </div>
                    @if($u['usd'] > 0)
                        <div class="flex justify-between items-baseline mt-1">
                            <span class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Costo prom.</span>
                            <span class="text-xs font-bold text-gray-600">C$ {{ number_format($u['costo_promedio_usd'], 4) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between items-baseline mt-1">
                        <span class="text-[10px] font-bold uppercase tracking-widest text-gray-400">NIO disp.</span>
                        <span class="text-xs font-bold text-gray-600">C$ {{ number_format($u['nio'], 2) }}</span>
                    </div>
                </div>
            @empty
                <div class="card p-6 text-sm text-gray-500">No hay bóvedas activas: abra una caja o active una cuenta bancaria.</div>
            @endforelse
        </div>
    </div>

    <!-- Historial -->
    <div class="card overflow-hidden">
        <div class="card-header">
            <h3 class="font-bold text-gray-900">Historial de Operaciones</h3>
            <p class="text-xs text-gray-500">Con bóveda de origen/destino, diferencial contable y asiento vinculado.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-gray-500">
                    <tr>
                        <th class="px-4 py-3 text-left font-bold uppercase text-xs tracking-wider">Fecha</th>
                        <th class="px-4 py-3 text-center font-bold uppercase text-xs tracking-wider">Tipo</th>
                        <th class="px-4 py-3 text-right font-bold uppercase text-xs tracking-wider">Tasa calle</th>
                        <th class="px-4 py-3 text-right font-bold uppercase text-xs tracking-wider">Tasa oficial</th>
                        <th class="px-4 py-3 text-right font-bold uppercase text-xs tracking-wider">USD</th>
                        <th class="px-4 py-3 text-right font-bold uppercase text-xs tracking-wider">NIO</th>
                        <th class="px-4 py-3 text-left font-bold uppercase text-xs tracking-wider">Bóvedas</th>
                        <th class="px-4 py-3 text-right font-bold uppercase text-xs tracking-wider">Diferencial</th>
                        <th class="px-4 py-3 text-left font-bold uppercase text-xs tracking-wider">Asiento</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse($operaciones as $op)
                        @php
                            $esCompra = $op->tipo === 'compra';
                            $montoUsdOp = $esCompra ? $op->monto_origen : $op->monto_destino;
                            $montoNioOp = $esCompra ? $op->monto_destino : $op->monto_origen;
                        @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-600 font-medium whitespace-nowrap">{{ \Carbon\Carbon::parse($op->fecha)->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="py-1 px-3 rounded-full text-xs font-bold uppercase tracking-wider {{ $esCompra ? 'bg-emerald-100 text-emerald-800' : 'bg-blue-100 text-blue-800' }}">
                                    {{ $esCompra ? 'Compra' : 'Venta' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right text-gray-900 font-bold">{{ number_format($op->tasa_aplicada, 4) }}</td>
                            <td class="px-4 py-3 text-right text-gray-500">{{ $op->tasa_oficial ? number_format($op->tasa_oficial, 4) : '—' }}</td>
                            <td class="px-4 py-3 text-right font-black {{ $esCompra ? 'text-emerald-600' : 'text-red-500' }}">
                                {{ $esCompra ? '+' : '-' }} $ {{ number_format($montoUsdOp, 2) }}
                            </td>
                            <td class="px-4 py-3 text-right font-black {{ $esCompra ? 'text-red-500' : 'text-emerald-600' }}">
                                {{ $esCompra ? '-' : '+' }} C$ {{ number_format($montoNioOp, 2) }}
                            </td>
                            <td class="px-4 py-3 text-[11px] text-gray-500 leading-tight">
                                @if($op->origenPago)
                                    {{ \App\Services\FondosService::etiquetaCorta($op->origenPago) }}
                                @endif
                                <span class="font-black text-gray-400">→</span>
                                @if($op->destinoPago)
                                    {{ \App\Services\FondosService::etiquetaCorta($op->destinoPago) }}
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-black {{ ($op->diferencial_costo ?? 0) > 0 ? 'text-emerald-600' : (($op->diferencial_costo ?? 0) < 0 ? 'text-red-600' : 'text-gray-300') }}">
                                {{ is_null($op->diferencial_costo) || $op->diferencial_costo == 0 ? '—' : number_format($op->diferencial_costo, 2) }}
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @if($op->asientoContable)
                                    <a href="{{ url('/asientos/' . $op->asientoContable->id) }}" class="font-bold text-emerald-700 hover:underline">{{ $op->asientoContable->numero_asiento }}</a>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-6 py-8 text-center text-gray-500 text-sm">No se han registrado operaciones de cambio de divisas aún.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- ==================== MODAL: TASA OFICIAL ==================== -->
    <div x-show="modalTasa" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/70 p-4 overflow-y-auto">
        <div class="relative bg-white rounded-xl shadow-2xl max-w-md w-full p-6" @click.outside="modalTasa = false">
            <h3 class="text-xl font-bold text-gray-900 mb-1">Tasa Oficial del Día</h3>
            <p class="text-sm text-gray-500 mb-4">Tasas publicadas por el Banco Central de Nicaragua para USD → NIO.</p>

            <form action="{{ route('mesa.tasa.oficial') }}" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="label">Fecha *</label>
                    <input type="date" name="fecha" required max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" class="input">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">BCN compra USD *</label>
                        <input type="number" name="tasa_compra" step="0.000001" min="1" required class="input font-black" placeholder="36.350000">
                    </div>
                    <div>
                        <label class="label">BCN vende USD *</label>
                        <input type="number" name="tasa_venta" step="0.000001" min="1" required class="input font-black" placeholder="36.650000">
                    </div>
                </div>
                <div class="pt-2 flex justify-end gap-3">
                    <button type="button" @click="modalTasa = false" class="btn btn-secondary">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar tasa</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function mesaCambio(config) {
        return {
            ubicaciones: config.ubicaciones,
            tipo: 'compra',
            montoUsd: null,
            tasaCalle: null,
            tasaOficial: null,
            origen: '',
            destino: '',
            modalTasa: false,

            clave(u) { return (u.tipo === 'App\\Models\\SesionCaja' ? 'sesion' : 'banco') + ':' + u.id; },

            get origenSeleccionada() {
                return this.ubicaciones.find(u => this.clave(u) === this.origen) || null;
            },

            get destinoSeleccionada() {
                return this.ubicaciones.find(u => this.clave(u) === this.destino) || null;
            },

            get saldoOrigen() {
                const u = this.origenSeleccionada;
                if (!u) return 0;
                return this.tipo === 'compra' ? u.nio : u.usd;
            },

            get costoPromedioOrigen() {
                const u = this.origenSeleccionada;
                return u ? parseFloat(u.costo_promedio_usd) : 0;
            },

            get origenValido() {
                const u = this.origenSeleccionada;
                if (!u) return false;
                return this.saldoOrigen > 0 && this.saldoOrigen >= (this.totalNio || 0);
            },

            get mensajeOrigen() {
                const u = this.origenSeleccionada;
                if (!u) return '';
                if (this.saldoOrigen <= 0) {
                    return this.tipo === 'compra'
                        ? 'Esa bóveda no tiene efectivo NIO disponible.'
                        : 'Esa bóveda no tiene dólares físicos disponibles.';
                }
                return 'Fondos insuficientes: disponible ' + this.saldoOrigen.toLocaleString('en-US', {minimumFractionDigits: 2}) + '.';
            },

            get totalNio() {
                const monto = parseFloat(this.montoUsd) || 0;
                const tasa = parseFloat(this.tasaCalle) || 0;
                return Math.round(monto * tasa * 100) / 100;
            },

            get spreadOficial() {
                const monto = parseFloat(this.montoUsd) || 0;
                const tasa = parseFloat(this.tasaCalle) || 0;
                const oficial = parseFloat(this.tasaOficial) || 0;
                if (!oficial || !tasa) return 0;
                return Math.round(monto * (tasa - oficial) * 100) / 100;
            },

            get diferencialVenta() {
                if (this.tipo !== 'venta') return 0;
                const monto = parseFloat(this.montoUsd) || 0;
                const costo = this.costoPromedioOrigen;
                if (!costo) return 0;
                return Math.round((this.totalNio - monto * costo) * 100) / 100;
            },

            get puedeOperar() {
                if (!this.origenSeleccionada || !this.destinoSeleccionada) return false;
                if (this.origen === this.destino) return true; // misma bóveda: cambio de caja local
                if (!this.origenValido) return false;
                if (this.tipo === 'venta' && this.costoPromedioOrigen <= 0) return false;
                return true;
            },

            get mensajeBloqueo() {
                if (!this.origenSeleccionada || !this.destinoSeleccionada) return 'Seleccione bóvedas de origen y destino';
                if (!this.origenValido) return this.mensajeOrigen;
                if (this.tipo === 'venta' && this.costoPromedioOrigen <= 0) return 'La bóveda de origen no tiene costo promedio de USD';
                return '';
            },

            etiqueta(u, lado) {
                const saldo = lado === 'entrega'
                    ? (this.tipo === 'compra' ? 'NIO C$ ' + u.nio.toLocaleString('en-US', {maximumFractionDigits: 2}) : 'USD $ ' + u.usd.toLocaleString('en-US', {maximumFractionDigits: 2}))
                    : (this.tipo === 'compra' ? 'recibe USD' : 'recibe NIO');
                return u.etiqueta + ' — ' + saldo;
            },
        };
    }
</script>
@endsection
