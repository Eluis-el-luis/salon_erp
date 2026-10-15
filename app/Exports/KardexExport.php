<?php

namespace App\Exports;

use App\Models\Articulo;
use App\Models\StockLedger;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Exportación del KÁRDEX corporativo: lee directamente del stock ledger
 * inmutable (misma fuente de verdad que la vista web), con saldo corrido.
 */
class KardexExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    protected $itemId;

    public function __construct($itemId)
    {
        $this->itemId = $itemId;
    }

    public function collection()
    {
        return StockLedger::with('usuario')
            ->where('item_id', $this->itemId)
            ->orderBy('fecha')
            ->orderBy('id')
            ->get()
            ->map(function (StockLedger $entrada) {
                return [
                    'fecha' => $entrada->fecha?->format('d/m/Y H:i'),
                    'tipo' => ucfirst($entrada->movimiento),
                    'concepto' => $this->concepto($entrada),
                    'cantidad_unidades' => (float) $entrada->cantidad,
                    'volumen_ml' => $entrada->volumen !== null ? (float) $entrada->volumen : '',
                    'costo_unitario' => (float) $entrada->costo_unitario,
                    'valor' => (float) $entrada->valor,
                    'saldo_unidades' => (float) $entrada->saldo_cantidad,
                    'saldo_valor' => (float) $entrada->saldo_valor,
                    'costo_promedio' => (float) $entrada->costo_promedio,
                    'usuario' => $entrada->usuario?->name ?? '',
                ];
            });
    }

    protected function concepto(StockLedger $entrada): string
    {
        return match ($entrada->origen) {
            StockLedger::ORIGEN_COMPRA => 'Compra (DOC '.$entrada->referencia_id.')',
            StockLedger::ORIGEN_VENTA => 'Venta #'.$entrada->referencia_id,
            StockLedger::ORIGEN_MERMA => 'Merma #'.$entrada->referencia_id,
            StockLedger::ORIGEN_REVERSION_VENTA => 'Reversión venta #'.$entrada->referencia_id,
            StockLedger::ORIGEN_ANULACION_COMPRA => 'Anulación compra (DOC '.$entrada->referencia_id.')',
            StockLedger::ORIGEN_APERTURA => 'Apertura de kárdex',
            default => ucfirst(str_replace('_', ' ', $entrada->origen)),
        };
    }

    public function headings(): array
    {
        return [
            'Fecha',
            'Tipo',
            'Concepto',
            'Cantidad (Und.)',
            'Volumen (ml)',
            'Costo Unitario',
            'Valor (C$)',
            'Saldo (Und.)',
            'Saldo Valor (C$)',
            'Costo Promedio',
            'Usuario',
        ];
    }

    public function map($row): array
    {
        return [
            $row['fecha'],
            $row['tipo'],
            $row['concepto'],
            $row['cantidad_unidades'],
            $row['volumen_ml'],
            $row['costo_unitario'],
            $row['valor'],
            $row['saldo_unidades'],
            $row['saldo_valor'],
            $row['costo_promedio'],
            $row['usuario'],
        ];
    }

    public function title(): string
    {
        $articulo = Articulo::find($this->itemId);

        return 'Kardex '.($articulo->producto ?? $this->itemId);
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '047857']]],
        ];
    }
}
