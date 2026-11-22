# R01-C0 — Contención preventiva de restauraciones HTTP

Fecha: 21 de noviembre de 2026. Implementación focalizada en código, sin despliegue ni peticiones al servidor empresarial. Referencias: [R01](R01_AUDITORIA_RESTAURACIONES.md), [H01-R](H01_R_TRAZABILIDAD_INVENTARIO.md) y [AGENTS.md](../AGENTS.md).

**POST /backups/restaurar queda contenido por configuración cerrada y un cierre adicional de código. Ningún valor de configuración puede reactivar por sí solo el ejecutor histórico en esta entrega.** El exportador no se modificó ni se ejecutó.

## 1. Riesgo mitigado

La ruta permitía a un operador autorizado enviar SQL arbitrario a la conexión predeterminada, sin contrato verificable de procedencia, destino, recuperación parcial o auditoría. R01 identificó además la posibilidad de copiar SQL al log al fallar el ejecutor.

R01-C0 impide que el handler HTTP alcance ese circuito: rechaza antes de validar el upload, acceder a su contenido o resolver DB. No pretende arreglar ni certificar el importador antiguo. Los permisos, autenticación y protección web existentes se conservan.

La contención es prospectiva: no cancela una restauración que ya hubiera comenzado antes de aplicar el código, ni certifica cómo está desplegado el ERP. No se inspeccionaron ni modificaron procesos empresariales.

## 2. Puerta implementada

Archivo nuevo `config/backups.php`:

```php
'http_restore_enabled' => false,
```

No usa `.env`, APP_ENV, reset de tests, inventario V2 o H06. Una configuración cacheada que todavía no incluya la clave también resulta cerrada por el valor por defecto del controlador.

`App\Support\HttpRestoreGate` es una clase final y pura. Exige valor booleano estrictamente `true` **y** `RECOVERY_CONTRACT_IMPLEMENTED`, constante privada que permanece `false`. Valores ausentes, NULL, números, cadenas, arrays, objetos, grupos mal formados o claves desconocidas nunca habilitan. Incluso un `true` accidental continúa rechazándose por el cierre de código.

La constante no es un segundo indicador de configuración ni una aprobación económica. Expresa que no existe un contrato de recuperación implementado/autorizado. Retirar esta barrera exige otra intervención de código revisada; no se proporciona ahora un modo alternativo de restauración.

`RespaldoController::restore` consulta únicamente esa configuración y las cabeceras para elegir formato de respuesta. El cuerpo histórico se conserva en `private restoreLegacy`, sin ruta ni consumidor HTTP alternativo. Se compararon el cuerpo del exportador y el bloque histórico con HEAD, normalizando solo saltos de línea: permanecen iguales. No se ejecutó ninguno.

## 3. Comportamiento de ruta e interfaz

| Entrada | Comportamiento |
| --- | --- |
| GET /backups | Mantiene selección de `backups.index` y roles existentes |
| GET /backups/descargar | Conserva controlador, implementación y enlace de descarga; no se genera un respaldo durante esta fase |
| POST /backups/restaurar que alcanza el controlador | HTTP 403 con mensaje estático de deshabilitación |
| Petición que espera JSON | JSON con `message` y `code=http_restore_disabled`, estado 403 |
| Petición web ordinaria | Texto plano UTF-8 y estado 403; sin redirección ni flash de sesión |

Mensaje: «La restauración SQL desde la aplicación está temporalmente deshabilitada por seguridad. La recuperación requiere un procedimiento autorizado.»

Se conservan `web`, `auth` y `role:admin`; el bypass vigente del propietario no se cambia. Un principal rechazado por middleware puede no alcanzar el handler: no se altera su respuesta de autenticación/autorización. Parámetros, cabeceras de supuesta autorización, archivo o clic del navegador no configuran la puerta.

La pantalla muestra aviso visible y botón nativo deshabilitado, sin modal, selector de archivo, formulario de restauración ni submit operativo. Se retira el estado Alpine que abría el modal. La descarga permanece visible, con texto que exige verificación independiente del destino y de la recuperación; no se anuncia restaurabilidad certificada.

No se rediseña el resto de la pantalla ni se modifican las rutas. La presentación antigua queda en el historial/diff del archivo; el ejecutor PHP histórico permanece además en el código, privado y contenido.

## 4. Evidencia de que el ejecutor no fue invocado

Se invoca el controlador real sobre un Container mínimo, sin Application empresarial, bootstrap, providers, sesiones, credenciales o kernel. El intérprete de pruebas tiene deshabilitadas las clases PDO y mysqli.

Los dobles fallan si se intenta:

- Resolver `db`, `log`, `files`, `auth` o `session` desde el handler.
- Invocar métodos de servicios espiados, incluida cualquier operación SQL.
- Validar o leer los datos de la petición, consultar el archivo o acceder a su ruta/contenido/tamaño/extensión.
- Llamar al lector `file_get_contents` del namespace del controlador. Ese interceptor existe solo en el proceso aislado de pruebas, no en producción.

