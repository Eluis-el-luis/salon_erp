# Contexto completo actualizado del proyecto Salon ERP

## 1. Visión general

Salon ERP es una aplicación web operativa desarrollada en Laravel 13 para gestionar de forma integral un salón de belleza. El sistema combina operaciones comerciales, administrativas y financieras en una sola interfaz web.

Incluye módulos para:
- agenda de citas,
- caja y POS,
- inventario y control de stock,
- empleados y nómina,
- asistencia diaria,
- adelantos y cuentas por cobrar,
- clientes y servicios,
- fórmulas o recetas de servicios,
- proveedores,
- respaldos y restauración de base de datos.

---

## 2. Objetivo funcional

El propósito del proyecto es centralizar la operación diaria del salón para que el negocio pueda trabajar de forma ordenada, rápida y con mejor trazabilidad. Permite:
- agendar servicios para clientes,
- convertir citas en ventas,
- registrar ventas de productos y servicios,
- controlar inventario y stock mínimo,
- calcular nómina con comisiones y adelantos,
- llevar historial de ventas, tickets y movimientos,
- crear recetas para servicios que usan productos fraccionables,
- registrar proveedores y asociarlos a productos,
- generar respaldos SQL locales y restaurarlos cuando sea necesario.

---

## 3. Arquitectura general

El proyecto sigue una arquitectura MVC clásica de Laravel:
- [routes/web.php](routes/web.php): define las rutas del sistema administrativo.
- [routes/api.php](routes/api.php): expone endpoints para operaciones dinámicas.
- [app/Http/Controllers](app/Http/Controllers): contiene la lógica de negocio.
- [app/Models](app/Models): representa las entidades del dominio.
- [resources/views](resources/views): contiene las vistas Blade.
- [database/migrations](database/migrations): define la estructura de la base de datos.

Flujo técnico general:
1. El usuario accede a una ruta web o API.
2. Laravel resuelve la ruta y delega al controlador correspondiente.
3. El controlador consulta modelos Eloquent.
4. Devuelve una vista Blade o una respuesta JSON.
5. La interfaz muestra la información con Blade, Tailwind y Alpine.js.

---

## 4. Stack tecnológico

### Backend
- PHP 8.3
- Laravel 13
- Eloquent ORM
- Sanctum para APIs ligeras
- Composer
- PHPUnit

### Frontend
- Blade templates
- Tailwind CSS
- Alpine.js
- Vite

### Dependencias adicionales
- laravel/sanctum
- laravel/tinker
- ifsnop/mysqldump para respaldos SQL

---

## 5. Estructura del proyecto

```text
app/
  Http/Controllers/
  Models/
  Providers/
bootstrap/
config/
database/
  migrations/
  seeders/
resources/
  css/
  js/
  views/
routes/
tests/
storage/
```

### Carpetas clave
- [app/Http/Controllers](app/Http/Controllers): controladores de negocio.
- [app/Models](app/Models): modelos principales.
- [resources/views](resources/views): vistas y componentes Blade.
- [routes/web.php](routes/web.php): rutas web del sistema.
- [routes/api.php](routes/api.php): rutas API reutilizables.
- [database/migrations](database/migrations): tablas y cambios de estructura.

---

## 6. Módulos principales

### 6.1 Dashboard

Ubicación:
- [app/Http/Controllers/DashboardController.php](app/Http/Controllers/DashboardController.php)
- [resources/views/dashboard.blade.php](resources/views/dashboard.blade.php)

Funcionalidad:
- mostrar ingresos del día,
- comparar ventas en efectivo y bancos,
- ver citas pendientes,
- monitorear stock crítico,
- listar últimas ventas.

---

### 6.2 Agenda de citas

Ubicación:
- [app/Http/Controllers/AppointmentController.php](app/Http/Controllers/AppointmentController.php)
- [app/Models/Appointment.php](app/Models/Appointment.php)
- [resources/views/appointments/index.blade.php](resources/views/appointments/index.blade.php)

Funcionalidad:
- agendar citas para clientes,
- asignar estilista y servicio,
- marcar citas como completadas,
- enviar la cita al POS para generar una venta.

Modelo clave:
```php
class Appointment extends Model
{
    protected $fillable = [
        'client_id',
        'stylist_id',
        'service_id',
        'appointment_date',
        'duration_minutes',
        'status',
        'notes',
    ];
}
```

---

### 6.3 POS / Caja

Ubicación:
- [app/Http/Controllers/SaleController.php](app/Http/Controllers/SaleController.php)
- [resources/views/sales/pos.blade.php](resources/views/sales/pos.blade.php)
- [resources/views/sales/index.blade.php](resources/views/sales/index.blade.php)
- [resources/views/sales/ticket.blade.php](resources/views/sales/ticket.blade.php)

