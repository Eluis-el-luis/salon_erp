<?php

namespace App\Http\Controllers;

use App\Models\Cita;
use App\Services\AgendaService;
use Illuminate\Http\Request;

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

    public function store(Request $request, AgendaService $agenda)
    {
        $validatedData = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'service_id' => 'required|exists:services,id',
            'appointment_date' => 'required|date',
            'stylists' => 'required|array|min:1',
            'stylists.*' => 'required|integer|distinct|exists:users,id',
            // Compatibilidad con consumidores anteriores: nunca se usa como duración.
            'duration_minutes' => 'sometimes|integer|min:1',
            'notes' => 'nullable|string'
        ]);

        $cita = $agenda->reservar($validatedData);

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
    public function update(Request $request, Cita $appointment, AgendaService $agenda)
    {
        $request->validate(['status' => 'sometimes|required|in:pendiente,completada,cancelada,no_asistio']);

        $agenda->cambiarEstado($appointment->id, $request->input('status', 'completada'));
        
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
