<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Venta;
use App\Models\Cita;
use App\Models\Articulo;
use App\Models\MovimientoCajaChica;
use Carbon\Carbon;

class PanelController extends Controller
{
    public function index()
    {
        $hoy = Carbon::today();

        // 1. CÁLCULOS DE ARQUEO POR TIPO DE PAGO
        $ventasEfectivo = Venta::whereDate('created_at', $hoy)->where('payment_method', 'efectivo')->sum('total');
        $ventasBac = Venta::whereDate('created_at', $hoy)->where('payment_method', 'bac')->sum('total');
        $ventasLafise = Venta::whereDate('created_at', $hoy)->where('payment_method', 'lafise')->sum('total');
        
        $totalIngresos = $ventasEfectivo + $ventasBac + $ventasLafise;

        // Egresos del Día (Caja Chica)
        $egresosCaja = MovimientoCajaChica::whereDate('fecha', $hoy)->sum('monto');

        // 2. MÉTRICAS DE LA AGENDA
        $citasTotal = Cita::whereDate('appointment_date', $hoy)->count();
        $citasCompletadas = Cita::whereDate('appointment_date', $hoy)->where('status', 'completada')->count();
        $citasPendientes = $citasTotal - $citasCompletadas;

        // 3. ALERTAS DE INVENTARIO
        $stockCritico = Articulo::where('type', '!=', 'servicio')->whereColumn('existencia_actual', '<=', 'stock_min')->count();

        // 4. ÚLTIMAS TRANSACCIONES
        $ultimasVentas = Venta::with('cajero')->orderBy('created_at', 'desc')->take(5)->get();

        return view('dashboard', compact(
            'ventasEfectivo', 'ventasBac', 'ventasLafise', 'totalIngresos', 'egresosCaja',
            'citasTotal', 'citasPendientes', 'stockCritico', 'ultimasVentas'
        ));
    }
}