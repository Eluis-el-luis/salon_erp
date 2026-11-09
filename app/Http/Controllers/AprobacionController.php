<?php

namespace App\Http\Controllers;

use App\Exceptions\ContabilidadException;
use App\Services\AprobacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Traits\AutorizaPinSupervisor;

/**
 * MESA DE APROBACIONES (gobernanza, octubre 2026).
 *
 * El rol Álvaro (y el Administrador) auditan y despachan las transacciones
 * retenidas: al aprobar se ejecutan los efectos físicos y el asiento contable;
 * al rechazar, el documento se marca 'anulada' con motivo de auditoría
 * (nunca tuvo efectos, no hay reversión).
 *
 * DOBLE CONFIRMACIÓN: aprobar/rechazar transacciones retenidas exige PIN de
 * supervisor (modo estricto: solo roles 'alvaro'/'admin').
 */
class AprobacionController extends Controller
{
    use AutorizaPinSupervisor;

    public function __construct(private AprobacionService $aprobaciones) {}

    public function index()
    {
        $pendientes = $this->aprobaciones->pendientes();

        return view('aprobaciones.index', compact('pendientes'));
    }

    public function aprobarVenta(Request $request, $id)
    {
        // DOBLE CONFIRMACIÓN: aprobar venta retenida exige PIN de supervisor
        $this->autorizarPin($request, soloSupervisor: true);

        try {
            $venta = $this->aprobaciones->aprobarVenta((int) $id, auth()->id());

            return back()->with('success', 'Factura #'.$venta->id.' aprobada: inventario, libro de pagos, comisiones y asiento contable ejecutados.');
        } catch (ContabilidadException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Error al aprobar venta retenida', [
                'sale_id' => $id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Error al aprobar la venta. Contacte al administrador.');
        }
    }

    public function rechazarVenta(Request $request, $id)
    {
        // DOBLE CONFIRMACIÓN: rechazar venta retenida exige PIN de supervisor
        $this->autorizarPin($request, soloSupervisor: true);

        $request->validate([
            'motivo' => 'required|string|min:5|max:255',
        ]);

        try {
            $venta = $this->aprobaciones->rechazarVenta((int) $id, trim($request->motivo), auth()->id());

            return back()->with('success', 'Factura #'.$venta->id.' rechazada. Quedó registrada como anulada con el motivo.');
        } catch (ContabilidadException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Error al rechazar venta retenida', [
                'sale_id' => $id,
                'exception' => $e->getMessage(),
            ]);

            return back()->with('error', 'Error al rechazar la venta. Contacte al administrador.');
        }
    }

    public function aprobarCompra(Request $request, $id)
    {
        // DOBLE CONFIRMACIÓN: aprobar compra retenida exige PIN de supervisor
        $this->autorizarPin($request, soloSupervisor: true);

        try {
            $factura = $this->aprobaciones->aprobarCompra((int) $id, auth()->id());

            return back()->with('success', 'Compra #'.$factura->id.' aprobada: inventario, lotes, kárdex, CxP y asiento COM-* ejecutados.');
        } catch (\Throwable $e) {
            Log::error('Error al aprobar compra retenida', [
                'purchase_invoice_id' => $id,
                'exception' => $e->getMessage(),
            ]);

            return back()->with('error', $e->getMessage());
        }
    }

    public function rechazarCompra(Request $request, $id)
    {
        // DOBLE CONFIRMACIÓN: rechazar compra retenida exige PIN de supervisor
        $this->autorizarPin($request, soloSupervisor: true);

        $request->validate([
            'motivo' => 'required|string|min:5|max:255',
        ]);

        try {
            $factura = $this->aprobaciones->rechazarCompra((int) $id, trim($request->motivo), auth()->id());

            return back()->with('success', 'Compra #'.$factura->id.' rechazada. Quedó registrada como anulada con el motivo.');
        } catch (\Throwable $e) {
            Log::error('Error al rechazar compra retenida', [
                'purchase_invoice_id' => $id,
                'exception' => $e->getMessage(),
            ]);

            return back()->with('error', $e->getMessage());
        }
    }
}
