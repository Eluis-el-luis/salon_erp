# AC-04B2B4A — Interfaz del piloto contable

Fecha: 2026-11-16. Resultado: **completada mediante la alternativa autorizada de consulta y revisión, sin escrituras financieras HTTP**. No constituye habilitación empresarial ni permite ejecutar el flujo financiero completo desde el navegador.

## 1. Decisión de frontera y arquitectura

Los escritores `AccountingBankChargeSimulation` y el reversor AC-04B2B3A exigen CLI, bootstrap controlado, identidad SQL y contratos internos. Un navegador no satisface ese contrato. No se retiraron esas guardas ni se creó un puente HTTP→CLI capaz de confirmar operaciones.

Se implementó una frontera independiente de **lectura**:

- `PilotDemoReadOnly`: proyección de documentos sintéticos propios y revisión pura de entradas; reutiliza normalización, canonicalización y verificación documental existentes.
- `PilotDemoController` y `PilotDemoAccess`: sesión, acceso restringido y errores controlados.
- Una vista Blade independiente que reutiliza los estilos existentes, sin navegación general ni CSS global adicional.
- Router local específico en `tests/Support/pilot-demo-readonly-router.php`. Solo sirve las rutas permitidas de demostración y su CSS; nunca entrega el directorio de trabajo ni devuelve el control al servidor de archivos estáticos.

No hay endpoint de confirmación, continuación de `prepared`, devolución o aprobación empresarial. El botón de confirmación permanece deshabilitado y explica la limitación. Revisar no persiste un documento.

## 2. Aislamiento verificable

El router exige `cli-server`, origen loopback y Host exacto `127.0.0.1:8774`. Carga el bootstrap de test y define un contrato de lectura específico. El servicio exige simultáneamente ese contrato, bootstrap controlado, reset deshabilitado, entorno testing, raíz y configuración SQL autorizadas.

La conexión de proyección tiene DSN fijo `127.0.0.1:3306/salon_erp_test`, usuario `salon_erp_tester`, comprobación de `DATABASE()`, `CURRENT_USER()`, puerto y roles. Se configura como `TRANSACTION READ ONLY` y se abre una transacción de consulta consistente, revertida al destruir el servicio. La conexión Laravel del servidor local también se configura en modo de solo lectura y rechaza sentencias distintas de SELECT/SHOW. No se cambian permisos SQL ni configuración persistente.

El servicio no llama al escritor, al reversor ni al resolutor para contabilización. Cambiar solo APP_ENV no abre la interfaz. Sin el contrato de arranque, las rutas de demostración rechazan el acceso; un destino diferente también se rechaza antes de proyectar documentos.

Esta es una defensa para la aplicación de ensayo, no una protección frente a una persona con capacidad de modificar el código y las credenciales locales.

## 3. Pantallas, rutas y ejecución local

Las rutas usan el prefijo reservado `__pilot-demo`, fuera del flujo bancario ordinario:

| Método y ruta | Uso |
| --- | --- |
| GET `/__pilot-demo/login` | Acceso de ensayo |
| POST `/__pilot-demo/login` | Autenticación, con CSRF y límite de intentos |
| POST `/__pilot-demo/logout` | Cerrar la sesión aislada, con CSRF |
| GET `/__pilot-demo` | Habilitaciones propias, revisión de entradas e historial |
| POST `/__pilot-demo/review` | Normalización, hash y revisión sin persistencia, con CSRF |
| GET `/__pilot-demo/lookup?uuid=...` | Localizar un documento, sujeto a autorización |
| GET `/__pilot-demo/operations/{uuid}` | Resultado histórico autorizado |

Comando local, desde la raíz del proyecto y después del preflight:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 -S 127.0.0.1:8774 -t public tests/Support/pilot-demo-readonly-router.php
```

Abrir `http://127.0.0.1:8774/__pilot-demo/login` con credenciales **de test** de un usuario autorizado. No se crean ni cambian credenciales. Detener ese proceso específico al terminar. El servidor usado por la prueba HTTP terminó y no se dejó un servicio de demostración en ejecución.

Las sesiones y el límite de intentos se guardan en archivos privados exclusivos de la demostración, con cookie propia, HttpOnly, SameSite Strict y ruta limitada al prefijo. No se usan las sesiones del ERP ordinario. Las opciones del servidor se establecen en memoria; no se modifican .env, APP_KEY ni archivos de configuración.

## 4. Permisos y autorización por recurso

El acceso de consulta exige usuario activo con rol `alvaro` o `contador`, revalidado en el servicio. `admin`, recepción y estilista no reciben un bypass. La autenticación deriva la identidad del usuario real y no de IDs enviados en el formulario.

