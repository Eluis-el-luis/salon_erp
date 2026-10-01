# AGENTS.md - Salon ERP

## 1. Propósito del proyecto

Salon ERP es el sistema operativo, administrativo y financiero de Álvaro Rugama Make Up Studio. No debe tratarse como un POS aislado: ventas, caja, inventario, nómina, cuentas por cobrar y pagar, bancos, divisas y contabilidad están relacionados y requieren trazabilidad de extremo a extremo.

El objetivo al modificarlo es conservar integridad financiera y de inventario, compatibilidad con los datos existentes y una operación clara para los roles del salón. Estas instrucciones aplican a cambios de código, esquemas, rutas, interfaz y documentación.

## 2. Entorno y stack verificado

- Host habitual: Windows 10 con Laragon y PowerShell. No asumir Bash ni rutas Linux.
- Backend: PHP `^8.3`, Laravel `^13.8`, Eloquent ORM.
- Persistencia: MySQL/MariaDB en despliegue; el esquema se evoluciona mediante migraciones.
- Frontend: Blade, Tailwind CSS 4, Alpine.js 3 y Vite 8.
- Pruebas: PHPUnit 12 mediante Laravel. Composer también incorpora Laravel Pint.
- Dependencias de dominio: Laravel Sanctum, Laravel Excel (`maatwebsite/excel`) y `ifsnop/mysqldump-php`.

Las versiones declaradas en `composer.json` y `package.json` son la referencia del stack. La presencia de Sanctum en Composer no demuestra por sí sola que una ruta API esté protegida por Sanctum.

## 3. Arquitectura y mapa del código

La aplicación usa MVC de Laravel con servicios para reglas financieras y de inventario. La separación no es completa: algunas pantallas y consultas siguen implementadas directamente como closures en rutas, por lo que antes de asumir una capa uniforme hay que seguir el flujo concreto.

- `routes/web.php`: interfaz administrativa, autenticación y grupos de acceso por rol.
- `routes/api.php`: recursos JSON de clientes, artículos y citas.
- `bootstrap/app.php`: registra rutas, endpoint de salud, alias `role` y formato JSON de errores bajo `api/*`.
- `app/Http/Controllers/`: casos de uso HTTP, validación, persistencia y coordinación de servicios.
- `app/Http/Middleware/RoleMiddleware.php`: autorización por rol; `admin` tiene bypass global.
- `app/Models/`: modelos Eloquent y relaciones del dominio.
- `app/Services/ContabilidadService.php`: asientos, integración financiera, períodos y cuadre.
- `app/Services/DivisaService.php`: saldos físicos de moneda y costo promedio ponderado.
- `app/Services/InventarioService.php`: consumo de productos, insumos fraccionados y lotes FIFO.
- `app/Exceptions/ContabilidadException.php`: error de dominio contable.
- `database/migrations/`: esquema e historial de cambios; revisar migraciones existentes antes de crear otra.
- `database/seeders/`: datos iniciales, incluidos roles y catálogo contable base.
- `resources/views/`: pantallas Blade agrupadas por módulo; `resources/js/app.js` y `resources/css/app.css` son los puntos de entrada frontend.
- `tests/`: pruebas PHPUnit. Actualmente el árbol contiene pruebas de ejemplo; no asumir que los flujos financieros están cubiertos.
- `public/build/`: salida generada por Vite; no editar manualmente.

Los nombres de tablas y algunos campos conservan nombres heredados en inglés (`sales`, `items`, `advances`), mientras que el dominio y la interfaz mezclan español e inglés. Inspeccionar los modelos y migraciones, no deducir el nombre físico de una tabla por el nombre de una clase.

## 4. Módulos y principales puntos de entrada

