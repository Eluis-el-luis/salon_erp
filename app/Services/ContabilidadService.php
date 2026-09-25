<?php

namespace App\Services;

use App\Models\AsientoContable;
use App\Models\DetalleAsiento;
use App\Models\CuentaContable;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Exception;

class ContabilidadService
{
    // Caché en memoria para evitar consultas redundantes a la base de datos
    protected array $cachedCuentas = [];
    protected ?int $monedaBaseId = null;

    /**
     * Obtiene el ID de una cuenta contable mediante su código, utilizando caché local.
     */
    protected function getCuentaIdByCodigo(string $codigo): int
    {
        if (!isset($this->cachedCuentas[$codigo])) {
            $cuenta = CuentaContable::where('codigo', $codigo)->first();
            if (!$cuenta) {
                throw new Exception("Error de Configuración Contable: La cuenta con código [{$codigo}] no existe en el catálogo.");
            }
            $this->cachedCuentas[$codigo] = $cuenta->id;
        }
        return $this->cachedCuentas[$codigo];
    }

    /**
     * Obtiene la moneda base del sistema.
     */
    protected function getMonedaBaseId(): int
    {
        if (is_null($this->monedaBaseId)) {
            $moneda = DB::table('monedas')->where('es_base', true)->first();
            if (!$moneda) {
                throw new Exception("Error del Sistema: No se ha definido una Moneda Base contable.");
            }
            $this->monedaBaseId = $moneda->id;
        }
        return $this->monedaBaseId;
    }

    /**
     * MANDAMIENTO CATÁLOGO ADAPTADO: resuelve la subcuenta de banco específica
     * (BAC -> 1.1.2.2, LAFISE -> 1.1.2.1). "banco" genérico apunta a Lafise.
     */
    protected function getBancoIdPorMetodo(string $metodoPago): int
    {
        if ($metodoPago === 'bac') {
            return $this->getCuentaIdByCodigo('1.1.2.2');
        }
        return $this->getCuentaIdByCodigo('1.1.2.1'); // lafise / banco genérico
    }

    /**
     * MANDAMIENTO CATÁLOGO ADAPTADO: deduce la subcuenta de banco a partir de la
     * CuentaBancaria de destino de una transferencia (BAC/LAFISE).
     */
    protected function getCuentaBancoDestino($transferencia): int
    {
        $codigoBanco = '1.1.2.1'; // Lafise por defecto
        if ($transferencia->destino_tipo === \App\Models\CuentaBancaria::class) {
            $cuenta = \App\Models\CuentaBancaria::with('banco')->find($transferencia->destino_id);
            if ($cuenta && $cuenta->banco && strtoupper($cuenta->banco->codigo) === 'BAC') {
                $codigoBanco = '1.1.2.2';
            }
        }
        return $this->getCuentaIdByCodigo($codigoBanco);
    }

