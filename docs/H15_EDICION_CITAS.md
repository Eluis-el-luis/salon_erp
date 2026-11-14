# H15 — Edición y reprogramación de citas

Estado final: implementado, 72/72 pruebas PHP aprobadas (684 aserciones),
26/26 JavaScript y build correcto. Desarrollo y bootstrap íntegros; indicador
de recreación de pruebas nuevamente en cero. Sin commits.

## Alcance implementado

Se reutiliza AgendaService y el modal existente. No se añade una segunda
implementación de disponibilidad, migraciones, estados, dependencias ni lógica
financiera. VentaController y AprobacionService permanecen sin cambios en H15.
No se continúa con H01.

Operaciones separadas, dentro del grupo web+auth ya existente:

- GET /api/appointments/{appointment}: precarga explícita para editar.
- PUT /api/appointments/{appointment}/details: datos/notas, con edit_token.
- PUT /api/appointments/{appointment}: transición H16, con status obligatorio.

Omitir status ya no completa la cita. No se mezclan datos y estado en una
misma petición. La ruta de edición rechaza status y duration_minutes; el
servidor escribe únicamente campos explícitos validados.

## Reglas

Pendiente sin venta no anulada: permite cliente, servicio, fecha/hora, estilistas
y notas. Completada, cancelada, no_asistio o vinculada a venta no anulada: solo
notas, según la aclaración expresa del propietario. Reactivar usa exclusivamente
el flujo H16. Una venta anulada no bloquea edición de una cita pendiente.

La restricción es por cambios REALES: reenviar valores comerciales iguales no
transforma una edición de notas en reprogramación. Agenda no altera documentos
financieros. La protección de documentos conserva el criterio H16 status <> anulada,
incluidas cotizaciones vinculadas, sin introducir una excepción nueva.

Cambiar service_id copia la duración positiva del nuevo services.duration.
Con el mismo servicio se conserva la duración fija, aunque cambie el catálogo;
una edición de notas tampoco recalcula. No se suma cleanup_minutes ni se
seleccionan variantes. La fecha se normaliza en la zona del salón y conserva
segundos. El formulario datetime-local usa step=1 para no mover involuntariamente
una cita con segundos al editar notas.

## Bloqueos y protección de formularios obsoletos

editar abre una transacción SQL local, con hasta tres intentos de deadlock como
el resto de agenda. bloquearCitas recibe los estilistas nuevos y bloquea la unión
de anteriores y nuevos por ID ascendente. Después bloquea la cita, relee el pivot
y rechaza si aparece algún estilista actual fuera del conjunto bloqueado. No
adquiere después un mutex faltante en orden inverso: devuelve conflicto para
recargar. Las ventas se consultan después, conservando usuarios -> citas -> ventas.

La consulta de intervalos es exactamente sinConflictos de H16, con lectura actual
FOR UPDATE y exclusión del propio ID; se comprueban todos los nuevos estilistas
y cruces de medianoche antes de escribir. Datos y sync de appointment_user se
confirman o revierten juntos. Si no cambian estilistas, no se recrea el pivot.

GET de edición obtiene una instantánea coherente bajo el mismo protocolo y
devuelve una huella SHA256 de datos, estado, duración, estilistas ordenados y
updated_at. Al guardar se compara después de los bloqueos. No depende solo de
timestamps con precisión de segundos y no requiere columnas nuevas. Si los
datos cambiaron, se rechaza con 409 antes de escribir; una segunda edición no
sobrescribe silenciosamente la primera. La huella no sustituye autenticación
ni CSRF, y no es una credencial.

## Interfaz

Editar está disponible para cargar cualquier cita autorizada. La respuesta del
servidor determina si se permiten datos comerciales o solo notas. No se envían
campos comerciales desde el modo de notas; aun manipulando la petición, el
servidor compara los cambios reales y aplica la protección.

El modal distingue Agendar/Editar/Editar notas, Crear cita/Guardar cambios,
precarga campos y mantiene confirmación de ID, fecha y hora guardadas. Después
de reprogramar ofrece navegar al día correcto, conservando filtros. No convierte
fechas locales a UTC desde JavaScript ni supone que un fallo de red implique
que la operación no se guardó.

