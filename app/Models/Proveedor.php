<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Proveedor extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'providers';

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
    public function articulos()
    {
        return $this->hasMany(Articulo::class);
    }

    // Relación: Un proveedor tiene muchas cuentas por pagar (facturas pendientes)
    public function cuentasPorPagar()
    {
        return $this->hasMany(CuentaPorPagar::class, 'provider_id');
    }
}