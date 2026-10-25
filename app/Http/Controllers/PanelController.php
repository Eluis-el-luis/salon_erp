<?php

namespace App\Http\Controllers;

use App\Models\Articulo;
use App\Models\AsientoContable;
use App\Models\Cita;
use App\Models\CompanyTarget;
use App\Models\Venta;
use App\Services\DashboardService;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * DASHBOARD GERENCIAL (Centro de Mando) — arquitectura BI:
 *
 *  - El financiero se lee EXCLUSIVAMENTE del Libro Mayor vía
 *    DashboardService (detalle_asientos), misma fuente del Estado de
 *    Resultados. Ver §Dashboard en AGENTS.md.
 *  - index() pinta el primer render; datos() alimenta la SPA asíncrona
 *    (Alpine.js + fetch) al cambiar el filtro de tiempo.
 *  - movimiento() sirve el Quick View (modal) de un movimiento de caja.
 *  - guardarMeta() persiste la meta mensual dinámica (company_targets).
 */
class PanelController extends Controller
{
    public function __construct(private DashboardService $bi) {}

    /**
     * Resuelve el rango de fechas según el filtro global (?rango=).
     * Valores: hoy | semana | mes | anio (se acepta 'año' por compatibilidad
     * de URLs antiguas y se normaliza a 'anio').
     */
    protected function resolverRango(Request $request): array
    {
        $rango = $request->query('rango', 'hoy');
        if (! in_array($rango, ['hoy', 'semana', 'mes', 'año', 'anio'])) {
            $rango = 'hoy';
        }
        if ($rango === 'año') {
            $rango = 'anio';
        }

        $fin = Carbon::now();

        switch ($rango) {
            case 'semana':
                $inicio = Carbon::now()->startOfWeek();
                break;
            case 'mes':
                $inicio = Carbon::now()->startOfMonth();
                break;
            case 'anio':
                $inicio = Carbon::now()->startOfYear();
                break;
            default:
                $inicio = Carbon::today();
                break;
        }

        return [$inicio, $fin, $rango];
    }

    public function index(Request $request)
    {
        [$inicio, $fin, $rango] = $this->resolverRango($request);

        // PAYLOAD BI inicial (el mismo que devuelve /panel/api/datos): el
        // primer render ya trae KPIs del mayor, serie, métodos, ranking y meta.
        $estado = $this->bi->panel($inicio, $fin, $rango);

        // ==========================================
        // KPIs OPERATIVOS (independientes del rango)
        // ==========================================
        $hoy = Carbon::today();
        $citasTotal = Cita::whereDate('appointment_date', $hoy)->count();
        $citasCompletadas = Cita::whereDate('appointment_date', $hoy)->where('status', 'completada')->count();

        // ALERTAS DE INVENTARIO: críticos AHORA (drill-down: /inventario?stock=critico)
        $stockCritico = Articulo::where('type', '!=', 'servicio')
            ->whereColumn('existencia_actual', '<=', 'stock_min')
            ->count();

        // ÚLTIMOS MOVIMIENTOS (eager loading: cajero + cliente + conteo de pagos)
        $ultimasVentas = $this->bi->ultimosMovimientos();

        // FLAGS DE ROL para drill-down condicional (mismo criterio del menú:
        // el admin tiene bypass, nunca 403 desde el dashboard).
        $rol = auth()->user()->role ?? null;

        return view('dashboard', [
            'estado' => $estado,
            'rango' => $rango,
            'citasTotal' => $citasTotal,
            'citasPendientes' => max(0, $citasTotal - $citasCompletadas),
            'stockCritico' => $stockCritico,
            'ultimasVentas' => $ultimasVentas,
            'puedeFinanzas' => in_array($rol, ['admin', 'contador']),
            'puedeCaja' => in_array($rol, ['admin', 'recepcion', 'contador']),
            'puedeInventario' => $rol === 'admin',
            'puedeEditarMeta' => $rol === 'admin',
        ]);
    }