El alcance es conservador: cada usuario solo consulta operaciones de las que es ejecutor y revisa habilitaciones sintéticas propias. No se concede consulta universal por conocer un UUID. Las devoluciones visibles también deben corresponder al actor autorizado del ensayo.

La administración de políticas conserva sus restricciones y servicios existentes. No se añade un permiso de ejecución financiera HTTP. No se almacenan contraseñas, PIN, cookies o tokens en evidencia documental o en el informe.

## 5. Datos y estados

La vista identifica permanentemente **SIMULACIÓN FINANCIERA / INFORMACIÓN SINTÉTICA / SIN APROBACIÓN EMPRESARIAL**. Permite revisar banco NIO, habilitación propia, mantenimiento/comisión, decimal exacto, fecha, referencia, descripción y UUID. No permite elegir cuentas, versión o actor autoritativos.

La revisión muestra hash documental, versión, cuentas fotografiadas y advertencias sobre retirada, vigencia o período no disponible. No crea períodos ni garantiza elegibilidad transaccional futura. La lectura refleja una fotografía consistente de consulta, no una reserva de autorización.

La consulta histórica muestra:

- UUID, hash del contenido, referencia, clasificación, importe y fecha.
- `prepared`: advertencia expresa de que no equivale a éxito financiero; no permite continuarlo.
- `confirmed`: movimiento original, estado y referencia de conciliación, asiento y sus dos detalles, versión/hash, habilitación y correspondencias originales.
- Devolución sintética confirmada, si existe: UUID, movimiento/asiento inversos y enlaces por detalle, conservando visible el cargo original. No hay botón de devolución.
- Autorización retirada: historia consultable, sin presentarla como autoridad vigente.
- Conflicto de UUID/contenido: 409. Ante resultado no disponible se indica consultar el UUID original, sin generar otra operación automáticamente.

El preparado ID 131 se consultó como evidencia inmutable; no fue actualizado, confirmado ni eliminado.

## 6. Seguridad HTTP e identidad

Todos los POST disponibles modifican exclusivamente sesión o realizan revisión sin persistencia SQL, y exigen CSRF. La prueba HTTP real no desactiva middleware: omitir el token devuelve 419 y un token válido permite procesar la autenticación inválida con error controlado.

Los datos se escapan mediante Blade. Los parámetros autoritativos no previstos en la revisión se rechazan. El hash se calcula en servidor con el contrato canónico existente. Mismo UUID/contenido permite consultar el resultado existente; contenido distinto se rechaza. No se afirma idempotencia de un escritor HTTP inexistente.

Los errores de conexión/documento devuelven un estado controlado de indisponibilidad, sin texto SQL. Las rutas `/confirm` y `/refund` no existen. El router rechaza rutas ordinarias como `/bancos` y accesos al workspace como `/.env`.

## 7. Pruebas finales y build

Ejecutadas sobre la versión final, sin RefreshDatabase, migrate:fresh, migraciones nuevas, Composer test ni suite general:

| Grupo del runner seguro | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| `demo-core` | 14 | 87 | PASS |
| `demo-http` | 2 | 18 | PASS |
| `demo-regressions` | 171 | 1.381 | PASS |
| **Total** | **187** | **1.486** | **PASS** |

La cobertura nueva comprueba renderizado de prepared/confirmed/devolución, autorización retirada, roles, actor inactivo, sesión, autenticación, CSRF, documentos ajenos, entrada inválida, precisión, clasificación, IDs manipulados, conflictos de UUID, destino incorrecto y rechazo real MySQL 1792 a DML sobre la conexión de lectura.

El servidor HTTP real se inició como proceso hijo loopback; se comprobaron login, cookie aislada, CSRF, sesión requerida, CSS y rutas bloqueadas, y su finalización limpia. No se realizó una comprobación visual interactiva en navegador autenticado; la sesión autenticada y el contenido se verificaron mediante pruebas del kernel HTTP de Laravel.

Las regresiones comprenden AC-02, AC-03A/B, AC-04B0, AC-04B1, AC-04B2A, AC-04B2B1, conciliación AC-04B2B2R y devolución AC-04B2B3A, incluidas sus guardas de rollback con evidencia. Se ejecutó la prueba existente de jerarquía con procesos/conexiones independientes y limpieza de sus cuentas temporales. No se repitieron carreras financieras sobre ventanas ocupadas. No se reivindican carreras de un escritor HTTP: no se creó tal escritor.

`npm.cmd run build`: PASS, Vite 8.1.5. Aviso no bloqueante existente sobre la dependencia opcional fontaine; no se instaló. PHP lint en los archivos nuevos: PASS. `git diff --check`: PASS. No hay nuevo JavaScript operativo; únicamente enfoque del resumen de errores de validación.

## 8. Respaldo, guardias e integridad

Antes de las pruebas se generó un respaldo nuevo, sin sobrescribir anteriores:

