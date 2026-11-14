# H02-A — Salidas y devoluciones no fraccionables

## Alcance implementado

Se conservan el monolito y los servicios actuales. No hay migraciones, dependencias, cambios de cuentas, precios, impuestos, pagos, comisiones, valoración ni algoritmos FIFO por volumen.

Para las salidas no fraccionables se exigen cantidades numéricas, finitas, positivas y enteras; stock suficiente para la demanda acumulada del documento y cobertura completa de lotes FIFO. También se exige saldo suficiente del kárdex: tener agregado y lotes sin apertura/movimientos no autoriza crear un saldo de libro negativo. Los artículos heredados no conciliados se rechazan con un mensaje de conciliación, sin crear lotes ni compras ficticias. En ventas se valida además la cantidad del carrito antes de que el cast entero de DetalleVenta pueda ocultar una fracción.

La demanda incluye productos repetidos y los insumos no fraccionables de todos los servicios, utilizando el resolvedor existente de consumos reales/fórmula. Se valida el total antes de descontar y se vuelve a comprobar cada débito con lecturas actuales. FIFO comprueba cobertura antes de modificar lotes y rechaza cualquier remanente de demanda sin cubrir. La validación temprana del carrito no aplica la regla de unidades a ingredientes estándar que el consumo real sustituye y no utiliza; los bloqueos cubren también el fallback de fórmula existente.

Las devoluciones no fraccionables adquieren el mismo mutex y releen el saldo actual antes de sumar. Se conservan tanto el promedio usado para valorar la devolución como la elección del lote más reciente: **H04 continúa pendiente**.

## Protocolo de bloqueos

Se conserva el prefijo existente de cada operación: mutex de estilistas, citas/pivotes y documentos de venta cuando correspondan; en compras, documento existente bloqueado. A continuación:

1. Antes de los artículos, adquirir en orden los bloqueos compartidos de los usuarios participantes que exigirán las FK de movimientos, mermas o comisiones. Se conserva el prefijo de H16; no se añaden mutex exclusivos globales de usuarios.
2. Resolver todos los IDs de artículos implicados y eliminar duplicados.
3. Bloquear artículos individualmente por ID ascendente mediante SELECT FOR UPDATE. Esta es una lectura actual, incluso con un snapshot previo de REPEATABLE READ.
4. Antes de insertar hijos con FK a items, adquirir los mutex de artículos: evita conversiones concurrentes de bloqueos compartidos a exclusivos en detalles de venta, consumos y mermas.
5. Validar demanda no fraccionable, bloquear lotes por fecha_entrada, id y comprobar saldo actual del kárdex.
6. Mantener lotes, artículo, kárdex y efectos documentales/financieros en la transacción existente.

Compras inmediatas, aprobaciones de compra y anulaciones adquieren el conjunto completo ordenado antes de procesar líneas; no se reordenan los cálculos ni se cambian cantidades o costos. Venta/aprobación/anulación conservan los bloqueos de agenda. El servicio no añade transacciones con commits propios ni reintentos financieros automáticos. Sus nuevas operaciones de bloqueo requieren una transacción activa.

En operaciones mixtas se bloquean también los artículos fraccionables implicados para evitar adquirir sus filas posteriormente en un orden inverso. Esto solo modifica la sincronización: las nuevas reglas de unidades y cobertura no se aplican a sus cálculos físicos. Se conserva el orden original de ejecución de consumos y su carga de modelos, evitando introducir saldos fraccionables precargados y obsoletos entre líneas.

## Archivos

