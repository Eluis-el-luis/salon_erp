<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Venta;
use App\Models\Cita;
use App\Models\Articulo;
use App\Models\DetalleVenta;
use App\Models\MovimientoCajaChica;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PanelController extends Controller
{
    /**
     * Resuelve el rango de fechas según el filtro global (?rango=).
     * Valores: hoy | semana | mes | año
     */
    protected function resolverRango(Request $request): array
    {
        $rango = in_array($request->query('rango', 'hoy'), ['hoy', 'semana', 'mes', 'año', 'anio'])
            ? $request->query('rango')
            : 'hoy';

        $fin = Carbon::now();

        switch ($rango) {
            case 'semana':
                $inicio = Carbon::now()->startOfWeek();
                break;
            case 'mes':
                $inicio = Carbon::now()->startOfMonth();
                break;
            case 'año':
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

        // ==========================================
        // KPIs DE VENTAS EN EL RANGO
        // ==========================================
        $ventasQuery = Venta::where('status', 'completada')
            ->whereBetween('created_at', [$inicio, $fin]);

        $totalIngresos = (clone $ventasQuery)->sum('total');
        $ventasEfectivo = (clone $ventasQuery)->where('payment_method', 'efectivo')->sum('total');
        $ventasBac = (clone $ventasQuery)->where('payment_method', 'bac')->sum('total');
        $ventasLafise = (clone $ventasQuery)->where('payment_method', 'lafise')->sum('total');

        // Egresos del periodo (Caja Chica)
        $egresosCaja = MovimientoCajaChica::whereBetween('fecha', [$inicio, $fin])->sum('monto');

        // ==========================================
        // UTILIDAD BRUTA: Ingresos - Costo de Insumos (cuenta 5.2)
        // ==========================================
        $costoInsumos = DB::table('asientos_contables')
            ->join('detalle_asientos', 'detalle_asientos.asiento_id', '=', 'asientos_contables.id')
            ->join('cuentas_contables', 'cuentas_contables.id', '=', 'detalle_asientos.cuenta_id')
            ->where('cuentas_contables.codigo', '5.2')
            ->whereBetween('asientos_contables.fecha', [$inicio, $fin])
            ->sum('detalle_asientos.debe');

        $costoMercaderia = DB::table('asientos_contables')
            ->join('detalle_asientos', 'detalle_asientos.asiento_id', '=', 'asientos_contables.id')
            ->join('cuentas_contables', 'cuentas_contables.id', '=', 'detalle_asientos.cuenta_id')
            ->where('cuentas_contables.codigo', '5.1')
            ->whereBetween('asientos_contables.fecha', [$inicio, $fin])
            ->sum('detalle_asientos.debe');

        $costoTotal = $costoInsumos + $costoMercaderia;
        $utilidadBruta = $totalIngresos - $costoTotal;

        // ==========================================
        // AGENDA (del día, independiente del rango)
        // ==========================================
        $hoy = Carbon::today();
        $citasTotal = Cita::whereDate('appointment_date', $hoy)->count();
        $citasCompletadas = Cita::whereDate('appointment_date', $hoy)->where('status', 'completada')->count();
        $citasPendientes = $citasTotal - $citasCompletadas;

        // ==========================================
        // ALERTAS DE INVENTARIO (stock actual)
        // ==========================================
        $stockCritico = Articulo::where('type', '!=', 'servicio')->whereColumn('existencia_actual', '<=', 'stock_min')->count();

        // ==========================================
        // ÚLTIMAS TRANSACCIONES
        // ==========================================
        $ultimasVentas = Venta::with('cajero')->orderBy('created_at', 'desc')->take(5)->get();

        // ==========================================
        // RANKING DE SERVICIOS MÁS VENDIDOS
        // ==========================================
        $serviciosMasVendidos = DetalleVenta::select('service_id', DB::raw('SUM(quantity) as total_cantidad'))
            ->whereNotNull('service_id')
            ->whereHas('venta', function ($q) use ($inicio, $fin) {
                $q->where('status', 'completada')->whereBetween('created_at', [$inicio, $fin]);
            })
            ->with('servicio')
            ->groupBy('service_id')
            ->orderByDesc('total_cantidad')
            ->take(5)
            ->get();

        // ==========================================
        // SERIE DE VENTAS: últimos 7 días (gráfico de líneas)
        // ==========================================
        $ventas7Dias = collect(range(6, 0))->map(function ($i) {
            $d = Carbon::now()->subDays($i);
            return [
                'fecha' => $d->format('d/m'),
                'total' => (float) Venta::whereDate('created_at', $d)->where('status', 'completada')->sum('total'),
            ];
        });

        // Proporción por método de pago (gráfico donut)
        $pagosDonut = [
            'Efectivo' => (float) $ventasEfectivo,
            'BAC' => (float) $ventasBac,
            'LAFISE' => (float) $ventasLafise,
        ];

        // ==========================================
        // WIDGET DE META MENSUAL
        // ==========================================
        $metaMensual = (float) config('salon.meta_mensual', 150000);
        $ventasMes = Venta::where('status', 'completada')
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->sum('total');
        $progresoMeta = $metaMensual > 0 ? min(100, round(($ventasMes / $metaMensual) * 100, 1)) : 0;

        return view('dashboard', compact(
            'ventasEfectivo', 'ventasBac', 'ventasLafise', 'totalIngresos', 'egresosCaja',
            'citasTotal', 'citasPendientes', 'stockCritico', 'ultimasVentas',
            'rango', 'inicio', 'fin',
            'costoInsumos', 'costoMercaderia', 'costoTotal', 'utilidadBruta',
            'serviciosMasVendidos', 'ventas7Dias', 'pagosDonut',
            'metaMensual', 'ventasMes', 'progresoMeta'
        ));
    }
}