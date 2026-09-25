<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EsquemaRango extends Model
{
    use HasFactory;

    protected $table = 'esquema_rangos';

    protected $fillable = [
        'esquema_comision_id',
        'desde',
        'hasta',
        'porcentaje',
        'orden',
    ];

    protected $casts = [
        'desde' => 'decimal:2',
        'hasta' => 'decimal:2',
        'porcentaje' => 'decimal:2',
    ];

    public function esquemaComision()
    {
        return $this->belongsTo(EsquemaComision::class, 'esquema_comision_id');
    }
}