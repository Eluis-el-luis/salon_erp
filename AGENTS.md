# Directrices de Desarrollo (Salón ERP)

## 1. Entorno de Ejecución (Windows + Laragon)
- El entorno host es **Windows 10**. NUNCA asumas un entorno Linux/Bash.
- La consola disponible es **PowerShell**.
- Los binarios de PHP y Composer son proveídos por Laragon de forma nativa en Windows.

## 2. Reglas Estrictas de Comandos en Terminal
- **PROHIBIDO:** Usar `powershell -command "..."`, secuencias complejas de escape, o variables dentro de comandos para inspeccionar archivos.
- **PROHIBIDO:** Intentar usar comandos de GNU/Linux (`grep`, `cat`, `wc -l`, `sed`, `awk`).
- **PERMITIDO:** Solo debes usar la terminal para comandos propios del ecosistema de desarrollo:
  - Comandos de Artisan: `php artisan migrate`, `php artisan make:model...`
  - Comandos de Composer: `composer install`, `composer require...`
  - Comandos de Node/NPM (si compilas assets): `npm run dev`, `npm run build`.

## 3. Inspección y Edición de Archivos
- Si necesitas leer un archivo, contar sus líneas o buscar texto, **USA EXCLUSIVAMENTE tus herramientas nativas MCP** (`read_file`, `search_files`, etc.). NUNCA uses la terminal para leer código.
- Para editar archivos, usa tus herramientas de edición interna (`edit_file`).

## 4. Contexto del Proyecto
# Contexto completo actualizado del proyecto Salon ERP

## 1. Visión general

Salon ERP es una aplicación web de gestión operativa construida en Laravel 13 para centralizar la administración de un salón de belleza con enfoque comercial, financiero y administrativo. El sistema combina la operación diaria del negocio con módulos avanzados de contabilidad, caja, inventario, nómina y control interno.

Módulos principales actuales:
- agenda y citas,
- punto de venta (POS),
- caja y arqueo de turno,
- caja chica y gastos operativos,
- inventario con compras, merma y kardex,
- empleados, asistencia y nómina,
- adelantos y cuotas por empleado,
- clientes y servicios,
- fórmulas o recetas de servicios,
- proveedores y cuentas por pagar,
- bancos y retiros,
- mesa de cambio de divisas,
- contabilidad general con catálogo de cuentas,
- asientos manuales, cierre fiscal y períodos contables,
- portal del colaborador para consultar comisiones, nóminas, adelantos y perfil,
- respaldos y restauración SQL.

---

## 2. Objetivo funcional

El proyecto está pensado para operar como un ERP ligero de salón, permitiendo controlar todas las áreas críticas del negocio con trazabilidad y consistencia financiera. Actualmente soporta:
- agendar y gestionar citas por servicio y estilista,
- convertir citas en ventas y generar tickets,
- registrar ventas de productos y servicios con métodos de pago,
- controlar inventario físico, compras, merma y movimientos de stock,
- calcular nómina, comisiones, adelantos y cuotas pendientes,
- llevar un historial de ventas, caja y arqueos,
- registrar movimientos bancarios y retiros,
- emitir y revisar información contable por libro diario, mayor, resultados y balance general,
- habilitar un portal autenticado para colaboradores,
- mantener respaldos y restauración de la base de datos para continuidad operativa.

---

## 3. Arquitectura general

La aplicación sigue un patrón MVC clásico de Laravel, pero con una capa adicional de dominio y reglas financieras en servicios y controladores de negocio. La estructura actual se organiza así:
- [routes/web.php](routes/web.php): define la navegación principal del sistema, incluyendo permisos por rol.
- [routes/api.php](routes/api.php): expone endpoints dinámicos utilizados por la interfaz y procesos auxiliares.
- [app/Http/Controllers](app/Http/Controllers): concentra la lógica de negocio, validación y respuestas.
- [app/Models](app/Models): representa entidades del negocio y relaciones Eloquent.
- [app/Services](app/Services): encapsula lógica compleja, especialmente contabilidad y procesos financieros.
- [resources/views](resources/views): contiene las vistas Blade y componentes de interfaz.
- [database/migrations](database/migrations): define la estructura y evolución de la base de datos.

Flujo técnico general:
1. El usuario ingresa a una ruta protegida.
2. Laravel valida la sesión y el rol asociado.
3. El controlador consulta modelos Eloquent y servicios.
4. Se ejecutan validaciones, cálculos contables y actualizaciones de inventario.
5. La vista Blade devuelve la información en formato administrativo con Tailwind, Alpine.js y Vite.

