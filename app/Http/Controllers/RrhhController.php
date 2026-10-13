<?php

namespace App\Http\Controllers;

use App\Models\AdelantoCuota;
use App\Models\AsignacionTurno;
use App\Models\Asistencia;
use App\Models\Ausencia;
use App\Models\ColillaNomina;
use App\Models\ComisionGenerada;
use App\Models\Empleado;
use App\Models\Propina;
use App\Models\Turno;
use App\Services\ContabilidadService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dashboard de RRHH (HRMS): KPIs del personal, Perfil 360 del colaborador,
 * gestión de turnos (Shift Roster) y registro de propinas en custodia.
 */
class RrhhController extends Controller
{
    protected ContabilidadService $contabilidad;

    public function __construct(ContabilidadService $contabilidad)
    {
        $this->contabilidad = $contabilidad;
    }

    // =====================================================================
    // DASHBOARD RRHH
    // =====================================================================

    public function index(Request $request)
    {
        $mes = $request->filled('mes') ? Carbon::parse($request->mes.'-01')->startOfMonth() : Carbon::now()->startOfMonth();
        $hoy = Carbon::today();

        $kpis = [
            'activos' => Empleado::where('estado_laboral', 'activo')->count(),
            'en_ausencia_hoy' => Ausencia::where('estado', 'aprobada')
                ->whereDate('fecha_inicio', '<=', $hoy)->whereDate('fecha_fin', '>=', $hoy)->count(),
            'solicitudes_pendientes' => Ausencia::where('estado', 'pendiente')->count(),
            'tardanzas_mes' => Asistencia::whereBetween('date', [$mes->copy()->startOfMonth(), $mes->copy()->endOfMonth()->toDateString()])
                ->where('minutos_tardanza', '>', 0)->count(),
            'horas_extras_mes' => round((float) Asistencia::whereBetween('date', [$mes->copy()->startOfMonth()->toDateString(), $mes->copy()->endOfMonth()->toDateString()])
                ->sum('horas_extras'), 2),
            'propinas_pendientes' => round((float) Propina::where('estado', 'pendiente')->sum('monto'), 2),
        ];

        // Costo laboral contabilizado en el mes (colillas pagadas)
        $kpis['costo_laboral_mes'] = round((float) ColillaNomina::where('estado', 'pagada')
            ->whereBetween('fecha_pago', [$mes->copy()->startOfMonth()->toDateString(), $mes->copy()->endOfMonth()->toDateString()])
            ->sum('neto_pagar'), 2);

        // Cumpleaños? No hay datos. Próximas ausencias aprobadas (7 días)
        $ausenciasProximas = Ausencia::with(['empleado.usuario', 'tipo'])
            ->where('estado', 'aprobada')
            ->whereDate('fecha_inicio', '>=', $hoy)
            ->whereDate('fecha_inicio', '<=', $hoy->copy()->addDays(7))
            ->orderBy('fecha_inicio')
            ->get();

        // Calendario del mes: ausencias aprobadas y pendientes
        $ausenciasMes = Ausencia::with(['empleado.usuario', 'tipo'])
            ->whereIn('estado', ['aprobada', 'pendiente'])
            ->whereDate('fecha_inicio', '<=', $mes->copy()->endOfMonth()->toDateString())
            ->whereDate('fecha_fin', '>=', $mes->copy()->startOfMonth()->toDateString())
            ->get();

        // Resumen de tardanzas por colaborador (mes en curso)
        $tardanzasPorEmpleado = Asistencia::join('users', 'users.id', '=', 'attendances.user_id')
            ->join('employees', 'employees.user_id', '=', 'users.id')
            ->whereBetween('attendances.date', [$mes->copy()->startOfMonth()->toDateString(), $mes->copy()->endOfMonth()->toDateString()])
            ->where('attendances.minutos_tardanza', '>', 0)
            ->groupBy('employees.id', 'users.name', 'employees.codigo_empleado')
            ->selectRaw('employees.id as employee_id, users.name, employees.codigo_empleado, COUNT(*) as incidencias, SUM(attendances.minutos_tardanza) as minutos')
            ->orderByDesc('incidencias')
            ->get();

        return view('rrhh.index', compact('kpis', 'ausenciasProximas', 'ausenciasMes', 'tardanzasPorEmpleado', 'mes'));
    }

    // =====================================================================
    // PERFIL 360 DEL COLABORADOR
    // =====================================================================

    public function perfil(int $id)
    {
        $empleado = Empleado::with(['usuario', 'estructura', 'asignacionesTurno.turno'])->findOrFail($id);
        $hace30 = Carbon::today()->subDays(30)->toDateString();
        $hoy = Carbon::today()->toDateString();

        $asistencia30 = Asistencia::where('user_id', $empleado->user_id)
            ->whereBetween('date', [$hace30, $hoy])
            ->get();

        $asistencia = [
            'dias_registrados' => $asistencia30->count(),
            'tardanzas' => $asistencia30->where('minutos_tardanza', '>', 0)->count(),
            'minutos_tardanza' => (int) $asistencia30->sum('minutos_tardanza'),
            'horas_extras' => round((float) $asistencia30->sum('horas_extras'), 2),
        ];

        $comisiones = [
            'pendientes' => (int) ComisionGenerada::where('empleado_id', $empleado->user_id)->where('estado', 'pendiente')->count(),
            'monto' => round((float) ComisionGenerada::where('empleado_id', $empleado->user_id)->where('estado', 'pendiente')->sum('monto_comision'), 2),
        ];

        $cuotasPendientes = AdelantoCuota::whereHas('adelanto', fn ($q) => $q->where('user_id', $empleado->user_id))
            ->whereIn('estado', ['pendiente', 'parcial'])
            ->get();
        $cuentaAdelantos = [
            'cuotas' => $cuotasPendientes->count(),
            'saldo' => round($cuotasPendientes->sum(fn ($c) => max(0, (float) $c->monto - (float) $c->monto_pagado)), 2),
        ];

        $propinasPendientes = round((float) Propina::where('employee_id', $empleado->id)->where('estado', 'pendiente')->sum('monto'), 2);

        $colillas = $empleado->colillas()->with('corrida')->limit(6)->get();
        $ausencias = $empleado->ausencias()->with('tipo')->limit(10)->get();
        $turnoVigente = $empleado->turnoVigente();

        return view('rrhh.perfil', compact(
            'empleado', 'asistencia', 'comisiones', 'cuentaAdelantos', 'propinasPendientes',
            'colillas', 'ausencias', 'turnoVigente'
        ));
    }

