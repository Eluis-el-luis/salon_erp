<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * FASE 1 ADMINISTRACIÓN: grupo de clientes (Ej. VIP, Regular, Mayorista).
 * Segmenta el directorio y define el descuento automático que aplica el POS
 * cuando el cliente no tiene un descuento manual en la factura.
 */
class ClienteGrupo extends Model
{
    protected $table = 'customer_groups';

    protected $fillable = [
        'name',
        'description',
        'discount_percent',
        'is_default',
    ];

    protected $casts = [
        'discount_percent' => 'float',
        'is_default' => 'boolean',
    ];

    public function clientes()
    {
        return $this->hasMany(Cliente::class, 'customer_group_id');
    }
}
