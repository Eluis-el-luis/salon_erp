<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Articulo;
use App\Models\Proveedor;
use App\Models\CuentaPorPagar;
use App\Models\Lote;
use App\Models\DetalleVenta;
use App\Models\Merma;
use App\Services\ContabilidadService;
use App\Services\InventarioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\KardexExport;
use Barryvdh\DomPDF\Facade\Pdf;

class InventarioController extends Controller
{
    // 1. Leer: Mostrar la tabla de inventario
    public function index()
    {
        
        $articulos = Articulo::where('type', '!=', 'servicio')
                        ->orderBy('producto', 'asc')
                        ->get();
        $proveedores = Proveedor::all();

        return view('inventory.index', compact('articulos', 'proveedores'));
    }

    public function store(Request $request)
    {
        // Validamos usando los nombres reales de las columnas
        $request->validate([
            'producto' => 'required|string|max:255',
            'type' => 'required|string', 
            'precio_c' => 'required|numeric|min:0',
            'existencia_actual' => 'required|integer|min:0',
        ]);

        Articulo::create($request->all());

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

        $articulo = Articulo::findOrFail($id);
        $articulo->update($request->all());

        return response()->json(['message' => 'Producto actualizado correctamente']);
    }

    // 4. Eliminar: Quitar un producto del catálogo
    public function destroy($id)
    {
        $articulo = Articulo::findOrFail($id);
        $articulo->delete();

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
            // 1. ACTUALIZAR EL INVENTARIO FÍSICO + COSTO PROMEDIO MÓVIL + LOTES (FIFO)
            foreach ($request->articulos as $linea) {
                $articulo = Articulo::findOrFail($linea['id']);
                $cantidad = (float) $linea['cantidad'];
                $costoUnitario = (float) ($linea['costo'] ?? $linea['precio_c'] ?? $articulo->precio_c);

                // Costo promedio móvil (igual concepto que la Mesa de Cambio)
                $stockAnterior = (float) $articulo->existencia_actual;
                $promedioAnterior = (float) $articulo->costo_promedio;
                $nuevaExistencia = $stockAnterior + $cantidad;

                if ($nuevaExistencia > 0) {
                    $articulo->costo_promedio = round(($stockAnterior * $promedioAnterior + $cantidad * $costoUnitario) / $nuevaExistencia, 6);
                } elseif ($articulo->costo_promedio == 0) {
                    $articulo->costo_promedio = round($costoUnitario, 6);
                }

                // Sumamos las botellas o unidades físicas
                $articulo->existencia_actual = $nuevaExistencia;

                // Si el producto es líquido (ej. Shampoo), sumamos también los mililitros
                if (isset($articulo->total_volume) && $articulo->total_volume > 0) {
                    $articulo->current_volume += ($articulo->total_volume * $cantidad);
                }

                $articulo->save();

                // Lote (FIFO): registrar entrada de mercadería
                Lote::create([
                    'item_id' => $articulo->id,
                    'lote' => $linea['lote'] ?? ('L' . date('ymd')),
                    'fecha_entrada' => Carbon::now(),
                    'fecha_vencimiento' => $linea['fecha_vencimiento'] ?? null,
                    'cantidad_entrada' => $cantidad,
                    'cantidad_disponible' => $cantidad,
                    'costo_unitario' => round($costoUnitario, 6),
                ]);
            }

            $referenciaCompra = time(); // Generamos un folio único para esta transacción

            // 2. GENERAR LA DEUDA SI ES A CRÉDITO (Módulo CxP)
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
            Log::error('Error al procesar compra de inventario', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return response()->json(['error' => 'Error al procesar la compra. Contacte al administrador.'], 500);
        }
    }

    public function createCompra()
    {
        // Traemos todos los proveedores activos
        $proveedores = Proveedor::orderBy('name', 'asc')->get();
        
        // Solo traemos productos físicos o insumos, NO servicios
        $articulos = Articulo::where('type', '!=', 'servicio')->orderBy('producto', 'asc')->get();
        
        return view('inventory.purchases', compact('proveedores', 'articulos'));
    }

