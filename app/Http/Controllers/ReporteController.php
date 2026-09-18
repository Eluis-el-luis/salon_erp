<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Venta;
use App\Models\Usuario;
use App\Models\Articulo;
use App\Models\ComisionGenerada;
use App\Models\DiferencialCambiario;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReporteController extends Controller
{
    public function index(Request $request)
    {
        // 1. Filtro de Fechas (Por defecto, el mes actual)
        $fechaInicio = $request->start_date ? Carbon::parse($request->start_date)->startOfDay() : Carbon::now()->startOfMonth();
        $fechaFin = $request->end_date ? Carbon::parse($request->end_date)->endOfDay() : Carbon::now()->endOfMonth();

        // ==========================================
        // REPORTE 1: VENTAS GENERALES
        // ==========================================
        $ventas = Venta::whereBetween('created_at', [$fechaInicio, $fechaFin])
                      ->where('status', 'completada')
                      ->get();
                      
        $totalVentas = $ventas->sum('total');
        $totalDescuentos = $ventas->sum('discount');
        $cantidadFacturas = $ventas->count();
        
        $ventasPorMetodo = [
            'efectivo' => $ventas->where('payment_method', 'efectivo')->sum('total'),
            'bac' => $ventas->where('payment_method', 'bac')->sum('total'),
            'lafise' => $ventas->where('payment_method', 'lafise')->sum('total'),
            'tarjeta' => $ventas->where('payment_method', 'tarjeta')->sum('total'),
            'transferencia' => $ventas->where('payment_method', 'transferencia')->sum('total'),
        ];

        // ==========================================
        // REPORTE 2: RENDIMIENTO POR ESTILISTA
        // Aprovechamos la tabla Comisiones_Generadas
        // ==========================================
        $estilistas = Usuario::whereIn('role', ['estilista', 'admin'])
            ->withSum(['comisionesGeneradas' => function($query) use ($fechaInicio, $fechaFin) {
                $query->whereBetween('fecha', [$fechaInicio, $fechaFin]);
            }], 'monto_venta')
            ->withSum(['comisionesGeneradas' => function($query) use ($fechaInicio, $fechaFin) {
                $query->whereBetween('fecha', [$fechaInicio, $fechaFin]);
            }], 'monto_comision')
            ->get()
            ->filter(function($usuario) {
                // Solo mostrar a los que vendieron algo en este periodo
                return $usuario->comisiones_generadas_sum_monto_venta > 0;
            })
            ->sortByDesc('comisiones_generadas_sum_monto_venta');

        // ==========================================
        // REPORTE 3: VALORACIÓN DE INVENTARIO
        // ==========================================
        $inventario = Articulo::where('type', '!=', 'servicio')->orderBy('existencia_actual', 'asc')->get();
        
        $valorInventario = $inventario->sum(function($articulo) {
            return $articulo->existencia_actual * $articulo->precio_c;
        });
        
        $articulosCriticos = $inventario->where('existencia_actual', '<=', 'stock_min');

        // ==========================================
        // REPORTE 4: DIFERENCIAL CAMBIARIO (Mesa de Cambio)
        // ==========================================
        $diferenciales = DiferencialCambiario::whereBetween('fecha', [$fechaInicio, $fechaFin])->get();

        $totalGananciaCambiaria = round($diferenciales->where('tipo', 'ganancia')->sum('diferencia_calculada'), 2);
        $totalPerdidaCambiaria = round($diferenciales->where('tipo', 'perdida')->sum('diferencia_calculada'), 2);
        $diferencialNeto = round($totalGananciaCambiaria - $totalPerdidaCambiaria, 2);

        return view('reports.index', compact(
            'fechaInicio', 'fechaFin', 
            'totalVentas', 'totalDescuentos', 'cantidadFacturas', 'ventasPorMetodo',
            'estilistas',
            'inventario', 'valorInventario', 'articulosCriticos',
            'diferenciales', 'totalGananciaCambiaria', 'totalPerdidaCambiaria', 'diferencialNeto'
        ));
    }

    public function exportExcel(Request $request)
    {
        $fechaInicio = $request->start_date ? Carbon::parse($request->start_date)->startOfDay() : Carbon::now()->startOfMonth();
        $fechaFin = $request->end_date ? Carbon::parse($request->end_date)->endOfDay() : Carbon::now()->endOfMonth();

        // Traemos las ventas con sus clientes
        $ventas = Venta::with('cliente')->whereBetween('created_at', [$fechaInicio, $fechaFin])->where('status', 'completada')->get();

        $fileName = 'Ventas_AlvaroRugama_' . $fechaInicio->format('d-m-Y') . '.csv';

        $headers = [
            "Content-type"        => "text/csv; charset=UTF-8",
            "Content-Disposition" => "attachment; filename=$fileName",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $callback = function() use($ventas) {
            $file = fopen('php://output', 'w');
            
            // Inyectar BOM (Byte Order Mark) para que Excel lea tildes y acentos correctamente
            fputs($file, $bom = ( chr(0xEF) . chr(0xBB) . chr(0xBF) ));
            
            // Fila de Encabezados
            fputcsv($file, ['Fecha', 'Factura', 'Cliente', 'Moneda', 'Metodo de Pago', 'Subtotal (C$)', 'Descuento (C$)', 'Total (C$)']);

            // Filas de Datos
            foreach ($ventas as $venta) {
                fputcsv($file, [
                    $venta->created_at->format('d/m/Y h:i A'),
                    'INV-' . str_pad($venta->id, 5, '0', STR_PAD_LEFT),
                    $venta->cliente ? $venta->cliente->name : 'Público en General',
                    strtoupper($venta->currency),
                    strtoupper($venta->payment_method),
                    $venta->subtotal,
                    $venta->discount,
                    $venta->total
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function exportPdf(Request $request)
    {
        $fechaInicio = $request->start_date ? Carbon::parse($request->start_date)->startOfDay() : Carbon::now()->startOfMonth();
        $fechaFin = $request->end_date ? Carbon::parse($request->end_date)->endOfDay() : Carbon::now()->endOfMonth();

        // Recopilar datos exactos de la pantalla
        $ventas = Venta::whereBetween('created_at', [$fechaInicio, $fechaFin])->where('status', 'completada')->get();
        $totalVentas = $ventas->sum('total');
        
        $ventasPorMetodo = [
            'efectivo' => $ventas->where('payment_method', 'efectivo')->sum('total'),
            'bac' => $ventas->where('payment_method', 'bac')->sum('total'),
            'lafise' => $ventas->where('payment_method', 'lafise')->sum('total'),
        ];

        $estilistas = Usuario::whereIn('role', ['estilista', 'admin'])
            ->withSum(['comisionesGeneradas' => function($query) use ($fechaInicio, $fechaFin) {
                $query->whereBetween('fecha', [$fechaInicio, $fechaFin]);
            }], 'monto_venta')
            ->withSum(['comisionesGeneradas' => function($query) use ($fechaInicio, $fechaFin) {
                $query->whereBetween('fecha', [$fechaInicio, $fechaFin]);
            }], 'monto_comision')
            ->get()
            ->filter(fn($usuario) => $usuario->comisiones_generadas_sum_monto_venta > 0)
            ->sortByDesc('comisiones_generadas_sum_monto_venta');

        return view('reports.pdf', compact('fechaInicio', 'fechaFin', 'totalVentas', 'ventasPorMetodo', 'estilistas'));
    }
}