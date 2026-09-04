<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte Gerencial - Álvaro Rugama</title>
    @vite(['resources/css/app.css'])
    <style>
        /* Instrucciones para que la impresora respete los colores y márgenes */
        @media print {
            body { background-color: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
            @page { margin: 1cm; size: A4; }
        }
    </style>
</head>
<body class="bg-gray-200 text-gray-900 p-8" onload="window.print()">

    <!-- Botón para regresar si cancelan la impresión -->
    <div class="max-w-4xl mx-auto mb-4 no-print">
        <button onclick="window.close()" class="text-sm font-bold text-gray-600 hover:text-gray-900 bg-white px-4 py-2 rounded shadow">
            ← Cerrar esta ventana
        </button>
    </div>

    <div class="max-w-4xl mx-auto bg-white p-12 shadow-xl rounded-xl print:shadow-none print:p-0">
        
        <!-- Cabecera Corporativa -->
        <div class="border-b-2 border-gray-800 pb-4 mb-8 text-center">
            <h1 class="text-3xl font-black uppercase tracking-widest text-gray-900">Álvaro Rugama</h1>
            <p class="text-sm font-bold text-gray-500 uppercase mt-1">Reporte Gerencial Consolidado</p>
            <p class="text-xs text-gray-400 mt-2 font-medium">
                PERIODO: {{ $fechaInicio->format('d/m/Y') }} AL {{ $fechaFin->format('d/m/Y') }}
            </p>
        </div>

        <!-- Sección 1: Resumen de Ventas -->
        <div class="mb-10">
            <h2 class="text-lg font-black bg-gray-800 text-white px-4 py-2 mb-4 uppercase">1. Resumen de Ingresos Netos</h2>
            
            <div class="grid grid-cols-2 gap-6">
                <div class="border border-gray-300 p-5 rounded-lg">
                    <p class="text-xs font-bold text-gray-500 uppercase">Ventas Totales en el Periodo</p>
                    <p class="text-3xl font-black text-emerald-600 mt-1">C$ {{ number_format($totalVentas, 2) }}</p>
                </div>
                <div class="border border-gray-300 p-5 rounded-lg">
                    <p class="text-xs font-bold text-gray-500 uppercase mb-2">Desglose por Banco / Método</p>
                    <ul class="text-sm space-y-1 font-medium text-gray-700">
                        <li class="flex justify-between border-b border-gray-100 pb-1"><span>Efectivo (Caja):</span> <span class="font-bold">C$ {{ number_format($ventasPorMetodo['efectivo'], 2) }}</span></li>
                        <li class="flex justify-between border-b border-gray-100 pb-1 pt-1"><span>Banco BAC:</span> <span class="font-bold">C$ {{ number_format($ventasPorMetodo['bac'], 2) }}</span></li>
                        <li class="flex justify-between pt-1"><span>Banco LAFISE:</span> <span class="font-bold">C$ {{ number_format($ventasPorMetodo['lafise'], 2) }}</span></li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Sección 2: Rendimiento Estilistas -->
        <div class="mb-10">
            <h2 class="text-lg font-black bg-gray-800 text-white px-4 py-2 mb-4 uppercase">2. Rendimiento y Comisiones del Personal</h2>
            
            <table class="w-full text-sm border-collapse border border-gray-300">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="border border-gray-300 px-4 py-3 text-left font-bold text-gray-700">Colaborador</th>
                        <th class="border border-gray-300 px-4 py-3 text-right font-bold text-gray-700">Ventas Producidas</th>
                        <th class="border border-gray-300 px-4 py-3 text-right font-bold text-gray-700">Comisión Generada</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($estilistas as $estilista)
                    <tr>
                        <td class="border border-gray-300 px-4 py-2 font-bold text-gray-900">{{ $estilista->name }}</td>
                        <td class="border border-gray-300 px-4 py-2 text-right text-gray-700">C$ {{ number_format($estilista->comisiones_generadas_sum_monto_venta, 2) }}</td>
                        <td class="border border-gray-300 px-4 py-2 text-right text-emerald-700 font-bold">C$ {{ number_format($estilista->comisiones_generadas_sum_monto_comision, 2) }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="3" class="border border-gray-300 px-4 py-6 text-center text-gray-500 italic">No hay producción registrada en este periodo.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Footer -->
        <div class="text-center text-xs text-gray-400 mt-16 border-t border-gray-200 pt-6">
            Documento generado por el Sistema ERP - Fecha de impresión: {{ \Carbon\Carbon::now()->format('d/m/Y h:i A') }}
        </div>
        
    </div>
</body>
</html>