<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Advance;
use App\Models\User;
use App\Models\Client;
use Illuminate\Support\Facades\Validator;
use App\Services\ContabilidadService;
use Illuminate\Support\Facades\DB;

class AdvanceController extends Controller
{
    public function index()
    {
        // Traemos todos los movimientos (Debe y Haber) con sus respectivos dueños
        $advances = Advance::with(['user', 'client'])->orderBy('date', 'desc')->get();
        
        // Listas para el formulario
        $employees = User::where('is_active', true)->orderBy('name', 'asc')->get();
        $clients = Client::orderBy('name', 'asc')->get();
        
        return view('advances.index', compact('advances', 'employees', 'clients'));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|exists:users,id',
            'client_id' => 'nullable|exists:clients,id',
            'type' => 'required|in:debe,haber',
            'date' => 'required|date',
            'amount' => 'required|numeric|min:1',
            'description' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!$request->user_id && !$request->client_id) {
            return response()->json(['errors' => ['general' => 'Debes seleccionar un empleado o un cliente.']], 422);
        }

        DB::beginTransaction();

        try {
            // 1. Guardar el movimiento
            $advance = Advance::create($request->all());

            // 2. Disparar el motor contable
            $contabilidad = new ContabilidadService();
            // Asumimos que los adelantos y cobros en el salón se manejan desde la Caja en efectivo
            $contabilidad->contabilizarAdelantoCXC($advance, 'efectivo');

            DB::commit();

            return response()->json(['message' => 'Movimiento registrado y contabilizado exitosamente']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['errors' => ['general' => 'Error contable: ' . $e->getMessage()]], 500);
        }
    }

    public function destroy($id)
    {
        $advance = Advance::findOrFail($id);

        \Illuminate\Support\Facades\DB::beginTransaction();

        try {
            // 1. Determinar la naturaleza contraria para anular el efecto
            $tipoContrario = $advance->type == 'debe' ? 'haber' : 'debe';

            // 2. Crear el registro de reversión automático
            $reversion = Advance::create([
                'user_id' => $advance->user_id,
                'client_id' => $advance->client_id,
                'date' => \Carbon\Carbon::now()->toDateString(), // Fecha en que se hace la anulación
                'amount' => $advance->amount,
                'type' => $tipoContrario,
                'description' => 'REVERSIÓN AUTOMÁTICA MOV. #' . $advance->id . ': ' . $advance->description,
            ]);

            // 3. Disparar el motor contable para que haga el asiento inverso
            $contabilidad = new \App\Services\ContabilidadService();
            $contabilidad->contabilizarAdelantoCXC($reversion, 'efectivo');

            \Illuminate\Support\Facades\DB::commit();

            return response()->json([
                'message' => 'Se generó un movimiento de reversión automático para mantener el cuadre contable.'
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json([
                'errors' => ['general' => 'Error contable al revertir: ' . $e->getMessage()]
            ], 500);
        }
    }
}