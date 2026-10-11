<?php

namespace App\Http\Controllers;

use App\Models\Banco;
use App\Models\ConciliacionBancaria;
use App\Models\CuentaBancaria;
use App\Models\CuentaContable;
use App\Models\Moneda;
use App\Models\MovimientoBancario;
use App\Models\SesionCaja;
use App\Models\Transferencia;
use App\Services\ContabilidadService;
use App\Services\DivisaService;
use App\Services\FondosService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GESTIÓN BANCARIA CORPORATIVA (nivel ERPNext):
 *  - Dashboard multimoneda (NIO/USD) con subcuentas del mayor por cuenta real.
 *  - Libro mayor bancario con saldo corriendo y estado de conciliación.
 *  - Transferencias entre bóvedas (caja <-> banco, banco <-> banco) con
 *    comisión bancaria contabilizada al gasto 6.9.
 *  - Cargos/abonos bancarios directos con asiento inmediato.
 *  - Conciliación bancaria: sólo cierra con diferencia C$ 0.00.
 */
class BancoController extends Controller
{
    protected FondosService $fondos;

    protected DivisaService $divisas;

    public function __construct()
    {
        $this->fondos = new FondosService;
        $this->divisas = new DivisaService;
    }

    // =====================================================================
    // DASHBOARD
    // =====================================================================
    public function index()
    {
        $this->asegurarCatalogoBancario();

        $cuentas = CuentaBancaria::with(['banco', 'moneda'])->orderBy('banco_id')->orderBy('moneda_id')->get();
        $cajaActiva = SesionCaja::where('user_id', auth()->id())->where('estado', 'abierta')->first();

        $idNio = Moneda::idPorCodigo('NIO');
        $idUsd = Moneda::idPorCodigo('USD');

        $kpis = [
            'total_nio' => round((float) $cuentas->where('moneda_id', $idNio)->sum('saldo_actual'), 2),
            'total_usd' => round((float) $cuentas->where('moneda_id', $idUsd)->sum('saldo_actual'), 2),
            'pendientes' => MovimientoBancario::where('estado_conciliacion', 'pendiente')->count(),
            'en_transito' => MovimientoBancario::where('estado_conciliacion', 'en_transito')->count(),
        ];

        $transferencias = Transferencia::with(['moneda'])
            ->orderByDesc('id')->take(8)->get();

        // Todas las cajas abiertas: pueden ser origen/destino de transferencias.
        $sesionesAbiertas = SesionCaja::with('usuario')->where('estado', 'abierta')->orderBy('fecha_apertura')->get();

        // Catálogo para los modales de cargo/abono bancario.
        $cuentasGasto = CuentaContable::where('activa', true)
            ->whereIn('tipo', ['gasto', 'costo'])
            ->where('permite_movimiento', true)
            ->orderBy('codigo')->get();
        $cuentasIngreso = CuentaContable::where('activa', true)
            ->where('tipo', 'ingreso')
            ->where('permite_movimiento', true)
            ->orderBy('codigo')->get();
        $cuentaCargoDefault = CuentaContable::where('codigo', '6.9')->first();
        $cuentaAbonoDefault = CuentaContable::where('codigo', '4.7')->first();

        return view('banks.index', compact(
            'cuentas', 'cajaActiva', 'kpis', 'transferencias', 'sesionesAbiertas',
            'cuentasGasto', 'cuentasIngreso', 'cuentaCargoDefault', 'cuentaAbonoDefault'
        ));
    }