| Área | Puntos de entrada principales | Responsabilidad |
| --- | --- | --- |
| Panel, agenda y citas | `PanelController`, ruta `/agenda`, `CitaController`, `Cita`, `Cliente`, `Servicio` | Indicadores del negocio, agenda diaria y citas. La agenda web actualmente consulta modelos desde una closure; el API usa `CitaController`. |
| Clientes | `ClienteController`, `Cliente` | Datos de clientes y relaciones con citas, ventas y movimientos de crédito. |
| POS y ventas | `VentaController`, `Venta`, `DetalleVenta`, `Pago` | Venta de servicios/productos, pagos mixtos, ticket, facturación de cita, inventario, comisiones y asiento contable. |
| Caja y caja chica | `CajaController`, `SesionCaja`, `CajaChicaController`, `CajaChica`, `MovimientoCajaChica` | Apertura/cierre, arqueo, movimientos de efectivo y gastos menores. |
| Inventario y compras | `InventarioController`, `Articulo`, `Lote`, `InventarioService` | Existencias, compras, costos, lotes, kardex y alertas de stock. |
| Recetas y mermas | `FormulaController`, `FormulaServicio`, `MermaController`, `Merma` | Consumo de productos fraccionables por servicio y bajas por merma. |
| Empleados, comisiones y nómina | `EmpleadoController`, `Usuario`, `EsquemaComision`, `EsquemaRango`, `ComisionGenerada`, `NominaController`, `Nomina`, `AsistenciaController` | Personal, asistencia, comisión histórica, deducciones, cuotas y nómina. |
| Adelantos / CxC | `AdelantoController`, `Adelanto`, `AdelantoCuota` | Saldos de empleados/clientes, cuotas y reversión de movimientos. |
| Proveedores / CxP | `ProveedorController`, `CuentaPorPagarController`, `CuentaPorPagar`, `PagoProveedor` | Proveedores, obligaciones y abonos. |
| Bancos y retiros | `BancoController`, `CuentaBancaria`, `MovimientoBancario`, `Transferencia`, `RetiroController`, `RetiroPropietario` | Saldos/movimientos bancarios, transferencias y retiros patrimoniales. |
| Mesa de cambio | `MesaCambioController`, `Moneda`, `SaldoMoneda`, `OperacionCambio`, `DiferencialCambiario`, `CierreMesaCambio`, `DivisaService` | Operaciones NIO/USD, existencias por ubicación, costo promedio y diferencial cambiario. |
| Contabilidad | `ContabilidadService`, `ContabilidadController`, `CuentaContableController`, `AsientoManualController`, `PeriodoContableController` | Libro diario/mayor, estados, catálogo, centros de costo, reversión y cierre fiscal. |
| Reportes y respaldos | `ReporteController`, `RespaldoController` | Reportes/exportaciones y respaldo/restauración SQL. Verificar impacto antes de restaurar datos. |
| Portal de colaborador | `PortalColaboradorController`, `PortalColaboradorConfig`, `PortalColaboradorToken` | Consulta de comisiones, nóminas, cuotas y perfil con token de sesión propio. Consultar también la limitación de rutas en §6. |

## 5. Reglas financieras y de inventario obligatorias

### 5.1 Partida doble y atomicidad

- Toda operación que cambie una posición financiera debe persistir sus movimientos y el asiento contable correspondiente dentro de una operación atómica: ventas, compras, gastos, nómina, adelantos/CxC, pagos a proveedores, caja, bancos, retiros y cambio de divisas cuando aplique.
- Nunca confirmar un asiento desequilibrado. `ContabilidadService::validarCuadre()` suma debe/haber y compara centavos enteros, sin tolerancia. Todo método nuevo que cree asientos debe ejecutar esta validación antes del commit.
- Si falla cualquier paso del caso de uso, se revierten también los cambios de negocio asociados; no dejar venta, pago, stock o saldo parcial.
- Algunas acciones actuales abren transacciones en el controlador y otras también dentro del servicio. Al tocar ese flujo, identifica quién es dueño de la transacción y conserva la atomicidad sin agregar commits prematuros.
- Importes contables se expresan en moneda base y se redondean a dos decimales. Tasas/costos de conversión usan seis decimales cuando la columna así lo define. Evitar cálculos monetarios nuevos con precisión binaria sin normalización explícita.

### 5.2 Catálogo contable y períodos

- Resolver cuentas contra el catálogo; no inventar ni sustituir códigos contables en controladores.
- El servicio usa, entre otras, caja `1.1.1`, banco Lafise `1.1.2.1`, banco BAC `1.1.2.2` y retiros del propietario `3.3`. Confirmar el catálogo seed y las reglas del método concreto antes de añadir mapeos.
- Los retiros del propietario son movimientos contra patrimonio, no gastos operativos.
- `ContabilidadService` resuelve el período de la fecha del movimiento: bloquea períodos cerrados y genera un período mensual abierto si no existe. Una operación fechada en período cerrado no debe saltarse ese bloqueo.
- Conservar referencia de origen, número/concepto de asiento, usuario, fecha y período para auditoría.

