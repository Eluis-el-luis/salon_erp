<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\OperacionCambio;
use App\Models\SaldoMoneda;
use App\Models\SesionCaja;
use App\Models\Moneda;
use App\Models\DiferencialCambiario;
use App\Services\ContabilidadService;
use App\Services\DivisaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MesaCambioController extends Controller
{
    protected DivisaService $divisas;

    public function __construct()
    {
        $this->divisas = new DivisaService();
    }

    public function index()
    {
        $operaciones = OperacionCambio::orderBy('fecha', 'desc')->orderBy('id', 'desc')->take(50)->get();

        $cajaActiva = SesionCaja::where('user_id', auth()->id())->where('estado', 'abierta')->first();

        $saldoUsd = 0;
        $costoPromedio = 0;

        if ($cajaActiva) {
            $datos = $this->divisas->saldoDivisas('USD', 'App\Models\SesionCaja', $cajaActiva->id);
            $saldoUsd = $datos['saldo'];
            $costoPromedio = $datos['costo_promedio'];
        }

        return view('exchange.index', compact('operaciones', 'cajaActiva', 'saldoUsd', 'costoPromedio'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'tipo' => 'required|in:compra,venta',
            'monto_usd' => 'required|numeric|min:0.01',
            'tasa_aplicada' => 'required|numeric|min:1',
            'caja_origen_id' => 'required|exists:cash_sessions,id'
        ]);

        DB::beginTransaction();

        try {
            $ubicacionTipo = 'App\Models\SesionCaja';
            $ubicacionId = (int) $request->caja_origen_id;
            $montoUsd = (float) $request->monto_usd;
            $tasa = (float) $request->tasa_aplicada;

            $idNio = Moneda::idPorCodigo('NIO');
            $idUsd = Moneda::idPorCodigo('USD');
            $montoCordobas = round($montoUsd * $tasa, 2);

            $diferencial = 0;
            $saldoFisico = null;

            if ($request->tipo == 'compra') {
                // EL SALÓN COMPRA USD (Recibe USD, Entrega NIO) -> recalcula promedio ponderado
                $saldoFisico = $this->divisas->ingresarDivisas('USD', $ubicacionTipo, $ubicacionId, $montoUsd, $tasa);
                $monedaOrigen = $idUsd;
                $monedaDestino = $idNio;
                $montoOrigen = $montoUsd;
                $montoDestino = $montoCordobas;
            } else {
                // EL SALÓN VENDE USD (Entrega USD, Recibe NIO). El promedio no cambia.
                $saldoFisico = SaldoMoneda::where('moneda_id', $idUsd)
                    ->where('ubicacion_tipo', $ubicacionTipo)
                    ->where('ubicacion_id', $ubicacionId)
                    ->first();

                $diferencial = $this->divisas->egresarDivisas('USD', $ubicacionTipo, $ubicacionId, $montoUsd, $tasa);

                $monedaOrigen = $idNio;
                $monedaDestino = $idUsd;
                $montoOrigen = $montoCordobas;
                $montoDestino = $montoUsd;
            }

            $costoPromedio = $saldoFisico ? (float) $saldoFisico->costo_promedio_ponderado : 0;

            // Registro histórico de la operación de cambio
            $operacion = OperacionCambio::create([
                'fecha' => Carbon::now(),
                'tipo' => $request->tipo,
                'moneda_origen_id' => $monedaOrigen,
                'moneda_destino_id' => $monedaDestino,
                'monto_origen' => $montoOrigen,
                'monto_destino' => $montoDestino,
                'tasa_aplicada' => $tasa,
                'origen_pago_tipo' => $ubicacionTipo,
                'origen_pago_id' => $ubicacionId,
                'usuario_id' => auth()->id() ?? 1,
            ]);

            // Registrar el diferencial cambiario (ganancia o pérdida) al vender
            if ($diferencial != 0 && $saldoFisico) {
                DiferencialCambiario::create([
                    'saldo_moneda_id' => $saldoFisico->id,
                    'fecha' => Carbon::now(),
                    'monto_moneda_extranjera' => $montoUsd,
                    'tasa_costo_promedio' => $costoPromedio,
                    'tasa_revaluacion' => $tasa,
                    'diferencia_calculada' => abs($diferencial),
                    'tipo' => $diferencial > 0 ? 'ganancia' : 'perdida',
                    'estado' => 'contabilizado',
                ]);
            }

            // Disparar el Motor Contable
            $contabilidad = new ContabilidadService();
            $contabilidad->contabilizarOperacionCambio($operacion, $diferencial);

            DB::commit();

            return back()->with('success', 'Operación de cambio registrada. El saldo en USD ha sido actualizado.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al procesar cambio de divisas', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return back()->withErrors(['error' => 'Error al procesar el cambio de divisas. Contacte al administrador.']);
        }
    }
}