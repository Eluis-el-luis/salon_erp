<?php

namespace App\Http\Controllers;

use App\Exports\KardexExport;
use App\Models\Articulo;
use App\Models\Lote;
use App\Models\Proveedor;
use App\Models\StockLedger;
use App\Services\CompraService;
use App\Services\InventarioService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

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
            'condicion_pago' => 'required|in:contado,credito',
            'metodo_pago_contado' => 'nullable|in:efectivo,banco',
            'fecha_factura' => 'nullable|date',
            'numero_documento' => 'nullable|string|max:60',
            'descuento' => 'nullable|numeric|min:0',
            'gastos_envio' => 'nullable|numeric|min:0',
            'articulos' => 'required|array|min:1',
            'articulos.*.id' => 'required|integer|exists:items,id',
            'articulos.*.cantidad' => 'required|numeric|min:0.0001',
            'articulos.*.costo' => 'required|numeric|min:0',
            'articulos.*.impuesto_porcentaje' => 'nullable|numeric|min:0|max:100',
        ]);

        try {
            $factura = (new CompraService)->registrarFactura([
                'provider_id' => (int) $request->provider_id,
                'condicion_pago' => $request->condicion_pago ?? $request->tipo_pago,
                'metodo_pago_contado' => $request->metodo_pago_contado ?? 'efectivo',
                'numero_documento' => $request->numero_documento,
                'fecha_factura' => $request->fecha_factura,
                'descuento' => (float) ($request->descuento ?? 0),
                'gastos_envio' => (float) ($request->gastos_envio ?? 0),
                'notas' => $request->notas,
                'articulos' => $request->articulos,
            ]);

            return response()->json([
                'message' => 'Compra registrada: inventario, kárdex, lotes y contabilidad actualizados.',
                'factura_id' => $factura->id,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error al procesar compra de inventario', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function createCompra()
    {
        // Proveedores activos con sus condiciones de crédito
        $proveedores = Proveedor::where('is_active', true)->orderBy('name', 'asc')->get();

        // Solo productos físicos o insumos, NO servicios, con costo actual
        // de valoración para prellenar el formulario (promedio de lotes).
        $articulos = Articulo::where('type', '!=', 'servicio')->orderBy('producto', 'asc')->get();

        $mapaCostos = Articulo::mapaCostosActuales($articulos->pluck('id')->all());
        foreach ($articulos as $articulo) {
            $articulo->costo_actual = $mapaCostos[$articulo->id]['costo_unitario'] ?? 0;
        }

        return view('inventory.purchases', compact('proveedores', 'articulos'));
    }

    /**
     * APERTURA DE KÁRDEX: fija el saldo inicial de un artículo que existía
     * antes del ledger corporativo. Registro real y auditable; solo aplica
     * si el artículo no tiene movimientos aún.
     */
    public function registrarApertura(Request $request)
    {
        $request->validate(['item_id' => 'required|exists:items,id']);

        $articulo = Articulo::findOrFail($request->item_id);

        DB::beginTransaction();
        try {
            $entrada = StockLedger::registrarApertura($articulo, auth()->id());

            if (! $entrada) {
                DB::rollBack();

                return back()->withErrors(['error' => 'Este artículo ya tiene movimientos en el kárdex; no requiere apertura.']);
            }

            DB::commit();

            return back()->with('success', 'Saldo de apertura registrado en el kárdex.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al registrar apertura de kárdex', ['exception' => $e->getMessage()]);

            return back()->withErrors(['error' => 'No se pudo registrar la apertura. Contacte al administrador.']);
        }
    }

    /**
     * KÁRDEX CORPORATIVO: estado de cuenta del inventario de un producto leído
     * del stock ledger inmutable (stock_ledger_entries): entradas (compras/
     * ajustes) y salidas (ventas/mermas) con saldo corrido valorado a costo
     * promedio móvil. Incluye lotes próximos a vencer y datos de valoración.
     */
    public function kardex(Request $request)
    {
        $articulos = Articulo::where('type', '!=', 'servicio')->orderBy('producto', 'asc')->get();
        $articulo = null;
        $entradas = collect();
        $lotes = collect();
        $requiereApertura = false;
        $resumenVolumen = null;

        if ($request->item_id) {
            $articulo = Articulo::find($request->item_id);

            if ($articulo) {
                $entradas = StockLedger::with('usuario')
                    ->where('item_id', $articulo->id)
                    ->orderBy('fecha')
                    ->orderBy('id')
                    ->get();

                // Artículos con stock físico previo al ledger corporativo
                $requiereApertura = $entradas->isEmpty()
                    && ((float) $articulo->existencia_actual > 0 || (float) $articulo->current_volume > 0);

                // Lotes abiertos con caducidad (FIFO + vencimiento)
                $lotes = Lote::where('item_id', $articulo->id)
                    ->where('cantidad_disponible', '>', 0)
                    ->orderBy('fecha_entrada')
                    ->orderBy('id')
                    ->get();

                if ($articulo->is_fractionable && $articulo->total_volume > 0) {
                    $inventarioService = new InventarioService;
                    $resumenVolumen = [
                        'existencia_unidades' => $articulo->existencia_actual,
                        'volumen_total_ml' => round($articulo->existencia_actual * $articulo->total_volume + $articulo->current_volume, 2),
                        'costo_promedio_ml' => round($inventarioService->calcularCostoPromedioVolumen($articulo), 6),
                        'lotes_activos' => $lotes->count(),
                    ];
                }
            }
        }

        return view('inventory.kardex', compact(
            'articulos', 'articulo', 'entradas', 'lotes', 'requiereApertura', 'resumenVolumen'
        ));
    }

    /**
     * Exportar Kárdex a Excel
     */
    public function kardexExcel(Request $request)
    {
        $request->validate(['item_id' => 'required|exists:items,id']);

        return Excel::download(new KardexExport($request->item_id), 'kardex_'.now()->format('Ymd_His').'.xlsx');
    }

    /**
     * Exportar Kárdex a PDF (lectura directa del ledger inmutable)
     */
    public function kardexPdf(Request $request)
    {
        $request->validate(['item_id' => 'required|exists:items,id']);

        $articulo = Articulo::findOrFail($request->item_id);

        $entradas = StockLedger::with('usuario')
            ->where('item_id', $articulo->id)
            ->orderBy('fecha')
            ->orderBy('id')
            ->get();

        $pdf = Pdf::loadView('inventory.kardex_pdf', compact('articulo', 'entradas'));

        return $pdf->download('kardex_'.$request->item_id.'_'.now()->format('Ymd_His').'.pdf');
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
