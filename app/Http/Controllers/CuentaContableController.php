<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CuentaContable;
use App\Models\CentroCosto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\CuentasContablesExport;
use App\Imports\CuentasContablesImport;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;

class CuentaContableController extends Controller
{
    /**
     * Vista del Catálogo de Cuentas en árbol (Tree View)
     */
    public function index()
    {
        // Árbol: solo raíces, los hijos se resuelven recursivamente en la vista
        $cuentasArbol = CuentaContable::with('subcuentas')
            ->whereNull('cuenta_padre_id')
            ->orderBy('codigo')
            ->get();

        // Listado plano para los selectores del cliente (modal / cuenta padre)
        $cuentas = CuentaContable::orderBy('codigo')->get();

        return view('accounting.catalogo', compact('cuentasArbol', 'cuentas'));
    }

    /**
     * Obtener cuentas en formato JSON para el árbol (AJAX)
     */
    public function tree()
    {
        $cuentas = CuentaContable::with('subcuentas')->whereNull('cuenta_padre_id')->get();
        
        return response()->json($cuentas->map(function ($cuenta) {
            return $this->cuentaToTree($cuenta);
        }));
    }

    private function cuentaToTree($cuenta)
    {
        return [
            'id' => $cuenta->id,
            'codigo' => $cuenta->codigo,
            'nombre' => $cuenta->nombre,
            'tipo' => $cuenta->tipo,
            'naturaleza' => $cuenta->naturaleza,
            'permite_movimiento' => $cuenta->permite_movimiento,
            'activa' => $cuenta->activa,
            'is_system_account' => $cuenta->is_system_account,
            'children' => $cuenta->subcuentas->map(function ($sub) {
                return $this->cuentaToTree($sub);
            })->toArray(),
        ];
    }

    /**
     * Crear nueva cuenta o subcuenta
     */
    public function store(Request $request)
    {
        $request->validate([
            'codigo' => 'required|string|max:20|unique:cuentas_contables,codigo',
            'nombre' => 'required|string|max:100',
            'tipo' => 'required|in:activo,pasivo,patrimonio,ingreso,gasto,costo',
            'naturaleza' => 'required|in:deudora,acreedora',
            'cuenta_padre_id' => 'nullable|exists:cuentas_contables,id',
            'permite_movimiento' => 'boolean',
        ]);

        // Si tiene cuenta padre, heredar naturaleza si es subcuenta de detalle
        $cuentaPadre = $request->cuenta_padre_id ? CuentaContable::find($request->cuenta_padre_id) : null;
        
        $cuenta = CuentaContable::create([
            'codigo' => $request->codigo,
            'nombre' => $request->nombre,
            'tipo' => $request->tipo,
            'naturaleza' => $request->naturaleza,
            'cuenta_padre_id' => $request->cuenta_padre_id,
            'nivel' => $cuentaPadre ? $cuentaPadre->nivel + 1 : 1,
            'permite_movimiento' => $request->boolean('permite_movimiento', true),
            'activa' => true,
            'is_system_account' => false,
        ]);

        return response()->json(['message' => 'Cuenta creada exitosamente', 'cuenta' => $cuenta], 201);
    }

    /**
     * Actualizar cuenta (solo nombre si es system account)
     */
    public function update(Request $request, $id)
    {
        $cuenta = CuentaContable::findOrFail($id);

        if ($cuenta->is_system_account) {
            // Solo permitir cambiar nombre si es cuenta del sistema
            $request->validate([
                'nombre' => 'required|string|max:100',
            ]);
            $cuenta->update(['nombre' => $request->nombre]);
        } else {
            $request->validate([
                'codigo' => 'required|string|max:20|unique:cuentas_contables,codigo,' . $id,
                'nombre' => 'required|string|max:100',
                'tipo' => 'required|in:activo,pasivo,patrimonio,ingreso,gasto,costo',
                'naturaleza' => 'required|in:deudora,acreedora',
                'cuenta_padre_id' => 'nullable|exists:cuentas_contables,id',
                'permite_movimiento' => 'boolean',
                'activa' => 'boolean',
            ]);

            // Calcular nuevo nivel si cambió de padre
            $nivel = 1;
            if ($request->cuenta_padre_id) {
                $padre = CuentaContable::find($request->cuenta_padre_id);
                $nivel = $padre->nivel + 1;
            }

            $cuenta->update([
                'codigo' => $request->codigo,
                'nombre' => $request->nombre,
                'tipo' => $request->tipo,
                'naturaleza' => $request->naturaleza,
                'cuenta_padre_id' => $request->cuenta_padre_id,
                'nivel' => $nivel,
                'permite_movimiento' => $request->boolean('permite_movimiento'),
                'activa' => $request->boolean('activa', true),
            ]);
        }

        return response()->json(['message' => 'Cuenta actualizada', 'cuenta' => $cuenta->fresh()]);
    }

    /**
     * Eliminar cuenta (prohibido si es system account o tiene movimientos)
     */
    public function destroy($id)
    {
        $cuenta = CuentaContable::findOrFail($id);

        if ($cuenta->is_system_account) {
            return response()->json(['error' => 'No se puede eliminar una cuenta del sistema.'], 403);
        }

        if ($cuenta->detalles()->exists()) {
            return response()->json(['error' => 'No se puede eliminar una cuenta con movimientos contables.'], 403);
        }

        if ($cuenta->subcuentas()->exists()) {
            return response()->json(['error' => 'No se puede eliminar una cuenta que tiene subcuentas. Elimine primero las subcuentas.'], 403);
        }

        $cuenta->delete();
        return response()->json(['message' => 'Cuenta eliminada']);
    }

    /**
     * Descargar plantilla Excel para importación
     */
    public function descargarPlantilla()
    {
        return Excel::download(new CuentasContablesExport(), 'plantilla_catalogo_cuentas.xlsx');
    }

    /**
     * Importar catálogo desde Excel
     */
    public function importar(Request $request)
    {
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls',
        ]);

        try {
            ExcelFacade::import(new CuentasContablesImport, $request->file('archivo'));
            return back()->with('success', 'Catálogo importado exitosamente.');
        } catch (\Exception $e) {
            Log::error('Error importando catálogo', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->withErrors(['error' => 'Error al importar: ' . $e->getMessage()]);
        }
    }

    /**
     * Exportar catálogo actual a Excel
     */
    public function exportar()
    {
        return Excel::download(new CuentasContablesExport, 'catalogo_cuentas_' . now()->format('Ymd_His') . '.xlsx');
    }
}