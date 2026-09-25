<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kárdex - {{ $articulo->producto }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; margin: 10px; }
        h1 { font-size: 14px; text-align: center; margin-bottom: 5px; }
        h2 { font-size: 11px; text-align: center; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; font-size: 8px; }
        th, td { border: 1px solid #ddd; padding: 3px 4px; text-align: center; }
        th { background-color: #f3f4f6; font-weight: bold; font-size: 7px; }
        .header { text-align: center; margin-bottom: 10px; }
        .header h1 { font-size: 16px; margin: 0; }
        .header p { margin: 2px 0; font-size: 10px; }
        .info-row { display: flex; justify-content: space-between; margin: 3px 0; font-size: 9px; }
        .info-row span:first-child { font-weight: bold; }
        .total-row { font-weight: bold; background-color: #f3f4f6; }
        .entrada { background-color: #d1fae5; }
        .salida { background-color: #fee2e2; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Kárdex Físico-Valorado</h1>
        <p>{{ $articulo->producto }} ({{ $articulo->unit_measure ?? 'uds' }})</p>
        <div class="info-row"><span>Fecha generación:</span><span>{{ now()->format('d/m/Y H:i') }}</div>
        <div class="info-row"><span>Existencia actual:</span><span>{{ number_format($articulo->existencia_actual, 2) }} {{ $articulo->unit_measure ?? 'uds' }}</div>
        <div class="info-row"><span>Costo promedio:</span><span>C$ {{ number_format($articulo->costo_promedio, 4) }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Tipo</th>
                <th>Concepto</th>
                <th>Cant. Und.</th>
                <th>Volumen (ml)</th>
                <th>Costo Unit.</th>
                <th>Valor (C$)</th>
                <th>Saldo Und.</th>
            </tr>
        </thead>
        <tbody>
            @php
                $saldo = 0;
                $saldoVolumen = 0;
            @endphp
            @foreach($movimientos as $mv)
                @php
                    $saldo += $mv['cantidad'];
                    $saldoVolumen += ($mv['volumen_ml'] ?? 0);
                    $valor = $mv['cantidad'] * $mv['costo'];
                @endphp
                <tr class="{{ $mv['tipo'] == 'entrada' ? 'entrada' : 'salida' }}">
                    <td>{{ \Carbon\Carbon::parse($mv['fecha'])->format('d/m/Y') }}</td>
                    <td>{{ $mv['tipo'] }}</td>
                    <td>{{ $mv['concepto'] }}</td>
                    <td style="text-align: right;">{{ $mv['cantidad'] > 0 ? '+' : '' }}{{ number_format($mv['cantidad'], 2) }}</td>
                    <td style="text-align: right;">{{ number_format($mv['volumen_ml'] ?? 0, 2) }}</td>
                    <td style="text-align: right;">C$ {{ number_format($mv['costo'], 4) }}</td>
                    <td style="text-align: right;">C$ {{ number_format($valor, 2) }}</td>
                    <td style="text-align: right;">{{ number_format($saldo, 2) }} ({{ number_format($saldoVolumen, 2) }} ml)</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>