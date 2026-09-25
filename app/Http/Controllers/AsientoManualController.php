<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AsientoContable;
use App\Models\DetalleAsiento;
use App\Models\CuentaContable;
use App\Models\CentroCosto;
use App\Services\ContabilidadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class AsientoManualController extends Controller
{
    public function index()
    {
        $asientos = AsientoContable::where('modulo_origen', 'manual')
            ->with(['detalles.cuenta', 'detalles.centroCosto'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('accounting.asientos.index', compact('asientos'));
    }

    public function create()
    {
        $cuentas = CuentaContable::where('permite_movimiento', true)
            ->where('activa', true)
            ->orderBy('codigo')
            ->get();

        $centrosCosto = \App\Models\CentroCosto::where('activo', true)->get();

        return view('accounting.asientos.create', compact('cuentas', 'centrosCosto'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'fecha' => 'required|date',
            'concepto' => 'required|string|max:255',
            'lineas' => 'required|array|min:2',
            'lineas.*.cuenta_id' => 'required|exists:cuentas_contables,id',
            'lineas.*.debe' => 'nullable|numeric|min:0',
            'lineas.*.haber' => 'nullable|numeric|min:0',
            'lineas.*.centro_costo_id' => 'nullable|exists:centros_costo,id',
            'lineas.*.descripcion' => 'nullable|string|max:255',
        ]);

        // Validar que hay al menos un debe y un haber
        $tieneDebe = collect($request->lineas)->contains('debe', '>', 0);
        $tieneHaber = collect($request->lineas)->contains('haber', '>', 0);

        if (!$tieneDebe || !$tieneHaber) {
            return back()->withErrors(['lineas' => 'Debe haber al menos una línea en DEBE y una en HABER.'])->withInput();
        }

        // Validar que DEBE = HABER
        $totalDebe = collect($request->lineas)->sum('debe');
        $totalHaber = collect($request->lineas)->sum('haber');

        if (abs($totalDebe - $totalHaber) > 0.01) {
            return back()->withErrors(['lineas' => 'La suma del DEBE debe ser igual a la suma del HABER.'])->withInput();
        }

        DB::beginTransaction();

        try {
            $monedaBase = $this->getMonedaBaseId();

            $asiento = AsientoContable::create([
                'numero_asiento' => 'MAN-' . time(),
                'fecha' => $request->fecha,
                'concepto' => $request->concepto,
                'modulo_origen' => 'manual',
                'usuario_id' => auth()->id() ?? 1,
                'periodo_id' => $this->getPeriodoAbierto()->id,
            ]);

            foreach ($request->lineas as $linea) {
                if (($linea['debe'] ?? 0) > 0 || ($linea['haber'] ?? 0) > 0) {
                    DetalleAsiento::create([
                        'asiento_id' => $asiento->id,
                        'cuenta_id' => $linea['cuenta_id'],
                        'centro_costo_id' => $linea['centro_costo_id'] ?? null,
                        'moneda_id' => $monedaBase,
                        'debe' => $linea['debe'] ?? 0,
                        'haber' => $linea['haber'] ?? 0,
                        'descripcion' => $linea['descripcion'] ?? '',
                    ]);
                }
            }

            $this->validarCuadre($asiento->id);
            DB::commit();

            return redirect()->route('asientos.show', $asiento->id)
                ->with('success', 'Asiento manual creado y contabilizado exitosamente.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al crear asiento manual', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => request()->all(),
            ]);
            return back()->withErrors(['error' => 'Error al crear el asiento: ' . $e->getMessage()])->withInput();
        }
    }

    public function show($id)
    {
        $asiento = AsientoContable::with(['detalles.cuenta', 'detalles.centroCosto', 'usuario'])->findOrFail($id);
        return view('accounting.asientos.show', compact('asiento'));
    }

    public function reversar(Request $request, $id)
    {
        $asientoOriginal = \App\Models\AsientoContable::with('detalles')->findOrFail($id);

        if ($asientoOriginal->modulo_origen === 'reversion') {
            return back()->withErrors(['error' => 'No se puede revertir un asiento que ya es una reversión.']);
        }

        DB::beginTransaction();

        try {
            $monedaBase = $this->getMonedaBaseId();

            $asientoReversion = \App\Models\AsientoContable::create([
                'numero_asiento' => 'REV-' . $id,
                'fecha' => now(),
                'concepto' => 'Reversión de asiento #' . $asientoOriginal->numero_asiento . ': ' . $asientoOriginal->concepto,
                'modulo_origen' => 'reversion',
                'referencia_id' => $asientoOriginal->id,
                'periodo_id' => $this->getPeriodoAbierto()->id,
                'usuario_id' => auth()->id() ?? 1,
            ]);

            foreach ($asientoOriginal->detalles as $detalle) {
                \App\Models\DetalleAsiento::create([
                    'asiento_id' => $asientoReversion->id,
                    'cuenta_id' => $detalle->cuenta_id,
                    'centro_costo_id' => $detalle->centro_costo_id,
                    'moneda_id' => $detalle->moneda_id ?? $monedaBase,
                    'debe' => $detalle->haber,  // Invertido
                    'haber' => $detalle->debe,  // Invertido
                    'descripcion' => 'Reversión: ' . $detalle->descripcion,
                ]);
            }

            $this->validarCuadre($asientoReversion->id);
            DB::commit();

            return back()->with('success', 'Asiento revertido exitosamente. Se creó el asiento de reversión.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al revertir asiento', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->withErrors(['error' => 'Error al revertir el asiento: ' . $e->getMessage()]);
        }
    }

    protected function getPeriodoAbierto()
    {
        return DB::table('periodos_contables')->where('estado', 'abierto')->first();
    }

    protected function getMonedaBaseId()
    {
        $moneda = DB::table('monedas')->where('es_base', true)->first();
        if (!$moneda) {
            throw new \Exception('No se ha definido una moneda base contable.');
        }
        return $moneda->id;
    }

    protected function validarCuadre($asientoId)
    {
        $totales = DB::table('detalle_asientos')
            ->where('asiento_id', $asientoId)
            ->selectRaw('SUM(debe) as total_debe, SUM(haber) as total_haber')
            ->first();

        $debe = round($totales->total_debe ?? 0, 2);
        $haber = round($totales->total_haber ?? 0, 2);

        if ($debe !== $haber) {
            throw new \Exception("Error Crítico de Partida Doble: Asiento descuadrado por C$ " . number_format(abs($debe - $haber), 2));
        }
    }
}