| Archivo | Justificación |
| --- | --- |
| app/Services/InventarioService.php | Bloqueo común, demanda acumulada, validación de unidades, cobertura FIFO y relectura en devolución. |
| app/Services/CompraService.php | Solo adquisición previa y ordenada de mutex de artículos. |
| app/Http/Controllers/VentaController.php | Prebloqueo de artículos antes de crear detalles y consumos con FK. |
| app/Http/Controllers/MermaController.php | Bloqueo anterior a la FK de merma y mensaje controlado de rechazo. |
| tests/Feature/InventoryUnitIntegrityTest.php | Stock exacto, insuficiencia, demanda repetida/BOM, cantidades, lotes, rollback, aprobación, compras y devoluciones. |
| tests/Feature/InventoryUnitConcurrencyTest.php | Carreras HTTP reales venta–venta, venta–merma y salida–anulación. |
| tests/Support/InventoryH02Fixtures.php | Fixtures exclusivamente locales de pruebas, con compra documental real. |
| tests/Support/h02-concurrency-worker.php | Proceso aislado con bootstrap, identidad y conexión autorizados. |
| tests/Support/run-h16-tests.php | Selecciones explícitas h02a, h02a-races y h02a-regressions, con el mismo preflight y finally. |
| tests/Support/isolation-snapshot.php | Línea base H02A para las tres bases, sin ampliar destinos de PHPUnit. |
| docs/H02_A_UNIDADES_CONCURRENCIA.md | Alcance, pruebas, limitaciones y reversión. |

No fue necesario modificar VentaController::anular(): ya mantiene la transacción y los bloqueos de agenda/documento; ahora el servicio añade el protocolo de artículos antes de devolver.

## Verificación autorizada

