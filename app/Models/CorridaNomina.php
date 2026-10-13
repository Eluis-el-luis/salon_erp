<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Corrida de nómina (Payroll Run): lote de colillas procesadas y contabilizadas
 * en un mismo período. La anulación revierte colilla por colilla con asientos REV.
 */
class CorridaNomina extends Model
{
    protected $table = 'payroll_runs';

    protected $fillable = [
        'periodo_inicio',
        'periodo_fin',
        'metodo_pago',
        'estado',
        'total_ingresos',
        'total_deducciones',
        'total_neto',
        'motivo_anulacion',
        'creado_por',
    ];

    protected $casts = [
        'periodo_inicio' => 'date',
        'periodo_fin' => 'date',
        'total_ingresos' => 'decimal:2',
        'total_deducciones' => 'decimal:2',
        'total_neto' => 'decimal:2',
    ];

    public function colillas()
    {
        return $this->hasMany(ColillaNomina::class, 'payroll_run_id');
    }

    public function creador()
    {
        return $this->belongsTo(Usuario::class, 'creado_por');
    }
}