    // =====================================================================
    // LIBRO MAYOR BANCARIO
    // =====================================================================
    public function libro(CuentaBancaria $cuenta_bancaria)
    {
        $cuenta = $cuenta_bancaria;
        $cuenta->load(['banco', 'moneda']);

        $movimientos = $cuenta->movimientos()
            ->with(['asientoContable', 'moneda'])
            ->orderBy('fecha')
            ->orderBy('id')
            ->take(300)
            ->get();

        // Saldo corriendo: se reconstruye desde el saldo inicial histórico
        // (saldo actual menos la suma de todos los movimientos registrados).
        $saldoInicial = round(
            (float) $cuenta->saldo_actual - $movimientos->sum(fn ($m) => $m->signo() * (float) $m->monto),
            2
        );

        $saldoCorriendo = $saldoInicial;
        foreach ($movimientos as $movimiento) {
            $saldoCorriendo = round($saldoCorriendo + $movimiento->signo() * (float) $movimiento->monto, 2);
            $movimiento->saldo_corriendo = $saldoCorriendo;
        }
        $movimientos = $movimientos->reverse()->values(); // más reciente arriba

        return view('banks.libro', compact('cuenta', 'movimientos', 'saldoInicial'));
    }

    // =====================================================================
    // DEPÓSITO DE EFECTIVO (Caja -> Banco), NIO o USD
    // =====================================================================
    public function depositar(Request $request)
    {
        $request->validate([
            'cuenta_destino_id' => 'required|exists:cuentas_bancarias,id',
            'monto' => 'required|numeric|min:0.01',
            'caja_origen_id' => 'required|exists:cash_sessions,id',
            'moneda' => 'required|in:nio,usd',
            'referencia_banco' => 'nullable|string|max:60',
            'fecha' => 'nullable|date',
            'en_transito' => 'nullable|boolean',
        ]);

        DB::beginTransaction();

        try {
            $caja = $this->fondos->resolver(FondosService::SESION, (int) $request->caja_origen_id);
            $cuenta = CuentaBancaria::with('moneda')->findOrFail($request->cuenta_destino_id);
            $monto = round((float) $request->monto, 2);

            $esUsd = $request->moneda === 'usd';
            $moneda = $esUsd ? Moneda::idPorCodigo('USD') : Moneda::idPorCodigo('NIO');

            if ($esUsd && ! $cuenta->esEnDolares()) {
                throw new \Exception('La cuenta bancaria seleccionada es en córdobas: no puede recibir un depósito en dólares.');
            }
            if (! $esUsd && $cuenta->esEnDolares()) {
                throw new \Exception('La cuenta bancaria seleccionada es en dólares: no puede recibir un depósito en córdobas.');
            }

            $tasaValoracion = null;

            if ($esUsd) {
                // Los dólares físicos del turno viven en saldos_moneda: se egresan
                // a valor libro (costo promedio) y validan existencia con candado.
                $saldo = $this->divisas->saldoDivisas('USD', FondosService::SESION, $caja->id);
                if ((float) $saldo['saldo'] < $monto) {
                    throw new \Exception('El turno no tiene suficientes dólares físicos. Disponible: $'.number_format($saldo['saldo'], 2));
                }
                $tasaValoracion = (float) $saldo['costo_promedio'];
                if ($tasaValoracion <= 0) {
                    throw new \Exception('Los dólares del turno no tienen costo promedio registrado; registre su entrada por la Mesa de Cambio.');
                }
                $this->divisas->egresarDivisas('USD', FondosService::SESION, $caja->id, $monto, $tasaValoracion);
            } else {
                $disponible = $this->fondos->efectivoNioDisponible($caja);
                if ($disponible < $monto) {
                    throw new \Exception('Efectivo NIO insuficiente en el turno. Disponible teórico: C$ '.number_format($disponible, 2));
                }
            }

            // 1. Transferencia polimórfica (auditoría del movimiento entre bóvedas)
            $transferencia = Transferencia::create([
                'origen_tipo' => FondosService::SESION,
                'origen_id' => $caja->id,
                'destino_tipo' => FondosService::BANCO,
                'destino_id' => $cuenta->id,
                'monto' => $monto,
                'moneda_id' => $moneda,
                'concepto' => $esUsd ? 'Depósito de dólares en efectivo' : 'Depósito de ventas de caja',
                'referencia_bancaria' => $request->referencia_banco,
                'comision' => 0,
                'fecha' => $request->filled('fecha') ? Carbon::parse($request->fecha) : Carbon::now(),
                'usuario_id' => auth()->id() ?? 1,
            ]);

            // 2. Asiento contable (DEBE banco / HABER caja)
            $contabilidad = new ContabilidadService;
            $asientoId = $contabilidad->contabilizarTransferencia($transferencia, $tasaValoracion);

            // 3. Movimiento en el libro bancario
            MovimientoBancario::create([
                'cuenta_bancaria_id' => $cuenta->id,
                'moneda_id' => $moneda,
                'tipo' => 'deposito',
                'monto' => $monto,
                'fecha' => $transferencia->fecha,
                'concepto' => 'Depósito de caja (Turno #'.$caja->id.')',
                'modulo_origen' => 'transferencias',
                'referencia_id' => $transferencia->id,
                'referencia_banco' => $request->referencia_banco,
                'estado_conciliacion' => $request->boolean('en_transito') ? 'en_transito' : 'pendiente',
                'asiento_contable_id' => $asientoId,
            ]);

            // 4. El saldo del banco vive en la moneda de la cuenta
            $cuenta->saldo_actual = round((float) $cuenta->saldo_actual + $monto, 2);
            $cuenta->save();

            DB::commit();

            return back()->with('success', 'Depósito registrado: '
                .($esUsd ? '$ ' : 'C$ ').number_format($monto, 2)
                .' hacia '.$cuenta->etiqueta().'. El asiento contable fue emitido.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al procesar depósito bancario', [
                'exception' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    // =====================================================================
    // TRANSFERENCIAS ENTRE BÓVEDAS (banco->banco, banco->caja, caja->banco)
    // =====================================================================
    public function transferir(Request $request)
    {
        $request->validate([
            'origen_tipo' => 'required|in:sesion,banco',
            'origen_id' => 'required|integer|min:1',
            'destino_tipo' => 'required|in:sesion,banco',
            'destino_id' => 'required|integer|min:1',
            'monto' => 'required|numeric|min:0.01',
            'comision' => 'nullable|numeric|min:0',
            'concepto' => 'required|string|max:120',
            'referencia_bancaria' => 'nullable|string|max:60',
            'fecha' => 'nullable|date',
        ]);

        DB::beginTransaction();

        try {
            $origen = $this->fondos->resolver($request->origen_tipo === 'sesion' ? FondosService::SESION : FondosService::BANCO, (int) $request->origen_id);
            $destino = $this->fondos->resolver($request->destino_tipo === 'sesion' ? FondosService::SESION : FondosService::BANCO, (int) $request->destino_id);

            $monto = round((float) $request->monto, 2);
            $comision = round((float) ($request->comision ?? 0), 2);

            if (get_class($origen) === get_class($destino) && $origen->id === $destino->id) {
                throw new \Exception('El origen y el destino de la transferencia son la misma bóveda.');
            }

            // La moneda del movimiento la fija la bóveda bancaria (si participa);
            // entre cajas siempre se mueve efectivo NIO.
            if ($origen instanceof CuentaBancaria) {
                $esUsd = $origen->esEnDolares();
            } elseif ($destino instanceof CuentaBancaria) {
                $esUsd = $destino->esEnDolares();
            } else {
                $esUsd = false;
            }

            $bancoOrigen = $origen instanceof CuentaBancaria ? $origen : null;
            $bancoDestino = $destino instanceof CuentaBancaria ? $destino : null;

            if ($bancoOrigen && $bancoDestino && $bancoOrigen->esEnDolares() !== $bancoDestino->esEnDolares()) {
                throw new \Exception('No se puede transferir entre cuentas de distinta moneda: use la Mesa de Cambio para convertir divisas.');
            }

            // --- Validación y egreso del origen ---------------------------------
            $tasaValoracion = null;

            if ($esUsd) {
                if ($bancoOrigen) {
                    if ((float) $bancoOrigen->saldo_actual < $monto + $comision) {
                        throw new \Exception('Saldo insuficiente en la cuenta bancaria origen (incluye comisión).');
                    }
                } else {
                    $saldo = $this->divisas->saldoDivisas('USD', FondosService::SESION, $origen->id);
                    if ((float) $saldo['saldo'] < $monto) {
                        throw new \Exception('El turno no tiene suficientes dólares físicos. Disponible: $'.number_format($saldo['saldo'], 2));
                    }
                    $tasaValoracion = (float) $saldo['costo_promedio'];
                    if ($tasaValoracion <= 0) {
                        throw new \Exception('Los dólares del turno no tienen costo promedio registrado.');
                    }
                    $this->divisas->egresarDivisas('USD', FondosService::SESION, $origen->id, $monto, $tasaValoracion);
                }
            } else {
                if ($bancoOrigen) {
                    if ((float) $bancoOrigen->saldo_actual < $monto + $comision) {
                        throw new \Exception('Saldo insuficiente en la cuenta bancaria origen (incluye comisión).');
                    }
                } else {
                    $disponible = $this->fondos->efectivoNioDisponible($origen);
                    if ($disponible < $monto) {
                        throw new \Exception('Efectivo NIO insuficiente en el turno. Disponible teórico: C$ '.number_format($disponible, 2));
                    }
                }
            }

            // --- Persistencia ----------------------------------------------------
            $transferencia = Transferencia::create([
                'origen_tipo' => get_class($origen),
                'origen_id' => $origen->id,
                'destino_tipo' => get_class($destino),
                'destino_id' => $destino->id,
                'monto' => $monto,
                'moneda_id' => $esUsd ? Moneda::idPorCodigo('USD') : Moneda::idPorCodigo('NIO'),
                'concepto' => $request->concepto,
                'referencia_bancaria' => $request->referencia_bancaria,
                'comision' => $comision,
                'fecha' => $request->filled('fecha') ? Carbon::parse($request->fecha) : Carbon::now(),
                'usuario_id' => auth()->id() ?? 1,
            ]);

            $contabilidad = new ContabilidadService;
            $asientoId = $contabilidad->contabilizarTransferencia($transferencia, $tasaValoracion);

            // Salidas y entradas de saldo + libro bancario de cada lado.
            if ($bancoOrigen) {
                $bancoOrigen->saldo_actual = round((float) $bancoOrigen->saldo_actual - $monto - $comision, 2);
                $bancoOrigen->save();

                MovimientoBancario::create([
                    'cuenta_bancaria_id' => $bancoOrigen->id,
                    'moneda_id' => $transferencia->moneda_id,
                    'tipo' => 'retiro',
                    'monto' => $monto,
                    'fecha' => $transferencia->fecha,
                    'concepto' => 'Transferencia a '.($bancoDestino ? $bancoDestino->etiqueta() : 'Caja (Turno #'.$destino->id.')'),
                    'modulo_origen' => 'transferencias',
                    'referencia_id' => $transferencia->id,
                    'referencia_banco' => $request->referencia_bancaria,
                    'estado_conciliacion' => 'pendiente',
                    'asiento_contable_id' => $asientoId,
                ]);

                if ($comision > 0) {
                    MovimientoBancario::create([
                        'cuenta_bancaria_id' => $bancoOrigen->id,
                        'moneda_id' => $transferencia->moneda_id,
                        'tipo' => 'cargo',
                        'monto' => $comision,
                        'fecha' => $transferencia->fecha,
                        'concepto' => 'Comisión bancaria por transferencia #'.$transferencia->id,
                        'modulo_origen' => 'transferencias',
                        'referencia_id' => $transferencia->id,
                        'estado_conciliacion' => 'pendiente',
                        'asiento_contable_id' => $asientoId,
                    ]);
                }
            }

            if ($bancoDestino) {
                $bancoDestino->saldo_actual = round((float) $bancoDestino->saldo_actual + $monto, 2);
                $bancoDestino->save();

                MovimientoBancario::create([
                    'cuenta_bancaria_id' => $bancoDestino->id,
                    'moneda_id' => $transferencia->moneda_id,
                    'tipo' => 'deposito',
                    'monto' => $monto,
                    'fecha' => $transferencia->fecha,
                    'concepto' => 'Transferencia recibida de '.($bancoOrigen ? $bancoOrigen->etiqueta() : 'Caja (Turno #'.$origen->id.')'),
                    'modulo_origen' => 'transferencias',
                    'referencia_id' => $transferencia->id,
                    'referencia_banco' => $request->referencia_bancaria,
                    'estado_conciliacion' => 'pendiente',
                    'asiento_contable_id' => $asientoId,
                ]);
            }

            DB::commit();

            return back()->with('success', 'Transferencia registrada y contabilizada (asiento DEP-'
                .str_pad($transferencia->id, 5, '0', STR_PAD_LEFT).').');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al procesar transferencia bancaria', [
                'exception' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    // =====================================================================
    // CARGOS Y ABONOS BANCARIOS DIRECTOS
    // =====================================================================
    public function registrarCargo(Request $request)
    {
        return $this->registrarMovimientoDirecto($request, 'cargo');
    }

    public function registrarAbono(Request $request)
    {
        return $this->registrarMovimientoDirecto($request, 'abono');
    }

    protected function registrarMovimientoDirecto(Request $request, string $tipo)
    {
        $esCargo = $tipo === 'cargo';

        $request->validate([
            'cuenta_bancaria_id' => 'required|exists:cuentas_bancarias,id',
            'monto' => 'required|numeric|min:0.01',
            'concepto' => 'required|string|max:150',
            'cuenta_contable_id' => 'required|exists:cuentas_contables,id',
            'fecha' => 'nullable|date',
            'referencia_banco' => 'nullable|string|max:60',
        ]);

        // El cargo se lleva a gasto/costo; el abono, a ingreso. Invariante de dominio.
        $contrapartida = CuentaContable::findOrFail($request->cuenta_contable_id);
        $tiposValidos = $esCargo ? ['gasto', 'costo'] : ['ingreso'];
        if (! in_array($contrapartida->tipo, $tiposValidos, true)) {
            return back()->withErrors([
                'error' => 'La cuenta contable seleccionada no es de tipo '
                    .($esCargo ? 'gasto/costo (para un cargo bancario)' : 'ingreso (para un abono bancario)').'.',
            ])->withInput();
        }

        DB::beginTransaction();

        try {
            $cuenta = CuentaBancaria::with('banco')->findOrFail($request->cuenta_bancaria_id);
            $monto = round((float) $request->monto, 2);

            // 1. El movimiento se crea primero para numerar el asiento CBA-#####
            $movimiento = MovimientoBancario::create([
                'cuenta_bancaria_id' => $cuenta->id,
                'moneda_id' => $cuenta->moneda_id,
                'tipo' => $tipo,
                'monto' => $monto,
                'fecha' => $request->filled('fecha') ? Carbon::parse($request->fecha) : Carbon::now(),
                'concepto' => $request->concepto,
                'modulo_origen' => 'cargos_bancarios',
                'referencia_id' => 0,
                'referencia_banco' => $request->referencia_banco,
                'estado_conciliacion' => 'pendiente',
            ]);

            // 2. Asiento: cargo -> DEBE gasto 6.9 | abono -> HABER ingreso 4.7
            $contabilidad = new ContabilidadService;
            $asientoId = $contabilidad->contabilizarMovimientoBancario($movimiento, (int) $request->cuenta_contable_id);

            $movimiento->asiento_contable_id = $asientoId;
            $movimiento->save();

            // 3. El saldo bancario real se ajusta de inmediato (el banco ya lo hizo)
            $cuenta->saldo_actual = round(
                (float) $cuenta->saldo_actual + ($esCargo ? -$monto : $monto),
                2
            );
            $cuenta->save();

            DB::commit();

            return back()->with('success', ucfirst($tipo).' bancario registrado por '
                .'C$ '.number_format($monto, 2).' en '.$cuenta->etiqueta().'.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al registrar movimiento bancario directo', [
                'tipo' => $tipo,
                'exception' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    // =====================================================================
    // CONCILIACIÓN BANCARIA
    // =====================================================================
    public function conciliacion(CuentaBancaria $cuenta_bancaria)
    {
        $cuenta = $cuenta_bancaria;
        $cuenta->load(['banco', 'moneda']);

        $pendientes = $cuenta->movimientos()
            ->whereIn('estado_conciliacion', ['pendiente', 'en_transito'])
            ->with('asientoContable')
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();

        $saldoInicialConciliado = $this->fondos->saldoInicialConciliado($cuenta);

        $historial = $cuenta->conciliaciones()->orderByDesc('fecha')->orderByDesc('id')->take(10)->get();

        // Cuentas de gasto e ingreso habilitadas para registrar diferencias.
        $cuentasGasto = CuentaContable::where('activa', true)
            ->whereIn('tipo', ['gasto', 'costo'])
            ->where('permite_movimiento', true)
            ->orderBy('codigo')->get();
        $cuentasIngreso = CuentaContable::where('activa', true)
            ->where('tipo', 'ingreso')
            ->where('permite_movimiento', true)
            ->orderBy('codigo')->get();

        return view('banks.conciliacion', compact(
            'cuenta', 'pendientes', 'saldoInicialConciliado', 'historial', 'cuentasGasto', 'cuentasIngreso'
        ));
    }

    public function guardarConciliacion(Request $request, CuentaBancaria $cuenta_bancaria)
    {
        $cuenta = $cuenta_bancaria;
        $request->validate([
            'fecha' => 'required|date|before_or_equal:today',
            'saldo_extracto' => 'required|numeric|min:0',
            'movimientos' => 'nullable|array',
            'movimientos.*' => 'integer|exists:movimientos_bancarios,id',
            'observaciones' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();

        try {
            $fecha = Carbon::parse($request->fecha);
            $saldoExtracto = round((float) $request->saldo_extracto, 2);

            $ids = collect($request->movimientos ?? []);
            $marcados = $cuenta->movimientos()
                ->whereIn('id', $ids)
                ->whereIn('estado_conciliacion', ['pendiente', 'en_transito'])
                ->whereDate('fecha', '<=', $fecha->toDateString())
                ->lockForUpdate()
                ->get();

            // Auditoría: ningún movimiento de otra cuenta puede colarse.
            if ($marcados->count() !== $ids->unique()->count()) {
                throw new \Exception('Hay movimientos seleccionados que no pertenecen a esta cuenta o ya están conciliados.');
            }

            $saldoInicial = $this->fondos->saldoInicialConciliado($cuenta);
            $conciliado = round($marcados->sum(fn ($m) => $m->signo() * (float) $m->monto), 2);
            $teoricoExtracto = round($saldoInicial + $conciliado, 2);
            $diferencia = round($saldoExtracto - $teoricoExtracto, 2);

            if (abs($diferencia) >= 0.01) {
                throw new \Exception(
                    'La conciliación NO cuadra por '.($diferencia > 0 ? 'C$ ' : 'C$ -').number_format(abs($diferencia), 2)
                    .'. Registre el cargo/abono bancario faltante (o deje pendientes los cheques en tránsito) antes de cerrar.'
                );
            }

            $conciliacion = ConciliacionBancaria::create([
                'cuenta_bancaria_id' => $cuenta->id,
                'fecha' => $fecha->toDateString(),
                'saldo_inicial_conciliado' => $saldoInicial,
                'saldo_extracto' => $saldoExtracto,
                'saldo_libros' => round($saldoInicial + $cuenta->movimientos()
                    ->whereDate('fecha', '<=', $fecha->toDateString())
                    ->get()
                    ->sum(fn ($m) => $m->signo() * (float) $m->monto), 2),
                'diferencia' => 0,
                'estado' => 'cerrada',
                'observaciones' => $request->observaciones,
                'usuario_id' => auth()->id() ?? 1,
            ]);

            foreach ($marcados as $movimiento) {
                $movimiento->update([
                    'conciliado' => true,
                    'estado_conciliacion' => 'conciliado',
                    'fecha_conciliacion' => $fecha->toDateString(),
                    'conciliacion_id' => $conciliacion->id,
                ]);
            }

            // Lo que quedó sin marcar y ya venció a la fecha del extracto pasa a
            // "en tránsito": existe en libros pero aún no pasa por el banco.
            $cuenta->movimientos()
                ->where('estado_conciliacion', 'pendiente')
                ->whereDate('fecha', '<=', $fecha->toDateString())
                ->update(['estado_conciliacion' => 'en_transito']);

            DB::commit();

            return redirect()
                ->route('bancos.conciliacion', $cuenta->id)
                ->with('success', 'Conciliación cerrada: el saldo del sistema coincide con el extracto del banco (C$ '.number_format($saldoExtracto, 2).').');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al conciliar cuenta bancaria', [
                'exception' => $e->getMessage(),
                'cuenta' => $cuenta->id,
                'request' => $request->all(),
            ]);

            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }
    }

    // =====================================================================
    // CATÁLOGO BANCARIO BASE (idempotente, ahora multimoneda)
    // =====================================================================
    protected function asegurarCatalogoBancario(): void
    {
        $monedaNio = Moneda::where('codigo', 'NIO')->first();
        $monedaUsd = Moneda::where('codigo', 'USD')->first();
        if (! $monedaNio) {
            return;
        }

        $bancosBase = [
            ['codigo' => 'BAC', 'nombre' => 'BAC Credomatic', 'cuenta_nio' => '1.1.2.2', 'cuenta_usd' => '1.1.2.3'],
            ['codigo' => 'LAFISE', 'nombre' => 'Banco LAFISE Bancentro', 'cuenta_nio' => '1.1.2.1', 'cuenta_usd' => '1.1.2.4'],
        ];

        foreach ($bancosBase as $bancoBase) {
            $banco = Banco::firstOrCreate(
                ['codigo' => $bancoBase['codigo']],
                ['nombre' => $bancoBase['nombre'], 'activo' => true]
            );

            if (! CuentaBancaria::where('banco_id', $banco->id)->where('moneda_id', $monedaNio->id)->exists()) {
                CuentaBancaria::create([
                    'banco_id' => $banco->id,
                    'numero_cuenta' => $banco->codigo.'-00000000',
                    'alias' => 'Cuenta principal '.$banco->codigo.' (NIO)',
                    'tipo_cuenta' => 'Corriente',
                    'moneda_id' => $monedaNio->id,
                    'cuenta_contable_id' => $this->idCuentaContable($bancoBase['cuenta_nio']),
                    'saldo_actual' => 0,
                    'activa' => true,
                ]);
                Log::info('Cuenta bancaria base creada', ['banco' => $banco->codigo, 'moneda' => 'NIO']);
            }

            if ($monedaUsd && ! CuentaBancaria::where('banco_id', $banco->id)->where('moneda_id', $monedaUsd->id)->exists()) {
                CuentaBancaria::create([
                    'banco_id' => $banco->id,
                    'numero_cuenta' => $banco->codigo.'-00000000-USD',
                    'alias' => 'Cuenta principal '.$banco->codigo.' (USD)',
                    'tipo_cuenta' => 'Corriente',
                    'moneda_id' => $monedaUsd->id,
                    'cuenta_contable_id' => $this->idCuentaContable($bancoBase['cuenta_usd']),
                    'saldo_actual' => 0,
                    'activa' => true,
                ]);
                Log::info('Cuenta bancaria base creada', ['banco' => $banco->codigo, 'moneda' => 'USD']);
            }

            // Backfill de vínculo al mayor para cuentas creadas antes de la fase corporativa.
            CuentaBancaria::where('banco_id', $banco->id)
                ->whereNull('cuenta_contable_id')
                ->get()
                ->each(function (CuentaBancaria $cuenta) use ($bancoBase) {
                    $codigo = $cuenta->esEnDolares() ? $bancoBase['cuenta_usd'] : $bancoBase['cuenta_nio'];
                    $cuenta->cuenta_contable_id = $this->idCuentaContable($codigo);
                    $cuenta->save();
                });
        }
    }

    /**
     * Resuelve el ID de una subcuenta contable si existe (sin inventar códigos).
     */
    protected function idCuentaContable(?string $codigo): ?int
    {
        if (! $codigo) {
            return null;
        }

        return CuentaContable::where('codigo', $codigo)->value('id');
    }
}
