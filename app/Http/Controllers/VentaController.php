<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\DetalleVenta;
use App\Models\Cita;
use App\Models\Cliente;
use App\Models\ClienteGrupo;
use App\Models\ConsumoVenta;
use App\Models\FormulaServicio;
use App\Models\ServicioVariante;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\ComisionGenerada;
use App\Models\Usuario;
use App\Models\SesionCaja;
use App\Services\InventarioService;
use App\Services\DivisaService;
use App\Services\AprobacionService;
use App\Services\ComisionService;
use App\Services\LibroPagosService;
use App\Services\NotificationService;
use App\Services\AgendaService;
use App\Traits\AutorizaPinSupervisor;
use App\Traits\Notificable;

class VentaController extends Controller
{
    use AutorizaPinSupervisor, Notificable;

    public function __construct(private NotificationService $notifications) {}
    public function facturarCita(Request $request, $id)
    {
        \Illuminate\Support\Facades\Log::info('facturarCita called', ['user_id' => auth()->id(), 'cookies' => $request->cookie()]);

        $cajaAbierta = \App\Models\SesionCaja::where('user_id', auth()->id())
                                              ->where('estado', 'abierta')
                                              ->exists();
        
        if (!$cajaAbierta) {
            return response()->json(['error' => 'ACCESO DENEGADO: Por seguridad, debes abrir tu turno de caja antes de facturar esta cita.'], 403);
        }

        $request->validate([
            'discount' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|in:efectivo,bac,lafise',
            'currency' => 'nullable|string|in:nio,usd',
            'exchange_rate' => 'nullable|numeric|min:1',
        ]);

        \Illuminate\Support\Facades\DB::beginTransaction();

        try {
            $cita = app(AgendaService::class)->validarParaFacturar([(int)$id])->get((int)$id);
            $cita->load('servicio');
            $subtotal = $cita->servicio->price;
            $descuento = $request->discount ?? 0;
            $total = $subtotal - $descuento;

            // FLUJO DE PRE-APROBACIÓN: facturas sensibles (monto sobre el umbral
            // o flag explícito) se RETIENEN sin contabilidad, inventario, divisas
            // ni comisiones hasta que un supervisor las apruebe.
            $requiereAprobacion = app(AprobacionService::class)->requiereAprobacionVenta(
                (float) max(0, $total),
                auth()->user()->role ?? null,
                $request->boolean('requiere_aprobacion')
            );

            $venta = \App\Models\Venta::create([
                'cashier_id' => auth()->id() ?? 1,
                'client_id' => $cita->client_id,
                'subtotal' => $subtotal,
                'discount' => $descuento,
                'total' => $total > 0 ? $total : 0,
                
                // NUEVOS CAMPOS ADAPTADOS (sin tasa fija: se valida desde el request)
                'currency' => $request->currency ?? 'nio',
                'exchange_rate' => $request->currency === 'usd' ? ($request->exchange_rate ?? 1.00) : 1.00,
                'payment_method' => $request->payment_method ?? 'efectivo',
                'appointment_id' => $cita->id,
                
                'status' => $requiereAprobacion ? \App\Models\Venta::ESTADO_PENDIENTE_APROBACION : \App\Models\Venta::ESTADO_PAGADA
            ]);

            // Soporte multi-estilista: un detalle de venta por estilista,
            // dividiendo el precio del servicio entre todos los estilistas asignados.
            $estilistas = $cita->estilistas;
            $cantidadEstilistas = $estilistas->count();

            if ($cantidadEstilistas > 0) {
                $precioPorEstilista = round($cita->servicio->price / $cantidadEstilistas, 2);
                foreach ($estilistas as $estilista) {
                    \App\Models\DetalleVenta::create([
                        'sale_id' => $venta->id,
                        'service_id' => $cita->service_id,
                        'item_id' => null,
                        'stylist_id' => $estilista->id,
                        'quantity' => 1,
                        'unit_price' => $precioPorEstilista,
                    ]);
                }
            } else {
                // Sin estilista asignado: un único detalle sin estilista.
                \App\Models\DetalleVenta::create([
                    'sale_id' => $venta->id,
                    'service_id' => $cita->service_id,
                    'item_id' => null,
                    'stylist_id' => null,
                    'quantity' => 1,
                    'unit_price' => $cita->servicio->price,
                ]);
            }

            // PRE-APROBACIÓN: solo el documento se persiste. La cita se completa,
            // el inventario se descuenta y el asiento se genera al aprobar.
            if ($requiereAprobacion) {
                $venta->update(['payload_aprobacion' => [
                    'payments' => [[
                        'metodo' => $venta->payment_method,
                        'moneda' => $venta->currency,
                        'monto' => (float) $venta->total,
                        'tasa' => (float) $venta->exchange_rate,
                    ]],
                    'change_currency' => null,
                ]]);

                // NOTIFICACIÓN: venta de cita pendiente de aprobación (usa trait Notificable)
                $this->notifyVentaPendiente($venta);

                \Illuminate\Support\Facades\DB::commit();

                return response()->json([
                    'message' => 'Factura retenida para aprobación de un supervisor. No afecta contabilidad hasta su aprobación.',
                    'sale_id' => $venta->id,
                    'notificaciones' => [],
                ], 201);
            }

            app(AgendaService::class)->completarDesdeVenta([$cita->id]);

            $venta->load('detalles');

            // Consumo de insumos fraccionados del servicio facturado (fórmulas)
            (new InventarioService())->descontarPorVenta($venta);

            // Multimoneda: factura de servicio cobrada en USD en efectivo aumenta el inventario de dólares
            if (($venta->currency === 'usd' || $venta->currency === 'dolar') && $venta->payment_method === 'efectivo') {
                $cajaActiva = SesionCaja::where('user_id', auth()->id())->where('estado', 'abierta')->first();
                if ($cajaActiva) {
                    (new DivisaService())->ingresarDivisas(
                        'USD',
                        'App\Models\SesionCaja',
                        $cajaActiva->id,
                        (float) $venta->total,
                        (float) $venta->exchange_rate
                    );
                }
            }

            $contabilidad = new \App\Services\ContabilidadService();
            $contabilidad->contabilizarVenta($venta);
            app(ComisionService::class)->registrarPorVenta($venta);
            $notificaciones = $contabilidad->notificaciones();

            \Illuminate\Support\Facades\DB::commit();

            return response()->json([
                'message' => 'Factura generada y contabilizada con éxito',
                'notificaciones' => $notificaciones,
            ]);

        } catch (\App\Exceptions\ContabilidadException $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            Log::warning('Venta rechazada por regla contable', ['exception' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            Log::error('Error al facturar cita', [
                'appointment_id' => $id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Error al generar la factura. Contacte al administrador.'], 500);
        }
    }

    public function store(Request $request)
    {
        \Illuminate\Support\Facades\Log::info('Venta store llamada', [
            'user_id' => auth()->id(),
            'headers' => $request->headers->all(),
            'cookies' => $request->cookie(),
        ]);

        $cajaAbierta = \App\Models\SesionCaja::where('user_id', auth()->id())
                                              ->where('estado', 'abierta')
                                              ->exists();
        
        if (!$cajaAbierta) {
            return response()->json(['error' => 'ACCESO DENEGADO: Por seguridad, debes abrir tu turno de caja inicializando el fondo antes de poder cobrar.'], 403);
        }

        $request->validate([
            'cart' => 'required|array',
            // nullable: si el POS omite el descuento, se aplica el % del grupo del cliente
            'discount' => 'nullable|numeric|min:0',
            'cart.*.variant_id' => 'nullable|integer',
            'cart.*.appointment_id' => 'nullable|integer|exists:appointments,id',
            // BOM DINÁMICO: consumo real editado por recepción antes de facturar
            'cart.*.consumo' => 'nullable|array',
            'cart.*.consumo.*.item_id' => 'required|integer|exists:items,id',
            'cart.*.consumo.*.cantidad' => 'required|numeric|min:0',
            // PAGOS MIXTOS: arreglo de pagos. Si no viene, se usa el pago único legacy.
            'payments' => 'nullable|array',
            'payments.*.metodo' => 'required|in:efectivo,bac,lafise',
            'payments.*.moneda' => 'required|in:nio,usd',
            'payments.*.monto' => 'required|numeric|min:0.01',
            'payments.*.tasa' => 'required|numeric|min:1',
            // Legacy (un solo pago)
            'payment_method' => 'nullable|string|in:efectivo,bac,lafise',
            'currency' => 'nullable|string|in:nio,usd',
            'exchange_rate' => 'nullable|numeric|min:1',
            // Vuelto multimoneda: moneda en la que se entrega el cambio al cliente
            'change_currency' => 'nullable|in:nio,usd',
            'client_id' => 'nullable|integer|exists:clients,id',
            // CICLO DE VIDA: pagar (mostrador) | cotizar (borrador sin efectos) | credito (emitida CxC)
            'action' => 'nullable|in:pagar,cotizar,credito',
            // Transformar/actualizar una cotización existente (mismo documento, trazable)
            'draft_id' => 'nullable|integer|exists:sales,id',
            // PRE-APROBACIÓN: fuerza la retención de la venta para supervisión
            'requiere_aprobacion' => 'nullable|boolean',
        ]);

        $accion = $request->action ?? 'pagar';

        if ($accion === 'credito' && !$request->client_id) {
            return response()->json([
                'error' => 'Una venta a crédito (por cobrar) requiere asignar un cliente para documentar la CxC.',
            ], 422);
        }

        // PAGOS MIXTOS: normalizamos el arreglo de pagos (compatibilidad con pago único legacy)
        $pagosRaw = ($request->has('payments') && is_array($request->payments) && count($request->payments) > 0)
            ? $request->payments
            : [[
                'metodo' => $request->payment_method ?? 'efectivo',
                'moneda' => $request->currency ?? 'nio',
                'monto' => 0, // se completa tras calcular el total
                'tasa' => $request->exchange_rate ?? 1,
            ]];

        $primario = $pagosRaw[0];
        $paymentMethod = $primario['metodo'];
        $currency = $primario['moneda'];
        $exchangeRate = (float) $primario['tasa'];

        \Illuminate\Support\Facades\DB::beginTransaction();

        try {
            // Antes de clientes/ventas: adquirir todos los mutex de agenda en orden.
            $citasDelCarrito = collect($request->cart)->pluck('appointment_id')->filter()->map(fn($id) => (int)$id)->all();
            $citasValidadas = $citasDelCarrito;
            if ($request->draft_id) {
                $citaDelBorrador = Venta::whereKey($request->draft_id)->value('appointment_id');
                if ($citaDelBorrador) $citasValidadas[] = (int)$citaDelBorrador;
            }
            // sales conserva UN appointment_id: no perder vínculos que luego
            // deban protegerse al cancelar o aprobar el documento.
            if (count(array_unique($citasValidadas)) > 1) {
                throw new \App\Exceptions\ContabilidadException('Una venta solo puede vincularse a una cita. Facture las citas por separado.');
            }
            if ($accion !== 'cotizar') app(AgendaService::class)->validarParaFacturar($citasValidadas);
            $subtotal = collect($request->cart)->sum(function($item) {
                return $item['precio_c'] * $item['quantity'];
            });

            // FIDELIZACIÓN: si el POS no envía descuento manual, se aplica el %
            // del grupo del cliente (VIP/Mayorista). Con descuento explícito,
            // manda el capturado en pantalla.
            $descuento = $request->filled('discount')
                ? (float) $request->discount
                : $this->descuentoPorGrupo($request->client_id, $subtotal);

            $total = $subtotal - $descuento;

            // FLUJO DE PRE-APROBACIÓN: ventas de mostrador sensibles (monto sobre
            // el umbral de config('salon.aprobaciones') o flag explícito) creadas
            // por un cajero NO supervisor se RETIENEN: documento sin contabilidad,
            // sin inventario, sin libro de pagos y sin comisiones hasta que un
            // supervisor (Álvaro/Administrador) las apruebe o rechace.
            $requiereAprobacion = $accion === 'pagar' && app(AprobacionService::class)->requiereAprobacionVenta(
                (float) max(0, $total),
                auth()->user()->role ?? null,
                $request->boolean('requiere_aprobacion')
            );

            $nuevoEstado = match ($accion) {
                'cotizar' => Venta::ESTADO_BORRADOR,
                'credito' => Venta::ESTADO_EMITIDA,
                default => $requiereAprobacion ? Venta::ESTADO_PENDIENTE_APROBACION : Venta::ESTADO_PAGADA,
            };

            // REGLA DE CxC (Fase 1 Administración): una factura a crédito se
            // bloquea si el cliente excede su límite o tiene facturas vencidas.
            // Se evalúa con lockForUpdate DENTRO de la transacción para evitar
            // carreras entre cajas simultáneas.
            if ($nuevoEstado === Venta::ESTADO_EMITIDA && $request->client_id) {
                $cliente = Cliente::where('id', $request->client_id)->lockForUpdate()->firstOrFail();

                if ($motivo = $cliente->motivoBloqueoCredito((float) $total)) {
                    throw new \App\Exceptions\ContabilidadException($motivo);
                }
            }

            $appointmentId = collect($request->cart)->pluck('appointment_id')->filter()->first();

            if ($request->draft_id) {
                // Transformar una cotización guardada: se reutiliza el MISMO documento
                // para conservar la trazabilidad (la cotización se convierte en venta).
                $venta = Venta::lockForUpdate()->findOrFail($request->draft_id);

                if ($accion !== 'cotizar' && $venta->appointment_id && !in_array((int)$venta->appointment_id, $citasValidadas, true)) {
                    throw new \App\Exceptions\ContabilidadException('El vínculo de la cita cambió. Vuelva a consultar la venta.');
                }

                if ($venta->status !== Venta::ESTADO_BORRADOR) {
                    throw new \App\Exceptions\ContabilidadException(
                        'El documento #' . $venta->id . ' ya fue procesado y no puede reutilizarse como cotización.'
                    );
                }

                $venta->detalles()->delete();
                $venta->update([
                    'client_id' => $request->client_id,
                    'subtotal' => $subtotal,
                    'discount' => $descuento,
                    'total' => $total > 0 ? $total : 0,
                    'currency' => $currency,
                    'exchange_rate' => $exchangeRate,
                    'payment_method' => $paymentMethod,
                    'status' => $nuevoEstado,
                ]);
            } else {
                $venta = \App\Models\Venta::create([
                    'cashier_id' => auth()->id() ?? 1,
                    'client_id' => $request->client_id,
                    'appointment_id' => $appointmentId,
                    'subtotal' => $subtotal,
                    'discount' => $descuento,
                    'total' => $total > 0 ? $total : 0,

                    // Método principal derivado del primer pago
                    'currency' => $currency,
                    'exchange_rate' => $exchangeRate,
                    'payment_method' => $paymentMethod,

                    'status' => $nuevoEstado
                ]);
            }

            $detallesCreados = [];
            (new InventarioService)->bloquearCarrito($request->cart);

            foreach ($request->cart as $index => $cartItem) {
                $esServicio = $cartItem['type'] === 'servicio';

                // VARIANTES DE PRECIO: validar que la variante pertenezca al
                // servicio facturado (no confiar en el payload del navegador).
                $variantId = null;
                if ($esServicio && !empty($cartItem['variant_id'])) {
                    $variante = ServicioVariante::find($cartItem['variant_id']);

                    if (!$variante || (int) $variante->service_id !== (int) $cartItem['id']) {
                        throw new \App\Exceptions\ContabilidadException(
                            'La variante seleccionada no pertenece al servicio facturado.'
                        );
                    }

                    $variantId = $variante->id;
                }

                $detallesCreados[$index] = \App\Models\DetalleVenta::create([
                    'sale_id' => $venta->id,
                    'service_id' => $esServicio ? $cartItem['id'] : null,
                    'item_id' => $esServicio ? null : $cartItem['id'],
                    'service_variant_id' => $variantId,
                    'stylist_id' => isset($cartItem['stylist_id']) ? $cartItem['stylist_id'] : null,
                    'quantity' => $cartItem['quantity'],
                    'unit_price' => $cartItem['precio_c']
                ]);

                if (isset($cartItem['appointment_id']) && $accion !== 'cotizar' && !$requiereAprobacion) {
                    app(AgendaService::class)->completarDesdeVenta([(int)$cartItem['appointment_id']]);
                }
            }

            // BOM DINÁMICO: persistir el consumo real editado por recepción
            // (fuente de verdad del descuento de inventario y su reversión).
            $this->registrarConsumosReales($request->cart, $detallesCreados);

            $venta->load('detalles');

            // CICLO DE VIDA - BORRADOR: la cotización NO afecta inventario,
            // contabilidad, divisas, comisiones ni la agenda. Solo documenta
            // la propuesta al cliente (ej. paquete de boda).
            if ($accion === 'cotizar') {
                \Illuminate\Support\Facades\DB::commit();

                return response()->json([
                    'message' => 'Cotización guardada con éxito. No afecta inventario ni contabilidad.',
                    'sale_id' => $venta->id,
                    'notificaciones' => [],
                ], 201);
            }

            // CICLO DE VIDA - PENDIENTE DE APROBACIÓN: la venta queda retenida
            // como documento. El libro de pagos original se conserva en
            // payload_aprobacion y se ejecuta íntegro cuando el supervisor
            // aprueba (AprobacionService), que genera entonces el asiento.
            if ($requiereAprobacion) {
                $venta->update(['payload_aprobacion' => [
                    'payments' => $pagosRaw,
                    'change_currency' => $request->input('change_currency'),
                ]]);

                // NOTIFICACIÓN: venta pendiente de aprobación (usa trait Notificable)
                $this->notifyVentaPendiente($venta);

                \Illuminate\Support\Facades\DB::commit();

                return response()->json([
                    'message' => 'Venta retenida para aprobación de un supervisor. El asiento contable se generará al aprobar.',
                    'sale_id' => $venta->id,
                    'pendiente_aprobacion' => true,
                    'notificaciones' => [],
                ], 201);
            }

            // Descuento de inventario (insumos fraccionados por fórmulas + productos físicos)
            $stockCritico = (new InventarioService())->descontarPorVenta($venta);

            // CICLO DE VIDA - EMITIDA (crédito): afecta inventario y reconoce el
            // ingreso como CxC del cliente (1.1.3). No hay libro de pagos aún;
            // el cobro posterior usa el endpoint /ventas/{id}/cobrar.
            if ($accion === 'credito') {
                $contabilidad = new \App\Services\ContabilidadService();
                $contabilidad->contabilizarVentaCredito($venta);
                app(ComisionService::class)->registrarPorVenta($venta);
                $notificaciones = $contabilidad->notificaciones();

                \Illuminate\Support\Facades\DB::commit();

                return response()->json([
                    'message' => 'Factura emitida a crédito (CxC) y contabilizada con éxito',
                    'sale_id' => $venta->id,
                    'notificaciones' => $notificaciones,
                ], 201);
            }

            // PAGOS MIXTOS: libro de pagos, vuelto multimoneda y divisas físicas
            app(LibroPagosService::class)->registrar(
                $venta,
                $total > 0 ? (float) $total : 0,
                $pagosRaw,
                $exchangeRate,
                $request->input('change_currency')
            );

            $venta->load('detalles', 'pagos');

            $contabilidad = new \App\Services\ContabilidadService();
            $contabilidad->contabilizarVenta($venta);
            app(ComisionService::class)->registrarPorVenta($venta);
            $notificaciones = $contabilidad->notificaciones();

            \Illuminate\Support\Facades\DB::commit();

            return response()->json([
                'message' => 'Venta registrada y contabilizada con éxito', 
                'sale_id' => $venta->id,
                'notificaciones' => $notificaciones,
            ], 201);

        } catch (\App\Exceptions\ContabilidadException $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            Log::warning('Venta rechazada por regla contable', ['exception' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            Log::error('Error al guardar la venta', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return response()->json(['error' => 'Error al guardar la venta. Contacte al administrador.'], 500);
        }
    }

    /**
     * Vista: Mis ventas pendientes de aprobación (para cajeros/recepción).
     * Muestra solo las ventas del usuario actual que están en estado pendiente_aprobacion,
     * pagada (aprobada) o anulada (rechazada).
     */
    public function misAprobaciones()
    {
        $misVentasPendientes = Venta::with(['cliente', 'cajero'])
            ->where('cashier_id', auth()->id())
            ->whereIn('status', [
                Venta::ESTADO_PENDIENTE_APROBACION,
                Venta::ESTADO_PAGADA,
                Venta::ESTADO_ANULADA,
            ])
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('sales.mis-aprobaciones', compact('misVentasPendientes'));
    }

    /**
     * FIDELIZACIÓN (Fase 1): descuento automático por grupo del cliente
     * (VIP/Mayorista) sobre el subtotal. Solo aplica cuando el POS NO envía
     * un descuento explícito; el descuento capturado en pantalla manda.
     */
    private function descuentoPorGrupo($clientId, float $subtotal): float
    {
        if (!$clientId) {
            return 0.0;
        }

        $cliente = Cliente::find($clientId);
        $porcentaje = (float) ($cliente?->grupo?->discount_percent ?? 0);

        return $porcentaje > 0 ? round($subtotal * $porcentaje / 100, 2) : 0.0;
    }

    /**
     * BOM DINÁMICO (Fase 1): persiste el consumo REAL de insumos editado por
     * recepción antes de facturar. Cada registro guarda también el consumo de
     * la fórmula estándar (quantity_standard) para auditar la desviación.
     * Solo se aceptan insumos que pertenecen a la fórmula del servicio;
     * InventarioService usará estos valores como fuente de verdad para
     * descontar inventario y revertirlo exactamente al anular.
     */
    protected function registrarConsumosReales(array $cart, array $detalles): void
    {
        foreach ($cart as $index => $cartItem) {
            $consumos = $cartItem['consumo'] ?? null;

            if (($cartItem['type'] ?? null) !== 'servicio' || !is_array($consumos) || count($consumos) === 0) {
                continue;
            }

            $detalle = $detalles[$index] ?? null;

            if (!$detalle || !$detalle->service_id) {
                continue;
            }

            $formulas = FormulaServicio::where('service_id', $detalle->service_id)
                ->withTrashed()
                ->get()
                ->keyBy('item_id');

            foreach ($consumos as $consumo) {
                $itemId = (int) ($consumo['item_id'] ?? 0);
                $formula = $formulas->get($itemId);

                if (!$formula) {
                    throw new \App\Exceptions\ContabilidadException(
                        'El insumo seleccionado no pertenece a la fórmula del servicio facturado. No se puede registrar el consumo real.'
                    );
                }

                ConsumoVenta::create([
                    'sale_detail_id' => $detalle->id,
                    'item_id' => $itemId,
                    'quantity_standard' => (float) $formula->quantity_used,
                    'quantity_actual' => round((float) ($consumo['cantidad'] ?? 0), 2),
                ]);
            }
        }
    }

    /**
     * COBRO DE FACTURA EMITIDA (crédito): registra el libro de pagos, mueve las
     * divisas físicas, cancela la CxC contable y marca la venta como pagada.
     */
    public function cobrar(Request $request, $id)
    {
        $cajaAbierta = SesionCaja::where('user_id', auth()->id())
                                    ->where('estado', 'abierta')
                                    ->exists();

        if (!$cajaAbierta) {
            return response()->json(['error' => 'ACCESO DENEGADO: debes abrir tu turno de caja antes de registrar un cobro.'], 403);
        }

        $request->validate([
            'payments' => 'required|array|min:1',
            'payments.*.metodo' => 'required|in:efectivo,bac,lafise',
            'payments.*.moneda' => 'required|in:nio,usd',
            'payments.*.monto' => 'required|numeric|min:0.01',
            'payments.*.tasa' => 'required|numeric|min:1',
            'change_currency' => 'nullable|in:nio,usd',
        ]);

        \Illuminate\Support\Facades\DB::beginTransaction();

        try {
            $venta = Venta::where('id', $id)->lockForUpdate()->firstOrFail();

            if ($venta->status !== Venta::ESTADO_EMITIDA) {
                throw new \App\Exceptions\ContabilidadException(
                    'La factura #' . $venta->id . ' no está pendiente de cobro (estado: ' . $venta->status . ').'
                );
            }

            $pagosRaw = $request->payments;
            $primario = $pagosRaw[0];
            $exchangeRate = (float) $primario['tasa'];

            app(LibroPagosService::class)->registrar(
                $venta,
                (float) $venta->total,
                $pagosRaw,
                $exchangeRate,
                $request->input('change_currency')
            );

            // Método principal (informativo) y cambio de estado del documento
            $venta->update([
                'status' => Venta::ESTADO_PAGADA,
                'payment_method' => $primario['metodo'],
                'currency' => $primario['moneda'],
                'exchange_rate' => $exchangeRate,
            ]);

            $venta->load('pagos');

            $contabilidad = new \App\Services\ContabilidadService();
            $contabilidad->contabilizarCobroVenta($venta);
            $notificaciones = $contabilidad->notificaciones();

            \Illuminate\Support\Facades\DB::commit();

            return response()->json([
                'message' => 'Cobro registrado. La factura #' . $venta->id . ' quedó pagada.',
                'sale_id' => $venta->id,
                'notificaciones' => $notificaciones,
            ]);
        } catch (\App\Exceptions\ContabilidadException $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            Log::warning('Cobro rechazado por regla contable', ['exception' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            Log::error('Error al cobrar factura a crédito', [
                'sale_id' => $id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Error al registrar el cobro. Contacte al administrador.'], 500);
        }
    }

    /**
     * ANULACIÓN DE FACTURA (doble confirmación con PIN de supervisor): el
     * documento NO se borra. Cambia a estado anulada, revierte el inventario
     * físico, devuelve los dólares cobrados al cliente y genera asientos de
     * reversión contable. Un cajero puede intentar la anulación, pero SOLO se
     * ejecuta con el PIN de un rol supervisor (Álvaro/Administrador); el PIN
     * propio del cajero no basta (modo estricto del trait).
     */
    public function anular(Request $request, $id)
    {
        $request->validate([
            'motivo' => 'required|string|min:5|max:255',
        ]);

        // DOBLE CONFIRMACIÓN: exige el PIN de un supervisor antes de tocar datos
        $this->autorizarPin($request, soloSupervisor: true);

        \Illuminate\Support\Facades\DB::beginTransaction();

        try {
            $venta = app(AgendaService::class)->bloquearVenta((int)$id, false);
            $venta->load(['detalles', 'pagos']);

            if ($venta->status === Venta::ESTADO_ANULADA) {
                throw new \App\Exceptions\ContabilidadException('La factura #' . $venta->id . ' ya está anulada.');
            }

            // Candado de nómina: si las comisiones de esta venta ya se pagaron,
            // anularla rompería la trazabilidad financiera del pago de planilla.
            $comisionPagada = ComisionGenerada::where('venta_id', $venta->id)
                ->where('estado', 'pagada')
                ->exists();

            if ($comisionPagada) {
                throw new \App\Exceptions\ContabilidadException(
                    'No se puede anular: las comisiones de esta factura ya fueron pagadas en una nómina. ' .
                    'Regulariza primero la nómina correspondiente.'
                );
            }

            $motivo = trim($request->motivo);
            (new InventarioService)->bloquearEfectosVenta($venta);
            $venta->setRelation('pagos', $venta->pagos()->orderBy('id')->lockForUpdate()->get());

            // Estado y evidencia actual: una retención/cotización no autoriza reversas.
            $asientosOriginales = \App\Models\AsientoContable::where('referencia_id', $venta->id)
                ->whereIn('modulo_origen', ['ventas', 'inventario'])->where('numero_asiento', 'not like', 'REV-%')->orderBy('id')->lockForUpdate()->get()->isNotEmpty();
            $sinEfectos = in_array($venta->status, [Venta::ESTADO_BORRADOR, Venta::ESTADO_PENDIENTE_APROBACION], true);
            if ($sinEfectos && ($asientosOriginales || $venta->pagos->isNotEmpty()
                || ComisionGenerada::where('venta_id', $venta->id)->orderBy('id')->lockForUpdate()->get()->isNotEmpty()
                || \App\Models\StockLedger::where('referencia_tipo', 'venta')->where('referencia_id', $venta->id)->orderBy('id')->lockForUpdate()->get()->isNotEmpty())) {
                throw new \App\Exceptions\ContabilidadException('El documento retenido o borrador presenta efectos registrados. Requiere conciliación antes de anular.');
            }

            // Las cotizaciones no generaron efectos: solo cambian de estado
            if (! $sinEfectos) {
                // 1. El inventario físico regresa (unidades y mililitros)
                (new InventarioService())->revertirPorVenta($venta);

                // 2. Divisas: se devuelven al cliente los USD cobrados y reingresa
                //    el vuelto USD entregado. Se usa el turno abierto del cajero
                //    original; si ya cerró, el turno abierto de quien anula.
                $pagosUsd = $venta->pagos->where('moneda', 'usd')->where('metodo', 'efectivo');
                if ($pagosUsd->count() > 0) {
                    $sesionDivisas = SesionCaja::where('user_id', $venta->cashier_id)->where('estado', 'abierta')->first()
                        ?? SesionCaja::where('user_id', auth()->id())->where('estado', 'abierta')->first();

                    if (!$sesionDivisas) {
                        throw new \App\Exceptions\ContabilidadException(
                            'La factura tiene cobros en dólares y no hay un turno de caja abierto ' .
                            'para devolver las divisas físicas. Abre un turno antes de anular.'
                        );
                    }

                    $divisaService = new DivisaService();
                    foreach ($pagosUsd as $pagoUsd) {
                        try {
                            if ($pagoUsd->tipo === 'pago') {
                                $divisaService->egresarDivisas('USD', 'App\Models\SesionCaja', $sesionDivisas->id, (float) $pagoUsd->monto, (float) $pagoUsd->tasa);
                            } else {
                                $divisaService->ingresarDivisas('USD', 'App\Models\SesionCaja', $sesionDivisas->id, (float) $pagoUsd->monto, (float) $pagoUsd->tasa);
                            }
                        } catch (\Exception $e) {
                            throw new \App\Exceptions\ContabilidadException(
                                'No hay suficientes dólares físicos en caja para devolver al cliente. ' .
                                'Regulariza el efectivo USD antes de anular la factura.'
                            );
                        }
                    }
                }

                // 3. Las comisiones pendientes de esta venta quedan anuladas
                ComisionGenerada::where('venta_id', $venta->id)
                    ->where('estado', 'pendiente')
                    ->update(['estado' => 'anulada']);

                // 4. Reversión contable espejo de todos los asientos de la venta
                $contabilidad = new \App\Services\ContabilidadService();
                if ($asientosOriginales) $contabilidad->reversarVenta($venta, $motivo);

                // 5. La cita asociada vuelve a pendiente para permitir refacturar
                if ($venta->appointment_id) {
                    app(AgendaService::class)->restaurarTrasAnulacion((int)$venta->appointment_id);
                }
            }

            $venta->update([
                'status' => Venta::ESTADO_ANULADA,
                'anulada_at' => now(),
                'anulada_por' => auth()->id(),
                'motivo_anulacion' => $motivo,
                'payload_aprobacion' => null,
            ]);

            \Illuminate\Support\Facades\DB::commit();

            return back()->with('success', $sinEfectos
                ? 'Factura #'.$venta->id.' anulada sin movimientos de devolución; no tenía efectos aplicados.'
                : 'Factura #' . $venta->id . ' anulada correctamente. El documento y su reversión quedaron registrados para auditoría.');
        } catch (\App\Exceptions\ContabilidadException $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return back()->with('error', $e->getMessage());
        } catch (\Illuminate\Validation\ValidationException $e) {
            // El PIN inválido debe llegar a la vista como error de validación
            \Illuminate\Support\Facades\DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            Log::error('Error al anular factura', [
                'sale_id' => $id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->with('error', 'Error al anular la factura. Contacte al administrador.');
        }
    }

    /**
     * Documento JSON para hidratar el POS: carga una cotización (borrador) o una
     * factura emitida pendiente de cobro en el carrito del punto de venta.
     * Permite borrador, emitida, pagada y anulada para visualización en "Mis Aprobaciones".
     */
    public function documento($id)
    {
        $venta = Venta::with([
            'detalles.servicio', 'detalles.articulo', 'detalles.consumos.articulo', 'detalles.variante', 'detalles.estilista',
            'cliente', 'cajero', 'pagos', 'anuladaPor', 'aprobadaPor', 'asientosContables.detalles'
        ])->findOrFail($id);

        // Permitir ver detalles de cualquier estado
        $estadosVisibles = [Venta::ESTADO_BORRADOR, Venta::ESTADO_EMITIDA, Venta::ESTADO_PAGADA, Venta::ESTADO_ANULADA];
        
        if (!in_array($venta->status, $estadosVisibles)) {
            abort(404, 'Estado de venta no válido para visualización.');
        }

        // Si es petición API (Accept: application/json), retorna JSON para el POS
        if (request()->wantsJson()) {
            return response()->json([
                'id' => $venta->id,
                'status' => $venta->status,
                'client_id' => $venta->client_id,
                'discount' => (float) $venta->discount,
                'total' => (float) $venta->total,
                'items' => $venta->detalles->map(function ($d) {
                    return [
                        'id' => $d->service_id ?? $d->item_id,
                        'producto' => trim(($d->servicio?->name ?? $d->articulo?->producto ?? 'Artículo')
                            . ($d->variante ? ' (' . $d->variante->name . ')' : '')),
                        'precio_c' => (float) $d->unit_price,
                        'type' => $d->service_id ? 'servicio' : 'producto',
                        'quantity' => (int) $d->quantity,
                        'stylist_id' => $d->stylist_id,
                        'variant_id' => $d->service_variant_id,
                        'consumo' => $d->consumos->map(fn ($c) => [
                            'item_id' => $c->item_id,
                            'cantidad' => (float) $c->quantity_actual,
                        ])->values(),
                    ];
                })->values(),
            ]);
        }

        // Petición web: retorna vista Blade completa
        return view('sales.documento', compact('venta'));
    }

    public function index(Request $request)
    {
        $ventas = Venta::with(['cliente', 'cajero'])
            ->withCount(['pagos as pagos_cobro_count' => function ($q) {
                $q->where('tipo', 'pago');
            }])
            ->when($request->estado && in_array($request->estado, Venta::ESTADOS), function ($q) use ($request) {
                $q->where('status', $request->estado);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        // El botón Anular abre el modal con doble confirmación por PIN: los
        // roles con acceso a la ruta pueden intentar; el PIN supervisor decide.
        $puedeAnular = in_array(auth()->user()->role, ['admin', 'alvaro', 'recepcion', 'contador']);
        $puedeAprobar = in_array(auth()->user()->role, ['alvaro', 'admin']);

        return view('sales.index', compact('ventas', 'puedeAnular', 'puedeAprobar'));
    }

    public function ticket($id)
    {
        $venta = Venta::with(['cliente', 'cajero', 'detalles.servicio', 'detalles.articulo', 'detalles.estilista', 'pagos', 'anuladaPor'])->findOrFail($id);
        return view('sales.ticket', compact('venta'));
    }
}
