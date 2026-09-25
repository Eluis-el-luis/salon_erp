<?php

namespace App\Imports;

use App\Models\CuentaContable;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class CuentasContablesImport implements \Maatwebsite\Excel\Concerns\ToModel,
                                        \Maatwebsite\Excel\Concerns\WithHeadingRow,
                                        \Maatwebsite\Excel\Concerns\WithValidation,
                                        \Maatwebsite\Excel\Concerns\WithBatchInserts,
                                        \Maatwebsite\Excel\Concerns\WithChunkReading
{
    public function model(array $row): \Illuminate\Database\Eloquent\Model|array|null
    {
        return new \App\Models\CuentaContable([
            'codigo' => $row['codigo'],
            'nombre' => $row['nombre'],
            'tipo' => $row['tipo'],
            'naturaleza' => $row['naturaleza'],
            'cuenta_padre_id' => $this->getParentId($row['codigo_padre'] ?? ''),
            'nivel' => 1, // Se calculará después
            'permite_movimiento' => filter_var($row['permite_movimiento'], FILTER_VALIDATE_BOOLEAN) ?? true,
            'activa' => filter_var($row['activa'], FILTER_VALIDATE_BOOLEAN) ?? true,
            'is_system_account' => false,
        ]);
    }

    public function rules(): array
    {
        return [
            'codigo' => 'required|string|max:20',
            'nombre' => 'required|string|max:100',
            'tipo' => 'required|in:activo,pasivo,patrimonio,ingreso,gasto,costo',
            'naturaleza' => 'required|in:deudora,acreedora',
            'codigo_padre' => 'nullable|string|max:20',
            'permite_movimiento' => 'nullable|boolean',
            'activa' => 'nullable|boolean',
        ];
    }

    public function batchSize(): int
    {
        return 100;
    }

    private function getParentId(string $codigo): ?int
    {
        if (empty($codigo)) return null;
        
        $cuenta = \App\Models\CuentaContable::where('codigo', $codigo)->first();
        return $cuenta ? $cuenta->id : null;
    }

    public function chunkSize(): int
    {
        return 100;
    }
}