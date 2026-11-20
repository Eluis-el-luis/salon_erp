# AC-05B3A — Demostración visual de reportes, solo lectura

Fecha de cierre: 20 de noviembre de 2026. Estado: completado como demostración local sintética. No sustituye resultados, balance o dashboard ordinarios; no habilita políticas empresariales, persistencia de clasificación o nuevos escritores.

## 1. Arquitectura adoptada

Servidor independiente: `http://127.0.0.1:8778/__report-demo`. El router está en `tests/Support/report-demo-router.php`; registra sus cinco rutas únicamente dentro del proceso de ensayo, después del bootstrap seguro. No se modificaron routes/web.php, bootstrap/app.php, navegación o permisos ordinarios.

Se inspeccionaron SyntheticAccountingPresentation, AccountingReportExtractor, AccountingPresentationComparison, las consultas reales de resultados/balance y la infraestructura AC-04. Se reutilizó el patrón de bootstrap/identidad restringida y el middleware de CSRF real de pruebas, **no la autoridad bancaria, sus sesiones o sus routers escritores8775/8776**. Tampoco se añadió una ruta al reversor.

Componentes nuevos:

- `ReportDemoBoundary`: instalación y actor permitidos, sin bypass de admin.
- `ReportPresentationDemo`: entrada cerrada, elección de fixture o lectura test, rechazo de discrepancia no explicada.
- `ReportPresentationDemoController`: sesión, formularios, consulta y errores controlados.
- `accounting/report-demo.blade.php`: presentación de procedencia, A/B/C/D, cuentas y detalles.
- `ReportPresentationFixtures`: datasets inmutables en código de ensayo, ajenos al catálogo MySQL.

## 2. Frontera y permisos

Se exige simultáneamente SAPI cli-server, loopback real127.0.0.1, Host exacto127.0.0.1:8778, SERVER_PORT8778, contrato interno `AC05B3A_REPORT_READONLY_V1`, bootstrap de pruebas, testing, raíz verificada y reset deshabilitado. Un parámetro HTTP o APP_ENV no define ese contrato.

Solo existe conexión mysql de test. Se verifica base `salon_erp_test`, identidad `salon_erp_tester@localhost`, puerto3306 y roles SQL NONE. Antes de atender la página se comprueba que las lecturas de desarrollo/bootstrap siguen denegadas con1142. No hay fallback a root, otra base o un PDO alternativo.

Se usa sesión de archivos separada:

| Propiedad | Valor |
| --- | --- |
| Cookie | `report_demo_readonly_session` |
| Path | `/__report-demo` |
| HttpOnly | Sí |
| SameSite | Strict |
| Directorio privado | `storage/app/private/report-demo/sessions` |
| Cache/throttle | Archivos privados separados de las otras demos |

Login verifica password del usuario existente; solo alvaro/contador activos pueden entrar. Página autenticada, controlador y servicio vuelven a comprobar la instalación/actor; la identidad se consulta desde servidor. `allowsActor` es un predicado de rol, no una concesión de autoridad HTTP. No hay privilegios nuevos para admin/cajera/recepción. Logout utiliza logoutCurrentDevice para no actualizar remember_token ni credenciales, e invalida solo esta sesión.

No se crearon cuentas de acceso, contraseñas o aprobaciones. La prueba HTTP y el navegador utilizaron la identidad sintética preexistente de AC-04 exclusivamente para autenticarse; no modificaron su política, habilitación o documentos. Esta reutilización de identidad no mezcla cookies ni autoridad de contabilización.

## 3. Rutas y seguridad HTTP

| Método/ruta | Uso |
| --- | --- |
| GET `/__report-demo/login` | Acceso de test |
| POST `/__report-demo/login` | Autenticación con CSRF real; throttle10/min |
| GET `/__report-demo` | Formulario y mensaje permanente |
| POST `/__report-demo/compare` | Lectura/comparación con CSRF real; throttle30/min |
| POST `/__report-demo/logout` | Cierre de sesión con CSRF |
| GET `/__report-demo/assets/app.css` | Único asset, resuelto mediante manifest y realpath dentro de public/build |

