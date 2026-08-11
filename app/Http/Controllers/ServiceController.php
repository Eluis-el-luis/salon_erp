<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Service;
use Illuminate\Support\Facades\Validator;

class ServiceController extends Controller
{
    public function index()
    {
        // Traemos todos los servicios ordenados alfabéticamente
        $services = Service::orderBy('name', 'asc')->get();
        return view('services.index', compact('services'));
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

        Service::create($request->all());

        return response()->json(['message' => 'Servicio creado exitosamente']);
    }

    public function update(Request $request, $id)
    {
        $service = Service::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'duration' => 'required|integer|min:5',
            'description' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $service->update($request->all());

        return response()->json(['message' => 'Servicio actualizado correctamente']);
    }

    public function destroy($id)
    {
        $service = Service::findOrFail($id);
        
        // En lugar de borrarlo físicamente (lo que rompería facturas viejas), 
        // lo desactivamos para que ya no salga en la caja registradora.
        $service->update(['is_active' => false]);

        return response()->json(['message' => 'Servicio dado de baja']);
    }
}