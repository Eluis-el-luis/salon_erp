@extends('layouts.app')

@section('content')
<div class="max-w-5xl mx-auto space-y-6" x-data="asientoForm()">

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="page-title">Nuevo Asiento Manual</h1>
            <p class="page-subtitle">Crear asiento contable manual con validación de partida doble</p>
        </div>
        <a href="{{ route('asientos.index') }}" class="btn btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
            Volver al Listado
        </a>
    </div>

    <!-- Errores de validación del servidor -->
    @if($errors->any())
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
            <ul class="list-disc pl-5 space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Formulario -->
    <form method="POST" action="{{ route('asientos.store') }}" class="space-y-6">
        @csrf

        <!-- Cabecera -->
        <div class="card p-6 space-y-4">
            <h2 class="text-lg font-bold text-gray-800">Datos del Asiento</h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="label">Fecha *</label>
                    <input type="date" name="fecha" x-model="fecha" :value="fecha" required class="input">
                </div>
                <div class="md:col-span-2">
                    <label class="label">Concepto *</label>
                    <input type="text" name="concepto" x-model="concepto" placeholder="Descripción del asiento..." required class="input" maxlength="255">
                </div>
            </div>
        </div>

        <!-- Líneas del Asiento -->
        <div class="card">
            <div class="card-header flex justify-between items-center">
                <h3 class="text-lg font-bold text-gray-800">Líneas del Asiento (Partida Doble)</h3>
                <div class="text-xs text-gray-500">DEBE = HABER (validación automática)</div>
            </div>

            <div class="p-6">
                <!-- Encabezados de tabla -->
                <div class="grid grid-cols-12 gap-2 px-2 py-2 bg-gray-50 rounded-t-lg font-bold text-xs text-gray-500 uppercase tracking-wider">
                    <div class="col-span-1 text-center">#</div>
                    <div class="col-span-3">Cuenta</div>
                    <div class="col-span-2">Centro Costo (opcional)</div>
                    <div class="col-span-2 text-right">DEBE (C$)</div>
                    <div class="col-span-2 text-right">HABER (C$)</div>
                    <div class="col-span-2">Descripción</div>
                    <div class="col-span-1 text-center">Acciones</div>
                </div>

                <!-- Líneas del asiento -->
                <div x-show="lineas.length === 0" class="py-8 text-center text-gray-400 text-sm">
                    No hay líneas agregadas. Agregue al menos una línea en DEBE y una en HABER.
                </div>

                <template x-for="(linea, index) in lineas" :key="linea.id">
                    <div class="grid grid-cols-12 gap-2 px-2 py-2 border-b border-gray-100 items-center">
                        <div class="col-span-1 text-center text-gray-500 text-xs font-mono" x-text="index + 1"></div>

                        <div class="col-span-3">
                            <select class="input w-full bg-gray-100 cursor-not-allowed" disabled>
                                <option x-text="linea.cuenta_codigo + ' - ' + linea.cuenta_nombre"></option>
                            </select>
                        </div>

                        <div class="col-span-2">
                            <select x-model="lineas[index].centro_costo_id" class="input">
                                <option value="">Sin centro de costo</option>
                                <template x-for="cc in centrosCosto" :key="cc.id">
                                    <option :value="cc.id" x-text="cc.codigo + ' - ' + cc.nombre"></option>
                                </template>
                            </select>
                        </div>

                        <div class="col-span-2">
                            <input type="number" x-model.number="lineas[index].debe" step="0.01" min="0" class="input w-full text-right" :disabled="lineas[index].haber > 0" placeholder="0.00">
                        </div>

                        <div class="col-span-2">
                            <input type="number" x-model.number="lineas[index].haber" step="0.01" min="0" class="input w-full text-right" :disabled="lineas[index].debe > 0" placeholder="0.00">
                        </div>

                        <div class="col-span-2">
                            <input type="text" x-model="lineas[index].descripcion" class="input" placeholder="Descripción (opcional)" maxlength="255">
                        </div>

                        <div class="col-span-1 flex justify-center">
                            <button type="button" @click="eliminarLinea(index)" class="text-red-500 hover:text-red-700 p-1.5 hover:bg-red-50 rounded-lg" title="Eliminar línea">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            </button>
                        </div>

                        <!-- Inputs ocultos que se envían al servidor -->
                        <input type="hidden" :name="'lineas[' + index + '][cuenta_id]'" :value="linea.cuenta_id">
                        <input type="hidden" :name="'lineas[' + index + '][centro_costo_id]'" :value="linea.centro_costo_id">
                        <input type="hidden" :name="'lineas[' + index + '][debe]'" :value="linea.debe">
                        <input type="hidden" :name="'lineas[' + index + '][haber]'" :value="linea.haber">
                        <input type="hidden" :name="'lineas[' + index + '][descripcion]'" :value="linea.descripcion">
                    </div>
                </template>

                <!-- Fila para agregar línea -->
                <div class="grid grid-cols-12 gap-2 px-2 py-3 border-t border-gray-200 items-center">
                    <div class="col-span-1 text-center text-gray-500 text-xs font-mono" x-text="lineas.length + 1"></div>
                    <div class="col-span-3">
                        <select x-model="nuevaCuentaId" class="input">
                            <option value="">+ Agregar línea...</option>
                            <template x-for="cuenta in cuentas" :key="cuenta.id">
                                <option :value="cuenta.id" x-text="cuenta.codigo + ' - ' + cuenta.nombre"></option>
                            </template>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <select x-model="nuevoCentroCostoId" class="input">
                            <option value="">Sin centro</option>
                            <template x-for="cc in centrosCosto" :key="cc.id">
                                <option :value="cc.id" x-text="cc.codigo + ' - ' + cc.nombre"></option>
                            </template>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <input type="number" step="0.01" min="0" x-model.number="nuevoDebe" class="input w-full text-right" placeholder="0.00">
                    </div>
                    <div class="col-span-2">
                        <input type="number" step="0.01" min="0" x-model.number="nuevoHaber" class="input w-full text-right" placeholder="0.00">
                    </div>
                    <div class="col-span-2">
                        <input type="text" x-model="nuevaDescripcion" class="input" placeholder="Descripción">
                    </div>
                    <div class="col-span-1 flex justify-center">
                        <button type="button" @click="agregarLinea" class="btn btn-primary py-1.5" :disabled="!nuevaCuentaId || (nuevoDebe <= 0 && nuevoHaber <= 0)">Agregar</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Totales y Validación -->
        <div class="card p-4 bg-gray-50 border-t border-gray-200">
            <div class="grid grid-cols-3 gap-4 text-center">
                <div class="bg-red-50 p-4 rounded-lg border border-red-200">
                    <p class="text-xs font-bold text-gray-500 uppercase">TOTAL DEBE</p>
                    <p class="text-2xl font-black text-red-600">C$ <span x-text="totales.debe.toFixed(2)"></span></p>
                </div>
                <div class="bg-emerald-50 p-4 rounded-lg border border-emerald-200">
                    <p class="text-xs font-bold text-gray-500 uppercase">TOTAL HABER</p>
                    <p class="text-2xl font-black text-emerald-600">C$ <span x-text="totales.haber.toFixed(2)"></span></p>
                </div>
                <div class="p-4 rounded-lg border-2" :class="diferencia === 0 ? 'bg-emerald-50 border-emerald-200' : 'bg-red-50 border-red-200'">
                    <p class="text-xs font-bold text-gray-500 uppercase">DIFERENCIA</p>
                    <p class="text-2xl font-black" :class="diferencia === 0 ? 'text-emerald-600' : 'text-red-600'">C$ <span x-text="diferencia.toFixed(2)"></span></p>
                </div>
            </div>
        </div>

        <!-- Botones -->
        <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
            <a href="{{ route('asientos.index') }}" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary" :disabled="!esValido">
                Guardar Asiento
            </button>
        </div>
    </form>
