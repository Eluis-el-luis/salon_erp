<?php

namespace App\Services;

use App\Models\Articulo;
use App\Models\CuentaPorPagar;
use App\Models\FacturaCompra;
use App\Models\Lote;
use App\Models\Proveedor;
use App\Models\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * CADENA DE SUMINISTRO — CICLO DE COMPRA (octubre 2026).
 *
 * Dueño de la transacción del caso de uso "Ingreso de Mercadería". Una factura
 * de proveedor, en UNA sola operación atómica:
 *  1. Crea el documento (purchase_invoices) con desglose de impuestos y
 *     reparto proporcional de descuento + gastos de envío (LANDED COST).
 *  2. Actualiza el inventario físico y el COSTO PROMEDIO MÓVIL por artículo.
 *  3. Crea los lotes FIFO (con caducidad) enlazados a su línea de compra.
 *  4. Escribe el KÁRDEX inmutable (stock_ledger_entries) con saldo corrido.
 *  5. Genera la CxP del proveedor (cuentas_por_pagar) si es al crédito.
 *  6. Envía el comando al ContabilidadService para el asiento COM-*
 *     (DEBE inventario + IVA crédito fiscal / HABER CxP o caja-banco).
 *
 * La anulación (anularFactura) sigue §5.3: nunca borra, revierte.
 */
class CompraService
{
    /**
     * Registra una factura de compra completa. Lanza Exception con mensaje de
     * dominio ante datos inválidos (la transacción queda sin efectos).
     *
     * @param  array  $datos  [
     *                        provider_id, condicion_pago (credito|contado), metodo_pago_contado,
     *                        numero_documento, fecha_factura, descuento, gastos_envio, notas,
     *                        articulos => [ ['id','cantidad','costo','impuesto_porcentaje','lote','fecha_vencimiento'], ... ]
     *                        ]
     */
    public function registrarFactura(array $datos, ?int $usuarioId = null): FacturaCompra
    {
        $proveedor = Proveedor::findOrFail($datos['provider_id']);

        return DB::transaction(function () use ($datos, $proveedor, $usuarioId) {
            $lineas = $this->valorarLineas($datos['articulos'] ?? []);

            $subtotal = round(array_sum(array_column($lineas, 'valor_comprado')), 2);
            $impuestos = round(array_sum(array_column($lineas, 'impuesto_monto')), 2);
            $descuento = round((float) ($datos['descuento'] ?? 0), 2);
            $envio = round((float) ($datos['gastos_envio'] ?? 0), 2);

            $baseImponible = round($subtotal - $descuento + $envio, 2);
            if ($baseImponible <= 0) {
                throw new \Exception('El descuento no puede superar el subtotal de la compra.');
            }

            // LANDED COST: descuento y envío se reparten proporcionalmente al
            // valor comprado de cada línea y se capitalizan al costo unitario.
            $factor = $baseImponible / $subtotal;

            foreach ($lineas as $indice => $linea) {
                $lineas[$indice]['valor_neto'] = round($linea['valor_comprado'] * $factor, 2);
                $lineas[$indice]['costo_unitario_final'] = round($lineas[$indice]['valor_neto'] / $linea['cantidad'], 6);
            }

            // Corrección de redondeo: la suma de líneas debe cuadrar con la base
            $diferencia = round($baseImponible - array_sum(array_column($lineas, 'valor_neto')), 2);
            if (abs($diferencia) >= 0.01) {
                $indiceMayor = 0;
                foreach ($lineas as $indice => $linea) {
                    if ($linea['valor_neto'] > $lineas[$indiceMayor]['valor_neto']) {
                        $indiceMayor = $indice;
                    }
                }
                $lineas[$indiceMayor]['valor_neto'] = round($lineas[$indiceMayor]['valor_neto'] + $diferencia, 2);
                $lineas[$indiceMayor]['costo_unitario_final'] = round($lineas[$indiceMayor]['valor_neto'] / $lineas[$indiceMayor]['cantidad'], 6);
            }

            $total = round($baseImponible + $impuestos, 2);

            $factura = FacturaCompra::create([
                'provider_id' => $proveedor->id,
                'numero_documento' => $datos['numero_documento'] ?? null,
                'fecha_factura' => $datos['fecha_factura'] ?? now()->toDateString(),
                'condicion_pago' => $datos['condicion_pago'] ?? 'credito',
                'metodo_pago_contado' => ($datos['condicion_pago'] ?? 'credito') === 'contado'
                    ? ($datos['metodo_pago_contado'] ?? 'efectivo')
                    : null,
                'subtotal' => $subtotal,
                'descuento' => $descuento,
                'gastos_envio' => $envio,
                'impuestos' => $impuestos,
                'total' => $total,
                'estado' => FacturaCompra::ESTADO_REGISTRADA,
                'notas' => $datos['notas'] ?? null,
                'usuario_id' => $usuarioId ?? auth()->id(),
            ]);

            // LÍNEAS + INVENTARIO + LOTES + KÁRDEX
            foreach ($lineas as $linea) {
                $articulo = Articulo::lockForUpdate()->findOrFail($linea['id']);

                if ($articulo->type === 'servicio') {
                    throw new \Exception('El artículo "'.$articulo->producto.'" es un servicio y no puede comprarse.');
                }

                $lineaDb = $factura->lineas()->create([
                    'item_id' => $articulo->id,
                    'descripcion' => $articulo->producto,
                    'cantidad' => $linea['cantidad'],
                    'costo_unitario' => round($linea['costo_unitario'], 6),
                    'costo_unitario_final' => $linea['costo_unitario_final'],
                    'impuesto_porcentaje' => $linea['impuesto_porcentaje'],
                    'impuesto_monto' => $linea['impuesto_monto'],
                    'valor_neto' => $linea['valor_neto'],
                    'total_linea' => round($linea['valor_neto'] + $linea['impuesto_monto'], 2),
                    'lote' => $linea['lote'] ?? null,
                    'fecha_vencimiento' => $linea['fecha_vencimiento'] ?? null,
                ]);

                $this->ingresarStock($articulo, $linea, $lineaDb, $factura, $usuarioId);
            }

            // CUENTA POR PAGAR: el pasivo del proveedor con sus días de crédito
            if ($factura->condicion_pago === 'credito') {
                $diasCredito = (int) ($proveedor->credit_days ?: 30);
                $cxp = CuentaPorPagar::create([
                    'provider_id' => $proveedor->id,
                    'purchase_invoice_id' => $factura->id,
                    'numero_documento' => $factura->numero_documento,
                    'fecha_emision' => $factura->fecha_factura,
                    'fecha_vencimiento' => $factura->fecha_factura->copy()->addDays($diasCredito),
                    'monto_original' => $total,
                    'saldo_pendiente' => $total,
                    'estado' => 'pendiente',
                ]);
                $factura->update(['cuenta_por_pagar_id' => $cxp->id]);
            }

            // MOTOR CONTABLE: asiento COM-* (cuenta con el IVA de compra)
            $asiento = (new ContabilidadService)->contabilizarFacturaCompra($factura);
            $factura->update(['asiento_contable_id' => $asiento->id]);

            return $factura->fresh(['lineas']);
        });
    }

