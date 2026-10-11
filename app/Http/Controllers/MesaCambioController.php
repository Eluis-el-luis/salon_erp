<?php

namespace App\Http\Controllers;

use App\Models\CuentaBancaria;
use App\Models\DiferencialCambiario;
use App\Models\Moneda;
use App\Models\MovimientoBancario;
use App\Models\OperacionCambio;
use App\Models\SaldoMoneda;
use App\Models\SesionCaja;
use App\Models\TipoCambio;
use App\Services\ContabilidadService;
use App\Services\DivisaService;
use App\Services\FondosService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MESA DE CAMBIO CORPORATIVA:
 *  - Toda operación declara la bóveda de origen (moneda que entrega) y la
 *    bóveda de destino (moneda que recibe): caja de turno, banco NIO o USD.
 *  - Registra la tasa oficial del día (BCN) junto a la tasa negociada y
 *    contabiliza la ganancia/pérdida cambiaria (4.6/7.5) al instante, valorando
 *    la salida de USD a costo promedio ponderado (valor libro).
 */
class MesaCambioController extends Controller
{
    protected DivisaService $divisas;

    protected FondosService $fondos;

    public function __construct()
    {
        $this->divisas = new DivisaService;
        $this->fondos = new FondosService;
    }

    // =====================================================================
    // DASHBOARD DE BÓVEDAS
    // =====================================================================
    public function index()
    {
        $ubicaciones = $this->fondos->listarUbicaciones();

        $operaciones = OperacionCambio::with(['asientoContable', 'usuario', 'origenPago', 'destinoPago'])
            ->orderBy('fecha', 'desc')->orderBy('id', 'desc')
            ->take(50)->get();

        $tasaOficial = TipoCambio::oficialUsdDelDia();

        $resumen = DiferencialCambiario::whereMonth('fecha', now()->month)
            ->whereYear('fecha', now()->year)
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'ganancia' THEN diferencia_calculada ELSE 0 END), 0) as ganancia,
                         COALESCE(SUM(CASE WHEN tipo = 'perdida' THEN diferencia_calculada ELSE 0 END), 0) as perdida")
            ->first();

        $cajaActiva = auth()->check()
            ? SesionCaja::where('user_id', auth()->id())->where('estado', 'abierta')->first()
            : null;

        // Consolida la posición de la divisa en todas las bóvedas.
        $totalUsdBovedas = round(array_sum(array_column($ubicaciones, 'usd')), 2);
        $valorLibroUsd = round(array_sum(array_map(
            fn ($u) => $u['usd'] * $u['costo_promedio_usd'],
            $ubicaciones
        )), 2);

        return view('exchange.index', compact(
            'ubicaciones', 'operaciones', 'tasaOficial', 'resumen', 'cajaActiva',
            'totalUsdBovedas', 'valorLibroUsd'
        ));
    }

    // =====================================================================
    // OPERACIÓN DE CAMBIO CON TRAZABILIDAD DE BÓVEDAS
    // =====================================================================
    public function store(Request $request)
    {
        $request->validate([
            'tipo' => 'required|in:compra,venta',
            'monto_usd' => 'required|numeric|min:0.01',
            'tasa_aplicada' => 'required|numeric|min:1',
            'tasa_oficial' => 'nullable|numeric|min:1',
            'origen_tipo' => 'required|in:sesion,banco',
            'origen_id' => 'required|integer|min:1',
            'destino_tipo' => 'required|in:sesion,banco',
            'destino_id' => 'required|integer|min:1',
            'referencia' => 'nullable|string|max:100',
        ]);

        DB::beginTransaction();

        try {
            $tipo = $request->tipo;
            $montoUsd = round((float) $request->monto_usd, 2);
            $tasa = (float) $request->tasa_aplicada;
            $tasaOficial = $request->filled('tasa_oficial') ? (float) $request->tasa_oficial : TipoCambio::oficialUsdDelDia()?->tasa_venta;

            $idNio = Moneda::idPorCodigo('NIO');
            $idUsd = Moneda::idPorCodigo('USD');

            // Bóveda que ENTREGA la moneda que sale (compra: NIO | venta: USD)
            $entrega = $this->fondos->resolver(
                $request->origen_tipo === 'sesion' ? FondosService::SESION : FondosService::BANCO,
                (int) $request->origen_id
            );
            // Bóveda que RECIBE la moneda que entra (compra: USD | venta: NIO)
            $recepcion = $this->fondos->resolver(
                $request->destino_tipo === 'sesion' ? FondosService::SESION : FondosService::BANCO,
                (int) $request->destino_id
            );

            $montoNio = round($montoUsd * $tasa, 2);
            $diferencialCosto = 0;
            $diferencialOficial = is_null($tasaOficial) ? null : round($montoUsd * ($tasa - $tasaOficial), 2);

            if ($tipo === 'compra') {
                // ================= EL SALÓN COMPRA USD =================
                // Salen córdobas de la bóveda de entrega; entran dólares a la de recepción.
                $this->validarEgresoNio($entrega, $montoNio);

                // Entrada de USD: recalcula el costo promedio ponderado (6 decimales).
                $this->ingresarUsdEnBoveda($recepcion, $montoUsd, $tasa);

                $monedaOrigen = $idUsd;
                $monedaDestino = $idNio;
                $montoOrigen = $montoUsd;
                $montoDestino = $montoNio;
            } else {
                // ================= EL SALÓN VENDE USD =================
                // Salen dólares de la bóveda de entrega (a valor libro); entran córdobas.
                $saldoUsdOrigen = $this->divisas->saldoDivisas('USD', get_class($entrega), $entrega->id);

                if ($entrega instanceof CuentaBancaria) {
                    throw new \Exception('Para vender dólares que están en un banco, primero transfiéralos a una caja de turno (la valoración a costo promedio se hace en la bóveda de efectivo).');
                }

                if ((float) $saldoUsdOrigen['saldo'] < $montoUsd) {
                    throw new \Exception('Esa bóveda no tiene suficientes dólares. Disponible: $'.number_format((float) $saldoUsdOrigen['saldo'], 2));
                }

                $tasaCostoUsd = (float) $saldoUsdOrigen['costo_promedio'];
                if ($tasaCostoUsd <= 0) {
                    throw new \Exception('Los dólares de esa bóveda no tienen costo promedio registrado.');
                }

                // Egreso con candado: valida saldo y calcula el diferencial de libro.
                $this->egresarUsdDeBoveda($entrega, $montoUsd, $tasaCostoUsd);

                $diferencialCosto = round($montoNio - round($montoUsd * $tasaCostoUsd, 2), 2);

                $monedaOrigen = $idNio;
                $monedaDestino = $idUsd;
                $montoOrigen = $montoNio;
                $montoDestino = $montoUsd;
            }

            // 1. Registro histórico de la operación (origen y destino separados)
            $operacion = OperacionCambio::create([
                'fecha' => Carbon::now(),
                'tipo' => $tipo,
                'moneda_origen_id' => $monedaOrigen,
                'moneda_destino_id' => $monedaDestino,
                'monto_origen' => $montoOrigen,
                'monto_destino' => $montoDestino,
                'tasa_aplicada' => $tasa,
                'tasa_oficial' => $tasaOficial,
                'tasa_costo_usd' => $tipo === 'venta' ? ($tasaCostoUsd ?? null) : null,
                'diferencial_costo' => $diferencialCosto,
                'diferencial_oficial' => $diferencialOficial,
                'origen_pago_tipo' => get_class($entrega),
                'origen_pago_id' => $entrega->id,
                'destino_pago_tipo' => get_class($recepcion),
                'destino_pago_id' => $recepcion->id,
                'referencia' => $request->referencia,
                'usuario_id' => auth()->id() ?? 1,
            ]);

            // 2. Asiento contable cuadrado (venta valora la salida de USD a libro)
            $contabilidad = new ContabilidadService;
            $asientoId = $contabilidad->contabilizarOperacionCambio($operacion, [
                'ubicacion_entrega' => $entrega,
                'ubicacion_recepcion' => $recepcion,
                'tasa_costo_usd' => $tipo === 'venta' ? ($tasaCostoUsd ?? null) : null,
            ]);

            $operacion->asiento_contable_id = $asientoId;
            $operacion->save();

            // 3. Diferencial cambiario histórico (venta con variación vs. costo)
            if ($tipo === 'venta' && $diferencialCosto != 0) {
                DiferencialCambiario::create([
                    'saldo_moneda_id' => $this->saldoMonedaId('USD', get_class($entrega), $entrega->id),
                    'fecha' => Carbon::now(),
                    'monto_moneda_extranjera' => $montoUsd,
                    'tasa_costo_promedio' => $tasaCostoUsd,
                    'tasa_revaluacion' => $tasa,
                    'diferencia_calculada' => abs($diferencialCosto),
                    'tipo' => $diferencialCosto > 0 ? 'ganancia' : 'perdida',
                    'estado' => 'contabilizado',
                ]);
            }

            // 4. Libro bancario cuando una de las bóvedas es una cuenta bancaria
            $this->registrarPatasBancarias($tipo, $entrega, $recepcion, $montoUsd, $montoNio, $operacion, $asientoId);

            DB::commit();

            $mensaje = $tipo === 'compra'
                ? 'Compra registrada: +$ '.number_format($montoUsd, 2).' entraron a la bóveda de destino por C$ '.number_format($montoNio, 2).'.'
                : 'Venta registrada: -$ '.number_format($montoUsd, 2).' salieron de la bóveda de origen por C$ '.number_format($montoNio, 2)
                    .($diferencialCosto != 0 ? ' (diferencial cambiario: C$ '.number_format($diferencialCosto, 2).')' : '').'.';

            return back()->with('success', $mensaje);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al procesar cambio de divisas', [
                'exception' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }
    }

    // =====================================================================
    // TASA OFICIAL DEL DÍA (BCN / caja oficial)
    // =====================================================================
    public function registrarTasaOficial(Request $request)
    {
        $request->validate([
            'fecha' => 'required|date|before_or_equal:today',
            'tasa_compra' => 'required|numeric|min:1',  // BCN compra USD
            'tasa_venta' => 'required|numeric|min:1',   // BCN vende USD
        ]);

        try {
            TipoCambio::create([
                'fecha' => Carbon::parse($request->fecha)->toDateString(),
                'moneda_origen_id' => Moneda::idPorCodigo('USD'),
                'moneda_destino_id' => Moneda::idPorCodigo('NIO'),
                'tasa_compra' => round((float) $request->tasa_compra, 6),
                'tasa_venta' => round((float) $request->tasa_venta, 6),
            ]);

            return back()->with('success', 'Tasa oficial registrada para el '.Carbon::parse($request->fecha)->format('d/m/Y').'.');
        } catch (\Exception $e) {
            Log::error('Error al registrar tasa oficial', ['exception' => $e->getMessage()]);

            return back()->withErrors(['error' => 'No se pudo registrar la tasa oficial. Verifique que la moneda USD exista.']);
        }
    }

    // =====================================================================
    // HELPERS DE BÓVEDAS
    // =====================================================================

    /**
     * Valida que una bóveda pueda entregar NIO (banco: saldo; caja: efectivo teórico).
     */
    protected function validarEgresoNio(object $bóveda, float $montoNio): void
    {
        if ($bóveda instanceof CuentaBancaria) {
            if ($bóveda->esEnDolares()) {
                throw new \Exception('La bóveda de entrega es una cuenta en dólares: no puede pagar córdobas.');
            }
            if ((float) $bóveda->saldo_actual < $montoNio) {
                throw new \Exception('La cuenta bancaria no tiene saldo suficiente en córdobas. Disponible: C$ '.number_format((float) $bóveda->saldo_actual, 2));
            }

            return;
        }

        $disponible = $this->fondos->efectivoNioDisponible($bóveda);
        if ($disponible < $montoNio) {
            throw new \Exception('Esa caja no tiene efectivo suficiente para pagar la operación. Disponible teórico: C$ '.number_format($disponible, 2));
        }
    }

    /**
     * Ingresa USD a una bóveda: en caja actualiza saldos_moneda (con costo promedio);
     * en banco incrementa el saldo de la cuenta USD.
     */
    protected function ingresarUsdEnBoveda(object $bóveda, float $montoUsd, float $tasaCompra): void
    {
        if ($bóveda instanceof CuentaBancaria) {
            if (! $bóveda->esEnDolares()) {
                throw new \Exception('La bóveda de recepción es una cuenta en córdobas: no puede recibir dólares.');
            }

            return; // el incremento de saldo se hace en registrarPatasBancarias()
        }

        $this->divisas->ingresarDivisas('USD', FondosService::SESION, $bóveda->id, $montoUsd, $tasaCompra);
    }

    /**
     * Egresa USD de una bóveda (sólo aplica a cajas: el banco se maneja en patas).
     */
    protected function egresarUsdDeBoveda(object $bóveda, float $montoUsd, float $tasaCosto): void
    {
        if ($bóveda instanceof CuentaBancaria) {
            return; // el decremento de saldo se hace en registrarPatasBancarias()
        }

        // Diferencial de libro ya calculado por el controlador; la tasa de
        // venta aquí es igual al costo para NO doble-contabilizar diferencial.
        $this->divisas->egresarDivisas('USD', FondosService::SESION, $bóveda->id, $montoUsd, $tasaCosto);
    }

    /**
     * Ajusta saldos de cuentas bancarias y emite los movimientos del libro
     * bancario correspondientes a cada pata de la operación de cambio.
     */
    protected function registrarPatasBancarias(string $tipo, object $entrega, object $recepcion, float $montoUsd, float $montoNio, OperacionCambio $operacion, int $asientoId): void
    {
        $fecha = Carbon::parse($operacion->fecha);
        $conceptoBase = ucfirst($tipo).' de divisas (Operación #'.$operacion->id.')';

        // Pata de la bóveda que ENTREGA
        if ($entrega instanceof CuentaBancaria) {
            $esUsd = $entrega->esEnDolares();
            $monto = $esUsd ? $montoUsd : $montoNio;

            $entrega->saldo_actual = round((float) $entrega->saldo_actual - $monto, 2);
            $entrega->save();

            MovimientoBancario::create([
                'cuenta_bancaria_id' => $entrega->id,
                'moneda_id' => $esUsd ? Moneda::idPorCodigo('USD') : Moneda::idPorCodigo('NIO'),
                'tipo' => 'retiro',
                'monto' => $monto,
                'fecha' => $fecha,
                'concepto' => $conceptoBase.($operacion->referencia ? ' — Ref: '.$operacion->referencia : ''),
                'modulo_origen' => 'mesa_cambio',
                'referencia_id' => $operacion->id,
                'referencia_banco' => $operacion->referencia,
                'estado_conciliacion' => 'pendiente',
                'asiento_contable_id' => $asientoId,
            ]);
        }

        // Pata de la bóveda que RECIBE
        if ($recepcion instanceof CuentaBancaria) {
            $esUsd = $recepcion->esEnDolares();
            $monto = $esUsd ? $montoUsd : $montoNio;

            $recepcion->saldo_actual = round((float) $recepcion->saldo_actual + $monto, 2);
            $recepcion->save();

            MovimientoBancario::create([
                'cuenta_bancaria_id' => $recepcion->id,
                'moneda_id' => $esUsd ? Moneda::idPorCodigo('USD') : Moneda::idPorCodigo('NIO'),
                'tipo' => 'deposito',
                'monto' => $monto,
                'fecha' => $fecha,
                'concepto' => $conceptoBase.($operacion->referencia ? ' — Ref: '.$operacion->referencia : ''),
                'modulo_origen' => 'mesa_cambio',
                'referencia_id' => $operacion->id,
                'referencia_banco' => $operacion->referencia,
                'estado_conciliacion' => 'pendiente',
                'asiento_contable_id' => $asientoId,
            ]);
        }
    }

    protected function saldoMonedaId(string $codigoMoneda, string $ubicacionTipo, int $ubicacionId): ?int
    {
        return SaldoMoneda::where('moneda_id', Moneda::idPorCodigo($codigoMoneda))
            ->where('ubicacion_tipo', $ubicacionTipo)
            ->where('ubicacion_id', $ubicacionId)
            ->value('id');
    }
}
