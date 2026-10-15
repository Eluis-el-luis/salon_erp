<?php

use Illuminate\Support\Facades\Route;
use App\Models\Cita;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\EmpleadoController;
use App\Http\Controllers\AsistenciaController;
use App\Http\Controllers\AusenciaController;
use App\Http\Controllers\RrhhController;
use App\Http\Controllers\AdelantoController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ServicioController;
use App\Http\Controllers\VentaController;
use App\Http\Controllers\RespaldoController;
use App\Http\Controllers\NominaController;
use App\Http\Controllers\FormulaController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\FacturaCompraController;
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
    // CUSTOMER 360: perfil financiero del cliente (citas, facturas, CxC)
    Route::get('/clientes/{id}', [ClienteController::class, 'show']);


    // -------------------------------------------------------------
    // NIVEL 2: CAJA Y RECEPCIÓN (Accede 'recepcion' y 'admin')
    // -------------------------------------------------------------
    // Manejo de dinero, punto de venta y cuentas por cobrar.
    
    Route::middleware(['role:recepcion,contador'])->group(function () {
        
        Route::get('/pos', function () {
            // Fase 1 Administración: variantes de precio y fórmulas (BOM) para
            // el selector de variantes y el editor de consumo real en el POS.
            $servicios = \App\Models\Servicio::with(['variantes', 'formulas.articulo'])
                ->where('is_active', true)
                ->orderBy('name', 'asc')
                ->get();
            $productos = \App\Models\Articulo::where('type', '!=', 'servicio')->orderBy('producto', 'asc')->get();
            $clientes = \App\Models\Cliente::with('grupo')->orderBy('name', 'asc')->get();

            return view('sales.pos', compact('servicios', 'productos', 'clientes'));
        });

        Route::post('/sales', [VentaController::class, 'store']);
        Route::post('/sales/bill-appointment/{appointment}', [VentaController::class, 'facturarCita']);

        Route::get('/historial-ventas', [VentaController::class, 'index']);
        Route::get('/ventas/{id}/ticket', [VentaController::class, 'ticket']);
        Route::get('/ventas/{id}/documento', [VentaController::class, 'documento']);
        Route::post('/ventas/{id}/cobrar', [VentaController::class, 'cobrar']);
        // MANDAMIENTO: anular facturas es privilegio exclusivo del administrador
        Route::post('/ventas/{id}/anular', [VentaController::class, 'anular'])->middleware('role:admin');

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
        Route::post('/mesa-cambio/tasa-oficial', [MesaCambioController::class, 'registrarTasaOficial'])->name('mesa.tasa.oficial');
    });


    // -------------------------------------------------------------
    // NIVEL 3: FINANZAS (Administrador + Contador)
    // -------------------------------------------------------------
    // Todo lo contable-financiero es territorio del contador (además del admin,
    // que siempre tiene bypass global): reportes, contabilidad, catálogo,
    // asientos, periodos, bancos, CxP y retiros.
    Route::middleware(['role:admin,contador'])->group(function () {

        // Reportes Financieros
        Route::get('/reportes', [ReporteController::class, 'index']);
        Route::get('/reportes/excel', [ReporteController::class, 'exportExcel']);
        Route::get('/reportes/pdf', [ReporteController::class, 'exportPdf']);

        // Contabilidad
        Route::get('/contabilidad', [ContabilidadController::class, 'diario']);
        Route::post('/contabilidad/gasto', [ContabilidadController::class, 'storeGasto']);
        Route::get('/contabilidad/mayor', [ContabilidadController::class, 'mayor']);
        Route::get('/contabilidad/resultados', [ContabilidadController::class, 'resultados'])->name('contabilidad.resultados');
        Route::get('/contabilidad/balance', [PeriodoContableController::class, 'balanceGeneral'])->name('contabilidad.balance');

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

        Route::get('/cuentas-por-pagar', [CuentaPorPagarController::class, 'index']);
        Route::post('/cuentas-por-pagar/abonar', [CuentaPorPagarController::class, 'store']);

        Route::get('/bancos', [BancoController::class, 'index']);
        Route::post('/bancos/depositar', [BancoController::class, 'depositar']);
        Route::post('/bancos/transferir', [BancoController::class, 'transferir']);
        Route::post('/bancos/cargo', [BancoController::class, 'registrarCargo'])->name('bancos.cargo');
        Route::post('/bancos/abono', [BancoController::class, 'registrarAbono'])->name('bancos.abono');
        Route::get('/bancos/{cuenta_bancaria}/libro', [BancoController::class, 'libro'])->name('bancos.libro');
        Route::get('/bancos/{cuenta_bancaria}/conciliacion', [BancoController::class, 'conciliacion'])->name('bancos.conciliacion');
        Route::post('/bancos/{cuenta_bancaria}/conciliacion', [BancoController::class, 'guardarConciliacion'])->name('bancos.conciliar');

        Route::get('/retiros', [RetiroController::class, 'index']);
        Route::post('/retiros', [RetiroController::class, 'depositar']);

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
        });
    });

    // -------------------------------------------------------------
    // NIVEL 4: EXCLUSIVO DEL ADMINISTRADOR
    // -------------------------------------------------------------
    // Operaciones críticas del negocio que ningún otro empleado debe ver:
    // recursos humanos, inventario, catálogos operativos y respaldos.
    Route::middleware(['role:admin'])->group(function () {

        // Recursos Humanos y Nómina (HRMS corporativo)
        Route::get('/empleados', [EmpleadoController::class, 'index']);
        Route::post('/empleados', [EmpleadoController::class, 'store']);
        Route::put('/empleados/{id}', [EmpleadoController::class, 'update']);
        Route::delete('/empleados/{id}', [EmpleadoController::class, 'destroy']);

        // Dashboard RRHH, Perfil 360, Turnos y Propinas
        Route::get('/rrhh', [RrhhController::class, 'index']);
        Route::get('/rrhh/colaborador/{id}', [RrhhController::class, 'perfil']);
        Route::get('/rrhh/turnos', [RrhhController::class, 'turnos']);
        Route::post('/rrhh/turnos', [RrhhController::class, 'turnosStore']);
        Route::put('/rrhh/turnos/{id}', [RrhhController::class, 'turnosUpdate']);
        Route::post('/rrhh/turnos/asignar', [RrhhController::class, 'turnosAsignar']);
        Route::get('/rrhh/propinas', [RrhhController::class, 'propinas']);
        Route::post('/rrhh/propinas', [RrhhController::class, 'propinasStore']);

        // Gestión de Permisos (Leave Management)
        Route::get('/ausencias', [AusenciaController::class, 'index']);
        Route::post('/ausencias', [AusenciaController::class, 'store']);
        Route::post('/ausencias/{id}/aprobar', [AusenciaController::class, 'aprobar']);
        Route::post('/ausencias/{id}/rechazar', [AusenciaController::class, 'rechazar']);
        Route::post('/ausencias/{id}/cancelar', [AusenciaController::class, 'cancelar']);

        // Nómina: wizard con previsualización, corridas, colillas y estructuras
        Route::get('/nomina', [NominaController::class, 'index']);
        Route::get('/nomina/wizard', [NominaController::class, 'wizard']);
        Route::post('/nomina/previsualizar', [NominaController::class, 'previsualizar']);
        Route::post('/nomina/procesar', [NominaController::class, 'procesar']);
        Route::get('/nomina/estructuras', [NominaController::class, 'estructuras']);
        Route::post('/nomina/estructuras', [NominaController::class, 'estructurasStore']);
        Route::post('/nomina/estructuras/{id}/toggle', [NominaController::class, 'estructurasToggle']);
        Route::get('/nomina/corrida/{id}', [NominaController::class, 'corrida']);
        Route::post('/nomina/corrida/{id}/anular', [NominaController::class, 'anular']);
        Route::get('/nomina/colilla/{id}', [NominaController::class, 'colilla']);
        // Colilla de planillas legacy (pre-HRMS), solo lectura
        Route::get('/nomina/legacy/{id}/ticket', [NominaController::class, 'ticketLegacy']);

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
        Route::post('/inventario/kardex/apertura', [InventarioController::class, 'registrarApertura'])->name('inventario.kardex.apertura');
        Route::get('/inventario/alertas-caducidad', [InventarioController::class, 'alertasCaducidad'])->name('inventario.alertas.caducidad');

        // Facturas de compra: detalle auditable y anulación con reversión
        Route::get('/compras/{id}', [FacturaCompraController::class, 'show'])->name('compras.show');
        Route::post('/compras/{id}/anular', [FacturaCompraController::class, 'anular'])->name('compras.anular');

        Route::get('/proveedores', [ProveedorController::class, 'index']);
        Route::get('/proveedores/{id}', [ProveedorController::class, 'show'])->name('proveedores.show');
        Route::post('/proveedores', [ProveedorController::class, 'store']);
        Route::put('/proveedores/{id}', [ProveedorController::class, 'update']);
        Route::delete('/proveedores/{id}', [ProveedorController::class, 'destroy']);

        // Configuración de Catálogos y Fórmulas
        Route::get('/servicios', [ServicioController::class, 'index']);
        Route::post('/servicios', [ServicioController::class, 'store']);
        Route::put('/servicios/{id}', [ServicioController::class, 'update']);
        Route::delete('/servicios/{id}', [ServicioController::class, 'destroy']);
        // Fase 1: alta rápida de categorías de servicios
        Route::post('/servicios/categorias', [ServicioController::class, 'storeCategoria']);

        Route::get('/formulas', [FormulaController::class, 'index']);
        Route::post('/formulas', [FormulaController::class, 'store']);
        Route::put('/formulas/{id}', [FormulaController::class, 'update']);
        Route::delete('/formulas/{id}', [FormulaController::class, 'destroy']);

        // Fase 1: grupos de clientes (fidelización y descuento automático)
        Route::post('/clientes/grupos', [ClienteController::class, 'storeGrupo']);

        // Seguridad del Sistema
        Route::get('/backups', [RespaldoController::class, 'index']);
        Route::get('/backups/descargar', [RespaldoController::class, 'download']);
        Route::post('/backups/restaurar', [RespaldoController::class, 'restore']);
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
