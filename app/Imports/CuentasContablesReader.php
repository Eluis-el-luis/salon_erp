<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Lector liviano del catálogo: solo materializa las filas del Excel para la
 * validación estructural previa (padre-hijo, duplicados, ciclos) sin tocar
 * la base de datos.
 */
class CuentasContablesReader implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows): void
    {
        // Sin efectos: la lectura solo alimenta el validador previo.
    }
}
