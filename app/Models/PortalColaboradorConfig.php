<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PortalColaboradorConfig extends Model
{
    use HasFactory;

    protected $table = 'portal_colaborador_config';

    protected $fillable = [
        'clave',
        'valor',
        'descripcion',
    ];
}