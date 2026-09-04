<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Moneda extends Model
{
    protected $table = 'monedas';

    protected $fillable = [
        'codigo',
        'nombre',
        'es_base',
    ];

    /**
     * Resuelve el ID de una moneda mediante su código (Ej. 'NIO', 'USD').
     * Lanza una excepción si la moneda no existe.
     */
    public static function idPorCodigo(string $codigo): int
    {
        $moneda = static::query()->where('codigo', $codigo)->first();

        if (!$moneda) {
            throw new \Exception("Error de Configuración: La moneda con código [{$codigo}] no existe.");
        }

        return $moneda->id;
    }
}