# AC-04B2B4C — Auditoría final y demostración del piloto

Fecha: 2026-11-19. Referencia inicial: `ad5fa5e7f2012156bf04a1a6787bf03c4b23114b`, árbol limpio al comenzar.

**Resultado:** cierre técnico recomendado para el piloto local sintético, con una omisión de comprobación del puerto real corregida y un mensaje de resultado incierto aclarado. No se identificó un bypass financiero crítico en el alcance revisado. No es autorización empresarial, certificación de todo el ERP ni despliegue.

No se registraron cargos, asientos, autorizaciones, preparados o devoluciones nuevos. No se repitieron carreras financieras. Las tres bases conservaron exactamente sus filas y estructura, incluidos AUTO_INCREMENT.

## 1. Alcance y método

Revisión de fuente focalizada, trazado de entrada→servicio→SQL/contabilidad, revisiones independientes y regresiones de lectura. Se aplicaron las pautas de `security-audit` y la comprobación visual de `ui-ux-pro-max` sobre la interfaz existente. No se auditó todo el ERP ni se extrapola este resultado a otros módulos o a producción.

Se inspeccionaron los componentes solicitados, sus caminos de ejecución y los colaboradores decisivos:

| Componente | Resultado comprobado |
| --- | --- |
| AccountingSimulationBoundary | Contrato/SAPI CLI, bootstrap, reset, raíz, configuración, identidad SQL y actor. Sin cambios. |
| AccountingHttpSimulationBoundary | Frontera HTTP independiente, ahora con puerto real, además de Host, sesión/CSRF, SQL y actor. |
| AccountingSimulationAccess | Solo despacha los dos contratos conocidos; un contrato desconocido falla. |
| AccountingSimulationPosting | Constructor privado, propiedades readonly y contrato privado; vuelve a validar al contabilizar. |
| AccountingBankChargeSimulation | Entradas CLI/HTTP separadas y procedimiento transaccional común; no otro motor. |
| AccountingEventAuthorizationService | Evidencia/hash, padre, ámbito, propósito, vigencia, retirada y locks; autoridad empresarial cerrada. |
| AccountingAccountResolver | Cobertura exacta, fotografías, cuentas y versión fijada; resolveForPosting cerrado. |
| AccountingEventIdentityService | UUID/hash canónico, actor y contenido originales; prepared no equivale a confirmed. |
| PilotDemoReadOnly | PDO propio READ ONLY y consultas preparadas; acceso por actor; referencias históricas originales. |
| PilotDemoController | Sesión, revisión pura, confirmación con frontera, errores controlados y recuperación por UUID. |
| PilotDemoAccess y rutas | Sesión/guardia de instalación; confirmación solo registrada bajo marker exacto. |
| Routers 8774 y 8775/8776 | Listas de rutas cerradas; no sirven el workspace; SQL y sesiones aisladas del ERP ordinario. |
| BankBalanceService / ContabilidadService | Banco X, cuentas/período/cuadre y misma transacción exterior; no se modificaron. |
| Framework y esquema | beforeExecuting/run, transacciones/COMMIT, CSRF/sesión y FKs compuestas relevantes. |

Dos revisiones independientes cubrieron fronteras financieras y HTTP/SQL. Una revisión crítica adicional identificó la omisión de SERVER_PORT; un verificador distinto contrastó la reproducción y la corrección. Los revisores no ejecutaron SQL ni modificaron archivos.

## 2. Hallazgos y severidad

### F-01 — Puerto real no vinculado al Host permitido — baja, corregido

El router y la frontera HTTP comprobaban el puerto declarado en `HTTP_HOST`, pero no el listener real. Un cliente local con capacidad de enviar HTTP crudo podía alcanzar el login de un servidor iniciado por error en un puerto no autorizado usando un Host permitido.

Reproducción mínima anterior al arreglo, sin autenticación ni mutaciones:

```text
Listener real: 127.0.0.1:8777
GET login con Host 127.0.0.1:8777 → 404
GET login con Host 127.0.0.1:8775 → 200, login renderizado
Proceso detenido al terminar
```

El efecto demostrado es limitado: acceso al login en un listener fuera del contrato. **No demuestra** bypass de usuario, CSRF, banco, SQL, contabilización o separación de bases. Por eso no se clasifica como crítico ni como acceso financiero no autorizado.

