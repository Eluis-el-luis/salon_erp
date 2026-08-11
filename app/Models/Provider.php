<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Provider extends Model
{
    use HasFactory;

    // Permitir la asignación masiva para estos campos
    protected $fillable = [
        'name',
        'contact_name',
        'phone',
        'email',
        'address',
        'notes',
    ];

    // Relación opcional: Un proveedor tiene muchos productos
    public function items()
    {
        return $this->hasMany(Item::class);
    }

    // Relación: Un proveedor tiene muchas cuentas por pagar (facturas pendientes)[cite: 4]
    public function cuentasPorPagar()
    {
        return $this->hasMany(CuentaPorPagar::class, 'provider_id');
    }
}