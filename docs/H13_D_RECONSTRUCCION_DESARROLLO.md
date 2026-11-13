# H13-D — Reconstrucción controlada de desarrollo

Estado final: H13-D completado y Salon ERP reabierto. Una sola ejecución de
migrate:fresh exclusivamente sobre salon_erp; 74 migraciones aplicadas y
validaciones críticas aprobadas. Las secciones históricas inferiores conservan
la parada previa y no describen el estado final.

## Reanudación autorizada

Se reanudó por autorización específica del propietario tras la parada descrita
en el informe histórico inferior. El modo resume reconoce el mantenimiento
existente sin repetir down. No se borraron marcadores ni evidencias para forzar
reintentos. La inspección anterior se conserva en h13d-preflight-stopped.json.

El guardia de mantenimiento ahora tiene un predicado comprobable sin Laravel
ni SQL. Se ejecutaron 18 escenarios: 18 aprobados y 0 fallidos, incluyendo el
archivo real de mantenimiento. Acepta /up (o ninguna exclusión), rechaza otras
rutas/comodines, bypass incluso vacío o '0', ausencia de plantilla, estado
distinto de 503 y redirects. La sintaxis PHP de los auxiliares fue validada.

Nueva inspección de Windows: cero workers Artisan/colas/scheduler y cero tareas
programadas identificadas para ERP/Artisan. Servidor Artisan en 8000 y Apache
compartido siguen funcionando; no se afectaron otros proyectos. En ambos
accesos, http://127.0.0.1:8000 y http://salon-erp.test, se comprobó 503 en GET
/login, /agenda y /api/clients, y POST /api/appointments (JSON incluido).

Identidad confirmada: salon_erp / root@localhost / TCP 127.0.0.1:3306,
MySQL 8.4.3, CURRENT_ROLE()=NONE, PHP 8.3.30. No se usa tester.

Nuevas líneas base completas antes de cualquier DDL:

| Base | Tablas | Registros | Migraciones |
| --- | ---: | ---: | ---: |
| salon_erp | 78 | 643 | 73 |
| salon_erp_test | 78 | 107 | 74 |
| salon_erp_bootstrap_check | 78 | 202 | 74 |

Incluyen conteos y hashes de todas las filas y CREATE TABLE de cada tabla.
También se capturaron identidades SQL y hashes de SHOW GRANTS de todas las
cuentas, sin contraseñas. El manifiesto incorpora ahora los propios auxiliares,
migraciones, seeders, .env, .env.testing y archivos funcionales relevantes.

## Respaldo nuevo verificado

Archivo: E:\salon-erp-backups\salon_erp_H13D_20261113_073240_ab75e5d74efb.sql

Tamaño: 177714 bytes. mysqldump exit 0. Estructura y datos, --single-transaction,
--routines --events --triggers; 78 CREATE TABLE, marcador de finalización y sin
USE/CREATE/DROP DATABASE. Nombre único y sidecar .sha256, sin sobrescrituras.
SHA256 releyendo archivo y sidecar:

```text
9D100833F4C1D4A2D2CFB3B760C669B9295DDC521B8DB85208C2CA0EF88714E9
```

Respaldo anterior conservado y SHA256 nuevamente validado:
E:\salon-erp-backups\salon_erp_20261113_054136_1807232_2e37f496.sql

Ambas exportaciones son SQL, no respaldos de .env, APP_KEY, uploads o permisos.
No se ensayó su restauración. No importarlas automáticamente sobre tablas nuevas.

## Reconstrucción e inicialización completadas

El modo rebuild fue invocado una única vez tras comprobar mantenimiento,
manifiesto, SHA256 nuevo, igualdad de las tres bases con la nueva línea base,
ausencia de transacciones InnoDB y de otras sesiones SQL en desarrollo.
Resultado: migrate:fresh código 0. 74 migraciones registradas, ninguna pendiente,
FK appointments.service_id a salon_erp.services.id. DatabaseSeeder esencial
código 0, cinco roles únicos, cuentas obligatorias protegidas, 3.3 patrimonial
y 7.2 inactiva/no imputable. Sin usuarios ni citas históricas, ventas, pagos,
compras, lotes, kárdex, asientos, saldos, nóminas, adelantos, mermas o cajas.

