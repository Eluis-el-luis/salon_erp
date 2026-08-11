<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use App\Models\Item;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function index()
    {
        // Cambiamos 'stylist' por 'stylists'
        $appointments = Appointment::with(['client', 'stylists', 'service'])
                            ->whereDate('appointment_date', today())
                            ->orderBy('appointment_date', 'asc')
                            ->get();

        return response()->json($appointments);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'service_id' => 'required|exists:items,id',
            'appointment_date' => 'required|date',
            'stylists' => 'required|array', // Ahora validamos que sea un arreglo de estilistas
            'stylists.*' => 'exists:users,id',
            'duration_minutes' => 'nullable|integer',
            'notes' => 'nullable|string'
        ]);

        $appointment = Appointment::create([
            'client_id' => $validatedData['client_id'],
            'service_id' => $validatedData['service_id'],
            'appointment_date' => $validatedData['appointment_date'],
            'duration_minutes' => $validatedData['duration_minutes'] ?? 45,
            'notes' => $validatedData['notes'] ?? null,
            'status' => 'pendiente'
        ]);

        // Sincronizamos la tabla intermedia con los estilistas seleccionados
        $appointment->stylists()->sync($validatedData['stylists']);

        return response()->json(['message' => 'Cita agendada exitosamente', 'appointment' => $appointment], 201);
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
    public function update(Request $request, Appointment $appointment)
    {
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
