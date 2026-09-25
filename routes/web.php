<?php

use Illuminate\Support\Facades\Route;
use App\Models\Cita;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\EmpleadoController;
use App\Http\Controllers\AsistenciaController;
use App\Http\Controllers\AdelantoController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ServicioController;
use App\Http\Controllers\VentaController;
use App\Http\Controllers\RespaldoController;
use App\Http\Controllers\NominaController;
use App\Http\Controllers\FormulaController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\AutenticacionController;
use App\Http\Controllers\ContabilidadController;
use App\Http\Controllers\CajaController;
use App\Http\Controllers\CuentaPorPagarController;
use App\Http\Controllers\CajaChicaController;
use App\Http\Controllers\BancoController;
use App\Http\Controllers\MesaCambioController;
use App\Http\Controllers\RetiroController;
use App\Http\Controllers\MermaController;
use App\Http\Controllers\PortalColaboradorController;
use App\Http\Controllers\CuentaContableController;
use App\Http\Controllers\AsientoManualController;
use App\Http\Controllers\CentroCostoController;
use App\Http\Controllers\PeriodoContableController;

// =================================================================
// RUTAS PÚBLICAS (No requieren sesión)
// =================================================================
Route::get('/login', [AutenticacionController::class, 'mostrarFormularioLogin'])->name('login');
Route::post('/login', [AutenticacionController::class, 'login'])->middleware('throttle:5,1');
Route::post('/logout', [AutenticacionController::class, 'logout'])->name('logout');

