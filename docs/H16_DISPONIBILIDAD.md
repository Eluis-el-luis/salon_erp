# H16 — Disponibilidad de estilistas

Implementado y verificado en salon_erp_test. No se ejecutaron pruebas de aplicación
contra desarrollo o bootstrap_check. Sin cambios de esquema, migraciones,
dependencias, estados, cálculos financieros, FIFO, nómina o H15.

## Reglas y duración

- Intervalos semiabiertos [inicio, fin): inicio_existente < fin_nuevo y
  fin_existente > inicio_nuevo. Se permiten extremos consecutivos y reservas
  de diferentes estilistas; se comprueba cada integrante de una cita grupal.
- Pendiente y completada ocupan; cancelada y no_asistio liberan, conservando
  cita, fecha, duración y pivot. No se filtra el conflicto solo por día:
  DATE_ADD considera cruces de medianoche.
- Reactivar desde cualquiera de los estados liberados comprueba otra vez
  disponibilidad, excluyendo la propia cita. No recalcula su duración histórica.
- Reservar copia services.duration a appointments.duration_minutes. Exige
  entero positivo. El duration_minutes positivo que envíe un consumidor antiguo
  se valida pero se ignora; NULL, cero, negativos o valores inválidos se rechazan.
  El formulario ya no envía el 45 fijo. No se suma cleanup_minutes.
- No se seleccionan variantes. Cuando exista selección explícita, validar que
  pertenece al servicio y congelar variant.duration ?? services.duration. No
  deducir la variante de una venta posterior ni cambiar citas existentes.
- No se añade una restricción nueva de rol: se conservan rutas, autenticación,
  CSRF y roles existentes. Se exige al menos un usuario existente, IDs enteros
  y sin duplicados. La vista conserva su selector de estilistas activos.

## Protocolo transaccional

AgendaService coordina creación y transiciones. Para crear, bloquea users por ID
ascendente, incluso sin citas previas. Para una cita existente descubre los IDs
del pivot, adquiere los mismos mutex, relee/bloquea citas por ID, relee el pivot
y comprueba que no apareció un estilista sin bloquear. Si cambió la asignación,
rechaza para reconsultar, sin continuar con mutex incompletos.

El orden global de las operaciones integradas es estilistas -> citas -> ventas.
El pivot se relee bajo el bloqueo de su cita; las consultas de conflictos usan
JOIN y FOR UPDATE para leer datos actuales, incluso si la transacción ya tenía
un snapshot REPEATABLE READ anterior. La decisión no depende de la primera
lectura informativa ni solamente de bloquear citas que pueden no existir.

Creación y pivot se confirman juntos. Los cambios de agenda usan DB::transaction
con hasta tres intentos de deadlock, seguros al tener solo escrituras SQL locales.
Las transacciones financieras exteriores conservan su propietario: no se añaden
commits intermedios ni reintentos automáticos de operaciones financieras.

Para H15: reutilizar este servicio y adquirir la unión de estilistas antiguos y
nuevos antes de modificar asignaciones/horario, en el mismo orden; releer y
revalidar. No se implementó endpoint de reprogramación ni formulario de edición.

## Integración mínima con ventas

VentaController::facturarCita valida estado actual y adquiere mutex dentro de
la transacción antes de crear la venta. El POS hace lo mismo para TODOS los
appointment_id del carrito y el vínculo persistido de una cotización convertida,
antes de bloquear clientes/ventas. Cancelada o no_asistio producen rechazo 422;
un carrito antiguo no puede reactivarlas. Las cotizaciones sin facturación no
completan citas, conservando su comportamiento anterior.

El esquema sales conserva una sola appointment_id. Se rechazan carritos con
varias citas distintas y conversiones que mezclarían la cita de la cotización
con otra del carrito, antes de producir documentos o efectos. El POS real abre
una cita desde su URL; múltiples líneas/estilistas para esa misma cita siguen
permitidas. No se inventa una relación múltiple ni se pierden vínculos al aprobar.

AprobacionService adquiere mutex de cita antes de bloquear la venta y valida
el estado antes de inventario, pagos, asientos y comisiones. Relee la venta y
rechaza si su vínculo cambió. Una aprobación sobre estado incompatible se
revierte conservando el documento retenido, sin efectos financieros parciales.
Pendiente y completada siguen siendo compatibles con la facturación existente.

La anulación de venta respeta el mismo orden y mantiene la regla existente
completada -> pendiente para refacturar: ambas ocupan el mismo intervalo. No
se modificaron sus reversiones, comisiones o cálculos.

Conforme a la aclaración del propietario, agenda impide AMBOS estados liberados
si hay venta no anulada. La comprobación conservadora usa status <> anulada,
incluyendo una cotización que conserve appointment_id: no se presume su extinción.
Una venta anulada no bloquea. Agenda solo lee documentos; nunca genera, anula o
modifica ventas como consecuencia de una transición.

