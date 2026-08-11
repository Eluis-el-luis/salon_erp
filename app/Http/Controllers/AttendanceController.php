<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Attendance;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    // Mostrar la lista de empleados para marcar asistencia HOY
    public function index()
    {
        $today = Carbon::today()->toDateString();
        
        // Traemos a todos los empleados activos, y si ya marcaron hoy, traemos ese registro
        $employees = User::where('is_active', true)
                         ->whereIn('role', ['estilista', 'recepcion', 'admin'])
                         ->with(['attendances' => function($query) use ($today) {
                             $query->where('date', $today);
                         }])
                         ->orderBy('name', 'asc')
                         ->get();

        return view('attendances.index', compact('employees', 'today'));
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

        $today = Carbon::today()->toDateString();

        // updateOrCreate busca si ya existe un registro hoy. Si existe, lo actualiza. Si no, lo crea.
        Attendance::updateOrCreate(
            ['user_id' => $request->user_id, 'date' => $today],
            [
                'time_in' => $request->time_in,
                'aseo' => $request->aseo,
                'uniforme' => $request->uniforme
            ]
        );

        return response()->json(['message' => 'Asistencia registrada con éxito']);
    }
}