<?php

namespace App\Http\Controllers;

use App\Models\AdelantoCuota;
use App\Models\ColillaNomina;
use App\Models\ComisionGenerada;
use App\Models\CorridaNomina;
use App\Models\Empleado;
use App\Models\EstructuraSalarial;
use App\Models\Nomina;
use App\Models\Propina;
use App\Services\NominaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Nómina corporativa (HRMS): wizard de corrida con previsualización previa a
 * contabilizar, colillas auditables/imprimibles, anulación con reversión y
 * gestión de estructuras salariales (Salary Structures).
 */
class NominaController extends Controller
{
    public function __construct(protected NominaService $nominaService) {}

    // =====================================================================
    // DASHBOARD
    // =====================================================================

    public function index()
    {
        $corridas = CorridaNomina::with(['colillas.empleado.usuario', 'creador'])
            ->orderByDesc('periodo_fin')
            ->limit(20)
            ->get();

        // Historial legacy (planillas pre-HRMS): solo lectura
        $nominasLegacy = Nomina::with('usuario')->orderByDesc('created_at')->limit(15)->get();

        $kpis = [
            'corridas_mes' => CorridaNomina::where('estado', 'contabilizada')
                ->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count(),
            'neto_mes' => round((float) ColillaNomina::where('estado', 'pagada')
                ->whereMonth('fecha_pago', now()->month)->whereYear('fecha_pago', now()->year)->sum('neto_pagar'), 2),
            'comisiones_pendientes' => round((float) ComisionGenerada::where('estado', 'pendiente')->sum('monto_comision'), 2),
            'propinas_pendientes' => round((float) Propina::where('estado', 'pendiente')->sum('monto'), 2),
        ];

        // Cuotas de adelantos vencidas al día de hoy (respetando monto_pagado parcial)
        $cuotas = AdelantoCuota::whereHas('adelanto', fn ($q) => $q->whereNotNull('user_id'))
            ->whereIn('estado', ['pendiente', 'parcial'])
            ->get();
        $kpis['cuotas_vencidas'] = round($cuotas->filter(fn ($c) => $c->fecha_vencimiento->lte(now()))
            ->sum(fn ($c) => max(0, (float) $c->monto - (float) $c->monto_pagado)), 2);

        return view('payrolls.index', compact('corridas', 'nominasLegacy', 'kpis'));
    }

    // =====================================================================
    // WIZARD DE NÓMINA (paso 1: selección)
    // =====================================================================

    public function wizard(Request $request)
    {
        $empleados = Empleado::with(['usuario', 'estructura'])
            ->where('estado_laboral', 'activo')
            ->whereHas('usuario', fn ($q) => $q->where('is_active', true))
            ->orderBy('codigo_empleado')
            ->get()
            ->map(function ($empleado) {
                $empleado->pendiente_comisiones = (float) ComisionGenerada::where('empleado_id', $empleado->user_id)
                    ->where('estado', 'pendiente')->sum('monto_comision');
                $empleado->pendiente_propinas = (float) Propina::where('employee_id', $empleado->id)
                    ->where('estado', 'pendiente')->sum('monto');

                $cuotas = AdelantoCuota::whereHas('adelanto', fn ($q) => $q->where('user_id', $empleado->user_id))
                    ->whereIn('estado', ['pendiente', 'parcial'])->get();
                $empleado->pendiente_adelantos = round($cuotas->sum(fn ($c) => max(0, (float) $c->monto - (float) $c->monto_pagado)), 2);

                $empleado->estructura_actual = $empleado->estructuraActual()?->nombre;

                return $empleado;
            });

        $estructuras = EstructuraSalarial::where('activa', true)->orderBy('nombre')->get();

        return view('payrolls.wizard', compact('empleados', 'estructuras'));
    }

    // =====================================================================
    // WIZARD (paso 2: previsualización)
    // =====================================================================

    public function previsualizar(Request $request)
    {
        $datos = $this->validarWizard($request);

        try {
            $preview = $this->nominaService->previsualizar($datos);
        } catch (\Throwable $e) {
            Log::warning('Error al previsualizar nómina', ['exception' => $e->getMessage()]);

            return redirect('/nomina/wizard')->withErrors(['error' => $e->getMessage()]);
        }

        return view('payrolls.preview', [
            'preview' => $preview,
            'datos' => $datos,
        ]);
    }

    // =====================================================================
    // WIZARD (paso 3: procesar y contabilizar)
    // =====================================================================

    public function procesar(Request $request)
    {
        $datos = $this->validarWizard($request);

        try {
            $corrida = $this->nominaService->procesar($datos);
        } catch (\Throwable $e) {
            Log::error('Error al contabilizar nómina', ['exception' => $e->getMessage()]);

            return redirect('/nomina/wizard')->withErrors(['error' => 'La nómina no fue contabilizada: '.$e->getMessage()]);
        }

        return redirect('/nomina/corrida/'.$corrida->id)
            ->with('success', "Corrida #{$corrida->id} contabilizada: ".count($datos['empleado_ids']).' colillas procesadas con asientos cuadrados.');
    }

