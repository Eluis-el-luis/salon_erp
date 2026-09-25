<?php

namespace App\Http\Controllers;

use App\Models\CuentaContable;
use App\Models\DetalleAsiento;
use App\Models\PeriodoContable;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PeriodoContableController extends Controller
{
    /**
     * Listado de periodos contables con resumen de asientos.
     */
    public function index()
    {
        $periodos = PeriodoContable::with(['asientos.detalles'])
            ->withCount('asientos')
            ->orderBy('fecha_inicio', 'desc')
            ->paginate(15);

        return view('accounting.periodos.index', compact('periodos'));
    }

    /**
     * Formulario de creación de periodo contable.
     */
    public function create()
    {
        return view('accounting.periodos.create');
    }

    /**
     * Guardar un nuevo periodo contable.
     */
    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
        ]);

        // Evitar solapamiento de periodos (la contabilidad debe poder ubicar cada fecha)
        $solapado = PeriodoContable::whereDate('fecha_inicio', '<=', $request->fecha_fin)
            ->whereDate('fecha_fin', '>=', $request->fecha_inicio)
            ->exists();

        if ($solapado) {
            return back()
                ->withErrors(['error' => 'El rango de fechas se solapa con un periodo contable ya existente.'])
                ->withInput();
        }

        PeriodoContable::create([
            'nombre' => $request->nombre,
            'fecha_inicio' => $request->fecha_inicio,
            'fecha_fin' => $request->fecha_fin,
            'estado' => 'abierto',
        ]);

        return redirect()->route('periodos.index')
            ->with('success', 'Periodo contable creado exitosamente.');
    }

    /**
     * Detalle de un periodo con sus asientos.
     */
    public function show($id)
    {
        $periodo = PeriodoContable::with(['asientos.detalles.cuenta', 'asientos.detalles.centroCosto'])
            ->findOrFail($id);

        $totalDebe = $periodo->asientos->flatMap->detalles->sum('debe');
        $totalHaber = $periodo->asientos->flatMap->detalles->sum('haber');

        return view('accounting.periodos.show', compact('periodo', 'totalDebe', 'totalHaber'));
    }

    /**
     * Balance General en tiempo real a una fecha de corte.
     * Inyecta dinámicamente la "Utilidad del Ejercicio" para que el balance cuadre.
     */
    public function balanceGeneral(Request $request)
    {
        $fechaCorte = $request->query('fecha_corte', now()->toDateString());

        $cuentas = CuentaContable::where('permite_movimiento', true)
            ->with(['detalles' => function ($q) use ($fechaCorte) {
                $q->whereHas('asiento', function ($a) use ($fechaCorte) {
                    $a->whereDate('fecha', '<=', $fechaCorte);
                });
            }])
            ->orderBy('codigo')
            ->get();

        $cuentasActivoConSaldo = collect();
        $cuentasPasivoConSaldo = collect();
        $cuentasPatrimonioConSaldo = collect();
        $totalActivo = 0;
        $totalPasivo = 0;
        $totalPatrimonio = 0;

        $ingresos = 0;
        $costos = 0;
        $gastos = 0;
        $otros = 0;

        foreach ($cuentas as $cuenta) {
            $debe = (float) $cuenta->detalles->sum('debe');
            $haber = (float) $cuenta->detalles->sum('haber');
            $codigo = $cuenta->codigo;

            if (str_starts_with($codigo, '1')) {
                $saldo = $debe - $haber;
                if (abs($saldo) > 0.005) {
                    $cuenta->saldo_calculado = $saldo;
                    $cuentasActivoConSaldo->push($cuenta);
                    $totalActivo += $saldo;
                }
            } elseif (str_starts_with($codigo, '2')) {
                $saldo = $haber - $debe;
                if (abs($saldo) > 0.005) {
                    $cuenta->saldo_calculado = $saldo;
                    $cuentasPasivoConSaldo->push($cuenta);
                    $totalPasivo += $saldo;
                }
            } elseif (str_starts_with($codigo, '3')) {
                $saldo = $haber - $debe;
                if (abs($saldo) > 0.005) {
                    $cuenta->saldo_calculado = $saldo;
                    $cuentasPatrimonioConSaldo->push($cuenta);
                    $totalPatrimonio += $saldo;
                }
            } elseif (str_starts_with($codigo, '4')) {
                $ingresos += $haber - $debe;
            } elseif (str_starts_with($codigo, '5')) {
                $costos += $debe - $haber;
            } elseif (str_starts_with($codigo, '6')) {
                $gastos += $debe - $haber;
            } else {
                $otros += $debe - $haber;
            }
        }

        // Utilidad (o pérdida) del ejercicio no trasladada aún a capital.
        // Mantiene la identidad Activo = Pasivo + Patrimonio.
        $utilidadEjercicio = $ingresos - $costos - $gastos - $otros;

        if (abs($utilidadEjercicio) > 0.005) {
            $cuentaUtilidad = new CuentaContable([
                'codigo' => '3.99',
                'nombre' => 'Utilidad / Pérdida del Ejercicio',
                'tipo' => 'patrimonio',
                'naturaleza' => 'acreedora',
            ]);
            $cuentaUtilidad->saldo_calculado = $utilidadEjercicio;
            $cuentasPatrimonioConSaldo->push($cuentaUtilidad);
            $totalPatrimonio += $utilidadEjercicio;
        }

        return view('accounting.balance', compact(
            'fechaCorte', 'cuentasActivoConSaldo', 'cuentasPasivoConSaldo', 'cuentasPatrimonioConSaldo',
            'totalActivo', 'totalPasivo', 'totalPatrimonio'
        ));
    }

    /**
     * Cierre Fiscal: genera el asiento que deja en cero las cuentas de
     * resultado (Clases 4, 5, 6 y 7) y traslada el resultado a Utilidades Retenidas (3.2).
     */
    public function cierreFiscal(PeriodoContable $periodo)
    {
        if ($periodo->estado === 'cerrado') {
            return back()->withErrors(['error' => 'Este periodo ya está cerrado.']);
        }

        if ($periodo->fecha_fin > now()) {
            return back()->withErrors(['error' => 'No se puede cerrar un periodo futuro.']);
        }

        $cuentaUtilidades = CuentaContable::where('codigo', '3.2')->first();
        if (!$cuentaUtilidades) {
            return back()->withErrors(['error' => 'No existe la cuenta de Utilidades Retenidas (3.2).']);
        }

        DB::beginTransaction();

        try {
            $monedaBase = DB::table('monedas')->where('es_base', true)->value('id');
            if (!$monedaBase) {
                throw new \Exception('No se ha definido una moneda base contable.');
            }

            $asientoCierre = \App\Models\AsientoContable::create([
                'numero_asiento' => 'CIE-' . $periodo->id . '-' . time(),
                'fecha' => $periodo->fecha_fin,
                'concepto' => 'Cierre Fiscal ' . $periodo->nombre . ' - Cierre de Ingresos/Gastos',
                'modulo_origen' => 'cierre_fiscal',
                'referencia_id' => $periodo->id,
                'periodo_id' => $periodo->id,
                'usuario_id' => auth()->id() ?? 1,
            ]);

            $ingresos = 0;
            $costos = 0;
            $gastos = 0;
            $otros = 0;

            // Cuentas de resultado con saldo del periodo (se excluye el propio cierre).
            $cuentasResultado = CuentaContable::where('permite_movimiento', true)
                ->where(function ($q) {
                    $q->where('codigo', 'like', '4.%')
                      ->orWhere('codigo', 'like', '5.%')
                      ->orWhere('codigo', 'like', '6.%')
                      ->orWhere('codigo', 'like', '7.%');
                })
                ->with(['detalles' => function ($q) use ($periodo) {
                    $q->whereHas('asiento', function ($a) use ($periodo) {
                        $a->where('periodo_id', $periodo->id)
                          ->where('modulo_origen', '!=', 'cierre_fiscal');
                    });
                }])
                ->get();

            foreach ($cuentasResultado as $cuenta) {
                $debe = (float) $cuenta->detalles->sum('debe');
                $haber = (float) $cuenta->detalles->sum('haber');

                if (str_starts_with($cuenta->codigo, '4')) {
                    $saldo = $haber - $debe; // saldo acreedor
                    $ingresos += $saldo;
                    if (abs($saldo) > 0.005) {
                        DetalleAsiento::create([
                            'asiento_id' => $asientoCierre->id,
                            'cuenta_id' => $cuenta->id,
                            'moneda_id' => $monedaBase,
                            'debe' => round($saldo, 2),
                            'haber' => 0,
                            'descripcion' => 'Cierre Ingreso: ' . $cuenta->nombre,
                        ]);
                    }
                } else {
                    $saldo = $debe - $haber; // saldo deudor (costos, gastos y otros)
                    if (str_starts_with($cuenta->codigo, '5')) $costos += $saldo;
                    elseif (str_starts_with($cuenta->codigo, '6')) $gastos += $saldo;
                    else $otros += $saldo;

                    if (abs($saldo) > 0.005) {
                        DetalleAsiento::create([
                            'asiento_id' => $asientoCierre->id,
                            'cuenta_id' => $cuenta->id,
                            'moneda_id' => $monedaBase,
                            'debe' => 0,
                            'haber' => round($saldo, 2),
                            'descripcion' => 'Cierre Resultado: ' . $cuenta->nombre,
                        ]);
                    }
                }
            }

            $utilidadNeta = round($ingresos - ($costos + $gastos + $otros), 2);

            if (abs($utilidadNeta) > 0.005) {
                DetalleAsiento::create([
                    'asiento_id' => $asientoCierre->id,
                    'cuenta_id' => $cuentaUtilidades->id,
                    'moneda_id' => $monedaBase,
                    'debe' => $utilidadNeta > 0 ? 0 : abs($utilidadNeta),
                    'haber' => $utilidadNeta > 0 ? $utilidadNeta : 0,
                    'descripcion' => 'Resultado del Ejercicio ' . $periodo->nombre,
                ]);
            }

            $this->validarCuadre($asientoCierre->id);

            $periodo->update(['estado' => 'cerrado']);

            DB::commit();

            return redirect()->route('periodos.index')
                ->with('success', 'Cierre fiscal ejecutado exitosamente. Periodo cerrado.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en cierre fiscal', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->withErrors(['error' => 'Error en cierre fiscal: ' . $e->getMessage()]);
        }
    }

    /**
     * Candado de partida doble (DEBE = HABER exacto al centavo).
     */
    protected function validarCuadre($asientoId)
    {
        $totales = DB::table('detalle_asientos')
            ->where('asiento_id', $asientoId)
            ->selectRaw('SUM(debe) as total_debe, SUM(haber) as total_haber')
            ->first();

        $debeCents = (int) round(((float) ($totales->total_debe ?? 0)) * 100);
        $haberCents = (int) round(((float) ($totales->total_haber ?? 0)) * 100);

        if ($debeCents !== $haberCents) {
            $diferencia = abs($debeCents - $haberCents) / 100;
            throw new \Exception(
                'Error Crítico de Partida Doble: Asiento de cierre descuadrado por C$ ' .
                number_format($diferencia, 2, '.', '') . '.'
            );
        }
    }
}
