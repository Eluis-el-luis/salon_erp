<?php

namespace App\Exports;

use App\Models\CuentaContable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class CuentasContablesExport implements \Maatwebsite\Excel\Concerns\FromCollection, 
                                       \Maatwebsite\Excel\Concerns\WithHeadings,
                                       \Maatwebsite\Excel\Concerns\WithMapping,
                                       \Maatwebsite\Excel\Concerns\WithTitle
{
    public function collection(): \Illuminate\Support\Enumerable
    {
        return CuentaContable::with('cuentaPadre')->orderBy('codigo')->get();
    }

    public function headings(): array
    {
        return [
            'codigo',
            'nombre',
            'tipo',
            'naturaleza',
            'codigo_padre',
            'permite_movimiento',
            'activa',
        ];
    }

    public function map(mixed $row): array
    {
        $cuenta = $row;
        return [
            $cuenta->codigo,
            $cuenta->nombre,
            $cuenta->tipo,
            $cuenta->naturaleza,
            $cuenta->cuentaPadre?->codigo ?? '',
            $cuenta->permite_movimiento ? '1' : '0',
            $cuenta->activa ? '1' : '0',
        ];
    }

    public function title(): string
    {
        return 'Catalogo de Cuentas';
    }
}