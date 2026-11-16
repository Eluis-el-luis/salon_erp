# AC-04B2B4B — Piloto HTTP sintético

Fecha: 2026-11-16. **Completado exclusivamente en salon_erp_test. No aprobado para uso empresarial.** No hay despliegue, migración, aprobación económica real ni devolución HTTP.

## 1. Auditoría previa y arquitectura

Se inspeccionaron AccountingBankChargeSimulation, AccountingSimulationBoundary, AccountingSimulationPosting, AccountingEventIdentityService, AccountingAccountResolver, AccountingEventAuthorizationService, BankBalanceService, ContabilidadService y la interfaz/router de AC-04B2B4A.

La frontera CLI estaba repetida en escritor, fijación de autorización, resolutor y permiso tipado de contabilización. Quitar solo el primer chequeo habría sido insuficiente y habría debilitado el contrato. Se conservaron las entradas CLI y se separó el despacho de frontera de la lógica común:

- `AccountingSimulationBoundary` permanece **sin cambios**, con su exigencia de PHP_SAPI=cli y contrato original.
- `AccountingHttpSimulationBoundary` es una frontera distinta, exclusiva del servidor sintético.
- `AccountingSimulationAccess` despacha únicamente contratos conocidos hacia su guardia completa; no acepta un booleano de autorización.
- `AccountingBankChargeSimulation::executeForInternalHarness()` sigue comprobando CLI antes de entrar al procedimiento común privado.
- `executeForSyntheticHttp()` exige la nueva frontera y propiedad de la habilitación; reutiliza ese mismo procedimiento privado.
- Las entradas CLI de autorización, resolutor y `AccountingSimulationPosting::forPrepared()` siguen rechazando el contrato HTTP. Sus procedimientos comunes revalidan la frontera correspondiente. El permiso tipado conserva privadamente su contrato para revalidarse al entrar en ContabilidadService.

**No existe puente HTTP→CLI.** La aplicación no utiliza proc_open, shell_exec ni comandos de sistema para contabilizar. Los procesos auxiliares pertenecen exclusivamente a las pruebas y transportan solicitudes HTTP o ejecutan los competidores de dominio inspeccionados.

## 2. Frontera HTTP y conexión de lectura

Nuevo servidor específico: `tests/Support/pilot-demo-http-router.php`, permitido únicamente en `127.0.0.1:8775` o `127.0.0.1:8776`. El segundo puerto permite ensayar procesos independientes en Windows, donde un único servidor PHP integrado serializaría las solicitudes.

Controles simultáneos:

- SAPI cli-server, REMOTE_ADDR loopback y Host exacto permitido.
- Bootstrap seguro, raíz verificada, testing y reset deshabilitado.
- Contrato de servidor definido por código, no por parámetros HTTP.
- Configuración limitada a mysql/tester/test; se rechazan conexiones alternativas, URLs o redirecciones de conexión.
- Identidad efectiva DATABASE(), CURRENT_USER(), puerto y roles SQL comprobados.
- Sesión, CSRF y usuario activo alvaro/contador revalidados en el servicio.
- Evento, propósito simulation, banco y recurso comprobados por los servicios existentes.

La ruta POST `/__pilot-demo/confirm` **solo se registra cuando el router específico ha definido el contrato exacto**. La guardia del controlador/servicio se repite aunque la ruta exista. La instalación ordinaria no registra esa confirmación. APP_ENV=testing por sí solo no basta.

El servidor anterior `pilot-demo-readonly-router.php` no fue modificado: conserva puerto 8774, modo SQL de lectura y lista de rutas sin confirmación. La conexión PDO de `PilotDemoReadOnly` permanece READ ONLY y separada del escritor. En el servidor nuevo, las peticiones de consulta también mantienen la conexión Laravel en modo de lectura; solo la confirmación tiene conexión de escritura, restringida por una lista de sentencias/tablas del evento. DDL, borrados y escrituras fuera del caso de uso se rechazan.

