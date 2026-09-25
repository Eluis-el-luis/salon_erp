@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto space-y-6">
    <div class="card p-6">
        <h2 class="page-title">Mis Comisiones</h2>
        <p class="page-subtitle">Historial de comisiones generadas y su estado.</p>
    </div>

    @if($errors->any())
        <div class="card p-4 bg-red-50 border-red-200">
            @foreach($errors->all() as $error)
                <p class="text-sm text-red-700 font-semibold">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="card overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-white">
                <tr>
                    <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Fecha</th>
                    <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Venta</th>
                    <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Servicio</th>
                    <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Monto Venta</th>
                    <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">% Comisión</th>
                    <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Comisión</th>
                    <th class="px-6 py-3 text-center font-bold tracking-wider uppercase text-xs text-gray-500">Estado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white">
                @forelse($comisiones as $comision)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3 text-gray-600 font-medium whitespace-nowrap">{{ \Carbon\Carbon::parse($comision->fecha)->format('d/m/Y') }}</td>
                        <td class="px-6 py-3 text-gray-900 font-medium">#{{ $comision->venta_id }}</td>
                        <td class="px-6 py-3 text-gray-600">{{ $comision->venta?->detalles->first()?->servicio?->name ?? 'Servicio' }}</td>
                        <td class="px-6 py-3 text-right font-bold text-gray-900">C$ {{ number_format($comision->monto_venta, 2) }}</td>
                        <td class="px-6 py-3 text-right text-gray-600">{{ $comision->porcentaje_aplicado }}%</td>
                        <td class="px-6 py-3 text-right font-bold text-emerald-600">C$ {{ number_format($comision->monto_comision, 2) }}</td>
                        <td class="px-6 py-3 text-center">
                            @if($comision->estado === 'pagada')
                                <span class="px-2 py-1 rounded text-xs font-bold bg-emerald-100 text-emerald-800">Pagada</span>
                            @else
                                <span class="px-2 py-1 rounded text-xs font-bold bg-amber-100 text-amber-800">Pendiente</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-8 text-center text-gray-500 font-medium">No hay comisiones en el periodo.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        {{ $comisiones->links() }}
    </div>
</div>
@endsection