Se mantienen temporalmente opciones históricas seleccionadas que ya no figuran
en el catálogo activo (por ejemplo servicio/estilista inactivo). Sus etiquetas
se insertan con x-text; no se usa HTML del servidor. Primero se renderizan esas
opciones y luego se asigna x-model. El resto del catálogo visible no se amplía.

Controles deshabilitados mientras se carga/guarda, confirmaciones H16 preservadas,
errores de validación/solapamiento/versión/sesión/CSRF diferenciados, sin trazas.
Formulario semántico, labels asociados, dialog identificado y foco inicial en
cliente o notas; cerrar/Escape no interrumpen una petición pendiente. Se consultó
la skill local ui-ux-pro-max para foco y feedback, sin framework/dependencia nueva.

## Verificaciones y ejecución

H15 focalizado: 24 pruebas, 279 aserciones, código 0, 17876 ms. Usa
DatabaseTransactions y fixtures de concurrencia confirmados/limpiados; no recrea
tablas. Incluye notas, separación de estado, cliente, cambios comerciales,
duración histórica, autoconflicto, protección financiera, estados históricos,
medianoche, formularios obsoletos, rollback del pivot, autenticación y CSRF real,
render del modal y opciones inactivas.

Tres carreras reales con procesos/PDO MySQL independientes:
1. Dos ediciones manteniendo estilista: A 200, B 409; solo persiste A.
2. Dos ediciones que cambian estilistas: B detecta un estilista actual fuera
   de su unión bloqueada y no escribe; A 200, B 409.
3. Reprogramación hacia un nuevo estilista frente a reserva nueva del mismo
   intervalo: A 200, B 422; no aparece una reserva adicional solapada.

Se comprueban CONNECTION_ID distintos, espera real en PROCESSLIST, resultado
persistido, pivot, salida 0 y cierre de ambas conexiones. Los hijos establecen
deliberadamente un snapshot anterior; el servicio debe usar sus relecturas actuales.
La infraestructura de H16 se extrajo a InteractsWithAppointmentWorkers para
reutilizarla, sin paralelizar una suite. El worker compartido añade PUT de edición
y confirma éxitos 200/201; el destino sigue fijado por tests/bootstrap.php.

JavaScript final: 26/26 aprobadas. Incluye precarga, segundos, modos de edición,
PUT con sesión/CSRF/huella, notas únicamente, éxito y navegación, conflicto 409,
validación, fallos de sesión y respuestas incompletas, bloqueo de doble solicitud
y reinicio correcto para crear otra cita. Vite compiló con código 0; aviso opcional
fontaine sin instalar paquetes ni cambiar políticas de PowerShell (se usa npm.cmd).

Regresiones H12/H13/H14-A/H16: 48/48 pruebas, 405 aserciones, código 0,
389309 ms. Incluyen facturación de servicios, rechazo de carritos antiguos,
aprobación/anulación, protección financiera, rollback y los dos casos reales
de concurrencia H16 con el auxiliar compartido. Una sola recreación automática
en esta selección; no se ejecutó una suite completa o Composer test.

Total de las dos selecciones: 72 casos PHP, 684 aserciones; cinco carreras con
MySQL independiente (tres H15 y dos H16). Ambas selecciones pasaron en su primera
ejecución, sin reparaciones destructivas ni cambios de cálculos para conseguirlo.

## Aislamiento

Preflight en cada selección: PHP 8.3.30 de Laragon, salon_erp_test,
salon_erp_tester@localhost, TCP 127.0.0.1:3306, CURRENT_ROLE()=NONE;
SHOW GRANTS exacto exclusivo de pruebas y denegación 1142 de SELECT a desarrollo
y bootstrap. Sin root como usuario de ejecución ni conexiones alternativas.
Respaldo salon_erp_test_20261112_074028_3813668.sql disponible con SHA256 validado
contra sidecar. No se crea otro respaldo ni se cambian usuarios/permisos SQL.

