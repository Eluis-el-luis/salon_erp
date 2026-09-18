<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CajaChica;
use App\Models\TipoGasto;
use App\Models\MovimientoCajaChica;
use App\Models\CuentaContable;
use App\Services\ContabilidadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CajaChicaController extends Controller
{
    public function index()
    {
        // 1. Truco: Crear tipos de gasto de prueba si no existen
        if (TipoGasto::count() == 0) {
            $cuentaGasto = CuentaContable::where('codigo', 'like', '6.%')->where('permite_movimiento', true)->first();
            if ($cuentaGasto) {
                TipoGasto::create(['codigo' => 'TG-01', 'nombre' => 'Insumos de Cafetería y Limpieza', 'cuenta_contable_id' => $cuentaGasto->id]);
                TipoGasto::create(['codigo' => 'TG-02', 'nombre' => 'Transporte y Viáticos', 'cuenta_contable_id' => $cuentaGasto->id]);
            }
        }

        // 2. Buscar si el usuario actual tiene una caja chica, si no, crearle una con C$ 2,000 de fondo base
        $caja = CajaChica::firstOrCreate(
            ['responsable_id' => auth()->id(), 'estado' => 'activa'],
            ['codigo' => 'CCH-' . auth()->id(), 'monto_fondo' => 2000]
        );

        $tiposGasto = TipoGasto::orderBy('nombre', 'asc')->get();
        
        // 3. Traer los gastos registrados
        $movimientos = MovimientoCajaChica::with('tipoGasto')
            ->where('caja_chica_id', $caja->id)
            ->orderBy('fecha', 'desc')
            ->orderBy('id', 'desc')
            ->get();
            
        // 4. Calcular saldo matemático
        $totalGastado = $movimientos->sum('monto');
        $saldoDisponible = $caja->monto_fondo - $totalGastado;

        return view('petty_cash.index', compact('caja', 'tiposGasto', 'movimientos', 'saldoDisponible', 'totalGastado'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'caja_chica_id' => 'required|exists:cajas_chicas,id',
            'tipo_gasto_id' => 'required|exists:tipos_gasto,id',
            'descripcion' => 'required|string|max:255',
            'monto' => 'required|numeric|min:1'
        ]);

        DB::beginTransaction();

        try {
            $caja = CajaChica::findOrFail($request->caja_chica_id);
            
            // Validar que la caja tenga dinero suficiente
            $gastado = MovimientoCajaChica::where('caja_chica_id', $caja->id)->sum('monto');
            $disponible = $caja->monto_fondo - $gastado;
            
            if ($request->monto > $disponible) {
                return back()->withErrors(['error' => 'No hay fondos suficientes. Saldo disponible: C$ ' . number_format($disponible, 2)]);
            }

            // Registrar el movimiento
            $movimiento = MovimientoCajaChica::create([
                'caja_chica_id' => $caja->id,
                'tipo_gasto_id' => $request->tipo_gasto_id,
                'descripcion' => $request->descripcion,
                'monto' => $request->monto,
                'fecha' => Carbon::now(),
            ]);

            // Disparar motor contable
            $contabilidad = new ContabilidadService();
            $movimiento->load('tipoGasto'); // Cargar la relación para que el servicio la lea
            $contabilidad->contabilizarGastoCajaChica($movimiento);

            DB::commit();

            return back()->with('success', 'Gasto menor registrado y contabilizado.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al registrar gasto de caja chica', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return back()->withErrors(['error' => 'Error al registrar el gasto. Contacte al administrador.']);
        }
    }
}