El router nunca devuelve false ni sirve el repositorio como archivos estáticos. Rechaza rutas bancarias, __pilot-demo, .env, AGENTS.md, PHP internos y cualquier otra ruta/método. Puerto8779 con Host falsificado8778 no supera SERVER_PORT; localhost tampoco equivale al Host admitido. No se utilizan UUID o IDs como credenciales de consulta.

CSRF no se desactiva por testing: se reutiliza `PilotDemoHttpCsrf` que obliga a su validación real. La cookie es independiente, la sesión se regenera al autenticar y no se recuerda al usuario. CSP limita contenido a esta instalación, sin scripts de página; no-store, nosniff y no-referrer protegen las respuestas. Los mensajes HTTP no contienen SQL, trazas o secretos. Los diagnósticos internos registran únicamente clase/archivo/línea, no contenido de excepción.

## 4. Procedencia: offline frente a MySQL

La banda permanente dice:

**SIMULACIÓN DE REPORTES — CLASIFICACIÓN HIPOTÉTICA — NO APROBADA PARA USO EMPRESARIAL.**

**Offline:** los IDs101–110 y documentos del ejemplo son ficticios y no identifican registros del ERP. Sus nombres, cantidades y grupos están escritos en `ReportPresentationFixtures`, no se generaron desde nombres/saldos empresariales. La pantalla declara «OFFLINE — fixtures de código, NO extraídos de MySQL» y snapshot no aplicable. Su comparación usa el comparador puro AC-05B2, no simula haber ejecutado el extractor SQL.

**MySQL test:** se invoca `AccountingReportExtractor::compare` y se muestra su procedencia real y snapshot propietario. Se utiliza una clasificación deliberadamente vacía, versionada como `AC05B3A-MYSQL-UNCLASSIFIED-1`: no se inventa la correspondencia económica de cuentas históricas para obtener un estado sintético positivo. Si hay movimientos relevantes, se muestra el rechazo por cobertura con sus IDs. Se conservan las etiquetas actuales y referencias históricas del extractor, sin atribuirles una nueva política.

Así pueden demostrarse estados sintéticos completos con fixtures y consultas heredadas reales de test sin confundirlos ni crear nuevos documentos financieros. No se afirma disponer de una clasificación empresarial completa de MySQL.

## 5. Contrato de clasificación y filtros

Las clasificaciones offline son purpose simulation, contrato synthetic-presentation-v1 y versiones `AC05B3A-OFFLINE-overview-1`, `...closing-1` e `...incomplete-1`. El hash lo calcula el clasificador existente. No se recibe una asociación cuenta/grupo, política, autorizador o ejecutor desde el navegador.

La entrada solo permite source, case, report, month, until, states, null_origin y period, con enumeraciones/formatos validados. Fechas offline: octubre2030, sin reinterpretar los detalles para otro mes. Resultados MySQL requiere un mes completo y fin de mes coherente. Balance es acumulado hasta corte y no admite período. El filtro por período1 es únicamente del fixture offline. No hay scopes de cuentas arbitrarios enviados por el operador.

Se rechazan parámetros adicionales, asociaciones manipuladas, arrays donde se exige escalar, fechas incongruentes y combinaciones de filtros no admitidas. El formulario conserva la selección utilizada; en errores de tipo no intenta renderizar arrays como HTML ni darles significado contable.

## 6. Presentación A/B/C/D

| Sección | Significado visible |
| --- | --- |
| A | Totales heredados globales realmente devueltos por MySQL. En offline dice expresamente que no están disponibles. |
| B | Totales del contrato legado dentro del universo comparable; una representación de fixture no se rotula como reporte MySQL real. |
| C | Clasificación sintética explícita por ID, cuando tiene cobertura. |
| D | Deltas por rubro, cuenta y detalle, sin confundir un subconjunto con reporte global. |

