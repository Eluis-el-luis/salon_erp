<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\DetalleVenta;
use App\Models\Cita;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\ComisionGenerada;
use App\Models\Usuario;
use App\Models\SesionCaja;
use App\Services\InventarioService;
use App\Services\DivisaService;

class VentaController extends Controller
{
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

        $cita = \App\Models\Cita::with(['servicio', 'estilistas'])->findOrFail($id);
        \Illuminate\Support\Facades\DB::beginTransaction();

        try {
            $subtotal = $cita->servicio->price;
            $descuento = $request->discount ?? 0;
            $total = $subtotal - $descuento;

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
                
                'status' => 'completada'
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

            $cita->update(['status' => 'completada']);

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
            $this->registrarComisionesVenta($venta);

            \Illuminate\Support\Facades\DB::commit();

            return response()->json(['message' => 'Factura generada y contabilizada con éxito']);

        } catch (\Exception $e) {
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
            'discount' => 'numeric|min:0',
            // VALIDAMOS LAS NUEVAS OPCIONES
            'payment_method' => 'required|string|in:efectivo,bac,lafise',
            'currency' => 'required|string|in:nio,usd',
            'exchange_rate' => 'required|numeric|min:1',
        ]);

        \Illuminate\Support\Facades\DB::beginTransaction();

        try {
            $subtotal = collect($request->cart)->sum(function($item) {
                return $item['precio_c'] * $item['quantity'];
            });
            
            $total = $subtotal - $request->discount;

            $venta = \App\Models\Venta::create([
                'cashier_id' => auth()->id() ?? 1,
                'client_id' => $request->client_id,
                'subtotal' => $subtotal,
                'discount' => $request->discount,
                'total' => $total > 0 ? $total : 0,
                
                // GUARDAMOS LAS OPCIONES SELECCIONADAS
                'currency' => $request->currency,
                'exchange_rate' => $request->exchange_rate,
                'payment_method' => $request->payment_method,
                
                'status' => 'completada'
            ]);

            foreach ($request->cart as $cartItem) {
                $esServicio = $cartItem['type'] === 'servicio';

                \App\Models\DetalleVenta::create([
                    'sale_id' => $venta->id,
                    'service_id' => $esServicio ? $cartItem['id'] : null,
                    'item_id' => $esServicio ? null : $cartItem['id'],
                    'stylist_id' => isset($cartItem['stylist_id']) ? $cartItem['stylist_id'] : null, 
                    'quantity' => $cartItem['quantity'],
                    'unit_price' => $cartItem['precio_c'] 
                ]);

                if (isset($cartItem['appointment_id'])) {
                    \App\Models\Cita::where('id', $cartItem['appointment_id'])
                        ->update(['status' => 'completada']);
                }
            }

            $venta->load('detalles');

            // Descuento de inventario (insumos fraccionados por fórmulas + productos físicos)
            $stockCritico = (new InventarioService())->descontarPorVenta($venta);

            // Multimoneda: una venta cobrada en USD en efectivo aumenta el inventario físico
            // de dólares de la caja, recalculando el costo promedio ponderado.
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
            $this->registrarComisionesVenta($venta);

            \Illuminate\Support\Facades\DB::commit();

            return response()->json([
                'message' => 'Venta registrada y contabilizada con éxito', 
                'sale_id' => $venta->id 
            ], 201);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            Log::error('Error al guardar la venta', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return response()->json(['error' => 'Error al guardar la venta. Contacte al administrador.'], 500);
        }
    }

    public function index()
    {
        $ventas = Venta::with(['cliente', 'cajero'])->orderBy('created_at', 'desc')->get();
        return view('sales.index', compact('ventas'));
    }

    public function ticket($id)
    {
        $venta = Venta::with(['cliente', 'cajero', 'detalles.servicio', 'detalles.articulo', 'detalles.estilista'])->findOrFail($id);
        return view('sales.ticket', compact('venta'));
    }

    /**
     * Calcula y registra las comisiones de los estilistas para una venta específica.
     */
    private function registrarComisionesVenta($venta)
    {
        // Iteramos sobre los detalles de la venta (servicios o productos)
        foreach ($venta->detalles as $detalle) {
            
            // Solo calculamos si hay un estilista asignado al detalle
            if ($detalle->stylist_id) {
                $estilista = clone Usuario::find($detalle->stylist_id);
                
                if ($estilista) {
                    $esquema = $estilista->esquemaActual;
                    
                    // Si tiene un esquema vigente, usamos ese porcentaje. 
                    // Si no, usamos el comision_servicio de su perfil por compatibilidad.
                    $porcentaje = $esquema ? $esquema->porcentaje_comision : ($estilista->comision_servicio ?? 0);

                    if ($porcentaje > 0) {
                        $montoVenta = $detalle->quantity * $detalle->unit_price;
                        $montoComision = $montoVenta * ($porcentaje / 100);

                        ComisionGenerada::create([
                            'empleado_id' => $estilista->id,
                            'venta_id' => $venta->id,
                            'monto_venta' => $montoVenta,
                            'porcentaje_aplicado' => $porcentaje,
                            'monto_comision' => $montoComision,
                            'fecha' => \Carbon\Carbon::now(),
                            'estado' => 'pendiente' // Queda pendiente hasta que se pague en la Planilla
                        ]);
                    }
                }
            }
        }
    }
}