    /**
     * ANULACIÓN DE FACTURA DE COMPRA (§5.3: reversión, nunca borrado).
     * Bloquea si la CxP tiene abonos o si los lotes de la compra ya tuvieron
     * consumos; revierte inventario, lotes, kárdex, CxP y genera el asiento
     * espejo REINV-COM-*.
     */
    public function anularFactura(int $facturaId, string $motivo, ?int $usuarioId = null): FacturaCompra
    {
        return DB::transaction(function () use ($facturaId, $motivo, $usuarioId) {
            $factura = FacturaCompra::lockForUpdate()
                ->with(['lineas', 'cuentaPorPagar'])
                ->findOrFail($facturaId);

            if ($factura->estado === FacturaCompra::ESTADO_ANULADA) {
                throw new \Exception('Esta factura de compra ya está anulada.');
            }

            $cxp = $factura->cuentaPorPagar;
            if ($cxp && $cxp->pagos()->exists()) {
                throw new \Exception('No se puede anular: la cuenta por pagar tiene abonos registrados. Corrige o revierte los pagos primero.');
            }

            // Validaciones ANTES de mutar: lotes intactos y stock suficiente
            foreach ($factura->lineas as $linea) {
                $articulo = Articulo::lockForUpdate()->find($linea->item_id);
                if (! $articulo) {
                    continue; // artículo eliminado del catálogo: nada físico que revertir
                }

                $esFraccionable = $articulo->is_fractionable && (float) $articulo->total_volume > 0;
                $nuevaExistencia = (float) $articulo->existencia_actual - (float) $linea->cantidad;
                $nuevoVolumen = $esFraccionable
                    ? (float) $articulo->current_volume - ($linea->cantidad * (float) $articulo->total_volume)
                    : 0.0;

                if ($nuevaExistencia < -0.0001 || ($esFraccionable && $nuevoVolumen < -0.0001)) {
                    throw new \Exception('No se puede anular: el stock de "'.$articulo->producto.'" ya fue consumido. Registra una merma o devolución en su lugar.');
                }

                foreach ($linea->lotes()->lockForUpdate()->get() as $lote) {
                    if ((float) $lote->cantidad_disponible < (float) $lote->cantidad_entrada - 0.0001) {
                        throw new \Exception('No se puede anular: el lote '.$lote->lote.' ya tuvo consumos. Registra una merma o devolución en su lugar.');
                    }
                }
            }

            // REVERSIÓN DE INVENTARIO + KÁRDEX + LOTES
            foreach ($factura->lineas as $linea) {
                $articulo = Articulo::lockForUpdate()->find($linea->item_id);
                if (! $articulo) {
                    continue;
                }

                $esFraccionable = $articulo->is_fractionable && (float) $articulo->total_volume > 0;

                $articulo->existencia_actual = (float) $articulo->existencia_actual - (float) $linea->cantidad;
                if ($esFraccionable) {
                    $articulo->current_volume -= $linea->cantidad * (float) $articulo->total_volume;
                }
                $articulo->save();

                // Los lotes se conservan como auditoría; quedan agotados
                foreach ($linea->lotes()->get() as $lote) {
                    $lote->cantidad_disponible = 0;
                    $lote->save();
                }

                StockLedger::registrar(
                    $articulo,
                    'salida',
                    StockLedger::ORIGEN_ANULACION_COMPRA,
                    -(float) $linea->cantidad,
                    $esFraccionable ? -(float) $linea->cantidad * (float) $articulo->total_volume : null,
                    (float) $linea->costo_unitario_final,
                    'factura_compra',
                    $factura->id,
                    $usuarioId ?? auth()->id(),
                    'Anulación de compra: '.$motivo,
                );
            }

            // CxP: anulada (el estado revierte la obligación sin borrarla)
            if ($cxp) {
                $cxp->update(['estado' => 'anulada', 'saldo_pendiente' => 0]);
            }

            // ASIENTO ESPEJO REINV-COM-*
            (new ContabilidadService)->contabilizarAnulacionCompra($factura, $motivo, $usuarioId);

            $factura->update(['estado' => FacturaCompra::ESTADO_ANULADA]);

            return $factura->fresh();
        });
    }

