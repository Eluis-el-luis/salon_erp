<?php

namespace App\Imports;

use App\Models\CuentaContable;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class CuentasContablesImport implements \Maatwebsite\Excel\Concerns\ToModel,
                                        \Maatwebsite\Excel\Concerns\WithHeadingRow,
                                        \Maatwebsite\Excel\Concerns\WithValidation,
                                        \Maatwebsite\Excel\Concerns\WithChunkReading
{
    public function model(array $row): \Illuminate\Database\Eloquent\Model|array|null
    {
        $padre = $this->buscarPadre($row['codigo_padre'] ?? '');

        $permiteMovimiento = filter_var($row['permite_movimiento'] ?? '1', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $activa = filter_var($row['activa'] ?? '1', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return new CuentaContable([
            'codigo' => (string) $row['codigo'],
            'nombre' => $row['nombre'],
            'tipo' => $row['tipo'],
            'naturaleza' => $row['naturaleza'],
            'cuenta_padre_id' => $padre?->id,
            // El nivel se deriva del padre para respetar la jerarquía
            'nivel' => $padre ? ((int) $padre->nivel + 1) : 1,
            'permite_movimiento' => $permiteMovimiento === null ? true : $permiteMovimiento,
            'activa' => $activa === null ? true : $activa,
            // Las cuentas importadas NUNCA son del sistema (el catálogo base ya está protegido)
            'is_system_account' => false,
        ]);
    }

    public function rules(): array
    {
        return [
            'codigo' => ['required', 'max:20', Rule::unique('cuentas_contables', 'codigo')],
            'nombre' => ['required', 'string', 'max:100'],
            'tipo' => ['required', 'in:activo,pasivo,patrimonio,ingreso,gasto,costo'],
            'naturaleza' => ['required', 'in:deudora,acreedora'],
            'codigo_padre' => [
                'nullable', 'max:20',
                function ($attribute, $value, $fail) {
                    if (!empty($value) && !CuentaContable::where('codigo', (string) $value)->exists()) {
                        $fail("La cuenta padre [{$value}] no existe o aparece después de esta fila. Verifique el orden del archivo.");
                    }
                },
            ],
            'permite_movimiento' => ['nullable', 'in:0,1,true,false,si,no,SI,NO'],
            'activa' => ['nullable', 'in:0,1,true,false,si,no,SI,NO'],
        ];
    }

    public function chunkSize(): int
    {
        return 100;
    }

    private function buscarPadre(string $codigo): ?CuentaContable
    {
        if (empty($codigo)) {
            return null;
        }

        return CuentaContable::where('codigo', $codigo)->first();
    }
}
