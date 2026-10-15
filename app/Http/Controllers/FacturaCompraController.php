<?php

namespace App\Http\Controllers;

use App\Models\FacturaCompra;
use App\Services\CompraService;
use Illuminate\Http\Request;

/**
 * FACTURA DE COMPRA: detalle auditable del documento de compra (líneas,
 * landed cost, IVA, lote/vencimiento, asiento COM-*) y su anulación con
 * reversión total (solo admin), siguiendo §5.3.
 */
class FacturaCompraController extends Controller
{
    public function show($id)
    {
        $factura = FacturaCompra::with([
            'proveedor',
            'lineas.articulo',
            'lineas.lotes',
            'cuentaPorPagar',
            'asiento',
        ])->findOrFail($id);

        return view('purchases.show', compact('factura'));
    }

    public function anular(Request $request, $id)
    {
        $request->validate(['motivo' => 'required|string|min:5|max:255']);

        try {
            (new CompraService)->anularFactura((int) $id, $request->motivo, auth()->id());

            return back()->with('success', 'Factura anulada: inventario, lotes, kárdex, CxP y asiento revertidos.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }
}
