<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CuentaContable;
use App\Models\CentroCosto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\CuentasContablesExport;
use App\Exports\PlantillaCuentasExport;
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
        
        try {
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
        } catch (\Throwable $e) {
            Log::error('Error al crear cuenta contable', [
                'exception' => $e->getMessage(),
                'request' => $request->except(['_token']),
            ]);
            return response()->json([
                'error' => 'No se pudo crear la cuenta contable.',
                'detalle' => $e->getMessage(),
                'tipo' => class_basename($e),
            ], 500);
        }

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

            // Evitar ciclos en el árbol: una cuenta no puede depender de sí misma
            // ni de una de sus propias subcuentas.
            if ($request->filled('cuenta_padre_id')) {
                if ((int) $request->cuenta_padre_id === (int) $cuenta->id) {
                    return response()->json(['errors' => [
                        'cuenta_padre_id' => ['Una cuenta no puede ser su propia cuenta padre.'],
                    ]], 422);
                }

                if (in_array((int) $request->cuenta_padre_id, $this->descendientesIds($cuenta->id), true)) {
                    return response()->json(['errors' => [
                        'cuenta_padre_id' => ['No se puede usar una subcuenta como cuenta padre (se crearía un ciclo).'],
                    ]], 422);
                }
            }

            // Calcular nuevo nivel si cambió de padre
            $nivel = 1;
            if ($request->cuenta_padre_id) {
                $padre = CuentaContable::find($request->cuenta_padre_id);
                $nivel = $padre->nivel + 1;
            }

            try {
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
            } catch (\Throwable $e) {
                Log::error('Error al actualizar cuenta contable', [
                    'exception' => $e->getMessage(),
                    'cuenta_id' => $cuenta->id,
                ]);
                return response()->json([
                    'error' => 'No se pudo actualizar la cuenta contable.',
                    'detalle' => $e->getMessage(),
                    'tipo' => class_basename($e),
                ], 500);
            }
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
     * Descargar plantilla Excel para importación.
     * Hoja "Plantilla" vacía (solo encabezados) + hoja "Ejemplo" de referencia.
     * Las cuentas existentes del catálogo se descargan con "Exportar Excel".
     */
    public function descargarPlantilla()
    {
        return Excel::download(new PlantillaCuentasExport(), 'plantilla_catalogo_cuentas.xlsx');
    }

    /**
     * Importar catálogo desde Excel
     */
    public function importar(Request $request)
    {
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls',
        ]);

        // PASO 1: lectura estructural SIN tocar la base de datos. Se validan
        // columnas, códigos únicos, relación padre-hijo y ciclos. Si algo está
        // mal, no se guarda NADA (antes de guardarla, como pide el diseño).
        try {
            $filas = ExcelFacade::toCollection(new \App\Imports\CuentasContablesReader(), $request->file('archivo'))->first() ?? collect();
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo leer el archivo Excel. Verifica que sea la plantilla correcta (.xlsx).');
        }

        $errores = $this->validarEstructuraCatalogo($filas);
        if (!empty($errores)) {
            return back()->with('error', 'Validación estructural fallida → ' . implode(' | ', $errores));
        }

        // PASO 2: importación transaccional (todo o nada) con validación por fila
        DB::beginTransaction();

        try {
            ExcelFacade::import(new CuentasContablesImport, $request->file('archivo'));
            DB::commit();
            return back()->with('success', 'Catálogo importado exitosamente.');
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            DB::rollBack();
            $detalle = collect($e->failures())
                ->map(fn ($f) => 'Fila ' . $f->row() . ': ' . implode(', ', $f->errors()))
                ->unique()
                ->take(6)
                ->implode(' | ');
            return back()->with('error', 'Errores de validación del catálogo → ' . $detalle);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error importando catálogo', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->with('error', 'Error al importar: ' . $e->getMessage());
        }
    }

    /**
     * Validación estructural del archivo ANTES de guardar:
     * encabezados, códigos únicos, padres existentes (BD o archivo) y sin ciclos.
     *
     * @return array<int,string> Lista de errores (vacía si la estructura es válida)
     */
    protected function validarEstructuraCatalogo($filas): array
    {
        if ($filas->isEmpty()) {
            return ['El archivo no contiene filas de datos.'];
        }

        $errores = [];
        $encabezados = array_keys($filas->first()->toArray());
        foreach (['codigo', 'nombre', 'tipo', 'naturaleza'] as $col) {
            if (!in_array($col, $encabezados, true)) {
                $errores[] = "Falta la columna [{$col}] en el encabezado.";
            }
        }
        if ($errores) {
            return $errores;
        }

        $vistos = [];        // codigo => fila excel
        $padresEnArchivo = []; // codigo => codigo_padre (cuando viene definido)
        $tiposValidos = ['activo', 'pasivo', 'patrimonio', 'ingreso', 'gasto', 'costo'];

        foreach ($filas as $i => $fila) {
            $n = $i + 2; // 1 = encabezado
            $codigo = trim((string) ($fila['codigo'] ?? ''));
            $nombre = trim((string) ($fila['nombre'] ?? ''));

            if ($codigo === '') {
                $errores[] = "Fila {$n}: el código es obligatorio.";
                continue;
            }
            if (isset($vistos[$codigo])) {
                $errores[] = "Fila {$n}: código [{$codigo}] duplicado en el archivo.";
            }
            $vistos[$codigo] = $n;

            if (CuentaContable::where('codigo', $codigo)->exists()) {
                $errores[] = "Fila {$n}: el código [{$codigo}] ya existe en el catálogo.";
            }
            if ($nombre === '') {
                $errores[] = "Fila {$n}: el nombre es obligatorio.";
            }
            if (!in_array(strtolower(trim((string) ($fila['tipo'] ?? ''))), $tiposValidos, true)) {
                $errores[] = "Fila {$n}: tipo inválido [" . trim((string) ($fila['tipo'] ?? '')) . "].";
            }
            if (!in_array(strtolower(trim((string) ($fila['naturaleza'] ?? ''))), ['deudora', 'acreedora'], true)) {
                $errores[] = "Fila {$n}: naturaleza inválida.";
            }

            $padre = trim((string) ($fila['codigo_padre'] ?? ''));
            if ($padre !== '') {
                $padresEnArchivo[$codigo] = $padre;
            }
        }

        // El padre debe existir en el catálogo o estar declarado en el propio archivo
        foreach ($padresEnArchivo as $codigo => $padre) {
            $n = $vistos[$codigo] ?? '?';
            if (!isset($vistos[$padre]) && !CuentaContable::where('codigo', $padre)->exists()) {
                $errores[] = "Fila {$n}: la cuenta padre [{$padre}] no existe en el catálogo ni en el archivo.";
            }
        }

        // Detectar ciclos dentro del propio archivo (A padre de B, B padre de A...)
        $ciclosReportados = [];
        foreach ($padresEnArchivo as $codigo => $padre) {
            $visitados = [$codigo => true];
            $actual = $padre;
            while (isset($padresEnArchivo[$actual])) {
                if (isset($visitados[$actual])) {
                    if (!isset($ciclosReportados[$codigo])) {
                        $errores[] = "El código [{$codigo}] forma un ciclo de cuentas padre-hijo dentro del archivo.";
                        $ciclosReportados[$codigo] = true;
                    }
                    break;
                }
                $visitados[$actual] = true;
                $actual = $padresEnArchivo[$actual];
            }
        }

        return array_slice(array_values(array_unique($errores)), 0, 8);
    }

    /**
     * Exportar catálogo actual a Excel
     */
    public function exportar()
    {
        return Excel::download(new CuentasContablesExport, 'catalogo_cuentas_' . now()->format('Ymd_His') . '.xlsx');
    }

    /**
     * IDs de todas las subcuentas (recursivas) de una cuenta.
     */
    private function descendientesIds(int $cuentaId): array
    {
        $ids = [];
        $pendientes = [$cuentaId];

        while (!empty($pendientes)) {
            $actual = array_pop($pendientes);
            $hijos = CuentaContable::where('cuenta_padre_id', $actual)->pluck('id')->all();

            foreach ($hijos as $hijo) {
                if (!in_array((int) $hijo, $ids, true)) {
                    $ids[] = (int) $hijo;
                    $pendientes[] = $hijo;
                }
            }
        }

        return $ids;
    }
}