<?php

namespace App\Http\Controllers;

use App\Models\Cita;
use App\Services\AgendaService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
    public function show(Cita $appointment, AgendaService $agenda)
    {
        return response()->json($agenda->paraEditar($appointment->id));
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
    // Transición explícita H16. La edición de datos usa updateDetails.
    public function update(Request $request, Cita $appointment, AgendaService $agenda)
    {
        $request->validate([
            'status' => 'required|in:pendiente,completada,cancelada,no_asistio',
            'client_id' => 'prohibited', 'service_id' => 'prohibited', 'appointment_date' => 'prohibited',
            'stylists' => 'prohibited', 'notes' => 'prohibited', 'edit_token' => 'prohibited',
        ]);

        $agenda->cambiarEstado($appointment->id, $request->input('status'));
        
        return response()->json(['message' => 'Estado de la cita actualizado']);
    }

    public function updateDetails(Request $request, Cita $appointment, AgendaService $agenda)
    {
        $data = $request->validate([
            'edit_token' => 'required|string|size:64',
            'client_id' => 'sometimes|required|exists:clients,id',
            'service_id' => 'sometimes|required|exists:services,id',
            'appointment_date' => 'sometimes|required|date',
            'stylists' => 'sometimes|required|array|min:1',
            'stylists.*' => 'required|integer|distinct|exists:users,id',
            'notes' => 'sometimes|nullable|string', 'status' => 'prohibited',
            'duration_minutes' => 'prohibited',
        ]);
        if (!array_intersect(array_keys($data), ['client_id', 'service_id', 'appointment_date', 'stylists', 'notes'])) {
            throw ValidationException::withMessages(['editing' => 'Indique los datos que desea editar.']);
        }
        $cita = $agenda->editar($appointment->id, $data);
        return response()->json(['message' => 'Cita actualizada exitosamente', 'appointment' => $cita]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
