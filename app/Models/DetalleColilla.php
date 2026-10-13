<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DetalleColilla extends Model
{
    protected $table = 'salary_slip_details';

    protected $fillable = [
        'salary_slip_id',
        'tipo',
        'codigo',
        'concepto',
        'fuente',
        'monto',
        'orden',
        'referencia_id',
    ];

    protected $casts = ['monto' => 'decimal:2'];

    public function colilla()
    {
        return $this->belongsTo(ColillaNomina::class, 'salary_slip_id');
    }
}
