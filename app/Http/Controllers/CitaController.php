<?php

namespace App\Http\Controllers;

use App\Models\Cita;
use App\Models\Cliente;
use App\Models\Usuario;
use App\Models\Articulo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CitaController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate(['fecha' => 'sometimes|required|date_format:Y-m-d']);
        $fecha = $validated['fecha'] ?? now(config('app.timezone'))->toDateString();
        // Traemos las citas con sus estilistas y servicios
        $citas = Cita::with(['cliente', 'estilistas', 'servicio'])
                            ->whereDate('appointment_date', $fecha)
                            ->orderBy('appointment_date', 'asc')
                            ->get();

        return response()->json($citas);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'service_id' => 'required|exists:services,id',
            'appointment_date' => 'required|date',
            'stylists' => 'required|array', // Ahora validamos que sea un arreglo de estilistas
            'stylists.*' => 'exists:users,id',
            'duration_minutes' => 'nullable|integer',
            'notes' => 'nullable|string'
        ]);

        $cita = DB::transaction(function () use ($validatedData) {
            $cita = Cita::create([
                'client_id' => $validatedData['client_id'],
                'service_id' => $validatedData['service_id'],
                'appointment_date' => $validatedData['appointment_date'],
                'duration_minutes' => $validatedData['duration_minutes'] ?? 45,
                'notes' => $validatedData['notes'] ?? null,
                'status' => 'pendiente'
            ]);

            // Sincronizamos la tabla intermedia con los estilistas seleccionados
            $cita->estilistas()->sync($validatedData['stylists']);

            return $cita->refresh();
        });

        return response()->json(['message' => 'Cita agendada exitosamente', 'appointment' => $cita], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    // El método update se usará cuando el estilista marque la cita como "completada"
    public function update(Request $request, Cita $appointment)
    {
        $request->validate(['status' => 'sometimes|required|in:pendiente,completada,cancelada,no_asistio']);

        $appointment->update([
            'status' => $request->input('status', 'completada')
        ]);
        
        return response()->json(['message' => 'Estado de la cita actualizado']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
