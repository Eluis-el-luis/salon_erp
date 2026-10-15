<?php

namespace App\Services;

use App\Models\Articulo;
use App\Models\Lote;
use App\Models\Servicio;
use App\Models\StockLedger;

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
        // Contexto de trazabilidad para el Kárdex inmutable (stock_ledger_entries)
        $contexto = [
            'origen' => StockLedger::ORIGEN_VENTA,
            'referencia_tipo' => 'venta',
            'referencia_id' => $venta->id,
        ];

        foreach ($venta->detalles as $detalle) {
            if ($detalle->service_id != null) {
                $this->descontarInsumosDeServicio($detalle, $stockCritico, $contexto);
            }

            if ($detalle->item_id != null) {
                $this->descontarProductoFisico($detalle->item_id, $detalle->quantity, $stockCritico, $contexto);
            }
        }

        return $stockCritico;
    }

    protected function descontarInsumosDeServicio($detalle, array &$stockCritico, ?array $contexto = null): void
    {
        // BOM DINÁMICO: consume lo capturado como consumo real (POS) o la
        // fórmula estándar si la línea no guardó consumos.
        foreach ($this->consumosDeDetalle($detalle) as $consumo) {
            $cantidadADescontar = $consumo['cantidad'] * (float) $detalle->quantity;
            $this->consumirVolumen($consumo['articulo'], $cantidadADescontar, $stockCritico, $contexto);
        }
    }

    /**
     * FASE 1 ADMINISTRACIÓN - CONSUMO REAL vs ESTÁNDAR (BOM dinámico):
     * Resuelve los insumos a descontar de una línea de venta de servicio.
     *  1. Si la línea guardó consumos reales (sale_detail_consumptions,
     *     editados por recepción antes de facturar), ESA ES LA FUENTE DE VERDAD
     *     tanto para descontar como para revertir el inventario.
     *  2. Si no hay consumos guardados, aplica la fórmula estándar del servicio.
     *
     * @return array<int, array{articulo: Articulo, cantidad: float}> (cantidad por cada unidad de servicio)
     */
    public function consumosDeDetalle($detalle): array
    {
        $detalle->loadMissing('consumos.articulo');

        if ($detalle->consumos->isNotEmpty()) {
            $consumos = [];

            foreach ($detalle->consumos as $consumo) {
                if ($consumo->articulo && (float) $consumo->quantity_actual > 0) {
                    $consumos[] = [
                        'articulo' => $consumo->articulo,
                        'cantidad' => (float) $consumo->quantity_actual,
                    ];
                }
            }

            if (count($consumos) > 0) {
                return $consumos;
            }
        }

        // Fallback: receta estándar (BOM) vigente del servicio
        $servicio = Servicio::with('formulas.articulo')->find($detalle->service_id);
        if (! $servicio) {
            return [];
        }

        $consumos = [];

        foreach ($servicio->formulas as $formula) {
            if ($formula->articulo && (float) $formula->quantity_used > 0) {
                $consumos[] = [
                    'articulo' => $formula->articulo,
                    'cantidad' => (float) $formula->quantity_used,
                ];
            }
        }

        return $consumos;
    }

    /**
     * Consume volumen de un insumo fraccionable. Cuando el volumen llega a 0,
     * descuenta una unidad física (existencia_actual) y reabre el volumen de la
     * unidad nueva (total_volume).
     * FASE 4 AVANZADA: También descuenta del lote correspondiente (FIFO por volumen).
     */
    protected function consumirVolumen(Articulo $articulo, float $cantidad, array &$stockCritico, ?array $contexto = null): void
    {
        // Insumo NO fraccionable en la receta: se consume por UNIDADES, sin
        // juego de volumen. (Antes el bucle de volumen drenaba toda la
        // existencia: integridad de inventario corregida.)
        if (! $articulo->is_fractionable || (float) $articulo->total_volume <= 0) {
            $articulo->existencia_actual -= $cantidad;
            $articulo->save();
            $this->consumirLotesFIFO($articulo, $cantidad);
            $this->registrarLedger($articulo, 'salida', -$cantidad, null, $contexto);
            $this->registrarStockCritico($articulo, $stockCritico);

            return;
        }

        $articulo->current_volume -= $cantidad;

        // FIFO AVANZADO: Consumir del lote más antiguo por VOLUMEN
        $this->consumirLotesFIFOVolumen($articulo, $cantidad);

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

        // KÁRDEX INMUTABLE: salida por volumen; las unidades equivalentes son
        // el espejo exacto del volumen consumido (ml / volumen por frasco).
        $this->registrarLedger(
            $articulo,
            'salida',
            -($cantidad / (float) $articulo->total_volume),
            -$cantidad,
            $contexto
        );
        $this->registrarStockCritico($articulo, $stockCritico);
    }

    /**
     * Registra un movimiento en el Kárdex inmutable (stock_ledger_entries).
     * En salidas el costo se resuelve solo: promedio móvil vigente del ledger.
     * Debe ejecutarse dentro de la transacción del caso de uso.
     */
    protected function registrarLedger(Articulo $articulo, string $movimiento, float $unidades, ?float $volumenMl, ?array $contexto): void
    {
        StockLedger::registrar(
            $articulo,
            $movimiento,
            $contexto['origen'] ?? StockLedger::ORIGEN_VENTA,
            $unidades,
            $volumenMl,
            null,
            $contexto['referencia_tipo'] ?? null,
            $contexto['referencia_id'] ?? null,
            auth()->id(),
        );
    }

    /**
     * Registra una alerta de stock crítico cuando el artículo queda en o por
     * debajo de su stock mínimo (mismo criterio que el dashboard: existencia <= stock_min).
     * Para fraccionables compara el volumen disponible (unidades + volumen abierto).
     */
    protected function registrarStockCritico(Articulo $articulo, array &$stockCritico): void
    {
        $minimo = (float) ($articulo->stock_min ?? 0);

        if ($articulo->is_fractionable && (float) $articulo->total_volume > 0) {
            $disponible = ((float) $articulo->existencia_actual * (float) $articulo->total_volume) + (float) $articulo->current_volume;
            $minimoVolumen = $minimo * (float) $articulo->total_volume;

            if ($disponible <= $minimoVolumen) {
                $stockCritico[$articulo->id] = $articulo->producto.' (queda '
                    .number_format($disponible, 2, '.', '').' '.($articulo->unit_measure ?: 'ml').')';
            }

            return;
        }

        if ((float) $articulo->existencia_actual <= $minimo) {
            $stockCritico[$articulo->id] = $articulo->producto.' (queda '.(int) $articulo->existencia_actual.')';
        }
    }

    protected function descontarProductoFisico($itemId, $cantidad, array &$stockCritico, ?array $contexto = null): void
    {
        $articulo = Articulo::find($itemId);
        if (! $articulo) {
            return;
        }

        $articulo->existencia_actual -= $cantidad;
        $articulo->save();

        // FIFO: consumir la cantidad desde los lotes más antiguos primero
        $this->consumirLotesFIFO($articulo, $cantidad);

        // KÁRDEX: salida por unidades (si es fraccionable, el volumen equivale
        // a botellas completas y lo calcula StockLedger automáticamente)
        $this->registrarLedger($articulo, 'salida', -(float) $cantidad, null, $contexto);
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
        if ($volumenMl <= 0 || ! $articulo->is_fractionable) {
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
     * ANULACIÓN DE FACTURA: devuelve al inventario todo lo consumido por una
     * venta. Espejo de descontarPorVenta():
     *  - Productos físicos: regresan las unidades.
     *  - Insumos fraccionables: regresa el volumen (si el frasco abierto se
     *    "llena", se reconstruye la unidad física).
     * Los lotes se reponen sobre el lote MÁS RECIENTE (retorno LIFO): el sistema
     * no guarda el lote exacto de cada consumo, por lo que la reposición es
     * agregada y mantiene consistentes artículo y totales de lote.
     */
    public function revertirPorVenta($venta): void
    {
        // Contexto de trazabilidad para el Kárdex inmutable
        $contexto = [
            'origen' => StockLedger::ORIGEN_REVERSION_VENTA,
            'referencia_tipo' => 'venta',
            'referencia_id' => $venta->id,
        ];

        foreach ($venta->detalles as $detalle) {
            if ($detalle->item_id != null) {
                $this->revertirProductoFisico($detalle->item_id, (float) $detalle->quantity, $contexto);
            }

            if ($detalle->service_id != null) {
                // La reversión usa la MISMA fuente de verdad que el descuento
                // (consumo real fotografiado o fórmula estándar), garantizando
                // exactitud aunque la fórmula del catálogo cambie a posteriori.
                foreach ($this->consumosDeDetalle($detalle) as $consumo) {
                    $cantidadDevuelta = $consumo['cantidad'] * (float) $detalle->quantity;
                    $articulo = $consumo['articulo'];

                    // Espejo exacto del descuento: insumos fraccionables por
                    // volumen; el resto por unidades.
                    if ($articulo->is_fractionable && (float) $articulo->total_volume > 0) {
                        $this->revertirVolumen($articulo, $cantidadDevuelta, $contexto);
                    } else {
                        $this->revertirProductoFisico($articulo->id, $cantidadDevuelta, $contexto);
                    }
                }
            }
        }
    }

    protected function revertirProductoFisico(int $itemId, float $cantidad, ?array $contexto = null): void
    {
        $articulo = Articulo::find($itemId);
        if (! $articulo || $cantidad <= 0) {
            return;
        }

        $articulo->existencia_actual += $cantidad;
        $articulo->save();

        $this->devolverUnidadesALoteReciente($articulo, $cantidad);

        // KÁRDEX: entrada espejo valorada al promedio móvil vigente
        $this->registrarLedger($articulo, 'entrada', $cantidad, null, $contexto);
    }

    protected function revertirVolumen(Articulo $articulo, float $volumenMl, ?array $contexto = null): void
    {
        if ($volumenMl <= 0) {
            return;
        }

        $unidadesRearmadas = 0;
        $articulo->current_volume += $volumenMl;

        // Si el frasco abierto se "desborda", se reconstruyen unidades físicas
        while ($articulo->total_volume > 0 && $articulo->current_volume > $articulo->total_volume) {
            $articulo->existencia_actual += 1;
            $articulo->current_volume -= $articulo->total_volume;
            $unidadesRearmadas++;
        }

        $articulo->save();

        // Espejo del consumo en lotes: las unidades enteras consumidas por
        // volumen (floor del consumo) más las rearmadas en la reposición.
        if ($articulo->is_fractionable && $articulo->total_volume > 0) {
            $unidadesPorVolumen = (int) floor($volumenMl / $articulo->total_volume);
            $this->devolverUnidadesALoteReciente($articulo, $unidadesPorVolumen + $unidadesRearmadas);
        }

        // KÁRDEX: entrada por volumen; unidades equivalentes exactas (ml/volumen)
        if ($articulo->is_fractionable && (float) $articulo->total_volume > 0) {
            $this->registrarLedger(
                $articulo,
                'entrada',
                $volumenMl / (float) $articulo->total_volume,
                $volumenMl,
                $contexto
            );
        }
    }

    /**
     * REGISTRO DE MERMA (centralizado): descuenta el stock físico (unidades o
     * volumen), consume lotes FIFO, escribe el KÁRDEX inmutable con la
     * referencia a la merma y devuelve el VALOR de la baja al costo real de
     * valoración (promedio móvil del ledger; nunca el precio de venta).
     * Debe ejecutarse dentro de la transacción del caso de uso.
     */
    public function registrarMerma(Articulo $articulo, string $tipo, float $cantidad, ?int $mermaId = null): float
    {
        $contexto = [
            'origen' => StockLedger::ORIGEN_MERMA,
            'referencia_tipo' => 'merma',
            'referencia_id' => $mermaId,
        ];
        $stockCritico = [];

        if ($tipo === 'volumen') {
            $this->consumirVolumen($articulo, $cantidad, $stockCritico, $contexto);
        } else {
            $this->descontarProductoFisico($articulo->id, $cantidad, $stockCritico, $contexto);
        }

        $entrada = StockLedger::where('item_id', $articulo->id)->orderByDesc('id')->first();

        return (float) abs($entrada?->valor ?? 0);
    }

    /**
     * Repone unidades en el lote más reciente del artículo (retorno LIFO).
     * Si no hay lotes, no bloquea: los contadores del artículo ya quedaron bien.
     */
    protected function devolverUnidadesALoteReciente(Articulo $articulo, float $unidades): void
    {
        if ($unidades <= 0) {
            return;
        }

        $lote = Lote::where('item_id', $articulo->id)
            ->orderBy('fecha_entrada', 'desc')
            ->orderBy('id', 'desc')
            ->lockForUpdate()
            ->first();

        if ($lote) {
            $lote->cantidad_disponible += $unidades;
            $lote->save();
        }
    }

    /**
     * FASE 4 AVANZADA: Costo Promedio Móvil por VOLUMEN para insumos fraccionables.
     * Calcula el costo promedio ponderado por ml/oz basado en los lotes disponibles.
     */
    public function calcularCostoPromedioVolumen(Articulo $articulo): float
    {
        if (! $articulo->is_fractionable || $articulo->total_volume <= 0) {
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
