# H13 — Citas y catálogo comercial de servicios

## Alcance y limitación histórica

services es la fuente de verdad comercial. items contiene productos e insumos.
Las coincidencias de ID o nombre entre ambos no prueban correspondencia.
Las ocho citas identificadas en desarrollo siguen sin conciliación individual
autorizada. La venta 106 y sus detalles no se modifican.

La migración nueva 2026_11_13_000001_point_appointments_to_services.php cambia
la referencia únicamente cuando appointments está VACÍA. Rechaza cualquier
cita heredada, incluso si el mismo número existe en ambos catálogos. No migra,
borra ni remapea datos. El éxito sobre una base vacía no valida los servicios
originales de ninguna cita histórica.

## Cambios de código

- CitaController valida service_id mediante exists:services,id. Mantiene la
  transacción H14-A, duración, estados y reglas de acceso H12.
- Cita::servicio conserva belongsTo Servicio; solo se corrige su comentario.
- Servicio::citas es hasMany por service_id.
- Usuario::citas es belongsToMany mediante appointment_user, user_id y appointment_id.
- Se retira Articulo::citas, relación semánticamente falsa; no se encontraron
  consumidores internos que la llamasen sobre artículos. Sus otras relaciones
  y fillable permanecen iguales.
- Fixtures H12/H14-A usan servicios sin artículos gemelos. El antiguo test de
  rechazo del servicio exclusivo ahora comprueba aceptación.
- No se cambia facturación, contabilidad, fórmulas, FIFO, nómina ni interfaz.

## Migración y condiciones de operación

up comprueba conexión MySQL sin prefijo, tablas InnoDB, columnas bigint unsigned
NOT NULL, FK simple esperada y reglas restrictivas, esquema de referencia local,
ausencia de citas y ausencia de transacción activa. Solo entonces emite UN
ALTER TABLE que retira la FK a items y crea la FK a services con otro nombre.
No utiliza FOREIGN_KEY_CHECKS=0. Conserva la columna, los IDs y sus índices.

down comprueba simétricamente el estado nuevo y exige appointments vacía antes
de volver a items. No intenta inferir asociaciones por IDs ni crear artículos
ficticios para facilitar el rollback.

El operador debe excluir TODOS los escritores (HTTP, colas, scheduler y otros
clientes SQL) durante comprobaciones y DDL: la comprobación de tabla vacía no
es un bloqueo contra escritores externos. La migración no afirma ofrecerlo.
MySQL DDL implica commits; no se envuelve en una transacción PHP. Se usa ALTER
único para evitar un paso independiente con la tabla sin FK. El ensayo real
comprueba esta operación en MySQL 8.4.3; no se usa SQLite como sustituto.

## Pruebas inspeccionadas

AppointmentServicesTest: servicios exclusivos, rechazo HTTP/1452 SQL de un
producto exclusivo, colisión deliberada, borrado restringido 1451, relaciones
inversas y varios estilistas. Los IDs de producto y servicio normales son
disjuntos. La colisión se fabrica solamente en su caso específico.

La regresión de facturación utiliza un servicio sin artículo, dos estilistas,
una caja fixture, rol propietario y catálogo contable local; comprueba venta vinculada y líneas
de servicios. No consume insumos ni llama servicios externos. Los seeders,
migraciones y rutas implicadas se inspeccionaron. Logs de pruebas en canal null.

AppointmentServicesMigrationTest se ejecuta DESPUÉS de las pruebas con
RefreshDatabase, en el mismo comando. No usa transacción exterior: el DDL de
MySQL la confirmaría implícitamente. Comprueba además el indicador de reset y
hereda las verificaciones de cuenta/base de TestCase. Las filas simuladas se
crean dentro de transacciones que revierten tras cada rechazo, sin DDL en ellas.
Ensaya down/up vacío, rechazo por transacción activa, rechazo de filas sin
destino, rechazo de colisiones ambiguas y down bloqueado con citas nuevas.
Termina con appointments vacía y FK a services, sin alterar el registro de
migraciones. Requiere la preparación anterior; no se debe ejecutar aislado
contra un esquema desconocido.

## Ejecución aislada

PHP: E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe.
Destino confirmado por PDO: salon_erp_test, salon_erp_tester@localhost, 3306,
rol NONE. SHOW GRANTS: solo esquema exacto de pruebas más USAGE global sin
acceso a datos. SELECT sobre salon_erp.users denegado con 1142. No había cachés
de rutas/configuración ni variables heredadas que cambiasen el destino.