```text
E:/salon-erp-backups/salon_erp_test_AC04B2B4A_20261116_221928_5ecec45fa514.sql
Tamaño: 1.438.553 bytes
SHA256: 3c37ad771b0b5010b39835b269b5d97536f3fe9b4900626a74dc875f8b731a8b
89 tablas / 82 migraciones / 1.739 filas
```

Exportación completada y validada por el helper seguro; tamaño, SHA256 y sidecar comprobados nuevamente por los preflights. `source_unchanged=true`. No se restauró el dump para ensayar recuperación (`restore_tested=false`); no se afirma una restauración comprobada. Se conservan todos los respaldos previos.

PHP 8.3.30; MySQL 8.4.3; identidad efectiva `salon_erp_tester@localhost`, puerto 3306, roles NONE, privilegios exactos y acceso denegado a las otras bases. Los helpers administrativos de respaldo/comparación preexistentes leen las bases para verificar aislamiento; la aplicación y las pruebas usan exclusivamente el tester y el esquema de test.

La comparación final con la nueva línea base dio:

| Base | Migraciones | Tablas | Filas | Resultado |
| --- | ---: | ---: | ---: | --- |
| salon_erp | 74 | 78 | 225 | Contenido y estructura intactos |
| salon_erp_test | 82 | 89 | 1.739 | Todas las filas previas y estructura conservadas; ninguna operación nueva persistida |
| salon_erp_bootstrap_check | 74 | 78 | 202 | Contenido y estructura intactos |

La comparación estructural excluye el contador AUTO_INCREMENT, que puede avanzar por fixtures temporales o transacciones revertidas. No se modifican datos históricos para compensarlo. Se preservan políticas, autorizaciones, extractos, cargos y devoluciones previas. La prueba de rechazo DML no cambia el saldo.

`SALON_TEST_DB_RESET_ALLOWED=0` se mantuvo y el runner lo restableció mediante finally. No se ejecutó DDL. Las opciones de conexión, bootstrap y credenciales originales no fueron editadas.

## 9. Archivos y diff

Nuevos:

- `app/Services/PilotDemoReadOnly.php`
- `app/Http/Controllers/PilotDemoController.php`
- `app/Http/Middleware/PilotDemoAccess.php`
- `resources/views/accounting/pilot-demo.blade.php`
- `tests/Support/pilot-demo-readonly-router.php`
- `tests/Support/PilotDemoHttpCsrf.php`
- `tests/Feature/PilotDemoReadOnlyTest.php`
- `tests/Feature/PilotDemoHttpBoundaryTest.php`
- Este informe y `docs/AC_04B2B4A_DIFF.patch`.

Modificados: `routes/web.php` (grupo guardado de demostración), `tests/Support/run-ac04b1-tests.php` (grupos focalizados), `tests/Support/ac04b1-backup.php` (fase y 82 migraciones), `tests/Support/ac04b1-isolation.php` (comparación estricta sin altas persistentes).

No se modificaron los escritores, el reversor, BankBalanceService, AccountIntegrityService, ContabilidadService, resolveForPosting, assertBusinessAuthority, las rutas bancarias originales, migraciones, CSS global ni UI-01. No hay commits automáticos.

El parche específico incluye los doce archivos de aplicación/pruebas, con los archivos nuevos; excluye este informe y el propio parche. Se obtuvo contra la línea base de fuente previa a la fase y puede revisarse sin mezclar fases anteriores.

## 10. Reversión y límites pendientes

Para retirar esta demostración: detener su servidor específico, revisar el parche y ejecutar primero `git apply --reverse --check docs/AC_04B2B4A_DIFF.patch`. Aplicar la reversión solo si sigue correspondiendo al estado de trabajo revisado, preservando cambios posteriores. El informe y el parche pueden conservarse como documentación. No requiere rollback SQL ni restaurar el respaldo. No borrar evidencia histórica o credenciales para retirar la interfaz.

La confirmación financiera navegable sigue pendiente de un contrato independiente autorizado y verificable. No basta con conectar este controlador al método ForSimulation ni con cambiar un propósito o APP_ENV. Una futura fase deberá revisar identidad de ejecución, aislamiento HTTP, recuperación de respuestas inciertas, autorización por recurso y carreras reales de UUID, retirada y saldo, sin debilitar CLI ni habilitar autoridad empresarial implícita.

La vigencia sintética futura puede producir advertencias durante la revisión actual; no se cambian fechas o autorizaciones para hacer aparecer una operación como habilitada. La interfaz no concede permisos económicos ni crea configuraciones empresariales.

Se detiene AC-04B2B4A en esta alternativa autorizada. No se continúa con activación empresarial, reversión operativa, AC-05/06, H01-B2B o despliegues.
