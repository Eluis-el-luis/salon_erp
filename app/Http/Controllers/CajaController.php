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
    /**
     * MANDAMIENTO: Solo el Administrador y el Contador gestionan los montos
     * de apertura y cierre de forma manual. El resto se calcula automáticamente.
     */
    protected function puedeGestionarMontos(): bool
    {
        return in_array(auth()->user()->role, ['admin', 'contador']);
    }

    // Mostrar la pantalla de Caja
    public function index()
    {
        $puedeGestionarMontos = $this->puedeGestionarMontos();

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

        // Monto automático de apertura: el cierre de la última sesión del usuario.
        // (Solo relevante para usuarios no privilegiados.)
        $autoApertura = 0;
        if (!$puedeGestionarMontos && !$sesion) {
            $ultimaCerrada = SesionCaja::where('user_id', auth()->id())
                ->where('estado', 'cerrada')
                ->orderByDesc('fecha_cierre')
                ->first();
            $autoApertura = $ultimaCerrada ? (float) $ultimaCerrada->monto_fisico : 0;
        }

        // Extraemos las últimas 10 cajas cerradas para la bitácora de auditoría
        $historial = SesionCaja::with('usuario')
                                ->where('estado', 'cerrada')
                                ->orderBy('fecha_cierre', 'desc')
                                ->take(10)
                                ->get();

        return view('cash.index', compact(
            'sesion', 'ventasEfectivo', 'historial',
            'puedeGestionarMontos', 'autoApertura'
        ));
    }

    // Abrir Turno
    public function open(Request $request)
    {
        $privilegiado = $this->puedeGestionarMontos();

        $request->validate([
            // Solo el privilegiado envía el monto manual; los demás van oculto.
            'monto_apertura' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            if ($privilegiado) {
                $montoApertura = round((float) ($request->monto_apertura ?? 0), 2);
            } else {
                // Automático: se hereda el monto con el que se cerró la última sesión.
                $ultimaCerrada = SesionCaja::where('user_id', auth()->id())
                    ->where('estado', 'cerrada')
                    ->orderByDesc('fecha_cierre')
                    ->first();
                $montoApertura = $ultimaCerrada ? round((float) $ultimaCerrada->monto_fisico, 2) : 0;
            }

            SesionCaja::create([
                'user_id' => auth()->id(),
                'monto_apertura' => $montoApertura,
                'estado' => 'abierta',
                'fecha_apertura' => Carbon::now(),
            ]);

            DB::commit();

            return back()->with('success', 'Caja abierta con éxito. Fondo inicial: C$ ' . number_format($montoApertura, 2));
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al abrir turno de caja', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return back()->withErrors(['error' => 'Error al abrir la caja. Contacte al administrador.']);
        }
    }

    // Cerrar Turno (Arqueo)
    public function close(Request $request)
    {
        $privilegiado = $this->puedeGestionarMontos();

        $request->validate([
            // Solo el privilegiado envía el monto físico; los demás se auto-calculan.
            'monto_fisico' => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $sesion = SesionCaja::where('user_id', auth()->id())->where('estado', 'abierta')->firstOrFail();

            // MANDAMIENTO: El monto teórico SIEMPRE lo calcula el backend.
            $ventasEfectivoTurno = Venta::where('payment_method', 'efectivo')
                ->where('cashier_id', auth()->id())
                ->where('created_at', '>=', $sesion->fecha_apertura)
                ->sum('total');

            $montoTeorico = round((float) $sesion->monto_apertura + (float) $ventasEfectivoTurno, 2);

            if ($privilegiado) {
                // Admin/Contador registran el arqueo físico real (manual).
                $montoFisico = round((float) $request->monto_fisico, 2);
            } else {
                // Automático: se asume que el arqueo coincide con el teórico.
                $montoFisico = $montoTeorico;
            }

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