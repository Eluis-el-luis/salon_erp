<?php

namespace App\Services;

use App\Models\AsientoContable;
use App\Models\DetalleAsiento;
use App\Models\CuentaContable;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ContabilidadService
{
    public function contabilizarVenta($sale)
    {
        // 1. Identificar el periodo actual
        $periodo = DB::table('periodos_contables')
                     ->where('estado', 'abierto')
                     ->whereDate('fecha_inicio', '<=', $sale->created_at)
                     ->whereDate('fecha_fin', '>=', $sale->created_at)
                     ->first();

        if (!$periodo) return; // Si no hay periodo fiscal abierto, aborta la contabilidad

        // 2. Extraer las Cuentas Contables del Catálogo
        $cuentaCaja = CuentaContable::where('codigo', '1.1.1')->first()->id; 
        $cuentaBanco = CuentaContable::where('codigo', '1.1.2.1')->first()->id; 
        $cuentaIngresoServicio = CuentaContable::where('codigo', '4.1')->first()->id; 
        $cuentaIngresoProducto = CuentaContable::where('codigo', '4.4')->first()->id; 
        
        $cuentaCostoInsumo = CuentaContable::where('codigo', '5.2')->first()->id; 
        $cuentaInventario = CuentaContable::where('codigo', '1.1.5')->first()->id; 

        $monedaBase = DB::table('monedas')->where('es_base', true)->first()->id;

        // ==========================================
        // ASIENTO 1: RECONOCIMIENTO DE INGRESO
        // ==========================================
        $asientoIngreso = AsientoContable::create([
            'numero_asiento' => 'ING-' . str_pad($sale->id, 5, '0', STR_PAD_LEFT),
            'fecha' => Carbon::now(),
            'concepto' => 'Ingreso por venta de factura #' . $sale->id,
            'modulo_origen' => 'ventas',
            'referencia_id' => $sale->id,
            'periodo_id' => $periodo->id,
            'usuario_id' => auth()->id() ?? 1,
        ]);

        // DEBE: A dónde entra el dinero (Mapeo exacto según el Catálogo Adaptado)
        if ($sale->payment_method == 'bac') {
            $cuentaDestinoFondos = CuentaContable::where('codigo', '1.1.3.02')->first()->id ?? 1; 
        } elseif ($sale->payment_method == 'lafise') {
            $cuentaDestinoFondos = CuentaContable::where('codigo', '1.1.3.01')->first()->id ?? 1; 
        } else {
            // Si es efectivo, verificamos la moneda
            if ($sale->currency == 'usd') {
                $cuentaDestinoFondos = CuentaContable::where('codigo', '1.1.2')->first()->id ?? 1; 
            } else {
                $cuentaDestinoFondos = CuentaContable::where('codigo', '1.1.1')->first()->id ?? 1; 
            }
        }
        
        DetalleAsiento::create([
            'asiento_id' => $asientoIngreso->id,
            'cuenta_id' => $cuentaDestinoFondos,
            'moneda_id' => $monedaBase,
            'debe' => $sale->total,
            'haber' => 0,
            'descripcion' => 'Cobro de factura #' . $sale->id,
        ]);

        // Registrar el Descuento en el DEBE (Contra-ingreso)
        if ($sale->discount > 0) {
            $cuentaDescuento = \App\Models\CuentaContable::where('codigo', '4.5')->first()->id ?? 1; 
            
            \App\Models\DetalleAsiento::create([
                'asiento_id' => $asientoIngreso->id,
                'cuenta_id' => $cuentaDescuento,
                'moneda_id' => $monedaBase,
                'debe' => $sale->discount, 
                'haber' => 0,
                'descripcion' => 'Descuento otorgado en venta #' . $sale->id,
            ]);
        }

        // --- ¡EL CANDADO SE QUITÓ DE AQUÍ! ---

        // HABER: Desglosar el ingreso entre Servicios y Productos
        $totalServicios = 0;
        $totalProductos = 0;
        $costoInsumosConsumidos = 0;

        foreach ($sale->details as $detail) {
            if ($detail->service_id != null) {
                $totalServicios += ($detail->unit_price * $detail->quantity);
                
                // Calcular el costo de los insumos usados en este servicio para el Asiento 2
                $service = \App\Models\Service::with('formulas.item')->find($detail->service_id);
                foreach ($service->formulas as $formula) {
                    $item = $formula->item;
                    $precioPorUnidad = $item->precio_c / $item->total_volume; 
                    $costoInsumosConsumidos += ($precioPorUnidad * $formula->quantity_used * $detail->quantity);
                }
            } else {
                $totalProductos += ($detail->unit_price * $detail->quantity);
            }
        }

        // Registrar el Haber de Servicios
        if ($totalServicios > 0) {
            DetalleAsiento::create([
                'asiento_id' => $asientoIngreso->id,
                'cuenta_id' => $cuentaIngresoServicio,
                'moneda_id' => $monedaBase,
                'debe' => 0,
                'haber' => $totalServicios,
                'descripcion' => 'Ingreso por servicios brindados',
            ]);
        }

        // Registrar el Haber de Productos
        if ($totalProductos > 0) {
            DetalleAsiento::create([
                'asiento_id' => $asientoIngreso->id,
                'cuenta_id' => $cuentaIngresoProducto,
                'moneda_id' => $monedaBase,
                'debe' => 0,
                'haber' => $totalProductos,
                'descripcion' => 'Ingreso por venta directa de productos',
            ]);
        }

        // --- ¡AQUÍ ES EL LUGAR CORRECTO DEL PRIMER CANDADO! ---
        // Ya registramos el DEBE (dinero en caja) y el HABER (servicios/productos).
        $this->validarCuadre($asientoIngreso->id);

        // ==========================================
        // ASIENTO 2: COSTO DE INSUMOS (Condicional)
        // ==========================================
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

            // DEBE: Reconocimiento del Gasto/Costo
            DetalleAsiento::create([
                'asiento_id' => $asientoCosto->id,
                'cuenta_id' => $cuentaCostoInsumo,
                'moneda_id' => $monedaBase,
                'debe' => $costoInsumosConsumidos,
                'haber' => 0,
                'descripcion' => 'Costo de insumos en servicio',
            ]);

            // HABER: Salida del Inventario Físico
            DetalleAsiento::create([
                'asiento_id' => $asientoCosto->id,
                'cuenta_id' => $cuentaInventario,
                'moneda_id' => $monedaBase,
                'debe' => 0,
                'haber' => $costoInsumosConsumidos,
                'descripcion' => 'Baja de inventario por consumo interno',
            ]);

           
            $this->validarCuadre($asientoCosto->id);
        }
    }
    
    public function contabilizarGasto($descripcion, $monto, $cuentaGastoId, $metodoPago)
    {
        // 1. Identificar periodo
        $periodo = DB::table('periodos_contables')->where('estado', 'abierto')->first();
        if (!$periodo) return;

        // 2. Extraer Cuentas
        $cuentaCaja = CuentaContable::where('codigo', '1.1.1')->first()->id;
        $cuentaBanco = CuentaContable::where('codigo', '1.1.2.1')->first()->id;
        $monedaBase = DB::table('monedas')->where('es_base', true)->first()->id;

        $cuentaOrigenFondos = ($metodoPago == 'efectivo') ? $cuentaCaja : $cuentaBanco;

        // 3. Crear Asiento
        $asiento = AsientoContable::create([
            'numero_asiento' => 'GST-' . time(), // Genera un folio único
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
    }

    public function contabilizarArqueo($cashSession)
    {
        if ($cashSession->diferencia == 0) return; // Si cuadra exacto, no hay asiento de ajuste

        $periodo = DB::table('periodos_contables')->where('estado', 'abierto')->first();
        if (!$periodo) return;

        $cuentaCaja = CuentaContable::where('codigo', '1.1.1')->first()->id;
        $monedaBase = DB::table('monedas')->where('es_base', true)->first()->id;

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
            // ES UN FALTANTE (Pérdida para el negocio)
            $cuentaPerdida = CuentaContable::where('codigo', '7.4')->first()->id; // Pérdidas por Robo o Extravío

            DetalleAsiento::create([
                'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaPerdida, 'moneda_id' => $monedaBase,
                'debe' => abs($cashSession->diferencia), 'haber' => 0, 'descripcion' => 'Faltante de caja'
            ]);
            DetalleAsiento::create([
                'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaCaja, 'moneda_id' => $monedaBase,
                'debe' => 0, 'haber' => abs($cashSession->diferencia), 'descripcion' => 'Salida de caja para cuadre físico'
            ]);
        } else {
            // ES UN SOBRANTE (Ingreso extra no justificado)
            $cuentaSobrante = CuentaContable::where('codigo', '7.3')->first()->id; // Lo enviamos a Otros Ingresos/Gastos

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
    }
    
    public function contabilizarCompraInventario($monto, $tipoPago, $referenciaId, $metodoPagoContado = 'efectivo')
    {
        $periodo = DB::table('periodos_contables')->where('estado', 'abierto')->first();
        if (!$periodo) return;

        // Cuentas clave de tu catálogo
        $cuentaInventario = CuentaContable::where('codigo', '1.1.5')->first()->id; // Inventario de Insumos
        $monedaBase = DB::table('monedas')->where('es_base', true)->first()->id;

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
    }

    public function contabilizarPagoProveedor($monto, $referenciaId, $metodoPago = 'efectivo')
    {
        $periodo = DB::table('periodos_contables')->where('estado', 'abierto')->first();
        if (!$periodo) return;

        $cuentaPasivo = CuentaContable::where('codigo', '2.1.1')->first()->id; // Proveedores
        $monedaBase = DB::table('monedas')->where('es_base', true)->first()->id;

        $cuentaCaja = CuentaContable::where('codigo', '1.1.1')->first()->id;
        $cuentaBanco = CuentaContable::where('codigo', '1.1.2.1')->first()->id;
        $cuentaOrigen = ($metodoPago == 'banco') ? $cuentaBanco : $cuentaCaja;

        $asiento = AsientoContable::create([
            'numero_asiento' => 'PXP-' . time(),
            'fecha' => \Carbon\Carbon::now(),
            'concepto' => 'Abono a proveedor (Recibo de Pago #' . $referenciaId . ')',
            'modulo_origen' => 'pagos_proveedor',
            'referencia_id' => $referenciaId,
            'periodo_id' => $periodo->id,
            'usuario_id' => auth()->id() ?? 1,
        ]);

        // DEBE: Disminuye nuestra deuda (Pasivo)
        DetalleAsiento::create([
            'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaPasivo,
            'moneda_id' => $monedaBase, 'debe' => $monto, 'haber' => 0,
            'descripcion' => 'Abono a cuenta por pagar'
        ]);

        // HABER: Sale el dinero (Activo)
        DetalleAsiento::create([
            'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaOrigen,
            'moneda_id' => $monedaBase, 'debe' => 0, 'haber' => $monto,
            'descripcion' => 'Salida de fondos por pago a proveedor'
        ]);

        $this->validarCuadre($asiento->id);
    }

    public function contabilizarGastoCajaChica($movimiento)
    {
        $periodo = DB::table('periodos_contables')->where('estado', 'abierto')->first();
        if (!$periodo) return;

        $monedaBase = DB::table('monedas')->where('es_base', true)->first()->id;
        
        // Asumimos que la Caja Chica usa la cuenta de Caja General (1.1.1) o una subcuenta
        $cuentaCaja = CuentaContable::where('codigo', '1.1.1')->first()->id; 

        $asiento = AsientoContable::create([
            'numero_asiento' => 'CCH-' . time(),
            'fecha' => \Carbon\Carbon::now(),
            'concepto' => 'Pago por Caja Chica: ' . $movimiento->descripcion,
            'modulo_origen' => 'caja_chica',
            'referencia_id' => $movimiento->id,
            'periodo_id' => $periodo->id,
            'usuario_id' => auth()->id() ?? 1,
        ]);

        // DEBE: Aumenta el Gasto (La cuenta viene del Tipo de Gasto)
        DetalleAsiento::create([
            'asiento_id' => $asiento->id, 
            'cuenta_id' => $movimiento->tipoGasto->cuenta_contable_id,
            'moneda_id' => $monedaBase, 
            'debe' => $movimiento->monto, 
            'haber' => 0,
            'descripcion' => $movimiento->descripcion
        ]);

        // HABER: Sale el dinero de la Caja (Activo disminuye)
        DetalleAsiento::create([
            'asiento_id' => $asiento->id, 
            'cuenta_id' => $cuentaCaja,
            'moneda_id' => $monedaBase, 
            'debe' => 0, 
            'haber' => $movimiento->monto,
            'descripcion' => 'Salida de efectivo por gasto menor'
        ]);

        $this->validarCuadre($asiento->id);
    }

    public function contabilizarTransferencia($monto, $referenciaId)
    {
        $periodo = DB::table('periodos_contables')->where('estado', 'abierto')->first();
        if (!$periodo) return;

        $monedaBase = DB::table('monedas')->where('es_base', true)->first()->id;
        
        $cuentaCaja = CuentaContable::where('codigo', '1.1.1')->first()->id; // Origen (Sale el dinero)
        $cuentaBanco = CuentaContable::where('codigo', '1.1.2.1')->first()->id; // Destino (Entra el dinero)

        $asiento = AsientoContable::create([
            'numero_asiento' => 'DEP-' . time(),
            'fecha' => \Carbon\Carbon::now(),
            'concepto' => 'Depósito / Transferencia de Caja a Banco (Ref #' . $referenciaId . ')',
            'modulo_origen' => 'transferencias',
            'referencia_id' => $referenciaId,
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
    }

    

    public function contabilizarNomina($payroll, $metodoPago = 'efectivo')
    {
        $periodo = \Illuminate\Support\Facades\DB::table('periodos_contables')->where('estado', 'abierto')->first();
        if (!$periodo) return;

        $monedaBase = \Illuminate\Support\Facades\DB::table('monedas')->where('es_base', true)->first()->id;
        
        // Cuentas del catálogo adaptado[cite: 7]
        $cuentaSueldos = \App\Models\CuentaContable::where('codigo', '6.11')->first()->id ?? 1; // Sueldos y Salarios
        $cuentaComisiones = \App\Models\CuentaContable::where('codigo', '5.3')->first()->id ?? 1; // Comisiones Estilistas
        $cuentaAnticipos = \App\Models\CuentaContable::where('codigo', '1.1.5')->first()->id ?? 1; // Anticipos a Empleados
        
        $cuentaCaja = \App\Models\CuentaContable::where('codigo', '1.1.1')->first()->id ?? 1;
        $cuentaBanco = \App\Models\CuentaContable::where('codigo', '1.1.3')->first()->id ?? 1;
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

        // 1. DEBE: Gasto por Salario Fijo (Si aplica)[cite: 7]
        if ($payroll->active_salary > 0) {
            \App\Models\DetalleAsiento::create([
                'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaSueldos,
                'moneda_id' => $monedaBase, 'debe' => $payroll->active_salary, 'haber' => 0,
                'descripcion' => 'Sueldo base devengado'
            ]);
        }

        // 2. DEBE: Gasto por Comisiones (Si aplica)[cite: 7]
        $totalComisiones = $payroll->services_commission + $payroll->products_commission;
        if ($totalComisiones > 0) {
            \App\Models\DetalleAsiento::create([
                'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaComisiones,
                'moneda_id' => $monedaBase, 'debe' => $totalComisiones, 'haber' => 0,
                'descripcion' => 'Comisiones por ventas devengadas'
            ]);
        }

        // 3. HABER: Recuperación de Anticipos (El Activo disminuye)
        if ($payroll->salary_advances > 0) {
            \App\Models\DetalleAsiento::create([
                'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaAnticipos,
                'moneda_id' => $monedaBase, 'debe' => 0, 'haber' => $payroll->salary_advances,
                'descripcion' => 'Deducción por adelanto de salario'
            ]);
        }

        // 4. HABER: Salida del Dinero Neto[cite: 6]
        if ($payroll->total_to_pay > 0) {
            \App\Models\DetalleAsiento::create([
                'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaOrigen,
                'moneda_id' => $monedaBase, 'debe' => 0, 'haber' => $payroll->total_to_pay,
                'descripcion' => 'Pago neto de nómina'
            ]);
        }
        $this->validarCuadre($asiento->id);
    }

    

    public function contabilizarAdelantoCXC($advance, $metodoPago = 'efectivo')
    {
        $periodo = \Illuminate\Support\Facades\DB::table('periodos_contables')->where('estado', 'abierto')->first();
        if (!$periodo) return;

        $monedaBase = \Illuminate\Support\Facades\DB::table('monedas')->where('es_base', true)->first()->id;

        // 1. Detectar el tipo de Deudor usando el catálogo adaptado
        if ($advance->user_id) {
            $cuentaDeuda = \App\Models\CuentaContable::where('codigo', '1.1.5')->first()->id ?? 1; // Anticipos a Empleados[cite: 7]
        } else {
            $cuentaDeuda = \App\Models\CuentaContable::where('codigo', '1.1.4')->first()->id ?? 1; // Cuentas por Cobrar Clientes[cite: 7]
        }

        // 2. Origen/Destino de los fondos
        $cuentaCaja = \App\Models\CuentaContable::where('codigo', '1.1.1')->first()->id ?? 1;
        $cuentaBanco = \App\Models\CuentaContable::where('codigo', '1.1.3')->first()->id ?? 1;
        $cuentaFondos = ($metodoPago == 'banco') ? $cuentaBanco : $cuentaCaja;

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
            // EL NEGOCIO PRESTA DINERO: Aumenta la deuda (Activo sube), Sale el dinero (Activo baja)
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
            // EL DEUDOR PAGA: Entra el dinero (Activo sube), Disminuye la deuda (Activo baja)
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
    }


    /**
     * Candado de seguridad: Verifica el principio de partida doble.
     * Debe ser llamado al final de cualquier método que genere un asiento.
     */
    public function validarCuadre($asientoId)
    {
        $totales = \Illuminate\Support\Facades\DB::table('detalle_asientos')
            ->where('asiento_id', $asientoId)
            ->selectRaw('SUM(debe) as total_debe, SUM(haber) as total_haber')
            ->first();

        // Usamos round() a 2 decimales para evitar los micro-errores de punto flotante de PHP
        $debe = round($totales->total_debe, 2);
        $haber = round($totales->total_haber, 2);
        $diferencia = round(abs($debe - $haber), 2);

        if ($diferencia > 0) {
            // Esto cancela la transacción completa y evita que la base de datos se corrompa
            throw new \Exception("Asiento descuadrado por C$ {$diferencia}. Debe: C$ {$debe} | Haber: C$ {$haber}");
        }
    }

   

    public function contabilizarOperacionCambio($operacion)
    {
        $periodo = \Illuminate\Support\Facades\DB::table('periodos_contables')->where('estado', 'abierto')->first();
        if (!$periodo) return;

        // Por simplicidad en este paso, asumimos que el salón tiene una cuenta para "Caja NIO" (1.1.1.1) y "Caja USD" (1.1.1.2)
        // Ajusta los códigos según tu catálogo exacto.
        $cuentaCajaNio = \App\Models\CuentaContable::where('codigo', '1.1.1')->first()->id ?? 1; 
        $cuentaCajaUsd = \App\Models\CuentaContable::where('codigo', '1.1.2')->first()->id ?? 1; // Asumiendo 1.1.2 para Caja USD

        $monedaBase = \Illuminate\Support\Facades\DB::table('monedas')->where('es_base', true)->first()->id;

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
            // El Salón COMPRA dólares: Entran USD, Salen NIO
            // Ambos valores se registran en la contabilidad en moneda base (Córdobas) según tu ERD
            $valorEnCordobas = $operacion->monto_destino; // Lo que pagamos en NIO

            \App\Models\DetalleAsiento::create([
                'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaCajaUsd, 'moneda_id' => $monedaBase,
                'debe' => $valorEnCordobas, 'haber' => 0, 'descripcion' => 'Ingreso de divisas (USD ' . $operacion->monto_origen . ' a tasa ' . $operacion->tasa_aplicada . ')'
            ]);

            \App\Models\DetalleAsiento::create([
                'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaCajaNio, 'moneda_id' => $monedaBase,
                'debe' => 0, 'haber' => $valorEnCordobas, 'descripcion' => 'Salida de efectivo (NIO) por compra de divisas'
            ]);
        } else {
            // El Salón VENDE dólares: Entran NIO, Salen USD
            $valorEnCordobas = $operacion->monto_origen; // Lo que recibimos en NIO

            \App\Models\DetalleAsiento::create([
                'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaCajaNio, 'moneda_id' => $monedaBase,
                'debe' => $valorEnCordobas, 'haber' => 0, 'descripcion' => 'Ingreso de efectivo (NIO) por venta de divisas'
            ]);

            \App\Models\DetalleAsiento::create([
                'asiento_id' => $asiento->id, 'cuenta_id' => $cuentaCajaUsd, 'moneda_id' => $monedaBase,
                'debe' => 0, 'haber' => $valorEnCordobas, 'descripcion' => 'Salida de divisas (USD ' . $operacion->monto_destino . ' a tasa ' . $operacion->tasa_aplicada . ')'
            ]);
        }

        // CANDADO DE SEGURIDAD INQUEBRANTABLE
        $this->validarCuadre($asiento->id);
    }
}