<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\OperacionCambio;
use App\Models\SaldoMoneda;
use App\Models\CashSession;
use App\Services\ContabilidadService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ExchangeController extends Controller
{
    public function index()
    {
        // Traemos las operaciones recientes
        $operaciones = OperacionCambio::orderBy('fecha', 'desc')->orderBy('id', 'desc')->take(50)->get();
        
        // Traemos el saldo actual de la moneda extranjera en la caja (Asumimos ID 2 para el Dólar)
        $cajaActiva = CashSession::where('user_id', auth()->id())->where('estado', 'abierta')->first();
        
        $saldoUsd = 0;
        $costoPromedio = 0;

        if ($cajaActiva) {
            $saldoFisico = SaldoMoneda::where('ubicacion_tipo', 'App\Models\CashSession')
                                      ->where('ubicacion_id', $cajaActiva->id)
                                      ->where('moneda_id', 2) // ID de la moneda USD
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
            $montoCordobas = $request->monto_usd * $request->tasa_aplicada;
            
            // 1. Encontrar o crear el registro de Saldo de la Moneda Extranjera (USD = ID 2)
            $saldoFisico = SaldoMoneda::firstOrCreate(
                [
                    'moneda_id' => 2, 
                    'ubicacion_tipo' => 'App\Models\CashSession', 
                    'ubicacion_id' => $request->caja_origen_id
                ],
                [
                    'saldo_actual' => 0, 
                    'costo_promedio_ponderado' => 0
                ]
            );

            // 2. Lógica matemática del Costo Promedio Ponderado[cite: 5]
            if ($request->tipo == 'compra') {
                // EL SALÓN COMPRA USD (Recibimos USD, Entregamos NIO)
                $valorTotalAntiguo = $saldoFisico->saldo_actual * $saldoFisico->costo_promedio_ponderado;
                $valorNuevoAgregado = $request->monto_usd * $request->tasa_aplicada;
                
                $nuevoSaldoUsd = $saldoFisico->saldo_actual + $request->monto_usd;
                
                // Calculamos el nuevo promedio a 6 decimales para evitar el error de centavos[cite: 5]
                $nuevoCostoPromedio = round(($valorTotalAntiguo + $valorNuevoAgregado) / $nuevoSaldoUsd, 6);

                $saldoFisico->saldo_actual = $nuevoSaldoUsd;
                $saldoFisico->costo_promedio_ponderado = $nuevoCostoPromedio;

                // Definimos el origen y destino para el historial
                $monedaOrigen = 2; // USD (Lo que da el cliente)
                $monedaDestino = 1; // NIO (Lo que damos nosotros)
                $montoOrigen = $request->monto_usd;
                $montoDestino = $montoCordobas;

            } else {
                // EL SALÓN VENDE USD (Entregamos USD, Recibimos NIO)
                if ($saldoFisico->saldo_actual < $request->monto_usd) {
                    throw new \Exception('No hay suficientes Dólares físicos en caja para realizar la venta.');
                }

                // Al vender, el costo promedio NO cambia, solo se restan los billetes.
                $saldoFisico->saldo_actual -= $request->monto_usd;

                // Definimos el origen y destino para el historial
                $monedaOrigen = 1; // NIO (Lo que da el cliente)
                $monedaDestino = 2; // USD (Lo que damos nosotros)
                $montoOrigen = $montoCordobas;
                $montoDestino = $request->monto_usd;
            }

            // Actualizamos la fecha de modificación
            $saldoFisico->fecha_actualizacion = Carbon::now();
            $saldoFisico->save();

            // 3. Crear el registro histórico de la transacción de cambio[cite: 5]
            $operacion = OperacionCambio::create([
                'fecha' => Carbon::now(),
                'tipo' => $request->tipo,
                'moneda_origen_id' => $monedaOrigen,
                'moneda_destino_id' => $monedaDestino,
                'monto_origen' => $montoOrigen,
                'monto_destino' => $montoDestino,
                'tasa_aplicada' => $request->tasa_aplicada,
                'origen_pago_tipo' => 'App\Models\CashSession',
                'origen_pago_id' => $request->caja_origen_id,
                'usuario_id' => auth()->id() ?? 1,
            ]);

            // 4. Disparar el Motor Contable
            $contabilidad = new ContabilidadService();
            $contabilidad->contabilizarOperacionCambio($operacion);

            DB::commit();

            return back()->with('success', 'Operación de cambio registrada. El saldo en USD ha sido actualizado.');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Error al procesar el cambio de divisas: ' . $e->getMessage()]);
        }
    }
}