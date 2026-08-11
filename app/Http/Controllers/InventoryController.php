<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Item;
use App\Models\Provider;
use App\Models\CuentaPorPagar;
use App\Services\ContabilidadService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class InventoryController extends Controller
{
    // 1. Leer: Mostrar la tabla de inventario
    public function index()
    {
        
        $products = Item::where('type', '!=', 'servicio')
                        ->orderBy('producto', 'asc') // 
                        ->get();
        $providers = Provider::all();

        return view('inventory.index', compact('products', 'providers'));
    }

   public function store(Request $request)
    {
        // Validamos usando los nuevos nombres del Excel
        $request->validate([
            'producto' => 'required|string|max:255',
            'type' => 'required|string', 
            'precio_c' => 'required|numeric|min:0',
            'existencia_actual' => 'required|integer|min:0',
        ]);

        Item::create($request->all());

        return response()->json(['message' => 'Producto agregado exitosamente']);
    }

    // 3. Actualizar: Modificar cualquier dato del producto
    public function update(Request $request, $id)
    {
        $request->validate([
            'producto' => 'required|string|max:255',
            'precio_c' => 'required|numeric|min:0',
            'precio_usd' => 'required|numeric|min:0',
            'existencia_actual' => 'required|integer|min:0',
            'stock_min' => 'required|integer|min:0',
        ]);

        $item = Item::findOrFail($id);
        $item->update($request->all());

        return response()->json(['message' => 'Producto actualizado correctamente']);
    }

    // 4. Eliminar: Quitar un producto del catálogo
    public function destroy($id)
    {
        $item = Item::findOrFail($id);
        $item->delete();

        return response()->json(['message' => 'Producto eliminado del sistema']);
    }

    public function registrarCompra(Request $request)
    {
        $request->validate([
            'provider_id' => 'required|exists:providers,id',
            'tipo_pago' => 'required|in:contado,credito',
            'monto_total' => 'required|numeric|min:1',
            'articulos' => 'required|array', // Los productos que estamos comprando
        ]);

        DB::beginTransaction();

        try {
            // 1. ACTUALIZAR EL INVENTARIO FÍSICO
            foreach ($request->articulos as $articulo) {
                $item = Item::findOrFail($articulo['id']);
                
                // Sumamos las botellas o unidades físicas
                $item->existencia_actual += $articulo['cantidad'];
                
                // Si el producto es líquido (ej. Shampoo), sumamos también los mililitros
                if (isset($item->total_volume) && $item->total_volume > 0) {
                    $item->current_volume += ($item->total_volume * $articulo['cantidad']);
                }
                
                $item->save();
            }

            $referenciaCompra = time(); // Generamos un folio único para esta transacción

            // 2. GENERAR LA DEUDA SI ES A CRÉDITO (Módulo CxP)[cite: 4]
            if ($request->tipo_pago == 'credito') {
                CuentaPorPagar::create([
                    'provider_id' => $request->provider_id,
                    'fecha_emision' => Carbon::now(),
                    'fecha_vencimiento' => Carbon::now()->addDays(30), // Por defecto 30 días, o puedes pedirlo en el Request
                    'monto_original' => $request->monto_total,
                    'saldo_pendiente' => $request->monto_total,
                    'estado' => 'pendiente'
                ]);
            }

            // 3. DISPARAR EL MOTOR CONTABLE
            $contabilidad = new ContabilidadService();
            $contabilidad->contabilizarCompraInventario(
                $request->monto_total, 
                $request->tipo_pago, 
                $referenciaCompra,
                $request->metodo_pago_contado ?? 'efectivo'
            );

            DB::commit();

            return response()->json([
                'message' => 'Compra registrada. Inventario y contabilidad actualizados.'
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Error al procesar la compra: ' . $e->getMessage()], 500);
        }
    }

    public function createCompra()
    {
        // Traemos todos los proveedores activos (asumiendo que tienes una columna activo, si no, quita el where)
        $providers = \App\Models\Provider::orderBy('name', 'asc')->get();
        
        // Solo traemos productos físicos o insumos, NO servicios
        $items = \App\Models\Item::where('type', '!=', 'servicio')->orderBy('producto', 'asc')->get();
        
        return view('inventory.purchases', compact('providers', 'items'));
    }
}