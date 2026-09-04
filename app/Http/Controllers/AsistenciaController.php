<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Usuario;
use App\Models\Asistencia;
use Carbon\Carbon;

class AsistenciaController extends Controller
{
    // Mostrar la lista de empleados para marcar asistencia HOY
    public function index()
    {
        $hoy = Carbon::today()->toDateString();
        
        // Traemos a todos los empleados activos, y si ya marcaron hoy, traemos ese registro
        $empleados = Usuario::where('is_active', true)
                         ->whereIn('role', ['estilista', 'recepcion', 'admin'])
                         ->with(['asistencias' => function($query) use ($hoy) {
                             $query->where('date', $hoy);
                         }])
                         ->orderBy('name', 'asc')
                         ->get();

        return view('attendances.index', compact('empleados', 'hoy'));
    }

    // Guardar o actualizar la asistencia del día
    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'time_in' => 'required',
            'aseo' => 'boolean',
            'uniforme' => 'boolean'
        ]);

        $hoy = Carbon::today()->toDateString();

        // updateOrCreate busca si ya existe un registro hoy. Si existe, lo actualiza. Si no, lo crea.
        Asistencia::updateOrCreate(
            ['user_id' => $request->user_id, 'date' => $hoy],
            [
                'time_in' => $request->time_in,
                'aseo' => $request->aseo,
                'uniforme' => $request->uniforme
            ]
        );

        return response()->json(['message' => 'Asistencia registrada con éxito']);
    }
}