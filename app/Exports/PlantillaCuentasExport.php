<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Plantilla para el importador del catálogo de cuentas (estilo ERPNext):
 *
 * Hoja 1 "Plantilla": únicamente los encabezados que espera CuentasContablesImport.
 *   El usuario llena aquí sus cuentas nuevas. Al importarse sin filas el
 *   validador estructural lo rechaza, así que subir la plantilla intacta no
 *   crea registros basura.
 * Hoja 2 "Ejemplo": filas de referencia con jerarquía padre-hijo. El importador
 *   solo lee la PRIMERA hoja, por lo que esta hoja nunca se importa.
 */
class PlantillaCuentasExport implements Export, WithMultipleSheets
{
    public const ENCABEZADOS = [
        'codigo', 'nombre', 'tipo', 'naturaleza', 'codigo_padre', 'permite_movimiento', 'activa',
    ];

    public function sheets(): array
    {
        return [
            new class implements FromArray, WithTitle, ShouldAutoSize
            {
                public function array(): array
                {
                    return [PlantillaCuentasExport::ENCABEZADOS];
                }

                public function title(): string
                {
                    return 'Plantilla';
                }
            },
            new class implements FromArray, WithTitle, ShouldAutoSize
            {
                public function array(): array
                {
                    return [
                        PlantillaCuentasExport::ENCABEZADOS,
                        ['9.8', 'Gastos de Marketing Digital', 'gasto', 'deudora', '', '0', '1'],
                        ['9.8.1', 'Publicidad en Redes Sociales', 'gasto', 'deudora', '9.8', '1', '1'],
                        ['9.8.2', 'Fotografía y Contenido', 'gasto', 'deudora', '9.8', '1', '1'],
                    ];
                }

                public function title(): string
                {
                    return 'Ejemplo (no se importa)';
                }
            },
        ];
    }
}