    /**
     * Kárdex Físico-Valorado AVANZADO: estado de cuenta del inventario de un producto.
     * Entradas (compras/lotes) - Salidas (ventas y mermas) = saldo final.
     * Incluye tracking por VOLUMEN (ml/oz) y costo promedio móvil.
     */
    public function kardex(Request $request)
    {
        $articulos = Articulo::where('type', '!=', 'servicio')->orderBy('producto', 'asc')->get();
        $articulo = null;
        $movimientos = collect();
        $resumenVolumen = null;

        if ($request->item_id) {
            $articulo = Articulo::with('lotes')->find($request->item_id);

            if ($articulo) {
                // ENTRADAS: lotes (compras)
                foreach ($articulo->lotes as $lote) {
                    $volumenEntrada = $lote->cantidad_entrada * $lote->articulo->total_volume;
                    $movimientos->push([
                        'fecha' => $lote->fecha_entrada,
                        'tipo' => 'entrada',
                        'concepto' => 'Compra / Lote ' . $lote->lote,
                        'cantidad' => (float) $lote->cantidad_entrada,
                        'volumen_ml' => round($volumenEntrada, 2),
                        'costo' => (float) $lote->costo_unitario,
                    ]);
                }

                // SALIDAS: ventas de producto físico
                $detalles = DetalleVenta::with('venta')->where('item_id', $articulo->id)->get();
                foreach ($detalles as $d) {
                    $movimientos->push([
                        'fecha' => $d->venta?->created_at ?? $d->created_at,
                        'tipo' => 'salida',
                        'concepto' => 'Venta #' . $d->sale_id,
                        'cantidad' => (float) -$d->quantity,
                        'volumen_ml' => ($d->quantity * ($d->articulo->total_volume ?? 0)),
                        'costo' => (float) $articulo->costo_promedio,
                    ]);
                }

                // SALIDAS: mermas
                foreach (Merma::where('item_id', $articulo->id)->get() as $m) {
                    $movimientos->push([
                        'fecha' => $m->fecha,
                        'tipo' => 'salida',
                        'concepto' => 'Merma: ' . $m->motivo,
                        'cantidad' => (float) -$m->cantidad,
                        'volumen_ml' => $m->tipo === 'volumen' ? round($m->cantidad * ($m->articulo->total_volume ?? 0), 2) : 0,
                        'costo' => $m->cantidad > 0 ? (float) ($m->valor / $m->cantidad) : 0,
                    ]);
                }

                $movimientos = $movimientos->sortBy('fecha')->values();

                // Resumen de volumen actual
                if ($articulo->is_fractionable && $articulo->total_volume > 0) {
                    $inventarioService = new InventarioService();
                    $resumenVolumen = [
                        'existencia_unidades' => $articulo->existencia_actual,
                        'volumen_total_ml' => round($articulo->existencia_actual * $articulo->total_volume + $articulo->current_volume, 2),
                        'costo_promedio_ml' => round($inventarioService->calcularCostoPromedioVolumen($articulo), 6),
                        'lotes_activos' => $articulo->lotes()->where('cantidad_disponible', '>', 0)->count(),
                    ];
                }
            }
        }

        return view('inventory.kardex', compact('articulos', 'articulo', 'movimientos', 'resumenVolumen'));
    }

    /**
     * Exportar Kárdex a Excel
     */
    public function kardexExcel(Request $request)
    {
        $request->validate(['item_id' => 'required|exists:items,id']);
        
        return Excel::download(new KardexExport($request->item_id), 'kardex_' . now()->format('Ymd_His') . '.xlsx');
    }

    /**
     * Exportar Kárdex a PDF
     */
    public function kardexPdf(Request $request)
    {
        $request->validate(['item_id' => 'required|exists:items,id']);
        
        $articulo = Articulo::with('lotes')->findOrFail($request->item_id);
        
        $movimientos = collect();
        
        // ENTRADAS: lotes (compras)
        foreach ($articulo->lotes as $lote) {
            $volumenEntrada = $lote->cantidad_entrada * $lote->articulo->total_volume;
            $movimientos->push([
                'fecha' => $lote->fecha_entrada,
                'tipo' => 'entrada',
                'concepto' => 'Compra / Lote ' . $lote->lote,
                'cantidad' => (float) $lote->cantidad_entrada,
                'volumen_ml' => round($volumenEntrada, 2),
                'costo' => (float) $lote->costo_unitario,
            ]);
        }

        // SALIDAS: ventas de producto físico
        $detalles = DetalleVenta::with('venta')->where('item_id', $request->item_id)->get();
        foreach ($detalles as $d) {
            $movimientos->push([
                'fecha' => $d->venta?->created_at ?? $d->created_at,
                'tipo' => 'salida',
                'concepto' => 'Venta #' . $d->sale_id,
                'cantidad' => (float) -$d->quantity,
                'volumen_ml' => ($d->quantity * ($d->articulo->total_volume ?? 0)),
                'costo' => (float) $articulo->costo_promedio,
            ]);
        }

        // SALIDAS: mermas
        foreach (Merma::where('item_id', $request->item_id)->get() as $m) {
            $movimientos->push([
                'fecha' => $m->fecha,
                'tipo' => 'salida',
                'concepto' => 'Merma: ' . $m->motivo,
                'cantidad' => (float) -$m->cantidad,
                'volumen_ml' => $m->tipo === 'volumen' ? round($m->cantidad * ($m->articulo->total_volume ?? 0), 2) : 0,
                'costo' => $m->cantidad > 0 ? (float) ($m->valor / $m->cantidad) : 0,
            ]);
        }

        $movimientos = $movimientos->sortBy('fecha')->values();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('inventory.kardex_pdf', compact('articulo', 'movimientos'));
        return $pdf->download('kardex_' . $request->item_id . '_' . now()->format('Ymd_His') . '.pdf');
    }

    /**
     * Alertas de caducidad de lotes
     */
    public function alertasCaducidad(Request $request)
    {
        $dias = $request->query('dias', 30);
        $fechaLimite = Carbon::now()->addDays($dias);
        
        $lotesProximos = Lote::with('articulo')
            ->where('cantidad_disponible', '>', 0)
            ->whereNotNull('fecha_vencimiento')
            ->where('fecha_vencimiento', '<=', Carbon::now()->addDays($dias))
            ->orderBy('fecha_vencimiento', 'asc')
            ->get()
            ->groupBy('articulo_id')
            ->map(function ($lotes, $articuloId) {
                $articulo = $lotes->first()->articulo;
                return [
                    'articulo' => $lotes->first()->articulo,
                    'lotes' => $lotes->map(function ($lote) {
                        return [
                            'lote' => $lote->lote,
                            'fecha_vencimiento' => $lote->fecha_vencimiento->format('d/m/Y'),
                            'dias_restantes' => $lote->fecha_vencimiento->diffInDays(now(), false),
                            'cantidad_disponible' => $lote->cantidad_disponible,
                        ];
                    }),
                ];
            });
        
        return response()->json([
            'alertas' => $lotesProximos->values(),
            'total_alertas' => $lotesProximos->sum('cantidad_disponible'),
        ]);
    }
}