---

## 4. Stack tecnológico

### Backend
- PHP 8.3
- Laravel 13
- Eloquent ORM
- Laravel Sanctum
- Composer
- PHPUnit
- MySQL / MariaDB

### Frontend
- Blade templates
- Tailwind CSS
- Alpine.js
- Vite

### Dependencias relevantes
- Laravel Sanctum para autenticación ligera y acceso a APIs.
- Laravel Tinker para tareas de desarrollo y mantenimiento.
- MySQL dump / exportación SQL para respaldos y restauración.
- Generación de reportes y exportación de datos a Excel/PDF en módulos contables y de reportes.

---

## 5. Estructura del proyecto

```text
app/
  Http/Controllers/
  Models/
  Services/
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
public/
```

### Carpetas clave
- [app/Http/Controllers](app/Http/Controllers): lógica de negocio y endpoints.
- [app/Models](app/Models): modelos principales del ERP.
- [app/Services](app/Services): procesos transversales como contabilidad financiera.
- [resources/views](resources/views): vistas Blade de administración y reportes.
- [routes/web.php](routes/web.php): rutas principales con control por rol.
- [routes/api.php](routes/api.php): endpoints para acceso dinámico.
- [database/migrations](database/migrations): estructura de base de datos y módulos del sistema.

---

## 6. Módulos principales y estado actual

### 6.1 Dashboard y panel general

Ubicación:
- [app/Http/Controllers/PanelController.php](app/Http/Controllers/PanelController.php)
- [resources/views](resources/views)

Funcionalidad:
- vista general del negocio,
- indicadores rápidos de ventas, ingresos, citas y stock crítico,
- acceso a información consolidada del día y del periodo.

---

### 6.2 Agenda de citas

Ubicación:
- [app/Http/Controllers/CitaController.php](app/Http/Controllers/CitaController.php)
- [app/Models/Cita.php](app/Models/Cita.php)
- [resources/views/appointments/index.blade.php](resources/views/appointments/index.blade.php)

Funcionalidad:
- agendar clientes y servicios,
- asignar estilista y fecha de atención,
- consultar citas del día,
- enviar la cita a caja para facturarla.

Notas técnicas:
- la lógica de agenda se maneja principalmente desde la ruta web con consultas directas a Eloquent,
- se usa la relación con `Cliente`, `Servicio` y `Usuario` para mostrar contexto operacional.

---

### 6.3 POS y ventas

Ubicación:
- [app/Http/Controllers/VentaController.php](app/Http/Controllers/VentaController.php)
- [resources/views/sales](resources/views/sales)

Funcionalidad:
- generar ventas de servicios y productos,
- aplicar descuentos y medios de pago,
- asociar cliente,
- imprimir ticket,
- facturar citas previamente agendadas.

Notas técnicas:
- el flujo de venta está integrado con la caja y el inventario,
- si el artículo es físico se ajusta el stock,
- el módulo está diseñado para operar con respuesta rápida en pantallas tipo POS.

---

### 6.4 Caja, arqueo y control de efectivo

Ubicación:
- [app/Http/Controllers/CajaController.php](app/Http/Controllers/CajaController.php)
- [app/Models/SesionCaja.php](app/Models/SesionCaja.php)
- [app/Models/Venta.php](app/Models/Venta.php)

Funcionalidad:
- apertura de turno de caja,
- cierre de sesión y arqueo,
- cálculo automático del monto teórico,
- comparación entre efectivo real y esperado,
- contabilidad automática asociada al cierre de caja.

Notas técnicas importantes:
- el sistema distingue entre usuarios privilegiados y usuarios normales,
- el administrador o contador puede definir el monto físico manualmente,
- los roles no privilegiados heredan el último monto de cierre para la apertura automática,
- se calcula diferencia entre físico y teórico y se registra en la contabilidad.

Este módulo es un punto crítico de auditoría y control financiero.

---

### 6.5 Caja chica, gastos y movimientos operativos

Ubicación:
- [app/Http/Controllers/CajaChicaController.php](app/Http/Controllers/CajaChicaController.php)
- [app/Models/CajaChica.php](app/Models/CajaChica.php)
- [app/Models/MovimientoCajaChica.php](app/Models/MovimientoCajaChica.php)

Funcionalidad:
- registrar gastos menores de operación,
- controlar saldos de caja chica,
- mantener trazabilidad de salidas de caja por operación del negocio.

---

### 6.6 Inventario, compras, merma y kardex