Se añadió comprobación de `SERVER_PORT` y su correspondencia exacta con Host:

- Router lector: listener real 8774 y Host 127.0.0.1:8774.
- Router escritor: listener real 8775/8776 y Host correspondiente.
- Frontera del servicio: repite ambos controles, además de los anteriores.

Referencias: `tests/Support/pilot-demo-http-router.php:5`, `tests/Support/pilot-demo-readonly-router.php:5`, `app/Support/AccountingHttpSimulationBoundary.php:16`. La regresión posterior rechaza los Host falsificados sobre 8777, incluido un encabezado `Server-Port` manipulable, y conserva los listeners autorizados.

### O-01 — Certeza excesiva ante fallo — corrección operativa, sin exploit demostrado

El catch exterior de los routers decía «No se confirmó ninguna operación». Su alcance incluye fallos al procesar o terminar una respuesta, por lo que no debe prometer que no hubo confirmación. Se sustituyó únicamente el texto por resultado desconocido y consulta del UUID original. No cambian transacciones ni reglas.

### Límites conocidos, sin vulnerabilidad crítica confirmada

| Observación | Interpretación y límite |
| --- | --- |
| Cookies entre puertos | Cookies se delimitan por host/path, no por puerto. 8775/8776 comparten intencionadamente sesión; no son dos entornos aislados. |
| Otros servicios loopback | HttpOnly/SameSite no impiden que el navegador envíe cookies del mismo host/path a otro puerto local. No se verificó una topología hostil ni una toma de sesión; se exige un entorno local confiable y perfil de navegador dedicado. |
| Privilegios tester | La cuenta está aislada a test, pero conserva privilegios de DDL de las pruebas históricas. No es un principal SQL mínimo específico del cargo. No se cambiaron permisos. |
| Lista SQL | Es defensa adicional sobre consultas del código, no un firewall ni sustituto de privilegios mínimos. No se encontró una entrada de SQL arbitrario desde el navegador. |
| Visualización genérica | El login conserva la etiqueta «consulta» aunque el servidor de ensayo tenga ruta de confirmación. La pantalla advierte su naturaleza sintética y el servidor exige habilitación; no se cambió el diseño en esta fase. |

No se asigna severidad a hipótesis que dependen de un servicio local hostil no observado. Antes de producción deben resolverse los supuestos de hosts, transporte, sesiones y privilegios, con un alcance autorizado propio.

## 3. Fronteras y autoridad

- CLI no admite el contrato HTTP. Las entradas originales de escritor, resolutor y permiso tipado siguen pasando por AccountingSimulationBoundary.
- HTTP no adquiere autoridad CLI: exige SAPI cli-server, contrato definido por el router, loopback, Host/puerto reales, bootstrap controlado, testing, reset 0, SQL efectivo, sesión, CSRF y usuario activo autorizado.
- APP_ENV, UUID o parámetros como cuenta/política/actor no conceden autoridad. Se rechazan campos no previstos.
- La instalación ordinaria no registra confirmación sintética. Su controlador/servicio también conservarían la guardia si se intentara invocarlos por otra vía.
- La consulta histórica no construye permisos de contabilización. El permiso tipado conserva su procedencia privada y vuelve a validarse en assertMovement.
- No hay fallback por nombre, prefijo o primera cuenta. La selección usa papel/ámbito exactos y comprueba el vínculo real del banco con su cuenta.
- resolveForPosting y assertBusinessAuthority continúan bloqueados. No se promueve simulation a business.
- No hay ruta de reversión ni puente HTTP→CLI. Los procesos auxiliares nuevos son herramientas de pruebas/inspección, no productores HTTP de cargos.

No se cambiaron cálculos, signos, importes, cuentas, períodos, bloqueo bancario, conciliación ni reversibilidad.

## 4. SQL, sesiones y archivos

El router solo permite escrituras del evento en confirmación. Las restantes peticiones y el PDO independiente de consulta usan READ ONLY. Antes de abrir documentos se comprueba configuración y cuenta efectiva, sin conexiones alternativas.

El callback beforeExecuting cubre las operaciones Laravel que pasan por `Connection::run()`, incluidas sentencias y unprepared. **PDO directo no pasa por ese callback.** Sus usos visibles aquí son sentencias fijas de identidad/READ ONLY y SELECT preparados; no reciben SQL del navegador. El regex comprueba prefijos y no analiza todas las construcciones SQL. No se interpreta como aislamiento por sí mismo.

