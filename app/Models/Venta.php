<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Venta extends Model
{
    use HasFactory;

    protected $table = 'sales';

    protected $fillable = [
        'cashier_id',
        'client_id',
        'subtotal',
        'discount',
        'total',
        'currency',
        'exchange_rate',
        'payment_method',
        'observations',
        'status',
    ];

    public function cajero()
    {
        return $this->belongsTo(Usuario::class, 'cashier_id');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'client_id');
    }

    public function detalles()
    {
        return $this->hasMany(DetalleVenta::class, 'sale_id');
    }

    public function pagos()
    {
        return $this->hasMany(Pago::class, 'venta_id');
    }

    /**
     * PAGOS MIXTOS: Efectivo físico en NIO neto (cobros efectivo menos vueltos en
     * córdobas) de una consulta de ventas ya filtrada. Las ventas legacy sin líneas
     * de pago mantienen el comportamiento anterior (total por payment_method).
     * Se usa para el arqueo de caja: los dólares físicos se controlan en divisas,
     * no en la caja de córdobas.
     */
    public static function efectivoNioNeto($ventasQuery): float
    {
        $legacy = (float) (clone $ventasQuery)
            ->whereDoesntHave('pagos')
            ->where('payment_method', 'efectivo')
            ->sum('total');

        $idsConPagos = (clone $ventasQuery)->whereHas('pagos')->pluck('id');

        $neto = $idsConPagos->isEmpty() ? 0 : (float) Pago::whereIn('venta_id', $idsConPagos)
            ->where('metodo', 'efectivo')
            ->where('moneda', 'nio')
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'pago' THEN valor_nio ELSE -valor_nio END), 0) as neto")
            ->value('neto');

        return round($legacy + $neto, 2);
    }

    /**
     * PAGOS MIXTOS: Totales por método de pago en moneda base (NIO), netos de
     * vuelto, sobre una consulta de ventas ya filtrada. El vuelto siempre sale
     * del efectivo sin importar el método registrado en la línea.
     * Las ventas legacy (sin pagos) se agregan por su payment_method histórico.
     */
    public static function totalesPorMetodo($ventasQuery): array
    {
        $totales = ['efectivo' => 0.0, 'bac' => 0.0, 'lafise' => 0.0];

        foreach ((clone $ventasQuery)->whereDoesntHave('pagos')->get(['payment_method', 'total']) as $ventaLegacy) {
            if (!array_key_exists($ventaLegacy->payment_method, $totales)) {
                $totales[$ventaLegacy->payment_method] = 0.0;
            }
            $totales[$ventaLegacy->payment_method] += (float) $ventaLegacy->total;
        }

        $idsConPagos = (clone $ventasQuery)->whereHas('pagos')->pluck('id');
        if ($idsConPagos->isNotEmpty()) {
            foreach (Pago::whereIn('venta_id', $idsConPagos)->get() as $pago) {
                if ($pago->tipo === 'vuelto') {
                    $totales['efectivo'] -= (float) $pago->valor_nio;
                } elseif (array_key_exists($pago->metodo, $totales)) {
                    $totales[$pago->metodo] += (float) $pago->valor_nio;
                }
            }
        }

        return array_map(fn ($monto) => round($monto, 2), $totales);
    }
}