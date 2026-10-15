<?php

namespace App\Services;

use App\Models\CompanyTarget;
use App\Models\Venta;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 * SERVICIO DE BI DEL DASHBOARD GERENCIAL (Centro de Mando)
 * ============================================================================
 * ÚNICA FUENTE DE LA VERDAD: el Libro Mayor (detalle_asientos).
 *
 * Todos los indicadores financieros se leen del ledger con el MISMO criterio
 * que ContabilidadController::resultados (Estado de Resultados):
 *   - Se excluyen SIEMPRE los asientos modulo_origen = 'cierre_fiscal'.
 *   - Solo participan cuentas con permite_movimiento = true.
 *   - Convención de signo: clase 4 (Ingresos) = HABER - DEBE;
 *     clases 5 (COGS), 6 (Gastos) y 7 (Otros egresos) = DEBE - HABER.
 *
 * Así el Dashboard coincide al 100% con el Estado de Resultados: las ventas
 * anuladas (asientos REV-*) restan por su espejo, las mermas (6.8) y
 * diferenciales cambiarios (7.5) pesan en gastos, y las compras de inventario
 * (activo 1.1.x) JAMÁS se confunden con el costo de lo vendido (5.x).
 *
 * Únicos datos operativos fuera del ledger (porque el mayor no los desagrega):
 *   - Ranking de servicios: unidades por línea (sale_details), en una sola
 *     consulta agrupada con join (sin N+1).
 *   - Agenda e inventario: recuentos operativos del día.
 */
class DashboardService
{
    /** Los cierres fiscales nunca distorsionan el panel (igual que el EERR). */
    private const MODULO_CIERRE_FISCAL = 'cierre_fiscal';

    /** Cuentas de fondos que delatan el método de cobro en el asiento de venta. */
    private const CUENTAS_FONDOS = ['1.1.1', '1.1.1.2', '1.1.2.1', '1.1.2.2'];

    // =====================================================================
    // LIBRO MAYOR: P&L DEL RANGO
    // =====================================================================

