<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RetiroPropietario extends Model
{
    use HasFactory;

    protected $table = 'retiros_propietario';

    protected $fillable = [
        'fecha',
        'monto',
        'moneda',
        'metodo_pago',
        'concepto',
        'user_id',
    ];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'decimal:2',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'user_id');
    }
}