Tras la inicialización esencial, las otras dos bases coinciden EXACTAMENTE con
las líneas base nuevas: todas las filas, conteos y estructura. Identidades SQL
y hashes de SHOW GRANTS también coinciden. .env, APP_KEY y archivos del
manifiesto permanecen intactos.

Canal HTTP interno en 127.0.0.1:8767 iniciado con comprobación de Host/origen y
nonce privado. Login devuelve 200 con formulario CSRF; sin nonce devuelve 503.
Se abrió WezTerm directamente con PHP desde cmd para configurar propietario y
comprobar login real, sin scripts PowerShell bloqueados ni secretos en argumentos.
La cuenta/contraseña SQL y APP_KEY provienen solo del .env de desarrollo y no
se copian a archivos auxiliares. El acceso ordinario permanece en mantenimiento.

El comando real salon:propietario finalizó con código 0 en WezTerm. El
propietario confirmó entrada oculta de contraseña y PIN, incluidas sus
confirmaciones, y RUNNER_EXIT_CODE=0. Se comprobaron cuenta activa, role=alvaro,
role_id coherente, hashes de contraseña/PIN y expediente vinculado; ningún
secreto fue pasado como argumento, impreso o guardado en evidencia auxiliar.

DemoSeeder finalizó con código 0: cinco usuarios y cinco expedientes con
roles coherentes; dos artículos con existencia_actual=0 y current_volume=0,
dos servicios demostrativos y una fórmula referida por claves deterministas.
No se crearon compras, facturas, saldos, lotes, kárdex ni asientos ficticios.

## Verificaciones HTTP y funcionales aprobadas

Las comprobaciones se realizaron por el canal interno mientras el ERP ordinario
seguía cerrado. Se usa Laravel real con sesión, autenticación, roles y CSRF;
solo mantenimiento se exceptúa dentro del proceso de loopback autorizado por
nonce. No se usó PHPUnit, postJson ni bypass del middleware CSRF.

| Comprobación | Resultado |
| --- | --- |
| Login HTTP del propietario | 302 a página autorizada; sesión válida después |
| Agenda, clientes, inventario y servicios con sesión del propietario | 200 |
| Clientes y citas sin sesión | 401 |
| Crear cita anónima con CSRF válido | 401 |
| Crear cita con sesión, sin CSRF o con CSRF incorrecto | 419; no persistió citas |
| Servicio exclusivo de services, sin producto del mismo ID | Servicio 3 aceptado por H13 |
| Dos citas del mismo estilista a horas distintas | 201 ambas; IDs 1 y 2 |
| Día futuro 2026-11-14 seleccionado | Ambas citas visibles; fecha correcta en HTML |
| Día actual por defecto | No incluye citas futuras |
| Fecha imposible | 422 |
| Borrado de cuenta base 3.3 | 403 |
| Estilista: agenda / catálogo contable | 200 / 403 |

Se dejaron en desarrollo un cliente, un servicio adicional y dos citas de
comprobación H13-D (10:00 y 12:00, 14/11/2026), con sus vínculos al estilista.
No son citas históricas reasociadas. La contraseña temporal del estilista demo
se restauró en finally tras probar permisos. No se hizo ninguna operación
financiera de prueba. El PIN se comprobó como hash e ingreso oculto; no se
ejercitó una autorización financiera por PIN.

## Integridad y reapertura

Después de la inicialización esencial, del modo owner, de verify, antes de up
y después de reabrir se compararon las otras dos bases con las NUEVAS líneas
base. Resultado: igualdad exacta de todas sus filas, conteos y CREATE TABLE.
También coinciden los hashes de SHOW GRANTS y las identidades de cuentas SQL.
No se modificaron cuentas, permisos, .env ni APP_KEY. El usuario root ordinario
conserva sus privilegios previos; no se declara que sea una cuenta restringida.

Última lectura de cierre:

| Base | Tablas | Registros | Migraciones | Estado |
| --- | ---: | ---: | ---: | --- |
| salon_erp | 78 | 208 | 74 | Reconstruida, completa, FK a services |
| salon_erp_test | 78 | 107 | 74 | Contenido y estructura idénticos a su línea base |
| salon_erp_bootstrap_check | 78 | 202 | 74 | Contenido y estructura idénticos a su línea base |

