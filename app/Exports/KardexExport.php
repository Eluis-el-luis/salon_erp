<?php

namespace App\Exports;

use App\Models\Articulo;
use App\Models\DetalleVenta;
use App\Models\Lote;
use App\Models\Merma;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class KardexExport implements FromCollection, WithHeadings, WithMapping, WithTitle, WithStyles
{
    protected $itemId;

    public function __construct($itemId)
    {
        $this->itemId = $itemId;
    }

    public function collection()
    {
        $articulo = Articulo::with('lotes')->findOrFail($this->itemId);
        
        $movimientos = collect();

        // ENTRADAS: lotes (compras)
        foreach ($articulo->lotes as $lote) {
            $volumenEntrada = $lote->cantidad_entrada * $lote->articulo->total_volume;
            yield [
                'fecha' => $lote->fecha_entrada,
                'tipo' => 'Entrada',
                'concepto' => 'Compra / Lote ' . $lote->lote,
                'cantidad_unidades' => (float) $lote->cantidad_entrada,
                'volumen_ml' => round($lote->cantidad_entrada * $articulo->total_volume, 2),
                'costo_unitario' => (float) $lote->costo_unitario,
                'valor' => round($lote->cantidad_entrada * $lote->costo_unitario, 2),
            ];
        }

        // SALIDAS: ventas de producto físico
        $detalles = DetalleVenta::with('venta')->where('item_id', $this->itemId)->get();
        foreach ($detalles as $d) {
            $volumenMl = $d->quantity * ($d->articulo->total_volume ?? 0);
            yield [
                'fecha' => $d->venta?->created_at ?? $d->created_at,
                'tipo' => 'Salida',
                'concepto' => 'Venta #' . $d->sale_id,
                'cantidad' => -$d->quantity,
                'volumen_ml' => round($volumenMl, 2),
                'costo_unitario' => $articulo->costo_promedio,
                'valor' => round(-$d->quantity * $articulo->costo_promedio, 2),
            ];
        }

        // SALIDAS: mermas
        foreach (Merma::where('item_id', $this->itemId)->get() as $m) {
            yield [
                'fecha' => $m->fecha,
                'tipo' => 'Salida (Merma)',
                'concepto' => 'Merma: ' . $m->motivo,
                'cantidad' => -$m->cantidad,
                'volumen_ml' => $m->tipo === 'volumen' ? round($m->cantidad * ($m->articulo->total_volume ?? 0), 2) : 0,
                'costo_unitario' => $m->cantidad > 0 ? ($m->valor / $m->cantidad) : 0,
                'valor' => -$m->valor,
            ];
        }
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
        ];
    }

    public function title(): string
    {
        $articulo = Articulo::find($this->itemId);
        return 'Kardex ' . ($articulo->producto ?? $this->itemId);
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true], 'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '047857']]],
        ];
    }
}