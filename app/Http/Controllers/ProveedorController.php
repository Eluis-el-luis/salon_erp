<?php

namespace App\Http\Controllers;

use App\Models\CuentaPorPagar;
use App\Models\PagoProveedor;
use App\Models\Proveedor;
use Illuminate\Http\Request;

class ProveedorController extends Controller
{
    public function index()
    {
        $proveedores = Proveedor::orderBy('name', 'asc')->get();

        // Saldo AP por proveedor para la columna del directorio (evita N+1)
        $saldos = CuentaPorPagar::query()
            ->selectRaw('provider_id, SUM(saldo_pendiente) AS saldo')
            ->whereIn('estado', ['pendiente', 'parcial'])
            ->groupBy('provider_id')
            ->pluck('saldo', 'provider_id');

        foreach ($proveedores as $proveedor) {
            $proveedor->saldo_pendiente = round((float) ($saldos[$proveedor->id] ?? 0), 2);
        }

        return view('providers.index', compact('proveedores'));
    }

    /**
     * SUPPLIER PROFILE 360: posición de CxP en tiempo real, facturas de
     * compra, pagos emitidos, mermas de sus artículos y catálogo suministrado.
     */
    public function show($id)
    {
        $proveedor = Proveedor::findOrFail($id);

        $metricas = [
            'saldo_pendiente' => $proveedor->saldoPendiente(),
            'monto_vencido' => $proveedor->montoVencido(),
            'total_comprado' => $proveedor->totalComprado(),
            'total_pagado' => $proveedor->totalPagado(),
            'facturas_abiertas' => $proveedor->cuentasPorPagar()->whereIn('estado', ['pendiente', 'parcial'])->count(),
            'facturas_vencidas' => $proveedor->facturasVencidas()->count(),
            'articulos_suministrados' => $proveedor->articulos()->count(),
        ];

        // Facturas de compra (nuevo flujo) con su CxP enlazada
        $facturas = $proveedor->facturasCompra()
            ->with('cuentaPorPagar')
            ->orderByDesc('fecha_factura')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        // CxP del flujo antiguo (sin factura de compra): historial heredado
        $cxpLegacy = $proveedor->cuentasPorPagar()
            ->whereNull('purchase_invoice_id')
            ->orderByDesc('fecha_emision')
            ->limit(50)
            ->get();

        // CxP abiertas para el abono rápido
        $cuentasAbiertas = $proveedor->cuentasPorPagar()
            ->whereIn('estado', ['pendiente', 'parcial'])
            ->orderBy('fecha_vencimiento')
            ->get();

        $pagos = PagoProveedor::query()
            ->whereIn('cuenta_por_pagar_id', $proveedor->cuentasPorPagar()->select('id'))
            ->with('cuentaPorPagar')
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $mermas = $proveedor->mermasDeArticulos()
            ->with('articulo')
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $articulos = $proveedor->articulos()->orderBy('producto', 'asc')->get();

        return view('providers.show', compact(
            'proveedor', 'metricas', 'facturas', 'cxpLegacy', 'cuentasAbiertas',
            'pagos', 'mermas', 'articulos'
        ));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'tax_id' => 'nullable|string|max:30',
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'credit_days' => 'nullable|integer|min:0|max:365',
        ]);

        $validated['credit_days'] = (int) ($validated['credit_days'] ?? 30);

        Proveedor::create($validated);

        return response()->json(['message' => 'Proveedor registrado con éxito']);
    }

    public function update(Request $request, $id)
    {
        $proveedor = Proveedor::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'tax_id' => 'nullable|string|max:30',
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'credit_days' => 'nullable|integer|min:0|max:365',
        ]);

        $validated['credit_days'] = (int) ($validated['credit_days'] ?? 30);

        $proveedor->update($validated);

        return response()->json(['message' => 'Proveedor actualizado con éxito']);
    }

    public function destroy($id)
    {
        $proveedor = Proveedor::findOrFail($id);

        // §5.3: la historia financiera no se borra. Un proveedor con CxP
        // abiertas, facturas de compra o artículos vinculados no se elimina.
        if ($proveedor->cuentasPorPagar()->whereIn('estado', ['pendiente', 'parcial'])->exists()) {
            return response()->json(['error' => 'No se puede eliminar: el proveedor tiene cuentas por pagar abiertas.'], 422);
        }

        if ($proveedor->facturasCompra()->exists()) {
            return response()->json(['error' => 'No se puede eliminar: el proveedor tiene facturas de compra en el historial (auditoría contable).'], 422);
        }

        if ($proveedor->cuentasPorPagar()->exists()) {
            return response()->json(['error' => 'No se puede eliminar: el proveedor tiene cuentas por pagar en el historial (auditoría contable).'], 422);
        }

        if ($proveedor->articulos()->exists()) {
            return response()->json(['error' => 'No se puede eliminar: hay artículos vinculados a este proveedor. Reasígnalos primero.'], 422);
        }

        $proveedor->delete();

        return response()->json(['message' => 'Proveedor eliminado']);
    }
}
