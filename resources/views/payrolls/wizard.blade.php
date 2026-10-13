@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div>
        <h2 class="page-title">Wizard de Nómina — Paso 1: Selección</h2>
        <p class="page-subtitle">Elija el período, la forma de pago y los colaboradores a liquidar. En el siguiente paso verá la previsualización antes de contabilizar.</p>
    </div>

    @if($errors->any())
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded font-bold">{{ $errors->first() }}</div>
    @endif

    <div class="card p-6">
        <form action="/nomina/previsualizar" method="POST" x-data="wizardSeleccion()">
            @csrf
            {{-- Período y forma de pago --}}
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Desde</label>
                    <input type="date" name="periodo_inicio" x-model="inicio" @change="sugerirFin(); filtrar()" required class="w-full border border-gray-300 rounded-md py-2 px-3">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Hasta</label>
                    <input type="date" name="periodo_fin" x-model="fin" required class="w-full border border-gray-300 rounded-md py-2 px-3">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Forma de pago</label>
                    <select name="metodo_pago" class="w-full border border-gray-300 rounded-md py-2 px-3">
                        <option value="banco">Transferencia bancaria</option>
                        <option value="efectivo">Efectivo (caja)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">Estructura (opcional)</label>
                    <select name="estructura_id" class="w-full border border-gray-300 rounded-md py-2 px-3">
                        <option value="">La asignada a cada colaborador</option>
                        @foreach($estructuras as $estructura)
                            <option value="{{ $estructura->id }}">{{ $estructura->nombre }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Acciones rápidas --}}
            <div class="flex flex-wrap gap-2 mb-3">
                <button type="button" @click="marcarTodos(true)" class="text-xs font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-3 py-1.5 rounded-lg">Marcar todos</button>
                <button type="button" @click="marcarTodos(false)" class="text-xs font-bold text-gray-600 bg-gray-100 border border-gray-200 px-3 py-1.5 rounded-lg">Desmarcar</button>
                <input type="text" x-model="busqueda" @input="filtrar()" placeholder="Buscar colaborador..." class="ml-auto border border-gray-300 rounded-md py-1.5 px-3 text-sm">
            </div>

            {{-- Tabla de selección --}}
            <div class="overflow-x-auto border border-gray-200 rounded-lg">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 w-12"></th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase">Colaborador</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase">Estructura</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">Salario base</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">Comisiones pend.</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">Adelantos pend.</th>
                            <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase">Propinas pend.</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse($empleados as $empleado)
                            <tr class="hover:bg-gray-50" data-nombre="{{ strtolower($empleado->nombre . ' ' . $empleado->codigo_empleado) }}">
                                <td class="px-4 py-3">
                                    <input type="checkbox" name="empleado_ids[]" value="{{ $empleado->id }}" x-model="seleccionados" x-init="seleccionados.push({{ $empleado->id }})" class="w-4 h-4 accent-emerald-600">
                                </td>
                                <td class="px-4 py-3">
                                    <p class="text-sm font-bold text-gray-800">{{ $empleado->nombre }}</p>
                                    <p class="text-xs text-gray-400">{{ $empleado->codigo_empleado }} · {{ ucfirst($empleado->usuario->role ?? '') }}</p>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-600">{{ $empleado->estructura_actual ?? 'Sin estructura' }}</td>
                                <td class="px-4 py-3 text-right text-sm font-bold">C$ {{ number_format((float) $empleado->salario_base, 2) }}</td>
                                <td class="px-4 py-3 text-right text-sm {{ $empleado->pendiente_comisiones > 0 ? 'text-emerald-600 font-bold' : 'text-gray-400' }}">C$ {{ number_format($empleado->pendiente_comisiones, 2) }}</td>
                                <td class="px-4 py-3 text-right text-sm {{ $empleado->pendiente_adelantos > 0 ? 'text-red-500 font-bold' : 'text-gray-400' }}">C$ {{ number_format($empleado->pendiente_adelantos, 2) }}</td>
                                <td class="px-4 py-3 text-right text-sm {{ $empleado->pendiente_propinas > 0 ? 'text-purple-600 font-bold' : 'text-gray-400' }}">C$ {{ number_format($empleado->pendiente_propinas, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-8 text-center text-sm text-gray-500">No hay colaboradores activos con expediente.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between mt-6">
                <p class="text-sm text-gray-600"><span class="font-black text-gray-800" x-text="seleccionados.length"></span> colaborador(es) seleccionado(s)</p>
                <button type="submit" :disabled="seleccionados.length === 0" class="btn btn-primary disabled:opacity-50 disabled:cursor-not-allowed">Previsualizar liquidación →</button>
            </div>
        </form>
    </div>
</div>

<script>
function wizardSeleccion() {
    return {
        seleccionados: [],
        busqueda: '',
        inicio: '',
        fin: '',

        sugerirFin() {
            if (!this.inicio) return;
            const d = new Date(this.inicio);
            d.setDate(d.getDate() + 14); // quincena sugerida
            this.fin = d.toISOString().slice(0, 10);
        },

        filtrar() {
            const q = this.busqueda.toLowerCase();
            document.querySelectorAll('tbody tr[data-nombre]').forEach(tr => {
                tr.style.display = tr.dataset.nombre.includes(q) ? '' : 'none';
            });
        },

        marcarTodos(estado) {
            document.querySelectorAll('input[name="empleado_ids[]"]').forEach(cb => {
                if (cb.closest('tr').style.display !== 'none') cb.checked = estado;
            });
        },
    };
}
</script>
@endsection
