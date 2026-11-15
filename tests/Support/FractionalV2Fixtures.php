<?php

namespace Tests\Support;

use App\Models\Articulo;
use App\Models\Proveedor;
use App\Models\Servicio;
use App\Services\CompraService;
use Illuminate\Support\Facades\DB;

trait FractionalV2Fixtures
{
    protected function itemV2(int $capacity = 750, string $unit = 'ml'): Articulo
    {
        $i = Articulo::create(['producto' => 'H03 V2 fixture', 'type' => 'producto_venta', 'precio_c' => 100, 'is_fractionable' => true,
            'total_volume' => $capacity, 'unit_measure' => $unit, 'existencia_actual' => 0, 'current_volume' => 0, 'stock_min' => 0]);
        // Clasificación exclusiva de fixture vacío, jamás una conversión histórica.
        DB::table('items')->where('id', $i->id)->update(['inventory_representation_version' => 2]);

        return $i->refresh();
    }

    protected function buyV2(Articulo $i, $n = 2, $cost = '17.23', array $extra = [])
    {
        try {
            return app(CompraService::class)->registrarFactura(array_replace(['provider_id' => Proveedor::create(['name' => 'H03 fixture'])->id,
                'condicion_pago' => 'credito', 'articulos' => [['id' => $i->id, 'cantidad' => $n, 'costo' => $cost]]], $extra));
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage().' '.implode(' / ', array_map(fn ($f) => basename($f['file'] ?? '').':'.($f['line'] ?? 0), array_slice($e->getTrace(), 0, 5))), 0, $e);
        }
    }

    protected function serviceV2(array $recipe): Servicio
    {
        $s = Servicio::create(['name' => 'H03 fixture', 'price' => 100, 'duration' => 30]);
        foreach ($recipe as $id => $q) {
            DB::table('service_formulas')->insert(['service_id' => $s->id, 'item_id' => $id, 'quantity_used' => $q]);
        }

        return $s;
    }

    protected function lineV2(Servicio $s, ?array $capture = null): array
    {
        $line = ['id' => $s->id, 'type' => 'servicio', 'quantity' => 1, 'precio_c' => 100];
        if ($capture !== null) {
            $line['consumo'] = array_map(fn ($id, $q) => ['item_id' => $id, 'cantidad' => $q], array_keys($capture), array_values($capture));
        }

        return $line;
    }
}