Funcionalidad:
- vender productos y servicios desde una caja tipo POS,
- aplicar descuentos,
- seleccionar método de pago,
- asignar cliente opcional,
- generar ticket y abrirlo en nueva ventana,
- facturar una cita previamente agendada.

Características técnicas:
- el carrito se maneja con Alpine.js,
- la venta se envía a la API de ventas,
- se crean registros en Sales y SaleDetails,
- si el artículo es un producto físico, se decrementa el stock.

---

### 6.4 Inventario

Ubicación:
- [app/Http/Controllers/InventoryController.php](app/Http/Controllers/InventoryController.php)
- [app/Models/Item.php](app/Models/Item.php)
- [resources/views/inventory/index.blade.php](resources/views/inventory/index.blade.php)

Funcionalidad:
- listar productos,
- crear y editar artículos,
- actualizar precios y stock,
- controlar stock mínimo,
- asignar proveedor,
- marcar productos como fraccionables.

Modelo clave:
```php
class Item extends Model
{
    protected $fillable = [
        'codigo',
        'ubicacion',
        'producto',
        'categoria',
        'marca',
        'type',
        'precio_c',
        'precio_usd',
        'existencia_actual',
        'stock_min',
        'provider_id',
        'is_fractionable',
        'unit_measure',
        'total_volume',
        'current_volume',
    ];
}
```

Nota importante:
- los productos físicos decrementan existencia al venderse,
- los servicios no afectan inventario,
- los productos fraccionables pueden usarse dentro de recetas o fórmulas.

---

### 6.5 Empleados y nómina

Ubicación:
- [app/Http/Controllers/EmployeeController.php](app/Http/Controllers/EmployeeController.php)
- [app/Http/Controllers/PayrollController.php](app/Http/Controllers/PayrollController.php)
- [app/Models/User.php](app/Models/User.php)
- [app/Models/Payroll.php](app/Models/Payroll.php)
- [resources/views/payrolls/index.blade.php](resources/views/payrolls/index.blade.php)

Funcionalidad:
- registrar empleados,
- guardar salario fijo y comisiones,
- dar de baja lógica de usuarios,
- calcular nómina por periodo,
- aplicar adelantos al salario,
- generar colillas de pago.

---

### 6.6 Asistencia diaria

Ubicación:
- [app/Http/Controllers/AttendanceController.php](app/Http/Controllers/AttendanceController.php)
- [app/Models/Attendance.php](app/Models/Attendance.php)
- [resources/views/attendances/index.blade.php](resources/views/attendances/index.blade.php)

Funcionalidad:
- registrar hora de entrada,
- marcar si el empleado tiene aseo y uniforme,
- evitar duplicados por día usando una clave única compuesta.

---

### 6.7 Adelantos y cuentas por cobrar

Ubicación:
- [app/Http/Controllers/AdvanceController.php](app/Http/Controllers/AdvanceController.php)
- [app/Models/Advance.php](app/Models/Advance.php)
- [resources/views/advances/index.blade.php](resources/views/advances/index.blade.php)

Funcionalidad:
- registrar movimientos de debe/haber para empleados o clientes,
- visualizar un historial simple de movimientos,
- apoyar el cálculo de nómina y pagos parciales.

---

### 6.8 Clientes y servicios

Ubicación:
- [app/Http/Controllers/ClientController.php](app/Http/Controllers/ClientController.php)
- [app/Http/Controllers/ServiceController.php](app/Http/Controllers/ServiceController.php)
- [app/Models/Client.php](app/Models/Client.php)
- [app/Models/Service.php](app/Models/Service.php)
- [resources/views/clients/index.blade.php](resources/views/clients/index.blade.php)
- [resources/views/services/index.blade.php](resources/views/services/index.blade.php)

Funcionalidad:
- mantener catálogo de clientes,
- administrar servicios con precio, duración y estado activo,
- usarlos en agenda y POS.

---

### 6.9 Fórmulas y recetas de servicios

Ubicación:
- [app/Http/Controllers/FormulaController.php](app/Http/Controllers/FormulaController.php)
- [app/Models/ServiceFormula.php](app/Models/ServiceFormula.php)
- [resources/views/formulas/index.blade.php](resources/views/formulas/index.blade.php)

Funcionalidad:
- asignar ingredientes o productos a un servicio,
- registrar la cantidad usada de cada producto,
- permitir recetas de servicios con productos fraccionables,
- agregar o eliminar líneas de receta.

Modelo clave:
```php
class ServiceFormula extends Model
{
    protected $fillable = ['service_id', 'item_id', 'quantity_used'];
}
```

Este módulo es importante porque conecta los servicios con el inventario físico y ayuda a modelar el consumo de materiales.

---

### 6.10 Proveedores

Ubicación:
- [app/Http/Controllers/ProviderController.php](app/Http/Controllers/ProviderController.php)
- [app/Models/Provider.php](app/Models/Provider.php)
- [resources/views/providers/index.blade.php](resources/views/providers/index.blade.php)

