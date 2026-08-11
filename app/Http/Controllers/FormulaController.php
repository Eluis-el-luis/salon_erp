<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Service;
use App\Models\Item;
use App\Models\ServiceFormula;

class FormulaController extends Controller
{
    // Mostrar la interfaz principal
    public function index()
    {
        // Traemos todos los servicios y les adjuntamos sus fórmulas e items
        $services = Service::with('formulas.item')->get();
        
        // Solo traemos los productos que el dueño marcó como "Fraccionables" (Líquidos/Polvos)
        $fractionableItems = Item::where('is_fractionable', true)->get();

        return view('formulas.index', compact('services', 'fractionableItems'));
    }

    // Guardar un nuevo ingrediente en la receta de un servicio
    public function store(Request $request)
    {
        $request->validate([
            'service_id' => 'required|exists:services,id',
            'item_id' => 'required|exists:items,id',
            'quantity_used' => 'required|numeric|min:0.1'
        ]);

        // Verificamos si este producto ya estaba en la receta de este servicio
        $existing = ServiceFormula::where('service_id', $request->service_id)
                                  ->where('item_id', $request->item_id)
                                  ->first();

        if ($existing) {
            // Si ya existe, le sumamos la cantidad
            $existing->quantity_used += $request->quantity_used;
            $existing->save();
        } else {
            // Si es nuevo, lo creamos
            ServiceFormula::create($request->all());
        }

        return redirect()->back()->with('success', 'Ingrediente agregado a la receta con éxito.');
    }

    // Eliminar un ingrediente de la receta
    public function destroy($id)
    {
        $formula = ServiceFormula::findOrFail($id);
        $formula->delete();

        return redirect()->back()->with('success', 'Ingrediente removido de la receta.');
    }
}