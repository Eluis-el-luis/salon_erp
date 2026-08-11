<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\ComisionGenerada;
use App\Models\User;

class SaleController extends Controller
{
    public function billAppointment(Request $request, $id)
    {
        \Illuminate\Support\Facades\Log::info('billAppointment called', ['user_id' => auth()->id(), 'cookies' => $request->cookie()]);

        $cajaAbierta = \App\Models\CashSession::where('user_id', auth()->id())
                                              ->where('estado', 'abierta')
                                              ->exists();
        
        if (!$cajaAbierta) {
            return response()->json(['error' => 'ACCESO DENEGADO: Por seguridad, debes abrir tu turno de caja antes de facturar esta cita.'], 403);
        }    

        $appointment = \App\Models\Appointment::with('service')->findOrFail($id);
        \Illuminate\Support\Facades\DB::beginTransaction();

        try {
            $subtotal = $appointment->service->price;
            $discount = $request->discount ?? 0;
            $total = $subtotal - $discount;

            $sale = \App\Models\Sale::create([
                'cashier_id' => auth()->id() ?? 1,
                'client_id' => $appointment->client_id,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total > 0 ? $total : 0,
                
                // NUEVOS CAMPOS ADAPTADOS
                'currency' => $request->currency ?? 'nio',
                'exchange_rate' => $request->exchange_rate ?? 36.50,
                'payment_method' => $request->payment_method ?? 'efectivo',
                
                'status' => 'completada'
            ]);

            \App\Models\SaleDetail::create([
                'sale_id' => $sale->id,
                'service_id' => $appointment->service_id,
                'item_id' => null, 
                'stylist_id' => $appointment->stylist_id,
                'quantity' => 1,
                'unit_price' => $appointment->service->price 
            ]);

            $appointment->update(['status' => 'completada']);

            $sale->load('details');
            $contabilidad = new \App\Services\ContabilidadService();
            $contabilidad->contabilizarVenta($sale);
            $this->registrarComisionesVenta($sale);

            \Illuminate\Support\Facades\DB::commit();

            return response()->json(['message' => 'Factura generada y contabilizada con éxito']);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json(['error' => 'Error al generar la factura: ' . $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        \Illuminate\Support\Facades\Log::info('Sale store called', [
            'user_id' => auth()->id(),
            'headers' => $request->headers->all(),
            'cookies' => $request->cookie(),
        ]);

        $cajaAbierta = \App\Models\CashSession::where('user_id', auth()->id())
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

            $sale = \App\Models\Sale::create([
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
                $isService = $cartItem['type'] === 'servicio';

                \App\Models\SaleDetail::create([
                    'sale_id' => $sale->id,
                    'service_id' => $isService ? $cartItem['id'] : null,
                    'item_id' => $isService ? null : $cartItem['id'],
                    'stylist_id' => isset($cartItem['stylist_id']) ? $cartItem['stylist_id'] : null, 
                    'quantity' => $cartItem['quantity'],
                    'unit_price' => $cartItem['precio_c'] 
                ]);

                if (isset($cartItem['appointment_id'])) {
                    \App\Models\Appointment::where('id', $cartItem['appointment_id'])
                        ->update(['status' => 'completada']);
                }
            }

            $sale->load('details');

            foreach ($sale->details as $detail) {
                if ($detail->service_id != null) {
                    $service = \App\Models\Service::with('formulas.item')->find($detail->service_id);
                    foreach ($service->formulas as $formula) {
                        $item = $formula->item;
                        $quantityToDeduct = $formula->quantity_used * $detail->quantity;
                        $item->current_volume -= $quantityToDeduct;

                        while ($item->current_volume <= 0) {
                            if ($item->existencia_actual > 0) {
                                $item->existencia_actual -= 1; 
                                $item->current_volume += $item->total_volume; 
                            } else {
                                break; 
                            }
                        }
                        $item->save();
                    }
                }
                
                if ($detail->item_id != null) {
                    $item = \App\Models\Item::find($detail->item_id);
                    $item->existencia_actual -= $detail->quantity;
                    $item->save();
                }
            }

            $contabilidad = new \App\Services\ContabilidadService();
            $contabilidad->contabilizarVenta($sale);
            $this->registrarComisionesVenta($sale);

            \Illuminate\Support\Facades\DB::commit();

            return response()->json([
                'message' => 'Venta registrada y contabilizada con éxito', 
                'sale_id' => $sale->id 
            ], 201);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json(['error' => 'Error al guardar la venta: ' . $e->getMessage()], 500);
        }
    }

    public function index()
    {
        $sales = Sale::with(['client', 'cashier'])->orderBy('created_at', 'desc')->get();
        return view('sales.index', compact('sales'));
    }

    public function ticket($id)
    {
        $sale = Sale::with(['client', 'cashier', 'details.service', 'details.item', 'details.stylist'])->findOrFail($id);
        return view('sales.ticket', compact('sale'));
    }

    /**
     * Calcula y registra las comisiones de los estilistas para una venta específica.
     */
    private function registrarComisionesVenta($sale)
    {
        // Iteramos sobre los detalles de la venta (servicios o productos)
        foreach ($sale->details as $detail) {
            
            // Solo calculamos si hay un estilista asignado al detalle
            if ($detail->stylist_id) {
                $estilista = clone User::find($detail->stylist_id);
                
                if ($estilista) {
                    $esquema = $estilista->esquemaActual;
                    
                    // Si tiene un esquema vigente, usamos ese porcentaje. 
                    // Si no, usamos el comision_servicio de su perfil por compatibilidad[cite: 5, 6].
                    $porcentaje = $esquema ? $esquema->porcentaje_comision : ($estilista->comision_servicio ?? 0);

                    if ($porcentaje > 0) {
                        $montoVenta = $detail->quantity * $detail->unit_price;
                        $montoComision = $montoVenta * ($porcentaje / 100);

                        ComisionGenerada::create([
                            'empleado_id' => $estilista->id,
                            'venta_id' => $sale->id,
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