La identidad tester comprobada conserva USAGE global y los nueve privilegios previstos sobre el esquema de test, incluidos CREATE/DROP/ALTER. No tiene acceso a las otras bases. Una futura operación empresarial necesitará evaluar un principal mínimo por función; no se reduce ni amplía ningún permiso en esta auditoría.

Cookies HttpOnly y SameSite Strict, sesión de archivos privada, CSRF real y límites de intentos permanecen. El lector 8774 utiliza otro nombre de cookie/backend; los dos puertos escritores comparten el mismo. La prueba confirmó esa compartición deliberada y rechazó un cliente sin sesión. No se promete aislamiento de cookies por puerto.

La propiedad se verifica tanto para habilitaciones como para documentos; el UUID no es un token. Las rutas bancarias ordinarias y archivos como .env, bootstrap, vendor o public/index.php se rechazan en el servidor del piloto. Los errores probados no revelan SQL ni trazas al navegador.

## 5. Historia y respuestas inciertas

La consulta utiliza la política, bindings, cuentas y movimientos originales. No reconstruye el asiento con una versión vigente posterior. No exige banco/cuenta activos para mostrar el documento ni habilitación actualmente vigente.

En esta fase se volvió a consultar, sin cambios:

- El cargo 365 de la fase B4B, con habilitación 979 retirada y política ya vencida a la fecha real de la auditoría.
- Sus dos detalles, dos correspondencias, cuenta e identidad originales, también con reloj de consulta posterior.
- Un cargo con devolución confirmada B3A usando su ejecutor original en una prueba de consulta autenticada.
- El preparado 131: no tiene resultado confirmado y permanece idéntico.

Desactivaciones y cambios de vínculo no se volvieron a aplicar al SQL: eso modificaría originales de fases anteriores. Se releyeron sus condiciones en la fuente actual y su cobertura B4B previamente aceptada; no se presentan como variaciones SQL reproducidas nuevamente. Las asociaciones posteriores no se usan para resolver historia.

Un confirmado repetido conserva su identidad original; un contenido divergente se rechaza. No se ejecutaron reenvíos financieros en C ni se reutilizó 979 para confirmar. Los estados prepared incompatibles y la contención mantienen las reglas existentes.

Si se pierde el acuse durante COMMIT, el resultado puede haber confirmado: rollback no debe afirmarse por inferencia. El framework usa un intento por defecto y puede volver a lanzar con nivel transaccional cero tras perder conexión. El controlador indica resultado desconocido y consulta del UUID original. **No se inyectó una pérdida real de COMMIT** ni se declaró nuevamente concurrencia demostrada.

## 6. Revisión visual con navegador real

Se utilizó Chrome instalado, headless real mediante CDP nativo de Node 24; sin Playwright, Puppeteer ni dependencias nuevas. Se revisaron visualmente sus PNG. **No fue una sesión manual interactiva de una persona** ni mera inspección de HTML.

El primer intento de renderizador dentro del sandbox no respondió. Se completó con la ejecución de Chrome expresamente aprobada fuera de ese aislamiento de procesos. Se usó perfil de ensayo/incógnito y se restringieron las peticiones de la página al prefijo loopback; la automatización bloquea además cualquier POST de confirmación.

Resultado reproducible: ocho capturas, login real con el fixture sintético preexistente 882, formulario, textos largos sin envío financiero, error de validación, cargo confirmado anterior, asiento y dos detalles, política/asociaciones, conciliación y saldo actual correctamente diferenciado del importe original. Se comprobó la conservación del UUID tras la validación; una espera inicial incompleta del DOM se corrigió en la automatización, sin cambiar la aplicación.

Resoluciones: 1280×800, 1366×900 y 1920×1080. No se observó desbordamiento horizontal de la página en las vistas capturadas. Los textos largos permanecen dentro de los controles; no es una comprobación de todas las tablas administrativas ni una resolución de UI-01.

Evidencia privada local, no publicada ni expuesta por rutas:

- `storage/app/private/ac04b2b4c-visual/01-login.png`
- `02-index-1280.png`, `02-index-1366.png`, `02-index-1920.png`
- `03-confirmed-1366.png`, `03-confirmed-1920.png`
- `04-long-controls.png`, `05-validation.png`
- `result.json` en ese mismo directorio: dimensiones y cierre de procesos.