### 5.3 Reversión y borrado

- Nunca hacer borrado físico de movimientos financieros, asientos, pagos, CxC/CxP u otros registros que soporten auditoría. Corregir con movimiento y asiento de reversión vinculados o identificables; no editar silenciosamente el historial.
- El flujo de eliminación de adelantos ya genera un nuevo movimiento de naturaleza opuesta y lo contabiliza. Mantener ese patrón para CxC.
- Para catálogos, respetar el comportamiento del modelo y sus relaciones. Hay migraciones de `SoftDeletes` para artículos y fórmulas; no generalizar que todas las entidades los tienen.
- Revisar el controlador, modelo y constraints antes de usar `delete()`, `forceDelete()` o cascadas. Que exista una ruta HTTP `DELETE` no autoriza borrado físico financiero.

### 5.4 Multimoneda y mesa de cambio

- Monedas operativas principales: córdobas NIO (moneda base contable) y dólares USD.
- `DivisaService::ingresarDivisas()` recalcula costo promedio ponderado y redondea a seis decimales; `egresarDivisas()` valida saldo y calcula diferencial con la tasa de venta y el costo promedio.
- `saldos_moneda.costo_promedio_ponderado` está definido como `decimal(15,6)`; los saldos monetarios son `decimal(15,2)`. No degradar esa precisión.
- Mantener saldo segregado por moneda y ubicación (polimórfica); no mezclar efectivo físico, caja y banco.
- Antes de cambiar el tratamiento de una venta cobrada en USD, seguir conjuntamente `VentaController`, `DivisaService` y `ContabilidadService`.

### 5.5 Inventario fraccionado y lotes

- Los servicios consumen insumos definidos en fórmulas; productos físicos consumen unidades. Los artículos fraccionables mantienen volumen abierto y unidades disponibles.
- `InventarioService` descuenta lotes FIFO por unidades/volumen y usa locks `lockForUpdate()` en sus consultas de lote. Los cambios que lo llamen deben ejecutarse dentro de una transacción.
- Mantener sincronizados artículo, lotes, kardex/movimiento correspondiente y contabilidad de costo cuando aplique. No actualizar únicamente el contador agregado.
- No permitir que una venta/merma deje inventario negativo por una ruta nueva; validar el comportamiento y las reglas existentes antes de ajustar cantidades.

### 5.6 Comisiones y nómina

- Las comisiones históricas se calculan y guardan como `ComisionGenerada` durante el flujo de venta; existen `EsquemaComision` y rangos (`EsquemaRango`) como configuración histórica.
- La nómina debe consumir los montos congelados de comisión, no recalcular ventas históricas usando el porcentaje actual del empleado.
- Cambios en venta, esquema o nómina deben considerar el estado de pago de la comisión, adelantos/cuotas y deducciones (incluidos INSS e impuesto sobre la renta).

## 6. Autenticación, autorización y rutas actuales

- Las rutas web administrativas están dentro de `auth`, salvo el bloque inicial de login/logout. Las rutas base incluyen panel, agenda, asistencia y clientes.
- `role:recepcion,contador` agrupa POS/ventas, adelantos, arqueo, caja chica y mesa de cambio. `role:admin` agrupa reportes, recursos humanos, inventario, catálogos, respaldos y contabilidad.
- `RoleMiddleware` permite siempre al usuario cuyo campo `role` sea `admin`, incluso si la ruta enumera otros roles. Al cambiar permisos, evaluar explícitamente ese bypass.
- La migración de septiembre de 2026 amplía `users.role` con `contador`; el enum y la tabla `roles` deben permanecer sincronizados.
- `routes/api.php` declara `apiResource` para `clients`, `items` y `appointments`. No asumir autenticación/autorización de esos endpoints sin verificar middleware y configuración efectiva.
- Hay una discrepancia importante en el portal: `/portal/login` y el resto del grupo `portal` están anidados dentro del grupo web `auth`, aunque el controlador además valida un token de portal propio guardado en sesión. No describir el login del colaborador como independiente del login administrativo hasta corregir y probar esa configuración.
- Antes de alterar rutas, verificar nombres, verbos, middleware y precedencia. `routes/web.php` contiene definiciones duplicadas para el catálogo contable y para la consulta de un período, además de closures con lógica de agenda/POS.

