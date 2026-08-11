<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Provider;

class ProviderController extends Controller
{
    public function index()
    {
        $providers = Provider::orderBy('name', 'asc')->get();
        return view('providers.index', compact('providers'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        Provider::create($validated);

        return response()->json(['message' => 'Proveedor registrado con éxito']);
    }

    public function update(Request $request, $id)
    {
        $provider = Provider::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $provider->update($validated);

        return response()->json(['message' => 'Proveedor actualizado con éxito']);
    }

    public function destroy($id)
    {
        $provider = Provider::findOrFail($id);
        $provider->delete();

        return response()->json(['message' => 'Proveedor eliminado']);
    }
}