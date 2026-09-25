<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AsientoContable extends Model
{
    protected $table = 'asientos_contables';
    protected $fillable = ['numero_asiento', 'fecha', 'concepto', 'modulo_origen', 'referencia_id', 'tipo_asiento', 'periodo_id', 'usuario_id', 'estado'];

    // Un asiento tiene muchas líneas de detalle
    public function detalles()
    {
        return $this->hasMany(DetalleAsiento::class, 'asiento_id');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function periodo()
    {
        return $this->belongsTo(PeriodoContable::class, 'periodo_id');
    }
}