// =================================================================
// TODAS LAS RUTAS PROTEGIDAS (Requieren inicio de sesión)
// =================================================================
Route::middleware(['auth'])->group(function () {

    // -------------------------------------------------------------
    // NIVEL 1: GENERAL (Todos los roles: Admin, Recepción, Caja)
    // -------------------------------------------------------------
    // El dashboard, agendar citas, ver clientes y marcar asistencia 
    // son operaciones básicas que todo el equipo necesita hacer.
    
    Route::get('/', [PanelController::class, 'index']);
    
    Route::get('/agenda', function () {
        $citas = Cita::with(['cliente', 'servicio'])
                            ->whereDate('appointment_date', today())
                            ->orderBy('appointment_date', 'asc')
                            ->get();

        $clientes = \App\Models\Cliente::orderBy('name', 'asc')->get();
        $servicios = \App\Models\Servicio::where('is_active', true)->orderBy('name', 'asc')->get();
        $estilistas = \App\Models\Usuario::where('role', 'estilista')->where('is_active', true)->get();

        return view('appointments.index', compact('citas', 'clientes', 'servicios', 'estilistas'));
    });

    Route::get('/asistencia', [AsistenciaController::class, 'index']);
    Route::post('/asistencia', [AsistenciaController::class, 'store']);

    Route::get('/clientes', [ClienteController::class, 'index']);
    Route::post('/clientes', [ClienteController::class, 'store']);
    Route::put('/clientes/{id}', [ClienteController::class, 'update']);
    Route::delete('/clientes/{id}', [ClienteController::class, 'destroy']);


    // -------------------------------------------------------------
    // NIVEL 2: CAJA Y RECEPCIÓN (Accede 'recepcion' y 'admin')
    // -------------------------------------------------------------
    // Manejo de dinero, punto de venta y cuentas por cobrar.
    
    Route::middleware(['role:recepcion,contador'])->group(function () {
        
        Route::get('/pos', function () {
            $servicios = \App\Models\Servicio::where('is_active', true)->orderBy('name', 'asc')->get();
            $productos = \App\Models\Articulo::where('type', '!=', 'servicio')->orderBy('producto', 'asc')->get();
            $clientes = \App\Models\Cliente::orderBy('name', 'asc')->get();
            
            return view('sales.pos', compact('servicios', 'productos', 'clientes'));
        });

        Route::post('/sales', [VentaController::class, 'store']);
        Route::post('/sales/bill-appointment/{appointment}', [VentaController::class, 'facturarCita']);

        Route::get('/historial-ventas', [VentaController::class, 'index']);
        Route::get('/ventas/{id}/ticket', [VentaController::class, 'ticket']);

        Route::get('/adelantos', [AdelantoController::class, 'index']);
        Route::post('/adelantos', [AdelantoController::class, 'store']);
        Route::delete('/adelantos/{id}', [AdelantoController::class, 'destroy']);

        Route::get('/caja/arqueo', [CajaController::class, 'index']);
        Route::post('/caja/abrir', [CajaController::class, 'open']);
        Route::post('/caja/cerrar', [CajaController::class, 'close']);

        Route::get('/caja-chica', [CajaChicaController::class, 'index']);
        Route::post('/caja-chica/gasto', [CajaChicaController::class, 'store']);

        Route::get('/mesa-cambio', [MesaCambioController::class, 'index']);
        Route::post('/mesa-cambio/operar', [MesaCambioController::class, 'store']);
    });


    // -------------------------------------------------------------
    // NIVEL 3: ADMINISTRADOR (Acceso exclusivo Gerencia)
    // -------------------------------------------------------------
    // Operaciones críticas del negocio que ningún otro empleado debe ver.
    
    Route::middleware(['role:admin'])->group(function () {
        
        // Reportes Financieros
        Route::get('/reportes', [ReporteController::class, 'index']);
        Route::get('/reportes/excel', [ReporteController::class, 'exportExcel']);
        Route::get('/reportes/pdf', [ReporteController::class, 'exportPdf']);

        // Recursos Humanos y Nómina
        Route::get('/empleados', [EmpleadoController::class, 'index']);
        Route::post('/empleados', [EmpleadoController::class, 'store']);
        Route::put('/empleados/{id}', [EmpleadoController::class, 'update']);
        Route::delete('/empleados/{id}', [EmpleadoController::class, 'destroy']);
        
        Route::get('/nomina', [NominaController::class, 'index']);
        Route::post('/nomina/generar', [NominaController::class, 'store']);
        Route::get('/nomina/{id}/ticket', [NominaController::class, 'ticket']);
        Route::get('/nomina/{id}/ticket', [NominaController::class, 'ticket']);
        
        // Control de Inventario y Proveedores
        Route::get('/inventario', [InventarioController::class, 'index']);
        Route::post('/inventario', [InventarioController::class, 'store']);
        Route::put('/inventario/{id}', [InventarioController::class, 'update']);
        Route::delete('/inventario/{id}', [InventarioController::class, 'destroy']);
        Route::get('/inventario/comprar', [InventarioController::class, 'createCompra']);
        Route::post('/inventario/comprar', [InventarioController::class, 'registrarCompra']);
        Route::get('/mermas', [MermaController::class, 'index']);
        Route::post('/mermas', [MermaController::class, 'store']);
        Route::get('/inventario/kardex', [InventarioController::class, 'kardex']);
        Route::get('/inventario/kardex/excel', [InventarioController::class, 'kardexExcel'])->name('inventario.kardex.excel');
        Route::get('/inventario/kardex/pdf', [InventarioController::class, 'kardexPdf'])->name('inventario.kardex.pdf');
        Route::get('/inventario/alertas-caducidad', [InventarioController::class, 'alertasCaducidad'])->name('inventario.alertas.caducidad');
        
        Route::get('/proveedores', [ProveedorController::class, 'index']);
        Route::post('/proveedores', [ProveedorController::class, 'store']);
        Route::put('/proveedores/{id}', [ProveedorController::class, 'update']);
        Route::delete('/proveedores/{id}', [ProveedorController::class, 'destroy']);
        
        // Configuración de Catálogos y Fórmulas
        Route::get('/servicios', [ServicioController::class, 'index']);
        Route::post('/servicios', [ServicioController::class, 'store']);
        Route::put('/servicios/{id}', [ServicioController::class, 'update']);
        Route::delete('/servicios/{id}', [ServicioController::class, 'destroy']);
        
        Route::get('/formulas', [FormulaController::class, 'index']);
        Route::post('/formulas', [FormulaController::class, 'store']);
        Route::delete('/formulas/{id}', [FormulaController::class, 'destroy']);
        
        // Seguridad del Sistema
        Route::get('/backups', [RespaldoController::class, 'index']);
        Route::get('/backups/descargar', [RespaldoController::class, 'download']);
        Route::post('/backups/restaurar', [RespaldoController::class, 'restore']);
        
        // Contabilidad
        Route::get('/contabilidad', [ContabilidadController::class, 'diario']);
        Route::post('/contabilidad/gasto', [ContabilidadController::class, 'storeGasto']);
        Route::get('/contabilidad/mayor', [ContabilidadController::class, 'mayor']);
        Route::get('/contabilidad/resultados', [ContabilidadController::class, 'resultados'])->name('contabilidad.resultados');
        Route::get('/contabilidad/balance', [PeriodoContableController::class, 'balanceGeneral'])->name('contabilidad.balance');
        
        // Catálogo de Cuentas
        Route::get('/catalogo', [CuentaContableController::class, 'index'])->name('catalogo.index');
        Route::get('/catalogo/tree', [CuentaContableController::class, 'tree'])->name('catalogo.tree');
        Route::post('/catalogo', [CuentaContableController::class, 'store'])->name('catalogo.store');
        Route::put('/catalogo/{id}', [CuentaContableController::class, 'update'])->name('catalogo.update');
        Route::delete('/catalogo/{id}', [CuentaContableController::class, 'destroy'])->name('catalogo.destroy');
        Route::get('/catalogo/plantilla', [CuentaContableController::class, 'descargarPlantilla'])->name('catalogo.plantilla');
        Route::post('/catalogo/importar', [CuentaContableController::class, 'importar'])->name('catalogo.importar');
        Route::get('/catalogo/exportar', [CuentaContableController::class, 'exportar'])->name('catalogo.exportar');
        
        Route::get('/cuentas-por-pagar', [CuentaPorPagarController::class, 'index']);
        Route::post('/cuentas-por-pagar/abonar', [CuentaPorPagarController::class, 'store']);
        
        Route::get('/bancos', [BancoController::class, 'index']);
        Route::post('/bancos/depositar', [BancoController::class, 'depositar']);
        
        Route::get('/retiros', [RetiroController::class, 'index']);
        Route::post('/retiros', [RetiroController::class, 'depositar']);
        
        // Catálogo de Cuentas
        Route::prefix('catalogo')->name('catalogo.')->group(function () {
            Route::get('/', [CuentaContableController::class, 'index'])->name('index');
            Route::get('/tree', [CuentaContableController::class, 'tree'])->name('tree');
            Route::post('/', [CuentaContableController::class, 'store'])->name('store');
            Route::put('/{id}', [CuentaContableController::class, 'update'])->name('update');
            Route::delete('/{id}', [CuentaContableController::class, 'destroy'])->name('destroy');
            Route::get('/plantilla', [CuentaContableController::class, 'descargarPlantilla'])->name('plantilla');
            Route::post('/importar', [CuentaContableController::class, 'importar'])->name('importar');
            Route::get('/exportar', [CuentaContableController::class, 'exportar'])->name('exportar');
        });

        // Asientos Manuales
        Route::prefix('asientos')->name('asientos.')->group(function () {
            Route::get('/', [AsientoManualController::class, 'index'])->name('index');
            Route::get('/crear', [AsientoManualController::class, 'create'])->name('create');
            Route::post('/', [AsientoManualController::class, 'store'])->name('store');
            Route::get('/{id}', [AsientoManualController::class, 'show'])->name('show');
            Route::post('/{id}/reversar', [AsientoManualController::class, 'reversar'])->name('reversar');
        });

        // Periodos Contables y Cierre Fiscal
        Route::prefix('periodos')->name('periodos.')->group(function () {
            Route::get('/', [PeriodoContableController::class, 'index'])->name('index');
            Route::get('/create', [PeriodoContableController::class, 'create'])->name('create');
            Route::post('/', [PeriodoContableController::class, 'store'])->name('store');
            Route::get('/{periodo}', [PeriodoContableController::class, 'show'])->name('show');
            Route::post('/{periodo}/cierre', [PeriodoContableController::class, 'cierreFiscal'])->name('cierre');
            Route::get('/{periodo}', [PeriodoContableController::class, 'show'])->name('show');
        });
    });

        Route::prefix('portal')->name('portal.')->group(function () {
        Route::get('/login', [PortalColaboradorController::class, 'showLogin'])->name('login');
        Route::post('/login', [PortalColaboradorController::class, 'login'])->name('login.post');
        Route::post('/logout', [PortalColaboradorController::class, 'logout'])->name('logout');

        Route::middleware(['auth'])->group(function () {
            Route::get('/dashboard', [PortalColaboradorController::class, 'dashboard'])->name('dashboard');
            Route::get('/comisiones', [PortalColaboradorController::class, 'comisiones'])->name('comisiones');
            Route::get('/nominas', [PortalColaboradorController::class, 'nominas'])->name('nominas');
            Route::get('/adelantos', [PortalColaboradorController::class, 'adelantos'])->name('adelantos');
            Route::get('/perfil', [PortalColaboradorController::class, 'perfil'])->name('perfil');
            Route::post('/perfil/password', [PortalColaboradorController::class, 'updatePassword'])->name('perfil.password');
        });
    });
});