    /**
     * ENDPOINT BI ASÍNCRONO: devuelve el payload completo del rango pedido
     * (KPIs del mayor, serie temporal, cobros por método, ranking y meta).
     * Lo consume Alpine.js al pulsar Hoy/Semana/Mes/Año sin recargar la página.
     */
    public function datos(Request $request)
    {
        [$inicio, $fin, $rango] = $this->resolverRango($request);

        return response()->json($this->bi->panel($inicio, $fin, $rango));
    }

    /**
     * DRILL-DOWN (Quick View): detalle de un movimiento de caja en JSON para
     * el modal del dashboard — líneas del ticket, libro de pagos, estado y
     * asientos contables vinculados (para saltar al Libro Diario).
     */
    public function movimiento($id)
    {
        $venta = Venta::with([
            'cliente:id,name',
            'cajero:id,name,role',
            'detalles.servicio:id,name',
            'detalles.articulo:id,producto',
            'detalles.variante:id,name',
            'detalles.estilista:id,name',
            'pagos',
            'anuladaPor:id,name',
        ])->findOrFail($id);

        // Asientos de origen de la venta (ING-*, COG-*, REV-*): trazabilidad
        // completa sin abandonar el panel.
        $asientos = AsientoContable::where('modulo_origen', 'ventas')
            ->where('referencia_id', $venta->id)
            ->orderBy('id')
            ->get(['id', 'numero_asiento', 'concepto', 'fecha']);

        return response()->json([
            'id' => $venta->id,
            'estado' => $venta->status,
            'fecha' => $venta->created_at->format('d/m/Y'),
            'hora' => $venta->created_at->format('h:i A'),
            'cliente' => $venta->cliente?->name ?? 'Público general',
            'cajero' => $venta->cajero?->name ?? 'Admin Salón',
            'subtotal' => (float) $venta->subtotal,
            'descuento' => (float) $venta->discount,
            'total' => (float) $venta->total,
            'items' => $venta->detalles->map(function ($d) {
                return [
                    'producto' => trim(($d->servicio?->name ?? $d->articulo?->producto ?? 'Ítem')
                        .($d->variante ? ' ('.$d->variante->name.')' : '')),
                    'estilista' => $d->estilista?->name,
                    'cantidad' => (int) $d->quantity,
                    'precio' => (float) $d->unit_price,
                    'subtotal' => round((float) $d->quantity * (float) $d->unit_price, 2),
                ];
            })->values(),
            'pagos' => $venta->pagos->map(fn ($p) => [
                'tipo' => $p->tipo,
                'metodo' => $p->metodo,
                'moneda' => $p->moneda,
                'monto' => (float) $p->monto,
                'valor_nio' => (float) $p->valor_nio,
            ])->values(),
            'anulacion' => $venta->status === Venta::ESTADO_ANULADA ? [
                'por' => $venta->anuladaPor?->name,
                'motivo' => $venta->motivo_anulacion,
                'fecha' => $venta->anulada_at?->format('d/m/Y h:i A'),
            ] : null,
            'asientos' => $asientos->map(fn ($a) => [
                'id' => $a->id,
                'numero' => $a->numero_asiento,
                'concepto' => $a->concepto,
                'fecha' => $a->fecha,
            ])->values(),
        ]);
    }

    /**
     * CONFIGURACIÓN DE METAS: persiste la meta de facturación del mes en
     * curso (company_targets). Ruta protegida con role:admin en web.php.
     */
    public function guardarMeta(Request $request)
    {
        $datos = $request->validate([
            'monto' => 'required|numeric|min:0|max:9999999999.99',
        ]);

        CompanyTarget::updateOrCreate(
            ['periodo' => now()->startOfMonth()->toDateString()],
            [
                'monto' => round((float) $datos['monto'], 2),
                'moneda' => 'NIO',
                'creado_por' => auth()->id(),
            ]
        );

        return response()->json(['meta' => $this->bi->metaMes()]);
    }
}
