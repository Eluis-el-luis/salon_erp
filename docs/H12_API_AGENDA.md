# H12 — Protección de clientes y agenda

## Problema y alcance

routes/api.php registraba recursos sin auth ni sesión web. Un solicitante
anónimo podía alcanzar ClienteController (incluido su perfil JSON y sus
mutaciones) y CitaController. ArticuloController es un scaffold vacío: sus
rutas también se protegieron, sin implementar funcionalidades nuevas.

La agenda consume POST /api/appointments y PUT /api/appointments/{id}.
La búsqueda en app/routes/resources/tests no encontró consumidores internos
de /api/clients ni /api/items. Se conservaron todas esas URL y los nombres
de resource por compatibilidad. No se presupone ausencia de consumidores externos.

## Corrección

- Registro de los tres resources bajo prefix('api') dentro del grupo auth de
  routes/web.php; reciben cookies, sesión y PreventRequestForgery del grupo web.
- Se retiran sus registros públicos en api.php, sin duplicar rutas.
- Se mantiene la autorización existente: clientes/agenda son operaciones
  generales de todos los roles autenticados. No se inventaron restricciones
  administrativas ni se cambió RoleMiddleware (bypass de alvaro, no admin).
- Los dos fetch de agenda envían credentials: same-origin y el token del meta
  csrf-token, manteniendo sus URL y payload.
- CitaController::update recibe Cita $appointment, coincidente con {appointment}.
  El ID inexistente produce 404. Se validan los estados ya admitidos en el enum;
  omitir status conserva el default previo completada.
- No se modificaron login, Sanctum, ClienteController, modelos ni migraciones.

## Regresión inspeccionada

tests/Feature/ApiAgendaSecurityTest.php contiene ocho casos limitados a H12.
Usa fixtures SQL locales, RefreshDatabase y transacciones; no contiene llamadas
de red, correo, exportaciones, respaldos ni conexiones alternativas. Se revisó
el setup y los seeders ya inspeccionados en 0.6.

Se utiliza el middleware real PreventRequestForgery con una subclase solo de
pruebas que desactiva exclusivamente runningUnitTests(). Se comprueban 419 para
token ausente/incorrecto y éxito con el token de sesión correcto. No se usa
postJson como única prueba de CSRF ni se cambia APP_ENV para eludir el guardia.
Laravel 13 permite Sec-Fetch-Site: same-origin en su middleware por diseño;
estos casos omiten ese header y ejercitan la validación del token. El frontend
envía token explícito. No se ha realizado una prueba automatizada de navegador.

Cobertura: lecturas y mutaciones anónimas denegadas, sesión sin actingAs,
cinco roles existentes, límites administrativos, creación/sync de estilistas,
actualización y 404 de cita, estados inválidos, CSRF y compatibilidad de URLs
de clientes. Se conserva el bloqueo de borrar clientes con citas.

## Incompatibilidad de catálogo pendiente (no corregida)

appointments.service_id y la validación de alta apuntan a items; Cita::servicio
y el selector de agenda apuntan a services. Cambiar solo la validación no
resolvería la FK y puede romper datos existentes. Por instrucción del usuario,
no se modificó ninguna relación ni migración.

Los fixtures de éxito declaran explícitamente IDs coincidentes en ambos
catálogos. Un caso separado demuestra que un ID exclusivo de services continúa
rechazándose con 422. El éxito de H12 no prueba que cualquier servicio actual
sea agendable: este defecto previo requiere diagnóstico y autorización aparte.

## Ejecución y reversión

