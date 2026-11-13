# H14-A — Navegación y confiabilidad de agenda

## Alcance

La agenda mostraba solo hoy aunque el alta admitía otras fechas. La cita 6
del diagnóstico se había guardado para el día siguiente. No se atribuye el
problema a una restricción por estilista: no existe esa regla actualmente.

Esta entrega no modifica disponibilidad, estados, duración, catálogo,
facturación, claves foráneas ni migraciones. H13 y H15 siguen pendientes.

## Cambios

- `/agenda?fecha=AAAA-MM-DD` y `/api/appointments?fecha=AAAA-MM-DD` validan la
  fecha; sin parámetro utilizan hoy en `config('app.timezone')` (Managua).
  Una fecha vacía es inválida: no equivale a omitir el parámetro. Se mantiene
  el formato de respuesta del listado API y el grupo web/auth. Fechas inválidas
  devuelven 422 HTML en /agenda y 422 con errors.fecha JSON en el API.
- Selector de fecha, encabezado con día explícito, acceso a Hoy y mensaje de
  día vacío. Se conservan parámetros escalares compatibles al consultar y al
  seguir el enlace de confirmación; se descarta paginación anterior.
- Tras un alta, se lee el ID y DATETIME que devuelve la cita recién
  persistida. Se muestra una confirmación permanente y un
  enlace al día guardado. No hay recarga automática que oculte una cita futura.
  La tabla se actualiza al consultar ese enlace o recargar su día.
- Las fechas locales se mantienen como cadenas; JavaScript no usa Date,
  toISOString ni conversiones UTC para elegir el día de la cita.
- El formulario se reinicia al guardar y al iniciar otra alta. Se bloquea
  Guardar durante la solicitud para evitar duplicación por doble clic.
- Mensajes distintos para 422/401/403/419/500 y fallo de red. En validación
  se muestran etiquetas conocidas de campos, no mensajes técnicos del servidor.
  Una respuesta ilegible o pérdida de red no se interpreta como prueba de que
  la cita no existe: se pide consultar la agenda antes de repetir.
- `DB::transaction()` engloba inserción de cita, sincronización de estilistas
  y lectura de los datos persistidos. Un fallo revierte ambas escrituras.

## Pruebas inspeccionadas

`tests/Feature/AgendaNavigationTest.php` contiene cuatro casos locales:

1. Dos citas del mismo estilista, confirmación de ID y DATETIME normalizado.
2. Navegación a pasado/futuro y hoy según Managua cerca de medianoche UTC.
3. Fechas inexistentes, vacías, de formato incorrecto y parámetros array.
4. Fallo inyectado durante INSERT de la tabla pivote: cita y pivote revierten.

Los fixtures declaran IDs coincidentes en items/services para mantener H13
fuera de alcance. La inyección usa un listener SQL solo dentro de su test;
no registra hooks en producción. RoleSeeder y migraciones fueron inspeccionados
para descartar conexiones alternativas y efectos externos.

`tests/Frontend/agenda.test.cjs` ejecuta el componente JavaScript real de la
vista con dobles DOM/HTTP, sin Laravel, red ni SQL. Sus nueve casos cubren
confirmación, enlace, fecha local guardada diferente de la enviada, reset,
errores seguros, red incierta, respuesta ilegible y doble clic. No es una
prueba de navegador ni demuestra aspectos visuales/responsive.

Se incluyen únicamente los ocho casos de ApiAgendaSecurityTest como regresión
de sesión, autorización, CSRF efectivo y limitación conocida H13.

## Ejecución segura

Se comprobó el respaldo externo
`E:\salon-erp-backups\salon_erp_test_20261112_074028_3813668.sql`:
SHA256 `8778320250E84322267A7D99BF86E18F70C099D0117EA5B73DAD67C2C32AA531`.

PDO confirmó salon_erp_test, salon_erp_tester@localhost, puerto 3306 y rol NONE.
SHOW GRANTS muestra permisos solo sobre el esquema exacto de pruebas más USAGE
global (sin acceso a datos). La lectura de salon_erp.users fue denegada con
1142. No había configuración/rutas cacheadas ni variables heredadas de destino.

Se capturó una línea base independiente de conteos y hashes de todas las tablas
de ambas bases, en el archivo ignorado isolation-H14A-before.json. Desarrollo:
78 tablas, 642 registros y 73 migraciones. Pruebas: 78 tablas, 106 registros y
73 migraciones. Estos valores corresponden al inicio de H14-A, no a fases anteriores.

Comandos específicos:

