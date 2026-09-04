<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Servicio;
use Illuminate\Support\Facades\Validator;

class ServicioController extends Controller
{
    public function index()
    {
        // Traemos todos los servicios ordenados alfabéticamente
        $servicios = Servicio::orderBy('name', 'asc')->get();
        return view('services.index', compact('servicios'));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'duration' => 'required|integer|min:5', // Mínimo 5 minutos
            'description' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        Servicio::create($request->all());

        return response()->json(['message' => 'Servicio creado exitosamente']);
    }

    public function update(Request $request, $id)
    {
        $servicio = Servicio::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'duration' => 'required|integer|min:5',
            'description' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $servicio->update($request->all());

        return response()->json(['message' => 'Servicio actualizado correctamente']);
    }

    public function destroy($id)
    {
        $servicio = Servicio::findOrFail($id);
        
        // En lugar de borrarlo físicamente (lo que rompería facturas viejas), 
        // lo desactivamos para que ya no salga en la caja registradora.
        $servicio->update(['is_active' => false]);

        return response()->json(['message' => 'Servicio dado de baja']);
    }
}