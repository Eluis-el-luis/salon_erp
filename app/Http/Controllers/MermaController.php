<?php

namespace App\Http\Controllers;

use App\Models\Merma;
use App\Models\Articulo;
use App\Services\ContabilidadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MermaController extends Controller
{
    public function index()
    {
        $mermas = Merma::with('articulo', 'usuario')->orderBy('fecha', 'desc')->get();
        $articulos = Articulo::where('type', '!=', 'servicio')->orderBy('producto', 'asc')->get();

        return view('inventory.mermas', compact('mermas', 'articulos'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'item_id' => 'required|exists:items,id',
            'fecha' => 'required|date',
            'tipo' => 'required|in:unidad,volumen',
            'cantidad' => 'required|numeric|min:0.01',
            'motivo' => 'required|string|max:255',
        ]);

        DB::beginTransaction();

        try {
            $articulo = Articulo::findOrFail($validated['item_id']);
            $cantidad = (float) $validated['cantidad'];

            if ($validated['tipo'] === 'volumen') {
                // Merma de insumo fraccionable: se descuenta volumen (ml/oz)
                $articulo->current_volume -= $cantidad;
                while ($articulo->current_volume <= 0) {
                    if ($articulo->existencia_actual > 0) {
                        $articulo->existencia_actual -= 1;
                        $articulo->current_volume += $articulo->total_volume;
                        (new \App\Services\InventarioService())->consumirLotesFIFO($articulo, 1);
                    } else {
                        break;
                    }
                }
                $articulo->save();

                $valor = $articulo->total_volume > 0 ? ($articulo->precio_c / $articulo->total_volume) * $cantidad : 0;
                $codigoInventario = '1.1.5';
            } else {
                // Merma de unidades (producto físico)
                $articulo->existencia_actual -= $cantidad;
                $articulo->save();

                (new \App\Services\InventarioService())->consumirLotesFIFO($articulo, $cantidad);

                $valor = $articulo->precio_c * $cantidad;
                $codigoInventario = $articulo->is_fractionable ? '1.1.5' : '1.1.4';
            }

            $merma = Merma::create([
                'item_id' => $articulo->id,
                'fecha' => $validated['fecha'],
                'tipo' => $validated['tipo'],
                'cantidad' => $cantidad,
                'valor' => round($valor, 2),
                'motivo' => $validated['motivo'],
                'user_id' => auth()->id(),
            ]);

            $contabilidad = new ContabilidadService();
            $contabilidad->contabilizarMerma($merma, $codigoInventario);

            DB::commit();

            return back()->with('success', 'Merma registrada y contabilizada correctamente.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al registrar merma de inventario', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return back()->withErrors(['error' => 'Error al registrar la merma. Contacte al administrador.']);
        }
    }
}