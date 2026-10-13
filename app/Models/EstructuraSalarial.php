<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstructuraSalarial extends Model
{
    protected $table = 'salary_structures';

    protected $fillable = ['nombre', 'descripcion', 'activa'];

    protected $casts = ['activa' => 'boolean'];

    public function detalles()
    {
        return $this->hasMany(DetalleEstructuraSalarial::class, 'salary_structure_id')->orderBy('orden');
    }

    public function empleados()
    {
        return $this->hasMany(Empleado::class, 'salary_structure_id');
    }

    /** La estructura activa por defecto (para empleados sin asignación explícita). */
    public static function activaPorDefecto(): ?self
    {
        return static::where('activa', true)->orderBy('id')->first();
    }
}
