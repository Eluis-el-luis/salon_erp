<?php

namespace App\Support;

use App\Exceptions\ContabilidadException;

final class InventoryDecimal
{
    public static function scaled($value, int $scale = 2, int $max = 9999999999999): int
    {
        if (! is_scalar($value) || is_bool($value)) {
            throw new ContabilidadException('Cantidad inválida.');
        }
        $pattern = $scale === 0 ? '/^([0-9]+)(?:\.0+)?$/D' : '/^([0-9]+)(?:\.([0-9]{1,'.$scale.'}))?$/D';
        if (! preg_match($pattern, (string) $value, $m)) {
            throw new ContabilidadException('Cantidad inválida o con exceso de precisión (escala '.$scale.').');
        }
        $digits = ltrim($m[1].str_pad($m[2] ?? '', $scale, '0'), '0') ?: '0';
        if (strlen($digits) > strlen((string) $max) || (strlen($digits) === strlen((string) $max) && strcmp($digits, (string) $max) > 0)) {
            throw new ContabilidadException('Cantidad fuera de límite.');
        }

        return (int) $digits;
    }

    public static function text(int $value, int $scale = 2): string
    {
        $digits = str_pad((string) abs($value), $scale + 1, '0', STR_PAD_LEFT);

        return ($value < 0 ? '-' : '').substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }

    public static function signed($value): int
    {
        $v = (string) $value;

        return (str_starts_with($v, '-') ? -1 : 1) * self::stored(ltrim($v, '-'), 999999999999999);
    }

    /** SQL conserva cuatro decimales en volumen; solo se quitan ceros, nunca se redondea. */
    public static function stored($value, int $max = 9999999999999): int
    {
        $v = (string) $value;
        if (str_contains($v, '.')) {
            $v = rtrim(rtrim($v, '0'), '.');
        }

        return self::scaled($v, 2, $max);
    }

    public static function physical($value, $units): int
    {
        $result = bcmul((string) self::scaled($value), (string) self::scaled($units, 0, 2147483647), 0);
        if (bccomp($result, '9999999999999', 0) > 0) {
            throw new ContabilidadException('Demanda física fuera de límite.');
        }

        return (int) $result;
    }

    public static function ratio(int $a, int $b, int $den): int
    {
        if (! extension_loaded('bcmath') || $den <= 0) {
            throw new ContabilidadException('Aritmética decimal no disponible.');
        }
        $product = bcmul((string) abs($a), (string) abs($b), 0);
        $q = bcdiv($product, (string) $den, 0);
        if (bccomp(bcmul(bcmod($product, (string) $den), '2', 0), (string) $den, 0) >= 0) {
            $q = bcadd($q, '1', 0);
        }
        if (bccomp($q, (string) PHP_INT_MAX, 0) > 0) {
            throw new ContabilidadException('Desbordamiento numérico.');
        }

        return (($a < 0) !== ($b < 0) ? -1 : 1) * (int) $q;
    }
}