run-h16-tests.php se reutiliza con perfiles explícitos h15 y h15-regressions.
Habilita el indicador solo en la ventana de la selección, y lo restaura/verifica
en finally. El primer perfil no usa RefreshDatabase; las regresiones sí están
autorizadas para recrear exclusivamente pruebas. No se cambian guardias de PHPUnit.

Comandos con PHP absoluto E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe
y opciones -d display_errors=0 -d zend.exception_ignore_args=1:

```text
tests/Support/isolation-snapshot.php before H15
tests/Support/run-h16-tests.php h15
tests/Support/run-h16-tests.php h15-regressions
tests/Support/isolation-snapshot.php after H15
node --test tests/Frontend/agenda.test.cjs
npm.cmd run build
```

Línea base completa previa:

| Base | Tablas | Filas | Migraciones |
| --- | ---: | ---: | ---: |
| salon_erp | 78 | 215 | 74 |
| salon_erp_test | 78 | 107 | 74 |
| salon_erp_bootstrap_check | 78 | 202 | 74 |

La comparación final de hashes de filas, conteos y CREATE TABLE confirmó igualdad
EXACTA de desarrollo y bootstrap con sus líneas base. Las inspecciones son
SELECT/SHOW; no son pruebas de aplicación ni DML sobre esas bases.

| Base | Tablas finales | Filas finales | Migraciones | Resultado |
| --- | ---: | ---: | ---: | --- |
| salon_erp | 78 | 215 | 74 | Contenido y estructura intactos |
| salon_erp_test | 78 | 107 | 74 | Recreación autorizada; fixtures retirados |
| salon_erp_bootstrap_check | 78 | 202 | 74 | Contenido y estructura intactos |

Las pruebas pueden avanzar AUTO_INCREMENT incluso con rollback; no se reinician
contadores ni se promete igualdad de los timestamps/counters de pruebas con
antes de RefreshDatabase. El indicador quedó restaurado y verificado en 0 tras
AMBAS selecciones. Evidencia ignorada: isolation-H15-before.json,
h15-phpunit.log y h15-regressions-phpunit.log. Sin contraseñas, PIN o cookies en
esos informes de resultado; no se publican cuerpos de respuesta de desarrollo.

## Archivos y revisión

Producción: AgendaService, CitaController, routes/web.php y vista
appointments/index.blade.php. Sin cambios H15 en ventas, contabilidad,
inventario, nómina, modelos o migraciones.

Pruebas nuevas: AppointmentEditingTest, AppointmentEditingConcurrencyTest y
InteractsWithAppointmentWorkers. Modificados los auxiliares de concurrencia
H16, su launcher/perfiles, el snapshot de fase H15 y agenda.test.cjs. Este informe.
No se modifica AGENTS.md, .env ordinario ni APP_KEY; no hay commits.

Git al cierre: nueve archivos versionados modificados y cuatro nuevos sin
seguimiento. Sintaxis PHP y git diff --check correctos. El diff completo de los
13 archivos, incluidos los nuevos, está en storage/app/private/h15-review.diff;
está ignorado y no incluye .env ni credenciales. No se añadió nada al índice.

## Límites y reversión

La garantía exige usar AgendaService en futuros escritores de citas/pivot;
SQL directo no adquiere esos mutex. La versión se aplica a la edición de datos,
no cambia el protocolo H16 de estados o la lógica comercial del POS. No se
reescriben carritos abiertos ni documentos financieros al reprogramar; si un
operador cambia datos de una cita antes de facturar, debe revisar el carrito
vigente. No se añadieron reglas de correspondencia/precios al POS.

No se evalúa carga masiva ni se promete ausencia total de contención/deadlocks.
No hay prueba visual completa en navegador ni auditoría completa de accesibilidad.
No se reconstruyen intervalos históricos inválidos ni se seleccionan variantes.

Revertir solo los parches H15 de los cuatro archivos de producción y sus
pruebas/auxiliares, conservando H16. No retirar AgendaService entero: existía
antes de H15 y lo usan ventas/estados. Revertir juntos interfaz y rutas.
No hay migraciones que deshacer ni restauración SQL necesaria por esta entrega.
No usar git reset global ni sobrescribir datos o trabajo ajeno.