No se modificaron permisos SQL, credenciales, APP_KEY ni configuración persistente. El aislamiento no pretende proteger frente a una persona que ya puede modificar código o credenciales locales.

## 3. Contrato financiero y locks

Se conserva el evento `bancos.cargo_gasto.nio`, contrato documental 1, maintenance/commission, moneda NIO y dos líneas. No se añade una selección de cuenta por nombre, código o prefijo. Los IDs elegidos por el cliente no son autoridad: se valida la habilitación propia y el motor fija sus recursos/cuentas.

Orden efectivo del procedimiento común:

1. Frontera, usuario activo y normalización decimal/canónica.
2. Si el UUID ya está confirmado: verificar actor, contenido y referencias originales; devolver historia sin volver a resolver vigencia o cuentas actuales.
3. Una transacción exterior. Fijación de versión/autorización mediante lecturas actuales y locks compartidos.
4. Asociaciones de esa versión; banco exclusivo mediante BankBalanceService antes de resolver recursos/cuentas.
5. Revalidación de autorización, recursos y cuentas; operación UUID y permiso tipado.
6. Movimiento y asiento mediante ContabilidadService; período y cuentas imputables según sus reglas actuales.
7. Dos correspondencias documentales, referencias de operación y saldo desde el banco bloqueado.
8. Verificación integral y validarCuadre; commit exterior único.

ContabilidadService conserva sus transacciones anidadas, que no confirman independientemente el documento exterior. El manejo de Throwable del escritor conserva el rollback hasta el nivel de entrada. No se añadieron reintentos financieros ni GET_LOCK administrativo al cargo.

El preparador existente sigue su orden recursos/cuentas→UUID; el escritor conserva versión→banco→cuentas→UUID. Su competencia real se ensayó. Publicación y retirada mantienen su mutex administrativo y su bloqueo de versión, sin incorporarlo al escritor.

No se modificó ContabilidadService, BankBalanceService ni la aritmética bancaria heredada. resolveForPosting y assertBusinessAuthority siguen cerrados. No se implementó un segundo motor contable.

## 4. Interfaz y seguridad

La vista Blade anterior conserva revisión, normalización y consulta. Solo en el servidor HTTP sintético muestra el botón **Confirmar expresamente el cargo sintético** y el aviso:

> SIMULACIÓN FINANCIERA — DATOS SINTÉTICOS — NO APROBADO PARA USO EMPRESARIAL.

La confirmación POST transporta únicamente UUID, habilitación seleccionada, clasificación, decimal, fecha, referencia y descripción. El servidor rechaza campos adicionales de cuenta, política o actor. Tras confirmar redirige al UUID original y muestra movimiento, asiento, correspondencias, política, habilitación y conciliación.

El saldo mostrado se identifica como **saldo actual**, que puede incluir operaciones posteriores; no se presenta como un saldo histórico congelado inexistente. La moneda del saldo actual se diferencia de la moneda original del documento.

Las sesiones del escritor usan cookie y archivos privados distintos de la consulta 8774 y del ERP ordinario. Cookie HttpOnly, SameSite Strict y ruta limitada al prefijo. CSRF se comprueba incluso bajo testing. Login limitado a cinco intentos por minuto y confirmación a treinta, con middleware existente.

Solo alvaro/contador activos pueden acceder. La habilitación debe pertenecer al ejecutor; el UUID no concede acceso. Se comprobaron solicitudes ajenas y actores sin permiso. No se amplían permisos de admin/recepción para esta operación.

El router rechaza `/bancos`, archivos del repositorio y cualquier ruta no permitida. No hay ruta o botón de devolución. No se modifica navegación, CSS global, UI-01 ni dependencias.

