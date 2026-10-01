<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeriodoContable extends Model
{
    protected $table = 'periodos_contables';

    protected $fillable = ['nombre', 'fecha_inicio', 'fecha_fin', 'estado', 'generado_automaticamente'];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'generado_automaticamente' => 'boolean',
    ];

    public function asientos()
    {
        return $this->hasMany(AsientoContable::class, 'periodo_id');
    }
}
