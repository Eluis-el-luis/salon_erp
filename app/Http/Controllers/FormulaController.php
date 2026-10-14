<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Servicio;
use App\Models\Articulo;
use App\Models\FormulaServicio;

/**
 * FASE 1 ADMINISTRACIÓN: fórmulas como BOM (Lista de Materiales) dinámica.
 * La receta estándar alimenta el cálculo de costo de insumos (valorado al
 * costo de compra actual por ml/oz desde los lotes) y el margen bruto
 * esperado; el consumo real se captura en el POS y se persiste al facturar
 * (sale_detail_consumptions vía VentaController).
 */
class FormulaController extends Controller
{
    public function index()
    {
        // Traemos todos los servicios y les adjuntamos sus fórmulas e artículos
        $servicios = Servicio::with('formulas.articulo')->orderBy('name', 'asc')->get();

        // Solo traemos los productos que el dueño marcó como "Fraccionables" (Líquidos/Polvos)
        $articulosFraccionables = Articulo::where('is_fractionable', true)
            ->orderBy('producto', 'asc')
            ->get();

        // COSTO DINÁMICO: mapa de costo de compra actual por insumo
        // (promedio ponderado de lotes abiertos, fallback último lote).
        $mapaCostos = Articulo::mapaCostosActuales($articulosFraccionables->pluck('id')->all());

        $costos = [];
        foreach ($articulosFraccionables as $articulo) {
            $costoUnitario = $mapaCostos[$articulo->id]['costo_unitario'] ?? null;

            $costos[$articulo->id] = [
                'costo_unitario' => $costoUnitario,
                'fuente' => $mapaCostos[$articulo->id]['fuente'] ?? 'sin_costo',
                // Costo por ml/oz: base para valorar el consumo de la receta
                'costo_por_medida' => ($costoUnitario !== null && (float) $articulo->total_volume > 0)
                    ? round($costoUnitario / (float) $articulo->total_volume, 6)
                    : $costoUnitario,
                'unit_measure' => $articulo->unit_measure ?: 'ml',
            ];
        }

        return view('formulas.index', compact('servicios', 'articulosFraccionables', 'costos'));
    }

    // Guardar un nuevo ingrediente en la receta de un servicio
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->reglas());

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Verificamos si este producto ya estaba en la receta de este servicio
        $existente = FormulaServicio::where('service_id', $request->service_id)
            ->where('item_id', $request->item_id)
            ->first();

        if ($existente) {
            // Si ya existe, le sumamos la cantidad
            $existente->quantity_used += (float) $request->quantity_used;
            $existente->save();

            return response()->json(['message' => 'El insumo ya estaba en la receta; se sumó la cantidad.']);
        }

        FormulaServicio::create([
            'service_id' => (int) $request->service_id,
            'item_id' => (int) $request->item_id,
            'quantity_used' => (float) $request->quantity_used,
            'notes' => $request->notes,
            'sort_order' => (int) FormulaServicio::where('service_id', $request->service_id)->max('sort_order') + 1,
        ]);

        return response()->json(['message' => 'Ingrediente agregado a la receta con éxito.'], 201);
    }

    /**
     * Edición inline del consumo estándar (BOM): permite afinar la receta
     * desde la misma tabla sin recargar la pantalla.
     */
    public function update(Request $request, $id)
    {
        $formula = FormulaServicio::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'quantity_used' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $formula->update([
            'quantity_used' => (float) $request->quantity_used,
            'notes' => $request->notes ?? $formula->notes,
        ]);

        return response()->json(['message' => 'Consumo estándar actualizado']);
    }

    // Eliminar un ingrediente de la receta
    public function destroy($id)
    {
        $formula = FormulaServicio::findOrFail($id);
        $formula->delete();

        return response()->json(['message' => 'Ingrediente removido de la receta.']);
    }

    private function reglas(): array
    {
        return [
            'service_id' => 'required|exists:services,id',
            'item_id' => 'required|exists:items,id',
            'quantity_used' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:255',
        ];
    }
}