Se muestran importes con signo y centavos exactos, cuenta ID/código/etiqueta actual, actividad y padre, grupo legado y propuesto, aportes y deltas. Los detalles desplegables conservan debe/haber, origen NULL explícito, estado, fecha/período, asiento/referencia y motivo tipado de inclusión/exclusión/cambio. Cuentas y detalles compensados o cero relevantes permanecen visibles.

Se añadió al resultado del clasificador un mapa paralelo `detail_contributions_cents`, calculado en el mismo punto donde ya determina la contribución. El comparador transmite ese valor por detalle a Blade. **No se recalcula el signo en la vista ni se modifica el detalle original**. Es una extensión aditiva de metadatos lectores, no otro motor financiero.

Etiquetas con marcado, como el nombre ficticio `<b>etiqueta literal</b>`, se escapan y aparecen como texto. Se utilizaron Blade, componentes/clases y CSS compilado existentes; no se modificaron CSS global, public/build, navegación ni UI-01. Tablas acotadas y tarjetas desplegables corresponden solo a esta demo.

## 7. Casos demostrados y cifras verificables

| Caso offline | Evidencia |
| --- | --- |
| Activo, ingreso, gasto y costo alternativos | Cuentas ACT-/INC-/EXP-/CST- con grupos explícitos por ID; diferencia contra las convenciones del legado visible. |
| Correctora de ingreso | Aporte−1.000 centavos, se muestra−10.00. |
| Contraactivo | Aporte−500 centavos, sin convertirlo en positivo. |
| Inactiva con historia | Cuenta107 visible, incluida su actividad actual y detalles. |
| Padre/hijo con movimientos propios | Aportes200/400 centavos, sin sumar otra vez un subtotal. |
| Manual y reversa | Ambos documentos permanecen y se compensan, sin eliminar líneas. |
| Cargo/devolución | Representación contable offline50/50; gasto neto0 por esos documentos. No acredita una devolución real ni ejecuta AC-04. |
| NULL, borrador, anulado y otro período | Todos/NULLinclude: ingreso neto114; confirmado/NULLexclude/período1:90. Motivos y universos distintos, sin forzar igualdad. |
| Cierre corregido | Cierre al debe ingreso100/haber correctora10/patrimonio90. Resultados90; balance con patrimonio90 y resultado pendiente0. |
| Scope parcial/global | Offline muestra subset declarado y A no disponible; no se lo presenta como balance global del salón. |

En el ejemplo general de balance: activo sintético1.070, patrimonio995 y resultado pendiente75; diferencia de balance0. Son cifras del fixture, no saldos empresariales. La contrapartida patrimonial ficticia del dataset no constituye autorización de apertura H01-B2B.

## 8. Snapshot y ausencia de autoridad financiera

El router fija READ ONLY en la sesión SQL y restringe sentencias a SELECT/SHOW y al SET TRANSACTION exacto que necesita el extractor. Esto es defensa adicional: la garantía no se basa solamente en una expresión regular; identidad/privilegios, rutas cerradas y READ ONLY siguen siendo indispensables.

Para MySQL, `ReportPresentationDemo` exige transactionLevel0 antes de invocar el extractor. La comparación usa la vía propietaria READ ONLY/REPEATABLE READ de AC-05B2 y comprueba ese resultado. No admite una transacción externa de características desconocidas. Candidatos, consultas heredadas y validación de volumen usan el mismo snapshot, sin locks financieros exclusivos.

Offline no declara un snapshot SQL de documentos: usa arrays inmutables, aun cuando el login/rol sí se verifiquen contra test. En ambas vías se rechaza una advertencia `unexplained_membership_difference` y se conservan las comprobaciones de identidad exacta de detalles. No se publican métodos de posting, se cambian guardias CLI/HTTP de AC-04 o se habilitan escritores.

## 9. Cobertura incompleta y límites de volumen

