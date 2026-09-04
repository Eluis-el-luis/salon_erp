# Documentación Técnica - Salon ERP

## 1. Propósito del documento

Este documento describe la arquitectura, el stack tecnológico, los módulos de negocio, las entidades, los flujos de datos y la configuración técnica de `salon-erp`.

Está diseñado para desarrolladores, arquitectos y equipos de soporte que necesiten entender el alcance y la implementación del sistema.

---

## 2. Visión general del sistema

`salon-erp` es una aplicación web construida con Laravel 13 que gestiona las operaciones de un salón de belleza.

Su alcance funcional incluye:
- administración de citas y agenda diaria,
- punto de venta para servicios y productos,
- gestión de inventario físico y de insumos fraccionables,
- administración de empleados, nómina y comisiones,
- registro de asistencias y adelantos,
- control de caja y arqueo de turno,
- proveedores, compras y cuentas por pagar,
- generación de respaldos SQL y restauración,
- contabilidad básica de ventas, compras y arqueos.

El proyecto está organizado con la arquitectura MVC clásica de Laravel, con separación clara entre rutas, controladores, modelos y vistas.

---

## 3. Stack tecnológico

### Backend
- PHP `^8.3`
- Laravel `^13.8`
- Eloquent ORM
- Laravel Sanctum para autenticación de API ligeras
- PHPUnit para pruebas
- Composer para gestión de dependencias

### Frontend
- Blade templates
- Tailwind CSS
- Alpine.js
- Vite como bundler de frontend

### Dependencias principales
- `ifsnop/mysqldump-php` — generación de backups SQL
- `laravel/sanctum` — seguridad de API
- `laravel/tinker` — consola interactiva
- `fakerphp/faker` — datos de prueba
- `phpunit/phpunit` — tests
- `concurrently` — ejecución de procesos simultáneos en desarrollo
- `@tailwindcss/postcss`, `@tailwindcss/vite`, `autoprefixer`, `postcss`, `tailwindcss`, `vite` — pipeline CSS/JS
- `alpinejs` — interactividad ligera en frontend

---

## 4. Estructura de carpetas

```
app/
  Http/Controllers/
  Models/
bootstrap/
config/
database/
  migrations/
  seeders/
public/
resources/
  css/
  js/
  views/
routes/
storage/
tests/
```

### Carpetas clave
- `app/Http/Controllers/` — controladores que implementan la lógica de negocio.
- `app/Models/` — entidades Eloquent que representan la estructura de datos.
- `resources/views/` — vistas Blade que componen la interfaz.
- `routes/` — rutas web y API.
- `database/migrations/` — migraciones para la estructura de base de datos.
- `database/seeders/` — seeders para datos iniciales.
- `storage/` — archivos generados, logs y backups.

---

## 5. Archivo de configuración y scripts

### `composer.json`

Define el proyecto como `laravel/laravel` con los requisitos mínimos de PHP, Laravel y paquetes útiles.

Scripts importantes:
- `setup` — instala dependencias, copia `.env`, genera clave de aplicación, migra la base de datos y construye el frontend.
- `dev` — arranca `php artisan serve`, `queue:listen`, `php artisan pail` y `npm run dev` en paralelo.
- `test` — limpia configuración y ejecuta tests.

### `package.json`

Define los scripts de frontend:
- `build` — `vite build`
- `dev` — `vite`

Incluye `alpinejs` como dependencia y la configuración necesaria de Tailwind/Vite.

---

## 6. Enrutamiento y control de acceso

### `routes/web.php`

Contiene la definición de rutas públicas y protegidas.

#### Rutas públicas
- `/login` — formulario de login.
- `/logout` — cierre de sesión.

#### Rutas protegidas por `auth`
- `/` — dashboard principal.
- `/agenda` — agenda diaria de citas.
- `/asistencia` — registrar asistencias.
- `/clientes` — CRUD de clientes.