    /**
     * Valora las líneas del request: impuesto por línea y valor comprado.
     */
    protected function valorarLineas(array $lineasRequest): array
    {
        if (count($lineasRequest) === 0) {
            throw new \Exception('La compra no tiene líneas de artículos.');
        }

        $lineas = [];

        foreach ($lineasRequest as $fila) {
            $cantidad = round((float) ($fila['cantidad'] ?? 0), 4);
            $costo = round((float) ($fila['costo'] ?? $fila['costo_unitario'] ?? 0), 6);

            if ($cantidad <= 0) {
                throw new \Exception('Cada línea requiere una cantidad mayor que cero.');
            }
            if ($costo < 0) {
                throw new \Exception('El costo unitario no puede ser negativo.');
            }

            $impuestoPorcentaje = round((float) ($fila['impuesto_porcentaje'] ?? 0), 2);
            $valorComprado = round($cantidad * $costo, 2);

            $lineas[] = [
                'id' => (int) $fila['id'],
                'cantidad' => $cantidad,
                'costo_unitario' => $costo,
                'impuesto_porcentaje' => $impuestoPorcentaje,
                'valor_comprado' => $valorComprado,
                'impuesto_monto' => round($valorComprado * $impuestoPorcentaje / 100, 2),
                'lote' => $fila['lote'] ?? null,
                'fecha_vencimiento' => $fila['fecha_vencimiento'] ?? null,
            ];
        }

        return $lineas;
    }

    /**
     * Ingresa el stock de una línea: costo promedio móvil, contadores físicos,
     * lote FIFO y entrada al kárdex inmutable.
     */
    protected function ingresarStock(Articulo $articulo, array $linea, $lineaDb, FacturaCompra $factura, ?int $usuarioId): void
    {
        $cantidad = (float) $linea['cantidad'];
        $costoFinal = (float) $linea['costo_unitario_final'];
        $esFraccionable = $articulo->is_fractionable && (float) $articulo->total_volume > 0;

        // COSTO PROMEDIO MÓVIL (por frasco/unidad), mismo concepto del motor de divisas
        $stockAnterior = (float) $articulo->existencia_actual;
        $promedioAnterior = (float) $articulo->costo_promedio;
        $nuevaExistencia = $stockAnterior + $cantidad;

        if ($nuevaExistencia > 0) {
            $articulo->costo_promedio = round(($stockAnterior * $promedioAnterior + $cantidad * $costoFinal) / $nuevaExistencia, 6);
        } else {
            $articulo->costo_promedio = round($costoFinal, 6);
        }

        $articulo->existencia_actual = $nuevaExistencia;
        if ($esFraccionable) {
            $articulo->current_volume += $cantidad * (float) $articulo->total_volume;
        }
        $articulo->save();

        // LOTE FIFO con caducidad y trazabilidad a la línea de compra
        Lote::create([
            'item_id' => $articulo->id,
            'purchase_invoice_line_id' => $lineaDb->id,
            'lote' => $linea['lote'] ?: ('L'.date('ymd')),
            'fecha_entrada' => $factura->fecha_factura,
            'fecha_vencimiento' => $linea['fecha_vencimiento'] ?? null,
            'cantidad_entrada' => $cantidad,
            'cantidad_disponible' => $cantidad,
            'costo_unitario' => $costoFinal,
        ]);

        // KÁRDEX INMUTABLE: entrada valorada al costo landed con saldo corrido
        StockLedger::registrar(
            $articulo,
            'entrada',
            StockLedger::ORIGEN_COMPRA,
            $cantidad,
            $esFraccionable ? $cantidad * (float) $articulo->total_volume : null,
            $costoFinal,
            'factura_compra',
            $factura->id,
            $usuarioId ?? auth()->id(),
        );
    }
}
