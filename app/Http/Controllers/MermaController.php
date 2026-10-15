<?php

namespace App\Http\Controllers;

use App\Models\Articulo;
use App\Models\Merma;
use App\Services\ContabilidadService;
use App\Services\InventarioService;
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

            // La merma se registra PRIMERO (para trazabilidad en el kárdex);
            // el descuento físico, lotes FIFO y valor al costo real los resuelve
            // el InventarioService (misma ruta que ventas y reversiones).
            $merma = Merma::create([
                'item_id' => $articulo->id,
                'fecha' => $validated['fecha'],
                'tipo' => $validated['tipo'],
                'cantidad' => $cantidad,
                'valor' => 0,
                'motivo' => $validated['motivo'],
                'user_id' => auth()->id(),
            ]);

            $inventario = new InventarioService;
            $valor = $inventario->registrarMerma($articulo, $validated['tipo'], $cantidad, $merma->id);

            $merma->update(['valor' => round($valor, 2)]);

            $codigoInventario = ($validated['tipo'] === 'volumen' || $articulo->is_fractionable || $articulo->type !== 'producto_venta')
                ? '1.1.5'
                : '1.1.4';

            $contabilidad = new ContabilidadService;
            $contabilidad->contabilizarMerma($merma->fresh(), $codigoInventario);

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