Capturas locales: [login](E:/laragon/www/salon-erp/storage/app/private/ac04b2b4c-visual/01-login.png), [cargo y asiento](E:/laragon/www/salon-erp/storage/app/private/ac04b2b4c-visual/03-confirmed-1920.png), [validación](E:/laragon/www/salon-erp/storage/app/private/ac04b2b4c-visual/05-validation.png).

Las credenciales se tomaron del fixture ya existente solo en memoria; no se crearon, cambiaron o publicaron. Las capturas no contienen contraseñas, PIN o cookies. No se envió una confirmación financiera ni se revisó una operación nueva usando 979.

**Pendiente para demostración humana completa:** revisión válida y nueva confirmación con un fixture vigente autorizado; preparado y devolución en navegador con las credenciales de sus respectivos ejecutores. El actor 882 no puede consultar documentos ajenos y no se eludió ese control. La devolución/preparado sí se comprobaron en las pruebas de consulta de Laravel.

## 7. Guía de demostración reproducible

### Preparación de consulta, disponible ahora

1. Desde `E:\laragon\www\salon-erp`, verificar PHP 8.3.30 y ejecutar el preflight seguro:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-ac04b1-tests.php audit-preflight
```

2. Verificar que la comparación con la línea base C sigue intacta:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/ac04b1-isolation.php after AC04B2B4C
```

No repetir `before AC04B2B4C`: la línea base es exclusiva y ya existe. No sobrescribirla. Si otro trabajo autorizado cambió test, detener esta comprobación y preparar otra línea base identificada antes del nuevo ensayo.

3. Iniciar solo el servidor de ensayo, comprobando primero que 8775 no esté ocupado por otro proceso:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 -S 127.0.0.1:8775 -t public tests/Support/pilot-demo-http-router.php
```

4. Abrir `http://127.0.0.1:8775/__pilot-demo/login` en un perfil dedicado. Usar una cuenta sintética propia, activa y alvaro/contador. El fixture 882 permite consultar sus cargos B4B con su credencial de ensayo ya existente; no se imprime aquí. Si no se dispone del acceso, solicitar aprovisionamiento autorizado, sin resetear cuentas durante esta guía.

5. Verificar el mensaje de simulación, consultar el historial y el UUID del cargo anterior. Ejemplo propio del fixture 882:

```text
984f7f00-62ee-470a-b459-17273c49519e
```

6. Revisar el cargo original C$50, movimiento 735, asiento 1971, DEBE/HABER, las dos asociaciones y política original. El saldo **actual** del banco 699 es C$450 tras todos los ensayos anteriores; no debe confundirse con C$950 inmediatamente después del primer cargo. Verificar estado en_transito y habilitación retirada, sin intentar reactivarla.

7. Para visualizar una devolución ya documentada, cerrar sesión y utilizar las credenciales del ejecutor original correspondiente. Seleccionar su cargo B3A con devolución confirmada; observar original, inverso y vínculos por detalle. No hay botón de devolución. No usar el UUID como sustituto de autorización. El preparado 131, consultado por su propietario, debe seguir mostrando que no es un éxito financiero.

8. Cerrar la sesión y detener únicamente el PHP iniciado en esa consola con Ctrl+C. Comprobar que ese PID terminó y que el puerto ya no responde. No detener MySQL, Laragon ni otros proyectos. Repetir la comparación `after`.

### Nueva confirmación: exige autorización separada de fixture

**No hay habilitación vigente creada en C y 979 no puede reutilizarse.** Una demostración de cargo nuevo requiere, antes de crear evidencia:

- Autorización específica para un fixture sintético nuevo, con banco/actor/política/ventana identificados, sin reutilizar ventanas ocupadas.
- Respaldo actualizado del estado completo y SHA256, nueva línea base y comprobación de no solapamiento.
- Habilitación simulation vigente provista por el arnés autorizado; no por el navegador, no business y sin promover registros existentes.

Solo después: seleccionar el banco de ese fixture, maintenance/commission, decimal y referencia sintética; revisar cuentas/fecha/UUID; confirmar expresamente; consultar el mismo UUID si se pierde la respuesta. Nunca generar otro automáticamente ni afirmar que prepared está confirmado. Verificar movimiento, saldo, dos líneas y dos correspondencias antes de cerrar.

