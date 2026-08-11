<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CashSession;
use App\Models\Sale;
use App\Services\ContabilidadService;
use Carbon\Carbon;

class CashController extends Controller
{
    // Mostrar la pantalla de Caja
    public function index()
    {
        // Cargamos la sesión actual incluyendo los datos del usuario responsable
        $session = CashSession::with('user')
                              ->where('user_id', auth()->id())
                              ->where('estado', 'abierta')
                              ->first();

        $ventasEfectivo = 0;
        if ($session) {
            // Solo sumamos las ventas en efectivo hechas por ESTE cajero durante ESTE turno
            $ventasEfectivo = Sale::where('payment_method', 'efectivo')
                                  ->where('cashier_id', auth()->id())
                                  ->where('created_at', '>=', $session->fecha_apertura)
                                  ->sum('total');
        }

        // Extraemos las últimas 10 cajas cerradas para la bitácora de auditoría
        $historial = CashSession::with('user')
                                ->where('estado', 'cerrada')
                                ->orderBy('fecha_cierre', 'desc')
                                ->take(10)
                                ->get();

        return view('cash.index', compact('session', 'ventasEfectivo', 'historial'));
    }

    // Abrir Turno
    public function open(Request $request)
    {
        $request->validate(['monto_apertura' => 'required|numeric|min:0']);

        CashSession::create([
            'user_id' => auth()->id(),
            'monto_apertura' => $request->monto_apertura,
            'estado' => 'abierta', 
            'fecha_apertura' => \Carbon\Carbon::now(),
        ]);

        return back()->with('success', 'Caja abierta con éxito. ¡Buen turno!');
    }

    // Cerrar Turno (Arqueo)
    public function close(Request $request)
    {
        $request->validate([
            'monto_fisico' => 'required|numeric|min:0',
            'monto_teorico' => 'required|numeric',
        ]);

        $session = CashSession::where('user_id', auth()->id())->where('estado', 'abierta')->firstOrFail();
        
        $diferencia = $request->monto_fisico - $request->monto_teorico;

        $session->update([
            'fecha_cierre' => Carbon::now(),
            'monto_teorico' => $request->monto_teorico,
            'monto_fisico' => $request->monto_fisico,
            'diferencia' => $diferencia,
            'estado' => 'cerrada'
        ]);

        // Disparamos la contabilidad automática
        $contabilidad = new ContabilidadService();
        $contabilidad->contabilizarArqueo($session);

        return back()->with('success', 'Arqueo realizado. Turno cerrado.');
    }
}