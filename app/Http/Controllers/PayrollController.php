<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Payroll;
use App\Models\ComisionGenerada;
use App\Models\Advance;
use App\Services\ContabilidadService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PayrollController extends Controller
{
    public function index()
    {
        $payrolls = Payroll::with('user')->orderBy('created_at', 'desc')->get();
        
        $employees = User::where('is_active', true)
            ->whereIn('role', ['estilista', 'recepcion', 'admin'])
            ->orderBy('name', 'asc')
            ->get();
            
        return view('payrolls.index', compact('payrolls', 'employees'));
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
            $user = User::findOrFail($request->user_id);
            $startDate = Carbon::parse($request->start_date)->startOfDay();
            $endDate = Carbon::parse($request->end_date)->endOfDay();

            // 1. Extraer las COMISIONES YA GENERADAS e intocables[cite: 6]
            $comisionesPendientes = ComisionGenerada::where('empleado_id', $user->id)
                ->whereBetween('fecha', [$startDate, $endDate])
                ->where('estado', 'pendiente')
                ->get();

            // Sumamos el total (ahora unificado en una sola bolsa de comisiones)
            $totalComisiones = $comisionesPendientes->sum('monto_comision');

            // 2. Buscar si pidió adelantos de salario (Deducciones)
            // Asumiendo que tu modelo Advance tiene un status 'pendiente'
            $advancesQuery = Advance::where('user_id', $user->id)
                ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()]);
            
            $advancesTotal = $advancesQuery->sum('amount');

            // 3. Salario Base y Total Neto a Pagar
            $activeSalary = $user->salario_fijo;
            $totalToPay = ($activeSalary + $totalComisiones) - $advancesTotal;

            // 4. Crear la Planilla directamente como PAGADA para disparar la contabilidad
            $payroll = Payroll::create([
                'user_id' => $user->id,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'active_salary' => $activeSalary,
                'services_commission' => $totalComisiones, // Guardamos el total aquí
                'products_commission' => 0, // Ya no necesitamos separarlo, la comision generada lo hizo por nosotros
                'extra_bonus' => 0,
                'sunday_bonus' => 0,
                'salary_advances' => $advancesTotal,
                'loan_payments' => 0,
                'total_to_pay' => $totalToPay > 0 ? $totalToPay : 0,
                'status' => 'pagada' // Cambiado de borrador a pagada[cite: 6]
            ]);

            // 5. Vincular las comisiones a esta planilla y cerrarlas para que no se paguen doble[cite: 6]
            if ($comisionesPendientes->count() > 0) {
                ComisionGenerada::whereIn('id', $comisionesPendientes->pluck('id'))->update([
                    'periodo_nomina_id' => $payroll->id,
                    'estado' => 'pagada'
                ]);
            }

            // (Opcional) Marcar los adelantos como 'pagados' si tienes un campo de estado en Advance
            // $advancesQuery->update(['status' => 'pagado']);

            // 6. Disparar Motor Contable
            $contabilidad = new ContabilidadService();
            // Por defecto asumimos que la nómina se transfiere del Banco, o podrías mandar 'efectivo'
            $contabilidad->contabilizarNomina($payroll, 'banco');

            DB::commit();

            return redirect()->back()->with('success', 'Nómina liquidada, comisiones pagadas y contabilidad actualizada con éxito.');

        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors(['error' => 'Error al generar la nómina: ' . $e->getMessage()]);
        }
    }

    public function ticket($id)
    {
        $payroll = Payroll::with('user')->findOrFail($id);
        return view('payrolls.ticket', compact('payroll'));
    }
}