Los errores HTTP no incluyen SQL ni trazas. Validación/negocio produce 422, identidad distinta 409 y acceso no autorizado 403/404. Ante fallo no clasificado se devuelve indisponibilidad y se pide consultar el UUID original. El diagnóstico privado contiene solo clase, archivo y línea, sin argumentos, SQL o credenciales.

## 5. Idempotencia y preparados

UUID UNIQUE y hash canónico siguen siendo la identidad original. Un reenvío explícito del mismo contenido devuelve los mismos documentos y no repite saldo. Otro contenido produce 409; un UUID ajeno no revela el documento. No se genera otro UUID automáticamente después de perder una respuesta.

La revisión y `executionInput()` solo normalizan, comprueban acceso y comparan identidad; no persisten preparados. Para documentos nuevos, la autoridad se repite bajo locks en el único motor financiero. Para resultados confirmados, no se exige que la configuración operativa actual siga siendo la original.

Un prepared íntegro puede confirmarse por el motor bajo su habilitación vigente; no se interpreta como éxito previamente. El preparado histórico ID 131 permanece intacto y no se utiliza como fixture mutable. Los preparados incompatibles y las referencias incompletas mantienen sus rechazos existentes.

No se incorpora un nuevo marcador de canal al contrato documental 1: la identidad permanece compatible con preparaciones CLI y reenvíos de ambas fronteras. La frontera se verifica al ejecutar y la evidencia del arnés identifica las solicitudes HTTP. Esto no acredita ninguna aprobación empresarial.

## 6. Consulta histórica

Se verificó el uso de la versión y bindings originales, no de una política vigente posterior. La proyección histórica no exige banco activo, cuenta activa ni habilitación vigente. Tampoco reconstruye las cuentas desde el vínculo bancario actual.

Se eliminó una dependencia residual de la moneda operativa actual del banco para la **consulta histórica**; el documento conserva su moneda original. El reenvío de un confirmado también puede devolver la historia sin pasar por las comprobaciones operativas de una revisión nueva. Las operaciones nuevas siguen revalidando banco, moneda, cuentas y habilitación bajo locks.

Pruebas verificadas: banco inactivo, cuenta original inactiva, vínculo contable bancario cambiado, habilitación retirada, política fuera de vigencia en el reloj de consulta y versión técnica posterior con asociación de gasto diferente. Las alteraciones de metadata se limitaron a fixtures nuevos de esta fase y se restauraron. Ningún asiento anterior se modificó.

La devolución sintética previamente confirmada de AC-04B2B3A continúa visible mediante las pruebas de consulta AC-04B2B4A; no se ejecuta el reversor desde HTTP.

## 7. Resultado monetario

Ensayo principal: banco sintético 699, actor sintético 882, política 1070, habilitación 979. Operación 365, movimiento 735, asiento 1971:

| Concepto | Resultado |
| --- | ---: |
| Saldo bancario inicial de fixture | C$1.000,00 |
| Cargo HTTP | C$50,00 |
| Saldo inmediato | **C$950,00** |
| DEBE gasto de ensayo, cuenta 2662 | C$50,00 |
| HABER fondos del banco, cuenta 2663 | C$50,00 |
| Líneas / correspondencias | 2 / 2 |

Los reenvíos no duplicaron esos documentos. Al terminar todos los ensayos hay diez cargos configurables de C$50 y un cargo heredado de C$50 en ese banco, todos con asiento. Saldo final C$450,00: 1.000 − 550.

Conciliación del mayor, signo DEBE−HABER:

| Cuenta | Antes de la fase | Delta de los documentos del banco 699 | Después |
| --- | ---: | ---: | ---: |
| Fondos sintéticos 2663 | 0,00 | −550,00 | −550,00 |
| Gasto sintético 2662 | 0,00 | +500,00 | +500,00 |
| Gasto heredado 6.9, ID 2330 | 2.065,00 | +50,00 | 2.115,00 |

La suma de débitos y créditos es exacta. Los reportes del motor heredado y las regresiones de AC-04B2A preservan diario, mayor, resultados, balance y libro bancario. El delta del mayor bancario coincide con el delta físico de saldo del fixture.

