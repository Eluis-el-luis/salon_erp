<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Propina acumulada en custodia del salón, a liquidar por nómina.
 * El reconocimiento contable (custodia 1.1.1.3 vs pasivo 2.1.6) ocurre al
 * registrarla; el pago se liquida desde la colilla de nómina.
 */
class Propina extends Model
{
    protected $table = 'tips';

    protected $fillable = [
        'employee_id',
        'fecha',
        'monto',
        'venta_id',
        'estado',
        'salary_slip_id',
        'asiento_contable_id',
        'observacion',
    ];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'decimal:2',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'employee_id');
    }

    public function colilla()
    {
        return $this->belongsTo(ColillaNomina::class, 'salary_slip_id');
    }
}
