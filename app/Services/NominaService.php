<?php

namespace App\Services;

use App\Models\AdelantoCuota;
use App\Models\Asistencia;
use App\Models\Ausencia;
use App\Models\ColillaNomina;
use App\Models\ComisionGenerada;
use App\Models\CorridaNomina;
use App\Models\DetalleColilla;
use App\Models\Empleado;
use App\Models\EstructuraSalarial;
use App\Models\Propina;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MOTOR DE NÓMINA CORPORATIVO (HRMS, octubre 2026).
 *
 * Liquidación por colilla (Salary Slip) basada en una estructura salarial
 * dinámica (Salary Structure). Cada línea de la estructura declara una
 * "calculadora" que este motor resuelve contra datos reales del período:
 *
 *   ingresos:  salario_base | comisiones (congeladas) | propinas (custodia)
 *              | horas_extras (asistencia) | fijo
 *   deducciones: inss | ir | sin_goce (ausencias) | tardanzas (asistencia)
 *              | adelantos (cuotas CxC amortizables) | fijo
 *
 * INVARIANTES FINANCIERAS:
 *  - Las comisiones se consumen de ComisionGenerada (montos congelados), nunca
 *    se recalculan desde las ventas históricas.
 *  - La amortización de adelantos NUNCA deja la colilla en negativo: se amortiza
 *    solo hasta agotar el disponible (ingresos - otras deducciones), cuota por
 *    cuota, dejando el resto como pendiente/parcial.
 *  - Todo el procesamiento es atómico: colillas, cierre de comisiones/propinas,
 *    amortización de cuotas y asientos contables se confirman o se revierten juntos.
 *  - La anulación no borra nada: genera asientos espejo REV-* y reabre comisiones,
 *    propinas y cuotas a su estado original.
 */
class NominaService
{
    public function __construct(protected ContabilidadService $contabilidad) {}

    // =====================================================================
    // API PÚBLICA
    // =====================================================================

    /**
     * Previsualiza la liquidación sin tocar la base de datos (paso 2 del wizard).
     * Devuelve colillas "virtuales" (instancias no persistidas) para la pantalla
     * de previsualización.
     */
    public function previsualizar(array $datos): array
    {
        return $this->liquidar($datos, bloquear: false, persistir: false);
    }

