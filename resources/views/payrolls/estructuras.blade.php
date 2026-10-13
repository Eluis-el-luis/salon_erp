@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div>
        <h2 class="page-title">Estructuras Salariales</h2>
        <p class="page-subtitle">Plantillas de ingresos y deducciones que el motor de nómina calcula dinámicamente por colaborador y período.</p>
    </div>

    @if(session('success'))
        <div class="bg-emerald-100 border-l-4 border-emerald-500 text-emerald-700 p-4 rounded font-bold">✓ {{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded font-bold">{{ $errors->first() }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Nueva estructura --}}
        <div class="card p-6" x-data="editorEstructura()">
            <h3 class="font-black text-gray-800 border-b pb-2 mb-4">Nueva Estructura</h3>
            <form action="/nomina/estructuras" method="POST" @submit="sincronizar()">
                @csrf
                <div class="space-y-3 mb-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Nombre</label>
                        <input type="text" name="nombre" required maxlength="120" class="w-full border border-gray-300 rounded-md py-2 px-3" placeholder="Ej. Estilistas Senior">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Descripción</label>
                        <textarea name="descripcion" rows="2" maxlength="1000" class="w-full border border-gray-300 rounded-md py-2 px-3"></textarea>
                    </div>
                </div>

                <p class="text-xs font-black text-gray-500 uppercase mb-2">Líneas de la plantilla</p>
                <template x-for="(linea, i) in lineas" :key="i">
                    <div class="border border-gray-200 rounded-lg p-3 mb-2 space-y-2 bg-gray-50">
                        <div class="grid grid-cols-2 gap-2">
                            <input type="text" x-model="linea.codigo" placeholder="CÓDIGO" maxlength="50" class="border border-gray-300 rounded py-1.5 px-2 text-sm uppercase">
                            <input type="text" x-model="linea.nombre" placeholder="Etiqueta legible" maxlength="150" class="border border-gray-300 rounded py-1.5 px-2 text-sm">
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <select x-model="linea.tipo" class="border border-gray-300 rounded py-1.5 px-2 text-sm">
                                <option value="ingreso">Ingreso</option>
                                <option value="deduccion">Deducción</option>
                            </select>
                            <select x-model="linea.calculadora" class="col-span-2 border border-gray-300 rounded py-1.5 px-2 text-sm">
                                <template x-for="(desc, clave) in calculadoras">
                                    <option :value="clave" x-text="clave + ' — ' + desc"></option>
                                </template>
                            </select>
                        </div>
                        <div class="flex gap-2 items-center">
                            <input type="number" x-model.number="linea.valor" step="0.0001" placeholder="Valor" class="w-28 border border-gray-300 rounded py-1.5 px-2 text-sm">
                            <input type="number" x-model.number="linea.orden" placeholder="Orden" min="0" class="w-20 border border-gray-300 rounded py-1.5 px-2 text-sm">
                            <button type="button" @click="lineas.splice(i, 1)" class="ml-auto text-red-500 font-bold text-sm hover:underline">Quitar</button>
                        </div>
                    </div>
                </template>
                <button type="button" @click="agregarLinea()" class="w-full border-2 border-dashed border-gray-300 rounded-lg py-2 text-sm font-bold text-gray-500 hover:border-emerald-400 hover:text-emerald-600">+ Agregar línea</button>

                <input type="hidden" name="lineas" :value="jsonLineas">
                <button type="submit" class="btn btn-primary w-full mt-4">Guardar Estructura</button>
            </form>
        </div>

        {{-- Listado --}}
        <div class="lg:col-span-2 space-y-4">
            @forelse($estructuras as $estructura)
                <div class="card overflow-hidden">
                    <div class="card-header flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 class="font-black text-gray-800">{{ $estructura->nombre }}</h3>
                            <p class="text-xs text-gray-500">{{ $estructura->empleados->count() }} colaborador(es) asignado(s)</p>
                        </div>
                        <form action="/nomina/estructuras/{{ $estructura->id }}/toggle" method="POST">
                            @csrf
                            <button type="submit" class="px-3 py-1.5 text-[11px] font-black rounded-full {{ $estructura->activa ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">
                                {{ $estructura->activa ? 'ACTIVA' : 'INACTIVA' }} · cambiar
                            </button>
                        </form>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-50 text-gray-500 uppercase text-[10px]">
                                <tr>
                                    <th class="px-4 py-2 text-left font-bold">Código</th>
                                    <th class="px-4 py-2 text-left font-bold">Concepto</th>
                                    <th class="px-4 py-2 text-center font-bold">Tipo</th>
                                    <th class="px-4 py-2 text-left font-bold">Calculadora</th>
                                    <th class="px-4 py-2 text-right font-bold">Valor</th>
                                    <th class="px-4 py-2 text-center font-bold">Orden</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($estructura->detalles as $detalle)
                                    <tr>
                                        <td class="px-4 py-2 font-mono font-bold text-gray-600">{{ $detalle->codigo }}</td>
                                        <td class="px-4 py-2 text-gray-700">{{ $detalle->nombre }}</td>
                                        <td class="px-4 py-2 text-center"><span class="px-1.5 py-0.5 rounded font-black text-[10px] {{ $detalle->tipo === 'ingreso' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' }}">{{ $detalle->tipo }}</span></td>
                                        <td class="px-4 py-2 text-gray-500">{{ $detalle->calculadora }}</td>
                                        <td class="px-4 py-2 text-right font-bold">{{ $detalle->valor != 0 ? $detalle->valor : '—' }}</td>
                                        <td class="px-4 py-2 text-center text-gray-400">{{ $detalle->orden }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($estructura->descripcion)
                        <p class="px-4 py-2 text-xs text-gray-500 border-t border-gray-100">{{ $estructura->descripcion }}</p>
                    @endif
                </div>
            @empty
                <div class="card p-8 text-center text-sm text-gray-500">No hay estructuras salariales definidas.</div>
            @endforelse
        </div>
    </div>
</div>

<script>
function editorEstructura() {
    return {
        lineas: [
            { codigo: 'SAL_BASE', nombre: 'Salario Base del Período', tipo: 'ingreso', calculadora: 'salario_base', valor: 0, orden: 10 },
            { codigo: 'COM_VENT', nombre: 'Comisiones de Ventas', tipo: 'ingreso', calculadora: 'comisiones', valor: 0, orden: 20 },
            { codigo: 'PROPINAS', nombre: 'Propinas Acumuladas', tipo: 'ingreso', calculadora: 'propinas', valor: 0, orden: 30 },
            { codigo: 'HORAS_EXT', nombre: 'Horas Extras', tipo: 'ingreso', calculadora: 'horas_extras', valor: 2.0, orden: 40 },
            { codigo: 'INSS', nombre: 'INSS Laboral', tipo: 'deduccion', calculadora: 'inss', valor: 0, orden: 100 },
            { codigo: 'IR', nombre: 'Impuesto sobre la Renta', tipo: 'deduccion', calculadora: 'ir', valor: 0, orden: 110 },
            { codigo: 'SIN_GOCE', nombre: 'Ausencias sin Goce', tipo: 'deduccion', calculadora: 'sin_goce', valor: 0, orden: 120 },
            { codigo: 'TARDANZAS', nombre: 'Deducción por Tardanzas', tipo: 'deduccion', calculadora: 'tardanzas', valor: 50, orden: 130 },
            { codigo: 'ADELANTOS', nombre: 'Amortización de Adelantos', tipo: 'deduccion', calculadora: 'adelantos', valor: 0, orden: 140 },
        ],
        jsonLineas: '',
        calculadoras: @json($calculadoras),

        agregarLinea() {
            this.lineas.push({ codigo: '', nombre: '', tipo: 'ingreso', calculadora: 'fijo', valor: 0, orden: 200 });
        },

        sincronizar() {
            this.lineas = this.lineas.filter(l => l.codigo && l.nombre);
            this.jsonLineas = JSON.stringify(this.lineas);
        },
    };
}
</script>
@endsection