#### Rutas con middleware `role:recepcion`
- `/pos` — punto de venta.
- `/sales` — registro de ventas.
- `/sales/bill-appointment/{appointment}` — facturar cita programada.
- `/historial-ventas` — histórico de ventas.
- `/ventas/{id}/ticket` — ticket de venta.
- `/adelantos` — gestión de adelantos de empleados.
- `/caja/arqueo`, `/caja/abrir`, `/caja/cerrar` — control de turno de caja.
- `/caja-chica` — caja chica.

#### Rutas con middleware `role:admin`
- `/reportes` — reportes financieros.
- `/empleados` — administración de personal.
- `/nomina` — gestión de nóminas.
- `/nomina/generar` — generación de nóminas.
- `/nomina/{id}/ticket` — colilla de nómina.
- `/inventario` — inventario y productos.
- `/inventario/comprar` — compra de inventario.
- `/proveedores` — proveedores.
- `/servicios` — servicios.
- `/formulas` — fórmulas de servicio.
- `/backups` — backups.
- `/backups/descargar` — descarga de backup SQL.
- `/backups/restaurar` — restauración SQL.
- `/contabilidad` — contabilidad general.
- `/cuentas-por-pagar` — cuentas por pagar.
- `/bancos` — movimientos bancarios.

### API REST

`routes/api.php` define recursos para:
- `clients`
- `items`
- `appointments`

Estas rutas son manejadas por `ClienteController`, `ArticuloController` y `CitaController` respectivamente.

---

## 7. Modelos y relaciones principales

### `app/Models/User.php`

Campos más relevantes:
- `name`, `email`, `password`
- `role_id`, `role`
- `salario_fijo`
- `comision_servicio`
- `comision_producto`
- `phone`
- `is_active`

Relaciones:
- `sales()` — ventas registradas por el cajero.
- `servicesPerformed()` — servicios realizados como estilista.
- `appointments()` — citas del estilista.
- `attendances()` — asistencias.
- `advances()` — adelantos.
- `payrolls()` — nóminas asociadas.

### `app/Models/Sale.php`

Campos:
- `cashier_id`
- `client_id`
- `subtotal`
- `discount`
- `total`
- `currency`
- `exchange_rate`
- `payment_method`
- `status`

Relaciones:
- `cashier()` — pertenece a `User`.
- `client()` — pertenece a `Client`.
- `details()` — tiene muchos `SaleDetail`.

### `app/Models/SaleDetail.php`

Campos:
- `sale_id`
- `item_id`
- `service_id`
- `stylist_id`
- `quantity`
- `unit_price`

Relaciones:
- `sale()` — pertenece a `Sale`.
- `item()` — pertenece a `Item`.
- `service()` — pertenece a `Service`.
- `stylist()` — pertenece a `User`.

### `app/Models/Item.php`

Campos:
- `codigo`, `ubicacion`, `producto`, `categoria`, `marca`
- `type` — determina si es `servicio` o producto físico.
- `precio_c`, `precio_usd`
- `existencia_actual`, `stock_min`
- `provider_id`
- `is_fractionable`
- `unit_measure`
- `total_volume`, `current_volume`

Relaciones:
- `appointments()` — usado por citas cuando el ítem es servicio.
- `saleDetails()` — detalles de ventas que usan el ítem.
- `provider()` — proveedor asociado.

### `app/Models/Appointment.php`

Campos:
- `client_id`
- `stylist_id`
- `service_id`
- `appointment_date`
- `duration_minutes`
- `status`
- `notes`

Relaciones:
- `client()` — cliente de la cita.
- `stylist()` — estilista asignado.
- `service()` — servicio reservado.

### `app/Models/Service.php`

Campos:
- `name`
- `price`
- `duration`
- `description`
- `is_active`

Relaciones:
- `formulas()` — insumos o recetas asociadas al servicio.

### `app/Models/Provider.php`

Campos:
- `name`
- `contact_name`
- `phone`
- `email`
- `address`
- `notes`

