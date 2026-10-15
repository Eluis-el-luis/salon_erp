<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kárdex - {{ $articulo->producto }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; margin: 10px; }
        h1 { font-size: 14px; text-align: center; margin-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; font-size: 8px; }
        th, td { border: 1px solid #ddd; padding: 3px 4px; text-align: center; }
        th { background-color: #f3f4f6; font-weight: bold; font-size: 7px; }
        .header { text-align: center; margin-bottom: 10px; }
        .header h1 { font-size: 16px; margin: 0; }
        .header p { margin: 2px 0; font-size: 10px; }
        .info-row { display: flex; justify-content: space-between; margin: 3px 0; font-size: 9px; }
        .info-row span:first-child { font-weight: bold; }
        .entrada { background-color: #d1fae5; }
        .salida { background-color: #fee2e2; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Kárdex Inmutable (Stock Ledger)</h1>
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
                <th>Volumen</th>
                <th>Costo Unit.</th>
                <th>Valor (C$)</th>
                <th>Saldo Und.</th>
                <th>Saldo Valor (C$)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($entradas as $entrada)
                <tr class="{{ $entrada->movimiento == 'entrada' ? 'entrada' : 'salida' }}">
                    <td>{{ $entrada->fecha?->format('d/m/Y') }}</td>
                    <td>{{ ucfirst($entrada->movimiento) }} · {{ $entrada->origen }}</td>
                    <td>{{ ucfirst(str_replace('_', ' ', $entrada->origen)) }} {{ $entrada->referencia_id ? '#' . $entrada->referencia_id : '' }}</td>
                    <td style="text-align: right;">{{ $entrada->cantidad > 0 ? '+' : '' }}{{ number_format($entrada->cantidad, 2) }}</td>
                    <td style="text-align: right;">{{ $entrada->volumen !== null ? number_format($entrada->volumen, 2) : '—' }}</td>
                    <td style="text-align: right;">C$ {{ number_format($entrada->costo_unitario, 4) }}</td>
                    <td style="text-align: right;">C$ {{ number_format($entrada->valor, 2) }}</td>
                    <td style="text-align: right;">{{ number_format($entrada->saldo_cantidad, 2) }}</td>
                    <td style="text-align: right;">C$ {{ number_format($entrada->saldo_valor, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