## 7. Datos, migraciones y documentación

- Las migraciones son la fuente de verdad del esquema versionado. Revisar tablas/constraints/índices actuales y migraciones cercanas antes de modificar modelos o generar migraciones nuevas.
- No editar migraciones que ya se hayan ejecutado en entornos compartidos; agregar migración correctiva reversible, salvo que el usuario confirme que el cambio es sólo local y no desplegado.
- Hay migraciones de compatibilidad que verifican existencia de columnas/tablas. Preservar idempotencia cuando sea parte del propósito de esa migración.
- Revisar seeders antes de cambiar catálogo contable, monedas, roles o datos iniciales. `ContabilidadBaseSeeder` es parte relevante de la configuración del motor contable.
- `README.md` describe instalación general, pero referencia `PROJECT_CONTEXT.md` y `DOCUMENTACION_TECNICA.md`, que no figuran en la estructura actual del workspace. Confirmar su existencia antes de enlazarlos o tratarlos como fuentes vigentes.
- `Como_trabajar.md` aporta mandamientos de negocio adicionales. Mantenerlo consistente con este archivo cuando se modifiquen reglas financieras; si hay contradicción, verificar implementación y pedir precisión antes de relajar una restricción financiera.

## 8. Convenciones para implementar cambios

1. Lee instrucciones aplicables y sigue el flujo desde ruta hasta controlador, servicio, modelos y migración. No infieras reglas sólo por el nombre de una clase.
2. Mantén el cambio enfocado y compatible con estilo/idioma local. La interfaz y comentarios existentes mezclan español e inglés; no hagas conversiones masivas no solicitadas.
3. Valida entrada en el límite HTTP, pero mantén invariantes financieras/de inventario en la capa de dominio/servicio para que no dependan de una sola pantalla.
4. Usa transacciones y locks al coordinar saldos, existencias, lotes y asientos que puedan sufrir concurrencia.
5. Para cambios de base de datos, contempla `up()` y `down()`, datos existentes, FKs, precisión decimal e idempotencia cuando corresponda.
6. Añade pruebas cercanas al comportamiento afectado. Prioridad: cuadre exacto, rollback, reversión, saldo insuficiente, límites de rol y consistencia de inventario.
7. No afirmar que una regla está cubierta por tests si sólo existe prueba de ejemplo. Ejecuta el test más específico disponible y reporta bloqueos de entorno.
8. No tocar `vendor/`, `public/build/`, secretos ni `.env` salvo solicitud explícita.

## 9. Comandos de trabajo en Windows

Usar PowerShell. Para inspeccionar código, buscar texto o leer archivos, usar las herramientas de workspace; no usar comandos de terminal ni utilidades GNU como `grep`, `cat`, `sed`, `awk` o `wc` para esa tarea. La terminal se reserva para comandos del ecosistema.

- Instalar dependencias: `composer install` y `npm install`.
- Preparación definida por Composer: `composer setup` (revisar `.env` y la base configurada antes; el script ejecuta migraciones con `--force`).
- Pruebas: `composer test` o `php artisan test --filter=NombreDePrueba`.
- Formato PHP: `vendor/bin/pint --test app/Services/ContabilidadService.php` (ajustar ruta al código tocado).
- Compilar frontend: `npm run build`.
- Desarrollo: `composer run dev` levanta Laravel, cola, logs y Vite según el script de Composer.

No ejecutar `migrate:fresh`, restauraciones, seed destructivo ni comandos que borren/reemplacen datos sin autorización explícita y confirmación del entorno/base destino.

## 10. Verificación mínima por tipo de cambio

- Regla financiera: prueba de asiento balanceado/desbalanceado y rollback; comprobar referencia y período.
- Venta/caja/divisas: verificar pagos mixtos, vuelto, moneda base, cuentas destino y saldos por ubicación.
- Inventario: verificar artículo, volumen abierto, FIFO de lotes, existencia negativa y costo.
- Nómina/comisión: verificar comisión congelada de la venta, cuotas/deducciones y asiento.
- Permisos/rutas: verificar usuario sin sesión, rol permitido, rol denegado y bypass admin intencional.
- Blade/JS/CSS: compilar con Vite y comprobar estados vacíos, errores de validación y uso responsive del flujo afectado.
- Migración: comprobar aplicación y reversión en una base de prueba apropiada; no usar producción como entorno de validación.