Ubicación:
- [app/Http/Controllers/InventarioController.php](app/Http/Controllers/InventarioController.php)
- [app/Models/Articulo.php](app/Models/Articulo.php)
- [app/Models/Lote.php](app/Models/Lote.php)
- [app/Models/Merma.php](app/Models/Merma.php)

Funcionalidad:
- crear y editar artículos o productos,
- registrar compras y controlar inventario inicial,
- aplicar merma y ajustes,
- consultar kardex por producto,
- alertar de caducidad y stock crítico,
- manejar productos fraccionables y servicios con relación a recetas.

Notas técnicas:
- la lógica del inventario se integra con ventas y con cálculos de receta,
- la operación de compras y movimientos es clave para trazabilidad y control financiero.

---

### 6.7 Empleados, asistencia y nómina

Ubicación:
- [app/Http/Controllers/EmpleadoController.php](app/Http/Controllers/EmpleadoController.php)
- [app/Http/Controllers/AsistenciaController.php](app/Http/Controllers/AsistenciaController.php)
- [app/Http/Controllers/NominaController.php](app/Http/Controllers/NominaController.php)
- [app/Models/Usuario.php](app/Models/Usuario.php)
- [app/Models/Asistencia.php](app/Models/Asistencia.php)
- [app/Models/Nomina.php](app/Models/Nomina.php)

Funcionalidad:
- registrar empleados y usuarios del sistema,
- manejar asistencia por día,
- almacenar salario, comisiones y adelantos,
- calcular nómina y generar piezas de pago,
- mantener la relación de personal con ventas y servicios.

Notas técnicas:
- la aplicación usa roles para diferenciar accesos y permisos,
- el módulo de nómina se integra con comisiones y adelantos en un flujo de cálculo financiero.

---

### 6.8 Adelantos, cuotas y cuentas por cobrar

Ubicación:
- [app/Http/Controllers/AdelantoController.php](app/Http/Controllers/AdelantoController.php)
- [app/Models/Adelanto.php](app/Models/Adelanto.php)
- [app/Models/AdelantoCuota.php](app/Models/AdelantoCuota.php)

Funcionalidad:
- registrar adelantos de salario o crédito,
- distribuir cuotas de pago,
- consultar saldo pendiente,
- apoyar procesos de nómina y recuperación de deuda.

---

### 6.9 Clientes, servicios y fórmulas

Ubicación:
- [app/Http/Controllers/ClienteController.php](app/Http/Controllers/ClienteController.php)
- [app/Http/Controllers/ServicioController.php](app/Http/Controllers/ServicioController.php)
- [app/Http/Controllers/FormulaController.php](app/Http/Controllers/FormulaController.php)
- [app/Models/Cliente.php](app/Models/Cliente.php)
- [app/Models/Servicio.php](app/Models/Servicio.php)
- [app/Models/FormulaServicio.php](app/Models/FormulaServicio.php)

Funcionalidad:
- mantener catálogo de clientes y servicios,
- asociar servicios con opciones de precio y duración,
- definir fórmulas o recetas con consumo de productos fraccionables,
- usar estas recetas para modelar el costo y material de cada servicio.

---

### 6.10 Proveedores, cuentas por pagar y bancos

Ubicación:
- [app/Http/Controllers/ProveedorController.php](app/Http/Controllers/ProveedorController.php)
- [app/Http/Controllers/CuentaPorPagarController.php](app/Http/Controllers/CuentaPorPagarController.php)
- [app/Http/Controllers/BancoController.php](app/Http/Controllers/BancoController.php)
- [app/Http/Controllers/RetiroController.php](app/Http/Controllers/RetiroController.php)
- [app/Models/Proveedor.php](app/Models/Proveedor.php)
- [app/Models/CuentaPorPagar.php](app/Models/CuentaPorPagar.php)
- [app/Models/Banco.php](app/Models/Banco.php)

Funcionalidad:
- registrar proveedores del negocio,
- gestionar obligaciones pendientes,
- registrar depósitos y retiros bancarios,
- controlar movimientos financieros externos a la caja del salón.

---

### 6.11 Mesa de cambio y moneda

Ubicación:
- [app/Http/Controllers/MesaCambioController.php](app/Http/Controllers/MesaCambioController.php)
- [app/Models/Moneda.php](app/Models/Moneda.php)
- [app/Models/OperacionCambio.php](app/Models/OperacionCambio.php)
- [app/Models/DiferencialCambiario.php](app/Models/DiferencialCambiario.php)

