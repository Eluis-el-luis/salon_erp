@extends('layouts.app')

@section('title', 'Conciliación Bancaria')

@section('content')
<div x-data="conciliacion({
        saldoInicial: @js((float) $saldoInicialConciliado),
        moneda: @js($cuenta->esEnDolares() ? 'USD' : 'NIO')
     })" class="max-w-7xl mx-auto space-y-6">

    <!-- Encabezado -->
    <div class="flex flex-col md:flex-row md:justify-between md:items-end gap-4">
        <div>
            <a href="{{ url('/bancos') }}" class="text-xs font-bold text-emerald-700 hover:underline uppercase tracking-widest">&larr; Volver a Bancos</a>
            <h2 class="page-title mt-1">Conciliación Bancaria</h2>
            <p class="page-subtitle">{{ $cuenta->etiqueta() }} — saldo según libros: <b>{{ $cuenta->esEnDolares() ? '$ ' : 'C$ ' }}{{ number_format($cuenta->saldo_actual, 2) }}</b></p>
        </div>
        <div class="flex flex-col items-end gap-1 text-right">
            <span class="text-xs font-bold uppercase tracking-widest text-gray-400">Saldo inicial conciliado</span>
            <span class="text-xl font-black text-gray-900">{{ $cuenta->esEnDolares() ? '$ ' : 'C$ ' }}{{ number_format($saldoInicialConciliado, 2) }}</span>
            <span class="text-[11px] text-gray-400">Último extracto cerrado</span>
        </div>
    </div>

    @if($errors->any())
        <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r">
            @foreach($errors->all() as $error)
                <p class="text-sm text-red-700 font-bold">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <!-- Panel de arqueo en vivo -->
    <div class="card p-6">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="space-y-4">
                <div>
                    <label class="label">Fecha del extracto bancario *</label>
                    <input type="date" name="fecha_extracto" x-model="fecha" max="{{ now()->toDateString() }}" class="input">
                    <p class="text-xs text-gray-500 mt-1">Sólo se consideran movimientos con fecha igual o anterior.</p>
                </div>
                <div>
                    <label class="label">Saldo final según el banco *</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center font-bold text-gray-400" x-text="moneda === 'USD' ? '$' : 'C$'"></span>
                        <input type="number" step="0.01" min="0" x-model.number="saldoExtracto" class="input pl-10 font-black text-lg" placeholder="0.00">
                    </div>
                </div>
            </div>

            <div class="lg:col-span-2">
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-gray-50 rounded-lg p-4 border border-gray-100">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Saldo inicial conciliado</p>
                        <p class="text-lg font-black text-gray-800">{{ $cuenta->esEnDolares() ? '$ ' : 'C$ ' }}{{ number_format($saldoInicialConciliado, 2) }}</p>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4 border border-gray-100">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Movimientos marcados (+/-)</p>
                        <p class="text-lg font-black text-gray-800" x-text="signoMoneda() + totalMarcado.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></p>
                    </div>
                    <div class="bg-emerald-50 rounded-lg p-4 border border-emerald-100">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-emerald-500">Extracto teórico (inicial + marcados)</p>
                        <p class="text-lg font-black text-emerald-800" x-text="signoMoneda() + teorico.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></p>
                    </div>
                    <div class="rounded-lg p-4 border" :class="cuadra ? 'bg-emerald-50 border-emerald-100' : 'bg-red-50 border-red-100'">
                        <p class="text-[10px] font-bold uppercase tracking-widest" :class="cuadra ? 'text-emerald-500' : 'text-red-400'">Diferencia vs. extracto</p>
                        <p class="text-lg font-black" :class="cuadra ? 'text-emerald-800' : 'text-red-700'"
                           x-text="(cuadra ? 'C$ 0.00 — listo para cerrar' : (signoMoneda() + Math.abs(diferencia).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + (diferencia > 0 ? ' faltan por marcar' : ' sobra sin marcar')))"></p>
                    </div>
                </div>

                <form action="{{ route('bancos.conciliar', $cuenta->id) }}" method="POST" class="mt-4" x-show="cuadra">
                    @csrf
                    <input type="hidden" name="fecha" :value="fecha">
                    <input type="hidden" name="saldo_extracto" :value="saldoExtracto">
                    <template x-for="id in idsMarcados" :key="id">
                        <input type="hidden" name="movimientos[]" :value="id">
                    </template>
                    <div class="flex flex-col md:flex-row gap-3 items-end">
                        <div class="flex-1 w-full">
                            <label class="label">Observaciones de la conciliación</label>
                            <input type="text" name="observaciones" class="input" placeholder="Ej: Extracto mensual de octubre revisado">
                        </div>
                        <button type="submit" class="btn btn-primary whitespace-nowrap">
                            Cerrar conciliación
                        </button>
                    </div>
                </form>

                <div class="mt-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-3 text-xs" x-show="!cuadra" x-cloak>
                    <b>Auditoría:</b> la conciliación sólo se cierra con diferencia C$ 0.00. Marque lo que ya pasó por el banco
                    (los cheques en tránsito quedan sin marcar) y registre abajo cualquier cargo/abono del extracto que falte en el sistema.
                </div>
            </div>
        </div>
    </div>

    <!-- Movimientos pendientes -->
    <div class="card overflow-hidden">
        <div class="card-header">
            <h3 class="font-bold text-gray-900">Movimientos pendientes de aclarar ({{ $pendientes->count() }})</h3>
            <p class="text-xs text-gray-500">Marque los que ya aparecen en el extracto del banco a la fecha seleccionada.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-gray-500">
                    <tr>
                        <th class="px-4 py-3 w-12"></th>
                        <th class="px-4 py-3 text-left font-bold uppercase text-xs tracking-wider">Fecha</th>
                        <th class="px-4 py-3 text-left font-bold uppercase text-xs tracking-wider">Concepto</th>
                        <th class="px-4 py-3 text-left font-bold uppercase text-xs tracking-wider">Origen</th>
                        <th class="px-4 py-3 text-left font-bold uppercase text-xs tracking-wider">Ref. banco</th>
                        <th class="px-4 py-3 text-left font-bold uppercase text-xs tracking-wider">Estado</th>
                        <th class="px-4 py-3 text-right font-bold uppercase text-xs tracking-wider">Cargo</th>
                        <th class="px-4 py-3 text-right font-bold uppercase text-xs tracking-wider">Abono</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($pendientes as $m)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <input type="checkbox" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                                       value="{{ $m->id }}" x-model="seleccionados" @change="recalcular()"
                                       @if($m->fecha->isAfter(now()->endOfDay())) disabled @endif>
                            </td>
                            <td class="px-4 py-3 text-gray-600 font-medium whitespace-nowrap">{{ $m->fecha->format('d/m/Y') }}</td>
                            <td class="px-4 py-3 text-gray-800">{{ $m->concepto }}</td>
                            <td class="px-4 py-3">
                                <span class="text-[10px] font-bold uppercase tracking-wider bg-gray-100 text-gray-600 py-1 px-2 rounded-full">{{ $m->modulo_origen }}</span>
                            </td>
                            <td class="px-4 py-3 text-gray-500 text-xs">{{ $m->referencia_banco ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if($m->estado_conciliacion === 'en_transito')
                                    <span class="text-[10px] font-bold uppercase tracking-wider bg-blue-100 text-blue-700 py-1 px-2 rounded-full">En tránsito</span>
                                @else
                                    <span class="text-[10px] font-bold uppercase tracking-wider bg-amber-100 text-amber-700 py-1 px-2 rounded-full">Pendiente</span>
                                @endif
                            </td>
                            @if($m->signo() > 0)
                                <td class="px-4 py-3 text-right text-gray-400">—</td>
                                <td class="px-4 py-3 text-right font-black text-emerald-700">{{ number_format($m->monto, 2) }}</td>
                            @else
                                <td class="px-4 py-3 text-right font-black text-red-600">{{ number_format($m->monto, 2) }}</td>
                                <td class="px-4 py-3 text-right text-gray-400">—</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-8 text-center text-gray-500 text-sm">No hay movimientos pendientes: el libro bancario está totalmente conciliado.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Registrar cargo/abono del extracto que falta en el sistema -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="card p-6">
            <h3 class="font-bold text-gray-900 mb-1">Cargo en el extracto no registrado</h3>
            <p class="text-xs text-gray-500 mb-4">Comisiones, mantenimiento, cheques devueltos. Va al gasto 6.9 u otra cuenta de gasto.</p>
            <form action="{{ route('bancos.cargo') }}" method="POST" class="space-y-3">
                @csrf
                <input type="hidden" name="cuenta_bancaria_id" value="{{ $cuenta->id }}">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">Monto *</label>
                        <input type="number" name="monto" step="0.01" min="0.01" required class="input font-bold">
                    </div>
                    <div>
                        <label class="label">Fecha</label>
                        <input type="date" name="fecha" class="input" value="{{ now()->toDateString() }}">
                    </div>
                </div>
                <div>
                    <label class="label">Cuenta de gasto *</label>
                    <select name="cuenta_contable_id" required class="input">
                        @foreach($cuentasGasto as $g)
                            <option value="{{ $g->id }}" {{ $g->codigo === '6.9' ? 'selected' : '' }}>{{ $g->codigo }} — {{ $g->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">Concepto *</label>
                        <input type="text" name="concepto" required maxlength="150" class="input">
                    </div>
                    <div>
                        <label class="label">Ref. extracto</label>
                        <input type="text" name="referencia_banco" class="input">
                    </div>
                </div>
                <button type="submit" class="btn btn-danger w-full">Registrar cargo</button>
            </form>
        </div>

        <div class="card p-6">
            <h3 class="font-bold text-gray-900 mb-1">Abono en el extracto no registrado</h3>
            <p class="text-xs text-gray-500 mb-4">Intereses ganados, devoluciones. Va a la cuenta de ingreso 4.7 u otra de ingreso.</p>
            <form action="{{ route('bancos.abono') }}" method="POST" class="space-y-3">
                @csrf
                <input type="hidden" name="cuenta_bancaria_id" value="{{ $cuenta->id }}">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">Monto *</label>
                        <input type="number" name="monto" step="0.01" min="0.01" required class="input font-bold">
                    </div>
                    <div>
                        <label class="label">Fecha</label>
                        <input type="date" name="fecha" class="input" value="{{ now()->toDateString() }}">
                    </div>
                </div>
                <div>
                    <label class="label">Cuenta de ingreso *</label>
                    <select name="cuenta_contable_id" required class="input">
                        @foreach($cuentasIngreso as $i)
                            <option value="{{ $i->id }}" {{ $i->codigo === '4.7' ? 'selected' : '' }}>{{ $i->codigo }} — {{ $i->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">Concepto *</label>
                        <input type="text" name="concepto" required maxlength="150" class="input">
                    </div>
                    <div>
                        <label class="label">Ref. extracto</label>
                        <input type="text" name="referencia_banco" class="input">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-full">Registrar abono</button>
            </form>
        </div>
    </div>

    <!-- Historial de conciliaciones -->
    <div class="card overflow-hidden">
        <div class="card-header">
            <h3 class="font-bold text-gray-900">Historial de conciliaciones cerradas</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-gray-500">
                    <tr>
                        <th class="px-6 py-3 text-left font-bold uppercase text-xs tracking-wider">Fecha extracto</th>
                        <th class="px-6 py-3 text-right font-bold uppercase text-xs tracking-wider">Saldo inicial</th>
                        <th class="px-6 py-3 text-right font-bold uppercase text-xs tracking-wider">Saldo extracto</th>
                        <th class="px-6 py-3 text-right font-bold uppercase text-xs tracking-wider">Saldo libros</th>
                        <th class="px-6 py-3 text-right font-bold uppercase text-xs tracking-wider">Diferencia</th>
                        <th class="px-6 py-3 text-left font-bold uppercase text-xs tracking-wider">Observaciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($historial as $c)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-gray-700 font-medium">{{ $c->fecha->format('d/m/Y') }}</td>
                            <td class="px-6 py-3 text-right text-gray-600">{{ number_format($c->saldo_inicial_conciliado, 2) }}</td>
                            <td class="px-6 py-3 text-right font-bold text-gray-900">{{ number_format($c->saldo_extracto, 2) }}</td>
                            <td class="px-6 py-3 text-right text-gray-600">{{ number_format($c->saldo_libros, 2) }}</td>
                            <td class="px-6 py-3 text-right font-black {{ $c->diferencia == 0 ? 'text-emerald-600' : 'text-red-600' }}">{{ number_format($c->diferencia, 2) }}</td>
                            <td class="px-6 py-3 text-gray-500 text-xs">{{ $c->observaciones ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-8 text-center text-gray-500 text-sm">Ésta será la primera conciliación de la cuenta.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function conciliacion(config) {
        return {
            fecha: new Date().toISOString().slice(0, 10),
            saldoExtracto: null,
            seleccionados: [],
            montos: {},
            saldoInicial: config.saldoInicial,
            moneda: config.moneda,

            init() {
                // Mapa id -> monto firmado, alimentado desde el DOM de Blade.
                document.querySelectorAll('input[type=checkbox][value]').forEach((chk) => {
                    const id = chk.getAttribute('value');
                    const fila = chk.closest('tr');
                    const abono = fila ? fila.querySelector('td.text-emerald-700') : null;
                    const cargo = fila ? fila.querySelector('td.text-red-600') : null;
                    const celda = abono || cargo;
                    if (!celda) return;
                    const valor = parseFloat(celda.textContent.replace(/,/g, ''));
                    if (!isNaN(valor)) {
                        this.montos[id] = abono ? valor : -valor;
                    }
                });
            },

            get idsMarcados() {
                return this.seleccionados.map(Number);
            },

            get totalMarcado() {
                return this.idsMarcados.reduce((suma, id) => suma + (this.montos[id] || 0), 0);
            },

            get teorico() {
                return Math.round((this.saldoInicial + this.totalMarcado) * 100) / 100;
            },

            get diferencia() {
                const extracto = parseFloat(this.saldoExtracto) || 0;
                return Math.round((extracto - this.teorico) * 100) / 100;
            },

            get cuadra() {
                return this.saldoExtracto !== null && this.saldoExtracto !== '' && Math.abs(this.diferencia) < 0.01;
            },

            signoMoneda() {
                return this.moneda === 'USD' ? '$ ' : 'C$ ';
            },

            recalcular() { /* gancho para efectos futuros; los getters reaccionan solos */ },
        };
    }
</script>
@endsection