Respaldo disponible:
E:\salon-erp-backups\salon_erp_test_20261112_074028_3813668.sql
SHA256 comprobado: 8778320250E84322267A7D99BF86E18F70C099D0117EA5B73DAD67C2C32AA531.
Baseline H13 ignorado: desarrollo 78 tablas/643 registros/73 migraciones;
pruebas 78 tablas/106 registros/73 migraciones. El auxiliar de comparación
solo añade el identificador de fase H13; no se alteran sus consultas.

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --configuration phpunit.xml --do-not-cache-result --no-progress tests/Feature/AppointmentServicesTest.php tests/Feature/ApiAgendaSecurityTest.php tests/Feature/AgendaNavigationTest.php tests/Feature/AppointmentServicesMigrationTest.php
```

Se habilita temporalmente el indicador en try/finally y se restaura el archivo
local original con RESET_ALLOWED=0. Salida ignorada: storage/app/private/h13-phpunit.log.
No hay suite completa ni ejecución paralela. No se ejecuta ningún Artisan
migrate/rollback contra desarrollo.

La primera ejecución aprobó 16 de 17 casos (166 aserciones antes del fallo).
El fixture de facturación usaba admin, pero la configuración vigente solo
exime a alvaro del umbral de aprobación (config/salon.php). Por eso recibió
201 de venta retenida, en vez del cobro inmediato 200 que se quería verificar.
Se corrigió SOLO el rol del fixture y se añadió la comprobación de venta pagada;
no se relajaron políticas ni aserciones. La repetición conserva salida en
storage/app/private/h13-phpunit-final.log. Finalmente se restaura el indicador
en ambas ejecuciones. Tras la primera, todas las tablas de desarrollo seguían
idénticas a la línea base.

## Traslado a desarrollo: NO habilitado

Con las ocho citas actuales esta migración se DETENDRÁ. No ejecutar migrate,
composer setup ni usar los cambios H13 como un despliegue completo: el código
y la FK deben activarse coordinadamente. En un esquema antiguo, la validación
nueva podría aceptar un servicio sin item y la FK antigua rechazar su alta.

Antes de trasladar H13:
1. Revisar y aprobar correspondencias por cita, con evidencia y responsable.
2. Elegir una extensión explícita de conciliación o una columna comercial
   nueva que preserve la referencia heredada y permita señalar lo no resuelto.
3. Diseñar y ensayar esa extensión separadamente; NO vaciar la tabla ni quitar
   el guardia para sortear el rechazo. No basta aprobar coincidencias numéricas.
4. Respaldar de nuevo el desarrollo actual, ensayar en copia aislada y revisar
   venta 106, vínculos, conteos y hashes antes/después.
5. Obtener autorización de los cambios de datos y excluir escritores durante
   la ventana de despliegue de esquema y código.

## Diff y reversión

Hay cambios H14-A previos sin commit: no revertir archivos enteros ni usar
git reset global. Revertir solo las líneas H13 del controlador/modelos y los
fixtures, retirar sus dos tests nuevos y documento y el identificador de
snapshot H13. Conservar rutas, vista y protecciones H12/H14-A.

En pruebas vacías, down puede restaurar la FK antigua tras verificar su estado.
Con citas nuevas, down se niega, aunque sus IDs existan también en items.
Se requiere corrección hacia adelante o un procedimiento aprobado de
conciliación/restauración; nunca borrar citas ni desactivar FK. Eliminar el
archivo de migración no revierte un esquema que ya se haya migrado.

No se modificaron migraciones históricas. H15 y disponibilidad siguen fuera
de alcance. No se certifica identidad comercial del historial por aprobar tests.

## Resultado final

- PHPUnit 12.5.33 / PHP 8.3.30: **17/17 aprobadas, 175 aserciones**, código 0,
  484605 ms. Incluye cuatro casos H13 funcionales, uno de migración, ocho H12
  y cuatro H14-A. No se ejecutó la suite completa ni pruebas paralelas.
- Regresión JavaScript H14-A: **9/9 aprobadas**, sin red ni SQL.
- Sintaxis PHP y git diff --check: correctos. No se instalaron dependencias.
- Comparación final: TODAS las tablas de salon_erp conservan conteos y hashes
  de contenido y estructura de la línea base: 78 tablas, 643 registros y
  73 migraciones. Conserva ocho citas, la FK a items y la venta 106 sin cambios.
- salon_erp_test: 78 tablas, 107 registros y 74 migraciones; FK a services.
  No quedan filas fixture en appointments, appointment_user, sales,
  sale_details, clients ni users. El registro adicional es la nueva migración.
- PDO posterior confirmó salon_erp_tester@localhost, salon_erp_test y puerto
  3306; la lectura de desarrollo volvió a rechazarse con 1142.
- SALON_TEST_DB_RESET_ALLOWED=0 restaurado mediante finally en ambas ejecuciones.
  Contraseñas, logs y baselines permanecen ignorados por Git.

Archivos H13: app/Http/Controllers/CitaController.php (solo la regla service_id),
app/Models/Cita.php (comentario), Servicio.php, Usuario.php y Articulo.php;
la migración nueva; tests/Feature/AppointmentServicesTest.php,
AppointmentServicesMigrationTest.php, ApiAgendaSecurityTest.php y
AgendaNavigationTest.php (fixtures); tests/Support/isolation-snapshot.php
(identificador H13) y este documento. Los cambios de routes/web.php,
resources/views/appointments/index.blade.php y demás artefactos H14-A ya estaban
presentes antes de iniciar esta entrega y se conservan.

Para revisión, git diff muestra los archivos versionados y los archivos nuevos
deben inspeccionarse completos. El diff contra HEAD puede incluir H14-A: no
usarlo indiscriminadamente como parche inverso de H13.

No se ejecutó ninguna migración, conciliación o restauración en salon_erp.
El resultado habilita H13 exclusivamente en pruebas; el traslado a desarrollo
continúa pendiente de decisiones y autorización sobre las correspondencias.
