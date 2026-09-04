<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Usuario;
use App\Models\Nomina;
use App\Models\ComisionGenerada;
use App\Models\Adelanto;
use App\Services\ContabilidadService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class NominaController extends Controller
{
    public function index()
    {
        $nominas = Nomina::with('usuario')->orderBy('created_at', 'desc')->get();
        
        $empleados = Usuario::where('is_active', true)
            ->whereIn('role', ['estilista', 'recepcion', 'admin'])
            ->orderBy('name', 'asc')
            ->get();
            
        return view('payrolls.index', compact('nominas', 'empleados'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        DB::beginTransaction();

        try {
            $usuario = Usuario::findOrFail($request->user_id);
            $fechaInicio = Carbon::parse($request->start_date)->startOfDay();
            $fechaFin = Carbon::parse($request->end_date)->endOfDay();

            // 1. Extraer las COMISIONES YA GENERADAS e intocables
            $comisionesPendientes = ComisionGenerada::where('empleado_id', $usuario->id)
                ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                ->where('estado', 'pendiente')
                ->get();

            // Sumamos el total (ahora unificado en una sola bolsa de comisiones)
            $totalComisiones = $comisionesPendientes->sum('monto_comision');

            // 2. Buscar si pidió adelantos de salario (Deducciones)
            $adelantosQuery = Adelanto::where('user_id', $usuario->id)
                ->whereBetween('date', [$fechaInicio->toDateString(), $fechaFin->toDateString()]);
            
            $totalAdelantos = $adelantosQuery->sum('amount');

            // 3. Salario Base y Total Neto a Pagar
            $salarioActivo = $usuario->salario_fijo;
            $totalAPagar = ($salarioActivo + $totalComisiones) - $totalAdelantos;

            // 4. Crear la Planilla directamente como PAGADA para disparar la contabilidad
            $nomina = Nomina::create([
                'user_id' => $usuario->id,
                'start_date' => $fechaInicio->toDateString(),
                'end_date' => $fechaFin->toDateString(),
                'active_salary' => $salarioActivo,
                'services_commission' => $totalComisiones, // Guardamos el total aquí
                'products_commission' => 0, // Ya no necesitamos separarlo, la comision generada lo hizo por nosotros
                'extra_bonus' => 0,
                'sunday_bonus' => 0,
                'salary_advances' => $totalAdelantos,
                'loan_payments' => 0,
                'total_to_pay' => $totalAPagar > 0 ? $totalAPagar : 0,
                'status' => 'pagada'
            ]);

            // 5. Vincular las comisiones a esta planilla y cerrarlas para que no se paguen doble
            if ($comisionesPendientes->count() > 0) {
                ComisionGenerada::whereIn('id', $comisionesPendientes->pluck('id'))->update([
                    'periodo_nomina_id' => $nomina->id,
                    'estado' => 'pagada'
                ]);
            }

            // 6. Disparar Motor Contable
            $contabilidad = new ContabilidadService();
            // Por defecto asumimos que la nómina se transfiere del Banco, o podrías mandar 'efectivo'
            $contabilidad->contabilizarNomina($nomina, 'banco');

            DB::commit();

            return redirect()->back()->with('success', 'Nómina liquidada, comisiones pagadas y contabilidad actualizada con éxito.');

        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors(['error' => 'Error al generar la nómina: ' . $e->getMessage()]);
        }
    }

    public function ticket($id)
    {
        $nomina = Nomina::with('usuario')->findOrFail($id);
        return view('payrolls.ticket', compact('nomina'));
    }
}