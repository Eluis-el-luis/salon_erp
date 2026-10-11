<?php

namespace App\Services;

use App\Models\CuentaBancaria;
use App\Models\OperacionCambio;
use App\Models\SesionCaja;
use App\Models\Transferencia;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;

/**
 * TRAZABILIDAD DE BÓVEDAS (Mandamiento de Fondos):
 * Toda operación financiera debe saber de dónde sale el dinero y a dónde entra.
 * Este servicio enumera las "bóvedas" disponibles (turnos de caja y cuentas
 * bancarias), calcula su saldo disponible por moneda y resuelve la subcuenta
 * contable de cada una para que ContabilidadService no invente códigos.
 *
 * Ubicaciones soportadas (polimórficas, igual que saldos_moneda):
 *  - App\Models\SesionCaja   -> efectivo físico del turno (NIO y USD)
 *  - App\Models\CuentaBancaria -> cuenta bancaria (NIO o USD según moneda_id)
 */
class FondosService
{
    public const SESION = 'App\Models\SesionCaja';

    public const BANCO = 'App\Models\CuentaBancaria';

    /**
     * Lista de bóvedas activas con sus saldos por moneda.
     * Cada elemento: ['tipo','id','etiqueta','naturaleza','moneda','nio','usd','costo_promedio_usd','detalle'].
     */
    public function listarUbicaciones(): array
    {
        $ubicaciones = [];

        $sesiones = SesionCaja::with('usuario')
            ->where('estado', 'abierta')
            ->orderBy('fecha_apertura')
            ->get();

        foreach ($sesiones as $sesion) {
            $divisas = app(DivisaService::class)->saldoDivisas('USD', self::SESION, $sesion->id);

            $ubicaciones[] = [
                'tipo' => self::SESION,
                'id' => $sesion->id,
                'etiqueta' => 'Caja #'.$sesion->id.' — '.($sesion->usuario->name ?? 'Turno'),
                'naturaleza' => 'efectivo',
                'moneda' => 'NIO/USD',
                'nio' => $this->efectivoNioDisponible($sesion),
                'usd' => (float) $divisas['saldo'],
                'costo_promedio_usd' => (float) $divisas['costo_promedio'],
                'detalle' => 'Efectivo físico del turno (apertura + cobros del día)',
            ];
        }

        $cuentas = CuentaBancaria::with(['banco', 'moneda'])
            ->where('activa', true)
            ->orderBy('banco_id')
            ->get();

        foreach ($cuentas as $cuenta) {
            $esUsd = $cuenta->esEnDolares();
            $ubicaciones[] = [
                'tipo' => self::BANCO,
                'id' => $cuenta->id,
                'etiqueta' => $cuenta->etiqueta(),
                'naturaleza' => 'banco',
                'moneda' => $esUsd ? 'USD' : 'NIO',
                'nio' => $esUsd ? 0.0 : (float) $cuenta->saldo_actual,
                'usd' => $esUsd ? (float) $cuenta->saldo_actual : 0.0,
                'costo_promedio_usd' => 0.0, // en banco no se pondera: se mueve a valor libro
                'detalle' => ucfirst($cuenta->tipo_cuenta ?? '').' • '.$cuenta->numero_cuenta,
            ];
        }

        return $ubicaciones;
    }

    /**
     * Resuelve y valida una ubicación de fondos desde su representación polimórfica.
     */
    public function resolver(string $tipo, int $id): object
    {
        if ($tipo === self::SESION) {
            $sesion = SesionCaja::find($id);
            if (! $sesion || $sesion->estado !== 'abierta') {
                throw new \Exception('La caja seleccionada no existe o no tiene un turno abierto.');
            }

            return $sesion;
        }

        if ($tipo === self::BANCO) {
            $cuenta = CuentaBancaria::with('moneda')->find($id);
            if (! $cuenta || ! $cuenta->activa) {
                throw new \Exception('La cuenta bancaria seleccionada no existe o está inactiva.');
            }

            return $cuenta;
        }

        throw new \Exception("Ubicación de fondos no soportada: [{$tipo}].");
    }

