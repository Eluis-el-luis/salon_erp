<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\OperacionCambio;
use App\Models\SaldoMoneda;
use App\Models\SesionCaja;
use App\Models\Moneda;
use App\Models\DiferencialCambiario;
use App\Services\ContabilidadService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MesaCambioController extends Controller
{
    public function index()
    {
        // Traemos las operaciones recientes
        $operaciones = OperacionCambio::orderBy('fecha', 'desc')->orderBy('id', 'desc')->take(50)->get();
        
        // Traemos el saldo actual de la moneda extranjera en la caja
        $cajaActiva = SesionCaja::where('user_id', auth()->id())->where('estado', 'abierta')->first();
        
        $saldoUsd = 0;
        $costoPromedio = 0;

        if ($cajaActiva) {
            $idUsd = Moneda::idPorCodigo('USD');
            $saldoFisico = SaldoMoneda::where('ubicacion_tipo', 'App\Models\SesionCaja')
                                      ->where('ubicacion_id', $cajaActiva->id)
                                      ->where('moneda_id', $idUsd)
                                      ->first();
            if ($saldoFisico) {
                $saldoUsd = $saldoFisico->saldo_actual;
                $costoPromedio = $saldoFisico->costo_promedio_ponderado;
            }
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
            // Resolver IDs de moneda por código (evita hardcodear 1 y 2)
            $idNio = Moneda::idPorCodigo('NIO');
            $idUsd = Moneda::idPorCodigo('USD');

            $montoCordobas = round($request->monto_usd * $request->tasa_aplicada, 2);
            
            // 1. Encontrar o crear el registro de Saldo de la Moneda Extranjera (USD)
            $saldoFisico = SaldoMoneda::firstOrCreate(
                [
                    'moneda_id' => $idUsd, 
                    'ubicacion_tipo' => 'App\Models\SesionCaja', 
                    'ubicacion_id' => $request->caja_origen_id
                ],
                [
                    'saldo_actual' => 0, 
                    'costo_promedio_ponderado' => 0
                ]
            );

            $diferencial = 0; // Ganancia (+) o pérdida (-) en Córdobas al vender USD

            // 2. Lógica matemática del Costo Promedio Ponderado
            if ($request->tipo == 'compra') {
                // EL SALÓN COMPRA USD (Recibimos USD, Entregamos NIO)
                $valorTotalAntiguo = $saldoFisico->saldo_actual * $saldoFisico->costo_promedio_ponderado;
                $valorNuevoAgregado = $request->monto_usd * $request->tasa_aplicada;
                
                $nuevoSaldoUsd = $saldoFisico->saldo_actual + $request->monto_usd;
                
                // Calculamos el nuevo promedio a 6 decimales para evitar el error de centavos
                $nuevoCostoPromedio = round(($valorTotalAntiguo + $valorNuevoAgregado) / $nuevoSaldoUsd, 6);

                $saldoFisico->saldo_actual = $nuevoSaldoUsd;
                $saldoFisico->costo_promedio_ponderado = $nuevoCostoPromedio;

                $monedaOrigen = $idUsd;
                $monedaDestino = $idNio;
                $montoOrigen = $request->monto_usd;
                $montoDestino = $montoCordobas;

            } else {
                // EL SALÓN VENDE USD (Entregamos USD, Recibimos NIO)
                if ($saldoFisico->saldo_actual < $request->monto_usd) {
                    throw new \Exception('No hay suficientes Dólares físicos en caja para realizar la venta.');
                }

                // Al vender, el costo promedio NO cambia, solo se restan los billetes.
                $saldoFisico->saldo_actual -= $request->monto_usd;

                // DIFERENCIAL CAMBIARIO: (tasa de venta - costo promedio) * USD vendidos
                // Si positivo, ganancia; si negativo, pérdida.
                $diferencial = round(($request->tasa_aplicada - $saldoFisico->costo_promedio_ponderado) * $request->monto_usd, 2);

                $monedaOrigen = $idNio;
                $monedaDestino = $idUsd;
                $montoOrigen = $montoCordobas;
                $montoDestino = $request->monto_usd;
            }

            // Actualizamos la fecha de modificación
            $saldoFisico->fecha_actualizacion = Carbon::now();
            $saldoFisico->save();

            // 3. Crear el registro histórico de la transacción de cambio
            $operacion = OperacionCambio::create([
                'fecha' => Carbon::now(),
                'tipo' => $request->tipo,
                'moneda_origen_id' => $monedaOrigen,
                'moneda_destino_id' => $monedaDestino,
                'monto_origen' => $montoOrigen,
                'monto_destino' => $montoDestino,
                'tasa_aplicada' => $request->tasa_aplicada,
                'origen_pago_tipo' => 'App\Models\SesionCaja',
                'origen_pago_id' => $request->caja_origen_id,
                'usuario_id' => auth()->id() ?? 1,
            ]);

            // 4. Registrar el diferencial cambiario si hay ganancia o pérdida (solo al vender)
            if ($diferencial != 0) {
                DiferencialCambiario::create([
                    'saldo_moneda_id' => $saldoFisico->id,
                    'fecha' => Carbon::now(),
                    'monto_moneda_extranjera' => $request->monto_usd,
                    'tasa_costo_promedio' => $saldoFisico->costo_promedio_ponderado,
                    'tasa_revaluacion' => $request->tasa_aplicada,
                    'diferencia_calculada' => abs($diferencial),
                    'tipo' => $diferencial > 0 ? 'ganancia' : 'perdida',
                    'estado' => 'contabilizado',
                ]);
            }

            // 5. Disparar el Motor Contable
            $contabilidad = new ContabilidadService();
            $contabilidad->contabilizarOperacionCambio($operacion, $diferencial);

            DB::commit();

            return back()->with('success', 'Operación de cambio registrada. El saldo en USD ha sido actualizado.');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Error al procesar el cambio de divisas: ' . $e->getMessage()]);
        }
    }
}