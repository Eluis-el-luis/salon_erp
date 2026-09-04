<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AsientoContable;
use App\Models\CuentaContable;
use App\Services\ContabilidadService;
use Illuminate\Support\Facades\DB;

class ContabilidadController extends Controller
{
    public function diario()
    {
        $asientos = AsientoContable::with(['detalles.cuenta'])
                    ->orderBy('created_at', 'desc')
                    ->get();

        // Solo traemos Gastos (Clase 6) y Costos (Clase 5)
        $cuentasGasto = CuentaContable::where('permite_movimiento', true)
                        ->where(function($query) {
                            $query->where('codigo', 'like', '5.%')
                                  ->orWhere('codigo', 'like', '6.%');
                        })->get();

        return view('accounting.diario', compact('asientos', 'cuentasGasto'));
    }

    public function storeGasto(Request $request)
    {
        $request->validate([
            'descripcion' => 'required|string|max:255',
            'monto' => 'required|numeric|min:1',
            'cuenta_id' => 'required|exists:cuentas_contables,id',
            'metodo_pago' => 'required|in:efectivo,banco'
        ]);

        DB::beginTransaction(); // INICIA LA PROTECCIÓN

        try {
            $contabilidad = new ContabilidadService();
            $contabilidad->contabilizarGasto(
                $request->descripcion, 
                $request->monto, 
                $request->cuenta_id, 
                $request->metodo_pago
            );

            DB::commit(); // SI EL CANDADO NO DETONA, GUARDAMOS
            return back()->with('success', 'Gasto registrado y contabilizado en el Libro Diario.');

        } catch (\Exception $e) {
            DB::rollBack(); // REVERTIMOS TODO SI HAY DESCUADRE
            return back()->withErrors(['error' => 'Error contable: ' . $e->getMessage()]);
        }
    }

    public function mayor()
    {
        $cuentas = CuentaContable::where('permite_movimiento', true)
            ->with(['detalles'])
            ->get()
            ->filter(function($cuenta) {
                return $cuenta->detalles->count() > 0;
            });

        return view('accounting.mayor', compact('cuentas'));
    }

    public function resultados()
    {
        // SOLO traemos Clases 4, 5 y 6. La clase 7 ya no existe en el catálogo formal.
        $cuentasResultados = CuentaContable::where('permite_movimiento', true)
            ->where(function($query) {
                $query->where('codigo', 'like', '4.%')
                      ->orWhere('codigo', 'like', '5.%')
                      ->orWhere('codigo', 'like', '6.%');
            })
            ->with('detalles')
            ->get();

        $ingresos = 0;
        $costos = 0;
        $gastosOperativos = 0;

        foreach ($cuentasResultados as $cuenta) {
            $debe = $cuenta->detalles->sum('debe');
            $haber = $cuenta->detalles->sum('haber');
            
            if (str_starts_with($cuenta->codigo, '4')) {
                $saldo = $haber - $debe;
                $ingresos += $saldo;
                $cuenta->saldo_final = $saldo; 
            } elseif (str_starts_with($cuenta->codigo, '5')) {
                $saldo = $debe - $haber;
                $costos += $saldo;
                $cuenta->saldo_final = $saldo;
            } elseif (str_starts_with($cuenta->codigo, '6')) {
                $saldo = $debe - $haber;
                $gastosOperativos += $saldo;
                $cuenta->saldo_final = $saldo;
            }
        }

        $utilidadBruta = $ingresos - $costos;
        $utilidadNeta = $utilidadBruta - $gastosOperativos;

        return view('accounting.resultados', compact(
            'cuentasResultados', 'ingresos', 'costos', 'gastosOperativos', 'utilidadBruta', 'utilidadNeta'
        ));
    }
}