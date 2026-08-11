<?php

use Illuminate\Support\Facades\Route;
use App\Models\Appointment;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AdvanceController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\FormulaController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AccountingController;
use App\Http\Controllers\CashController;
use App\Http\Controllers\PayableController;
use App\Http\Controllers\PettyCashController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\ExchangeController;

// =================================================================
// RUTAS PÚBLICAS (No requieren sesión)
// =================================================================
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// =================================================================
// TODAS LAS RUTAS PROTEGIDAS (Requieren inicio de sesión)
// =================================================================
Route::middleware(['auth'])->group(function () {

    // -------------------------------------------------------------
    // NIVEL 1: GENERAL (Todos los roles: Admin, Recepción, Caja)
    // -------------------------------------------------------------
    // El dashboard, agendar citas, ver clientes y marcar asistencia 
    // son operaciones básicas que todo el equipo necesita hacer.
    
    Route::get('/', [DashboardController::class, 'index']);
    
    Route::get('/agenda', function () {
        $appointments = Appointment::with(['client', 'service'])
                            ->whereDate('appointment_date', today())
                            ->orderBy('appointment_date', 'asc')
                            ->get();

        $clients = \App\Models\Client::orderBy('name', 'asc')->get();
        $services = \App\Models\Service::where('is_active', true)->orderBy('name', 'asc')->get();
        $stylists = \App\Models\User::where('role', 'estilista')->where('is_active', true)->get();

        return view('appointments.index', compact('appointments', 'clients', 'services', 'stylists'));
    });

    Route::get('/asistencia', [AttendanceController::class, 'index']);
    Route::post('/asistencia', [AttendanceController::class, 'store']);

    Route::get('/clientes', [ClientController::class, 'index']);
    Route::post('/clientes', [ClientController::class, 'store']);
    Route::put('/clientes/{id}', [ClientController::class, 'update']);
    Route::delete('/clientes/{id}', [ClientController::class, 'destroy']);


    // -------------------------------------------------------------
    // NIVEL 2: CAJA Y RECEPCIÓN (Accede 'recepcion' y 'admin')
    // -------------------------------------------------------------
    // Manejo de dinero, punto de venta y cuentas por cobrar.
    
    Route::middleware(['role:recepcion'])->group(function () {
        
        Route::get('/pos', function () {
            $services = \App\Models\Service::where('is_active', true)->orderBy('name', 'asc')->get();
            $products = \App\Models\Item::where('type', '!=', 'servicio')->orderBy('producto', 'asc')->get();
            $clients = \App\Models\Client::orderBy('name', 'asc')->get();
            
            return view('sales.pos', compact('services', 'products', 'clients'));
        });

        Route::post('/sales', [SaleController::class, 'store']);
        Route::post('/sales/bill-appointment/{appointment}', [SaleController::class, 'billAppointment']);

        Route::get('/historial-ventas', [SaleController::class, 'index']);
        Route::get('/ventas/{id}/ticket', [SaleController::class, 'ticket']);

        Route::get('/adelantos', [AdvanceController::class, 'index']);
        Route::post('/adelantos', [AdvanceController::class, 'store']);
        Route::delete('/adelantos/{id}', [AdvanceController::class, 'destroy']);

        Route::get('/caja/arqueo', [CashController::class, 'index']);
        Route::post('/caja/abrir', [CashController::class, 'open']);
        Route::post('/caja/cerrar', [CashController::class, 'close']);

        Route::get('/caja-chica', [PettyCashController::class, 'index']);
        Route::post('/caja-chica/gasto', [PettyCashController::class, 'store']);

        Route::get('/mesa-cambio', [ExchangeController::class, 'index']);
        Route::post('/mesa-cambio/operar', [ExchangeController::class, 'store']);
    });


    // -------------------------------------------------------------
    // NIVEL 3: ADMINISTRADOR (Acceso exclusivo Gerencia)
    // -------------------------------------------------------------
    // Operaciones críticas del negocio que ningún otro empleado debe ver.
    
    Route::middleware(['role:admin'])->group(function () {
        
        // Reportes Financieros
        Route::get('/reportes', [ReportController::class, 'index']);
        Route::get('/reportes/excel', [ReportController::class, 'exportExcel']);
        Route::get('/reportes/pdf', [ReportController::class, 'exportPdf']);

        // Recursos Humanos y Nómina
        Route::get('/empleados', [EmployeeController::class, 'index']);
        Route::post('/empleados', [EmployeeController::class, 'store']);
        Route::put('/empleados/{id}', [EmployeeController::class, 'update']);
        Route::delete('/empleados/{id}', [EmployeeController::class, 'destroy']);
        
        Route::get('/nomina', [PayrollController::class, 'index']);
        Route::post('/nomina/generar', [PayrollController::class, 'store']);
        Route::get('/nomina/{id}/ticket', [PayrollController::class, 'ticket']);

        // Control de Inventario y Proveedores
        Route::get('/inventario', [InventoryController::class, 'index']);
        Route::post('/inventario', [InventoryController::class, 'store']);
        Route::put('/inventario/{id}', [InventoryController::class, 'update']);
        Route::delete('/inventario/{id}', [InventoryController::class, 'destroy']);
        Route::get('/inventario/comprar', [InventoryController::class, 'createCompra']);
        Route::post('/inventario/comprar', [InventoryController::class, 'registrarCompra']);
        
        Route::get('/proveedores', [ProviderController::class, 'index']);
        Route::post('/proveedores', [ProviderController::class, 'store']);
        Route::put('/proveedores/{id}', [ProviderController::class, 'update']);
        Route::delete('/proveedores/{id}', [ProviderController::class, 'destroy']);

        // Configuración de Catálogos y Fórmulas
        Route::get('/servicios', [ServiceController::class, 'index']);
        Route::post('/servicios', [ServiceController::class, 'store']);
        Route::put('/servicios/{id}', [ServiceController::class, 'update']);
        Route::delete('/servicios/{id}', [ServiceController::class, 'destroy']);
        
        Route::get('/formulas', [FormulaController::class, 'index']);
        Route::post('/formulas', [FormulaController::class, 'store']);
        Route::delete('/formulas/{id}', [FormulaController::class, 'destroy']);

        // Seguridad del Sistema
        Route::get('/backups', [BackupController::class, 'index']);
        Route::get('/backups/descargar', [BackupController::class, 'download']);
        Route::post('/backups/restaurar', [BackupController::class, 'restore']);

        // Contabilidad
        Route::get('/contabilidad', [AccountingController::class, 'diario']);
        Route::post('/contabilidad/gasto', [AccountingController::class, 'storeGasto']);
        Route::get('/contabilidad/mayor', [AccountingController::class, 'mayor']);
        Route::get('/contabilidad/resultados', [AccountingController::class, 'resultados']);

        Route::get('/cuentas-por-pagar', [PayableController::class, 'index']);
        Route::post('/cuentas-por-pagar/abonar', [PayableController::class, 'store']);

        Route::get('/bancos', [BankController::class, 'index']);
        Route::post('/bancos/depositar', [BankController::class, 'depositar']);

    });

});