Los uploads son dobles tipados con nombres `.sql`, `.txt`, `.php` y `.SQL`, sin archivo físico ni contenido leído. No se ejecuta un archivo para validar la puerta. La configuración `true` se ensaya expresamente y también es rechazada. Repetir la petición tres veces produce tres rechazos iguales, sin efectos.

La lista de interacciones peligrosas permanece vacía en los casos de rechazo. No hubo invocaciones a `DB::unprepared`, ni siquiera para probarlo con un SQL sintético. El cuerpo antiguo no se llama por reflexión: solo se comprueba que el método es privado y que no existe ruta hacia él.

**Privacidad:** la rama de rechazo no registra payloads, archivos, contraseñas, identificadores personales, excepciones ni SQL. Tampoco intenta resolver el logger. No se introdujo un evento de auditoría nuevo: no hay un canal específico de rechazo, independiente de SQL y de contexto adicional, verificado en esta fase. Se opta por respuesta estática sin efectos antes que reutilizar el registro de errores del ejecutor antiguo. Una auditoría mínima y segura podrá definirse en el contrato posterior; no se presenta el silencio como registro de autorización.

El alcance de esta garantía es el handler contenido. Middleware de sesión/autenticación y logs de infraestructura siguen sus reglas normales; no se afirma que una petición real completa nunca consulte sesiones en DB o que una infraestructura ajena no registre metadatos. Esta fase no realizó peticiones a esa infraestructura ni cambió sus reglas.

## 5. Pruebas y aserciones

