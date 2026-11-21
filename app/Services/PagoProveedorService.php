<?php

namespace App\Services;

use App\Exceptions\ContabilidadException;
use App\Models\{CuentaPorPagar, PagoProveedor};
use App\Support\InventoryDecimal as D;
use Illuminate\Support\Facades\DB;

/** Único caso de uso de abonos: CxP -> pagos -> contabilidad, en la misma conexión. */
class PagoProveedorService
{
    public const MAX_CENTAVOS = 999999999999999; // DECIMAL(15,2).

    public function __construct(private ContabilidadService $contabilidad) {}

    public static function montoCentavos($monto): int
    {
        $centavos = D::scaled($monto, 2, self::MAX_CENTAVOS);
        if ($centavos === 0) throw new ContabilidadException('El importe del abono debe ser mayor que cero.');
        return $centavos;
    }

    public function registrar(int $cuentaId, $monto, string $formaPago, int $usuarioId): PagoProveedor
    {
        $centavos = self::montoCentavos($monto);
        if (!in_array($formaPago, ['efectivo', 'banco'], true)) {
            throw new ContabilidadException('Forma de pago no válida.');
        }

        return DB::transaction(function () use ($cuentaId, $centavos, $formaPago, $usuarioId) {
            // La CxP es el mutex compartido con anularFactura. No pedir después el lock de factura.
            $cuenta = $this->bloquearCuenta($cuentaId);
            if (!in_array($cuenta->estado, ['pendiente', 'parcial'], true)) {
                throw new ContabilidadException('La obligación no está en un estado que permita abonos.');
            }
            $original = D::scaled($cuenta->getRawOriginal('monto_original'), 2, self::MAX_CENTAVOS);
            $saldo = D::scaled($cuenta->getRawOriginal('saldo_pendiente'), 2, self::MAX_CENTAVOS);
            $abonado = 0;
            // Lectura actual, no snapshot previo de REPEATABLE READ después de esperar el mutex.
            foreach ($this->pagosActuales($cuenta) as $pago) {
                $importe = self::montoCentavos($pago->getRawOriginal('monto'));
                if ($importe > $original - $abonado) {
                    throw new ContabilidadException('Los pagos de la obligación requieren conciliación.');
                }
                $abonado += $importe;
            }
            if ($saldo > $original || $abonado !== $original - $saldo) {
                throw new ContabilidadException('El saldo no coincide con los abonos registrados. Requiere conciliación.');
            }
            if ($centavos > $saldo) {
                throw new ContabilidadException('El abono de C$ '.D::text($centavos).' supera el saldo pendiente de C$ '.D::text($saldo).'.');
            }

            $importe = D::text($centavos);
            $pago = $this->crearPago([
                'cuenta_por_pagar_id' => $cuenta->id, 'fecha' => now(), 'monto' => $importe,
                'forma_pago' => $formaPago, 'usuario_id' => $usuarioId,
            ]);
            $nuevoSaldo = $saldo - $centavos;
            $this->guardarSaldo($cuenta, D::text($nuevoSaldo), $nuevoSaldo === 0 ? 'pagada' : 'parcial');
            $this->contabilidad->contabilizarPagoProveedor($importe, $pago->id, $formaPago);

            return $pago;
        });
    }

    protected function bloquearCuenta(int $id): CuentaPorPagar
    {
        return CuentaPorPagar::lockForUpdate()->findOrFail($id);
    }

    protected function pagosActuales(CuentaPorPagar $cuenta)
    {
        return $cuenta->pagos()->orderBy('id')->lockForUpdate()->get();
    }

    protected function crearPago(array $datos): PagoProveedor { return PagoProveedor::create($datos); }

    protected function guardarSaldo(CuentaPorPagar $cuenta, string $saldo, string $estado): void
    {
        $cuenta->saldo_pendiente = $saldo;
        $cuenta->estado = $estado;
        $cuenta->save();
    }
}