Funcionalidad:
- registrar operaciones de cambio de divisas,
- operar con NIO y USD,
- controlar el diferencial cambiario y movimientos asociados.

---

### 6.12 Contabilidad general y libro mayor

Ubicación:
- [app/Http/Controllers/ContabilidadController.php](app/Http/Controllers/ContabilidadController.php)
- [app/Http/Controllers/CuentaContableController.php](app/Http/Controllers/CuentaContableController.php)
- [app/Http/Controllers/AsientoManualController.php](app/Http/Controllers/AsientoManualController.php)
- [app/Http/Controllers/PeriodoContableController.php](app/Http/Controllers/PeriodoContableController.php)
- [app/Models/CuentaContable.php](app/Models/CuentaContable.php)
- [app/Models/AsientoContable.php](app/Models/AsientoContable.php)
- [app/Models/DetalleAsiento.php](app/Models/DetalleAsiento.php)
- [app/Models/CentroCosto.php](app/Models/CentroCosto.php)

Funcionalidad:
- gestionar catálogo de cuentas contables,
- registrar asientos manuales,
- consultar libro diario y libro mayor,
- analizar resultados y balance general,
- cerrar períodos fiscales,
- mapear movimientos a centros de costo.

Notas técnicas:
- la contabilidad se basa en un modelo de partida doble con `AsientoContable` y `DetalleAsiento`,
- la lógica de cálculo está centralizada en `ContabilidadService`,
- los gastos operativos y el arqueo de caja disparan movimientos contables automáticos,
- el sistema soporta tanto flujo operativo del negocio como auditoria financiera.

---

### 6.13 Portal del colaborador

Ubicación:
- [app/Http/Controllers/PortalColaboradorController.php](app/Http/Controllers/PortalColaboradorController.php)
- [app/Models/PortalColaboradorConfig.php](app/Models/PortalColaboradorConfig.php)
- [app/Models/PortalColaboradorToken.php](app/Models/PortalColaboradorToken.php)

Funcionalidad:
- autenticar a empleados en un portal propio,
- consultar comisiones generadas,
- visualizar nóminas,
- revisar cuotas de adelantos,
- actualizar perfil y contraseña.

Notas técnicas:
- el portal usa tokens de sesión generados y validaciones por expiración,
- está deshabilitado o habilitado mediante configuración en base de datos,
- se utiliza un flujo diferente al login administrativo; es un acceso orientado a empleados.

---

### 6.14 Respaldos y seguridad del sistema

Ubicación:
- [app/Http/Controllers/RespaldoController.php](app/Http/Controllers/RespaldoController.php)
- [resources/views/backups/index.blade.php](resources/views/backups/index.blade.php)

Funcionalidad:
- exportar la base de datos en SQL,
- restaurar desde un archivo previo,
- proteger la operación con confirmación antes de aplicar cambios.

---

## 7. Modelos y relaciones principales

### Cita
- representa una cita del negocio,
- pertenece a `Cliente`,
- tiene relación con `Servicio` y `Usuario` (estilista),
- puede convertirse en venta.

### Venta
- representa una venta o factura del sistema,
- incluye líneas de detalle,
- se asocia con cajero y cliente,
- puede integrarse con caja y contabilidad.

### DetalleVenta
- representa cada línea dentro de una venta,
- puede referenciar producto o servicio,
- ayuda a calcular totales y consumo de inventario.

### Articulo
- representa los productos del inventario,
- puede ser fraccionable o no,
- puede estar asociado a proveedor y a fórmulas de servicio.

### Servicio
- representa el catálogo de servicios,
- puede relacionarse con costos de materiales a través de fórmulas.

### FormulaServicio
- representa la composición de un servicio con insumos utilizados.

### Usuario
- representa al empleado o usuario del sistema,
- maneja permisos, asistencias, nómina, adelantos y ventas.

### Cliente
- representa al cliente del salón,
- tiene vínculo con citas y ventas.

### Nomina
- consolida el cálculo de pago por periodo y usuario.

### AsientoContable / DetalleAsiento
- componen la base de la contabilidad de la operación.

### CuentaContable
- representa el catálogo de cuentas del plan contable.

### CentroCosto
- permite segmentar movimientos por área o actividad del negocio.

---

## 8. Rutas principales actuales

### Rutas públicas
- /login
- /logout
- /portal/login

### Rutas protegidas base
- / → dashboard
- /agenda → agenda del día
- /asistencia → registro de asistencia
- /clientes → administración de clientes

