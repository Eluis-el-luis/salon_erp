<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CajaChica extends Model
{
    protected $table = 'cajas_chicas';
    protected $fillable = ['codigo', 'responsable_id', 'monto_fondo', 'estado'];

    // Relación con el usuario (Cajero/Recepcionista)
    public function responsable()
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    // Los gastos que se han hecho con esta caja[cite: 2]
    public function movimientos()
    {
        return $this->hasMany(MovimientoCajaChica::class, 'caja_chica_id');
    }
}