Funcionalidad:
- registrar proveedores del negocio,
- guardar datos de contacto y dirección,
- asociar proveedores a productos del inventario.

---

### 6.11 Respaldos y restauración

Ubicación:
- [app/Http/Controllers/BackupController.php](app/Http/Controllers/BackupController.php)
- [resources/views/backups/index.blade.php](resources/views/backups/index.blade.php)

Funcionalidad:
- exportar la base de datos a un archivo SQL,
- restaurar desde un archivo SQL previamente generado,
- proteger la operación con confirmación antes de ejecutar.

---

## 7. Modelos principales y relaciones

### Appointment
- representa una cita agendada,
- pertenece a Client,
- pertenece a User como estilista,
- puede estar vinculada a un servicio.

### Sale
- representa una venta o factura,
- tiene varios SaleDetail,
- pertenece a un cajero (User),
- puede tener un cliente asociado.

### SaleDetail
- representa cada línea de la venta,
- puede referenciar un service o un item,
- puede incluir un estilista asociado.

### Item
- representa un producto o un servicio,
- puede pertenecer a un Provider,
- puede usarse en recetas de servicios.

### Service
- representa un servicio ofrecido por el salón,
- puede tener muchas fórmulas o ingredientes.

### ServiceFormula
- une un servicio con un item y su cantidad usada.

### User
- representa un empleado o usuario del sistema,
- tiene ventas, asistencias, adelantos, nómina y citas.

### Client
- representa a un cliente del salón,
- puede tener múltiples citas y ventas.

### Payroll
- representa una nómina calculada por periodo,
- está asociada a un usuario.

### Attendance
- representa la asistencia de un empleado en un día específico.

### Advance
- representa un movimiento contable por adelanto o crédito.

---

## 8. Rutas principales

### Rutas web
- / → dashboard
- /agenda → agenda del día
- /pos → caja / POS
- /inventario → inventario
- /empleados → empleados
- /asistencia → asistencia diaria
- /adelantos → adelantos / CXC
- /clientes → clientes
- /servicios → servicios
- /nomina → nómina
- /formulas → fórmulas y recetas
- /proveedores → proveedores
- /backups → respaldos y restauración
- /historial-ventas → historial de ventas
- /ventas/{id}/ticket → ticket de venta

### Rutas API
- /api/appointments
- /api/sales
- /api/sales/bill-appointment/{appointment}
- /api/clients
- /api/items
- /api/user

---

## 9. Flujos de negocio clave

### Flujo 1: crear una cita y facturarla
1. Se registra un cliente.
2. Se crea una cita con servicio y estilista.
3. Desde la agenda, el usuario puede enviarla a la caja.
4. El POS genera una venta relacionada con esa cita.
5. La cita queda marcada como completada.

### Flujo 2: venta rápida en POS
1. Se agregan servicios o productos al carrito.
2. Se aplica descuento y se elige método de pago.
3. Se envía la venta al backend.
4. Se registra la venta y se actualiza el inventario cuando aplica.
5. Se muestra el ticket para impresión.

### Flujo 3: cálculo de nómina
1. Se selecciona empleado y rango de fechas.
2. El sistema reúne ventas donde participó el empleado.
3. Calcula comisiones por servicios y productos.
4. Descuenta adelantos de salario.
5. Guarda la nómina como registro de pago.

### Flujo 4: crear una receta para un servicio
1. Se selecciona un servicio.
2. Se elige un producto fraccionable.
3. Se define la cantidad usada.
4. El sistema guarda la relación en la tabla de fórmulas.
5. Esa receta queda disponible para futuras operaciones y análisis.

### Flujo 5: respaldo y restauración
1. El usuario descarga un respaldo SQL completo.
2. En caso de pérdida o cambio mayor, sube el archivo para restaurar.
3. El sistema ejecuta el script SQL directamente.

---

## 10. Notas técnicas actuales

- El proyecto está orientado a funcionalidad operativa más que a una arquitectura muy modular.
- Gran parte de la lógica y validación sigue concentrada en los controladores.
- El frontend usa Alpine.js para interacción rápida en vistas como agenda, asistencia y POS.
- El diseño visual está basado en Tailwind CSS y una interfaz administrativa moderna.
- El sistema ya incorpora módulos adicionales que reflejan necesidades reales del negocio, como fórmulas, proveedores y respaldos.

---

## 11. Cómo levantar el proyecto

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
php artisan serve
```

---

## 12. Resumen ejecutivo

Salon ERP es un sistema de gestión para salón de belleza con enfoque operativo, financiero y administrativo. Integra agenda, caja, inventario, personal, nómina, asistencia, clientes, fórmulas, proveedores y respaldos en una sola plataforma, lo que lo convierte en una herramienta útil para administrar el día a día del negocio con mayor control y trazabilidad.