    /**
     * Procesa y contabiliza la corrida completa de forma atómica (paso 3 del wizard).
     */
    public function procesar(array $datos): CorridaNomina
    {
        DB::beginTransaction();
        try {
            $resultado = $this->liquidar($datos, bloquear: true, persistir: true);
            DB::commit();

            return $resultado['corrida'];
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Error al procesar corrida de nómina', [
                'datos' => collect($datos)->except(['_token'])->all(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Anula una corrida contabilizada: asientos espejo REV-* por colilla,
     * reapertura de comisiones/propinas y reversión de amortizaciones.
     * Nunca borra registros (mandamiento de reversión financiera).
     */
    public function anular(CorridaNomina $corrida, string $motivo): void
    {
        if ($corrida->estado !== 'contabilizada') {
            throw new \Exception('Solo se pueden anular corridas contabilizadas.');
        }

        DB::beginTransaction();
        try {
            foreach ($corrida->colillas()->where('estado', 'pagada')->get() as $colilla) {
                // 1. Reabrir comisiones congeladas
                ComisionGenerada::where('salary_slip_id', $colilla->id)->update([
                    'estado' => 'pendiente',
                    'periodo_nomina_id' => null,
                    'salary_slip_id' => null,
                ]);

                // 2. Reabrir propinas (vuelven a custodia pendiente)
                Propina::where('salary_slip_id', $colilla->id)->update([
                    'estado' => 'pendiente',
                    'salary_slip_id' => null,
                ]);

                // 3. Revertir amortización de cuotas de adelantos
                $lineasAdelantos = DetalleColilla::where('salary_slip_id', $colilla->id)
                    ->where('fuente', 'adelantos')
                    ->whereNotNull('referencia_id')
                    ->get();

                foreach ($lineasAdelantos as $linea) {
                    $cuota = AdelantoCuota::lockForUpdate()->find($linea->referencia_id);
                    if (! $cuota) {
                        continue;
                    }
                    $cuota->monto_pagado = max(0, round((float) $cuota->monto_pagado - (float) $linea->monto, 2));
                    $cuota->estado = $cuota->monto_pagado >= $cuota->monto
                        ? 'pagada'
                        : ($cuota->monto_pagado > 0 ? 'parcial' : 'pendiente');
                    if ($cuota->estado !== 'pagada') {
                        $cuota->fecha_pago = null;
                    }
                    $cuota->save();
                }

                // 4. Asiento espejo REV-*
                if ($colilla->asiento_contable_id) {
                    $this->contabilidad->reversarColillaNomina($colilla, $motivo);
                }

                $colilla->update(['estado' => 'anulada']);
            }

            $corrida->update([
                'estado' => 'anulada',
                'motivo_anulacion' => $motivo,
            ]);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Error al anular corrida de nómina', [
                'corrida_id' => $corrida->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    // =====================================================================
    // NÚCLEO DE LIQUIDACIÓN
    // =====================================================================

    /**
     * Calcula (y opcionalmente persiste/contabiliza) la corrida.
     *
     * @param  array  $datos  periodo_inicio, periodo_fin, metodo_pago, empleado_ids[], estructura_id?
     */
    protected function liquidar(array $datos, bool $bloquear, bool $persistir): array
    {
        $inicio = Carbon::parse($datos['periodo_inicio'])->startOfDay();
        $fin = Carbon::parse($datos['periodo_fin'])->endOfDay();
        $metodoPago = $datos['metodo_pago'] ?? 'banco';
        $estructuraId = $datos['estructura_id'] ?? null;

        if ($fin->lt($inicio)) {
            throw new \Exception('El rango del período de nómina es inválido.');
        }

        $empleados = Empleado::with('usuario')
            ->whereIn('id', $datos['empleado_ids'])
            ->orderBy('codigo_empleado')
            ->get();

        if ($empleados->isEmpty()) {
            throw new \Exception('No hay colaboradores seleccionados para la nómina.');
        }

        $colillas = [];
        $totales = ['ingresos' => 0.0, 'deducciones' => 0.0, 'neto' => 0.0];

        foreach ($empleados as $empleado) {
            $data = $this->calcularColilla($empleado, $inicio, $fin, $estructuraId, $bloquear);
            $colillas[] = $data;

            $totales['ingresos'] += $data['total_ingresos'];
            $totales['deducciones'] += $data['total_deducciones'];
            $totales['neto'] += $data['neto_pagar'];
        }

        $totales = array_map(fn ($v) => round($v, 2), $totales);

        if (! $persistir) {
            return ['corrida' => null, 'colillas' => $colillas, 'totales' => $totales,
                'periodo_inicio' => $inicio->toDateString(), 'periodo_fin' => $fin->toDateString(),
                'metodo_pago' => $metodoPago];
        }

        // ------------------------------------------------------------------
        // PERSISTENCIA ATÓMICA + CONTABILIZACIÓN
        // ------------------------------------------------------------------
        $corrida = CorridaNomina::create([
            'periodo_inicio' => $inicio->toDateString(),
            'periodo_fin' => $fin->toDateString(),
            'metodo_pago' => $metodoPago,
            'estado' => 'borrador',
            'total_ingresos' => $totales['ingresos'],
            'total_deducciones' => $totales['deducciones'],
            'total_neto' => $totales['neto'],
            'creado_por' => auth()->id(),
        ]);

        foreach ($colillas as $data) {
            $colilla = ColillaNomina::create([
                'payroll_run_id' => $corrida->id,
                'employee_id' => $data['empleado']->id,
                'user_id' => $data['empleado']->user_id,
                'periodo_inicio' => $inicio->toDateString(),
                'periodo_fin' => $fin->toDateString(),
                'salario_base' => $data['salario_base'],
                'total_ingresos' => $data['total_ingresos'],
                'total_deducciones' => $data['total_deducciones'],
                'neto_pagar' => $data['neto_pagar'],
                'estado' => 'borrador',
                'fecha_pago' => now()->toDateString(),
            ]);

            foreach ($data['lineas'] as $linea) {
                DetalleColilla::create([
                    'salary_slip_id' => $colilla->id,
                    'tipo' => $linea['tipo'],
                    'codigo' => $linea['codigo'],
                    'concepto' => $linea['concepto'],
                    'fuente' => $linea['fuente'],
                    'monto' => $linea['monto'],
                    'orden' => $linea['orden'],
                    'referencia_id' => $linea['referencia_id'] ?? null,
                ]);
            }

            // 1. Cerrar comisiones congeladas (quedan vinculadas a la colilla)
            if ($data['comisiones_ids'] !== []) {
                ComisionGenerada::whereIn('id', $data['comisiones_ids'])->update([
                    'estado' => 'pagada',
                    'periodo_nomina_id' => $corrida->id,
                    'salary_slip_id' => $colilla->id,
                ]);
            }

            // 2. Cerrar propinas en custodia
            if ($data['propinas_ids'] !== []) {
                Propina::whereIn('id', $data['propinas_ids'])->update([
                    'estado' => 'pagada',
                    'salary_slip_id' => $colilla->id,
                ]);
            }

            // 3. Amortizar cuotas de adelantos
            foreach ($data['amortizaciones'] as $cuotaId => $monto) {
                $cuota = AdelantoCuota::lockForUpdate()->find($cuotaId);
                if (! $cuota) {
                    continue;
                }
                $cuota->monto_pagado = round((float) $cuota->monto_pagado + $monto, 2);
                $cuota->estado = $cuota->monto_pagado >= $cuota->monto ? 'pagada' : 'parcial';
                $cuota->fecha_pago = now()->toDateString();
                $cuota->save();
            }

            // 4. Contabilizar la colilla (valida cuadre exacto; falla => rollback total)
            $asientoId = $this->contabilidad->contabilizarColillaNomina($colilla, $metodoPago);

            $colilla->update([
                'estado' => 'pagada',
                'asiento_contable_id' => $asientoId,
            ]);
        }

        $corrida->update(['estado' => 'contabilizada']);

        return [
            'corrida' => $corrida,
            'colillas' => $colillas,
            'totales' => $totales,
            'periodo_inicio' => $inicio->toDateString(),
            'periodo_fin' => $fin->toDateString(),
            'metodo_pago' => $metodoPago,
        ];
    }

    /**
     * Calcula una colilla individual contra los datos reales del período.
     *
     * @return array{empleado: Empleado, usuario: ?Usuario, estructura: EstructuraSalarial, salario_base: float,
     *               lineas: array[], total_ingresos: float, total_deducciones: float, neto_pagar: float,
     *               comisiones_ids: int[], propinas_ids: int[], amortizaciones: array<int,float>, resumen: array}
     */
    protected function calcularColilla(Empleado $empleado, Carbon $inicio, Carbon $fin, ?int $estructuraId, bool $bloquear): array
    {
        $estructura = $estructuraId
            ? EstructuraSalarial::with('detalles')->find($estructuraId)
            : $empleado->estructuraActual();

        if (! $estructura) {
            throw new \Exception("El colaborador {$empleado->codigo_empleado} no tiene estructura salarial asignada.");
        }

        $usuario = $empleado->usuario;
        $usuarioId = $usuario?->id;

        $salarioBase = (float) $empleado->salario_base;
        if ($salarioBase == 0.0 && $usuario) {
            $salarioBase = (float) $usuario->salario_fijo; // retrocompatibilidad
        }
        $salarioBase = round($salarioBase, 2);

        // ------------------------------------------------------------------
        // Fuente: ASISTENCIA (horas extra, tardanzas)
        // ------------------------------------------------------------------
        $asistenciasQuery = Asistencia::where('user_id', $usuarioId)
            ->whereBetween('date', [$inicio->toDateString(), $fin->toDateString()]);
        $asistencias = $bloquear ? $asistenciasQuery->lockForUpdate()->get() : $asistenciasQuery->get();

        $horasExtras = round((float) $asistencias->sum('horas_extras'), 2);
        $incidenciasTardanza = $asistencias->where('minutos_tardanza', '>', 0)->count();

        // ------------------------------------------------------------------
        // Fuente: AUSENCIAS aprobadas que se solapan con el período
        // ------------------------------------------------------------------
        $ausencias = Ausencia::with('tipo')
            ->where('employee_id', $empleado->id)
            ->where('estado', 'aprobada')
            ->whereDate('fecha_inicio', '<=', $fin->toDateString())
            ->whereDate('fecha_fin', '>=', $inicio->toDateString())
            ->get();

        $diasPeriodo = (int) abs($inicio->copy()->startOfDay()->diffInDays($fin->copy()->startOfDay())) + 1;
        $diasSinGoce = 0;
        $diasConGoce = 0;

        foreach ($ausencias as $ausencia) {
            // Solape en días completos (Carbon 3: diffInDays es firmado y fraccional)
            $ausenciaIni = Carbon::parse($ausencia->fecha_inicio)->startOfDay();
            $ausenciaFin = Carbon::parse($ausencia->fecha_fin)->startOfDay();
            $periodoIni = $inicio->copy()->startOfDay();
            $periodoFin = $fin->copy()->startOfDay();

            $solapeIni = $ausenciaIni->greaterThan($periodoIni) ? $ausenciaIni : $periodoIni;
            $solapeFin = $ausenciaFin->lessThan($periodoFin) ? $ausenciaFin : $periodoFin;

            if ($solapeFin->lessThan($solapeIni)) {
                continue;
            }

            $diasSolape = (int) abs($solapeFin->diffInDays($solapeIni)) + 1;

            if ($ausencia->tipo && $ausencia->tipo->pagado) {
                $diasConGoce += $diasSolape;
            } else {
                $diasSinGoce += $diasSolape;
            }
        }

        // ------------------------------------------------------------------
        // Recorrido de la ESTRUCTURA SALARIAL (una sola pasada, por orden)
        // ------------------------------------------------------------------
        $lineas = [];
        $amortizaciones = [];
        $comisionesIds = [];
        $propinasIds = [];
        $baseGravada = 0.0;   // Base para INSS/IR (salario + extras + comisiones; NUNCA propinas)

        $agregarIngresoGravado = function (string $codigo, string $concepto, string $fuente, float $monto, int $orden) use (&$lineas, &$baseGravada) {
            $monto = round($monto, 2);
            if ($monto > 0) {
                $lineas[] = ['tipo' => 'ingreso', 'codigo' => $codigo, 'concepto' => $concepto, 'fuente' => $fuente, 'monto' => $monto, 'orden' => $orden];
                $baseGravada = round($baseGravada + $monto, 2);
            }
        };

        $tarifaHora = $salarioBase / (config('salon.nomina.dias_nomina_mes', 30) * config('salon.nomina.jornada_horas_diarias', 8));
        $tarifaHora = round($tarifaHora, 6);

        $deduccionesAcumuladas = 0.0;

        foreach ($estructura->detalles->where('activo', true)->sortBy('orden') as $detalle) {
            switch ($detalle->calculadora) {
                case 'salario_base':
                    $agregarIngresoGravado('SAL_BASE', 'Salario base del período', 'estructura', $salarioBase, (int) $detalle->orden);
                    break;

                case 'comisiones':
                    // COMISIONES CONGELADAS: se consumen de ComisionGenerada pendiente
                    if ($usuarioId) {
                        $query = ComisionGenerada::where('empleado_id', $usuarioId)
                            ->whereBetween('fecha', [$inicio->copy()->startOfDay(), $fin->copy()->endOfDay()])
                            ->where('estado', 'pendiente');
                        $comisiones = $bloquear ? $query->lockForUpdate()->get() : $query->get();
                        $comisionesIds = $comisiones->pluck('id')->all();
                        $agregarIngresoGravado('COM_VENT', 'Comisiones de ventas (congeladas)', 'comisiones', (float) $comisiones->sum('monto_comision'), (int) $detalle->orden);
                    }
                    break;

                case 'propinas':
                    // PROPINAS EN CUSTODIA del salón (pasivo 2.1.6 registrado al recibirlas)
                    $query = Propina::where('employee_id', $empleado->id)
                        ->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
                        ->where('estado', 'pendiente');
                    $propinas = $bloquear ? $query->lockForUpdate()->get() : $query->get();
                    $propinasIds = $propinas->pluck('id')->all();
                    $montoPropinas = round((float) $propinas->sum('monto'), 2);
                    if ($montoPropinas > 0) {
                        $lineas[] = ['tipo' => 'ingreso', 'codigo' => 'PROPINAS', 'concepto' => 'Propinas acumuladas en custodia', 'fuente' => 'propinas', 'monto' => $montoPropinas, 'orden' => (int) $detalle->orden];
                    }
                    break;

                case 'horas_extras':
                    $montoExtras = round($tarifaHora * $horasExtras * (float) $detalle->valor, 2);
                    $agregarIngresoGravado('HORAS_EXT', "Horas extras ({$horasExtras} h x ".rtrim(rtrim(number_format((float) $detalle->valor, 2, '.', ''), '0'), '.').')', 'asistencia', $montoExtras, (int) $detalle->orden);
                    break;

                case 'inss':
                    $tasa = (float) config('salon.nomina.inss_empleado', 0.07);
                    $inss = round($baseGravada * $tasa, 2);
                    if ($inss > 0) {
                        $lineas[] = ['tipo' => 'deduccion', 'codigo' => 'INSS', 'concepto' => 'INSS laboral ('.rtrim(rtrim(number_format($tasa * 100, 2, '.', ''), '0'), '.').'%)', 'fuente' => 'ley', 'monto' => $inss, 'orden' => (int) $detalle->orden];
                        $deduccionesAcumuladas = round($deduccionesAcumuladas + $inss, 2);
                    }
                    break;

                case 'ir':
                    $exento = (float) config('salon.nomina.ir_exento', 100000);
                    $tasaIR = (float) config('salon.nomina.ir_tasa', 0.15);
                    $impuesto = round(max(0, $baseGravada - $exento) * $tasaIR, 2);
                    if ($impuesto > 0) {
                        $lineas[] = ['tipo' => 'deduccion', 'codigo' => 'IR', 'concepto' => 'Impuesto sobre la renta', 'fuente' => 'ley', 'monto' => $impuesto, 'orden' => (int) $detalle->orden];
                        $deduccionesAcumuladas = round($deduccionesAcumuladas + $impuesto, 2);
                    }
                    break;

                case 'sin_goce':
                    $montoSinGoce = round($salarioBase / max(1, $diasPeriodo) * $diasSinGoce, 2);
                    if ($montoSinGoce > 0) {
                        $lineas[] = ['tipo' => 'deduccion', 'codigo' => 'SIN_GOCE', 'concepto' => "Ausencias sin goce ({$diasSinGoce} días)", 'fuente' => 'ausencias', 'monto' => $montoSinGoce, 'orden' => (int) $detalle->orden];
                        $deduccionesAcumuladas = round($deduccionesAcumuladas + $montoSinGoce, 2);
                    }
                    break;

                case 'tardanzas':
                    $montoTardanza = round((float) $detalle->valor * $incidenciasTardanza, 2);
                    if ($montoTardanza > 0) {
                        $lineas[] = ['tipo' => 'deduccion', 'codigo' => 'TARDANZAS', 'concepto' => "Llegadas tardías ({$incidenciasTardanza} incidencias)", 'fuente' => 'asistencia', 'monto' => $montoTardanza, 'orden' => (int) $detalle->orden];
                        $deduccionesAcumuladas = round($deduccionesAcumuladas + $montoTardanza, 2);
                    }
                    break;

                case 'adelantos':
                    // Amortización con CAP: nunca deja la colilla en negativo
                    if ($usuarioId) {
                        $cuotasQuery = AdelantoCuota::whereHas('adelanto', function ($q) use ($usuarioId) {
                            $q->where('user_id', $usuarioId);
                        })
                            ->whereBetween('fecha_vencimiento', [$inicio->toDateString(), $fin->toDateString()])
                            ->whereIn('estado', ['pendiente', 'parcial'])
                            ->orderBy('fecha_vencimiento')
                            ->orderBy('id');
                        $cuotas = $bloquear ? $cuotasQuery->lockForUpdate()->get() : $cuotasQuery->get();

                        $totalIngresos = collect($lineas)->where('tipo', 'ingreso')->sum('monto');
                        $disponible = round($totalIngresos - $deduccionesAcumuladas, 2);

                        foreach ($cuotas as $cuota) {
                            if ($disponible <= 0) {
                                break;
                            }
                            $pendiente = round((float) $cuota->monto - (float) $cuota->monto_pagado, 2);
                            if ($pendiente <= 0) {
                                continue;
                            }
                            $amortizar = round(min($pendiente, $disponible), 2);
                            if ($amortizar <= 0) {
                                continue;
                            }
                            $amortizaciones[$cuota->id] = $amortizar;
                            $disponible = round($disponible - $amortizar, 2);
                            $lineas[] = [
                                'tipo' => 'deduccion',
                                'codigo' => 'ADELANTOS',
                                'concepto' => "Amortización cuota {$cuota->numero_cuota} de adelanto #{$cuota->adelanto_id}",
                                'fuente' => 'adelantos',
                                'monto' => $amortizar,
                                'orden' => (int) $detalle->orden,
                                'referencia_id' => $cuota->id,
                            ];
                            $deduccionesAcumuladas = round($deduccionesAcumuladas + $amortizar, 2);
                        }
                    }
                    break;

                case 'fijo':
                    $montoFijo = round((float) $detalle->valor, 2);
                    if ($montoFijo != 0.0) {
                        $esIngreso = $detalle->tipo === 'ingreso';
                        $lineas[] = ['tipo' => $detalle->tipo, 'codigo' => $detalle->codigo, 'concepto' => $detalle->nombre, 'fuente' => 'manual', 'monto' => $montoFijo, 'orden' => (int) $detalle->orden];
                        if ($esIngreso) {
                            $baseGravada = round($baseGravada + $montoFijo, 2);
                        } else {
                            $deduccionesAcumuladas = round($deduccionesAcumuladas + $montoFijo, 2);
                        }
                    }
                    break;

                case 'manual':
                default:
                    break;
            }
        }

        // ------------------------------------------------------------------
        // TOTALES E INVARIANTES
        // ------------------------------------------------------------------
        $totalIngresos = round(collect($lineas)->where('tipo', 'ingreso')->sum('monto'), 2);
        $totalDeducciones = round(collect($lineas)->where('tipo', 'deduccion')->sum('monto'), 2);

        if ($totalDeducciones > $totalIngresos) {
            throw new \Exception(
                'Las deducciones del período (C$ '.number_format($totalDeducciones, 2).') exceden los ingresos '.
                '(C$ '.number_format($totalIngresos, 2).") para {$empleado->codigo_empleado} - {$empleado->nombre}. ".
                'Revise adelantos, tardanzas o la estructura salarial.'
            );
        }

        $netoPagar = round($totalIngresos - $totalDeducciones, 2);

        return [
            'empleado' => $empleado,
            'usuario' => $usuario,
            'estructura' => $estructura,
            'salario_base' => $salarioBase,
            'lineas' => $lineas,
            'total_ingresos' => $totalIngresos,
            'total_deducciones' => $totalDeducciones,
            'neto_pagar' => $netoPagar,
            'comisiones_ids' => $comisionesIds,
            'propinas_ids' => $propinasIds,
            'amortizaciones' => $amortizaciones,
            'resumen' => [
                'dias_periodo' => $diasPeriodo,
                'dias_sin_goce' => $diasSinGoce,
                'dias_con_goce' => $diasConGoce,
                'horas_extras' => $horasExtras,
                'tardanzas' => $incidenciasTardanza,
                'comisiones_pendientes' => count($comisionesIds),
                'propinas_pendientes' => count($propinasIds),
            ],
        ];
    }
}
