<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Colilla de nómina (Salary Slip): documento auditable e imprimible por
 * colaborador y período. Su asiento contable se genera por colilla (NOM-*).
 */
class ColillaNomina extends Model
{
    protected $table = 'salary_slips';

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'user_id',
        'periodo_inicio',
        'periodo_fin',
        'salario_base',
        'total_ingresos',
        'total_deducciones',
        'neto_pagar',
        'estado',
        'fecha_pago',
        'asiento_contable_id',
    ];

    protected $casts = [
        'periodo_inicio' => 'date',
        'periodo_fin' => 'date',
        'fecha_pago' => 'date',
        'salario_base' => 'decimal:2',
        'total_ingresos' => 'decimal:2',
        'total_deducciones' => 'decimal:2',
        'neto_pagar' => 'decimal:2',
    ];

    public function corrida()
    {
        return $this->belongsTo(CorridaNomina::class, 'payroll_run_id');
    }

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'employee_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }

    public function detalles()
    {
        return $this->hasMany(DetalleColilla::class, 'salary_slip_id')->orderBy('orden');
    }

    public function asiento()
    {
        return $this->belongsTo(AsientoContable::class, 'asiento_contable_id');
    }

    /** Suma de una línea de detalle por su código (ej. COM_VENT). */
    public function montoPorCodigo(string $codigo): float
    {
        return (float) $this->detalles->where('codigo', $codigo)->sum('monto');
    }
}