Se inspeccionaron los tests y sus efectos antes de ejecutarlos. Usan PHP 8.3.30 de Laragon, phpunit.xml, el bootstrap y los guardias existentes. El preflight comprueba salon_erp_test, salon_erp_tester@localhost, TCP 3306, ausencia de roles, permisos exactos únicamente sobre test, denegación de ambas bases ajenas y SHA256 del respaldo existente.

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 tests/Support/isolation-snapshot.php before H02A
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-h16-tests.php h02a
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-h16-tests.php h02a-races
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-h16-tests.php h02a-regressions
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 tests/Support/isolation-snapshot.php after H02A
```

El snapshot before no se sobrescribe. Las pruebas funcionales nuevas usan DatabaseTransactions; las carreras necesitan fixtures confirmados y procesos con conexiones independientes. No borran sus documentos financieros; una ejecución posterior autorizada de RefreshDatabase puede sustituir esos fixtures. La regresión existente CadenaSuministroApTest utiliza RefreshDatabase; solo ella requiere recrear el esquema autorizado. El launcher restaura SALON_TEST_DB_RESET_ALLOWED=0 mediante finally en cada ejecución. No se ejecutan suites completas ni Composer test.

Las carreras retienen el mutex de A, comprueban la solicitud FOR UPDATE de B en PROCESSLIST antes de liberar A y verifican el resultado persistido: artículo, lotes, saldo de kárdex, ventas, pagos, comisiones y asientos. B inicia deliberadamente una transacción con snapshot anterior para verificar la relectura actual. Se comprueban salida de ambos procesos y desaparición de sus conexiones. Solo se ejecutan esos dos procesos controlados por carrera.

La evidencia de cada carrera queda en storage/app/private/h02a-race-evidence.jsonl; los resultados PHPUnit en h02a-phpunit.log, h02a-races-phpunit.log y h02a-regressions-phpunit.log, dentro del mismo directorio ignorado por Git. No contienen credenciales reales.

Resultados finales (sin sumar repeticiones): **50 pruebas / 372 aserciones**, salida 0:

| Selección | Pruebas | Aserciones |
| --- | ---: | ---: |
| h02a: integridad funcional | 17 | 65 |
| h02a-races: MySQL independiente | 3 | 74 |
| h02a-regressions: SCM + H01-A | 30 | 233 |

Hubo una ejecución inicial de regresión y otra final después de completar los bloqueos compartidos de FK; ambas aprobadas y ambas recrearon exclusivamente test mediante RefreshDatabase. Los casos funcionales y las carreras se repitieron al completar las guardas; no se ejecutó la suite completa. No hubo fallos funcionales en las selecciones ejecutadas.

Evidencia de la última ejecución de carreras:

| Carrera | CONNECTION_ID distintos | HTTP A / B | Confirmaciones | Saldo final artículo / lotes / kárdex |
| --- | --- | --- | --- | --- |
| venta–venta | 555 / 556 | 201 / 422 | A sí, B no | 0 / 0 / 0 |
| venta–merma | 558 / 559 | 201 / 302 | A sí, B no; redirección con error de stock | 0 / 0 / 0 |
| salida–anulación | 561 / 562 | 201 / 302 | Ambas sí | 1 / 1 / 1 |

El HTTP 302 no se considera éxito por sí solo: se verifica la sesión, sus errores/success y el estado persistido. Las seis conexiones desaparecieron de PROCESSLIST. En salida–anulación, el documento anterior quedó anulado y la nueva venta confirmada; no se perdió la devolución.

La inyección de error al crear el kárdex, después del descuento físico/FIFO, confirmó rollback de artículo, lotes, kárdex, venta, detalles, pagos, asientos, líneas de asiento, comisiones y mermas. También se verificó rechazo en aprobación sin modificar el documento retenido. Los tests de conservación fraccionable mantienen volumen de compra duplicado y consumo parcial sin decremento de lote, precisamente porque H03 no se corrigió aquí.

| Base | Antes: tablas / filas / migraciones | Después | Resultado |
| --- | --- | --- | --- |
| salon_erp | 78 / 225 / 74 | 78 / 225 / 74 | Conteos, contenido y estructura idénticos en todas las tablas, incluidas sesiones. |
| salon_erp_test | 78 / 107 / 74 | 78 / 259 / 74 | Solo fixtures y operaciones de prueba autorizadas; las carreras finales conservan sus documentos para revisión. |
| salon_erp_bootstrap_check | 78 / 202 / 74 | 78 / 202 / 74 | Conteos, contenido y estructura idénticos en todas las tablas. |

El comparador final salió con código 0. SALON_TEST_DB_RESET_ALLOWED terminó en **0**, confirmado tras finally y por lectura del archivo local. No se cambiaron permisos, cuentas SQL, APP_KEY ni .env ordinario. PHP lint y git diff --check pasaron. El diff inverso se comprobó con --check; no se aplicó.

## Límites y siguientes dependencias

- H03: decisión provisional aceptada: existencia_actual = envases cerrados; current_volume = remanente abierto; disponible = cerrados × capacidad + remanente; trazabilidad del remanente por lote. No se implementó ni se convirtieron datos históricos.
- Los defectos existentes de fraccionables, incluidos volumen duplicado en compras y consumo parcial sin actualización de lote, permanecen. Las pruebas de conservación documentan ese comportamiento, no lo legitiman como diseño correcto.
- H04: se conservan costo vigente y devolución al lote más reciente. No se garantiza reversión histórica exacta de cantidades derivadas de recetas modificadas ni de artículos archivados.
- La anulación ordinaria de una venta retenida conserva el problema diagnosticado de devolver existencias no descontadas. Se debe corregir por separado; la sincronización no demuestra que esa devolución corresponda comercialmente.
- No se sanea stock previo, precisión de cantidades de compras ni conciliaciones ausentes entre agregado, lotes y kárdex. Se exige cobertura para las salidas aquí protegidas; no se inventan movimientos para reparar diferencias heredadas.
- La apertura heredada de kárdex y la futura H01-B requieren revisar su propia sincronización e idempotencia antes de ampliarlas.
- No se asegura ausencia universal de deadlocks en todos los módulos financieros. Se elimina la inversión de artículos revisada y se comprueban las tres carreras exigidas; los conflictos del motor no se reintentan automáticamente.

## Reversión

Reversión de código únicamente, sin restauraciones SQL ni migraciones. El diff completo se prepara en storage/app/private/h02a-review.diff. Antes de una reversión posterior autorizada, comprobar `git apply --reverse --check storage/app/private/h02a-review.diff`; aplicar sin --check solo después de revisar cambios posteriores. Revertir devuelve los riesgos originales de H02. No se hicieron commits ni se aplicó la reversión.