</div>

@push('scripts')
<script>
    function asientoForm() {
        return {
            lineas: [],
            cuentas: @json($cuentas),
            centrosCosto: @json($centrosCosto),
            fecha: new Date().toISOString().slice(0, 10),
            concepto: '',
            nuevaCuentaId: '',
            nuevoCentroCostoId: '',
            nuevoDebe: 0,
            nuevoHaber: 0,
            nuevaDescripcion: '',

            get totales() {
                const debe = this.lineas.reduce((s, l) => s + (Number(l.debe) || 0), 0);
                const haber = this.lineas.reduce((s, l) => s + (Number(l.haber) || 0), 0);
                return { debe, haber };
            },

            get diferencia() {
                return Math.round(Math.abs(this.totales.debe - this.totales.haber) * 100) / 100;
            },

            get esValido() {
                if (this.lineas.length < 2) return false;
                const tieneDebe = this.lineas.some(l => (Number(l.debe) || 0) > 0);
                const tieneHaber = this.lineas.some(l => (Number(l.haber) || 0) > 0);
                return tieneDebe && tieneHaber && this.diferencia < 0.01;
            },

            agregarLinea() {
                if (!this.nuevaCuentaId) return;
                const cuenta = this.cuentas.find(c => c.id == this.nuevaCuentaId);
                if (!cuenta) return;

                this.lineas.push({
                    id: Date.now(),
                    cuenta_id: this.nuevaCuentaId,
                    cuenta_nombre: cuenta.nombre,
                    cuenta_codigo: cuenta.codigo,
                    centro_costo_id: this.nuevoCentroCostoId || '',
                    debe: Number(this.nuevoDebe) || 0,
                    haber: Number(this.nuevoHaber) || 0,
                    descripcion: this.nuevaDescripcion || '',
                });

                this.nuevaCuentaId = '';
                this.nuevoCentroCostoId = '';
                this.nuevoDebe = 0;
                this.nuevoHaber = 0;
                this.nuevaDescripcion = '';
            },

            eliminarLinea(index) {
                this.lineas.splice(index, 1);
            },
        }
    }
</script>
@endpush
@endsection