    // =====================================================================
    // DETALLE DE CORRIDA Y COLILLA
    // =====================================================================

    public function corrida(int $id)
    {
        $corrida = CorridaNomina::with(['colillas.empleado.usuario', 'colillas.detalles', 'creador'])->findOrFail($id);

        return view('payrolls.corrida', compact('corrida'));
    }

    public function colilla(int $id)
    {
        $colilla = ColillaNomina::with(['empleado.usuario', 'detalles', 'corrida', 'asiento'])->findOrFail($id);

        return view('payrolls.colilla', compact('colilla'));
    }

    /** Anula una corrida contabilizada (reversión espejo REV-*, sin borrado físico). */
    public function anular(Request $request, int $id)
    {
        $datos = $request->validate(['motivo' => 'required|string|min:5|max:500']);

        $corrida = CorridaNomina::findOrFail($id);

        try {
            $this->nominaService->anular($corrida, $datos['motivo']);
        } catch (\Throwable $e) {
            return redirect('/nomina/corrida/'.$id)->withErrors(['error' => 'No se pudo anular la corrida: '.$e->getMessage()]);
        }

        return redirect('/nomina')->with('success', 'Corrida anulada con asientos de reversión REV-*.');
    }

    // =====================================================================
    // ESTRUCTURAS SALARIALES (Salary Structures)
    // =====================================================================

    public function estructuras()
    {
        $estructuras = EstructuraSalarial::with(['detalles', 'empleados'])->orderBy('nombre')->get();
        $calculadoras = $this->catalogoCalculadoras();

        return view('payrolls.estructuras', compact('estructuras', 'calculadoras'));
    }

    public function estructurasStore(Request $request)
    {
        $datos = $request->validate([
            'nombre' => 'required|string|max:120|unique:salary_structures,nombre',
            'descripcion' => 'nullable|string|max:1000',
            'lineas' => 'required|array|min:1',
            'lineas.*.codigo' => 'required|string|max:50',
            'lineas.*.nombre' => 'required|string|max:150',
            'lineas.*.tipo' => 'required|in:ingreso,deduccion',
            'lineas.*.calculadora' => 'required|string|in:'.implode(',', array_keys($this->catalogoCalculadoras())),
            'lineas.*.valor' => 'nullable|numeric|min:-999999|max:999999',
            'lineas.*.orden' => 'nullable|integer|min:0|max:999',
            'lineas.*.activo' => 'nullable|boolean',
        ]);

        EstructuraSalarial::create([
            'nombre' => $datos['nombre'],
            'descripcion' => $datos['descripcion'] ?? null,
            'activa' => true,
        ])->detalles()->createMany(collect($datos['lineas'])->map(fn ($l) => [
            'codigo' => strtoupper($l['codigo']),
            'nombre' => $l['nombre'],
            'tipo' => $l['tipo'],
            'calculadora' => $l['calculadora'],
            'valor' => $l['valor'] ?? 0,
            'orden' => $l['orden'] ?? 0,
            'activo' => true,
        ])->all());

        return redirect('/nomina/estructuras')->with('success', 'Estructura salarial creada.');
    }

    public function estructurasToggle(int $id)
    {
        $estructura = EstructuraSalarial::findOrFail($id);
        $estructura->update(['activa' => ! $estructura->activa]);

        return redirect('/nomina/estructuras')->with('success', 'Estado de la estructura actualizado.');
    }

    // =====================================================================
    // LEGACY (planillas pre-HRMS): colilla antigua sigue disponible
    // =====================================================================

    public function ticketLegacy(int $id)
    {
        $nomina = Nomina::with('usuario')->findOrFail($id);

        return view('payrolls.ticket', compact('nomina'));
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    protected function validarWizard(Request $request): array
    {
        return $request->validate([
            'periodo_inicio' => 'required|date',
            'periodo_fin' => 'required|date|after_or_equal:periodo_inicio',
            'metodo_pago' => 'required|in:efectivo,banco',
            'empleado_ids' => 'required|array|min:1',
            'empleado_ids.*' => 'integer|exists:employees,id',
            'estructura_id' => 'nullable|integer|exists:salary_structures,id',
        ]);
    }

    protected function catalogoCalculadoras(): array
    {
        return [
            'salario_base' => 'Salario base del empleado',
            'comisiones' => 'Comisiones congeladas (ComisionGenerada pendiente)',
            'propinas' => 'Propinas acumuladas en custodia',
            'horas_extras' => 'Horas extras de asistencia (valor = multiplicador)',
            'inss' => 'INSS laboral (tasa de config/salon.php)',
            'ir' => 'Impuesto sobre la renta (tasa de config/salon.php)',
            'sin_goce' => 'Días sin goce de sueldo (prorrateo)',
            'tardanzas' => 'Llegadas tardías (valor = monto por incidencia)',
            'adelantos' => 'Amortización de adelantos CxC (con cap)',
            'fijo' => 'Monto fijo (valor = monto)',
        ];
    }
}
