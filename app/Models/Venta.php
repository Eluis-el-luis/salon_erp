<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Venta extends Model
{
    use HasFactory;

    protected $table = 'sales';

    protected $fillable = [
        'cashier_id',
        'client_id',
        'subtotal',
        'discount',
        'total',
        'currency',
        'exchange_rate',
        'payment_method',
        'observations',
        'status',
    ];

    public function cajero()
    {
        return $this->belongsTo(Usuario::class, 'cashier_id');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'client_id');
    }

    public function detalles()
    {
        return $this->hasMany(DetalleVenta::class, 'sale_id');
    }
}