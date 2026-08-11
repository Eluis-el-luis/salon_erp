<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CierreMesaCambio extends Model
{
    protected $table = 'cierres_mesa_cambio';
    protected $fillable = [
        'fecha', 'usuario_id', 'moneda_origen_id', 'moneda_destino_id',
        'monto_origen', 'monto_destino', 'tasa_aplicada', 'diferencia'
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