### Rutas de operación comercial
- /pos → punto de venta
- /historial-ventas → historial de ventas
- /ventas/{id}/ticket → ticket de venta
- /adelantos → adelantos y cuotas
- /caja/arqueo → control de sesión de caja
- /caja-chica → gastos menores
- /mesa-cambio → operaciones cambiarias

### Rutas de administración y gestión
- /empleados → empleados
- /nomina → nómina
- /inventario → inventario y compras
- /mermas → mermas y ajustes de stock
- /inventario/kardex → movimientos de almacén
- /proveedores → proveedores
- /servicios → servicios
- /formulas → recetas
- /backups → respaldos y restauración
- /reportes → reportes del negocio

### Rutas contables
- /contabilidad → libro diario
- /contabilidad/mayor → libro mayor
- /contabilidad/resultados → estado de resultados
- /contabilidad/balance → balance general
- /catalogo → catálogo de cuentas
- /asientos → asientos manuales
- /periodos → períodos contables y cierre fiscal
- /cuentas-por-pagar → obligaciones pendientes
- /bancos → movimientos bancarios
- /retiros → retiros del sistema

### Rutas del portal del colaborador
- /portal/login
- /portal/dashboard
- /portal/comisiones
- /portal/nominas
- /portal/adelantos
- /portal/perfil

---

## 9. Flujos de negocio clave actuales

### Flujo 1: cita -> venta -> caja
1. El cliente es registrado o seleccionado.
2. Se agenda una cita con servicio y estilista.
3. La cita puede enviarse a venta desde la agenda.
4. El POS genera la venta y el ticket asociado.
5. La caja calcula el cierre del turno y puede contabilizar el arqueo.

### Flujo 2: inventario y consumo de materiales
1. Se registran artículos y compras.
2. Se usan en ventas o en recetas de servicio.
3. El sistema ajusta stock y registra movimientos por consumo o merma.
4. El kardex permite revisar trazabilidad del inventario.

### Flujo 3: nómina y comisiones
1. El empleado genera ventas o servicios.
2. El sistema calcula comisiones sobre la base operativa.
3. Se registran adelantos y cuotas pendientes.
4. La nómina consolida salarios, comisiones y descuentos.
5. El colaborador puede consultar su estado desde el portal.

### Flujo 4: contabilidad financiera
1. El sistema registra movimientos de ventas, gastos, caja, banco y retiros.
2. El catálogo de cuentas define la estructura del plan contable.
3. Los asientos se formalizan en el libro diario y luego se consolidan en mayor.
4. El cierre del período permite analizar resultados y balance general.

### Flujo 5: respaldo y continuidad operativa
1. El usuario descarga un respaldo de la base de datos en SQL.
2. En caso de necesidad, se restaura la información con validación previa.
3. La operación mantiene continuidad del sistema y minimiza riesgo de pérdida.

---

## 10. Notas técnicas actuales y decisiones de implementación

- El proyecto está evolucionando hacia una estructura ERP más realista, con módulos financieros y operativos integrados.
- La lógica de negocio sigue concentrada en controladores, aunque ya se observan servicios dedicados para procesos complejos como contabilidad.
- La gestión por roles es una pieza clave: hay accesos diferenciados para administración, recepción, caja y contador.
- La caja se comporta como un subsistema crítico: apertura, cierre, arqueo y contabilidad automática están fuertemente integrados.
- La contabilidad ya no es un módulo auxiliar; es un componente central del sistema y soporta auditoría, análisis y cierre fiscal.
- El sistema combina procesos administrativos con control financiero y operativo, por lo que requiere trazabilidad en cada movimiento.
- El portal del colaborador amplía el alcance del sistema al permitir que los empleados revisen su información sin acceso directo al backoffice.
- El frontend sigue basado en Blade + Tailwind + Alpine.js, con enfoque en interfaces administrativas rápidas y funcionales.

---

## 11. Cómo levantar el proyecto

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

Para desarrollo local:

```bash
npm run dev
php artisan serve
php artisan queue:listen --tries=1 --timeout=0
php artisan pail --timeout=0
```

---

## 12. Resumen ejecutivo

Salon ERP se ha consolidado como una solución operativa y financiera para la gestión de un salón de belleza. Además de la agenda, ventas e inventario, ahora incorpora control de caja, bancos, cuentas por pagar, pago a proveedores, cierre fiscal, contabilidad de doble entrada, portal del colaborador y reportes financieros. Esto lo posiciona como un sistema más cercano a un ERP ligero, con fuerte enfoque en trazabilidad, auditoría y control del negocio en tiempo real.