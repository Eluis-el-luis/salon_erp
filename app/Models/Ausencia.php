<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ausencia extends Model
{
    protected $table = 'leave_applications';

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'fecha_inicio',
        'fecha_fin',
        'dias',
        'motivo',
        'estado',
        'revisado_por',
        'fecha_revision',
        'observaciones',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'dias' => 'decimal:2',
        'fecha_revision' => 'datetime',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'employee_id');
    }

    public function tipo()
    {
        return $this->belongsTo(TipoAusencia::class, 'leave_type_id');
    }

    public function revisor()
    {
        return $this->belongsTo(Usuario::class, 'revisado_por');
    }
}
