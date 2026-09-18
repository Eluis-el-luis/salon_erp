<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CuentaPorPagar;
use App\Models\PagoProveedor;
use App\Services\ContabilidadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CuentaPorPagarController extends Controller
{
    public function index()
    {
        // Traemos solo las facturas que aún tienen saldo pendiente
        $cuentas = CuentaPorPagar::with('proveedor')
            ->whereIn('estado', ['pendiente', 'parcial'])
            ->orderBy('fecha_vencimiento', 'asc')
            ->get();

        return view('payables.index', compact('cuentas'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'cuenta_por_pagar_id' => 'required|exists:cuentas_por_pagar,id',
            'monto' => 'required|numeric|min:0.01',
            'forma_pago' => 'required|in:efectivo,banco'
        ]);

        DB::beginTransaction();

        try {
            $cuenta = CuentaPorPagar::findOrFail($request->cuenta_por_pagar_id);

            // Validar que no pague de más
            if ($request->monto > $cuenta->saldo_pendiente) {
                return back()->withErrors(['error' => 'El abono de C$ ' . $request->monto . ' supera el saldo pendiente de C$ ' . $cuenta->saldo_pendiente]);
            }

            // 1. Registrar el pago en PAGOS_PROVEEDOR
            $pago = PagoProveedor::create([
                'cuenta_por_pagar_id' => $cuenta->id,
                'fecha' => Carbon::now(),
                'monto' => $request->monto,
                'forma_pago' => $request->forma_pago,
                'usuario_id' => auth()->id() ?? 1
            ]);

            // 2. Descontar el saldo en CUENTAS_POR_PAGAR
            $cuenta->saldo_pendiente -= $request->monto;
            $cuenta->estado = ($cuenta->saldo_pendiente <= 0) ? 'pagada' : 'parcial';
            $cuenta->save();

            // 3. Disparar el Motor Contable
            $contabilidad = new ContabilidadService();
            $contabilidad->contabilizarPagoProveedor($request->monto, $pago->id, $request->forma_pago);

            DB::commit();

            return back()->with('success', 'Abono registrado y contabilizado con éxito.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al procesar pago a proveedor', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return back()->withErrors(['error' => 'Error al procesar el pago. Contacte al administrador.']);
        }
    }
}