    /**
     * KPIs financieros del rango leídos del mayor. Una sola consulta con
     * CASE por clase contable (4 ingresos / 5 costos / 6 gastos / 7 otros).
     */
    public function kpisRango(Carbon $desde, Carbon $hasta): array
    {
        $fila = $this->consultaResultados($desde, $hasta)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN c.codigo LIKE '4.%' THEN d.haber - d.debe ELSE 0 END), 0) as ingresos,
                COALESCE(SUM(CASE WHEN c.codigo LIKE '5.%' THEN d.debe - d.haber ELSE 0 END), 0) as costos,
                COALESCE(SUM(CASE WHEN c.codigo LIKE '6.%' THEN d.debe - d.haber ELSE 0 END), 0) as gastos_operativos,
                COALESCE(SUM(CASE WHEN c.codigo NOT LIKE '4.%' AND c.codigo NOT LIKE '5.%' AND c.codigo NOT LIKE '6.%' THEN d.debe - d.haber ELSE 0 END), 0) as otros_egresos
            ")
            ->first();

        $ingresos = round((float) $fila->ingresos, 2);
        $costos = round((float) $fila->costos, 2);

        return [
            'ingresos' => $ingresos,
            'costos' => $costos,
            'gastos_operativos' => round((float) $fila->gastos_operativos, 2),
            'otros_egresos' => round((float) $fila->otros_egresos, 2),
            'utilidad_bruta' => round($ingresos - $costos, 2),
            'margen_bruto' => $ingresos > 0 ? round(($ingresos - $costos) / $ingresos * 100, 1) : 0.0,
        ];
    }

    /**
     * Serie temporal del mayor (ingresos y costos por periodo), agrupada por
     * día o por mes. Rellena los períodos vacíos para que Chart.js no salte.
     */
    public function serieTemporal(Carbon $desde, Carbon $hasta, string $granularidad = 'dia'): array
    {
        $agrupacion = $granularidad === 'mes'
            ? "DATE_FORMAT(a.fecha, '%Y-%m')"
            : 'a.fecha';

        $filas = $this->consultaResultados($desde, $hasta)
            ->selectRaw("
                {$agrupacion} as periodo,
                COALESCE(SUM(CASE WHEN c.codigo LIKE '4.%' THEN d.haber - d.debe ELSE 0 END), 0) as ingresos,
                COALESCE(SUM(CASE WHEN c.codigo LIKE '5.%' THEN d.debe - d.haber ELSE 0 END), 0) as costos
            ")
            ->groupBy(DB::raw($agrupacion))
            ->get()
            ->keyBy(fn ($f) => (string) $f->periodo);

        $labels = [];
        $ingresos = [];
        $costos = [];

        if ($granularidad === 'mes') {
            $cursor = $desde->copy()->startOfMonth();
            while ($cursor <= $hasta) {
                $clave = $cursor->format('Y-m');
                $labels[] = $cursor->isoFormat('MMM YY');
                $ingresos[] = (float) ($filas[$clave]->ingresos ?? 0);
                $costos[] = (float) ($filas[$clave]->costos ?? 0);
                $cursor->addMonth();
            }
        } else {
            foreach (Carbon::parse($desde->toDateString())->daysUntil($hasta->copy()->endOfDay()) as $dia) {
                $clave = $dia->toDateString();
                $labels[] = $dia->format('d/m');
                $ingresos[] = (float) ($filas[$clave]->ingresos ?? 0);
                $costos[] = (float) ($filas[$clave]->costos ?? 0);
            }
        }

        return [
            'labels' => $labels,
            'ingresos' => $ingresos,
            'costos' => $costos,
        ];
    }

    /**
     * COBROS POR MÉTODO leídos del ledger: en el asiento de venta (ING-*,
     * COB-*, REV-*) el DEBE neto de cada cuenta de fondos delata cómo entró
     * el dinero: caja NIO/USD = efectivo, banco LAFISE o BAC = tarjeta.
     * Las ventas a crédito (CxC) y las anuladas no cuentan hasta cobrarse.
     */
    public function cobrosPorMetodo(Carbon $desde, Carbon $hasta): array
    {
        $filas = DB::table('detalle_asientos as d')
            ->join('asientos_contables as a', 'a.id', '=', 'd.asiento_id')
            ->join('cuentas_contables as c', 'c.id', '=', 'd.cuenta_id')
            ->where('a.modulo_origen', 'ventas')
            ->whereBetween('a.fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->whereIn('c.codigo', self::CUENTAS_FONDOS)
            ->groupBy('c.codigo')
            ->selectRaw('c.codigo, COALESCE(SUM(d.debe - d.haber), 0) as monto')
            ->pluck('monto', 'codigo');

        return [
            'efectivo' => round((float) ($filas['1.1.1'] ?? 0) + (float) ($filas['1.1.1.2'] ?? 0), 2),
            'lafise' => round((float) ($filas['1.1.2.1'] ?? 0), 2),
            'bac' => round((float) ($filas['1.1.2.2'] ?? 0), 2),
        ];
    }

    // =====================================================================
    // META MENSUAL (company_targets)
    // =====================================================================

    /**
     * Meta del mes en curso con su avance REAL (ingresos clase 4 del mes
     * según el mayor, no sumas de sales). Siempre independiente del rango.
     */
    public function metaMes(): array
    {
        $inicioMes = Carbon::now()->startOfMonth();
        $finMes = Carbon::now()->endOfMonth();

        $meta = CompanyTarget::montoParaMes(Carbon::now());
        $avance = $this->kpisRango($inicioMes, $finMes)['ingresos'];
        $monto = (float) $meta['monto'];

        return [
            'monto' => $monto,
            'fuente' => $meta['fuente'],
            'avance' => $avance,
            'progreso' => $monto > 0 ? min(100, round($avance / $monto * 100, 1)) : 0.0,
            'periodo' => $inicioMes->format('Y-m'),
        ];
    }

    // =====================================================================
    // DATOS OPERATIVOS (complementarios al ledger)
    // =====================================================================

    /**
     * Ranking de servicios del rango: una única consulta agrupada con join
     * (cantidad de unidades e ingreso facturado). Sin N+1.
     */
    public function rankingServicios(Carbon $desde, Carbon $hasta, int $take = 5): array
    {
        return DB::table('sale_details as sd')
            ->join('sales as v', 'v.id', '=', 'sd.sale_id')
            ->join('services as s', 's.id', '=', 'sd.service_id')
            ->whereIn('v.status', Venta::ESTADOS_DEVENGADOS)
            ->whereBetween('v.created_at', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->groupBy('sd.service_id', 's.name')
            ->orderByDesc('monto')
            ->limit($take)
            ->selectRaw("sd.service_id as id, s.name as servicio,
                SUM(sd.quantity) as unidades, SUM(sd.quantity * sd.unit_price) as monto")
            ->get()
            ->map(fn ($f) => [
                'id' => (int) $f->id,
                'servicio' => $f->servicio,
                'unidades' => (int) $f->unidades,
                'monto' => round((float) $f->monto, 2),
            ])
            ->values()
            ->toArray();
    }

    /**
     * Últimos movimientos de caja con EAGER LOADING explícito (cajero y
     * cliente seleccionan columnas mínimas; recuento de pagos en subconsulta)
     * para evitar N+1 en el listado.
     */
    public function ultimosMovimientos(int $take = 6)
    {
        return Venta::query()
            ->with([
                'cajero:id,name,role',
                'cliente:id,name',
            ])
            ->withCount(['pagos as pagos_count' => fn ($q) => $q->where('tipo', 'pago')])
            ->orderByDesc('created_at')
            ->limit($take)
            ->get();
    }

    // =====================================================================
    // PAYLOAD COMPLETO (usa index() y datos())
    // =====================================================================

    /**
     * Payload BI del rango: KPIs del mayor, serie, cobros por método,
     * ranking y meta. Consumido por la vista inicial y por el endpoint
     * asíncrono /panel/api/datos.
     */
    public function panel(Carbon $desde, Carbon $hasta, string $rango): array
    {
        [$serieDesde, $serieHasta, $granularidad] = $this->resolverSerie($rango);

        return [
            'rango' => $rango,
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'kpis' => $this->kpisRango($desde, $hasta),
            'serie' => $this->serieTemporal($serieDesde, $serieHasta, $granularidad),
            'metodo' => $this->cobrosPorMetodo($desde, $hasta),
            'ranking' => $this->rankingServicios($desde, $hasta),
            'meta' => $this->metaMes(),
        ];
    }

    /**
     * Granularidad del gráfico según el filtro: hoy/semana → días,
     * mes → días del mes, año → meses del año. Para 'hoy' se muestra el
     * contexto de los últimos 7 días (el ledger solo fecha a nivel día).
     * Semana/mes/año cubren el periodo COMPLETO (días futuros en cero),
     * como espera un panel BI.
     */
    protected function resolverSerie(string $rango): array
    {
        return match ($rango) {
            'semana' => [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek(), 'dia'],
            'mes' => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(), 'dia'],
            'anio' => [Carbon::now()->startOfYear(), Carbon::now()->endOfYear(), 'mes'],
            default => [Carbon::today()->subDays(6), Carbon::today(), 'dia'],
        };
    }

    /** Consulta base compartida por KPIs y serie (mismo criterio del EERR). */
    protected function consultaResultados(Carbon $desde, Carbon $hasta)
    {
        return DB::table('detalle_asientos as d')
            ->join('asientos_contables as a', 'a.id', '=', 'd.asiento_id')
            ->join('cuentas_contables as c', 'c.id', '=', 'd.cuenta_id')
            ->where('a.modulo_origen', '!=', self::MODULO_CIERRE_FISCAL)
            ->where('c.permite_movimiento', true)
            ->where(function ($q) {
                $q->where('c.codigo', 'like', '4.%')
                    ->orWhere('c.codigo', 'like', '5.%')
                    ->orWhere('c.codigo', 'like', '6.%')
                    ->orWhere('c.codigo', 'like', '7.%');
            })
            ->whereBetween('a.fecha', [$desde->toDateString(), $hasta->toDateString()]);
    }
}