    public function contabilizarVenta($sale)
    {
        // 1. Identificar el periodo actual
        $periodo = DB::table('periodos_contables')
            ->where('estado', 'abierto')
            ->whereDate('fecha_inicio', '<=', $sale->created_at)
            ->whereDate('fecha_fin', '>=', $sale->created_at)
            ->first();

        if (!$periodo) {
            throw new Exception("Operación Cancelada: No existe un periodo contable abierto para la fecha de esta venta.");
        }

        $monedaBase = $this->getMonedaBaseId();
        
        // Determinar factor de conversión bimonetaria
        $tasa = ($sale->currency === 'usd' || $sale->currency === 'dolar') ? $sale->exchange_rate : 1.00;

        // Calcular subtotales brutos por línea para partir correctamente el descuento
        $subtotalBrutoServicios = 0;
        $subtotalBrutoProductos = 0;
        $costoInsumosConsumidos = 0;
        $costoMercaderiaProductos = 0; // Costo de mercadería para productos físicos (cuenta 5.1)

        foreach ($sale->detalles as $detail) {
            $subtotalLinea = $detail->unit_price * $detail->quantity;
            $subtotalLineaNormalizado = $subtotalLinea * $tasa;
            
            if ($detail->service_id != null) {
                $subtotalBrutoServicios += $subtotalLineaNormalizado;
                
                // Costo de insumos del servicio (fórmulas)
                $servicio = \App\Models\Servicio::with('formulas.articulo')->find($detail->service_id);
                if ($servicio) {
                    foreach ($servicio->formulas as $formula) {
                        $item = $formula->articulo;
                        if ($item && $item->total_volume > 0) {
                            $precioPorUnidad = $item->precio_c / $item->total_volume; 
                            $costoInsumosConsumidos += ($precioPorUnidad * $formula->quantity_used * $detail->quantity);
                        }
                    }
                }
            } else {
                $subtotalBrutoProductos += $subtotalLineaNormalizado;
                
                // Costo de mercadería para productos físicos vendidos
                $articulo = \App\Models\Articulo::find($detail->item_id);
                if ($articulo && $articulo->precio_c > 0) {
                    $costoMercaderiaProductos += ($articulo->precio_c * $detail->quantity) * $tasa;
                }
            }
        }

        $subtotalBrutoTotal = $subtotalBrutoServicios + $subtotalBrutoProductos;
        
        // Distribuir el descuento proporcionalmente entre servicios y productos
        $montoDescuentoNormalizado = round($sale->discount * $tasa, 2);
        $descuentoServicios = 0;
        $descuentoProductos = 0;
        
        if ($subtotalBrutoTotal > 0 && $montoDescuentoNormalizado > 0) {
            $descuentoServicios = round(($subtotalBrutoServicios / $subtotalBrutoTotal) * $montoDescuentoNormalizado, 2);
            $descuentoProductos = $montoDescuentoNormalizado - $descuentoServicios;
        }

        // Montos netos por categoría
        $totalServiciosNeto = $subtotalBrutoServicios - $descuentoServicios;
        $totalProductosNeto = $subtotalBrutoProductos - $descuentoProductos;
        
        // Monto total neto que entra a caja/banco
        $montoTotalNetoNormalizado = round($sale->total * $tasa, 2);

        // Iniciar transacción atómica
        DB::beginTransaction();
        try {
            // ASIENTO 1: RECONOCIMIENTO DE INGRESO NETO
            $asientoIngreso = AsientoContable::create([
                'numero_asiento' => 'ING-' . str_pad($sale->id, 5, '0', STR_PAD_LEFT),
                'fecha' => Carbon::now(),
                'concepto' => 'Ingreso por venta de factura #' . $sale->id . ($tasa > 1 ? " (Conversión USD a NIO)" : ""),
                'modulo_origen' => 'ventas',
                'referencia_id' => $sale->id,
                'periodo_id' => $periodo->id,
                'usuario_id' => auth()->id() ?? 1,
            ]);

            // DEBE: Desglose por pagos mixtos (si existen) o pago único legacy
            if ($sale->pagos && $sale->pagos->count() > 0) {
                foreach ($sale->pagos as $pago) {
                    if ($pago->tipo !== 'pago') {
                        continue;
                    }

                    $cuenta = match (true) {
                        $pago->metodo === 'bac' => $this->getCuentaIdByCodigo('1.1.2.2'),
                        $pago->metodo === 'lafise' => $this->getCuentaIdByCodigo('1.1.2.1'),
                        $pago->moneda === 'usd' => $this->getCuentaIdByCodigo('1.1.1.2'),
                        default => $this->getCuentaIdByCodigo('1.1.1'),
                    };

                    DetalleAsiento::create([
                        'asiento_id' => $asientoIngreso->id,
                        'cuenta_id' => $cuenta,
                        'moneda_id' => $monedaBase,
                        'debe' => round((float) $pago->valor_nio, 2),
                        'haber' => 0,
                        'descripcion' => 'Cobro ' . strtoupper($pago->metodo) . ' (' . strtoupper($pago->moneda) . ') factura #' . $sale->id,
                    ]);
                }

                // Vuelto entregado al cliente (reduce la caja)
                $vuelto = round((float) $sale->pagos->where('tipo', 'vuelto')->sum('valor_nio'), 2);
                if ($vuelto > 0) {
                    DetalleAsiento::create([
                        'asiento_id' => $asientoIngreso->id,
                        'cuenta_id' => $this->getCuentaIdByCodigo('1.1.1'),
                        'moneda_id' => $monedaBase,
                        'debe' => 0,
                        'haber' => $vuelto,
                        'descripcion' => 'Vuelto/cambio entregado al cliente factura #' . $sale->id,
                    ]);
                }
            } else {
                // Pago único legacy
                if ($sale->payment_method === 'bac') {
                    $cuentaDestinoFondos = $this->getCuentaIdByCodigo('1.1.2.2');
                } elseif ($sale->payment_method === 'lafise') {
                    $cuentaDestinoFondos = $this->getCuentaIdByCodigo('1.1.2.1');
                } else {
                    $cuentaDestinoFondos = ($sale->currency === 'usd' || $sale->currency === 'dolar')
                        ? $this->getCuentaIdByCodigo('1.1.2')
                        : $this->getCuentaIdByCodigo('1.1.1');
                }

                DetalleAsiento::create([
                    'asiento_id' => $asientoIngreso->id,
                    'cuenta_id' => $cuentaDestinoFondos,
                    'moneda_id' => $monedaBase,
                    'debe' => $montoTotalNetoNormalizado,
                    'haber' => 0,
                    'descripcion' => 'Cobro neto de factura #' . $sale->id,
                ]);
            }

            // HABER: Ingreso por Servicios (neto de descuento)
            if ($totalServiciosNeto > 0) {
                DetalleAsiento::create([
                    'asiento_id' => $asientoIngreso->id,
                    'cuenta_id' => $this->getCuentaIdByCodigo('4.1'),
                    'moneda_id' => $monedaBase,
                    'debe' => 0,
                    'haber' => round($totalServiciosNeto, 2),
                    'descripcion' => 'Ingreso neto por servicios brindados',
                ]);
            }

            // HABER: Ingreso por Productos (neto de descuento)
            if ($totalProductosNeto > 0) {
                DetalleAsiento::create([
                    'asiento_id' => $asientoIngreso->id,
                    'cuenta_id' => $this->getCuentaIdByCodigo('4.4'),
                    'moneda_id' => $monedaBase,
                    'debe' => 0,
                    'haber' => round($totalProductosNeto, 2),
                    'descripcion' => 'Ingreso neto por venta directa de productos',
                ]);
            }

            // DEBE: Descuento sobre Servicios (contra-ingreso 4.5)
            if ($descuentoServicios > 0) {
                DetalleAsiento::create([
                    'asiento_id' => $asientoIngreso->id,
                    'cuenta_id' => $this->getCuentaIdByCodigo('4.5'),
                    'moneda_id' => $monedaBase,
                    'debe' => $descuentoServicios,
                    'haber' => 0,
                    'descripcion' => 'Descuento otorgado en servicios - factura #' . $sale->id,
                ]);
            }

            // DEBE: Descuento sobre Productos (contra-ingreso 4.5)
            if ($descuentoProductos > 0) {
                DetalleAsiento::create([
                    'asiento_id' => $asientoIngreso->id,
                    'cuenta_id' => $this->getCuentaIdByCodigo('4.5'),
                    'moneda_id' => $monedaBase,
                    'debe' => $descuentoProductos,
                    'haber' => 0,
                    'descripcion' => 'Descuento otorgado en productos - factura #' . $sale->id,
                ]);
            }

            $this->validarCuadre($asientoIngreso->id);

            // ASIENTO 2: COSTO DE INSUMOS (Servicios con fórmulas)
            if ($costoInsumosConsumidos > 0) {
                $asientoCosto = AsientoContable::create([
                    'numero_asiento' => 'CST-' . str_pad($sale->id, 5, '0', STR_PAD_LEFT),
                    'fecha' => Carbon::now(),
                    'concepto' => 'Costo de insumos consumidos en factura #' . $sale->id,
                    'modulo_origen' => 'inventario',
                    'referencia_id' => $sale->id,
                    'periodo_id' => $periodo->id,
                    'usuario_id' => auth()->id() ?? 1,
                ]);

                DetalleAsiento::create([
                    'asiento_id' => $asientoCosto->id,
                    'cuenta_id' => $this->getCuentaIdByCodigo('5.2'),
                    'moneda_id' => $monedaBase,
                    'debe' => round($costoInsumosConsumidos, 2),
                    'haber' => 0,
                    'descripcion' => 'Costo de insumos en servicio',
                ]);

                DetalleAsiento::create([
                    'asiento_id' => $asientoCosto->id,
                    'cuenta_id' => $this->getCuentaIdByCodigo('1.1.5'),
                    'moneda_id' => $monedaBase,
                    'debe' => 0,
                    'haber' => round($costoInsumosConsumidos, 2),
                    'descripcion' => 'Baja de inventario por consumo interno',
                ]);

                $this->validarCuadre($asientoCosto->id);
            }

            // ASIENTO 3: COSTO DE MERCADERÍA (Productos físicos vendidos)
            if ($costoMercaderiaProductos > 0) {
                $asientoCostoMercaderia = AsientoContable::create([
                    'numero_asiento' => 'CMV-' . str_pad($sale->id, 5, '0', STR_PAD_LEFT),
                    'fecha' => Carbon::now(),
                    'concepto' => 'Costo de mercadería vendida en factura #' . $sale->id,
                    'modulo_origen' => 'inventario',
                    'referencia_id' => $sale->id,
                    'periodo_id' => $periodo->id,
                    'usuario_id' => auth()->id() ?? 1,
                ]);

                DetalleAsiento::create([
                    'asiento_id' => $asientoCostoMercaderia->id,
                    'cuenta_id' => $this->getCuentaIdByCodigo('5.1'),
                    'moneda_id' => $monedaBase,
                    'debe' => round($costoMercaderiaProductos, 2),
                    'haber' => 0,
                    'descripcion' => 'Costo de productos vendidos',
                ]);

                DetalleAsiento::create([
                    'asiento_id' => $asientoCostoMercaderia->id,
                    'cuenta_id' => $this->getCuentaIdByCodigo('1.1.4'),
                    'moneda_id' => $monedaBase,
                    'debe' => 0,
                    'haber' => round($costoMercaderiaProductos, 2),
                    'descripcion' => 'Baja de inventario de productos',
                ]);

                $this->validarCuadre($asientoCostoMercaderia->id);
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
    
public function contabilizarGasto($descripcion, $monto, $cuentaGastoId, $metodoPago)
    {
        // 1. Identificar periodo
        $periodo = DB::table('periodos_contables')->where('estado', 'abierto')->first();
        if (!$periodo) return;

        // 2. Extraer Cuentas
        $cuentaCaja = $this->getCuentaIdByCodigo('1.1.1');
        $monedaBase = $this->getMonedaBaseId();

        // MANDAMIENTO CATÁLOGO ADAPTADO: subcuenta de banco específica (BAC/LAFISE)
        $cuentaOrigenFondos = ($metodoPago == 'efectivo') ? $cuentaCaja : $this->getBancoIdPorMetodo($metodoPago);

        DB::beginTransaction();
        try {
            // 3. Crear Asiento
            $asiento = AsientoContable::create([
                'numero_asiento' => 'GST-' . time(),
                'fecha' => Carbon::now(),
                'concepto' => $descripcion,
                'modulo_origen' => 'gastos',
                'periodo_id' => $periodo->id,
                'usuario_id' => auth()->id() ?? 1,
            ]);

            // DEBE: El Gasto aumenta (Clase 6 o 7)
            DetalleAsiento::create([
                'asiento_id' => $asiento->id,
                'cuenta_id' => $cuentaGastoId,
                'moneda_id' => $monedaBase,
                'debe' => $monto,
                'haber' => 0,
                'descripcion' => 'Registro de gasto/egreso',
            ]);

            // HABER: El Activo disminuye (Sale dinero de Caja o Banco)
            DetalleAsiento::create([
                'asiento_id' => $asiento->id,
                'cuenta_id' => $cuentaOrigenFondos,
                'moneda_id' => $monedaBase,
                'debe' => 0,
                'haber' => $monto,
                'descripcion' => 'Pago del gasto',
            ]);

            $this->validarCuadre($asiento->id);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function contabilizarArqueo($cashSession)
    {
        if ($cashSession->diferencia == 0) return;

        $periodo = DB::table('periodos_contables')->where('estado', 'abierto')->first();
        if (!$periodo) return;

        $cuentaCaja = CuentaContable::where('codigo', '1.1.1')->first()->id;
        $monedaBase = DB::table('monedas')->where('es_base', true)->first()->id;

        DB::beginTransaction();
        try {
            $asiento = AsientoContable::create([
                'numero_asiento' => 'ARQ-' . str_pad($cashSession->id, 5, '0', STR_PAD_LEFT),
                'fecha' => Carbon::now(),
                'concepto' => 'Ajuste por arqueo de caja (Sesión #' . $cashSession->id . ')',
                'modulo_origen' => 'caja',
                'referencia_id' => $cashSession->id,
                'periodo_id' => $periodo->id,
                'usuario_id' => $cashSession->user_id,
            ]);

            if ($cashSession->diferencia < 0) {
                $cuentaPerdida = CuentaContable::where('codigo', '7.4')->first()->id;

                DetalleAsiento::create([
                    'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaPerdida, 'moneda_id' => $monedaBase,
                    'debe' => abs($cashSession->diferencia), 'haber' => 0, 'descripcion' => 'Faltante de caja'
                ]);
                DetalleAsiento::create([
                    'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaCaja, 'moneda_id' => $monedaBase,
                    'debe' => 0, 'haber' => abs($cashSession->diferencia), 'descripcion' => 'Salida de caja para cuadre físico'
                ]);
            } else {
                $cuentaSobrante = CuentaContable::where('codigo', '7.3')->first()->id;

                DetalleAsiento::create([
                    'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaCaja, 'moneda_id' => $monedaBase,
                    'debe' => abs($cashSession->diferencia), 'haber' => 0, 'descripcion' => 'Entrada a caja por sobrante'
                ]);
                DetalleAsiento::create([
                    'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaSobrante, 'moneda_id' => $monedaBase,
                    'debe' => 0, 'haber' => abs($cashSession->diferencia), 'descripcion' => 'Sobrante de caja'
                ]);
            }
            $this->validarCuadre($asiento->id);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
    
    public function contabilizarCompraInventario($monto, $tipoPago, $referenciaId, $metodoPagoContado = 'efectivo')
    {
        $periodo = DB::table('periodos_contables')->where('estado', 'abierto')->first();
        if (!$periodo) return;

        $cuentaInventario = CuentaContable::where('codigo', '1.1.5')->first()->id;
        $monedaBase = DB::table('monedas')->where('es_base', true)->first()->id;

        DB::beginTransaction();
        try {
            $asiento = AsientoContable::create([
            'numero_asiento' => 'COM-' . time(),
            'fecha' => Carbon::now(),
            'concepto' => 'Compra de mercadería a proveedor (Ref: ' . $referenciaId . ')',
            'modulo_origen' => 'inventario',
            'referencia_id' => $referenciaId,
            'periodo_id' => $periodo->id,
            'usuario_id' => auth()->id() ?? 1,
        ]);

        // 1. DEBE: El inventario del salón aumenta (Activo)
        DetalleAsiento::create([
            'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaInventario,
            'moneda_id' => $monedaBase, 'debe' => $monto, 'haber' => 0,
            'descripcion' => 'Ingreso de mercadería al inventario'
        ]);

        

        // 2. HABER: Origen de los fondos (Deuda o Dinero líquido)
        if ($tipoPago == 'credito') {
            // Aumenta nuestra deuda con el proveedor (Pasivo)
            $cuentaPasivo = CuentaContable::where('codigo', '2.1.1')->first()->id; // CxP Proveedores
            
            DetalleAsiento::create([
                'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaPasivo,
                'moneda_id' => $monedaBase, 'debe' => 0, 'haber' => $monto,
                'descripcion' => 'Cuenta por pagar generada por compra'
            ]);
        } else {
            // Sale dinero de la caja o banco para pagar al contado
            $cuentaCaja = CuentaContable::where('codigo', '1.1.1')->first()->id;
            $cuentaBanco = CuentaContable::where('codigo', '1.1.2.1')->first()->id;
            $cuentaOrigen = ($metodoPagoContado == 'banco') ? $cuentaBanco : $cuentaCaja;
            
            DetalleAsiento::create([
                'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaOrigen,
                'moneda_id' => $monedaBase, 'debe' => 0, 'haber' => $monto,
                'descripcion' => 'Pago de contado por compra de mercadería'
            ]);
        }

        $this->validarCuadre($asiento->id);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function contabilizarPagoProveedor($monto, $referenciaId, $metodoPago = 'efectivo')
    {
        $periodo = DB::table('periodos_contables')->where('estado', 'abierto')->first();
        if (!$periodo) return;

        $cuentaPasivo = CuentaContable::where('codigo', '2.1.1')->first()->id;
        $monedaBase = DB::table('monedas')->where('es_base', true)->first()->id;

        $cuentaCaja = CuentaContable::where('codigo', '1.1.1')->first()->id;
        $cuentaBanco = CuentaContable::where('codigo', '1.1.2.1')->first()->id;
        $cuentaOrigen = ($metodoPago == 'banco') ? $cuentaBanco : $cuentaCaja;

        DB::beginTransaction();
        try {
            $asiento = AsientoContable::create([
                'numero_asiento' => 'PXP-' . time(),
                'fecha' => \Carbon\Carbon::now(),
                'concepto' => 'Abono a proveedor (Recibo de Pago #' . $referenciaId . ')',
                'modulo_origen' => 'pagos_proveedor',
                'referencia_id' => $referenciaId,
                'periodo_id' => $periodo->id,
                'usuario_id' => auth()->id() ?? 1,
            ]);

            DetalleAsiento::create([
                'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaPasivo,
                'moneda_id' => $monedaBase, 'debe' => $monto, 'haber' => 0,
                'descripcion' => 'Abono a cuenta por pagar'
            ]);

            DetalleAsiento::create([
                'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaOrigen,
                'moneda_id' => $monedaBase, 'debe' => 0, 'haber' => $monto,
                'descripcion' => 'Salida de fondos por pago a proveedor'
            ]);

            $this->validarCuadre($asiento->id);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function contabilizarGastoCajaChica($movimiento)
    {
        $periodo = DB::table('periodos_contables')->where('estado', 'abierto')->first();
        if (!$periodo) return;

        $monedaBase = DB::table('monedas')->where('es_base', true)->first()->id;
        $cuentaCaja = CuentaContable::where('codigo', '1.1.1')->first()->id; 

        DB::beginTransaction();
        try {
            $asiento = AsientoContable::create([
                'numero_asiento' => 'CCH-' . time(),
                'fecha' => \Carbon\Carbon::now(),
                'concepto' => 'Pago por Caja Chica: ' . $movimiento->descripcion,
                'modulo_origen' => 'caja_chica',
                'referencia_id' => $movimiento->id,
                'periodo_id' => $periodo->id,
                'usuario_id' => auth()->id() ?? 1,
            ]);

            DetalleAsiento::create([
                'asiento_id' => $asiento->id, 
                'cuenta_id' => $movimiento->tipoGasto->cuenta_contable_id,
                'moneda_id' => $monedaBase, 
                'debe' => $movimiento->monto, 
                'haber' => 0,
                'descripcion' => $movimiento->descripcion
            ]);

            DetalleAsiento::create([
                'asiento_id' => $asiento->id, 
                'cuenta_id' => $cuentaCaja,
                'moneda_id' => $monedaBase, 
                'debe' => 0, 
                'haber' => $movimiento->monto,
                'descripcion' => 'Salida de efectivo por gasto menor'
            ]);

            $this->validarCuadre($asiento->id);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function contabilizarTransferencia($transferencia)
    {
        $periodo = DB::table('periodos_contables')->where('estado', 'abierto')->first();
        if (!$periodo) return;

        $monedaBase = $this->getMonedaBaseId();
        $cuentaCaja = $this->getCuentaIdByCodigo('1.1.1');
        // MANDAMIENTO CATÁLOGO ADAPTADO: subcuenta específica del banco de destino (BAC/LAFISE)
        $cuentaBanco = $this->getCuentaBancoDestino($transferencia);

        $monto = round((float) $transferencia->monto, 2);

        DB::beginTransaction();
        try {
            $asiento = AsientoContable::create([
            'numero_asiento' => 'DEP-' . str_pad($transferencia->id, 5, '0', STR_PAD_LEFT),
            'fecha' => \Carbon\Carbon::now(),
            'concepto' => 'Depósito / Transferencia de Caja a Banco (Ref #' . $transferencia->id . ')',
            'modulo_origen' => 'transferencias',
            'referencia_id' => $transferencia->id,
            'periodo_id' => $periodo->id,
            'usuario_id' => auth()->id() ?? 1,
        ]);

        // DEBE: Entra el dinero al Banco (Aumenta Activo)
        DetalleAsiento::create([
            'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaBanco,
            'moneda_id' => $monedaBase, 'debe' => $monto, 'haber' => 0,
            'descripcion' => 'Ingreso de fondos por depósito de caja'
        ]);

        // HABER: Sale el dinero de la Caja (Disminuye Activo)
        DetalleAsiento::create([
            'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaCaja,
            'moneda_id' => $monedaBase, 'debe' => 0, 'haber' => $monto,
            'descripcion' => 'Salida de efectivo hacia el banco'
        ]);
        $this->validarCuadre($asiento->id);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    

    public function contabilizarNomina($payroll, $metodoPago = 'efectivo')
    {
        $periodo = \Illuminate\Support\Facades\DB::table('periodos_contables')->where('estado', 'abierto')->first();
        if (!$periodo) return;

        $monedaBase = \Illuminate\Support\Facades\DB::table('monedas')->where('es_base', true)->first()->id;
        
        // Cuentas del catálogo adaptado
        $cuentaSueldos = $this->getCuentaIdByCodigo('6.7'); // Sueldos y Salarios
        $cuentaComisiones = $this->getCuentaIdByCodigo('5.3'); // Comisiones Estilistas
        $cuentaAnticipos = $this->getCuentaIdByCodigo('1.1.6'); // Adelantos de Salario
        $cuentaRetenciones = $this->getCuentaIdByCodigo('2.1.5'); // Retenciones por Pagar (INSS/IR)

        $cuentaCaja = $this->getCuentaIdByCodigo('1.1.1');
        // CORRECCIÓN: 1.1.3 era "Cuentas por Cobrar". El banco por defecto es una subcuenta específica (Lafise).
        $cuentaBanco = $this->getCuentaIdByCodigo('1.1.2.1');
        $cuentaOrigen = ($metodoPago == 'banco') ? $cuentaBanco : $cuentaCaja;

        $asiento = \App\Models\AsientoContable::create([
            'numero_asiento' => 'NOM-' . time(),
            'fecha' => \Carbon\Carbon::now(),
            'concepto' => 'Liquidación de nómina empleado: ' . $payroll->user->name,
            'modulo_origen' => 'nomina',
            'referencia_id' => $payroll->id,
            'periodo_id' => $periodo->id,
            'usuario_id' => auth()->id() ?? 1,
        ]);

        DB::beginTransaction();
        try {
            // 1. DEBE: Gasto por Salario Fijo
            if ($payroll->active_salary > 0) {
                \App\Models\DetalleAsiento::create([
                    'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaSueldos,
                    'moneda_id' => $monedaBase, 'debe' => $payroll->active_salary, 'haber' => 0,
                    'descripcion' => 'Sueldo base devengado'
                ]);
            }

            // 2. DEBE: Gasto por Comisiones
            $totalComisiones = $payroll->services_commission + $payroll->products_commission;
            if ($totalComisiones > 0) {
                \App\Models\DetalleAsiento::create([
                    'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaComisiones,
                    'moneda_id' => $monedaBase, 'debe' => $totalComisiones, 'haber' => 0,
                    'descripcion' => 'Comisiones por ventas devengadas'
                ]);
            }

            // 3. HABER: Retenciones por pagar (INSS + IR) - Pasivo 2.1.5
            $retenciones = $payroll->inss_empleado + $payroll->impuesto_renta;
            if ($retenciones > 0) {
                \App\Models\DetalleAsiento::create([
                    'asiento_id' => $asiento->id, 'cuenta_id' => $this->getCuentaIdByCodigo('2.1.5'),
                    'moneda_id' => $monedaBase, 'debe' => 0, 'haber' => $retenciones,
                    'descripcion' => 'Retenciones INSS/IR nómina ' . $payroll->user->name
                ]);
            }

            // 4. HABER: Recuperación de Anticipos (El Activo 1.1.6 disminuye)
            if ($payroll->salary_advances > 0) {
                \App\Models\DetalleAsiento::create([
                    'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaAnticipos,
                    'moneda_id' => $monedaBase, 'debe' => 0, 'haber' => $payroll->salary_advances,
                    'descripcion' => 'Recuperación de adelantos de salario (activo 1.1.6)'
                ]);
            }

            // 5. HABER: Salida del Dinero Neto (Caja/Banco) = total_to_pay (neto a pagar)
            if ($payroll->total_to_pay > 0) {
                \App\Models\DetalleAsiento::create([
                    'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaOrigen,
                    'moneda_id' => $monedaBase, 'debe' => 0, 'haber' => $payroll->total_to_pay,
                    'descripcion' => 'Pago neto de nómina'
                ]);
            }

            $this->validarCuadre($asiento->id);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    

    public function contabilizarAdelantoCXC($advance, $metodoPago = 'efectivo')
    {
        $periodo = \Illuminate\Support\Facades\DB::table('periodos_contables')->where('estado', 'abierto')->first();
        if (!$periodo) return;

        $monedaBase = \Illuminate\Support\Facades\DB::table('monedas')->where('es_base', true)->first()->id;

        // Detectar el tipo de Deudor usando el catálogo adaptado
        if ($advance->user_id) {
            $cuentaDeuda = $this->getCuentaIdByCodigo('1.1.6'); // Anticipos a Empleados
        } else {
            // CORRECCIÓN: 1.1.3 es "Cuentas por Cobrar". Antes apuntaba a 1.1.4 (Inventario).
            $cuentaDeuda = $this->getCuentaIdByCodigo('1.1.3'); // Cuentas por Cobrar Clientes
        }

        $cuentaCaja = $this->getCuentaIdByCodigo('1.1.1');
        // CORRECCIÓN: banco por subcuenta específica (Lafise), no 1.1.3 (CxC).
        $cuentaBanco = $this->getCuentaIdByCodigo('1.1.2.1');
        $cuentaFondos = ($metodoPago == 'banco') ? $cuentaBanco : $cuentaCaja;

        DB::beginTransaction();
        try {
            $asiento = \App\Models\AsientoContable::create([
                'numero_asiento' => 'CXC-' . time(),
                'fecha' => \Carbon\Carbon::parse($advance->date),
                'concepto' => 'Movimiento CXC: ' . $advance->description,
                'modulo_origen' => 'adelantos',
                'referencia_id' => $advance->id,
                'periodo_id' => $periodo->id,
                'usuario_id' => auth()->id() ?? 1,
            ]);

            if ($advance->type == 'debe') {
                \App\Models\DetalleAsiento::create([
                    'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaDeuda,
                    'moneda_id' => $monedaBase, 'debe' => $advance->amount, 'haber' => 0,
                    'descripcion' => 'Incremento de deuda por adelanto/crédito'
                ]);
                \App\Models\DetalleAsiento::create([
                    'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaFondos,
                    'moneda_id' => $monedaBase, 'debe' => 0, 'haber' => $advance->amount,
                    'descripcion' => 'Salida de fondos'
                ]);
            } else {
                \App\Models\DetalleAsiento::create([
                    'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaFondos,
                    'moneda_id' => $monedaBase, 'debe' => $advance->amount, 'haber' => 0,
                    'descripcion' => 'Ingreso de fondos por abono de deudor'
                ]);
                \App\Models\DetalleAsiento::create([
                    'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaDeuda,
                    'moneda_id' => $monedaBase, 'debe' => 0, 'haber' => $advance->amount,
                    'descripcion' => 'Disminución de saldo deudor'
                ]);
            }

            $this->validarCuadre($asiento->id);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }


    /**
     * Candado de seguridad: Verifica el principio de partida doble.
     * Debe ser llamado al final de cualquier método que genere un asiento.
     */
    public function validarCuadre($asientoId)
    {
        $totales = DB::table('detalle_asientos')
            ->where('asiento_id', $asientoId)
            ->selectRaw('SUM(debe) as total_debe, SUM(haber) as total_haber')
            ->first();

        // VALIDACIÓN ESTRICTA DE PARTIDA DOBLE a 2 decimales.
        // Se comparan los valores redondeados al centavo (enteros) para evitar
        // diferencias por precisión de punto flotante. NO se admite tolerancia:
        // el DEBE debe ser EXACTAMENTE igual al HABER.
        $debeCents = (int) round(((float) ($totales->total_debe ?? 0)) * 100);
        $haberCents = (int) round(((float) ($totales->total_haber ?? 0)) * 100);

        if ($debeCents !== $haberCents) {
            $diferencia = abs($debeCents - $haberCents) / 100;
            throw new Exception(
                "Error Crítico de Partida Doble: Asiento contable ID [{$asientoId}] descuadrado por C$ " .
                number_format($diferencia, 2, '.', '') .
                ". (Debe: C$ " . number_format($debeCents / 100, 2, '.', '') .
                " | Haber: C$ " . number_format($haberCents / 100, 2, '.', '') . ")."
            );
        }
    }

    /**
     * MANDAMIENTO: Los retiros del propietario NUNCA son gastos operativos.
     * Se registran contra la contra-cuenta de Patrimonio 3.3 "Retiros del Propietario".
     *
     * DEBE  -> 3.3 Retiros del Propietario (incrementa el contra-capital, reduce el patrimonio)
     * HABER -> Caja (1.1.1) o Banco (1.1.2.1) según el método de pago
     */
    public function contabilizarRetiroPropietario($retiro, $metodoPago = 'efectivo')
    {
        $periodo = DB::table('periodos_contables')
            ->where('estado', 'abierto')
            ->whereDate('fecha_inicio', '<=', $retiro->fecha)
            ->whereDate('fecha_fin', '>=', $retiro->fecha)
            ->first();

        if (!$periodo) {
            throw new Exception("Operación Cancelada: No existe un periodo contable abierto para la fecha del retiro.");
        }

        $monedaBase = $this->getMonedaBaseId();
        $cuentaRetiros = $this->getCuentaIdByCodigo('3.3');
        $cuentaCaja = $this->getCuentaIdByCodigo('1.1.1');
        $cuentaBanco = $this->getCuentaIdByCodigo('1.1.2.1');
        $cuentaOrigen = ($metodoPago == 'banco') ? $cuentaBanco : $cuentaCaja;

        $monto = round((float) $retiro->monto, 2);

        DB::beginTransaction();
        try {
            $asiento = AsientoContable::create([
                'numero_asiento' => 'RET-' . str_pad($retiro->id, 5, '0', STR_PAD_LEFT),
                'fecha' => $retiro->fecha,
                'concepto' => 'Retiro de propietario: ' . $retiro->concepto,
                'modulo_origen' => 'retiros',
                'referencia_id' => $retiro->id,
                'periodo_id' => $periodo->id,
                'usuario_id' => auth()->id() ?? 1,
            ]);

            DetalleAsiento::create([
                'asiento_id' => $asiento->id,
                'cuenta_id' => $cuentaRetiros,
                'moneda_id' => $monedaBase,
                'debe' => $monto,
                'haber' => 0,
                'descripcion' => 'Retiro de propietario (contra patrimonio)',
            ]);

            DetalleAsiento::create([
                'asiento_id' => $asiento->id,
                'cuenta_id' => $cuentaOrigen,
                'moneda_id' => $monedaBase,
                'debe' => 0,
                'haber' => $monto,
                'descripcion' => 'Salida de fondos por retiro del propietario',
            ]);

            $this->validarCuadre($asiento->id);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * MANDAMIENTO DE MERMAS:
     * DEBE -> 6.8 Mermas y Desperdicios de Inventario
     * HABER -> Inventario de Insumos (1.1.5) o Productos (1.1.4) según el artículo
     */
    public function contabilizarMerma($merma, string $codigoInventario)
    {
        $periodo = DB::table('periodos_contables')
            ->where('estado', 'abierto')
            ->whereDate('fecha_inicio', '<=', $merma->fecha)
            ->whereDate('fecha_fin', '>=', $merma->fecha)
            ->first();

        if (!$periodo) {
            throw new Exception("Operación Cancelada: No existe un periodo contable abierto para la fecha de la merma.");
        }

        $monedaBase = $this->getMonedaBaseId();
        $cuentaMerma = $this->getCuentaIdByCodigo('6.8');
        $cuentaInventario = $this->getCuentaIdByCodigo($codigoInventario);
        $monto = round((float) $merma->valor, 2);

        DB::beginTransaction();
        try {
            $asiento = AsientoContable::create([
                'numero_asiento' => 'MER-' . str_pad($merma->id, 5, '0', STR_PAD_LEFT),
                'fecha' => $merma->fecha,
                'concepto' => 'Merma de inventario: ' . $merma->motivo,
                'modulo_origen' => 'mermas',
                'referencia_id' => $merma->id,
                'periodo_id' => $periodo->id,
                'usuario_id' => auth()->id() ?? 1,
            ]);

            DetalleAsiento::create([
                'asiento_id' => $asiento->id,
                'cuenta_id' => $cuentaMerma,
                'moneda_id' => $monedaBase,
                'debe' => $monto,
                'haber' => 0,
                'descripcion' => 'Gasto por merma / desperdicio de inventario',
            ]);

            DetalleAsiento::create([
                'asiento_id' => $asiento->id,
                'cuenta_id' => $cuentaInventario,
                'moneda_id' => $monedaBase,
                'debe' => 0,
                'haber' => $monto,
                'descripcion' => 'Baja física de inventario por merma',
            ]);

            $this->validarCuadre($asiento->id);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function contabilizarOperacionCambio($operacion, $diferencial = 0)
    {
        $periodo = \Illuminate\Support\Facades\DB::table('periodos_contables')->where('estado', 'abierto')->first();
        if (!$periodo) return;

        // CORRECCIÓN B9: cuentas de caja por divisa (subcuentas específicas del catálogo).
        // Caja NIO (Córdobas): 1.1.1.1 — Caja USD: 1.1.1.2 (subcuenta de Caja).
        $cuentaCajaNio = $this->getCuentaIdByCodigo('1.1.1.1');
        $cuentaCajaUsd = $this->getCuentaIdByCodigo('1.1.1.2');

        $monedaBase = \Illuminate\Support\Facades\DB::table('monedas')->where('es_base', true)->first()->id;

        DB::beginTransaction();
        try {
            $asiento = \App\Models\AsientoContable::create([
                'numero_asiento' => 'CAM-' . time(),
                'fecha' => \Carbon\Carbon::parse($operacion->fecha),
                'concepto' => 'Operación de Mesa de Cambio: ' . ucfirst($operacion->tipo) . ' de divisas',
                'modulo_origen' => 'mesa_cambio',
                'referencia_id' => $operacion->id,
                'periodo_id' => $periodo->id,
                'usuario_id' => auth()->id() ?? 1,
            ]);

            if ($operacion->tipo == 'compra') {
                $valorEnCordobas = $operacion->monto_destino;

                \App\Models\DetalleAsiento::create([
                    'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaCajaUsd, 'moneda_id' => $monedaBase,
                    'debe' => $valorEnCordobas, 'haber' => 0, 'descripcion' => 'Ingreso de divisas (USD ' . $operacion->monto_origen . ' a tasa ' . $operacion->tasa_aplicada . ')'
                ]);

                \App\Models\DetalleAsiento::create([
                    'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaCajaNio, 'moneda_id' => $monedaBase,
                    'debe' => 0, 'haber' => $valorEnCordobas, 'descripcion' => 'Salida de efectivo (NIO) por compra de divisas'
                ]);
            } else {
                $valorEnCordobas = $operacion->monto_origen;

                \App\Models\DetalleAsiento::create([
                    'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaCajaNio, 'moneda_id' => $monedaBase,
                    'debe' => $valorEnCordobas, 'haber' => 0, 'descripcion' => 'Ingreso de efectivo (NIO) por venta de divisas'
                ]);

                \App\Models\DetalleAsiento::create([
                    'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaCajaUsd, 'moneda_id' => $monedaBase,
                    'debe' => 0, 'haber' => $valorEnCordobas, 'descripcion' => 'Salida de divisas (USD ' . $operacion->monto_destino . ' a tasa ' . $operacion->tasa_aplicada . ')'
                ]);
            }

            // ASIENTO ADICIONAL: Diferencial Cambiario (Ganancia o Pérdida al vender USD)
            if ($diferencial != 0) {
                $cuentaGananciaPerdida = $diferencial > 0 ? $this->getCuentaIdByCodigo('7.1') : $this->getCuentaIdByCodigo('7.3');
                $signo = $diferencial > 0 ? 'debe' : 'haber';
                $montoAbs = abs($diferencial);

                DetalleAsiento::create([
                    'asiento_id' => $asiento->id,
                    'cuenta_id' => $cuentaGananciaPerdida,
                    'moneda_id' => $monedaBase,
                    'debe' => $diferencial > 0 ? $montoAbs : 0,
                    'haber' => $diferencial > 0 ? 0 : $montoAbs,
                    'descripcion' => $diferencial > 0 ? 'Diferencial cambiario Ganancia por venta de USD' : 'Diferencial cambiario Pérdida por venta de USD',
                ]);
            }

            $this->validarCuadre($asiento->id);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}