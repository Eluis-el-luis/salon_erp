<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Colilla de Pago — {{ $colilla->empleado->nombre }}</title>
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            body { margin: 0; padding: 0; }
            .no-print { display: none; }
            .print-container { box-shadow: none !important; border: none !important; max-width: 100%; }
        }
    </style>
</head>
<body class="bg-gray-200 font-sans text-gray-900 py-8">

    <div class="max-w-md mx-auto mb-4 flex justify-between no-print">
        <a href="javascript:window.close()" class="text-sm text-gray-600 hover:text-gray-900">← Cerrar</a>
        <button onclick="window.print()" class="bg-emerald-600 text-white px-4 py-1 rounded shadow text-sm font-bold">🖨️ Imprimir Colilla</button>
    </div>

    <div class="print-container bg-white mx-auto max-w-md p-8 rounded shadow-lg border-t-8 border-emerald-600">

        <div class="text-center mb-4">
            <h1 class="text-lg font-black uppercase text-gray-800">Álvaro Rugama Make Up Studio</h1>
            <p class="text-xs font-bold tracking-widest text-gray-500 uppercase">Colilla de Pago de Nómina</p>
        </div>

        <div class="border-b border-dashed border-gray-400 mb-4"></div>

        <div class="text-xs mb-4 space-y-1.5">
            <div class="flex justify-between"><span class="font-bold">Colilla #:</span><span>SLIP-{{ str_pad($colilla->id, 6, '0', STR_PAD_LEFT) }}</span></div>
            <div class="flex justify-between"><span class="font-bold">Corrida:</span><span>#{{ $colilla->payroll_run_id }}</span></div>
            <div class="flex justify-between"><span class="font-bold">Colaborador:</span><span>{{ $colilla->empleado->nombre }} ({{ $colilla->empleado->codigo_empleado }})</span></div>
            <div class="flex justify-between"><span class="font-bold">Cargo:</span><span class="uppercase">{{ $colilla->empleado->cargo ?? $colilla->empleado->usuario->role ?? '—' }}</span></div>
            <div class="flex justify-between"><span class="font-bold">Período:</span><span>{{ $colilla->periodo_inicio->format('d/m/Y') }} al {{ $colilla->periodo_fin->format('d/m/Y') }}</span></div>
            <div class="flex justify-between"><span class="font-bold">Forma de pago:</span><span>{{ $colilla->corrida->metodo_pago === 'banco' ? 'Transferencia bancaria' : 'Efectivo' }}</span></div>
            <div class="flex justify-between"><span class="font-bold">Fecha de pago:</span><span>{{ $colilla->fecha_pago?->format('d/m/Y') }}</span></div>
            @if($colilla->asiento)
                <div class="flex justify-between"><span class="font-bold">Asiento contable:</span><span class="font-mono">{{ $colilla->asiento->numero_asiento }}</span></div>
            @endif
        </div>

        <div class="border-b border-dashed border-gray-400 mb-4"></div>

        {{-- INGRESOS --}}
        <div class="mb-4">
            <p class="text-[10px] font-black text-emerald-700 uppercase tracking-wider mb-1">Ingresos</p>
            <table class="w-full text-xs">
                <tbody>
                    @foreach($colilla->detalles->where('tipo', 'ingreso') as $linea)
                        <tr>
                            <td class="py-0.5 text-gray-700">{{ $linea->concepto }}</td>
                            <td class="py-0.5 text-right font-bold">C$ {{ number_format((float) $linea->monto, 2) }}</td>
                        </tr>
                    @endforeach
                    <tr class="border-t border-gray-300">
                        <td class="py-1 font-black uppercase">Total devengado</td>
                        <td class="py-1 text-right font-black">C$ {{ number_format((float) $colilla->total_ingresos, 2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- DEDUCCIONES --}}
        <div class="mb-4">
            <p class="text-[10px] font-black text-red-700 uppercase tracking-wider mb-1">Deducciones</p>
            <table class="w-full text-xs">
                <tbody>
                    @forelse($colilla->detalles->where('tipo', 'deduccion') as $linea)
                        <tr>
                            <td class="py-0.5 text-gray-700">{{ $linea->concepto }}</td>
                            <td class="py-0.5 text-right font-bold">-C$ {{ number_format((float) $linea->monto, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-0.5 text-gray-400">Sin deducciones</td></tr>
                    @endforelse
                    <tr class="border-t border-gray-300">
                        <td class="py-1 font-black uppercase">Total deducciones</td>
                        <td class="py-1 text-right font-black">C$ {{ number_format((float) $colilla->total_deducciones, 2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- NETO --}}
        <div class="bg-emerald-600 text-white rounded-lg p-4 flex justify-between items-center mb-4">
            <span class="text-sm font-black uppercase">Neto Recibido</span>
            <span class="text-xl font-black">C$ {{ number_format((float) $colilla->neto_pagar, 2) }}</span>
        </div>

        <div class="border-b border-dashed border-gray-400 mb-4"></div>

        <div class="flex justify-between items-end text-[10px] text-gray-500">
            <div class="text-center">
                <div class="border-t border-gray-400 w-40 pt-1">Firma del Colaborador</div>
            </div>
            <div class="text-center">
                <div class="border-t border-gray-400 w-40 pt-1">Álvaro Rugama</div>
            </div>
        </div>

        <p class="text-center text-[9px] text-gray-400 mt-4">Documento generado por Salon ERP · {{ now()->format('d/m/Y H:i') }} @if($colilla->estado === 'anulada') · <strong class="text-red-600">ANULADA</strong> @endif</p>
    </div>
</body>
</html>
