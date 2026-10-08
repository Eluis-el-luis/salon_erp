<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Venta extends Model
{
    use HasFactory;

    protected $table = 'sales';

    // CICLO DE VIDA DEL DOCUMENTO: workflow de estados de factura
    const ESTADO_BORRADOR = 'borrador'; // Cotización: no afecta inventario ni contabilidad
    const ESTADO_EMITIDA = 'emitida';   // Por cobrar (crédito): afecta inventario y CxC
    const ESTADO_PAGADA = 'pagada';     // Venta de mostrador cobrada
    const ESTADO_ANULADA = 'anulada';   // Corrección: con reversión contable e inventario

    const ESTADOS = [
        self::ESTADO_BORRADOR,
        self::ESTADO_EMITIDA,
        self::ESTADO_PAGADA,
        self::ESTADO_ANULADA,
    ];

    // Estados en los que el ingreso ya se reconoció (devengado) y el inventario
    // ya fue afectado. Los usan panel, reportes y kárdex. El dinero físico solo
    // se mueve en 'pagada'.
    const ESTADOS_DEVENGADOS = [self::ESTADO_PAGADA, self::ESTADO_EMITIDA];

    protected $fillable = [
        'cashier_id',
        'client_id',
        'appointment_id',
        'subtotal',
        'discount',
        'total',
        'currency',
        'exchange_rate',
        'payment_method',
        'observations',
        'status',
        'anulada_at',
        'anulada_por',
        'motivo_anulacion',
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

    public function cita()
    {
        return $this->belongsTo(Cita::class, 'appointment_id');
    }

    public function anuladaPor()
    {
        return $this->belongsTo(Usuario::class, 'anulada_por');
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