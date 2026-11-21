<?php

namespace App\Services;

use App\Models\ComisionGenerada;
use App\Models\Usuario;
use App\Models\Venta;
use App\Exceptions\ContabilidadException;
use App\Support\InventoryDecimal as D;
use Illuminate\Support\Facades\DB;

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
        if (PrestacionCompartidaService::nueva($venta)) {
            app(PrestacionCompartidaService::class)->validarDocumento($venta);
            foreach ($venta->detalles as $d) foreach ($d->service_id ? $d->participantes : [] as $p) {
                $resolved = $venta->sharedCommissionResolutions[$p->id] ?? null;
                if (!$resolved || $p->commission_resolution_snapshot !== null || ComisionGenerada::where('service_participation_id', $p->id)->exists()) throw new ContabilidadException('La comisión requiere resolución actual y no puede aplicarse dos veces.');
                $base = D::physical($d->getRawOriginal('unit_price'), $d->quantity);
                $amount = D::ratio($base, $resolved['rate'], 10000);
                if ($resolved['rate'] > 0) ComisionGenerada::create(['service_participation_id' => $p->id,
                    'empleado_id' => $p->user_id, 'venta_id' => $venta->id, 'monto_venta' => D::text($base),
                    'porcentaje_aplicado' => D::text($resolved['rate']), 'monto_comision' => D::text($amount), 'fecha' => now(), 'estado' => 'pendiente']);
                $p->commission_resolution_snapshot = $resolved['source'] + ($resolved['rate'] === 0 ? ['percentage' => '0.00', 'amount' => '0.00'] : []);
                $p->save();
            }
            return;
        }
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

    /** Después de usuarios/cita/venta, antes de artículos. Sin crear devengado temprano. */
    public function prepararParticipaciones(Venta $venta): void
    {
        if (!PrestacionCompartidaService::nueva($venta)) return;
        app(PrestacionCompartidaService::class)->validarDocumento($venta);
        if (DB::transactionLevel() === 0) throw new ContabilidadException('La comisión requiere transacción.');
        $users = $venta->detalles->filter(fn ($d) => $d->service_id)->flatMap(fn ($d) => $d->participantes->pluck('user_id'))->unique()->sort()->values();
        $resolvedUsers = [];
        foreach ($users as $id) {
            $user = Usuario::whereKey($id)->lockForUpdate()->firstOrFail();
            $scheme = $user->esquemaActual()->lockForUpdate()->first();
            if ($scheme) $scheme->setRelation('rangos', $scheme->rangos()->lockForUpdate()->get());
            $resolvedUsers[$id] = [$user, $scheme];
        }
        foreach ($venta->detalles as $d) foreach ($d->service_id ? $d->participantes : [] as $p) {
            [$user, $scheme] = $resolvedUsers[$p->user_id];
            $base = D::physical($d->getRawOriginal('unit_price'), $d->quantity);
            $resolved = $this->resolver($user, $scheme, $base);
            if (D::ratio($base, $resolved['rate'], 10000) > CarritoVentaService::MAX_CENTAVOS) throw new ContabilidadException('La comisión excede la precisión monetaria persistida.');
            $venta->sharedCommissionResolutions[$p->id] = $resolved;
        }
    }

    protected function resolver(Usuario $user, $scheme, int $base): array
    {
        $range = $scheme ? $scheme->rangos->sortBy('orden')->first(fn ($r) => D::scaled($r->desde) <= $base && ($r->hasta === null || D::scaled($r->hasta) >= $base)) : null;
        $percent = $range?->porcentaje ?? ($scheme ? ($scheme->porcentaje_comision ?? 0) : ($user->comision_servicio ?? 0));
        return ['rate' => D::scaled($percent, 2, 99999), 'source' => ['version' => 1,
            'source' => $range ? 'range' : ($scheme ? 'scheme' : 'profile'), 'scheme_id' => $scheme?->id,
            'range_id' => $range?->id, 'valid_from' => $scheme?->vigente_desde, 'valid_until' => $scheme?->vigente_hasta,
            'range_from' => $range?->desde, 'range_until' => $range?->hasta, 'range_order' => $range?->orden]];
    }
}