Caso incomplete: falta la cuenta ficticia103. El resultado tiene synthetic=null, estado rechazado y diagnóstico explícito de cuenta faltante. La pantalla no presenta subtotales parciales de C como un estado completo. La lectura MySQL sin asignaciones funciona con ese mismo criterio; no usa nombre, código, tipo o primer binding para completar cobertura.

Límites técnicos del prototipo en memoria:

- SQL: máximo1.000 detalles y500 cuentas en las tablas leídas por el adaptador. Se cuentan **dentro del mismo snapshot y antes de materializar candidatos o consultas globales heredadas**. Se limita el universo total porque el controlador legado puede leer cuentas fuera del scope de comparación.
- Offline: máximo120 detalles; el dataset actual contiene30, o6 en el caso de cierre. El navegador no puede ampliar esos arrays.
- Al exceder un límite: rechazo controlado, sin LIMIT silencioso ni un reporte rotulado completo. Los originales no se alteran.

Justificación: se trata de una pantalla de auditoría que conserva simultáneamente fuente, legado, propuesta, deltas y representación Blade; no está diseñada para volúmenes empresariales ilimitados. Se ensayó el rechazo con1.001 detalles nuevos revertibles y se comprobó que no se ejecutaba la consulta materializadora. Cien comparaciones offline no acumularon32MiB de memoria retenida. Esto **no certifica pico máximo, rendimiento de producción o capacidad empresarial**; los límites deberán revisarse por separado antes de otro alcance.

## 10. Pruebas definitivas

| Grupo | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| ReportPresentationDemoTest | 9 | 52 | Aprobado |
| ReportPresentationHttpTest | 1 | 57 | Aprobado |
| AccountingReportExtractionTest | 27 | 143 | Aprobado |
| AccountingPresentationTest + AccountingReportCharacterizationTest | 34 | 123 | Aprobado |
| FiscalClosingSignsTest | 29 | 556 | Aprobado |
| **Total** | **100** | **931** | **Aprobado** |

Se ejecutaron los modos report-demo-core, report-demo-http, extract-core, closing-regressions y closing-core del auxiliar existente. Los resultados intermedios no se suman. La adaptación final de controles/array inválido se verificó de nuevo en las pruebas de demo; las regresiones pasaron con la extensión final del clasificador.

Cobertura: sesión ausente, login autorizado/rechazado, CSRF real ausente/inválido/otra sesión, cookie HttpOnly/Strict/path propio, logout sin cambios de usuario, Host, puerto real incluso falsificando Host, archivos internos y rutas ordinarias denegados, datos inválidos y arrays manipulados, clasificación incompleta, grupos/cuentas/detalles, escape HTML, mes/corte, límites y preservación de originales. El predicado de rol también verifica alvaro/contador, admin/cajera/recepción y contador inactivo; el ensayo HTTP positivo usó contador, sin atribuir una aprobación real a ese usuario.

Las pruebas SQL nuevas usan DatabaseTransactions o lecturas; la prueba HTTP no crea fixtures financieros. La regresión de cierre ejecuta únicamente períodos nuevos revertibles de AC-05C0, nunca desde esta demo. No se ejecutaron RefreshDatabase, seeders, suite general ni carreras persistentes anteriores. No se modificaron tests históricos para ocultar diferencias.

Lint PHP de componentes nuevos y router y node --check del arnés: sin errores. `git diff --check` no informó errores de espacios; permanecen avisos CRLF/LF de auxiliares Windows.

## 11. Revisión visual y capturas

Chrome headless real mediante CDP, **revisión automatizada**, sin inspección manual interactiva. Se examinaron también imágenes generadas para comprobar procedencia, signos, selección del formulario y jerarquía de información. No se ejecutó una confirmación financiera.

Evidencia final: `storage/app/private/ac05b3a-browser-1795156715902/`, con result.json y ocho PNG:

