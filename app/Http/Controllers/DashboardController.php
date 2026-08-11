<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sale;
use App\Models\Appointment;
use App\Models\Item;
use App\Models\MovimientoCajaChica;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $today = Carbon::today();

        // 1. CÁLCULOS DE ARQUEO POR TIPO DE PAGO
        $ventasEfectivo = Sale::whereDate('created_at', $today)->where('payment_method', 'efectivo')->sum('total');
        $ventasBac = Sale::whereDate('created_at', $today)->where('payment_method', 'bac')->sum('total');
        $ventasLafise = Sale::whereDate('created_at', $today)->where('payment_method', 'lafise')->sum('total');
        
        $totalIngresos = $ventasEfectivo + $ventasBac + $ventasLafise;

        // Egresos del Día (Caja Chica)
        $egresosCaja = MovimientoCajaChica::whereDate('fecha', $today)->sum('monto');

        // 2. MÉTRICAS DE LA AGENDA
        $citasTotal = Appointment::whereDate('appointment_date', $today)->count();
        $citasCompletadas = Appointment::whereDate('appointment_date', $today)->where('status', 'completada')->count();
        $citasPendientes = $citasTotal - $citasCompletadas;

        // 3. ALERTAS DE INVENTARIO
        $stockCritico = Item::where('type', '!=', 'servicio')->whereColumn('existencia_actual', '<=', 'stock_min')->count();

        // 4. ÚLTIMAS TRANSACCIONES
        $ultimasVentas = Sale::with('cashier')->orderBy('created_at', 'desc')->take(5)->get();

        return view('dashboard', compact(
            'ventasEfectivo', 'ventasBac', 'ventasLafise', 'totalIngresos', 'egresosCaja',
            'citasTotal', 'citasPendientes', 'stockCritico', 'ultimasVentas'
        ));
    }
}