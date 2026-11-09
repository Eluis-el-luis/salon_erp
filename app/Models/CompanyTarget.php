<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Meta de facturación mensual configurable por el administrador.
 *
 * Regla de resolución (Dashboard BI):
 *  1. Meta exacta del mes consultado (periodo = primer día del mes).
 *  2. Si no existe, se HEREDA la última meta definida en un mes anterior
 *     (el objetivo vigente más reciente).
 *  3. Fallback final: config('salon.meta_mensual') para instalaciones nuevas.
 */
class CompanyTarget extends Model
{
    protected $table = 'company_targets';

    protected $fillable = ['periodo', 'monto', 'moneda', 'creado_por'];

    protected $casts = [
        'periodo' => 'date',
        'monto' => 'float',
    ];

    public function creador()
    {
        return $this->belongsTo(Usuario::class, 'creado_por');
    }

    /**
     * Devuelve ['monto' => float, 'fuente' => 'exacta|heredada|config'] para el
     * mes indicado. Nunca lanza excepción: el dashboard siempre debe renderizar.
     */
    public static function montoParaMes(Carbon $mes): array
    {
        $inicioMes = $mes->copy()->startOfMonth()->toDateString();

        $exacta = static::whereDate('periodo', $inicioMes)->first();
        if ($exacta) {
            return ['monto' => (float) $exacta->monto, 'fuente' => 'exacta'];
        }

        $heredada = static::whereDate('periodo', '<', $inicioMes)
            ->orderByDesc('periodo')
            ->first();
        if ($heredada) {
            return ['monto' => (float) $heredada->monto, 'fuente' => 'heredada'];
        }

        return ['monto' => (float) config('salon.meta_mensual', 150000), 'fuente' => 'config'];
    }
}