    /**
     * Efectivo NIO disponible en un turno: apertura + cobros netos del libro de
     * pagos - dinero que ya salió de esa caja (depósitos, pagos bancarios y
     * compras de divisas). Evita vender dos veces el mismo efectivo.
     */
    public function efectivoNioDisponible(SesionCaja $sesion): float
    {
        $ventasEfectivo = Venta::efectivoNioNeto(
            Venta::where('status', Venta::ESTADO_PAGADA)
                ->where('cashier_id', $sesion->user_id)
                ->where('created_at', '>=', $sesion->fecha_apertura)
        );

        // Depósitos y transferencias que ya salieron de esta caja.
        $salidasTransferencias = (float) Transferencia::where('origen_tipo', self::SESION)
            ->where('origen_id', $sesion->id)
            ->where('estado', 'completada')
            ->sum(DB::raw('monto + comision'));

        // Córdobas entregados en la Mesa de Cambio (compras de USD pagadas desde esta caja).
        $salidasCambio = (float) OperacionCambio::where('tipo', 'compra')
            ->where('origen_pago_tipo', self::SESION)
            ->where('origen_pago_id', $sesion->id)
            ->where('estado', 'completada')
            ->sum('monto_destino'); // en una compra de USD, monto_destino es el NIO pagado

        return round((float) $sesion->monto_apertura + $ventasEfectivo - $salidasTransferencias - $salidasCambio, 2);
    }

    /**
     * Subcuenta contable que representa el efectivo NIO de una ubicación.
     */
    public function cuentaContableNio(object $ubicacion): int
    {
        $contabilidad = app(ContabilidadService::class);

        if ($ubicacion instanceof SesionCaja) {
            // Convención del sistema (AGENTS §5.4): el efectivo NIO se mueve en la
            // Caja General 1.1.1; los dólares físicos en la subcuenta 1.1.1.2.
            return $contabilidad->cuentaIdPorCodigo('1.1.1');
        }

        if ($ubicacion instanceof CuentaBancaria) {
            if ($ubicacion->esEnDolares()) {
                throw new \Exception('La cuenta bancaria seleccionada es en dólares: no puede mover córdobas.');
            }

            return $this->cuentaContableDeBanco($ubicacion);
        }

        throw new \Exception('Ubicación de fondos inválida para córdobas.');
    }

    /**
     * Subcuenta contable que representa la divisa USD de una ubicación.
     */
    public function cuentaContableUsd(object $ubicacion): int
    {
        $contabilidad = app(ContabilidadService::class);

        if ($ubicacion instanceof SesionCaja) {
            return $contabilidad->cuentaIdPorCodigo('1.1.1.2'); // Caja Dólares
        }

        if ($ubicacion instanceof CuentaBancaria) {
            if (! $ubicacion->esEnDolares()) {
                throw new \Exception('La cuenta bancaria seleccionada es en córdobas: no puede mover dólares.');
            }

            return $this->cuentaContableDeBanco($ubicacion);
        }

        throw new \Exception('Ubicación de fondos inválida para dólares.');
    }

    /**
     * Cuenta del mayor de una cuenta bancaria: prioriza el vínculo directo
     * (cuenta_contable_id); si es legacy, cae al mapeo histórico por código
     * de banco (BAC 1.1.2.2 / LAFISE 1.1.2.1).
     */
    public function cuentaContableDeBanco(CuentaBancaria $cuenta): int
    {
        $contabilidad = app(ContabilidadService::class);

        if ($cuenta->cuenta_contable_id) {
            return (int) $cuenta->cuenta_contable_id;
        }

        $codigo = strtoupper($cuenta->banco->codigo ?? 'LAFISE');

        return $contabilidad->cuentaIdPorCodigo($codigo === 'BAC' ? '1.1.2.2' : '1.1.2.1');
    }

    /**
     * Etiqueta breve de una ubicación polimórfica (para el historial de operaciones).
     */
    public static function etiquetaCorta(object $ubicacion): string
    {
        if ($ubicacion instanceof SesionCaja) {
            return 'Caja #'.$ubicacion->id;
        }

        if ($ubicacion instanceof CuentaBancaria) {
            return ($ubicacion->banco->codigo ?? 'BANCO').' '.($ubicacion->moneda->codigo ?? '');
        }

        return 'Bóveda';
    }

    /**
     * Movimientos pendientes de conciliar de una cuenta, hasta una fecha,
     * con su saldo acumulado inicial (desde la última conciliación cerrada).
     */
    public function saldoInicialConciliado(CuentaBancaria $cuenta): float
    {
        $ultima = $cuenta->conciliaciones()->where('estado', 'cerrada')->orderByDesc('fecha')->orderByDesc('id')->first();

        return $ultima ? (float) $ultima->saldo_extracto : 0.0;
    }
}
