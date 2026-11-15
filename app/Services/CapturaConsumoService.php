<?php

namespace App\Services;

use App\Exceptions\ContabilidadException;
use App\Models\Articulo;
use App\Models\ConsumoVenta;
use App\Models\Servicio;
use App\Support\InventoryDecimal as D;

class CapturaConsumoService
{
    /** Puerta temporal de esta entrega: no se activa en desarrollo mediante configuración. */
    public static function activa(): bool
    {
        return app()->environment('testing') && config('salon.inventory_fractional_v2', false)
            && config('database.connections.mysql.database') === 'salon_erp_test'
            && config('database.connections.mysql.username') === 'salon_erp_tester';
    }

    /** Lectura del catálogo: una receta inválida no debe inutilizar todo el POS. */
    public function referenciaDisponible(int $serviceId): ?string
    {
        try {
            return $this->foto(['type' => 'servicio', 'id' => $serviceId, 'quantity' => 1])['reference'];
        } catch (ContabilidadException $e) {
            return null; // La operación seguirá rechazándose en la validación estricta del servidor.
        }
    }

    public function foto(array $line): array
    {
        $qty = D::scaled($line['quantity'] ?? null, 0, 2147483647);
        if (! $qty) {
            throw new ContabilidadException('La cantidad de líneas nuevas debe ser entera y positiva.');
        }
        if (($line['type'] ?? null) !== 'servicio') {
            $item = Articulo::find($line['id'] ?? null);
            if (! $item) {
                throw new ContabilidadException('El artículo no está disponible para esta operación.');
            }

            return ['version' => 2, 'mode' => 'product', 'ingredients' => [], 'item_id' => (int) $item->id,
                'item_type' => $item->type, 'fractional' => (bool) $item->is_fractionable];
        }
        $service = Servicio::with('formulas.articulo')->findOrFail($line['id']);
        $ingredients = [];
        foreach ($service->formulas->sortBy('item_id') as $formula) {
            if (! $formula->articulo) {
                throw new ContabilidadException('La composición contiene un artículo archivado; requiere revisión.');
            }
            $i = $formula->articulo;
            $ingredients[(int) $i->id] = ['item_id' => (int) $i->id, 'standard' => D::text(D::scaled($formula->getRawOriginal('quantity_used'))),
                'unit' => $i->unit_measure, 'capacity' => $i->total_volume, 'fractional' => (bool) $i->is_fractionable, 'item_type' => $i->type];
        }
        $capture = array_key_exists('consumo', $line) && $line['consumo'] !== null;
        $actual = [];
        if ($capture) {
            if (! is_array($line['consumo'])) {
                throw new ContabilidadException('Captura inválida.');
            }
            foreach ($line['consumo'] as $row) {
                $id = (int) ($row['item_id'] ?? 0);
                if (isset($actual[$id]) || ! isset($ingredients[$id]) || ! array_key_exists('cantidad', $row)) {
                    throw new ContabilidadException('Captura duplicada, ajena o incompleta.');
                }
                $amount = D::scaled($row['cantidad'], 2, 9999999999);
                if (! $ingredients[$id]['fractional']) {
                    D::scaled($row['cantidad'], 0, 99999999);
                }
                $actual[$id] = D::text($amount);
            }
            $keys = array_keys($actual);
            sort($keys);
            $required = array_keys($ingredients);
            sort($required);
            if ($keys !== $required) {
                throw new ContabilidadException('La captura debe incluir todos los insumos, también los de cantidad cero.');
            }
        }
        $reference = hash('sha256', json_encode(array_values($ingredients), JSON_THROW_ON_ERROR));
        if (isset($line['composition_reference']) && ! hash_equals($reference, (string) $line['composition_reference'])) {
            throw new ContabilidadException('La composición cambió desde que inició el carrito. Recargue el servicio.');
        }
        foreach ($ingredients as $id => &$row) {
            $row['actual'] = $capture ? $actual[$id] : $row['standard'];
        }
        unset($row);

        return ['version' => 2, 'mode' => $capture ? 'complete' : 'standard', 'reference' => $reference, 'ingredients' => array_values($ingredients)];
    }

    public function resolver($detail): array
    {
        if (! self::activa()) {
            throw new ContabilidadException('La ejecución de composición versionada no está activada.');
        }
        $foto = $detail->consumption_snapshot;
        if (! is_array($foto) || ($foto['version'] ?? null) !== 2) {
            throw new ContabilidadException('Composición histórica sin fotografía verificable; requiere conciliación.');
        }
        $result = [];
        foreach ($foto['ingredients'] as $row) {
            $item = Articulo::find($row['item_id']);
            if (! $item || $item->unit_measure !== $row['unit'] || (string) $item->total_volume !== (string) $row['capacity'] || (bool) $item->is_fractionable !== $row['fractional'] || $item->type !== ($row['item_type'] ?? null)) {
                throw new ContabilidadException('La medida del insumo cambió o está archivado.');
            }
            $amount = D::scaled($row['actual']);
            if ($amount > 0) {
                $result[] = ['articulo' => $item, 'cantidad' => $row['actual']];
            }
        }

        return $result;
    }

    public function guardar($detail, array $line, ?array $foto = null): void
    {
        $foto ??= $this->foto($line);
        $detail->consumption_snapshot = $foto;
        $detail->save();
        if ($foto['mode'] === 'complete') {
            foreach ($foto['ingredients'] as $row) {
                ConsumoVenta::create([
                    'sale_detail_id' => $detail->id, 'item_id' => $row['item_id'], 'quantity_standard' => $row['standard'], 'quantity_actual' => $row['actual']]);
            }
        }
    }
}