## Interfaz

Se conservan selección de fecha, confirmación de ID/fecha/hora y sesión/CSRF.
El error de disponibilidad explica que se debe elegir otro horario/estilista,
sin mostrar trazas SQL. El bloqueo financiero tiene mensaje propio.

Controles mínimos con confirmación: completar, cancelar, no asistió y reactivar.
Cancelada/no_asistio muestran su estado y Reactivar; solo pendiente ofrece el
enlace al POS. Los botones se deshabilitan durante el envío y el error permanece
visible. No hay edición de fecha, servicio o estilistas de H15.
Se consultó la skill local ui-ux-pro-max para feedback y recuperación de errores,
sin cambios globales de diseño ni dependencias.

## Seguridad y ejecución

Preflight del launcher: PHP 8.3.30, bootstrap/raíz existentes, destino efectivo
salon_erp_test / salon_erp_tester@localhost / 3306, CURRENT_ROLE()=NONE,
SHOW GRANTS exactos: USAGE más SELECT, INSERT, UPDATE, DELETE, CREATE, DROP,
ALTER, INDEX y REFERENCES únicamente sobre salon\_erp\_test. SELECT sobre
salon_erp y salon_erp_bootstrap_check rechazados con 1142. Sin fallback a root.

Respaldo de pruebas disponible:
E:\salon-erp-backups\salon_erp_test_20261112_074028_3813668.sql
(107631 bytes), SHA256 comprobado contra su sidecar. No se creó otro respaldo.
Se revisaron migraciones/seeders y efectos de fixtures: SQL local, servicios sin
consumos en pruebas financieras; correo array, cache array, cola sync y logs null.
No servicios externos, exportaciones o procesos ajenos al ensayo.

run-h16-tests.php habilita temporalmente el indicador, invoca PHPUnit directamente
con phpunit.xml y archivos explícitos, y lo restaura/verifica en finally. No se
modifican los guardias ni la allowlist de PHPUnit. La selección completa incluye
solo H16 y regresiones H12, H13 de servicios/facturación y H14-A; no incluye una
suite financiera completa ni la migración/reversión H13 ya comprobada previamente.

Comandos (PHP absoluto E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe,
opciones -d display_errors=0 -d zend.exception_ignore_args=1):

```text
tests/Support/isolation-snapshot.php before H16
tests/Support/run-h16-tests.php
tests/Support/run-h16-tests.php checks-only
tests/Support/run-h16-tests.php links-only
tests/Support/isolation-snapshot.php after H16
node --test tests/Frontend/agenda.test.cjs
npm.cmd run build
```

## Resultados

- Selección PHP inicial: 44/44, 374 aserciones, exit 0, 396045 ms; incluye dos
  pruebas MySQL de concurrencia real y regresiones H12/H13/H14-A.
- Tras añadir controles finales y corregir la limpieza de fixtures: selección
  checks-only 3/3, 48 aserciones, exit 0, 7933 ms. Solo render transaccional y
  repetición de los dos casos de concurrencia, SIN recrear tablas nuevamente.
- Revisión final de vínculos: links-only 3/3, 20 aserciones, exit 0, 1362 ms;
  transaccional, SIN recreación adicional. Comprueba rechazo de múltiples citas,
  conservación del vínculo de una cotización y múltiples líneas de una misma cita.
- Total de casos distintos PHP aprobados: 48; 50 ejecuciones en las selecciones
  finales (dos casos de concurrencia repetidos), 442 aserciones. Una ejecución
  intermedia de links-only pasó un caso y falló una aserción del fixture que
  comparaba un modelo sin hidratar con defaults/decimales hidratados por MySQL;
  se corrigió solo la comparación a dos estados hidratados y los tres casos
  pasaron. Log intermedio conservado, sin fallo de aplicación ni cambio de cálculos.
- JavaScript final: 14/14. Prueban confirmación, fecha local, errores, CSRF en
  requests, reactivación y ausencia de duración arbitraria predeterminada.
- Vite: exit 0. PowerShell rechazó inicialmente npm.ps1 por su política; se
  ejecutó npm.cmd, sin cambiar la política. Aviso no bloqueante del paquete
  opcional fontaine, no instalado. Artefactos public/build ignorados.
- Sintaxis PHP y git diff --check correctos. Sin commits.

El rollback se comprobó tanto al fallar la asociación (regresión H14-A) como
después de insertar un asiento simulado: venta, detalles, asiento y estado de cita
se revierten. Rechazos por cita liberada conservan íntegros los snapshots de
ventas, detalles, pagos, asientos, comisiones, lotes, kárdex y saldos. Una venta
retenida incompatible conserva estado/payload y no genera efectos al aprobar.

## Concurrencia real

Cada caso inicia solo DOS procesos PHP controlados. Cada hijo carga el bootstrap
de pruebas, valida identidad SQL y usa su PDO MySQL independiente; no recibe
destinos/credenciales por argumentos. Los IDs CONNECTION_ID son distintos.
Los fixtures son confirmados para ser visibles a ambos procesos.

