<?php
namespace App\Support;

/** Despacho interno explícito. La guardia CLI original no admite HTTP. */
final class AccountingSimulationAccess
{
    public static function assert(string $contract): void
    {
        if ($contract === AccountingSimulationBoundary::CONTRACT) {
            AccountingSimulationBoundary::assert($contract);
        } elseif ($contract === AccountingHttpSimulationBoundary::CONTRACT) {
            AccountingHttpSimulationBoundary::assert($contract);
        } else {
            throw new \LogicException('Frontera de simulación desconocida.');
        }
    }
}