```powershell
node --test tests/Frontend/agenda.test.cjs
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --configuration phpunit.xml --do-not-cache-result --no-progress tests/Feature/AgendaNavigationTest.php tests/Feature/ApiAgendaSecurityTest.php
npm.cmd run build
```

El comando PHPUnit se ejecuta con indicador temporal en 1 dentro de try/finally,
restaurando exactamente el archivo local original con indicador 0. Su salida se
guarda en storage/app/private/h14a-phpunit.log (ignorado por Git). No se ejecuta
suite completa, paralelismo, Composer test ni migraciones administrativas.

Las dos primeras ejecuciones detectaron problemas en el caso de fecha inválida
(11 casos aprobados en cada una). Primero, la política existente de errores
devuelve HTML en /agenda y la validación provocaba redirección; una aserción
fallida quedó además enmascarada por un error del auxiliar de Laravel al
inspeccionar esa respuesta. Se corrigió el rechazo HTML explícito con 422 y
la expectativa del test, sin tocar framework ni bootstrap global.
Después se detectó que query(fecha, default) sustituía el null producido por
una cadena vacía. Se pasó a validar el conjunto de parámetros antes de aplicar
el default solo cuando falta fecha. Los logs de esas ejecuciones se conservan
ignorados; la ejecución definitiva utiliza h14a-phpunit-verified.log.
En ambas se restauró el indicador mediante finally y la comparación de todas
las tablas de desarrollo resultó idéntica a la línea base.

## Revisión manual pendiente

Con una sesión legítima, consultar Hoy, un día anterior y otro posterior;
comprobar que título, selector y tabla coinciden. Tras un alta autorizada,
comprobar confirmación/enlace y que Nueva Cita no conserva datos previos.
Comprobar modal en móvil/escritorio. No repetir una solicitud de resultado
incierto sin verificar el registro. Un entorno HTTP no queda aislado solo por
la presencia de .env.testing: debe comprobarse su destino antes de altas de prueba.

## Diff y reversión

```powershell
git diff -- app/Http/Controllers/CitaController.php routes/web.php resources/views/appointments/index.blade.php tests/Support/isolation-snapshot.php
```

Revisar completos los archivos nuevos de pruebas e informe. Para revertir,
aplicar exclusivamente la inversa del diff H14-A y retirar sus archivos nuevos;
conservar las protecciones H12 y el aislamiento previo. No restaurar SQL:
no hay transformación de datos de desarrollo ni migración que revertir.
Volver atrás también retiraría la atomicidad y la navegación por fechas.

## Resultado final verificado

- PHPUnit: **12/12 aprobadas, 127 aserciones**, código 0, 289922 ms, PHP 8.3.30.
  Cuatro casos H14-A y ocho regresiones H12; sin suite completa ni paralelismo.
- JavaScript: **9/9 aprobadas**, con Node y dobles locales sin red/SQL.
- Build Vite: correcto con npm.cmd; aviso opcional fontaine, sin instalarlo.
- Sintaxis PHP y git diff --check: correctos.
- Desarrollo tras la última ejecución: 78 tablas, 642 registros, 73 migraciones;
  conteos y hashes de contenido y esquema de TODAS las tablas iguales a la línea base.
- Pruebas tras RefreshDatabase: 78 tablas, 106 registros, 73 migraciones;
  cero citas y cero usuarios fixture h14@example.test persistentes.
- PDO posterior: salon_erp_test / salon_erp_tester@localhost / puerto 3306;
  acceso a salon_erp.users rechazado nuevamente con 1142.
- Indicador restaurado a **SALON_TEST_DB_RESET_ALLOWED=0** mediante finally
  en las tres ejecuciones. No se modificaron cuentas/permisos SQL ni .env ordinario.

Archivos de esta entrega: CitaController.php, routes/web.php,
resources/views/appointments/index.blade.php, AgendaNavigationTest.php,
tests/Frontend/agenda.test.cjs, tests/Support/isolation-snapshot.php (solo añade
el identificador H14A) y este documento. No se modificaron modelos, migraciones,
AGENTS.md, configuración general ni módulos financieros/inventario. Sin commits.

H12 conserva sesión, roles y CSRF efectivo (el test desactiva únicamente el
bypass del middleware para el ejecutor de pruebas). No se amplían endpoints ni
permisos de clientes. Las pruebas de componente no sustituyen la revisión
manual de navegador, pendiente. La validación/FK items vs services sigue siendo
la limitación H13; tampoco hay validación de solapamientos ni edición H15.
