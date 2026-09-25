<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PortalColaboradorToken extends Model
{
    use HasFactory;

    protected $table = 'portal_colaborador_tokens';

    protected $fillable = [
        'usuario_id',
        'token',
        'expira_en',
        'activo',
    ];

    protected $casts = [
        'expira_en' => 'datetime',
        'activo' => 'boolean',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}