Los C$1.000 iniciales son una precondición sintética del arnés, no una apertura contable real. No se inventó un asiento de apertura para convertir el ejemplo en un balance empresarial certificado.

## 8. Carreras reales y evidencia

Dos servidores HTTP independientes y workers de dominio, conexiones MySQL distintas y barreras verificables. Se observó la consulta bancaria FOR UPDATE bloqueada mediante SHOW PROCESSLIST antes de liberar el banco. En preparación, el preparador mantuvo su transacción mientras el HTTP esperaba banco X. No se simula concurrencia mediante mocks.

| Carrera definitiva | Operación principal | Conexiones SQL | Procesos | Saldo al terminar |
| --- | ---: | --- | --- | ---: |
| Mismo UUID, dos HTTP | 367 | 629 / 631 | 12196 / 11272 | 900,00 |
| UUID distintos, dos HTTP | 369 y 370 | 633 / 635 | 12196 / 11272 | 800,00 |
| HTTP / publicación incompatible | 371 | 637 / 639 | 12196 / 5784 | 750,00 |
| HTTP / cargo heredado | 372 | 640 / 642 | 12196 / 11296 | 650,00 |
| Preparado / ejecución HTTP | 373 | 644 / 643 | 12196 / 10104 | 600,00 |
| HTTP / conciliación | 376 | 659 / 661 | 6916 / 7552 | 500,00 |
| HTTP / retirada | 377 | 662 / 664 | 6916 / 2508 | 450,00 |

Mismo UUID confirmó una sola operación. UUID distintos y cargo heredado conservaron ambas salidas. La publicación solapada se rechazó sin cambiar versión. La preparación conservó la misma identidad y se confirmó una sola vez. La retirada confirmó después del cargo fijado; nuevas operaciones se rechazaron, mientras el resultado original siguió consultable.

La conciliación utiliza base cero provisional, extracto cero y ningún cargo presentado: diez cargos quedaron en tránsito, saldo_libros −500 y diferencia cero; la última salida, posterior al cierre, quedó pendiente. Saldo_actual no fue alterado por conciliar. Se verificó que el extracto cerrado no cambió después.

Evidencias privadas: `ac04b2b4b-http-races.json`, `ac04b2b4b-functional-result.json`, resultados de workers y `ac04b2b4b-rejected-reconciliation-attempt.json`. No contienen cookies/PIN/contraseñas; las cookies de clientes concurrentes viajan por pipes y se excluyen de sus resultados. Los procesos se cerraron y las transacciones terminaron limpiamente.

## 9. Incidencias del ensayo y reanudación controlada

Se conserva toda la evidencia; no se limpiaron documentos para obtener un resultado positivo:

1. Un fixture inicial intentó publicar con inicio al comienzo del día, ya pasado. El servicio lo rechazó correctamente. Quedaron su borrador, banco y catálogo sintéticos sin cargo ni asiento. El fixture definitivo se publicó con inicio futuro y esperó a su vigencia real, sin alterar el reloj HTTP ni la política de publicación.
2. La primera confirmación HTTP fue bloqueada por una lista SQL de lectura todavía aplicada al router nuevo. Se comprobó explícitamente saldo 1.000, cero operaciones y cero movimientos. Se corrigió únicamente el router de escritura; el router anterior READ ONLY no se tocó. Se continuó con el mismo UUID.
3. Se ajustó la respuesta HTTP de una excepción de negocio contable a 422; el intento rechazado no dejó efectos. El cargo principal ya confirmado solo se reenvió con su UUID, sin repetir saldo.
4. La primera carrera de conciliación llevaba un saldo de extracto negativo, prohibido por la validación existente. La conciliación se rechazó antes del lock; su cargo HTTP independiente sí confirmó como operación 375 y se conservó. Ese intento no se cuenta como carrera válida de conciliación.

