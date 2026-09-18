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
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BancoController extends Controller
{
    public function index()
    {
        $this->asegurarCatalogoBancario();

        $cuentas = CuentaBancaria::with('banco')->get();
        
        // Buscar si hay una caja abierta de donde sacar el dinero
        $cajaActiva = SesionCaja::where('user_id', auth()->id())->where('estado', 'abierta')->first();

        return view('banks.index', compact('cuentas', 'cajaActiva'));
    }

    /**
     * Asegura que exista el catálogo bancario base (BAC y LAFISE) de forma
     * IDEMPOTENTE, sin depender del conteo global ni duplicar registros.
     */
    protected function asegurarCatalogoBancario(): void
    {
        $moneda = DB::table('monedas')->where('es_base', true)->first();
        if (!$moneda) {
            return;
        }

        $bancosBase = [
            ['codigo' => 'BAC', 'nombre' => 'BAC Credomatic'],
            ['codigo' => 'LAFISE', 'nombre' => 'Banco LAFISE Bancentro'],
        ];

        foreach ($bancosBase as $bancoBase) {
            $banco = Banco::firstOrCreate(
                ['codigo' => $bancoBase['codigo']],
                ['nombre' => $bancoBase['nombre'], 'activo' => true]
            );

            $existeCuenta = CuentaBancaria::where('banco_id', $banco->id)->exists();
            if (!$existeCuenta) {
                CuentaBancaria::create([
                    'banco_id' => $banco->id,
                    'numero_cuenta' => strtoupper($banco->codigo) . '-00000000',
                    'tipo_cuenta' => 'Corriente',
                    'moneda_id' => $moneda->id,
                    'saldo_actual' => 0,
                    'activa' => true,
                ]);
                Log::info('Cuenta bancaria base creada', ['banco' => $banco->codigo]);
            }
        }
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
            $contabilidad->contabilizarTransferencia($transferencia);

            DB::commit();

            return back()->with('success', 'Depósito realizado. El saldo del banco ha sido actualizado.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al procesar depósito bancario', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return back()->withErrors(['error' => 'Error al procesar el depósito. Contacte al administrador.']);
        }
    }
}