No ejecutar `web-core`, `web-history`, `web-race`, `web-race-resume` ni el archivo completo `web-boundary` para repetir esta auditoría: algunos crean evidencia o modifican metadata, aunque la restauren después. Tampoco repetir suites antiguas sobre sus ventanas. La nueva confirmación **no se ejecutó** en C.

### Lista de comprobación del propietario

- [ ] Sesión y rol correctos; aviso de simulación en todas las vistas.
- [ ] No confundir dos puertos escritores con dos sesiones aisladas; utilizar perfiles separados para usuarios distintos.
- [ ] UUID propio y estado correctos; prepared no presentado como contabilizado.
- [ ] Cuenta de gasto/fondos, dos detalles, moneda y versión/hash originales visibles.
- [ ] Saldo actual separado del importe y del saldo histórico del primer ensayo.
- [ ] Errores comprensibles, datos conservados y consulta por UUID tras respuesta incierta.
- [ ] Devolución previa visible solo con acceso autorizado, sin botón de ejecución.
- [ ] Nueva confirmación únicamente con fixture y respaldo expresamente autorizados.
- [ ] Ningún cambio en las bases durante una demostración de solo consulta; servidor detenido al terminar.

## 8. Pruebas ejecutadas

Antes de probar se revisaron los cuerpos de las pruebas. Se excluyeron los ensayos financieros persistentes, los cambios de metadata del histórico B4B y las pruebas que crean usuarios aunque hagan rollback, para conservar incluso AUTO_INCREMENT.

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-ac04b1-tests.php audit-readonly
```

**6 pruebas, 77 aserciones, PASS.** Dos pruebas HTTP existentes seguras y cuatro nuevas de auditoría. La conexión del caso nuevo está READ ONLY y rechaza SQL distinto de SELECT/SHOW.

Cobertura ejecutada: entradas CLI/HTTP y empresarial cerradas; ruta ordinaria ausente; puerto real/Host falsificado; login real, cookie/CSRF; peticiones sin sesión, objetos ajenos y rol no autorizado; sesión compartida entre puertos del mismo ensayo; archivos/rutas internas rechazados; validación sin habilitación; prepared/historia vencida-retirada/devolución; preservación de ID 131. No se llama al escritor con un cargo nuevo ni se reenvía uno confirmado.

El navegador añadió comprobaciones de renderizado, UUID conservado y cero solicitudes financieras. Chrome y PHP terminaron. Lint de los PHP inspeccionados/modificados: PASS; `node --check` del arnés: PASS; `git diff --check`: PASS. No se ejecutó build nuevo: Blade, CSS y JS de aplicación no cambiaron; el build B4B permanece como antecedente, no como comprobación nueva.

No se repitieron las 192 pruebas ni las siete carreras anteriores. Tampoco se simularon caídas durante COMMIT o contención financiera. Se conserva su evidencia previa y se distingue de esta regresión segura.

## 9. Estado de las tres bases y guardias

| Base | Migraciones | Tablas | Filas antes / después |
| --- | ---: | ---: | ---: |
| salon_erp | 74 | 78 | 225 / 225 |
| salon_erp_test | 82 | 89 | 1.873 / 1.873 |
| salon_erp_bootstrap_check | 74 | 78 | 202 / 202 |

Comparación C estricta: filas, columnas y DDL completo **idénticos, incluido AUTO_INCREMENT**. No hay nuevos asientos, saldos, movimientos, autorizaciones o evidencia documental SQL. Se preservan 131, 979 y todos los extractos/documentos anteriores.

Aplicación/pruebas: `salon_erp_tester@localhost`, MySQL 8.4.3, puerto 3306, roles NONE, privilegios esperados y acceso ajeno denegado. El helper administrativo preexistente se limita a comparar las tres bases mediante lectura; en C también fuerza sesión SQL READ ONLY.

SALON_TEST_DB_RESET_ALLOWED=0 comprobado; runners y helpers lo restablecen mediante finally. Sin DDL, recreación, seeders, suite general, Composer test, restauración, cambios de permisos/credenciales/APP_KEY/AGENTS.md o activación de V2 en desarrollo.

## 10. Último respaldo verificado

```text
E:/salon-erp-backups/salon_erp_test_AC04B2B4B_20261116_224724_3566adb90885.sql
Tamaño: 1.438.554 bytes
SHA256: dd086cf07eb82b8788dc2ccdb27f411ff2ea2822f67c132921a757bd8e7650c6
89 tablas / 82 migraciones / 1.739 filas al exportar
```

Es el último dump existente y su hash/tamaño/sidecar se verificaron. No se creó uno nuevo ni se sobrescribió ninguno. La estructura corresponde al estado previo a los ensayos B4B.

**Límite importante:** no incluye las 134 filas sintéticas creadas después en B4B. Verificar su hash no equivale a certificar una restauración del estado actual de 1.873 filas. No se ensayó restauración. Antes de otro ensayo persistente se requiere respaldo nuevo completo; no restaurar este dump automáticamente.

## 11. Archivos modificados y diff

Cambios mínimos de frontera/mensaje:

- `app/Support/AccountingHttpSimulationBoundary.php`: puerto real y correspondencia con Host.
- `tests/Support/pilot-demo-http-router.php` y `pilot-demo-readonly-router.php`: listener real y mensaje de incertidumbre.

Preparación de comprobaciones reproducibles:

- `tests/Support/run-ac04b1-tests.php`: grupos audit-preflight/audit-readonly y etiqueta de respaldo existente.
- `tests/Support/ac04b1-isolation.php`: fase C sin altas permitidas, DDL exacto y finally.
- Nuevo `tests/Feature/PilotDemoFinalAuditTest.php`.
- Nuevos `tests/Support/ac04b2b4c-browser-info.php` y `ac04b2b4c-browser.mjs`, solo lectura/visualización.
- Este documento y `docs/AC_04B2B4C_DIFF.patch`.

El diff específico parte del árbol limpio inicial; no contiene migraciones ni cambios a controladores/servicios financieros, vistas, CSS global o UI-01. Sin commits automáticos. Las capturas y líneas base privadas están ignoradas por Git.

Para retirar código de esta fase, revisar primero `git apply --reverse --check docs/AC_04B2B4C_DIFF.patch`. No aplicar reversión ciegamente sobre cambios posteriores y no retirar la corrección del puerto sin evaluar la regresión. No requiere rollback SQL ni restauración. No borrar evidencia para revertir herramientas de auditoría.

## 12. Condiciones pendientes para producción

Este piloto no resuelve ni autoriza: aprobación empresarial real, habilitación de evento/recurso/entorno, host y transporte productivos, aislamiento frente a otros servicios locales, principal SQL mínimo, recuperación operativa ante incertidumbre, política bancaria empresarial de cortes, reversión empresarial, procedimientos del contador ni despliegue coordinado.

Las versiones técnicas de ensayo y sus referencias no sustituyen una aprobación del contador. No migrar saldos, renumerar cuentas, promover simulation o modificar documentos históricos para cerrar estas condiciones. Requieren una fase autorizada propia.

## 13. Recomendación de cierre técnico

Cerrar AC-04B2B4C y el piloto **como demostración técnica local sintética**, conservando los límites anteriores. La omisión demostrada del puerto quedó corregida y probada; no se encontró otro bypass financiero confirmado en el alcance. La revisión no declara producción habilitada ni cobertura completa de otros módulos.

La parte pendiente de demostración positiva nueva es deliberada: necesita autorización específica de fixture vigente, respaldo actual y acceso de los ejecutores adecuados. No impide documentar o consultar de forma reproducible la evidencia existente.

## 14. Preparación para AC-05, sin iniciarlo

AC-01 identifica AC-05 como **reportes/clasificación**: separar agrupaciones de presentación de prefijos numéricos preservando historia. El siguiente alcance debería autorizar primero un diagnóstico de diario/mayor/balance/resultados/dashboard/cierre, con matriz de clasificación propuesta por el contador y comparaciones de saldos antes/después.

Condiciones de entrada recomendadas: autorización expresa de AC-05, política de clasificación empresarial pendiente identificada, datos de ensayo y respaldo actual, reportes de referencia y pruebas de contra-cuentas, cuentas inactivas con saldo, exclusión de cierres y ausencia de doble conteo. No importar el Excel, renumerar cuentas ni sustituir resoluciones financieras como parte implícita de ese trabajo.

Se detiene aquí. No se inicia AC-05, AC-06, H01-B2B ni ninguna activación empresarial.