Se inspeccionaron los resultados y se reanudaron **solo las dos carreras pendientes**, con un nuevo cargo identificado y un extracto cero válido. Las cinco carreras ya verificadas no se repitieron. El modo de reanudación exige expresamente la evidencia de esas cinco y el saldo esperado, y rechaza otra situación. No es un mecanismo de reintentos financieros de la aplicación.

## 10. Pruebas finales

| Grupo focalizado | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| web-core | 1 | 50 | PASS |
| web-race-resume | 1 | 153 | PASS; completa y verifica el registro de siete carreras |
| web-history | 1 | 135 | PASS |
| web-boundary | 2 | 19 | PASS sobre las fronteras y ruta definitivas |
| demo-core | 14 | 87 | PASS |
| demo-http | 2 | 18 | PASS con servidor de consulta real |
| demo-regressions | 171 | 1.385 | PASS |
| **Total de últimas ejecuciones aprobadas** | **192** | **1.847** | **PASS** |

El total no incluye aserciones de intentos interrumpidos ni ejecuciones repetidas. No representa una suite general. La extracción financiera definitiva pasó las regresiones; los últimos ajustes de consulta/ruta se verificaron con los grupos de frontera y lectura, incluido un reenvío HTTP que no cambió ninguna fila financiera.

Cobertura: cargo correcto y cuentas exactas, decimal/clasificación inválidos, cuentas/política/actor manipulados, CSRF ausente o incorrecto, sesión, rol no autorizado, actor inactivo, documento/habilitación ajenos, banco/cuenta inactivos, período cerrado, UUID y respuesta perdida, historia tras cambios, conservación de originales y resultado de las carreras. Las regresiones comprueban rollback inyectado del procedimiento común, preparados incompatibles, cuentas/períodos, autorización y reversas históricas.

La prueba de frontera demuestra que las entradas CLI originales no admiten el contrato HTTP y que el contrato HTTP no puede adquirirse desde CLI ni desde una instalación ordinaria.

`npm.cmd run build`: PASS, Vite 8.1.5. Aviso no bloqueante de fontaine opcional; no se instaló. PHP lint: PASS. `git diff --check`: PASS. No hubo revisión visual manual de un navegador; se verificó el contenido y las sesiones mediante HTTP real y pruebas de Laravel.

## 11. Respaldo, bases y guardias

Nuevo respaldo antes de las escrituras, conservando todos los anteriores:

```text
E:/salon-erp-backups/salon_erp_test_AC04B2B4B_20261116_224724_3566adb90885.sql
1.438.554 bytes; 89 tablas; 82 migraciones; 1.739 filas
SHA256 dd086cf07eb82b8788dc2ccdb27f411ff2ea2822f67c132921a757bd8e7650c6
```

Salida correcta, estructura/tamaño/SHA256/sidecar comprobados y `source_unchanged=true`. No se ensayó restauración (`restore_tested=false`). No se sobrescribieron ni restauraron dumps.

Preflight: PHP 8.3.30, MySQL 8.4.3, tester@localhost restringido, puerto 3306, roles NONE, privilegios exactos y acceso a desarrollo/bootstrap denegado. Los helpers administrativos existentes solo se usaron para respaldo y comparación. Las operaciones de aplicación y pruebas utilizan tester/test.

Comparación final contra la línea base independiente:

| Base | Migraciones | Tablas | Filas antes → después | Resultado |
| --- | ---: | ---: | ---: | --- |
| salon_erp | 74 | 78 | 225 → 225 | Filas y estructura intactas |
| salon_erp_test | 82 | 89 | 1.739 → 1.873 | Todas las filas previas conservadas; 134 filas nuevas sintéticas |
| salon_erp_bootstrap_check | 74 | 78 | 202 → 202 | Filas y estructura intactas |

