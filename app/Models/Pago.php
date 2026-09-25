<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pago extends Model
{
    use HasFactory;

    protected $table = 'pagos';

    protected $fillable = [
        'venta_id',
        'metodo',
        'moneda',
        'monto',
        'tasa',
        'valor_nio',
        'tipo',
    ];

    protected $casts = [
        'monto' => 'float',
        'tasa' => 'float',
        'valor_nio' => 'float',
    ];

    public function venta()
    {
        return $this->belongsTo(Venta::class, 'venta_id');
    }
}