<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transferencia extends Model
{
    protected $table = 'transferencias';
    protected $fillable = [
        'origen_tipo', 'origen_id', 
        'destino_tipo', 'destino_id', 
        'monto', 'fecha', 'usuario_id', 'estado'
    ];

    // Relación Polimórfica: Permite mover dinero desde Caja o Banco usando la misma tabla[cite: 3]
    public function origen()
    {
        return $this->morphTo();
    }

    // Relación Polimórfica: Permite enviar dinero a Caja o Banco[cite: 3]
    public function destino()
    {
        return $this->morphTo();
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}