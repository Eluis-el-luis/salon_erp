@extends('layouts.app')

@section('content')
<div x-data="{ tipo: 'unidad' }" class="max-w-6xl mx-auto space-y-6">

    <div class="card p-6">
        <h2 class="page-title">Mermas y Ajustes de Inventario</h2>
        <p class="page-subtitle">Registra productos dañados, vencidos o derramados. Se descuenta del stock y se contabiliza como gasto por merma.</p>
    </div>

    @if(isset($errors) && $errors->any())
        <div class="card p-4 bg-red-50 border-red-200">
            @foreach($errors->all() as $error)
                <p class="text-sm text-red-700 font-semibold">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Formulario -->
        <div class="card p-6 h-fit">
            <h3 class="text-lg font-bold text-gray-800 mb-4">Registrar Merma</h3>
            <form action="{{ url('/mermas') }}" method="POST" class="space-y-4">
                @csrf

                <div>
                    <label class="label" for="item_id">Producto / Insumo</label>
                    <select id="item_id" name="item_id" required class="input">
                        <option value="">Selecciona un artículo...</option>
                        @foreach($articulos as $articulo)
                            <option value="{{ $articulo->id }}">
                                {{ $articulo->producto }} ({{ $articulo->is_fractionable ? 'Fraccionable / ' . $articulo->unit_measure : 'Unidad' }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="label">Tipo de Merma</label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="flex items-center justify-center gap-2 border rounded-lg px-3 py-2.5 cursor-pointer text-sm font-semibold transition"
                               :class="tipo === 'unidad' ? 'bg-emerald-50 border-emerald-300 text-emerald-700' : 'bg-white border-gray-300 text-gray-600'">
                            <input type="radio" name="tipo" value="unidad" x-model="tipo" class="sr-only">
                            Unidad
                        </label>
                        <label class="flex items-center justify-center gap-2 border rounded-lg px-3 py-2.5 cursor-pointer text-sm font-semibold transition"
                               :class="tipo === 'volumen' ? 'bg-emerald-50 border-emerald-300 text-emerald-700' : 'bg-white border-gray-300 text-gray-600'">
                            <input type="radio" name="tipo" value="volumen" x-model="tipo" class="sr-only">
                            Volumen (ml/oz)
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="label" for="cantidad">Cantidad</label>
                        <input id="cantidad" type="number" step="0.01" min="0.01" name="cantidad" required class="input">
                    </div>
                    <div>
                        <label class="label" for="fecha">Fecha</label>
                        <input id="fecha" type="date" name="fecha" value="{{ \Carbon\Carbon::today()->toDateString() }}" required class="input">
                    </div>
                </div>

                <div>
                    <label class="label" for="motivo">Motivo</label>
                    <input id="motivo" type="text" name="motivo" required placeholder="Ej. Producto vencido, derrame, daño..." class="input">
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    Registrar Merma
                </button>
            </form>
        </div>

        <!-- Historial -->
        <div class="card overflow-hidden lg:col-span-2">
            <div class="card-header bg-gray-50">
                <h3 class="text-lg font-bold text-gray-900">Historial de Mermas</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-white">
                        <tr>
                            <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Fecha</th>
                            <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Producto</th>
                            <th class="px-6 py-3 text-center font-bold tracking-wider uppercase text-xs text-gray-500">Tipo</th>
                            <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Cantidad</th>
                            <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Valor (C$)</th>
                            <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Motivo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @forelse($mermas as $merma)
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-3 text-gray-600 whitespace-nowrap">{{ \Carbon\Carbon::parse($merma->fecha)->format('d/m/Y') }}</td>
                                <td class="px-6 py-3 font-bold text-gray-900">{{ $merma->articulo->producto ?? '—' }}</td>
                                <td class="px-6 py-3 text-center">
                                    <span class="px-2 py-1 rounded text-xs font-bold {{ $merma->tipo == 'volumen' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700' }}">
                                        {{ $merma->tipo == 'volumen' ? 'Volumen' : 'Unidad' }}
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-right font-bold text-gray-900">{{ number_format($merma->cantidad, 2) }}</td>
                                <td class="px-6 py-3 text-right font-bold text-red-600">C$ {{ number_format($merma->valor, 2) }}</td>
                                <td class="px-6 py-3 text-gray-600">{{ $merma->motivo }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-500">No hay mermas registradas.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</div>

@if(session('success'))
<script>
    document.addEventListener('DOMContentLoaded', function() {
        window.dispatchEvent(new CustomEvent('notify', { detail: '{{ session('success') }}' }));
    });
</script>
@endif
@endsection