<?php

namespace App\Support;

use DomainException;

/** Pure encoding, not a signature. Semantic list ordering belongs to the validator. */
final class FinancialPresentationCanonicalizer
{
    public const VERSION = 'financial-presentation-canonical-v1';

    public function encode(mixed $value): string
    {
        return json_encode($this->ordered($value, 0), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function ordered(mixed $value, int $depth): mixed
    {
        if ($depth > 32) throw new DomainException('content.depth');
        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) throw new DomainException('content.utf8');
            return $value; // No trim, case folding, numeric conversion or Unicode normalization.
        }
        if ($value === null || is_bool($value) || is_int($value)) return $value;
        if (!is_array($value)) throw new DomainException('content.scalar_type');
        if (array_is_list($value)) return array_map(fn ($v) => $this->ordered($v, $depth + 1), $value);
        foreach ($value as $key => $_) {
            if (!is_string($key) || preg_match('//u', $key) !== 1) throw new DomainException('content.object_key');
        }
        ksort($value, SORT_STRING);
        foreach ($value as &$child) $child = $this->ordered($child, $depth + 1);
        return $value;
    }
}
