<?php

namespace App\Services;

use App\Models\ComisionGenerada;
use App\Models\Usuario;
use App\Models\Venta;

/**
 * MOTOR DE COMISIONES DE VENTA (FASE 5 AVANZADA).
 *
 * Reglas extraídas de VentaController para que el POS, la facturación de
 * citas y la APROBACIÓN de ventas retenidas generen comisiones idénticas:
 * soporta esquemas con rangos escalonados (EsquemaRango), porcentaje único
 * del esquema y fallback al % del perfil del empleado. Las comisiones se
 * crean en estado 'pendiente' y la nómina consume los montos CONGELADOS
 * (§5.6 de AGENTS.md), nunca recalculados.
 */
class ComisionService
{
    /**
     * Calcula y registra las comisiones de los estilistas de una venta.
     */
    public function registrarPorVenta(Venta $venta): void
    {
        foreach ($venta->detalles as $detalle) {
            if ($detalle->stylist_id) {
                $estilista = Usuario::find($detalle->stylist_id);

                if (!$estilista) {
                    continue;
                }

                $esquema = $estilista->esquemaActual;
                $montoVenta = $detalle->quantity * $detalle->unit_price;

                // Determinar porcentaje aplicable
                $porcentaje = 0;

                if ($esquema && $esquema->rangos->count() > 0) {
                    // COMISIONES ESCALONADAS: buscar el rango correspondiente al monto de venta
                    $rangoAplicable = $esquema->rangos
                        ->where('desde', '<=', $montoVenta)
                        ->where(function ($q) use ($montoVenta) {
                            $q->where('hasta', '>=', $montoVenta)
                              ->orWhereNull('hasta');
                        })
                        ->orderBy('orden')
                        ->first();

                    if ($rangoAplicable) {
                        $porcentaje = $rangoAplicable->porcentaje;
                    } elseif ($esquema->porcentaje_comision) {
                        // Fallback: porcentaje único del esquema
                        $porcentaje = $esquema->porcentaje_comision;
                    }
                } elseif ($esquema) {
                    // Esquema sin rangos: usar porcentaje único
                    $porcentaje = $esquema->porcentaje_comision;
                } else {
                    // Fallback: porcentaje del perfil del empleado
                    $porcentaje = $estilista->comision_servicio ?? 0;
                }

                if ($porcentaje > 0) {
                    $montoComision = $montoVenta * ($porcentaje / 100);

                    ComisionGenerada::create([
                        'empleado_id' => $estilista->id,
                        'venta_id' => $venta->id,
                        'monto_venta' => $montoVenta,
                        'porcentaje_aplicado' => $porcentaje,
                        'monto_comision' => $montoComision,
                        'fecha' => \Carbon\Carbon::now(),
                        'estado' => 'pendiente',
                    ]);
                }
            }
        }
    }
}
