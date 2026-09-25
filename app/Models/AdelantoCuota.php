<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdelantoCuota extends Model
{
    use HasFactory;

    protected $table = 'adelanto_cuotas';

    protected $fillable = [
        'adelanto_id',
        'numero_cuota',
        'fecha_vencimiento',
        'monto',
        'estado',
        'monto_pagado',
        'fecha_pago',
    ];

    protected $casts = [
        'fecha_vencimiento' => 'date',
        'monto' => 'decimal:2',
        'monto_pagado' => 'decimal:2',
        'fecha_pago' => 'date',
    ];

    public function adelanto()
    {
        return $this->belongsTo(Adelanto::class, 'adelanto_id');
    }
}