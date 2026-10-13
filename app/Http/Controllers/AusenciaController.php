<?php

namespace App\Http\Controllers;

use App\Models\Ausencia;
use App\Models\Empleado;
use App\Models\TipoAusencia;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gestión de Permisos (Leave Management): solicitud, aprobación y rechazo de
 * ausencias. Las ausencias aprobadas sin goce alimentan la deducción de la
 * colilla de nómina (NominaService::calcularColilla).
 */
class AusenciaController extends Controller
{
    public function index(Request $request)
    {
        $mes = $request->filled('mes') ? Carbon::parse($request->mes.'-01')->startOfMonth() : Carbon::now()->startOfMonth();

        $pendientes = Ausencia::with(['empleado.usuario', 'tipo', 'revisor'])
            ->where('estado', 'pendiente')
            ->orderBy('fecha_inicio')
            ->get();

        $procesadas = Ausencia::with(['empleado.usuario', 'tipo', 'revisor'])
            ->whereIn('estado', ['aprobada', 'rechazada', 'cancelada'])
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get();

        $ausenciasMes = Ausencia::with(['empleado.usuario', 'tipo'])
            ->whereIn('estado', ['aprobada', 'pendiente'])
            ->whereDate('fecha_inicio', '<=', $mes->copy()->endOfMonth()->toDateString())
            ->whereDate('fecha_fin', '>=', $mes->copy()->startOfMonth()->toDateString())
            ->get();

        $empleados = Empleado::with('usuario')->where('estado_laboral', 'activo')->orderBy('codigo_empleado')->get();
        $tipos = TipoAusencia::where('activo', true)->orderBy('nombre')->get();

        return view('ausencias.index', compact('pendientes', 'procesadas', 'ausenciasMes', 'empleados', 'tipos', 'mes'));
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'leave_type_id' => 'required|exists:leave_types,id',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            'motivo' => 'nullable|string|max:1000',
        ]);

        DB::beginTransaction();
        try {
            // Solapamiento con solicitudes activas del mismo colaborador
            $solapa = Ausencia::where('employee_id', $datos['employee_id'])
                ->whereIn('estado', ['pendiente', 'aprobada'])
                ->whereDate('fecha_inicio', '<=', $datos['fecha_fin'])
                ->whereDate('fecha_fin', '>=', $datos['fecha_inicio'])
                ->exists();

            if ($solapa) {
                DB::rollBack();

                return redirect('/ausencias')->withErrors(['error' => 'El colaborador ya tiene una ausencia activa que se solapa con esas fechas.']);
            }

            $dias = (int) abs(Carbon::parse($datos['fecha_inicio'])->diffInDays(Carbon::parse($datos['fecha_fin']))) + 1;

            Ausencia::create($datos + ['dias' => $dias, 'estado' => 'pendiente']);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error al registrar solicitud de ausencia', ['exception' => $e->getMessage()]);

            return redirect('/ausencias')->withErrors(['error' => 'No se pudo registrar la solicitud: '.$e->getMessage()]);
        }

        return redirect('/ausencias')->with('success', 'Solicitud de ausencia registrada. Queda pendiente de aprobación.');
    }

    public function aprobar(Request $request, int $id)
    {
        $ausencia = Ausencia::with('tipo')->findOrFail($id);

        if ($ausencia->estado !== 'pendiente') {
            return redirect('/ausencias')->withErrors(['error' => 'La solicitud ya fue procesada.']);
        }

        DB::beginTransaction();
        try {
            $ausencia->update([
                'estado' => 'aprobada',
                'revisado_por' => auth()->id(),
                'fecha_revision' => now(),
                'observaciones' => $request->input('observaciones'),
            ]);

            // Si la ausencia cubre hoy, refleja el estado laboral del colaborador
            $hoy = Carbon::today();
            if ($ausencia->fecha_inicio->lte($hoy) && $ausencia->fecha_fin->gte($hoy)) {
                $estadoLaboral = str_contains($ausencia->tipo->codigo, 'MED') ? 'licencia' : 'vacaciones';
                $ausencia->empleado->update(['estado_laboral' => $estadoLaboral]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error al aprobar ausencia', ['exception' => $e->getMessage()]);

            return redirect('/ausencias')->withErrors(['error' => 'No se pudo aprobar la solicitud: '.$e->getMessage()]);
        }

        return redirect('/ausencias')->with('success', 'Ausencia aprobada. Afectará el cálculo de la próxima nómina.');
    }

    public function rechazar(Request $request, int $id)
    {
        $ausencia = Ausencia::findOrFail($id);

        if ($ausencia->estado !== 'pendiente') {
            return redirect('/ausencias')->withErrors(['error' => 'La solicitud ya fue procesada.']);
        }

        $ausencia->update([
            'estado' => 'rechazada',
            'revisado_por' => auth()->id(),
            'fecha_revision' => now(),
            'observaciones' => $request->input('observaciones'),
        ]);

        return redirect('/ausencias')->with('success', 'Solicitud rechazada.');
    }

    public function cancelar(int $id)
    {
        $ausencia = Ausencia::findOrFail($id);

        if (in_array($ausencia->estado, ['rechazada', 'cancelada'])) {
            return redirect('/ausencias')->withErrors(['error' => 'La solicitud ya está cerrada.']);
        }

        $ausencia->update([
            'estado' => 'cancelada',
            'revisado_por' => auth()->id(),
            'fecha_revision' => now(),
        ]);

        // Si estaba marcando el estado laboral y ya no cubre hoy, restaura
        $hoy = Carbon::today();
        $empleado = $ausencia->empleado;
        if (! $hoy->between($ausencia->fecha_inicio, $ausencia->fecha_fin) && in_array($empleado->estado_laboral, ['vacaciones', 'licencia'])) {
            $empleado->update(['estado_laboral' => 'activo']);
        }

        return redirect('/ausencias')->with('success', 'Ausencia cancelada.');
    }
}
