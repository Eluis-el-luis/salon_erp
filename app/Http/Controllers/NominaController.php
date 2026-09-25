<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Usuario;
use App\Models\Nomina;
use App\Models\ComisionGenerada;
use App\Models\Adelanto;
use App\Models\AdelantoCuota;
use App\Services\ContabilidadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

            // 2. Buscar adelantos y sus cuotas vencidas en el período
            $adelantosQuery = Adelanto::where('user_id', $usuario->id)
                ->whereBetween('date', [$fechaInicio->toDateString(), $fechaFin->toDateString()]);
            
            $totalAdelantos = $adelantosQuery->sum('amount');

            // FASE 5 AVANZADA: Amortización de adelantos por cuotas
            // Solo descontar las cuotas vencidas en este período
            $adelantosConCuotas = Adelanto::where('user_id', $usuario->id)
                ->whereHas('cuotas', function ($q) use ($fechaInicio, $fechaFin) {
                    $q->whereBetween('fecha_vencimiento', [$fechaInicio->toDateString(), $fechaFin->toDateString()])
                      ->whereIn('estado', ['pendiente', 'parcial']);
                })->with(['cuotas' => function ($q) use ($fechaInicio, $fechaFin) {
                    $q->whereBetween('fecha_vencimiento', [$fechaInicio->toDateString(), $fechaFin->toDateString()])
                      ->whereIn('estado', ['pendiente', 'parcial']);
                }])->get();

            $totalCuotasVencidas = $adelantosConCuotas->flatMap->cuotas
                ->whereIn('estado', ['pendiente', 'parcial'])
                ->whereBetween('fecha_vencimiento', [$fechaInicio->toDateString(), $fechaFin->toDateString()])
                ->sum('monto');

            // Si hay cuotas vencidas, usar esas en lugar del total de adelantos
            $totalAdelantosEfectivo = $totalCuotasVencidas > 0 ? $totalCuotasVencidas : $totalAdelantos;

            // Marcar cuotas como pagadas al generar la nómina
            foreach ($adelantosConCuotas as $adelanto) {
                foreach ($adelanto->cuotas as $cuota) {
                    if ($cuota->estado !== 'pagada' && 
                        $cuota->fecha_vencimiento >= $fechaInicio->toDateString() && 
                        $cuota->fecha_vencimiento <= $fechaFin->toDateString()) {
                        $cuota->update([
                            'estado' => 'pagada',
                            'monto_pagado' => $cuota->monto,
                            'fecha_pago' => now()->toDateString(),
                        ]);
                    }
                }
            }

            // 3. Salario Base, Deducciones de Ley y Total Neto a Pagar
            $salarioActivo = $usuario->salario_fijo;
            $totalDevengado = $salarioActivo + $totalComisiones;

            // FASE 5: Deducciones legales (INSS/IR) configurables
            $cfg = config('salon.nomina');
            $inssEmpleado = round($totalDevengado * (float) $cfg['inss_empleado'], 2);
            $baseIR = max(0, $totalDevengado - (float) $cfg['ir_exento']);
            $impuestoRenta = round($baseIR * (float) $cfg['ir_tasa'], 2);
            $retenciones = round($inssEmpleado + $impuestoRenta, 2);

            $totalAPagar = ($totalDevengado - $totalAdelantosEfectivo) - $retenciones;

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
                'salary_advances' => $totalAdelantosEfectivo,
                'loan_payments' => 0,
                'inss_empleado' => $inssEmpleado,
                'impuesto_renta' => $impuestoRenta,
                'retenciones_totales' => $retenciones,
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
            Log::error('Error al generar nómina', [
                'user_id' => $request->user_id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return redirect()->back()->withErrors(['error' => 'Error al generar la nómina. Contacte al administrador.']);
        }
    }

    public function ticket($id)
    {
        $nomina = Nomina::with('usuario')->findOrFail($id);
        return view('payrolls.ticket', compact('nomina'));
    }
}