<?php

namespace App\Http\Controllers;

use App\Models\Asistencia;
use App\Models\Ausencia;
use App\Models\Empleado;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Control de Asistencia HRMS: registro de entrada y salida contra el turno
 * vigente del colaborador, cálculo automático de minutos de tardanza (fuera de
 * tolerancia) y horas extra sobre la jornada estándar.
 */
class AsistenciaController extends Controller
{
    // Mostrar la lista de empleados para marcar asistencia del día (+ resumen del mes)
    public function index(Request $request)
    {
        $hoy = $request->filled('fecha')
            ? Carbon::parse($request->fecha)->toDateString()
            : Carbon::today()->toDateString();

        // Empleados activos con su expediente y turno vigente
        $empleados = Empleado::with(['usuario', 'asignacionesTurno.turno'])
            ->whereHas('usuario', fn ($q) => $q->where('is_active', true))
            ->whereIn('estado_laboral', ['activo', 'vacaciones', 'licencia'])
            ->orderBy('codigo_empleado')
            ->get()
            ->map(function ($empleado) use ($hoy) {
                $empleado->asistencia_hoy = Asistencia::where('user_id', $empleado->user_id)->where('date', $hoy)->first();
                $empleado->turno_actual = $empleado->turnoVigente();

                return $empleado;
            });

        // Resumen mensual por colaborador
        $inicioMes = Carbon::parse($hoy)->startOfMonth()->toDateString();
        $finMes = Carbon::parse($hoy)->endOfMonth()->toDateString();

        $resumenMes = collect();
        foreach ($empleados as $empleado) {
            $asistenciasMes = Asistencia::where('user_id', $empleado->user_id)
                ->whereBetween('date', [$inicioMes, $finMes])
                ->get();

            $resumenMes->push([
                'empleado' => $empleado,
                'dias' => $asistenciasMes->count(),
                'tardanzas' => $asistenciasMes->where('minutos_tardanza', '>', 0)->count(),
                'minutos_tardanza' => (int) $asistenciasMes->sum('minutos_tardanza'),
                'horas_extras' => round((float) $asistenciasMes->sum('horas_extras'), 2),
                'ausencias' => Ausencia::where('employee_id', $empleado->id)
                    ->where('estado', 'aprobada')
                    ->whereDate('fecha_inicio', '<=', $finMes)
                    ->whereDate('fecha_fin', '>=', $inicioMes)
                    ->sum('dias'),
            ]);
        }

        return view('attendances.index', compact('empleados', 'hoy', 'resumenMes'));
    }

    // Registrar (o actualizar) la ENTRADA del día con cálculo de tardanza
    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'time_in' => 'required|date_format:H:i',
            'aseo' => 'boolean',
            'uniforme' => 'boolean',
        ]);

        $hoy = Carbon::today()->toDateString();
        $empleado = Empleado::where('user_id', $request->user_id)->first();
        $turno = $empleado?->turnoVigente();

        $asistencia = Asistencia::updateOrCreate(
            ['user_id' => $request->user_id, 'date' => $hoy],
            [
                'time_in' => $request->time_in,
                'shift_id' => $turno?->id,
                'aseo' => $request->boolean('aseo', true),
                'uniforme' => $request->boolean('uniforme', true),
            ],
        );

        // Tardanza: minutos de exceso sobre (hora_entrada + tolerancia)
        $tardanza = 0;
        if ($turno) {
            $limite = Carbon::parse($hoy.' '.$turno->hora_entrada)->addMinutes($turno->tolerancia_minutos);
            $entrada = Carbon::parse($hoy.' '.$request->time_in);
            $tardanza = $entrada->gt($limite) ? $entrada->diffInMinutes($limite) : 0;
        }

        $asistencia->update([
            'minutos_tardanza' => $tardanza,
            'estado' => $tardanza > 0 ? 'tardanza' : 'asistio',
        ]);

        $this->recalcularHoras($asistencia->refresh());

        return response()->json([
            'message' => $tardanza > 0
                ? "Asistencia registrada con {$tardanza} minutos de tardanza."
                : 'Asistencia registrada con éxito (puntual).',
            'tardanza' => $tardanza,
            'estado' => $asistencia->estado,
        ]);
    }

    // Registrar la SALIDA del día y calcular horas trabajadas / extras
    public function salida(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'time_out' => 'required|date_format:H:i',
        ]);

        $hoy = Carbon::today()->toDateString();

        $asistencia = Asistencia::where('user_id', $request->user_id)->where('date', $hoy)->first();
        if (! $asistencia) {
            return response()->json(['message' => 'El colaborador no tiene entrada registrada hoy.'], 422);
        }

        $asistencia->time_out = $request->time_out;
        $this->recalcularHoras($asistencia);

        return response()->json([
            'message' => 'Salida registrada: '.$asistencia->horas_trabajadas.' h trabajadas'
                .($asistencia->horas_extras > 0 ? " (+{$asistencia->horas_extras} h extra)" : '').'.',
            'horas_trabajadas' => $asistencia->horas_trabajadas,
            'horas_extras' => $asistencia->horas_extras,
        ]);
    }

    /**
     * Recalcula horas trabajadas y extras a partir de entrada/salida.
     * Horas extra = excedente sobre la jornada estándar (config salon.nomina).
     */
    protected function recalcularHoras(Asistencia $asistencia): void
    {
        if (! $asistencia->time_in || ! $asistencia->time_out) {
            return;
        }

        $fecha = Carbon::parse($asistencia->date)->toDateString();
        $entrada = Carbon::parse($fecha.' '.$asistencia->time_in);
        $salida = Carbon::parse($fecha.' '.$asistencia->time_out);

        if ($salida->lte($entrada)) {
            return; // salida inválida (cruce de medianoche no soportado: salón diurno)
        }

        $minutos = $entrada->diffInMinutes($salida);
        $horas = round($minutos / 60, 2);
        $jornada = (float) config('salon.nomina.jornada_horas_diarias', 8);

        $asistencia->update([
            'horas_trabajadas' => $horas,
            'horas_extras' => round(max(0, $horas - $jornada), 2),
        ]);
    }
}