Relaciones:
- `items()` — productos suministrados.
- `cuentasPorPagar()` — cuentas por pagar asociadas.

### `app/Models/Payroll.php`

Campos:
- `user_id`
- `start_date`, `end_date`
- `active_salary`
- `services_commission`
- `products_commission`
- `extra_bonus`
- `sunday_bonus`
- `salary_advances`
- `loan_payments`
- `total_to_pay`
- `status`

Relación:
- `user()` — empleado al que corresponde la nómina.

---

## 8. Controllers y lógica de negocio clave

### `App\Http\Controllers\VentaController.php`

Funcionalidad:
- registra ventas en POS.
- factura citas.
- valida que exista caja abierta antes de cobrar.
- crea `Sale` y `SaleDetail`.
- actualiza inventario producto físico y controles de insumos fraccionables.
- usa transacciones DB para mantener consistencia.
- invoca `ContabilidadService` para generar asientos contables.

**Proceso de venta:**
1. Validar caja abierta.
2. Calcular subtotal y total.
3. Crear venta.
4. Crear detalles de venta.
5. Actualizar stock físico o volumen de insumos.
6. Ejecutar contabilidad.
7. Confirmar transacción.

**Facturación de cita:**
- toma `Appointment`.
- crea `Sale` para servicio.
- genera `SaleDetail` solo con `service_id`.
- marca cita como completada.
- contabiliza la venta.

### `App\Http\Controllers\InventarioController.php`

Funcionalidad:
- listados y CRUD de productos.
- registrar compras de inventario.
- actualizar inventario en compras.
- crear deudas `CuentaPorPagar` cuando la compra es a crédito.
- invocar contabilidad de compra.
- entrega formulario de compra con proveedores y productos.

### `App\Http\Controllers\NominaController.php`

Funcionalidad:
- listar nóminas y empleados.
- calcular nómina de un empleado por rango de fechas.
- sumar ventas por estilo y productos.
- aplicar comisiones del empleado.
- restar adelantos.
- guardar nómina como borrador.
- generar ticket de colilla.

### `App\Http\Controllers\RespaldoController.php`

Funcionalidad:
- generar respaldo SQL con `ifsnop/mysqldump-php`.
- descargar archivo `.sql`.
- restaurar base de datos desde archivo `.sql`.
- usa `DB::unprepared` para ejecutar el SQL.

### `App\Http\Controllers\CajaController.php`

Funcionalidad:
- mostrar turno de caja actual y ventas en efectivo del turno.
- abrir turno con monto inicial.
- cerrar turno con arqueo y diferencia.
- contabilizar arqueo automático.

---

## 9. Flujos de negocio importantes

### 9.1 Flujo de venta POS

- El cajero abre la caja con `CajaController@open`.
- En `/pos`, carga productos y servicios activos.
- El carrito se envía a `VentaController@store`.
- Se valida caja abierta y el request.
- Se crean los registros de venta.
- Se actualiza inventario físico o volumen fraccionado.
- Se registra contabilidad.
- Si la venta viene de una cita, la cita se completa.

### 9.2 Flujo de facturación de cita

- Se selecciona una cita en la agenda.
- Se invoca `VentaController@billAppointment`.
- Se crea venta con el servicio de la cita.
- Se asocia el estilista.
- Se marca la cita como `completada`.
- Se contabiliza la transacción.

### 9.3 Flujo de gestión de inventario

- `InventarioController@store` crea productos nuevos.
- `update` modifica precios, stock y atributos.
- `destroy` elimina productos.
- `registrarCompra` actualiza stock físico y volumen.
- Si la compra es a crédito, crea `CuentaPorPagar`.
- Se ejecuta contabilidad de compra.

### 9.4 Flujo de nómina

- Se selecciona empleado y rango de fechas.
- Se suman ventas del estilista en el período.
- Se calculan comisiones por servicios y productos.
- Se restan adelantos solicitados.
- Se guarda la nómina como `borrador`.
- Se genera ticket de pago.