- 01-login.png:1366×900.
- 02-resultados.png:1366×900.
- 03-balance.png:1366×900.
- 03-balance-1280.png:1280×800.
- 03-balance-1920.png:1920×1080.
- 04-cierre.png:1366×900.
- 05-cobertura.png:1366×900.
- 06-error.png:1366×900.

En todos los casos document.scrollWidth coincidió con innerWidth: no se observó desbordamiento horizontal de página en esos anchos. Los detalles utilizan disclosure nativo; no se certificó accesibilidad completa o revisión móvil. Las capturas solo muestran fixtures offline, no saldos empresariales de MySQL.

El primer intento de capturas quedó bloqueado dentro del sandbox antes de Chrome. Se detuvo el arnés propio y, con autorización de ejecución ampliada, se generaron capturas. Una revisión detectó que el formulario volvía a opciones iniciales tras comparar; se corrigió para conservar el universo visible y se repitieron capturas con la versión final. El ajuste posterior para arrays inválidos no modifica el renderizado de esos casos válidos. Se conservaron los resultados anteriores; no se los presenta como evidencia definitiva.

Se usó `ui-ux-pro-max` para consultar legibilidad, contraste y contención de tablas, respetando los estilos existentes. No se generó un sistema de diseño nuevo ni se corrigió UI-01. El manifiesto del navegador informa cero escrituras financieras y procesos detenidos. La comprobación posterior no encontró procesos node/chrome/php del arnés activos.

## 12. Arranque reproducible y cierre

1. Revisar este alcance y ejecutar el preflight seguro:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 tests/Support/run-ac04b1-tests.php report-demo-preflight
```

2. Desde la raíz del proyecto, comprobar que8778 está libre e iniciar en una terminal local controlada:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 -S 127.0.0.1:8778 -t public tests/Support/report-demo-router.php
```

3. Abrir `http://127.0.0.1:8778/__report-demo/login` y usar una cuenta **sintética existente** de test, activa y contable. No usar credenciales empresariales ni crear una autorización. Las credenciales no se publican en este documento.
4. Mantener offline/octubre2030; comparar overview en resultados y balance; después closing e incomplete. Cambiar estados/NULL/período permite observar los universos, sin guardar nada.
5. MySQL es opcional: seleccionar el mes completo o corte y procedencia mysql. A muestra totales reales del test; C puede rechazarse por falta de clasificación. No completar asociaciones desde esa pantalla.
6. Cerrar la sesión propia y detener el servidor con Ctrl+C; comprobar que el proceso y el puerto quedaron cerrados. No iniciar8775/8776 como parte de esta guía.

El arnés `node tests/Support/ac05b3a-browser.mjs` automatiza capturas offline con la identidad sintética previa y cierra Chrome/servidor. Requiere permisos locales para lanzar ambos procesos, Chrome instalado y el puerto libre; no instala dependencias. No incluye una ruta de cargos o devolución.

## 13. Integridad de las tres bases, respaldo y guardias

Línea base: `storage/app/private/ac05b3a-isolation-before.json`. La comparación posterior a pruebas/capturas confirmó hashes de filas, columnas y esquema, con normalización exclusiva del contador AUTO_INCREMENT de test.

| Base | Tablas antes/después | Filas antes/después | Migraciones antes/después |
| --- | ---: | ---: | ---: |
| salon_erp | 78/78 | 225/225 | 74/74 |
| salon_erp_test | 89/89 | 1.873/1.873 | 82/82 |
| salon_erp_bootstrap_check | 78/78 | 202/202 | 74/74 |

`OTHER_DATABASES_EXACT=True`, `OLD_ROWS_PRESERVED=True`. El modo AC05B3A no permite tablas ni filas persistentes adicionales. Los únicos INSERT de volumen/regresión pertenecieron a fixtures revertidos; pueden avanzar secuencias, que no se restauran. ID131, autorizaciones, usuarios/credenciales, saldos, asientos y extractos previos permanecen intactos. Las sesiones/cache/capturas son archivos privados de test, no documentos SQL nuevos.