Caso compartido: A retiene realmente el mutex users. B establece deliberadamente
un snapshot anterior y comienza su SELECT FOR UPDATE. El padre comprueba el
comando bloqueante en PROCESSLIST de la misma cuenta, luego permite confirmar A.
Resultado: A 201, B 422 por disponibilidad; la base contiene exactamente el ID
de A y su pivot, sin cita de B.

Caso distinto: B usa otro estilista y confirma con 201 mientras A conserva su
mutex; A confirma después. Dos IDs persistidos y dos asociaciones comprobados.
Se comprueban salida 0 y desaparición de ambas conexiones en PROCESSLIST.
Finally termina procesos propios si falla el ensayo y retira sus archivos.
Los requests de este ensayo usan el bypass CSRF normal del entorno testing;
la prueba real de middleware sin bypass está en las regresiones H12/H14-A/H16.

Se detectaron dos clientes de fixture conservados por SoftDeletes en la primera
limpieza. Se cambió solo el test para forceDelete de su cliente sin historia,
después de borrar sus citas. Los dos residuos anteriores se retiraron mediante
PDO tester: patrón/IDs de fixture, deleted_at no NULL, bloqueo de fila y ausencia
de citas/ventas comprobados antes del DELETE, en una única transacción. Los
casos repetidos ya verifican que no dejan esos registros. Ninguna limpieza en
desarrollo, bootstrap o documentos financieros.

## Estado de bases y limitaciones

Líneas base completas antes y comparación después: filas ordenadas por hashes,
conteos y SHOW CREATE TABLE de cada tabla. Desarrollo y bootstrap EXACTAMENTE
iguales. No se efectuaron pruebas de aplicación ni DML sobre esas bases.

| Base | Tablas | Filas finales | Migraciones | Resultado |
| --- | ---: | ---: | ---: | --- |
| salon_erp | 78 | 208 | 74 | Contenido y estructura intactos |
| salon_erp_test | 78 | 107 | 74 | Recreación autorizada; fixtures limpiados |
| salon_erp_bootstrap_check | 78 | 202 | 74 | Contenido y estructura intactos |

Las pruebas pueden avanzar AUTO_INCREMENT aun con rollback. No se reiniciaron
contadores ni se promete identidad de timestamps/DDL de pruebas con su estado
anterior a RefreshDatabase. SALON_TEST_DB_RESET_ALLOWED terminó en 0 en AMBAS
ejecuciones. Evidencias ignoradas: isolation-H16-before.json, h16-phpunit.log y
h16-checks-phpunit.log, h16-links-phpunit.log y h16-links-phpunit-first.log.

InnoDB puede aplicar locks de rango en consultas de conflicto y todavía puede
haber contención/deadlocks; el orden y los reintentos acotados de agenda reducen
ese riesgo, sin prometer ausencia total de espera. Las transacciones financieras
no se reintentan automáticamente. No se probó una carga masiva ni se añadieron
índices sin evidencia de necesidad.

La garantía exige reutilizar AgendaService en cualquier futuro escritor de
citas/pivot. Escrituras SQL directas o código futuro que eluda el protocolo no
están protegidos por una restricción SQL de exclusión. No se reparan ni recalculan
automáticamente intervalos históricos inválidos o solapamientos previos. No hubo
prueba visual completa en navegador; sí render Blade y comportamiento JS.

## Archivos y reversión

Producción: nuevo app/Services/AgendaService.php; cambios delimitados en
CitaController, VentaController, AprobacionService y appointments/index.blade.php.
Sin cambios en modelos, rutas, configuraciones ordinarias o migraciones.

Pruebas: AppointmentAvailabilityTest, AppointmentConcurrencyTest,
AgendaStateControlsTest, AppointmentSaleLinksTest, h16-concurrency-worker.php, run-h16-tests.php;
agenda.test.cjs y fase H16 de isolation-snapshot.php. Este informe y diagnóstico
previo señalado como histórico. No se modificó AGENTS.md.

Revertir juntos los parches H16 de los cuatro archivos de producción y retirar
AgendaService y sus pruebas/auxiliares específicos; revertir únicamente la fase
H16 del snapshot y los tests JS añadidos. No usar git reset global ni eliminar
cambios anteriores. No hay migración que deshacer ni restauración SQL necesaria:
los datos de las bases ajenas a pruebas no cambiaron. Revertir devuelve la
vulnerabilidad de disponibilidad anterior y no modifica reservas ya guardadas.

Diff completo de revisión (incluye archivos nuevos, sin modificar índice/commits):
storage/app/private/h16-review.diff. No incluye .env, credenciales ni evidencia
SQL privada. El árbol de trabajo conserva seis archivos versionados modificados
y nueve archivos nuevos sin seguimiento. No usar una reversión global del repo.
