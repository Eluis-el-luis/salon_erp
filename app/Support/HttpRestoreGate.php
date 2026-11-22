<?php

namespace App\Support;

/** Contención independiente del entorno y de las puertas de inventario/ventas. */
final class HttpRestoreGate
{
    // No es configuración: cambiar este cierre requiere otra intervención de código.
    private const RECOVERY_CONTRACT_IMPLEMENTED = false;

    public const MESSAGE = 'La restauración SQL desde la aplicación está temporalmente deshabilitada por seguridad. La recuperación requiere un procedimiento autorizado.';

    public static function allows(mixed $configured): bool
    {
        return $configured === true && self::RECOVERY_CONTRACT_IMPLEMENTED;
    }
}
