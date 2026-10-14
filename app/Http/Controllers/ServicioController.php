<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\Servicio;
use App\Models\ServicioCategoria;
use App\Models\ServicioVariante;
use App\Models\PlantillaImpuesto;

/**
 * FASE 1 ADMINISTRACIÓN: catálogo de servicios con inteligencia de precios.
 * Categorías para reportes, variantes de precio (Corto/Medio/Largo), buffer de
 * limpieza para la agenda, plantilla de impuestos y cálculo de costo dinámico
 * de insumos (marginado en la vista desde Servicio::costoInsumos()).
 */
class ServicioController extends Controller
{
    public function index()
    {
        $servicios = Servicio::with(['categoria', 'plantillaImpuesto', 'variantes', 'formulas.articulo'])
            ->orderBy('name', 'asc')
            ->get();

        $categorias = ServicioCategoria::orderBy('name', 'asc')->get();
        $plantillas = PlantillaImpuesto::orderBy('name', 'asc')->get();

        return view('services.index', compact('servicios', 'categorias', 'plantillas'));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->reglas());

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();

        try {
            $servicio = Servicio::create($this->datos($request));
            $this->guardarVariantes($servicio, $request->input('variantes'));

            DB::commit();

            return response()->json(['message' => 'Servicio creado exitosamente'], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json(['error' => 'Error al guardar el servicio. Contacte al administrador.'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $servicio = Servicio::findOrFail($id);

        $validator = Validator::make($request->all(), $this->reglas());

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();

        try {
            $servicio->update($this->datos($request));
            $this->guardarVariantes($servicio, $request->input('variantes'));

            DB::commit();

            return response()->json(['message' => 'Servicio actualizado correctamente']);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return response()->json(['error' => 'Error al actualizar el servicio. Contacte al administrador.'], 500);
        }
    }

    public function destroy($id)
    {
        $servicio = Servicio::findOrFail($id);

        // En lugar de borrarlo físicamente (lo que rompería facturas viejas),
        // lo desactivamos para que ya no salga en la caja registradora.
        $servicio->update(['is_active' => false]);

        return response()->json(['message' => 'Servicio dado de baja']);
    }

    /** Alta rápida de categorías desde el catálogo de servicios */
    public function storeCategoria(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:120|unique:service_categories,name',
            'description' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $categoria = ServicioCategoria::create([
            'name' => trim($request->name),
            'description' => $request->description,
            'color' => $request->color,
        ]);

        return response()->json(['message' => 'Categoría creada', 'categoria' => $categoria], 201);
    }

    private function reglas(): array
    {
        return [
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'duration' => 'required|integer|min:5', // Mínimo 5 minutos
            'description' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'service_category_id' => 'nullable|integer|exists:service_categories,id',
            'tax_template_id' => 'nullable|integer|exists:tax_templates,id',
            'cleanup_minutes' => 'nullable|integer|min:0|max:480',
            // VARIANTES DE PRECIO: repeater del formulario
            'variantes' => 'nullable|array|max:20',
            'variantes.*.id' => 'nullable|integer',
            'variantes.*.name' => 'required_with:variantes|string|max:100',
            'variantes.*.price' => 'required_with:variantes|numeric|min:0',
            'variantes.*.duration' => 'nullable|integer|min:5|max:600',
            'variantes.*.is_active' => 'nullable|boolean',
        ];
    }

    private function datos(Request $request): array
    {
        $datos = $request->only([
            'name', 'price', 'duration', 'description', 'is_active',
            'service_category_id', 'tax_template_id',
        ]);

        $datos['cleanup_minutes'] = (int) $request->input('cleanup_minutes', 0);
        $datos['price'] = (float) $datos['price'];
        $datos['duration'] = (int) $datos['duration'];

        return $datos;
    }

    /**
     * Sincroniza el repeater de variantes: crea, actualiza y elimina las que
     * el administrador quitó del formulario. Las facturas históricas conservan
     * su precio (FK nullOnDelete en sale_details.service_variant_id).
     */
    private function guardarVariantes(Servicio $servicio, $variantes): void
    {
        if (!is_array($variantes)) {
            $variantes = [];
        }

        $idsConservar = [];

        foreach (array_values($variantes) as $index => $data) {
            if (empty($data['name']) || !isset($data['price']) || $data['price'] === '') {
                continue;
            }

            $payload = [
                'name' => trim($data['name']),
                'price' => (float) $data['price'],
                'duration' => isset($data['duration']) && (int) $data['duration'] > 0 ? (int) $data['duration'] : null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'sort_order' => $index,
            ];

            if (!empty($data['id'])) {
                $variante = ServicioVariante::where('service_id', $servicio->id)->find($data['id']);

                if ($variante) {
                    $variante->update($payload);
                    $idsConservar[] = $variante->id;
                    continue;
                }
            }

            $nueva = $servicio->variantes()->create($payload);
            $idsConservar[] = $nueva->id;
        }

        ServicioVariante::where('service_id', $servicio->id)
            ->whereNotIn('id', $idsConservar)
            ->delete();
    }
}
