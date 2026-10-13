<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\EsquemaComision;
use App\Models\Rol;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class EmpleadoController extends Controller
{
    // Roles válidos del sistema (deben coincidir con el enum de users.role)
    protected const ROLES_VALIDOS = ['admin', 'estilista', 'recepcion', 'contador'];

    // Tipos de contrato del expediente RRHH
    protected const TIPOS_CONTRATO = ['indefinido', 'plazo_fijo', 'obra_labor', 'servicios_profesionales'];

    // Mostrar la lista de empleados
    public function index()
    {
        // Traemos a los empleados junto con su esquema de comisión actual vigente y su expediente RRHH
        $empleados = Usuario::with(['esquemaActual', 'empleado'])->orderBy('name', 'asc')->get();

        return view('employees.index', compact('empleados'));
    }

    // Guardar un nuevo empleado
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|string|in:'.implode(',', self::ROLES_VALIDOS),
            'salario_fijo' => 'nullable|numeric|min:0',
            'comision_servicio' => 'nullable|numeric|min:0|max:100',
            'comision_producto' => 'nullable|numeric|min:0|max:100',
            'phone' => 'nullable|string|max:30',
            'fecha_ingreso' => 'nullable|date|before_or_equal:today',
            'tipo_contrato' => 'nullable|string|in:'.implode(',', self::TIPOS_CONTRATO),
            'cargo' => 'nullable|string|max:120',
            'documento_identidad' => 'nullable|string|max:50',
            'nss' => 'nullable|string|max:50',
        ], $this->mensajesValidacion(), $this->atributos());

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Datos del colaborador inválidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $usuario = Usuario::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => $request->role,
                'role_id' => $this->resolverRoleId($request->role),
                'salario_fijo' => $request->salario_fijo ?? 0,
                'comision_servicio' => $request->comision_servicio ?? 0, // Se mantiene por retrocompatibilidad
                'comision_producto' => $request->comision_producto ?? 0,
                'phone' => $request->phone,
                'is_active' => true,
            ]);

            // HRMS: expediente Employee (ciclo de vida) separado del User (acceso)
            Empleado::crearParaUsuario($usuario, [
                'tipo_contrato' => $request->tipo_contrato ?? 'indefinido',
                'fecha_ingreso' => $request->fecha_ingreso ?? Carbon::today()->toDateString(),
                'cargo' => $request->cargo,
                'documento_identidad' => $request->documento_identidad,
                'nss' => $request->nss,
            ]);

            // Crear el Esquema de Comisión inicial si tiene comisión por servicio
            if ($request->comision_servicio > 0) {
                EsquemaComision::create([
                    'empleado_id' => $usuario->id,
                    'porcentaje_comision' => $request->comision_servicio,
                    'vigente_desde' => Carbon::today(),
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Empleado registrado exitosamente',
                'empleado_id' => $usuario->id,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->errorDetallado($e, 'No se pudo registrar el colaborador');
        }
    }

    // Actualizar datos o salario
    public function update(Request $request, $id)
    {
        try {
            $empleado = Usuario::with('esquemaActual')->find($id);

            if (! $empleado) {
                return response()->json([
                    'error' => 'Colaborador no encontrado.',
                    'detalle' => "No existe un usuario con ID {$id}.",
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email,'.$empleado->id,
                'role' => 'required|string|in:'.implode(',', self::ROLES_VALIDOS),
                'salario_fijo' => 'nullable|numeric|min:0',
                'comision_servicio' => 'nullable|numeric|min:0|max:100',
                'comision_producto' => 'nullable|numeric|min:0|max:100',
                'phone' => 'nullable|string|max:30',
                'password' => 'nullable|string|min:8',
                'fecha_ingreso' => 'nullable|date|before_or_equal:today',
                'tipo_contrato' => 'nullable|string|in:'.implode(',', self::TIPOS_CONTRATO),
                'cargo' => 'nullable|string|max:120',
                'documento_identidad' => 'nullable|string|max:50',
                'nss' => 'nullable|string|max:50',
            ], $this->mensajesValidacion(), $this->atributos());

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Datos del colaborador inválidos.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            DB::beginTransaction();

            // 1. Verificar si el porcentaje de comisión cambió
            $comisionActual = $empleado->esquemaActual ? $empleado->esquemaActual->porcentaje_comision : $empleado->comision_servicio;
            $nuevaComision = $request->comision_servicio;

            if (floatval($comisionActual) !== floatval($nuevaComision)) {
                // Cerrar el esquema actual (venció ayer)
                if ($empleado->esquemaActual) {
                    $empleado->esquemaActual->update([
                        'vigente_hasta' => Carbon::yesterday(),
                    ]);
                }

                // Crear el nuevo esquema (vigente desde hoy)
                EsquemaComision::create([
                    'empleado_id' => $empleado->id,
                    'porcentaje_comision' => $nuevaComision,
                    'vigente_desde' => Carbon::today(),
                ]);
            }

            // 2. Actualizar los demás datos del usuario
            $data = $request->except('password');
            $data['role_id'] = $this->resolverRoleId($request->role);

            if ($request->filled('password')) {
                $data['password'] = Hash::make($request->password);
            }

            $empleado->update($data);

            // HRMS: sincronizar el expediente Employee (salario base y lifecycle)
            $expediente = Empleado::firstOrCreate(
                ['user_id' => $empleado->id],
                ['codigo_empleado' => Empleado::siguienteCodigo($empleado->id)],
            );
            $expediente->update([
                'salario_base' => $request->salario_fijo ?? $expediente->salario_base,
                'tipo_contrato' => $request->tipo_contrato ?? $expediente->tipo_contrato,
                'fecha_ingreso' => $request->fecha_ingreso ?? $expediente->fecha_ingreso,
                'cargo' => $request->cargo,
                'documento_identidad' => $request->documento_identidad,
                'nss' => $request->nss,
            ]);

            DB::commit();

            return response()->json(['message' => 'Datos del empleado actualizados correctamente']);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->errorDetallado($e, 'No se pudo actualizar el colaborador');
        }
    }

    // Desactivar un empleado (En ERPs no se borran para no quebrar las facturas viejas)
    public function destroy($id)
    {
        try {
            $empleado = Usuario::find($id);

            if (! $empleado) {
                return response()->json([
                    'error' => 'Colaborador no encontrado.',
                    'detalle' => "No existe un usuario con ID {$id}.",
                ], 404);
            }

            $empleado->update(['is_active' => false]);

            // HRMS: cierra el ciclo de vida del expediente (fecha de salida)
            $expediente = Empleado::where('user_id', $empleado->id)->first();
            $expediente?->update([
                'estado_laboral' => 'retirado',
                'fecha_salida' => Carbon::today()->toDateString(),
            ]);

            return response()->json(['message' => 'Empleado dado de baja del sistema']);
        } catch (\Throwable $e) {
            return $this->errorDetallado($e, 'No se pudo dar de baja al colaborador');
        }
    }

    /**
     * Mensajes de validación detallados y en español.
     */
    protected function mensajesValidacion(): array
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'string' => 'El campo :attribute debe ser texto.',
            'email' => 'El campo :attribute debe ser un correo electrónico válido.',
            'unique' => 'El :attribute ya está registrado en el sistema.',
            'numeric' => 'El campo :attribute debe ser un número.',
            'in' => 'El valor de :attribute no es válido. Roles permitidos: '.implode(', ', self::ROLES_VALIDOS).'.',
            'min' => [
                'string' => 'El campo :attribute debe tener al menos :min caracteres.',
                'numeric' => 'El campo :attribute debe ser como mínimo :min.',
            ],
            'max' => [
                'string' => 'El campo :attribute no debe superar :max caracteres.',
                'numeric' => 'El campo :attribute no debe ser mayor que :max.',
            ],
        ];
    }

    /**
     * Nombres legibles de los campos para los mensajes.
     */
    protected function atributos(): array
    {
        return [
            'name' => 'nombre',
            'email' => 'correo',
            'password' => 'contraseña',
            'role' => 'rol',
            'salario_fijo' => 'salario fijo',
            'comision_servicio' => 'comisión por servicios',
            'comision_producto' => 'comisión por productos',
            'phone' => 'teléfono',
            'fecha_ingreso' => 'fecha de ingreso',
            'tipo_contrato' => 'tipo de contrato',
            'cargo' => 'cargo',
            'documento_identidad' => 'documento de identidad',
            'nss' => 'número INSS',
        ];
    }

    /**
     * Traduce el rol (string del sistema) al role_id válido de la tabla roles.
     * Crea el rol si no existe para evitar romper la FK role_id.
     */
    protected function resolverRoleId(string $role): int
    {
        $mapa = [
            'admin' => 'Administrador',
            'recepcion' => 'Recepción / Caja',
            'estilista' => 'Estilista',
            'contador' => 'Contador',
        ];

        $nombre = $mapa[$role] ?? ucfirst($role);

        return Rol::firstOrCreate(['name' => $nombre])->id;
    }

    /**
     * Respuesta de error detallada (con log) para dar indicios claros de la falla.
     */
    protected function errorDetallado(\Throwable $e, string $contexto, int $status = 500)
    {
        Log::error($contexto, [
            'exception' => $e->getMessage(),
            'tipo' => get_class($e),
            'trace' => $e->getTraceAsString(),
            'request' => request()->except(['password', '_token']),
        ]);

        return response()->json([
            'error' => $contexto.'.',
            'detalle' => $e->getMessage(),
            'tipo' => class_basename($e),
        ], $status);
    }
}
