<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Cliente;
use App\Models\ClienteGrupo;
use App\Models\Venta;
use Illuminate\Support\Facades\Validator;

/**
 * FASE 1 ADMINISTRACIÓN: el cliente como centro de las Cuentas por Cobrar.
 * Index con métricas AR agregadas, perfil 360 (/clientes/{id}) con historial
 * de citas, facturas y CxC, gestión de grupos de fidelización y reglas de
 * crédito evaluadas desde el dominio (Cliente::motivoBloqueoCredito).
 */
class ClienteController extends Controller
{
    public function index()
    {
        $clientes = Cliente::with('grupo')
            ->withCount(['ventas as ventas_count', 'citas as citas_count'])
            ->withSum(['ventas as saldo_pendiente' => function ($q) {
                $q->where('status', Venta::ESTADO_EMITIDA);
            }], 'total')
            ->orderBy('name', 'asc')
            ->get();

        $grupos = ClienteGrupo::orderBy('name', 'asc')->get();

        return view('clients.index', compact('clientes', 'grupos'));
    }

    /**
     * CUSTOMER PROFILE 360: historial de citas, facturas y cuenta por cobrar
     * del cliente. Sirve la vista web o un payload JSON (apiResource clients).
     */
    public function show(Request $request, $id)
    {
        $cliente = Cliente::with('grupo')->findOrFail($id);

        $citas = $cliente->citas()
            ->with(['servicio', 'estilistas'])
            ->orderByDesc('appointment_date')
            ->limit(30)
            ->get();

        $ventas = $cliente->ventas()
            ->with('cajero')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $pendientes = $cliente->ventas()
            ->where('status', Venta::ESTADO_EMITIDA)
            ->orderBy('created_at', 'asc')
            ->get();

        $ultimaVisita = $cliente->citas()->max('appointment_date');

        $metricas = [
            'total_facturado' => round((float) $cliente->ventas()->whereIn('status', Venta::ESTADOS_DEVENGADOS)->sum('total'), 2),
            'saldo_pendiente' => $cliente->saldoPendiente(),
            'facturas_pendientes' => $pendientes->count(),
            'facturas_vencidas' => $cliente->facturasVencidas()->count(),
            'total_citas' => $cliente->citas()->count(),
            'ultima_visita' => $ultimaVisita ? \Carbon\Carbon::parse($ultimaVisita)->format('d/m/Y') : null,
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'cliente' => $cliente,
                'metricas' => $metricas,
                'pendientes' => $pendientes,
            ]);
        }

        return view('clients.show', compact('cliente', 'citas', 'ventas', 'pendientes', 'metricas'));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->reglas());

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        Cliente::create($this->datos($request));

        return response()->json(['message' => 'Cliente registrado exitosamente']);
    }

    public function update(Request $request, $id)
    {
        $cliente = Cliente::findOrFail($id);

        $validator = Validator::make($request->all(), $this->reglas());

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $cliente->update($this->datos($request));

        return response()->json(['message' => 'Datos del cliente actualizados']);
    }

    public function destroy($id)
    {
        $cliente = Cliente::findOrFail($id);

        // TRAZABILIDAD: un cliente con facturas soporta CxC y auditoría
        // contable; su historial no se puede borrar ni "desactivar".
        if ($cliente->ventas()->exists()) {
            return response()->json(['error' => 'No se puede eliminar: el cliente tiene facturas registradas (auditoría y CxC). Ajusta su grupo o datos en lugar de borrarlo.'], 422);
        }

        if ($cliente->citas()->exists()) {
            return response()->json(['error' => 'No se puede eliminar: el cliente tiene citas en el historial de la agenda.'], 422);
        }

        $cliente->delete();

        return response()->json(['message' => 'Cliente eliminado del sistema']);
    }

    /**
     * GRUPOS DE FIDELIZACIÓN: alta rápida desde el directorio de clientes.
     * Al marcar un grupo como default, se desmarca el anterior.
     */
    public function storeGrupo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:120|unique:customer_groups,name',
            'description' => 'nullable|string|max:255',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'is_default' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $grupo = ClienteGrupo::create([
            'name' => trim($request->name),
            'description' => $request->description,
            'discount_percent' => (float) ($request->discount_percent ?? 0),
            'is_default' => $request->boolean('is_default'),
        ]);

        if ($grupo->is_default) {
            ClienteGrupo::where('id', '!=', $grupo->id)->update(['is_default' => false]);
        }

        return response()->json(['message' => 'Grupo de clientes creado', 'grupo' => $grupo], 201);
    }

    private function reglas(): array
    {
        return [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'customer_group_id' => 'nullable|integer|exists:customer_groups,id',
            'credit_limit' => 'nullable|numeric|min:0|max:999999999.99',
            'credit_days' => 'nullable|integer|min:0|max:365',
            'birthday' => 'nullable|date|before:today',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    private function datos(Request $request): array
    {
        $datos = $request->only(array_keys($this->reglas()));

        // Normalizaciones para no guardar strings vacíos
        $datos['credit_days'] = (int) ($datos['credit_days'] ?? 0);

        return $datos;
    }
}
