@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto space-y-6" x-data="{ tab: 'ventas' }">

    <!-- Encabezado y Filtros -->
    <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col md:flex-row justify-between items-center">
        <div class="mb-4 md:mb-0">
            <h2 class="text-3xl font-extrabold text-gray-900">Reportes Gerenciales</h2>
            <p class="text-gray-500 text-sm mt-1">Análisis financiero, rendimiento y valoración de stock.</p>
        </div>
        
        <form method="GET" action="{{ url('/reportes') }}" class="flex flex-wrap items-end gap-3 bg-gray-50 p-3 rounded-xl border border-gray-200">
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Desde</label>
                <input type="date" name="start_date" value="{{ $fechaInicio->format('Y-m-d') }}" class="text-sm border-gray-300 rounded focus:ring-emerald-500">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Hasta</label>
                <input type="date" name="end_date" value="{{ $fechaFin->format('Y-m-d') }}" class="text-sm border-gray-300 rounded focus:ring-emerald-500">
            </div>
            
            <!-- Botón de Filtrar en Pantalla -->
            <button type="submit" class="bg-gray-900 hover:bg-black text-white font-bold py-2 px-4 rounded shadow transition">
                Filtrar Pantalla
            </button>

            <!-- Separador Visual -->
            <div class="h-8 w-px bg-gray-300 mx-2 hidden md:block"></div>

            <!-- Botones de Exportación (Usan las mismas fechas) -->
            <button type="submit" formaction="{{ url('/reportes/excel') }}" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded shadow transition flex items-center">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                Excel
            </button>
            <button type="submit" formtarget="_blank" formaction="{{ url('/reportes/pdf') }}" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded shadow transition flex items-center">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path></svg>
                PDF
            </button>
        </form>
    </div>

    <!-- Navegación de Pestañas -->
    <div class="flex space-x-1 bg-white p-1 rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
        <button @click="tab = 'ventas'" :class="tab === 'ventas' ? 'bg-emerald-50 text-emerald-700 shadow-sm border-emerald-200' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50 border-transparent'" class="flex-1 py-3 px-4 rounded-lg font-bold text-sm border transition">
            📈 Resumen de Ventas
        </button>
        <button @click="tab = 'rendimiento'" :class="tab === 'rendimiento' ? 'bg-blue-50 text-blue-700 shadow-sm border-blue-200' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50 border-transparent'" class="flex-1 py-3 px-4 rounded-lg font-bold text-sm border transition">
            💇‍♀️ Rendimiento del Personal
        </button>
        <button @click="tab = 'inventario'" :class="tab === 'inventario' ? 'bg-purple-50 text-purple-700 shadow-sm border-purple-200' : 'text-gray-500 hover:text-gray-700 hover:bg-gray-50 border-transparent'" class="flex-1 py-3 px-4 rounded-lg font-bold text-sm border transition">
            📦 Valoración de Inventario
        </button>
    </div>

    <!-- CONTENIDO DE LAS PESTAÑAS -->

    <!-- TAB 1: VENTAS -->
    <div x-show="tab === 'ventas'" class="space-y-6" style="display: none;" x-transition>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 border-l-4 border-l-emerald-500">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-1">Ingresos Totales (Netos)</p>
                <h3 class="text-3xl font-black text-emerald-600">C$ {{ number_format($totalVentas, 2) }}</h3>
                <p class="text-xs text-gray-400 mt-2">En el rango de fechas seleccionado</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 border-l-4 border-l-blue-500">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-1">Tickets Emitidos</p>
                <h3 class="text-3xl font-black text-gray-900">{{ $cantidadFacturas }}</h3>
                <p class="text-xs text-gray-400 mt-2">Facturas cobradas con éxito</p>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 border-l-4 border-l-red-500">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-1">Descuentos Otorgados</p>
                <h3 class="text-3xl font-black text-red-500">C$ {{ number_format($totalDescuentos, 2) }}</h3>
                <p class="text-xs text-gray-400 mt-2">Dinero exonerado en caja</p>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-bold text-gray-900 mb-4 border-b pb-2">Distribución por Método de Pago</h3>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                @foreach($ventasPorMetodo as $metodo => $monto)
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-100 text-center">
                        <p class="text-xs font-bold text-gray-500 uppercase">{{ $metodo }}</p>
                        <p class="text-lg font-black text-gray-900 mt-1">C$ {{ number_format($monto, 2) }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <!-- TAB 2: RENDIMIENTO ESTILISTAS -->
    <div x-show="tab === 'rendimiento'" class="space-y-6" style="display: none;" x-transition>
        <div class="bg-white shadow-sm border border-gray-200 rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                <h3 class="text-lg font-bold text-gray-900">Ranking de Producción por Colaborador</h3>
            </div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-white">
                    <tr>
                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Pos.</th>
                        <th class="px-6 py-3 text-left font-bold tracking-wider uppercase text-xs text-gray-500">Colaborador</th>
                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Total Producido (Ventas)</th>
                        <th class="px-6 py-3 text-right font-bold tracking-wider uppercase text-xs text-gray-500">Comisiones Generadas</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse($estilistas as $index => $estilista)
                        <tr class="hover:bg-gray-50 transition">
                            <td class="px-6 py-4 font-black text-gray-400">#{{ $index + 1 }}</td>
                            <td class="px-6 py-4 font-bold text-gray-900">{{ $estilista->name }}</td>
                            <td class="px-6 py-4 text-right font-black text-blue-600">C$ {{ number_format($estilista->comisiones_generadas_sum_monto_venta, 2) }}</td>
                            <td class="px-6 py-4 text-right font-black text-emerald-600">C$ {{ number_format($estilista->comisiones_generadas_sum_monto_comision, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-8 text-center text-gray-500 font-medium">No hay ventas registradas para el personal en este rango de fechas.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- TAB 3: INVENTARIO -->
    <div x-show="tab === 'inventario'" class="space-y-6" style="display: none;" x-transition>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 border-l-4 border-l-purple-500 flex items-center justify-between">
                <div>
                    <p class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-1">Capital en Inventario Físico</p>
                    <h3 class="text-3xl font-black text-purple-600">C$ {{ number_format($valorInventario, 2) }}</h3>
                    <p class="text-xs text-gray-400 mt-2">Costo total de la mercadería en bodega</p>
                </div>
                <div class="p-4 bg-purple-50 rounded-full text-purple-500">
                    <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 border-l-4 border-l-red-500">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-1">Alertas de Stock</p>
                <h3 class="text-3xl font-black text-red-500">{{ $articulosCriticos->count() }} <span class="text-lg text-gray-600">productos críticos</span></h3>
                <p class="text-xs text-gray-400 mt-2">Artículos por debajo del nivel mínimo permitido</p>
            </div>
        </div>

        @if($articulosCriticos->count() > 0)
        <div class="bg-red-50 border border-red-200 rounded-xl overflow-hidden">
            <div class="px-6 py-3 border-b border-red-200 bg-red-100">
                <h3 class="text-sm font-bold text-red-800">Atención: Productos que necesitan reabastecimiento</h3>
            </div>
            <table class="min-w-full divide-y divide-red-200 text-sm">
                <tbody class="divide-y divide-red-100 bg-white">
                    @foreach($articulosCriticos as $critico)
                        <tr>
                            <td class="px-6 py-3 font-bold text-gray-900">{{ $critico->codigo }} - {{ $critico->producto }}</td>
                            <td class="px-6 py-3 text-right">
                                <span class="bg-red-100 text-red-800 font-bold px-2 py-1 rounded text-xs">Stock Actual: {{ $critico->existencia_actual }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

</div>
@endsection