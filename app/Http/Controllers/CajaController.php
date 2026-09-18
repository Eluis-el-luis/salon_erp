<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SesionCaja;
use App\Models\Venta;
use App\Services\ContabilidadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CajaController extends Controller
{
    // Mostrar la pantalla de Caja
    public function index()
    {
        // Cargamos la sesión actual incluyendo los datos del usuario responsable
        $sesion = SesionCaja::with('usuario')
                              ->where('user_id', auth()->id())
                              ->where('estado', 'abierta')
                              ->first();

        $ventasEfectivo = 0;
        if ($sesion) {
            // Solo sumamos las ventas en efectivo hechas por ESTE cajero durante ESTE turno
            $ventasEfectivo = Venta::where('payment_method', 'efectivo')
                                  ->where('cashier_id', auth()->id())
                                  ->where('created_at', '>=', $sesion->fecha_apertura)
                                  ->sum('total');
        }

        // Extraemos las últimas 10 cajas cerradas para la bitácora de auditoría
        $historial = SesionCaja::with('usuario')
                                ->where('estado', 'cerrada')
                                ->orderBy('fecha_cierre', 'desc')
                                ->take(10)
                                ->get();

        return view('cash.index', compact('sesion', 'ventasEfectivo', 'historial'));
    }

    // Abrir Turno
    public function open(Request $request)
    {
        $request->validate(['monto_apertura' => 'required|numeric|min:0']);

        SesionCaja::create([
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
        ]);

        DB::beginTransaction();

        try {
            $sesion = SesionCaja::where('user_id', auth()->id())->where('estado', 'abierta')->firstOrFail();

            // MANDAMIENTO: El monto teórico SIEMPRE lo calcula el backend.
            // Nunca se confía en el valor que envía el frontend.
            $ventasEfectivoTurno = Venta::where('payment_method', 'efectivo')
                ->where('cashier_id', auth()->id())
                ->where('created_at', '>=', $sesion->fecha_apertura)
                ->sum('total');

            $montoTeorico = round((float) $sesion->monto_apertura + (float) $ventasEfectivoTurno, 2);
            $montoFisico = round((float) $request->monto_fisico, 2);
            $diferencia = round($montoFisico - $montoTeorico, 2);

            $sesion->update([
                'fecha_cierre' => Carbon::now(),
                'monto_teorico' => $montoTeorico,
                'monto_fisico' => $montoFisico,
                'diferencia' => $diferencia,
                'estado' => 'cerrada'
            ]);

            // Disparamos la contabilidad automática (el arqueo valida su propio cuadre)
            $contabilidad = new ContabilidadService();
            $contabilidad->contabilizarArqueo($sesion);

            DB::commit();

            return back()->with('success', 'Arqueo realizado. Turno cerrado. Diferencia: C$ ' . number_format($diferencia, 2));

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al cerrar arqueo de caja', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return back()->withErrors(['error' => 'Error al realizar el arqueo. Contacte al administrador.']);
        }
    }
}