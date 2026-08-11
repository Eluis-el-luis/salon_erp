<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #{{ str_pad($sale->id, 5, '0', STR_PAD_LEFT) }}</title>
    @vite(['resources/css/app.css'])
    <style>
        /* Estilos optimizados para impresora térmica de 80mm */
        @media print {
            body { margin: 0; padding: 0; font-family: monospace; }
            .no-print { display: none; }
            .print-container { width: 100%; max-width: 300px; margin: 0 auto; box-shadow: none; border: none; }
        }
    </style>
</head>
<body class="bg-gray-200 font-sans text-gray-900 py-8">

    <!-- Botones de Acción (Ocultos al imprimir) -->
    <div class="max-w-sm mx-auto mb-4 flex justify-between no-print">
        <a href="javascript:window.close()" class="text-sm text-gray-600 hover:text-gray-900">← Cerrar</a>
        <button onclick="window.print()" class="bg-emerald-600 text-white px-4 py-1 rounded shadow text-sm font-bold">🖨️ Imprimir Ticket</button>
    </div>

    <!-- Contenedor del Ticket -->
    <div class="print-container bg-white mx-auto max-w-sm p-6 rounded shadow-lg border-t-8 border-gray-800">
        
        <!-- Cabecera del Salón -->
        <div class="text-center mb-6">
            <h1 class="text-2xl font-black uppercase text-gray-800">Álvaro Rugama</h1>
            <p class="text-sm font-bold tracking-widest text-gray-500 uppercase">Make Up Studio</p>
            <p class="text-xs text-gray-500 mt-2">Managua, Nicaragua</p>
            <p class="text-xs text-gray-500">Tel: +505 0000-0000</p>
        </div>

        <div class="border-b border-dashed border-gray-400 mb-4"></div>

        <!-- Info de la Factura -->
        <div class="text-xs mb-4 space-y-1">
            <div class="flex justify-between">
                <span class="font-bold">Factura #:</span>
                <span>INV-{{ str_pad($sale->id, 5, '0', STR_PAD_LEFT) }}</span>
            </div>
            <div class="flex justify-between">
                <span class="font-bold">Fecha:</span>
                <span>{{ $sale->created_at->format('d/m/Y h:i A') }}</span>
            </div>
            <div class="flex justify-between">
                <span class="font-bold">Cliente:</span>
                <span>{{ $sale->client ? $sale->client->name : 'Consumidor Final' }}</span>
            </div>
            <div class="flex justify-between">
                <span class="font-bold">Cajero:</span>
                <span>{{ $sale->cashier ? $sale->cashier->name : 'Admin' }}</span>
            </div>
        </div>

        <div class="border-b border-dashed border-gray-400 mb-4"></div>

        <!-- Detalle de Productos/Servicios -->
        <table class="w-full text-xs mb-4">
            <thead>
                <tr class="border-b border-gray-200">
                    <th class="text-left pb-2 w-2/3">Cant - Descripción</th>
                    <th class="text-right pb-2 w-1/3">Importe</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sale->details as $detail)
                <tr class="border-b border-gray-100">
                    <td class="py-2">
                        <span class="font-bold">{{ $detail->quantity }}x</span> 
                        
                        @if($detail->service)
                            {{ $detail->service->name }} <br>
                            <span class="text-[10px] text-gray-500 italic">Por: {{ $detail->stylist ? $detail->stylist->name : 'N/A' }}</span>
                            
                            <!-- NUEVO: Mostrar insumos gastados -->
                            @if($detail->service->formulas && $detail->service->formulas->count() > 0)
                                <span class="text-[9px] text-gray-400 block mt-1 leading-tight">
                                    [ Insumos: 
                                    @foreach($detail->service->formulas as $formula)
                                        {{ $formula->quantity_used * $detail->quantity }}{{ $formula->item->unit_measure }} {{ $formula->item->producto }}@if(!$loop->last), @endif
                                    @endforeach
                                    ]
                                </span>
                            @endif
                            
                        @elseif($detail->item)
                            {{ $detail->item->producto }}
                        @else
                            Artículo General
                        @endif
                    </td>
                    <td class="text-right font-bold py-2 align-top">C$ {{ number_format($detail->quantity * $detail->unit_price, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Totales -->
        <div class="space-y-1 text-sm">
            <div class="flex justify-between text-gray-600">
                <span>Subtotal:</span>
                <span>C$ {{ number_format($sale->subtotal, 2) }}</span>
            </div>
            @if($sale->discount > 0)
            <div class="flex justify-between text-red-500">
                <span>Descuento:</span>
                <span>- C$ {{ number_format($sale->discount, 2) }}</span>
            </div>
            @endif
            <div class="flex justify-between font-black text-lg border-t border-gray-300 pt-2 mt-2">
                <span>TOTAL A PAGAR:</span>
                <span>C$ {{ number_format($sale->total, 2) }}</span>
            </div>
        </div>

        <div class="bg-gray-100 p-2 mt-4 rounded text-center">
            <p class="text-xs text-gray-700 uppercase font-bold">
                PAGO RECIBIDO EN: 
                @if($sale->payment_method == 'efectivo')
                    Efectivo (Caja)
                @else
                    Banco {{ strtoupper($sale->payment_method) }}
                @endif
            </p>
            
            @if($sale->currency == 'usd')
                <p class="text-[11px] font-black text-gray-900 mt-1">
                    Equivalente cobrado: $ {{ number_format($sale->total / $sale->exchange_rate, 2) }} USD
                </p>
                <p class="text-[9px] text-gray-500 italic mt-0.5">
                    (Tasa de cambio aplicada: C$ {{ number_format($sale->exchange_rate, 2) }})
                </p>
            @endif
        </div>

        <div class="border-b border-dashed border-gray-400 my-6"></div>

        <!-- Pie de página -->
        <div class="text-center text-xs text-gray-500">
            <p class="font-bold text-gray-700">¡Gracias por su preferencia!</p>
            <p class="mt-1">El maquillaje perfecto para cualquier ocasión.</p>
        </div>

    </div>
</body>
</html>