Desarrollo: cinco roles, cinco usuarios, cinco expedientes, 63 cuentas contables,
dos artículos, tres servicios (dos demo más el de comprobación), una fórmula,
un cliente, dos citas y dos asociaciones. El total incluye sesiones generadas
por verificaciones HTTP y puede variar con uso normal. Ventas, pagos, compras,
asientos, saldos, lotes, kárdex, nóminas, adelantos y mermas comprobados vacíos.
Los ocho appointments antiguos y la venta 106 no se conservaron en el esquema
nuevo; su estado anterior está en los respaldos autorizados.

El servidor interno 8767 se detuvo antes de retirar mantenimiento. verify y
open terminaron con código 0, sin repetir down ni migrate:fresh. Ya no existen
los archivos activos de mantenimiento. Se comprobó el puerto auxiliar cerrado.

Tras up, comprobación por AMBOS accesos ordinarios
(http://127.0.0.1:8000 y http://salon-erp.test): /login 200, /api/clients sin
sesión 401 y /agenda sin sesión 302 al login. Apache compartido y el servidor
Artisan original continuaron funcionando. No se interrumpieron otros proyectos.

SALON_TEST_DB_RESET_ALLOWED sigue en 0. No se ejecutó PHPUnit, Composer setup,
Composer test, pruebas paralelas ni una suite financiera.

Comandos del runner, siempre con PHP absoluto 8.3.30 y
-d display_errors=0 -d zend.exception_ignore_args=1:

```text
tests/Support/development-rebuild-guard-check.php
tests/Support/development-rebuild.php resume
tests/Support/development-rebuild.php baseline
tests/Support/development-rebuild.php backup
tests/Support/development-rebuild.php rebuild
tests/Support/development-rebuild.php owner
tests/Support/development-rebuild.php verify
tests/Support/development-rebuild.php open
```

El respaldo se ejecutó con permiso de escritura fuera del workspace. No se
instalaron dependencias ni se hicieron commits. .env y APP_KEY se conservan.

El servidor temporal se inició con PHP absoluto y estas opciones, y luego fue
detenido mediante Ctrl+C sobre su propia sesión, no sobre otros procesos:

```text
-S 127.0.0.1:8767 -t public tests/Support/development-rebuild-http.php
```

## Evidencia, cambios y limitaciones

Evidencias locales ignoradas: h13d-before.json, h13d-grants-before.json,
h13d-backup.json, h13d-schema.json, h13d-essential.json, h13d-owner.json,
h13d-demo.json, h13d-functional.json, h13d-integrity.json, h13d-verified.json,
h13d-opened.json, h13d-final.json, h13d-after.json y h13d-console.log. No mostrar
h13d-closed.json: contiene el nonce interno. Los archivos failure y
assertion-failure conservan la parada HISTÓRICA de close; las evidencias finales
passed=true y opened/final describen el cierre actual. No hubo fallo en la
reconstrucción ni se necesitaron cambios de migraciones/seeders para completarla.

En esta reanudación se modificaron development-rebuild.php (reconocimiento del
mantenimiento, predicado verificable, manifiesto de auxiliares y comparación
de cuentas/grants), development-rebuild-http.php (usa el mismo guardia) y este
informe. Se añadió development-rebuild-guard-check.php. Los auxiliares
development-rebuild-backup.php y development-rebuild-functional.php de la
preparación anterior fueron inspeccionados y ejecutados sin cambios.

Git: los seis archivos H13-D están sin seguimiento; no se añadieron al índice
ni se hicieron commits. Credenciales, nonce y evidencia privada están ignorados.
Sintaxis PHP de los cinco auxiliares y git diff --check correctos. No se
modificaron módulos funcionales, reglas financieras ni migraciones históricas.

No se ensayó restaurar el dump ni un circuito financiero completo en esta fase.
Las pruebas HTTP no sustituyen una revisión visual completa del navegador.
La semilla esencial no inventa bancos operativos, tasas actuales ni saldos.
No se continuó con disponibilidad, H15 o correcciones operativas de inventario.

## Recuperación futura

No se necesita restaurar: la reconstrucción y reapertura pasaron. Si surgiera
un problema, cerrar solo Salon ERP, conservar respaldos/evidencia y obtener
autorización específica para restaurar. Preferir primero una restauración de
ensayo en un destino nuevo autorizado. No importar el SQL sobre tablas nuevas:
se exportó sin DROP TABLE ni CREATE/USE DATABASE. La recuperación requiere un
destino vacío confirmado y el .env/APP_KEY originales conservados.

El respaldo H13D recupera el estado previo con 73 migraciones y FK de citas a
items, no H13. Exige código/esquema compatibles; restaurarlo descartaría los
datos nuevos posteriores y NO equivale a migrate:rollback. No ejecutar otro
migrate:fresh ni restaurar automáticamente. Conservar ambos respaldos.

---

## Informe histórico de la parada anterior — no es el estado actual

Estado: NO se ejecutó migrate:fresh, ninguna migración ni seeder. Salon ERP
permanece cerrado por mantenimiento. No se creó todavía el respaldo nuevo.
No se modificaron cuentas ni permisos SQL, .env ordinario o APP_KEY.

## Comprobaciones previas

PHP 8.3.30 de Laragon. MySQL 8.4.3, conexión TCP a 127.0.0.1:3306.
Identidad efectiva verificada dentro del runner: DATABASE()=salon_erp,
CURRENT_USER()=root@localhost y CURRENT_ROLE()=NONE. Es la cuenta ordinaria
de desarrollo, no tester; no se crearon ni alteraron usuarios SQL.

74 archivos de migración presentes; desarrollo conserva 73 aplicadas.
H12: rutas JSON en grupo web+auth; H13: validación contra services y migración
nueva presentes; H14-A: navegación de fecha presente; H13-B: DatabaseSeeder
esencial separado de demos/credenciales. Los consumidores y cambios ya
verificados en H13-C se conservan.

Sin cachés de configuración/rutas, .env.local, schema dump mysql o variables
heredadas que cambien el destino. El runner valida raíz absoluta, versión PHP,
entorno local, configuración resuelta, APP_KEY original, cuenta y destino en
el mismo proceso. Limita conexiones de Laravel a mysql. Para sus procesos
usa cache array, cola sync, correo array y logging null, sin modificar .env.

No se encontraron conexiones alternativas explícitas ni efectos externos
en migraciones/seeders. Sin FKs entre esquemas, rutinas, eventos ni triggers
en desarrollo; las 78 tablas son InnoDB. No había transacciones InnoDB activas.

Windows: se identificaron el servidor Artisan del ERP en 127.0.0.1:8000 y
Apache compartido. Sin workers Artisan/colas/scheduler ni tareas programadas
identificadas para Salon ERP. No se detuvo Apache ni procesos de otros proyectos.
El bloqueo HTTP se hizo con mantenimiento de Laravel y respuesta prerenderizada,
antes de cargar la aplicación desde public/index.php.

## Fallo que detuvo la fase

El comando down terminó con código 0 y creó los archivos de mantenimiento.
La comprobación siguiente del auxiliar exigía una lista de excepciones vacía.
Laravel incluye automáticamente /up como endpoint de salud: el auxiliar
rechazó esa configuración estándar. Fue un defecto del verificador, no un
fallo SQL ni una evidencia de acceso a otra base.

Datos inspeccionados: mantenimiento activo, plantilla prerenderizada de
6606 bytes, estado 503, secret ausente y única excepción /up.

Comprobaciones HTTP realizadas, sin seguir redirects ni mostrar cuerpos:

| URL | Estado |
| --- | --- |
| http://127.0.0.1:8000/login | 503 |
| http://salon-erp.test/login | 503 |
| http://salon-erp.test/api/clients | 503 |

Se corrigió estáticamente dr_closed para aceptar exclusivamente la excepción
estándar /up y rechazar otras excepciones o bypass secreto. Esa corrección
no se usó para continuar la ejecución: conforme a la condición de parada de
la fase 1, NO se avanzó al respaldo ni a la reconstrucción. No se ejecutó
PHPUnit ni se habilitó SALON_TEST_DB_RESET_ALLOWED.

Un auxiliar de inspección intentó leer una evidencia aún inexistente; se
corrigió para escribir el resumen previsto y volvió a ejecutarse solo con
SELECT/SHOW. Ese error no produjo operaciones SQL de escritura.

## Bases al detenerse

| Base | Tablas | Registros | Migraciones |
| --- | ---: | ---: | ---: |
| salon_erp | 78 | 643 | 73 |
| salon_erp_test | 78 | 107 | 74 |
| salon_erp_bootstrap_check | 78 | 202 | 74 |

salon_erp y salon_erp_test coinciden exactamente en conteos, hashes de todas
las filas y DDL con la línea base conservada de H13-C. Los conteos de negocio
disponibles del cierre de bootstrap_check también coinciden con H13-C; no
existe un snapshot completo histórico de ese cierre para afirmar igualdad
íntegra retrospectiva de todos sus hashes. Se capturó ahora su snapshot
completo para la futura reanudación. No se enviaron escrituras a ninguna base.
Una segunda lectura al cierre confirmó igualdad exacta de las TRES bases con
ese snapshot de parada; esto no sustituye la línea base anterior a una futura
reconstrucción. SALON_TEST_DB_RESET_ALLOWED sigue en 0; git diff --check correcto.

Evidencias locales ignoradas en storage/app/private: h13d-preflight.json,
h13d-failure.json, h13d-assertion-failure.json, h13d-console.log,
h13d-preparation-stop.json y h13d-preparation-snapshot.json. No existe
h13d-rebuild-started.json ni evidencia de respaldo nuevo.

## Respaldos

El respaldo anterior sigue disponible, sin sobrescribir:
E:\salon-erp-backups\salon_erp_20261113_054136_1807232_2e37f496.sql

177714 bytes. SHA256 nuevamente comprobado:
FF0753F2EBF11A9EE50EEB878F7AB3D2738CDACF532A05FDE0DBC0C987145EB5

El respaldo NUEVO sigue pendiente. El auxiliar preparado reserva un nombre
único H13D fuera del repositorio, usa mysqldump --single-transaction con datos,
estructura, rutinas, eventos y triggers; comprueba exit 0, tamaño, 78 CREATE
TABLE, marcador final, ausencia de cambio de base y SHA256 con sidecar.
No se debe sustituir este requisito por el respaldo anterior.

## Comandos ejecutados

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/development-rebuild.php inspect
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/development-rebuild.php close
```

inspect: código 0. close: down interno código 0, runner código 1 por la
comprobación explicada. También se ejecutaron php -l, git diff --check,
inspecciones de procesos/tareas, SELECT/SHOW, HTTP GET y SHA256 de solo lectura.

## Archivos preparados

- tests/Support/development-rebuild.php: fases explícitas, allowlist,
  validaciones, manifiesto, snapshots, marcador exclusivo de reconstrucción,
  inicialización y cierre. No se reintenta migrate:fresh automáticamente.
- tests/Support/development-rebuild-backup.php: exportación nueva verificada.
- tests/Support/development-rebuild-http.php: canal de loopback con nonce,
  todavía NO iniciado; permitiría comprobar autenticación/CSRF manteniendo
  cerrado el acceso ordinario. Solo exceptúa mantenimiento en ese proceso.
- tests/Support/development-rebuild-functional.php: login real y verificaciones
  de catálogos/agenda, todavía NO ejecutado. Sin operaciones financieras.
- Este informe. Sin cambios en módulos funcionales ni migraciones históricas.

Los cuatro archivos PHP pasan validación de sintaxis. No hay commits.

## Reanudación y recuperación

Se requiere confirmar la reanudación tras este fallo previo, según la condición
expresa de parada de la fase 1. Antes: validar el guardia corregido, reconocer
el mantenimiento ya establecido sin invocar close tal como está (rechaza un
mantenimiento preexistente), revisar de nuevo escritores y capturar líneas base
completas. Crear/verificar respaldo nuevo y solo entonces invocar rebuild.
No retirar marcadores ni reutilizar evidencias de otra ventana para forzar
una ejecución. El modo rebuild permanece sin ejecutar ni validar en desarrollo.

Tras reconstruir se configuraría propietario en WezTerm con entrada oculta;
si falla debe quedar cerrado. Luego demos, login, HTTP y comparación completa
de las otras bases. La verificación preparada dejaría un cliente, un servicio
exclusivo de services y dos citas identificadas H13-D, sin facturación.

No se necesita restaurar ahora: los datos de desarrollo están intactos.
Si se cancela la reconstrucción, se puede retirar el mantenimiento bajo una
decisión explícita y verificar disponibilidad. Para una falla futura tras DDL:
mantener cerrado, conservar evidencia/respaldo y solicitar autorización para
restaurar; nunca importar automáticamente encima del esquema nuevo. El dump
anterior devuelve 73 migraciones y FK a items, por lo que exige código/esquema
compatibles y no equivale a instalar H13. La restauración no ha sido ensayada.
