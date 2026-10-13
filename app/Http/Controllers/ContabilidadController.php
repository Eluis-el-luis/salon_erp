<?php

namespace App\Http\Controllers;

use App\Models\AsientoContable;
use App\Models\CentroCosto;
use App\Models\CuentaContable;
use App\Models\DetalleAsiento;
use App\Services\ContabilidadService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContabilidadController extends Controller
{
    public function diario()
    {
        $asientos = AsientoContable::with(['detalles.cuenta'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Solo traemos Gastos (Clase 6) y Costos (Clase 5)
        $cuentasGasto = CuentaContable::where('permite_movimiento', true)
            ->where(function ($query) {
                $query->where('codigo', 'like', '5.%')
                    ->orWhere('codigo', 'like', '6.%');
            })->get();

        return view('accounting.diario', compact('asientos', 'cuentasGasto'));
    }

    public function storeGasto(Request $request)
    {
        $request->validate([
            'descripcion' => 'required|string|max:255',
            'monto' => 'required|numeric|min:1',
            'cuenta_id' => 'required|exists:cuentas_contables,id',
            'metodo_pago' => 'required|in:efectivo,banco,bac,lafise',
        ]);

        DB::beginTransaction(); // INICIA LA PROTECCIÓN

        try {
            $contabilidad = new ContabilidadService;
            $contabilidad->contabilizarGasto(
                $request->descripcion,
                $request->monto,
                $request->cuenta_id,
                $request->metodo_pago
            );

            DB::commit(); // SI EL CANDADO NO DETONA, GUARDAMOS

            return back()->with('success', 'Gasto registrado y contabilizado en el Libro Diario.');

        } catch (\Exception $e) {
            DB::rollBack(); // REVERTIMOS TODO SI HAY DESCUADRE
            Log::error('Error contable al registrar gasto', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return back()->withErrors(['error' => 'Error contable al registrar el gasto. Contacte al administrador.']);
        }
    }

    /**
     * Libro Mayor con Drill-down y Centros de Costo
     */
    public function mayor(Request $request)
    {
        $cuentaId = $request->query('cuenta_id');
        $centroCostoId = $request->query('centro_costo_id');
        $fechaDesde = $request->query('fecha_desde');
        $fechaHasta = $request->query('fecha_hasta');
        $buscar = $request->query('buscar');

        // Cuentas disponibles para el filtro (las que permiten movimiento y tienen movimientos)
        $cuentasDisponibles = CuentaContable::where('permite_movimiento', true)
            ->whereHas('detalles')
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'nombre']);

        $centrosCosto = CentroCosto::where('activo', true)->orderBy('nombre')->get();

        $cuenta = null;
        $movimientos = collect();
        $totales = ['debe' => 0, 'haber' => 0, 'saldo' => 0];
        $agrupadosPorCentro = collect();
        $filtros = [
            'cuenta_id' => $cuentaId,
            'centro_costo_id' => $centroCostoId,
            'fecha_desde' => $fechaDesde,
            'fecha_hasta' => $fechaHasta,
            'buscar' => $buscar,
        ];

        if ($cuentaId) {
            $cuenta = CuentaContable::with(['detalles.centroCosto', 'detalles.asiento'])->findOrFail($cuentaId);

            // Construir query base de detalles
            $query = DetalleAsiento::where('cuenta_id', $cuenta->id)
                ->with(['asiento', 'centroCosto'])
                ->whereHas('asiento', function ($q) use ($fechaDesde, $fechaHasta, $buscar) {
                    if ($fechaDesde) {
                        $q->whereDate('fecha', '>=', $fechaDesde);
                    }
                    if ($fechaHasta) {
                        $q->whereDate('fecha', '<=', $fechaHasta);
                    }
                    if ($buscar) {
                        $q->where('concepto', 'like', "%{$buscar}%");
                    }
                });

            // Filtrar por centro de costo si se especifica
            if ($centroCostoId) {
                $query->where('centro_costo_id', $centroCostoId);
            }

            $detalles = $query->get()
                ->sortBy(function ($d) {
                    return $d->asiento->fecha.str_pad($d->asiento->id, 10, '0', STR_PAD_LEFT);
                })
                ->values();

            // Mapear a una estructura uniforme que la vista puede consumir
            $movimientos = $detalles->map(function ($d) {
                return [
                    'asiento_id' => $d->asiento_id,
                    'fecha' => $d->asiento->fecha,
                    'asiento' => $d->asiento->numero_asiento,
                    'concepto' => $d->asiento->concepto,
                    'centro_costo' => $d->centroCosto ? $d->centroCosto->nombre : '—',
                    'debe' => (float) $d->debe,
                    'haber' => (float) $d->haber,
                ];
            });

            $totalDebe = $movimientos->sum('debe');
            $totalHaber = $movimientos->sum('haber');

            $totales = [
                'debe' => $totalDebe,
                'haber' => $totalHaber,
                'saldo' => $totalDebe - $totalHaber,
            ];

            // Agrupar por centro de costo si no hay filtro específico
            if (! $centroCostoId && $movimientos->isNotEmpty()) {
                $agrupadosPorCentro = $detalles
                    ->groupBy('centro_costo_id')
                    ->map(function ($grupo) {
                        // Reconstruir desde el grupo para preservar el orden original
                        $movs = $grupo->map(function ($d) {
                            return [
                                'asiento_id' => $d->asiento_id,
                                'fecha' => $d->asiento->fecha,
                                'asiento' => $d->asiento->numero_asiento,
                                'concepto' => $d->asiento->concepto,
                                'centro_costo' => $d->centroCosto ? $d->centroCosto->nombre : '—',
                                'debe' => (float) $d->debe,
                                'haber' => (float) $d->haber,
                            ];
                        })->values();

                        $debe = $movs->sum('debe');
                        $haber = $movs->sum('haber');

                        return [
                            'centro_costo' => $grupo->first()->centroCosto,
                            'detalles' => $movs,
                            'totales' => [
                                'debe' => $debe,
                                'haber' => $haber,
                                'saldo' => $debe - $haber,
                            ],
                        ];
                    })
                    ->values();
            }
        }

        return view('accounting.mayor', compact(
            'cuentasDisponibles', 'centrosCosto', 'cuenta', 'movimientos', 'agrupadosPorCentro',
            'totales', 'cuentaId', 'centroCostoId', 'fechaDesde', 'fechaHasta', 'buscar', 'filtros'
        ));
    }

    /**
     * ESTADO DE RESULTADOS con filtro mensual.
     *
     * - Por defecto muestra el MES ACTUAL (en curso).
     * - ?mes=YYYY-MM filtra los movimientos por la fecha del asiento.
     * - ?mes=todo conserva la vista histórica acumulada (comportamiento anterior).
     */
    public function resultados(Request $request)
    {
        $mes = $request->query('mes', now()->format('Y-m'));

        if ($mes !== 'todo' && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
            $mes = now()->format('Y-m'); // valor manipulado o inválido: mes actual
        }

        $esHistorico = $mes === 'todo';
        $desde = $esHistorico ? null : Carbon::createFromFormat('Y-m-d', $mes.'-01')->startOfDay();
        $hasta = $esHistorico ? null : $desde->copy()->endOfMonth();

        // Clases 4 (Ingresos), 5 (Costos), 6 (Gastos) y 7 (Otros egresos).
        // La clase 7 SÍ existe en el catálogo y recibe diferenciales cambiarios y ajustes de arqueo.
        $cuentasResultados = CuentaContable::where('permite_movimiento', true)
            ->where(function ($query) {
                $query->where('codigo', 'like', '4.%')
                    ->orWhere('codigo', 'like', '5.%')
                    ->orWhere('codigo', 'like', '6.%')
                    ->orWhere('codigo', 'like', '7.%');
            })
            ->with(['detalles' => function ($q) use ($desde, $hasta) {
                // El asiento de cierre fiscal siempre se excluye para que el
                // reporte conserve los saldos reales del periodo una vez cerrado.
                $q->whereHas('asiento', function ($a) use ($desde, $hasta) {
                    $a->where('modulo_origen', '!=', 'cierre_fiscal');
                    if ($desde && $hasta) {
                        $a->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()]);
                    }
                });
            }])
            ->orderBy('codigo')
            ->get();

        $ingresos = 0;
        $costos = 0;
        $gastosOperativos = 0;
        $otrosEgresos = 0;

        foreach ($cuentasResultados as $cuenta) {
            $debe = $cuenta->detalles->sum('debe');
            $haber = $cuenta->detalles->sum('haber');

            if (str_starts_with($cuenta->codigo, '4')) {
                $saldo = $haber - $debe;
                $ingresos += $saldo;
            } elseif (str_starts_with($cuenta->codigo, '5')) {
                $saldo = $debe - $haber;
                $costos += $saldo;
            } elseif (str_starts_with($cuenta->codigo, '6')) {
                $saldo = $debe - $haber;
                $gastosOperativos += $saldo;
            } else {
                $saldo = $debe - $haber;
                $otrosEgresos += $saldo;
            }

            $cuenta->saldo_final = $saldo;
        }

        $utilidadBruta = $ingresos - $costos;
        $utilidadOperativa = $utilidadBruta - $gastosOperativos;
        $utilidadNeta = $utilidadOperativa - $otrosEgresos;

        // Periodo que cubre el mes mostrado (para el encabezado corporativo).
        $periodo = $esHistorico
            ? (DB::table('periodos_contables')->where('estado', 'abierto')->orderBy('fecha_inicio')->first()
                ?? DB::table('periodos_contables')->orderBy('fecha_fin', 'desc')->first())
            : DB::table('periodos_contables')
                ->whereDate('fecha_inicio', '<=', $desde->toDateString())
                ->whereDate('fecha_fin', '>=', $hasta->toDateString())
                ->orderBy('fecha_inicio')
                ->first();

        $meses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];

        $etiquetaMes = $esHistorico
            ? 'Histórico completo'
            : $meses[(int) $desde->month].' '.$desde->year;

        $mesAnterior = $esHistorico ? null : $desde->copy()->subMonth()->format('Y-m');
        $mesSiguiente = $esHistorico ? null : $desde->copy()->addMonth()->format('Y-m');
        // No se permite navegar hacia meses futuros: aún no hay operaciones.
        $esMesActual = ! $esHistorico && $mes === now()->format('Y-m');

        return view('accounting.resultados', compact(
            'cuentasResultados', 'ingresos', 'costos', 'gastosOperativos', 'otrosEgresos',
            'utilidadBruta', 'utilidadOperativa', 'utilidadNeta', 'periodo',
            'mes', 'etiquetaMes', 'mesAnterior', 'mesSiguiente', 'esMesActual', 'esHistorico',
            'desde', 'hasta'
        ));
    }
}
