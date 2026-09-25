<?php

namespace App\Services;

use App\Models\Articulo;
use App\Models\Servicio;
use App\Models\Lote;

/**
 * MANDAMIENTO DE INVENTARIO FRACCIONADO:
 * Centraliza el descuento de inventario físico. Los servicios consumen
 * insumos por mililitros/onzas vía las tablas pivote (service_formulas),
 * y los productos físicos decrementan unidades. Devuelve alertas de stock crítico.
 * 
 * FASE 4 AVANZADA: FIFO por volumen + costo promedio móvil por ml/oz
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
     * FASE 4 AVANZADA: También descuenta del lote correspondiente (FIFO por volumen).
     */
    protected function consumirVolumen(Articulo $articulo, float $cantidad, array &$stockCritico): void
    {
        $articulo->current_volume -= $cantidad;

        // FIFO AVANZADO: Consumir del lote más antiguo por VOLUMEN
        if ($articulo->is_fractionable && $articulo->total_volume > 0) {
            $this->consumirLotesFIFOVolumen($articulo, $cantidad);
        }

        while ($articulo->current_volume <= 0) {
            if ($articulo->existencia_actual > 0) {
                $articulo->existencia_actual -= 1;
                $articulo->current_volume += $articulo->total_volume;
                // FIFO: cada unidad consumida sale del lote más antiguo
                $this->consumirLotesFIFO($articulo, 1);
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

        // FIFO: consumir la cantidad desde los lotes más antiguos primero
        $this->consumirLotesFIFO($articulo, $cantidad);
        $this->registrarStockCritico($articulo, $stockCritico);
    }

    /**
     * FASE 4 AVANZADA - FIFO por UNIDADES: descuenta unidades del lote más antiguo (First In, First Out).
     * Si no hay lotes registrados, no bloquea la operación (los contadores físicos ya se actualizan).
     */
    public function consumirLotesFIFO(Articulo $articulo, float $cantidad): void
    {
        if ($cantidad <= 0) {
            return;
        }

        $lotes = Lote::where('item_id', $articulo->id)
            ->where('cantidad_disponible', '>', 0)
            ->orderBy('fecha_entrada', 'asc')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        $pendiente = (float) $cantidad;

        foreach ($lotes as $lote) {
            if ($pendiente <= 0) {
                break;
            }

            $aDescontar = min($lote->cantidad_disponible, $pendiente);
            $lote->cantidad_disponible -= $aDescontar;
            $lote->save();
            $pendiente -= $aDescontar;
        }
    }

    /**
     * FASE 4 AVANZADA - FIFO por VOLUMEN: descuenta mililitros/onzas del lote más antiguo.
     * Usado para insumos fraccionables (Keratina, Shampoo, etc.) que se miden en ml/oz.
     */
    public function consumirLotesFIFOVolumen(Articulo $articulo, float $volumenMl): void
    {
        if ($volumenMl <= 0 || !$articulo->is_fractionable) {
            return;
        }

        $lotes = Lote::where('item_id', $articulo->id)
            ->where('cantidad_disponible', '>', 0)
            ->orderBy('fecha_entrada', 'asc')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        $pendienteMl = (float) $volumenMl;

        foreach ($lotes as $lote) {
            if ($pendienteMl <= 0) {
                break;
            }

            // El lote tiene cantidad_disponible en UNIDADES, cada una con total_volume ml
            $volumenDisponibleLote = $lote->cantidad_disponible * $articulo->total_volume;
            
            if ($volumenDisponibleLote <= 0) {
                continue;
            }

            $aDescontarMl = min($volumenDisponibleLote, $pendienteMl);
            
            // Calcular cuántas UNIDADES completas y ml parciales se consumen
            $unidadesCompletas = floor($aDescontarMl / $articulo->total_volume);
            $mlParcial = $aDescontarMl - ($unidadesCompletas * $articulo->total_volume);
            
            if ($unidadesCompletas > 0) {
                $aDescontarUnidades = min($unidadesCompletas, $lote->cantidad_disponible);
                $lote->cantidad_disponible -= $aDescontarUnidades;
            }
            
            // Si quedan ml parciales y no hay unidades completas, marcamos el lote como parcialmente consumido
            // (en la práctica, el current_volume del artículo ya refleja esto)
            if ($mlParcial > 0 && $lote->cantidad_disponible > 0) {
                // El lote queda con una unidad "parcial" - el current_volume del artículo lo refleja
            }
            
            $lote->save();
            $pendienteMl -= $aDescontarMl;
        }
    }

    /**
     * FASE 4 AVANZADA: Costo Promedio Móvil por VOLUMEN para insumos fraccionables.
     * Calcula el costo promedio ponderado por ml/oz basado en los lotes disponibles.
     */
    public function calcularCostoPromedioVolumen(Articulo $articulo): float
    {
        if (!$articulo->is_fractionable || $articulo->total_volume <= 0) {
            return 0;
        }

        $lotes = Lote::where('item_id', $articulo->id)
            ->where('cantidad_disponible', '>', 0)
            ->orderBy('fecha_entrada', 'asc')
            ->get();

        $volumenTotalMl = 0;
        $valorTotal = 0;

        foreach ($lotes as $lote) {
            $volumenLote = $lote->cantidad_disponible * $articulo->total_volume;
            $valorLote = $volumenLote * $lote->costo_unitario;
            
            $volumenTotalMl += $volumenLote;
            $valorTotal += $valorLote;
        }

        if ($volumenTotalMl <= 0) {
            return 0;
        }

        return round($valorTotal / $volumenTotalMl, 6);
    }
}