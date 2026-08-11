<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sale;
use App\Models\User;
use App\Models\Item;
use App\Models\ComisionGenerada;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        // 1. Filtro de Fechas (Por defecto, el mes actual)
        $startDate = $request->start_date ? Carbon::parse($request->start_date)->startOfDay() : Carbon::now()->startOfMonth();
        $endDate = $request->end_date ? Carbon::parse($request->end_date)->endOfDay() : Carbon::now()->endOfMonth();

        // ==========================================
        // REPORTE 1: VENTAS GENERALES
        // ==========================================
        $ventas = Sale::whereBetween('created_at', [$startDate, $endDate])
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
        $estilistas = User::whereIn('role', ['estilista', 'admin'])
            ->withSum(['comisionesGeneradas' => function($query) use ($startDate, $endDate) {
                $query->whereBetween('fecha', [$startDate, $endDate]);
            }], 'monto_venta')
            ->withSum(['comisionesGeneradas' => function($query) use ($startDate, $endDate) {
                $query->whereBetween('fecha', [$startDate, $endDate]);
            }], 'monto_comision')
            ->get()
            ->filter(function($user) {
                // Solo mostrar a los que vendieron algo en este periodo
                return $user->comisiones_generadas_sum_monto_venta > 0;
            })
            ->sortByDesc('comisiones_generadas_sum_monto_venta');

        // ==========================================
        // REPORTE 3: VALORACIÓN DE INVENTARIO
        // ==========================================
        $inventario = Item::where('type', '!=', 'servicio')->orderBy('existencia_actual', 'asc')->get();
        
        $valorInventario = $inventario->sum(function($item) {
            return $item->existencia_actual * $item->precio_c;
        });
        
        $itemsCriticos = $inventario->where('existencia_actual', '<=', 'stock_min');

        return view('reports.index', compact(
            'startDate', 'endDate', 
            'totalVentas', 'totalDescuentos', 'cantidadFacturas', 'ventasPorMetodo',
            'estilistas',
            'inventario', 'valorInventario', 'itemsCriticos'
        ));
    }

    public function exportExcel(Request $request)
    {
        $startDate = $request->start_date ? Carbon::parse($request->start_date)->startOfDay() : Carbon::now()->startOfMonth();
        $endDate = $request->end_date ? Carbon::parse($request->end_date)->endOfDay() : Carbon::now()->endOfMonth();

        // Traemos las ventas con sus clientes
        $ventas = Sale::with('client')->whereBetween('created_at', [$startDate, $endDate])->where('status', 'completada')->get();

        $fileName = 'Ventas_AlvaroRugama_' . $startDate->format('d-m-Y') . '.csv';

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
                    $venta->client ? $venta->client->name : 'Público en General',
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
        $startDate = $request->start_date ? Carbon::parse($request->start_date)->startOfDay() : Carbon::now()->startOfMonth();
        $endDate = $request->end_date ? Carbon::parse($request->end_date)->endOfDay() : Carbon::now()->endOfMonth();

        // Recopilar datos exactos de la pantalla
        $ventas = Sale::whereBetween('created_at', [$startDate, $endDate])->where('status', 'completada')->get();
        $totalVentas = $ventas->sum('total');
        
        $ventasPorMetodo = [
            'efectivo' => $ventas->where('payment_method', 'efectivo')->sum('total'),
            'bac' => $ventas->where('payment_method', 'bac')->sum('total'),
            'lafise' => $ventas->where('payment_method', 'lafise')->sum('total'),
        ];

        $estilistas = User::whereIn('role', ['estilista', 'admin'])
            ->withSum(['comisionesGeneradas' => function($query) use ($startDate, $endDate) {
                $query->whereBetween('fecha', [$startDate, $endDate]);
            }], 'monto_venta')
            ->withSum(['comisionesGeneradas' => function($query) use ($startDate, $endDate) {
                $query->whereBetween('fecha', [$startDate, $endDate]);
            }], 'monto_comision')
            ->get()
            ->filter(fn($user) => $user->comisiones_generadas_sum_monto_venta > 0)
            ->sortByDesc('comisiones_generadas_sum_monto_venta');

        return view('reports.pdf', compact('startDate', 'endDate', 'totalVentas', 'ventasPorMetodo', 'estilistas'));
    }
}