<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoAusencia extends Model
{
    protected $table = 'leave_types';

    protected $fillable = ['codigo', 'nombre', 'pagado', 'requiere_documento', 'activo'];

    protected $casts = [
        'pagado' => 'boolean',
        'requiere_documento' => 'boolean',
        'activo' => 'boolean',
    ];

    public function ausencias()
    {
        return $this->hasMany(Ausencia::class, 'leave_type_id');
    }
}
