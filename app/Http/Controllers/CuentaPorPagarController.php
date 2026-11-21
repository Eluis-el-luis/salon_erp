<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CuentaPorPagar;
use App\Exceptions\ContabilidadException;
use App\Services\PagoProveedorService;
use Illuminate\Support\Facades\Log;

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

    public function store(Request $request, PagoProveedorService $abonos)
    {
        $request->validate([
            'cuenta_por_pagar_id' => 'required|integer|min:1|exists:cuentas_por_pagar,id',
            'monto' => ['bail', 'required', function ($attribute, $value, $fail) {
                try { PagoProveedorService::montoCentavos($value); }
                catch (ContabilidadException $e) { $fail($e->getMessage()); }
            }],
            'forma_pago' => 'required|in:efectivo,banco'
        ]);

        try {
            $abonos->registrar((int) $request->cuenta_por_pagar_id, $request->monto, $request->forma_pago, auth()->id() ?? 1);

            return back()->with('success', 'Abono registrado y contabilizado con éxito.');

        } catch (ContabilidadException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('Error al procesar pago a proveedor', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return back()->withErrors(['error' => 'Error al procesar el pago. Contacte al administrador.']);
        }
    }
}