Sin DDL. La comparación de estructura ignora exclusivamente el avance de AUTO_INCREMENT propio de inserts o rollbacks. Se preservaron ID 131, extractos y evidencia anterior. No se atribuyeron políticas nuevas a asientos legacy. Inventario y nómina anteriores permanecen intactos.

SALON_TEST_DB_RESET_ALLOWED=0, restaurado por finally de los runners. No se ejecutó RefreshDatabase, migrate:fresh, Composer test, suite general, seeder, cambio de permisos o despliegue.

## 12. Archivos y uso local

Modificados respecto al inicio de esta fase:

- AccountingBankChargeSimulation, AccountingEventAuthorizationService y AccountingAccountResolver: entradas estrictas y procedimientos comunes de simulación.
- AccountingSimulationPosting: revalidación por la frontera que produjo el permiso tipado.
- PilotDemoReadOnly: entrada canónica pura, saldo de consulta y separación de historia/configuración operativa.
- PilotDemoController, vista Blade y routes/web.php: confirmación condicionada al servidor autorizado.
- Los tres helpers de backup, aislamiento y grupos de pruebas: fase B4B, 82 migraciones y comprobación de altas sintéticas sin modificar filas previas.

Nuevos:

- `app/Support/AccountingHttpSimulationBoundary.php`, `AccountingSimulationAccess.php`.
- `tests/Support/pilot-demo-http-router.php`, `PilotHttpClient.php`, `pilot-demo-http-client.php`, `ac04b2b4b-domain-worker.php`, `ac04b2b4b-inspect.php`.
- Cuatro pruebas: PilotDemoHttpExecutionTest, PilotDemoHttpConcurrencyTest, PilotDemoHistoricalTest y PilotDemoBoundarySeparationTest.
- Este informe y `docs/AC_04B2B4B_DIFF.patch`.

Servidor local específico, si se desea consultar la evidencia:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 -S 127.0.0.1:8775 -t public tests/Support/pilot-demo-http-router.php
```

Acceso: `http://127.0.0.1:8775/__pilot-demo/login`, únicamente con credenciales de ensayo autorizadas. No se dejaron servidores ejecutándose. No se publican credenciales en el informe.

La habilitación 979 terminó retirada intencionalmente por la carrera. La historia y el reenvío del confirmado funcionan; un cargo nuevo requiere una habilitación sintética vigente provista por un arnés autorizado. El servidor no la crea ni la reactiva automáticamente. La versión técnica futura tampoco es autoridad económica ni tiene habilitación financiera por defecto.

## 13. Diff, reversión y límites

`AC_04B2B4B_DIFF.patch` contiene solo los cambios de esta fase respecto a su snapshot inicial, incluidos los nuevos archivos, excluyendo este informe y el propio parche. Preserva los cambios de AC-04B2B4A que ya estaban en el árbol al empezar. Git continúa con cambios pendientes; no hay commits automáticos.

Para retirar la infraestructura: detener únicamente sus servidores específicos, revisar el diff y ejecutar primero `git apply --reverse --check docs/AC_04B2B4B_DIFF.patch`. Revertir código solo si el parche sigue correspondiendo al árbol revisado. No revertir indiscriminadamente todo el estado de Git.

No requiere rollback SQL: no hubo migración. **No borrar cargos, asientos, cuentas con historia, extractos o autorizaciones para retirar el código.** Conservar la evidencia sintética; no restaurar automáticamente el dump. La consulta anterior y el contrato documental original permanecen compatibles con los cargos confirmados.

Límites: ensayo local, política de conciliación provisional, fixtures sin valor empresarial, autorización de cargo retirada al finalizar, reversas solo consultables, sin escritor empresarial. La ejecución de tests persistentes no es repetible ciegamente sobre su misma evidencia: revisar los archivos/estado y preparar otro ensayo autorizado cuando corresponda.

Se detiene AC-04B2B4B. No se continúa con habilitación empresarial, AC-05/06, H01-B2B o despliegues.
