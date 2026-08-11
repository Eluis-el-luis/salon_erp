<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\EsquemaComision;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class EmployeeController extends Controller
{
    // Mostrar la lista de empleados
    public function index()
    {
        // Traemos a los empleados junto con su esquema de comisión actual vigente
        $employees = User::with('esquemaActual')->orderBy('name', 'asc')->get();
        return view('employees.index', compact('employees'));
    }

    // Guardar un nuevo empleado
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|string',
            'salario_fijo' => 'numeric|min:0',
            'comision_servicio' => 'numeric|min:0|max:100',
            'comision_producto' => 'numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // 1. Crear el usuario
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'role_id' => 1,
            'salario_fijo' => $request->salario_fijo ?? 0,
            'comision_servicio' => $request->comision_servicio ?? 0, // Se mantiene por retrocompatibilidad[cite: 5, 6]
            'comision_producto' => $request->comision_producto ?? 0,
            'phone' => $request->phone,
            'is_active' => true,
        ]);

        // 2. Crear el Esquema de Comisión inicial si es estilista y tiene comisión
        if ($request->comision_servicio > 0) {
            EsquemaComision::create([
                'empleado_id' => $user->id,
                'porcentaje_comision' => $request->comision_servicio,
                'vigente_desde' => Carbon::today(),
            ]);
        }

        return response()->json(['message' => 'Empleado registrado exitosamente']);
    }

    // Actualizar datos o salario
    public function update(Request $request, $id)
    {
        $employee = User::with('esquemaActual')->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,'.$employee->id,
            'role' => 'required|string',
            'salario_fijo' => 'numeric|min:0',
            'comision_servicio' => 'numeric|min:0|max:100',
            'comision_producto' => 'numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // 1. Verificar si el porcentaje de comisión cambió
        $comisionActual = $employee->esquemaActual ? $employee->esquemaActual->porcentaje_comision : $employee->comision_servicio;
        $nuevaComision = $request->comision_servicio;

        if (floatval($comisionActual) !== floatval($nuevaComision)) {
            
            // Cerrar el esquema actual (venció ayer)
            if ($employee->esquemaActual) {
                $employee->esquemaActual->update([
                    'vigente_hasta' => Carbon::yesterday()
                ]);
            }

            // Crear el nuevo esquema (vigente desde hoy)
            EsquemaComision::create([
                'empleado_id' => $employee->id,
                'porcentaje_comision' => $nuevaComision,
                'vigente_desde' => Carbon::today(),
            ]);
        }

        // 2. Actualizar los demás datos del usuario
        $data = $request->except('password');
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $employee->update($data);

        return response()->json(['message' => 'Datos del empleado actualizados correctamente']);
    }

    // Desactivar un empleado (En ERPs no se borran para no quebrar las facturas viejas)
    public function destroy($id)
    {
        $employee = User::findOrFail($id);
        $employee->update(['is_active' => false]);

        return response()->json(['message' => 'Empleado dado de baja del sistema']);
    }
}