<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Banco;
use App\Models\CuentaBancaria;
use App\Models\Transferencia;
use App\Models\MovimientoBancario;
use App\Models\SesionCaja;
use App\Services\ContabilidadService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BancoController extends Controller
{
    public function index()
    {
        // 1. Truco: Crear BAC y LAFISE automáticamente si el catálogo está vacío
        if (Banco::count() == 0) {
            $bac = Banco::create(['codigo' => 'BAC', 'nombre' => 'BAC Credomatic']);
            $lafise = Banco::create(['codigo' => 'LAFISE', 'nombre' => 'Banco LAFISE Bancentro']);
            
            $moneda = DB::table('monedas')->where('es_base', true)->first();
            if ($moneda) {
                CuentaBancaria::create(['banco_id' => $bac->id, 'numero_cuenta' => 'BAC-77889900', 'tipo_cuenta' => 'Corriente', 'moneda_id' => $moneda->id, 'saldo_actual' => 0]);
                CuentaBancaria::create(['banco_id' => $lafise->id, 'numero_cuenta' => 'LAF-11223344', 'tipo_cuenta' => 'Ahorro', 'moneda_id' => $moneda->id, 'saldo_actual' => 0]);
            }
        }

        $cuentas = CuentaBancaria::with('banco')->get();
        
        // Buscar si hay una caja abierta de donde sacar el dinero
        $cajaActiva = SesionCaja::where('user_id', auth()->id())->where('estado', 'abierta')->first();

        return view('banks.index', compact('cuentas', 'cajaActiva'));
    }

    public function depositar(Request $request)
    {
        $request->validate([
            'cuenta_destino_id' => 'required|exists:cuentas_bancarias,id',
            'monto' => 'required|numeric|min:1',
            'caja_origen_id' => 'required|exists:cash_sessions,id'
        ]);

        DB::beginTransaction();

        try {
            $cuenta = CuentaBancaria::findOrFail($request->cuenta_destino_id);

            // 1. Crear la transferencia (Diseño Polimórfico)
            $transferencia = Transferencia::create([
                'origen_tipo' => 'App\Models\SesionCaja',
                'origen_id' => $request->caja_origen_id,
                'destino_tipo' => 'App\Models\CuentaBancaria',
                'destino_id' => $cuenta->id,
                'monto' => $request->monto,
                'fecha' => Carbon::now(),
                'usuario_id' => auth()->id() ?? 1,
            ]);

            // 2. Registrar el movimiento bancario (Historial inmutable)
            MovimientoBancario::create([
                'cuenta_bancaria_id' => $cuenta->id,
                'tipo' => 'deposito',
                'monto' => $request->monto,
                'fecha' => Carbon::now(),
                'concepto' => 'Depósito de ventas de caja (Turno #' . $request->caja_origen_id . ')',
                'modulo_origen' => 'transferencias',
                'referencia_id' => $transferencia->id,
            ]);

            // 3. Recalcular el saldo actual de la cuenta bancaria
            $cuenta->saldo_actual += $request->monto;
            $cuenta->save();

            // 4. Disparar Motor Contable
            $contabilidad = new ContabilidadService();
            $contabilidad->contabilizarTransferencia($request->monto, $transferencia->id);

            DB::commit();

            return back()->with('success', 'Depósito realizado. El saldo del banco ha sido actualizado.');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Error al procesar el depósito: ' . $e->getMessage()]);
        }
    }
}