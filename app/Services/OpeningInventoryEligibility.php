<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;

/** Inspección conservadora, solo SELECT parametrizados; no adquiere locks ni clasifica. */
final class OpeningInventoryEligibility
{
    public function inspect(ConnectionInterface $connection, int $itemId): array
    {
        if ($itemId <= 0) throw new \InvalidArgumentException('Artículo inválido.');
        $item = $connection->select('SELECT * FROM items WHERE id = ? LIMIT 1', [$itemId])[0] ?? null;
        $history = [];
        foreach (['lotes','stock_ledger_entries','stock_ledger_lot_allocations','purchase_invoice_lines',
            'sale_details','sale_detail_consumptions','mermas','inventory_opening_lines'] as $table) {
            // Tabla exclusivamente de esta lista interna; nunca proviene de la solicitud.
            if ($connection->select("SELECT id FROM {$table} WHERE item_id = ? LIMIT 1", [$itemId])) $history[] = $table;
        }
        if ($connection->select('SELECT d.id FROM sale_details d JOIN service_formulas f ON f.service_id = d.service_id WHERE f.item_id = ? LIMIT 1', [$itemId])) {
            $history[] = 'documented_service_formula';
        }
        if ($connection->select("SELECT id FROM sale_details WHERE JSON_CONTAINS(consumption_snapshot, JSON_OBJECT('item_id', ?), '$.ingredients') LIMIT 1", [$itemId])) {
            $history[] = 'documented_service_snapshot';
        }
        // Una receta actual no permite excluir una composición histórica distinta.
        // No reconstruirla: exigir conciliación de servicios sin fotografía verificable.
        if ($connection->select("SELECT id FROM sale_details WHERE service_id IS NOT NULL AND NOT COALESCE(
            JSON_TYPE(JSON_EXTRACT(consumption_snapshot, '$.version')) = 'INTEGER'
            AND JSON_EXTRACT(consumption_snapshot, '$.version') = 2
            AND JSON_TYPE(JSON_EXTRACT(consumption_snapshot, '$.ingredients')) = 'ARRAY'
            AND JSON_UNQUOTE(JSON_EXTRACT(consumption_snapshot, '$.mode')) IN ('complete','standard'), 0) LIMIT 1")) {
            $history[] = 'legacy_service_composition_unresolved';
        }
        return $this->assess($item === null ? null : (array) $item, $history);
    }

    /** Permite probar la decisión sin SQL; la fotografía debe proceder del servidor. */
    public function assess(?array $item, array $history): array
    {
        $reasons = $history;
        if ($item === null) $reasons[] = 'missing_item';
        else {
            if (($item['deleted_at'] ?? null) !== null) $reasons[] = 'archived_item';
            if (!in_array($item['type'] ?? null, ['producto_venta','producto_interno'], true)) $reasons[] = 'non_inventory_item';
            foreach (['existencia_actual','current_volume','costo_promedio'] as $field) {
                $v = $item[$field] ?? null;
                if ((!is_string($v) && !is_int($v)) || !preg_match('/^0+(?:\.0+)?$/D', (string) $v)) $reasons[] = 'nonzero_or_unknown_'.$field;
            }
            $fractional = $item['is_fractionable'] ?? null;
            $version = $item['inventory_representation_version'] ?? null;
            if (!in_array($fractional, [0,1,'0','1',false,true], true)
                || !in_array($version, [null,2,'2'], true)
                || (!in_array($fractional, [1,'1',true], true) && $version !== null)) $reasons[] = 'ambiguous_representation';
        }
        $reasons = array_values(array_unique($reasons));
        return ['eligible_at_inspection'=>$reasons === [], 'reasons'=>$reasons,
            'requires_locked_recheck'=>true, 'confirmation_allowed'=>false];
    }
}