Respaldo verificado: `E:/salon-erp-backups/salon_erp_test_AC05B1_20261119_225424_43e6c3abf4dc.sql`,1.536.280 bytes, SHA256 `85d77381db1a51387e020482fab4a752c116c5c39616278991f452c56fe430b6`. No se restauró ni se sobrescribió. `SALON_TEST_DB_RESET_ALLOWED=0` comprobado al finalizar. No hubo DDL, cambios de APP_KEY/credenciales/permisos/dependencias ni commits.

## 14. Archivos modificados, diff y reversión

Delta propio de AC-05B3A:

| Archivo | Propósito |
| --- | --- |
| `app/Support/ReportDemoBoundary.php` | Frontera8778 y actor activo contable. |
| `app/Services/ReportPresentationDemo.php` | Entrada cerrada, procedencia y snapshot propietario. |
| `app/Http/Controllers/ReportPresentationDemoController.php` | Sesión/CSRF/formularios/errores. |
| `resources/views/accounting/report-demo.blade.php` | Pantalla Blade de lectura. |
| `tests/Support/report-demo-router.php` | Servidor local exclusivo y conexión READ ONLY. |
| `tests/Support/ReportPresentationFixtures.php` | Fixtures inmutables offline. |
| `tests/Support/ReportDemoHttpClient.php` | Cliente HTTP de pruebas, sin operaciones financieras. |
| `tests/Support/ac05b3a-browser.mjs` | Capturas automatizadas y limpieza de procesos. |
| `tests/Feature/ReportPresentationDemoTest.php` | Contenido, permisos, memoria, tipos y volumen. |
| `tests/Feature/ReportPresentationHttpTest.php` | Sesión/CSRF/fronteras y preservación de datos. |
| `app/Services/AccountingReportExtractor.php` | Límite de volumen del prototipo dentro del snapshot. |
| `app/Services/SyntheticAccountingPresentation.php` | Mapa aditivo de contribución por detalle, sin alterar originales o signos. |
| `app/Services/AccountingPresentationComparison.php` | Transmisión de esas contribuciones, sin otro cálculo de signos. |
| Dos auxiliares run-ac04b1-tests/ac04b1-isolation | Modos report-demo-* y fase AC05B3A. |
| Este documento | Evidencia y límites. |

El árbol ya tenía AC-05B1/B2/C0 sin commit. No atribuir sus modificaciones completas a esta fase: se preservaron controlador de cierre, documentos/pruebas previos y auxiliar de respaldo. Revisar los archivos nuevos completos y los hunks propios de los tres lectores y dos auxiliares; `git diff` solo muestra archivos rastreados, por lo que no sustituye revisar los no rastreados.

Reversión: detener8778; retirar archivos nuevos específicos de demo y deshacer solo límite de volumen, metadatos aditivos y modos/fase AC05B3A de los lectores/auxiliares. Conservar AC-05B1/B2/C0, documentación y evidencia privada. No ejecutar reset general, restaurar bases, borrar documentos o limpiar sesiones de otras demostraciones. No hay migración que revertir ni clasificación SQL que despublicar.

## 15. Límites para una futura clasificación empresarial

Continúan pendientes la aprobación del contador de rubros/signos/correctoras, política de estados y NULL, cortes/períodos, conservación de ediciones emitidas y clasificación persistente versionada. El hash de fixture o su renderizado no aprueban esa política. El modo MySQL incompleto no es un estado financiero empresarial certificado.

No se ha probado rendimiento empresarial, publicación concurrente de clasificación, archivo de edición o nueva seguridad concurrente de escritores. No se corrigieron reportes operativos, libro300, inventario comercial, comisiones o historia negativa; no se reabrieron períodos ni conciliaciones.

AC-05B3A termina aquí. No se inicia clasificación persistente, sustitución de reportes, AC-06, H01-B2B o habilitación empresarial, ni se despliega en desarrollo.
