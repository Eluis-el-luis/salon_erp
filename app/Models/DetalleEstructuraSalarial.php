<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DetalleEstructuraSalarial extends Model
{
    protected $table = 'salary_structure_details';

    protected $fillable = [
        'salary_structure_id',
        'codigo',
        'nombre',
        'tipo',
        'calculadora',
        'valor',
        'orden',
        'cuenta_contable_id',
        'activo',
    ];

    protected $casts = [
        'valor' => 'float',
        'activo' => 'boolean',
    ];

    public function estructura()
    {
        return $this->belongsTo(EstructuraSalarial::class, 'salary_structure_id');
    }

    public function cuentaContable()
    {
        return $this->belongsTo(CuentaContable::class, 'cuenta_contable_id');
    }
}
