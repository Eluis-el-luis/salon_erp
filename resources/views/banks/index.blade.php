@extends('layouts.app')

@section('title', 'Bancos')

@section('content')
<div x-data="bancoDashboard()" class="max-w-7xl mx-auto space-y-6">

    <!-- Encabezado -->
    <div class="flex flex-col md:flex-row md:justify-between md:items-end gap-4">
        <div>
            <h2 class="page-title">Gestión Bancaria</h2>
            <p class="page-subtitle">Cuentas multimoneda, libro mayor, cargos bancarios y conciliación con extracto real.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button @click="abrirModal('transferir')" class="btn btn-secondary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                Transferir entre bóvedas
            </button>
            <button @click="abrirModal('deposito')" class="btn btn-primary" :disabled="!cajaActiva" title="{{ $cajaActiva ? '' : 'Requiere caja abierta' }}">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v8m-4-4h8m-9 9h10a2 2 0 002-2V6a2 2 0 00-2-2H7a2 2 0 00-2 2v13a2 2 0 002 2z"></path></svg>
                Depositar efectivo
            </button>
        </div>
    </div>

    @if($errors->any())
        <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r">
            @foreach($errors->all() as $error)
                <p class="text-sm text-red-700 font-bold">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <!-- KPIs -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="card p-5">
            <p class="text-xs font-bold uppercase tracking-widest text-gray-400">Saldo Bancario NIO</p>
            <p class="text-2xl font-black text-gray-900 mt-1">C$ {{ number_format($kpis['total_nio'], 2) }}</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-bold uppercase tracking-widest text-gray-400">Saldo Bancario USD</p>
            <p class="text-2xl font-black text-emerald-700 mt-1">$ {{ number_format($kpis['total_usd'], 2) }}</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-bold uppercase tracking-widest text-gray-400">Pendientes de conciliar</p>
            <p class="text-2xl font-black {{ $kpis['pendientes'] > 0 ? 'text-amber-600' : 'text-gray-900' }} mt-1">{{ $kpis['pendientes'] }}</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-bold uppercase tracking-widest text-gray-400">En tránsito (no acreditados)</p>
            <p class="text-2xl font-black {{ $kpis['en_transito'] > 0 ? 'text-blue-600' : 'text-gray-900' }} mt-1">{{ $kpis['en_transito'] }}</p>
        </div>
    </div>

    <!-- Cuentas por moneda -->
    @php $cuentasPorMoneda = $cuentas->groupBy(fn ($c) => $c->esEnDolares() ? 'USD' : 'NIO'); @endphp

    @foreach(['NIO' => 'Cuentas en Córdobas', 'USD' => 'Cuentas en Dólares'] as $codigo => $titulo)
        @php $grupo = $cuentasPorMoneda->get($codigo, collect()); @endphp
        <div>
            <h3 class="text-xs font-black uppercase tracking-[0.2em] text-gray-400 mb-3">{{ $titulo }}</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @forelse($grupo as $cuenta)
                    <div class="rounded-2xl p-6 shadow-lg text-white relative overflow-hidden bg-gradient-to-br {{ $cuenta->esEnDolares() ? 'from-teal-800 to-teal-950' : ($cuenta->banco->codigo == 'BAC' ? 'from-red-700 to-red-950' : 'from-emerald-700 to-emerald-950') }}">
                        <div class="absolute top-0 right-0 p-4 opacity-20">
                            <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
                        </div>

                        <h3 class="text-lg font-black uppercase tracking-widest mb-0.5">{{ $cuenta->banco->nombre }}</h3>
                        <p class="text-white/70 text-xs font-medium mb-4">{{ $cuenta->etiqueta() }}</p>

                        <p class="text-[10px] uppercase tracking-widest font-bold text-white/60">Saldo según libros</p>
                        <p class="text-3xl font-black mb-4">
                            {{ $cuenta->esEnDolares() ? '$' : 'C$' }} {{ number_format($cuenta->saldo_actual, 2) }}
                            <span class="text-sm font-bold text-white/60">{{ $codigo }}</span>
                        </p>

                        @if($cuenta->cuentaContable)
                            <p class="text-[10px] text-white/60 mb-4 uppercase tracking-widest font-bold">
                                Mayor: {{ $cuenta->cuentaContable->codigo }} · {{ $cuenta->cuentaContable->nombre }}
                            </p>
                        @endif

                        <div class="flex flex-wrap gap-2 border-t border-white/20 pt-4">
                            <a href="{{ route('bancos.libro', $cuenta->id) }}" class="bg-white text-gray-900 hover:bg-gray-100 font-bold py-2 px-3 rounded-lg shadow text-xs transition">
                                Libro mayor
                            </a>
                            <a href="{{ route('bancos.conciliacion', $cuenta->id) }}" class="bg-white/20 hover:bg-white/30 font-bold py-2 px-3 rounded-lg shadow text-xs transition">
                                Conciliar
                            </a>
                            <button @click="prepararCargo({{ $cuenta->id }}, '{{ addslashes($cuenta->etiqueta()) }}', {{ $cuenta->esEnDolares() ? 'true' : 'false' }})"
                                    class="bg-white/20 hover:bg-white/30 font-bold py-2 px-3 rounded-lg shadow text-xs transition">
                                Cargo
                            </button>
                            <button @click="prepararAbono({{ $cuenta->id }}, '{{ addslashes($cuenta->etiqueta()) }}', {{ $cuenta->esEnDolares() ? 'true' : 'false' }})"
                                    class="bg-white/20 hover:bg-white/30 font-bold py-2 px-3 rounded-lg shadow text-xs transition">
                                Abono
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="card p-6 text-sm text-gray-500">No hay cuentas en {{ $codigo }}.</div>
                @endforelse
            </div>
        </div>
    @endforeach

    <!-- Últimas transferencias -->
    <div class="card overflow-hidden">
        <div class="card-header">
            <h3 class="font-bold text-gray-900">Últimas transferencias entre bóvedas</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-gray-500">
                    <tr>
                        <th class="px-6 py-3 text-left font-bold uppercase text-xs tracking-wider">Fecha</th>
                        <th class="px-6 py-3 text-left font-bold uppercase text-xs tracking-wider">Concepto</th>
                        <th class="px-6 py-3 text-right font-bold uppercase text-xs tracking-wider">Monto</th>
                        <th class="px-6 py-3 text-right font-bold uppercase text-xs tracking-wider">Comisión</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($transferencias as $t)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 text-gray-600 font-medium">{{ \Carbon\Carbon::parse($t->fecha)->format('d/m/Y H:i') }}</td>
                            <td class="px-6 py-3 text-gray-800">{{ $t->concepto ?? 'Transferencia de fondos' }}</td>
                            <td class="px-6 py-3 text-right font-black text-gray-900">
                                {{ ($t->moneda && $t->moneda->codigo === 'USD') ? '$ ' : 'C$ ' }}{{ number_format($t->monto, 2) }}
                            </td>
                            <td class="px-6 py-3 text-right text-gray-500">{{ $t->comision > 0 ? number_format($t->comision, 2) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-8 text-center text-gray-500 text-sm">Sin transferencias registradas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- ==================== MODAL: DEPÓSITO ==================== -->
    <div x-show="modal === 'deposito'" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/70 p-4 overflow-y-auto">
        <div class="relative bg-white rounded-xl shadow-2xl max-w-lg w-full p-6" @click.outside="cerrarModal()">
            <h3 class="text-xl font-bold text-gray-900 mb-1">Depositar Efectivo</h3>
            <p class="text-sm text-gray-500 mb-4">El dinero sale de una caja de turno y entra a la cuenta bancaria seleccionada, con su asiento contable.</p>

            @if(!$cajaActiva)
                <div class="bg-red-50 text-red-700 p-4 rounded-lg text-sm font-bold border border-red-200">
                    No tienes una caja abierta. Abre tu turno en Control de Caja antes de depositar.
                </div>
            @else
                <form action="{{ url('/bancos/depositar') }}" method="POST" class="space-y-4">
                    @csrf
                    <input type="hidden" name="caja_origen_id" value="{{ $cajaActiva->id }}">

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label">Bóveda destino *</label>
                            <select name="cuenta_destino_id" x-model="deposito.cuenta" required class="input">
                                <option value="">— Seleccione —</option>
                                @foreach($cuentas as $c)
                                    <option value="{{ $c->id }}">{{ $c->etiqueta() }} ({{ $c->moneda->codigo }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="label">Moneda depositada *</label>
                            <select name="moneda" x-model="deposito.moneda" required class="input">
                                <option value="nio">Córdobas (NIO)</option>
                                <option value="usd">Dólares (USD)</option>
                            </select>
                        </div>
                    </div>

                    <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-3 text-xs" x-show="deposito.cuenta" x-cloak>
                        <span x-show="deposito.moneda === 'usd'">
                            Depósito en USD: los dólares salen del <b>saldo físico del turno</b> (saldos de divisas) a su costo promedio.
                            Sólo cuentas bancarias en USD pueden recibirlos.
                        </span>
                        <span x-show="deposito.moneda === 'nio'">
                            Depósito en NIO: se valida contra el <b>efectivo teórico</b> del turno (apertura + cobros netos − salidas previas).
                        </span>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label">Monto *</label>
                            <input type="number" name="monto" step="0.01" min="0.01" required class="input font-black text-lg">
                        </div>
                        <div>
                            <label class="label">Fecha valor</label>
                            <input type="date" name="fecha" class="input" value="{{ now()->toDateString() }}">
                        </div>
                    </div>

                    <div>
                        <label class="label">Referencia del banco (boleta / comprobante)</label>
                        <input type="text" name="referencia_banco" class="input" placeholder="Ej: BOLETA-00123">
                    </div>

                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="en_transito" value="1" class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                        El banco aún no lo ha acreditado (queda <b>en tránsito</b> para la conciliación)
                    </label>

                    <div class="pt-2 flex justify-end gap-3">
                        <button type="button" @click="cerrarModal()" class="btn btn-secondary">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Confirmar depósito</button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    <!-- ==================== MODAL: TRANSFERIR ==================== -->
    <div x-show="modal === 'transferir'" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/70 p-4 overflow-y-auto">
        <div class="relative bg-white rounded-xl shadow-2xl max-w-2xl w-full p-6" @click.outside="cerrarModal()">
            <h3 class="text-xl font-bold text-gray-900 mb-1">Transferir entre Bóvedas</h3>
            <p class="text-sm text-gray-500 mb-4">Banco → Banco, Banco → Caja o Caja → Banco. La comisión del banco se contabiliza al gasto 6.9.</p>

            <form action="{{ url('/bancos/transferir') }}" method="POST" class="space-y-4">
                @csrf
                <input type="hidden" name="origen_tipo" :value="transferir.origen.split(':')[0] || ''">
                <input type="hidden" name="origen_id" :value="transferir.origen.split(':')[1] || ''">
                <input type="hidden" name="destino_tipo" :value="transferir.destino.split(':')[0] || ''">
                <input type="hidden" name="destino_id" :value="transferir.destino.split(':')[1] || ''">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="label">Origen (sale el dinero) *</label>
                        <select x-model="transferir.origen" required class="input">
                            <option value="">— Seleccione —</option>
                            <optgroup label="Cajas de turno (efectivo)">
                                @foreach($sesionesAbiertas as $s)
                                    <option value="sesion:{{ $s->id }}">Caja #{{ $s->id }} — {{ $s->usuario->name ?? 'Turno' }}</option>
                                @endforeach
                            </optgroup>
                            <optgroup label="Cuentas bancarias">
                                @foreach($cuentas as $c)
                                    <option value="banco:{{ $c->id }}">{{ $c->etiqueta() }}</option>
                                @endforeach
                            </optgroup>
                        </select>
                    </div>
                    <div>
                        <label class="label">Destino (entra el dinero) *</label>
                        <select x-model="transferir.destino" required class="input">
                            <option value="">— Seleccione —</option>
                            <optgroup label="Cajas de turno (efectivo)">
                                @foreach($sesionesAbiertas as $s)
                                    <option value="sesion:{{ $s->id }}">Caja #{{ $s->id }} — {{ $s->usuario->name ?? 'Turno' }}</option>
                                @endforeach
                            </optgroup>
                            <optgroup label="Cuentas bancarias">
                                @foreach($cuentas as $c)
                                    <option value="banco:{{ $c->id }}">{{ $c->etiqueta() }}</option>
                                @endforeach
                            </optgroup>
                        </select>
                    </div>
                </div>

                <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-3 text-xs">
                    La moneda del movimiento la fija la cuenta bancaria que participe (NIO o USD).
                    No se permiten transferencias directas NIO↔USD: use la Mesa de Cambio.
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="label">Monto *</label>
                        <input type="number" name="monto" step="0.01" min="0.01" required class="input font-black text-lg">
                    </div>
                    <div>
                        <label class="label">Comisión del banco</label>
                        <input type="number" name="comision" step="0.01" min="0" value="0" class="input">
                    </div>
                    <div>
                        <label class="label">Fecha</label>
                        <input type="date" name="fecha" class="input" value="{{ now()->toDateString() }}">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="label">Concepto *</label>
                        <input type="text" name="concepto" required maxlength="120" class="input" placeholder="Ej: Reposición de caja desde BAC">
                    </div>
                    <div>
                        <label class="label">Referencia bancaria</label>
                        <input type="text" name="referencia_bancaria" class="input" placeholder="Nº de confirmación / SPEI">
                    </div>
                </div>

                <div class="pt-2 flex justify-end gap-3">
                    <button type="button" @click="cerrarModal()" class="btn btn-secondary">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Transferir y contabilizar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==================== MODAL: CARGO / ABONO ==================== -->
    <div x-show="modal === 'cargo' || modal === 'abono'" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/70 p-4 overflow-y-auto">
        <div class="relative bg-white rounded-xl shadow-2xl max-w-lg w-full p-6" @click.outside="cerrarModal()">
            <h3 class="text-xl font-bold text-gray-900 mb-1" x-text="modal === 'cargo' ? 'Registrar Cargo Bancario' : 'Registrar Abono Bancario'"></h3>
            <p class="text-sm text-gray-500 mb-4">
                <span x-show="modal === 'cargo'">Comisiones, mantenimiento, cheques devueltos. El asiento lleva el cargo a la cuenta de gasto indicada (contable). Default: 6.9 Comisiones y Cargos Bancarios.</span>
                <span x-show="modal === 'abono'">Intereses o devoluciones que el banco acredita. El asiento acredita la cuenta de ingreso indicada. Default: 4.7 Ingresos por Intereses.</span>
            </p>

            <form :action="modal === 'cargo' ? '{{ url('/bancos/cargo') }}' : '{{ url('/bancos/abono') }}'" method="POST" class="space-y-4">
                @csrf
                <div>
                    <label class="label">Cuenta bancaria afectada *</label>
                    <select name="cuenta_bancaria_id" x-model="directo.cuenta" required class="input">
                        <option value="">— Seleccione —</option>
                        @foreach($cuentas as $c)
                            <option value="{{ $c->id }}">{{ $c->etiqueta() }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1" x-show="directo.cuenta" x-text="etiquetaCuentaSeleccionada" x-cloak></p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">Monto *</label>
                        <input type="number" name="monto" step="0.01" min="0.01" required class="input font-black text-lg">
                    </div>
                    <div>
                        <label class="label">Fecha</label>
                        <input type="date" name="fecha" class="input" value="{{ now()->toDateString() }}">
                    </div>
                </div>

                <div>
                    <label class="label">Cuenta contable *</label>
                    <select name="cuenta_contable_id" required class="input">
                        <option value="">— Seleccione —</option>
                        <optgroup label="Recomendadas">
                            <option value="{{ $cuentaCargoDefault->id ?? '' }}" x-show="modal === 'cargo'">6.9 — Comisiones y Cargos Bancarios</option>
                            <option value="{{ $cuentaAbonoDefault->id ?? '' }}" x-show="modal === 'abono'">4.7 — Ingresos por Intereses y Rendimientos</option>
                        </optgroup>
                        <optgroup :label="modal === 'cargo' ? 'Gastos y costos' : 'Ingresos'">
                            @foreach($cuentasGasto as $g)
                                <option value="{{ $g->id }}" x-show="modal === 'cargo'">{{ $g->codigo }} — {{ $g->nombre }}</option>
                            @endforeach
                            @foreach($cuentasIngreso as $i)
                                <option value="{{ $i->id }}" x-show="modal === 'abono'">{{ $i->codigo }} — {{ $i->nombre }}</option>
                            @endforeach
                        </optgroup>
                    </select>
                </div>

                <div>
                    <label class="label">Concepto *</label>
                    <input type="text" name="concepto" required maxlength="150" class="input" placeholder="Ej: Comisión por transferencia SPEI 0412">
                </div>

                <div>
                    <label class="label">Referencia del extracto</label>
                    <input type="text" name="referencia_banco" class="input">
                </div>

                <div class="pt-2 flex justify-end gap-3">
                    <button type="button" @click="cerrarModal()" class="btn btn-secondary">Cancelar</button>
                    <button type="submit" class="btn" :class="modal === 'cargo' ? 'btn-danger' : 'btn-primary'"
                            x-text="modal === 'cargo' ? 'Registrar cargo' : 'Registrar abono'"></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function bancoDashboard() {
        return {
            modal: null,
            cajaActiva: @js((bool) $cajaActiva),
            deposito: { cuenta: '', moneda: 'nio' },
            transferir: { origen: '', destino: '' },
            directo: { cuenta: '', esUsd: false },
            etiquetas: @js($cuentas->mapWithKeys(fn ($c) => [strval($c->id) => $c->etiqueta()])->all()),

            get etiquetaCuentaSeleccionada() {
                return this.etiquetas[String(this.directo.cuenta)] || '';
            },

            abrirModal(nombre) {
                this.modal = nombre;
            },

            cerrarModal() {
                this.modal = null;
                this.deposito = { cuenta: '', moneda: 'nio' };
                this.directo = { cuenta: '', esUsd: false };
            },

            prepararCargo(id, etiqueta, esUsd) {
                this.directo = { cuenta: String(id), esUsd };
                this.modal = 'cargo';
            },

            prepararAbono(id, etiqueta, esUsd) {
                this.directo = { cuenta: String(id), esUsd };
                this.modal = 'abono';
            },
        };
    }
</script>
@endsection