### 9.5 Flujo de backup y restauración

- El administrador descarga un respaldo SQL desde `/backups/descargar`.
- El archivo se genera con MySQLDump en el servidor.
- Para restaurar, se sube un `.sql` válido a `/backups/restaurar`.
- El motor ejecuta el SQL completo con `DB::unprepared`.

---

## 10. Contabilidad y servicio especializado

### `App\Services\ContabilidadService`

Este servicio centraliza la lógica contable del sistema.

Se utiliza desde:
- `VentaController` para ventas.
- `InventarioController` para compras.
- `CajaController` para arqueos.
- posiblemente otros controladores de contabilidad.

Permite desacoplar la generación de asientos contables de la lógica del flujo operacional.

---

## 11. Consideraciones técnicas y riesgos

### Defensas de seguridad
- El acceso se protege con `auth` y roles (`role:recepcion`, `role:admin`).
- Los controladores validan requests con reglas de `validate()`.
- La contraseña se almacena con casting `hashed` en `User`.

### Riesgos identificados
- La restauración de backups usa `DB::unprepared()`; los archivos `.sql` deben provenir de fuentes confiables.
- Los roles parecen gestionarse principalmente en middleware, no en políticas explícitas.
- La estructura de roles y permisos debe revisarse si se requieren reglas más finas.
- La contabilidad depende de `ContabilidadService`; hay que garantizar que maneje errores y rollback en todos los casos.

### Reglas de negocio sensibles
- No se permite vender sin caja abierta.
- El cálculo de nómina depende de ventas completadas y no de borradores.
- Los productos fraccionables consumen `current_volume` y pueden decrementar `existencia_actual` cuando se agota el volumen.
- El cierre de turno registra diferencias de caja y dispara asiento contable.

---

## 12. DevOps y configuración de desarrollo

### Setup inicial
1. `composer install`
2. Copiar `.env.example` a `.env`
3. `php artisan key:generate`
4. `php artisan migrate --force`
5. `npm install --ignore-scripts`
6. `npm run build`

### Desarrollo
- `npm run dev`
- `php artisan serve`
- `php artisan queue:listen --tries=1 --timeout=0`
- `php artisan pail --timeout=0`

### Tests
- `composer test` invoca `php artisan config:clear --ansi` y `php artisan test`.

---

## 13. Recomendaciones para continuar

- Documentar los roles exactos y permisos en un archivo de políticas.
- Añadir validaciones adicionales para las operaciones contables y de backup.
- Generar seeders representativos para clientes, servicios y productos.
- Añadir pruebas unitarias/integración para los flujos de venta, nómina e inventario.
- Revisar `PROJECT_CONTEXT.md` y `README.md` para reemplazar la plantilla Laravel con documentación específica del proyecto.

---

## 14. Archivo de referencia

Este documento se generó a partir del código y los archivos existentes del proyecto en las rutas:
- `routes/web.php`
- `routes/api.php`
- `app/Http/Controllers/VentaController.php`
- `app/Http/Controllers/InventarioController.php`
- `app/Http/Controllers/NominaController.php`
- `app/Http/Controllers/RespaldoController.php`
- `app/Http/Controllers/CajaController.php`
- `app/Models/User.php`
- `app/Models/Sale.php`
- `app/Models/SaleDetail.php`
- `app/Models/Item.php`
- `app/Models/Appointment.php`
- `app/Models/Service.php`
- `app/Models/Provider.php`
- `app/Models/Payroll.php`

---

## 15. Notas finales

`salon-erp` está estructurado como un ERP local altamente verticalizado para un salón de belleza. El sistema combina múltiples dominios —ventas, inventario, nómina y contabilidad— en una sola aplicación Laravel.

Para mantenerlo sostenible, la prioridad técnica debe ser:
- asegurar la integridad transaccional,
- separar lógica contable de lógica operacional,
- estandarizar validaciones de request,
- documentar roles y permisos, y
- proteger las operaciones de backup/restauración.