Selección explícita, sin `phpunit.xml`, `tests/bootstrap.php` ni suites generales:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d disable_classes=PDO,mysqli -d memory_limit=128M -d max_execution_time=60 -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result --no-progress tests/Unit/HttpRestoreContainmentTest.php
node --test tests/Frontend/backups-containment.test.cjs
```

Resultado definitivo: **23 pruebas PHP aprobadas / 280 aserciones** y **2 pruebas de frontend aprobadas**. Los cinco PHP nuevos/modificados pasaron lint; `git diff --check` pasó. `npm.cmd run build` terminó correctamente, sin instalar dependencias; conserva la advertencia opcional de `fontaine`. Los artefactos Vite fueron generados por el compilador y no editados manualmente ni desplegados.

| Requisito | Evidencia |
| --- | --- |
| 1–3. Ausente, deshabilitada, inválida | Casos de configuración y comprobación del archivo de configuración real; 403 y cero interacciones |
| 4–5. SQL sintético/otro tipo | Dobles de upload inaccesibles, incluido archivo ausente; no se solicita siquiera validación o extensión |
| 6. Repetición | Tres rechazos sin estado mutable ni efectos |
| 7. Cero SQL | Resolución de DB prohibida y PDO/mysqli deshabilitados; no se llama a unprepared |
| 8. Cero lectura/log de contenido | Tripwires de Request, upload, lector y logger; parámetros sintéticos no aparecen en la respuesta |
| 9. Rechazo claro | 403 JSON y texto UTF-8 con mensaje constante; sin mensaje de éxito o intento de recuperación |
| 10. Interfaz | Comprobación del Blade fuente: aviso, disabled, ausencia de form/upload/submit/modal y enlace de descarga conservado |
| 11. Rutas/permisos | Registro real de `routes/web.php` sobre Router y Container mínimos, sin despachar acciones; comprueba los tres handlers y sus grupos web/auth/role:admin, sin ruta a restoreLegacy |
| 12. Compatibilidad | Index real selecciona la misma vista; comparación estática del exportador/histórico; ningún archivo de otros módulos modificado |

La primera ejecución tuvo errores del fixture: el constructor heredado UploadedFile exigía un archivo físico inexistente antes de invocar el controlador. Se corrigió exclusivamente el doble para no inicializar ni leer archivo nativo; no se relajó la contención o una expectativa. La comparación inicial del código también se repitió con lectura UTF-8 explícita para evitar una falsa diferencia de codificación. Las comprobaciones definitivas pasan.

Son pruebas puras/de controlador con dependencias simuladas y registro de rutas, más comprobaciones de fuente de interfaz. No se afirma renderizado completo de layout, prueba visual en navegador, kernel/middleware ejecutados ni validación SQL. No se ensaya download como regresión positiva porque generaría un respaldo real y excede la autorización.

## 6. Archivos modificados

| Archivo | Cambio |
| --- | --- |
| `app/Support/HttpRestoreGate.php` — nuevo | Puerta pura, mensaje y cierre de código |
| `config/backups.php` — nuevo | Intención de restauración HTTP cerrada por defecto, sin variables de entorno |
| `app/Http/Controllers/RespaldoController.php` | Rechazo temprano y conservación privada del cuerpo histórico; exportador sin cambios |
| `resources/views/backups/index.blade.php` | Aviso de contención y controles deshabilitados; consulta/descarga conservadas |
| `tests/Support/R01RestoreTripwires.php` — nuevo | Dobles que impiden resolución de servicios y lectura del archivo |
| `tests/Unit/HttpRestoreContainmentTest.php` — nuevo | Casos de puerta/controlador/registro de rutas |
| `tests/Frontend/backups-containment.test.cjs` — nuevo | Estado inoperante de restauración y enlace/advertencia de descarga |
| `docs/R01_C0_CONTENCION_RESTAURACIONES.md` — nuevo | Este informe |

El informe R01 ya estaba sin seguimiento al comenzar; se conserva sin modificar. No se cambiaron AGENTS, rutas, middleware, roles, credenciales ni archivos funcionales de inventario, ventas, nómina, contabilidad o H06-P1.

## 7. Compatibilidad con el ERP

La modificación de comportamiento autorizada es el rechazo de la restauración HTTP, también para quien conserve permiso de acceso a la pantalla. No elimina la URL ni añade rutas. No toca la generación/descarga de respaldos ni amplía permisos de ningún principal.

La puerta no depende de APP_ENV/testing, H03, H06, V2, perfiles AC-05 o indicadores de reset. Cambiar esos valores o suministrar parámetros de autorización no puede abrirla. La UI se mantiene cerrada incluso si alguien cambia la configuración a true, coherente con el cierre de código.

No se ejecutan suites de módulos ajenos para declarar una compatibilidad empresarial que no se midió. La compatibilidad se sustenta en alcance del diff, selección de index/registro de rutas, exportador conservado y verificaciones focalizadas. Un despliegue/cache/opcache de un servidor activo no se comprobó ni modificó en esta fase.

## 8. Preservación de bases

No se abrieron conexiones a `salon_erp`, `salon_erp_test` o `salon_erp_bootstrap_check`. No hubo SQL de ningún tipo, respaldos, restauraciones, migraciones, seeders, RefreshDatabase, wipes, reconstrucciones ni cambios de datos empresariales.

Las pruebas no cargan el bootstrap empresarial ni credenciales reales: usan configuración y respuestas sintéticas, con clases PDO/mysqli deshabilitadas. No se modificaron `.env`, configuración sensible, evidencias AC-04/AC-05, documentos históricos, usuarios, infraestructura o Laragon. Compilar Vite no arrancó el kernel Laravel ni un ejecutor SQL.

No se realizó una comparación SQL nueva de contenidos: se preservaron las bases por ausencia de operaciones sobre ellas. No hubo Git de escritura, servicios nuevos ni despliegues.

## 9. Riesgos no resueltos del exportador

Permanecen los riesgos documentados en R01: resolución independiente de conexión mediante env con puerto/URL/socket omitidos, ausencia de certificación de destino, nombres a segundos con posibles colisiones, parciales/custodia/retención no verificados, contenido sensible y falta de prueba de restaurabilidad del dump.

No se genera un respaldo para comprobarlos ni se modifica el exportador para resolverlos dentro de C0. La pantalla no promete que la copia descargada tenga un destino certificado o pueda importarse con seguridad. Un respaldo no equivale a recuperación ensayada.

## 10. Recuperación futura y reversibilidad

La contención es de código/configuración/presentación, sin migración ni datos que revertir. El ejecutor histórico se conserva para análisis y revisión, no como alternativa autorizada de recuperación.

**No existe un procedimiento de habilitación basado solo en cambiar una bandera.** No se debe sustituir false por true en la constante para «probar» la restauración antigua. Una fase posterior requiere autorización independiente, contrato implementado, procedencia verificable, destino/identidad/grants acotados, consentimiento ligado a operación/archivo, privacidad de errores, exclusión de escritores, tratamiento de parcialidad y verificación del resultado.

El propietario podrá revisar un cambio posterior que retire/sustituya la contención al incorporar ese contrato. No se recomienda retirar el guard para reexponer el importador sin esos controles. No hace falta restaurar bases ni ejecutar DDL para revisar o revertir cambios de esta entrega. Cualquier aplicación/reversión en servidor, edición de configuración efectiva o despliegue pertenece al propietario y requiere su autorización, no se ejecutó aquí.

Primero deben ensayarse los mecanismos nuevos con fixtures sintéticos en Debian independiente y con permiso específico para SQL. Este informe no inicia el laboratorio ni modifica las autorizaciones de fases anteriores.

## 11. Riesgos residuales

- El código peligroso histórico permanece, aunque sin ruta ejecutable a través de la puerta actual. Una futura modificación de código podría reexponerlo; requiere revisión del cierre y de todos los consumidores, no una bandera.
- Exportación y copias históricas no quedan certificadas por bloquear restore; sus riesgos continúan explícitos.
- La contención HTTP no limita SQL manual, herramientas privilegiadas externas, acciones ya iniciadas ni configuraciones de infraestructura no inspeccionadas.
- Las rutas siguen autenticación/sesión web normal; esta fase no cambia ni certifica el resto del sistema de permisos o logs de infraestructura.
- Las pruebas son aisladas, no prueban un servidor desplegado, un navegador completo, recuperación MySQL ni integridad de una copia real.

R01-C0 termina aquí con contención implementada y verificada de forma aislada. No se inicia recuperación, infraestructura, despliegue u otra fase.
