<?php

namespace App\Services;

use App\Models\Articulo;
use App\Models\Servicio;

/**
 * MANDAMIENTO DE INVENTARIO FRACCIONADO:
 * Centraliza el descuento de inventario físico. Los servicios consumen
 * insumos por mililitros/onzas vía las tablas pivote (service_formulas),
 * y los productos físicos decrementan unidades. Devuelve alertas de stock crítico.
 */
class InventarioService
{
    /**
     * Descuenta el inventario de una venta (detalles cargados en $venta->detalles).
     *
     * @return array<string,string> stock crítico: [articulo_id => nombre (queda N)]
     */
    public function descontarPorVenta($venta): array
    {
        $stockCritico = [];

        foreach ($venta->detalles as $detalle) {
            if ($detalle->service_id != null) {
                $this->descontarInsumosDeServicio($detalle, $stockCritico);
            }

            if ($detalle->item_id != null) {
                $this->descontarProductoFisico($detalle->item_id, $detalle->quantity, $stockCritico);
            }
        }

        return $stockCritico;
    }

    protected function descontarInsumosDeServicio($detalle, array &$stockCritico): void
    {
        $servicio = Servicio::with('formulas.articulo')->find($detalle->service_id);
        if (!$servicio) {
            return;
        }

        foreach ($servicio->formulas as $formula) {
            $articulo = $formula->articulo;
            if (!$articulo) {
                continue;
            }

            $cantidadADescontar = $formula->quantity_used * $detalle->quantity;
            $this->consumirVolumen($articulo, $cantidadADescontar, $stockCritico);
        }
    }

    /**
     * Consume volumen de un insumo fraccionable. Cuando el volumen llega a 0,
     * descuenta una unidad física (existencia_actual) y reabre el volumen de la
     * unidad nueva (total_volume).
     */
    protected function consumirVolumen(Articulo $articulo, float $cantidad, array &$stockCritico): void
    {
        $articulo->current_volume -= $cantidad;

        while ($articulo->current_volume <= 0) {
            if ($articulo->existencia_actual > 0) {
                $articulo->existencia_actual -= 1;
                $articulo->current_volume += $articulo->total_volume;
            } else {
                break;
            }
        }

        $articulo->save();
        $this->registrarStockCritico($articulo, $stockCritico);
    }

    protected function descontarProductoFisico($itemId, $cantidad, array &$stockCritico): void
    {
        $articulo = Articulo::find($itemId);
        if (!$articulo) {
            return;
        }

        $articulo->existencia_actual -= $cantidad;
        $articulo->save();
        $this->registrarStockCritico($articulo, $stockCritico);
    }

    protected function registrarStockCritico(Articulo $articulo, array &$stockCritico): void
    {
        if ($articulo->stock_min !== null && $articulo->existencia_actual <= $articulo->stock_min) {
            $stockCritico[$articulo->id] = $articulo->producto . ' (queda ' . (int) $articulo->existencia_actual . ')';
        }
    }
}