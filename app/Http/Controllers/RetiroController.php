<?php

namespace App\Http\Controllers;

use App\Models\RetiroPropietario;
use App\Services\ContabilidadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RetiroController extends Controller
{
    public function index()
    {
        $retiros = RetiroPropietario::with('usuario')->orderBy('fecha', 'desc')->get();
        return view('retiros.index', compact('retiros'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'fecha' => 'required|date',
            'monto' => 'required|numeric|min:0.01',
            'moneda' => 'required|in:nio,usd',
            'metodo_pago' => 'required|in:efectivo,banco',
            'concepto' => 'required|string|max:255',
        ]);

        DB::beginTransaction();

        try {
            $retiro = RetiroPropietario::create([
                'fecha' => $validated['fecha'],
                'monto' => $validated['monto'],
                'moneda' => $validated['moneda'],
                'metodo_pago' => $validated['metodo_pago'],
                'concepto' => $validated['concepto'],
                'user_id' => auth()->id(),
            ]);

            $contabilidad = new ContabilidadService();
            $contabilidad->contabilizarRetiroPropietario($retiro, $validated['metodo_pago']);

            DB::commit();

            return back()->with('success', 'Retiro de propietario registrado y contabilizado contra Patrimonio.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al registrar retiro de propietario', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return back()->withErrors(['error' => 'Error al registrar el retiro. Contacte al administrador.']);
        }
    }
}