    // =====================================================================
    // TURNOS (Shift Roster)
    // =====================================================================

    public function turnos()
    {
        $turnos = Turno::withCount('asignaciones')->orderBy('nombre')->get();
        $empleados = Empleado::with(['usuario', 'asignacionesTurno.turno'])
            ->where('estado_laboral', 'activo')
            ->orderBy('codigo_empleado')
            ->get()
            ->map(function ($empleado) {
                $empleado->turno_actual = $empleado->turnoVigente();

                return $empleado;
            });

        return view('rrhh.turnos', compact('turnos', 'empleados'));
    }

    public function turnosStore(Request $request)
    {
        $datos = $request->validate([
            'nombre' => 'required|string|max:120|unique:shifts,nombre',
            'hora_entrada' => 'required|date_format:H:i',
            'hora_salida' => 'required|date_format:H:i',
            'tolerancia_minutos' => 'required|integer|min:0|max:240',
        ]);

        Turno::create($datos);

        return redirect('/rrhh/turnos')->with('success', 'Turno creado correctamente.');
    }

    public function turnosUpdate(Request $request, int $id)
    {
        $turno = Turno::findOrFail($id);

        $datos = $request->validate([
            'nombre' => 'required|string|max:120|unique:shifts,nombre,'.$turno->id,
            'hora_entrada' => 'required|date_format:H:i',
            'hora_salida' => 'required|date_format:H:i',
            'tolerancia_minutos' => 'required|integer|min:0|max:240',
            'activo' => 'nullable|boolean',
        ]);

        $turno->update($datos + ['activo' => $request->boolean('activo')]);

        return redirect('/rrhh/turnos')->with('success', 'Turno actualizado.');
    }

    /** Asigna un turno a un colaborador cerrando la asignación anterior. */
    public function turnosAsignar(Request $request)
    {
        $datos = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'shift_id' => 'required|exists:shifts,id',
            'vigente_desde' => 'required|date',
        ]);

        DB::beginTransaction();
        try {
            // Cierra la asignación vigente el día anterior al nuevo inicio
            AsignacionTurno::where('employee_id', $datos['employee_id'])
                ->whereNull('vigente_hasta')
                ->update(['vigente_hasta' => Carbon::parse($datos['vigente_desde'])->subDay()->toDateString()]);

            AsignacionTurno::create($datos);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error al asignar turno', ['exception' => $e->getMessage()]);

            return redirect('/rrhh/turnos')->withErrors(['error' => 'No se pudo asignar el turno: '.$e->getMessage()]);
        }

        return redirect('/rrhh/turnos')->with('success', 'Turno asignado correctamente.');
    }

    // =====================================================================
    // PROPINAS EN CUSTODIA
    // =====================================================================

    public function propinas()
    {
        $pendientes = Propina::with('empleado.usuario')
            ->where('estado', 'pendiente')
            ->orderByDesc('fecha')
            ->get()
            ->groupBy(fn ($p) => $p->empleado->codigo_empleado);

        $historial = Propina::with(['empleado.usuario', 'colilla'])
            ->orderByDesc('fecha')
            ->limit(30)
            ->get();

        $empleados = Empleado::where('estado_laboral', 'activo')->with('usuario')->orderBy('codigo_empleado')->get();

        return view('rrhh.propinas', compact('pendientes', 'historial', 'empleados'));
    }

    /**
     * Registra propinas en custodia y genera el asiento de reconocimiento
     * (DEBE 1.1.1.3 Propinas en Custodia / HABER 2.1.6 Propinas por Pagar).
     */
    public function propinasStore(Request $request)
    {
        $datos = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'fecha' => 'required|date|before_or_equal:today',
            'monto' => 'required|numeric|min:0.01|max:999999',
            'observacion' => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            $empleado = Empleado::with('usuario')->findOrFail($datos['employee_id']);

            $asientoId = $this->contabilidad->contabilizarRegistroPropinas(
                (float) $datos['monto'],
                $empleado->nombre,
                $datos['fecha'],
            );

            Propina::create([
                'employee_id' => $empleado->id,
                'fecha' => $datos['fecha'],
                'monto' => $datos['monto'],
                'estado' => 'pendiente',
                'asiento_contable_id' => $asientoId,
                'observacion' => $datos['observacion'] ?? null,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error al registrar propinas', ['exception' => $e->getMessage()]);

            return redirect('/rrhh/propinas')->withErrors(['error' => 'No se pudieron registrar las propinas: '.$e->getMessage()]);
        }

        return redirect('/rrhh/propinas')->with('success', 'Propinas registradas y contabilizadas en custodia.');
    }
}
