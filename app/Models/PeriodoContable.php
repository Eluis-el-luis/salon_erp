<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeriodoContable extends Model
{
    protected $table = 'periodos_contables';

    protected $fillable = ['nombre', 'fecha_inicio', 'fecha_fin', 'estado'];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
    ];

    public function asientos()
    {
        return $this->hasMany(AsientoContable::class, 'periodo_id');
    }
}