Comando exclusivo (indicador temporal habilitado y restaurado mediante finally):

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --configuration phpunit.xml --do-not-cache-result --no-progress tests/Feature/ApiAgendaSecurityTest.php
```

No ejecutar la suite completa. Antes: respaldo/SHA256, cuenta restringida,
identidad/puerto/base, denegación de desarrollo, cachés, variables y baseline.
El auxiliar isolation-snapshot.php admite la fase H12 para comparar desarrollo
completo antes/después y resumir el estado reconstruido de pruebas.

La ejecución inicial usó `--debug`, pero se perdió su salida final de la sesión
de terminal. No se atribuye un resultado a esa ejecución. Se repitieron solo
los mismos ocho casos con `--no-progress`, conservando stdout/stderr en
`storage/app/private/h12-phpunit.log` (ignorado por Git). En ambas ejecuciones
el indicador temporal se restaura desde el contenido original mediante finally.

Respaldo verificado antes de ejecutar:
`E:\salon-erp-backups\salon_erp_test_20261112_074028_3813668.sql`.
SHA256: `8778320250E84322267A7D99BF86E18F70C099D0117EA5B73DAD67C2C32AA531`.
La línea base de desarrollo contiene 78 tablas, 633 registros y 73 migraciones.
La línea base de pruebas contiene 78 tablas, 106 registros y 73 migraciones.

## Resultado verificado — implementación H12

La repetición registrada terminó con código 0: **8 pruebas aprobadas, 62
aserciones**, 428688 ms. Se ejecutó exclusivamente ApiAgendaSecurityTest con
PHP 8.3.30, sin paralelismo ni suite completa. No fue necesario corregir las
pruebas ni ampliar el alcance después de ejecutarlas.

- Anónimos: lecturas y mutaciones rechazadas con 401 cuando el token es válido;
  el rechazo no depende exclusivamente de CSRF.
- Autenticados: sesión web, cinco roles, creación de cita/estilistas, actualización
  del modelo correcto y edición de clientes por ambas URL aprobados.
- CSRF efectivo: 419 ante tokens ausentes/incorrectos, sin mutación; éxito al
  enviar el token válido. Se comprobaron también 403, 404 y 422 de regresión.
- La agenda renderiza y sus operaciones HTTP pasan con el fixture compatible.
  No se afirma cobertura de navegador ni resolución del problema items/services.
- `npm.cmd run build` terminó correctamente. El intento con `npm run build`
  encontró la política local que bloquea npm.ps1; no se cambió esa política.
  Vite emitió un aviso opcional de fontaine; no se instalaron dependencias.
- `git diff --check` y las comprobaciones de sintaxis PHP pasaron.

Comprobación posterior independiente, de solo lectura:

| Base | Tablas | Registros | Migraciones | Comparación |
| --- | ---: | ---: | ---: | --- |
| salon_erp | 78 | 633 | 73 | Conteos y hashes del contenido y esquema de TODAS sus tablas idénticos a la línea base |
| salon_erp_test | 78 | 106 | 73 | Recreada exclusivamente por RefreshDatabase; fixtures revertidos |

PDO confirmó `salon_erp_tester@localhost`, base `salon_erp_test`, puerto 3306,
rol `NONE` y privilegios únicamente sobre el esquema de pruebas (además de
USAGE global, que no concede acceso a datos). La lectura de salon_erp.users
fue rechazada nuevamente por MySQL con 1142. No quedan clientes de los fixtures
H12. No hubo cambios de permisos ni cuentas SQL.

El archivo `.env.testing` volvió a **SALON_TEST_DB_RESET_ALLOWED=0** mediante
finally. Ese archivo, el log y la línea base están ignorados por Git. No se
modificó `.env`, AGENTS.md, modelos ni migraciones; no se realizó ningún commit.

Archivos de esta entrega: routes/api.php, routes/web.php,
app/Http/Controllers/CitaController.php,
resources/views/appointments/index.blade.php,
tests/Feature/ApiAgendaSecurityTest.php,
tests/Support/isolation-snapshot.php (solo admite la fase H12) y este informe.
Los demás cambios de aislamiento del árbol de trabajo proceden de fases previas.

Para revisar el diff funcional sin mezclar el aislamiento anterior:

```powershell
git diff -- routes/api.php routes/web.php app/Http/Controllers/CitaController.php resources/views/appointments/index.blade.php
```

Los archivos nuevos de prueba y documentación se revisan completos, pues aún
no están indexados. Próximo paso: revisión conjunta del diff y comprobación
manual de agenda con una sesión legítima; el catálogo divergente requiere una
decisión posterior separada. No se continuó con otros hallazgos.

Revertir solo los cambios H12 de routes/api.php, routes/web.php,
CitaController.php y appointments/index.blade.php; retirar las pruebas/informe
de H12 y el identificador H12 del auxiliar si se desea. No usar git reset global:
hay cambios previos de aislamiento no relacionados. No hay migración ni datos
históricos que revertir. Reabrir rutas públicas reintroduce la vulnerabilidad:
ante una regresión, restringir su acceso mientras se prepara una corrección.
