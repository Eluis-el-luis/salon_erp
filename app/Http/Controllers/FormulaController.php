<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Servicio;
use App\Models\Articulo;
use App\Models\FormulaServicio;

class FormulaController extends Controller
{
    // Mostrar la interfaz principal
    public function index()
    {
        // Traemos todos los servicios y les adjuntamos sus fórmulas e artículos
        $servicios = Servicio::with('formulas.articulo')->get();
        
        // Solo traemos los productos que el dueño marcó como "Fraccionables" (Líquidos/Polvos)
        $articulosFraccionables = Articulo::where('is_fractionable', true)->get();

        return view('formulas.index', compact('servicios', 'articulosFraccionables'));
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
        $existente = FormulaServicio::where('service_id', $request->service_id)
                                  ->where('item_id', $request->item_id)
                                  ->first();

        if ($existente) {
            // Si ya existe, le sumamos la cantidad
            $existente->quantity_used += $request->quantity_used;
            $existente->save();
        } else {
            // Si es nuevo, lo creamos
            FormulaServicio::create($request->all());
        }

        return redirect()->back()->with('success', 'Ingrediente agregado a la receta con éxito.');
    }

    // Eliminar un ingrediente de la receta
    public function destroy($id)
    {
        $formula = FormulaServicio::findOrFail($id);
        $formula->delete();

        return redirect()->back()->with('success', 'Ingrediente removido de la receta.');
    }
}