# AC-04B2B2R — Conciliación bancaria concurrente verificada

Fecha: 2026-11-16. Implementación y ensayo completados exclusivamente en `salon_erp_test`, sin DDL ni nuevas migraciones. **Las reglas son provisionales del prototipo; no acreditan aprobación del contador o propietario para una implantación real.** No se implementa reversión bancaria ni habilitación financiera empresarial.

## 1. Contrato prospectivo implementado

Se mantienen rutas, roles, sesión, CSRF y presentación actuales. La confirmación exige:

- Un cierre por banco/fecha de fin de día; fecha estrictamente posterior al último cerrado.
- Identidad explícita del extracto base revisado. `base_conciliacion_id=null` representa inequívocamente ausencia de extracto; omitir el campo se rechaza. Cero no es un ID válido.
- Relectura actual de base y movimientos después de fijar el banco. Si la base cambió, rechazar y solicitar nueva preparación.
- Pendientes/en_transito con fecha <= corte siguen elegibles, aunque sean anteriores al último cierre. Los ya conciliados no vuelven a integrar el neto pendiente ni pueden reasignarse.
- Una segunda petición del mismo corte se rechaza; no se interpreta como éxito idempotente.
- Primera base sin extracto: cero, según el contrato histórico provisional. Nunca se toma del navegador ni de saldo_actual.

No se cambian fechas o saldos de extractos cerrados, se reconstruye historia o se agrega una apertura bancaria. Un registro posterior con fecha anterior no modifica fotografías cerradas: queda pendiente para tratamiento en un corte posterior.

## 2. Corrección de saldo_libros

Para cada nuevo corte:

```text
base = saldo_extracto del último cerrado, o cero si no existe
saldo_libros = round(base + neto de TODOS los pendientes/en_transito hasta el corte, 2)
extracto_teorico = round(base + neto de los movimientos seleccionados, 2)
diferencia = round(saldo_extracto ingresado - extracto_teorico, 2)
```

La fotografía de saldo_libros se calcula **antes del marcaje**. Mantiene seleccionados y no seleccionados del conjunto pendiente, excluye futuros y ya conciliados. Diferencia >= 0,01 en valor absoluto se rechaza, conservando la normalización monetaria existente; no se agregaron ajustes contables, compensaciones ni otra tolerancia.

Prueba de sucesión: abono documentado 100, cierre 100; abono documentado 20, cierre 120. El segundo saldo_libros es **120, no 220**. La cabecera del primer corte permanece idéntica.

Prueba de tránsito: +100 seleccionado y -10 no seleccionado producen extracto 100 y libros 90; -10 queda en_transito. En otro corte se selecciona ese movimiento antiguo: extracto/libros 90, sin descontarlo dos veces. Un +20 posterior al corte permanece fuera de ambas fotografías hasta que corresponda.

La conciliación no cambia `cuentas_bancarias.saldo_actual` ni crea asientos. El saldo de libros fotografiado puede diferir legítimamente del saldo actual después de operaciones concurrentes o de movimientos posteriores al corte.

## 3. Preparación y referencias

El GET de conciliación usa una transacción corta de lectura, relee banco bajo S y mantiene lecturas actuales de pendientes/base antes de devolver datos de vista. La plantilla añade únicamente el hidden `base_conciliacion_id`; un valor vacío explícito se convierte a NULL por el middleware existente.

El POST valida presencia de ese campo y compara su ID contra la base obtenida del servidor. No recibe un saldo inicial autoritativo. No hay compatibilidad silenciosa con formularios antiguos que omitan la precondición; deben volver a prepararse.

En FondosService se añade `ultimaConciliacionCerrada(CuentaBancaria $cuenta, bool $current=false)`. El modo actual exige transacción y utiliza sharedLock. `saldoInicialConciliado()` conserva su firma y semántica, delegando la selección sin cambiar la resolución de fondos/cuentas.

La fecha y saldo del extracto cerrado provienen del modelo leído actualmente, no de un route binding obsoleto o un snapshot anterior a esperar el banco.

## 4. Locks, atomicidad e integridad

Confirmación: transacción exterior → banco X por BankBalanceService → último cerrado S actual → validación de base/corte → pendientes/en_transito hasta corte X por ID → cálculo → cabecera y marcaje → verificación de referencias → único commit.

Se bloquea el conjunto pendiente relevante, no solo los IDs seleccionados. Se verifica banco/corte y moneda, además de ausencia de booleano/referencias de conciliación contradictorios. Pendientes con moneda NULL o referencias incompatibles se rechazan para revisión documental, sin reinterpretarlos ni repararlos.

La transición a en_transito se limita a los IDs del conjunto leído y bloqueado que siguen pendientes. Los seleccionados se vinculan a la cabecera nueva, con booleano, estado y fecha coherentes. Antes de confirmar se releen los vinculados y se contrastan cantidad y referencias.

Se amplía el catch a Throwable para revertir también errores fuera de Exception. No se añadieron commits intermedios, retries automáticos o GET_LOCK administrativo. La conciliación no bloquea políticas ni cuentas contables después de tomar el banco y no invierte el orden de los escritores AC-04B0. El piloto mantiene versión → banco; la conciliación no solicita versión.

No se añadió una regla de período fiscal abierto para marcar extractos: no hay asiento que contabilizar. Los escritores financieros conservan ContabilidadService y sus controles de período. Banco inactivo/moneda no admitida se rechazan mediante BankBalanceService, sin bypass.

Unicidad lógica: el banco X serializa al único escritor de cierre identificado y la fecha debe superar al último cerrado. No existe una nueva UNIQUE banco/fecha ni garantía frente a SQL que eluda el servicio. No se modificaron FK históricas CASCADE o los estados borrador/cerrada; el endpoint continúa creando cerrada sin reaperturas.

## 5. Respaldo y preflight

Se comprobó primero la identidad restringida con el preflight existente. Después, antes de escrituras de ensayo, se creó un respaldo **nuevo y consistente** mediante el exportador seguro ampliado con la fase allowlisted AC04B2B2R:

```text
E:/salon-erp-backups/salon_erp_test_AC04B2B2R_20261116_155115_86aef24f2857.sql
1197216 bytes; 87 tablas; 81 migraciones; 1364 filas
SHA256 6b5942413e4e6d548c8eda2fe9d652a5835eeac9c600894a569ef85532d9e5c4
```

Verificados salida 0, estructura, finalización, tamaño, SHA256/sidecar y fuente sin cambios durante la exportación. Single-transaction, rutinas, eventos y triggers; archivo único fuera del repositorio, sin sobrescribir respaldos anteriores. No se probó restauración. La identidad administrativa se utilizó solo para respaldo/inspecciones de lectura, nunca para ejecutar los ensayos financieros.

Los grupos verificaron PHP 8.3.30 de Laragon, MySQL 8.4.3, TCP 127.0.0.1:3306, `salon_erp_test`, `salon_erp_tester@localhost`, roles NONE y privilegios exactos de esquema, más denegación de acceso a desarrollo/bootstrap. Se contrastaron las 81 migraciones por nombre/estado.

Preflight estructural real: InnoDB en las tres tablas bancarias, DECIMAL(15,2) de saldos, fecha DATE, enum borrador/cerrada, índice del banco y FK de conciliación a cuenta bancaria. También se comprueba explícitamente ID131: prepared, banco221 y referencias financieras NULL. Sin DDL.

## 6. Pruebas definitivas

| Grupo | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| reconciliation-core | 16 | 166 | Aprobado |
| reconciliation-race | 1, con 16 parejas MySQL | 604 | Aprobado |
| reconciliation-regressions | 129 | 758 | Aprobado |
| Total | **146** | **1528** | **Sin fallos** |

Secuenciales: primera base cero documentada, 100→120, tránsito antiguo, pendiente no seleccionado en libros, fecha igual/anterior, base obsoleta/omitida, diferencia, otro banco, ya conciliado, futuro, moneda/referencias incompatibles, corte vacío y repetición vacía, movimiento retrofechado posterior y preservación de fotografía. Fallos inyectados tras cabecera, primer marcaje y antes de commit revierten estados, referencias y documentos; se utilizó Error para verificar el manejo de Throwable. Se compara además saldo bancario y asientos antes/después.

Regresiones focalizadas: AccountIntegrityTest, AccountHierarchyConcurrencyTest, AccountingPolicyTest, AccountingAccountResolverTest, BankBalanceIntegrityTest, AccountingEventDocumentationTest, AccountingEventRollbackGuardTest, AccountingBankChargeSimulationTest y AccountingAdministrationTest. No se repitieron carreras históricas de esas fases ni se ejecutó una suite general.

Se actualizaron solo los tres payloads de primer extracto de BancosMesaCambioTest para incluir base NULL explícita, manteniendo sus aserciones. **Esa clase histórica no se ejecutó**, porque usa RefreshDatabase y esta fase no autoriza recreación. La cobertura nueva utiliza DatabaseTransactions o fixtures independientes persistentes, sin reset; el rechazo de campo omitido tiene prueba propia.

## 7. Concurrencia real y resultados persistidos

Una pasada nueva, 32 procesos/conexiones independientes, sin reutilizar bancos o ventanas anteriores. La pausa del primer worker ocurre en la consulta posterior al SELECT banco FOR UPDATE real, cuando el lock ya está adquirido; no se sustituyó el lock del controlador por uno artificial externo. Se verificó que el segundo proceso no termina antes de liberar el primero.

Las dos solicitudes de cierre parten de la misma base revisada NULL: al confirmar la primera, la segunda debe fallar y solicitar nueva preparación, incluso si su fecha es consecutiva. No se le cambia la base automáticamente.

| Pareja, ambos órdenes | CONNECTION_ID | Resultado |
| --- | --- | --- |
| Mismo banco/corte | 1465/1466 y 1467/1468 | Un cierre por banco; segundo rechazado |
| Fechas consecutivas | 1469/1470 y 1471/1472 | Confirma el primero que adquiere banco; el otro rechaza por base/corte; no dos cierres con preparación obsoleta |
| Cargo | 1473/1474 y 1475/1476 | Ambos documentos íntegros; saldo final 950 |
| Abono | 1477/1478 y 1479/1480 | Ambos íntegros; saldo final 1050 |
| Depósito | 1481/1482 y 1483/1484 | Ambos íntegros; saldo final 1100 |
| Transferencia 80 + comisión 5 | 1485/1486 y 1487/1488 | Origen 915/destino 80; locks de bancos ordenados; sin modificar comisión |
| Mesa de cambio | 1489/1490 y 1491/1492 | Banco 900; USD 10 en bóveda nueva; costo promedio 10.000000 |
| Piloto B2A sintético | 1493/1494 y 1495/1496 | Banco 950; versión/autoridad simulation y dos correspondencias; sin habilitación empresarial |

Todos los bancos principales parten de cero y reciben un abono **documentado de fixture** de 1000 antes de la carrera; no se sustituye base por un saldo bancario inventado. La selección de conciliación cubre ese movimiento original. Los flujos de depósito/cambio utilizan bóvedas nuevas y valores monetarios explícitamente hipotéticos, sin llamadas externas ni uso de tasas empresariales reales. No se crean movimientos para compensar diferencias de conciliación.

Cuando conciliación toma banco primero, su saldo_libros es 1000 y el movimiento posterior queda pendiente. Cuando el escritor toma banco primero, saldo_libros incluye su efecto (950/1050/1100/915/900), y el nuevo movimiento no seleccionado queda en_transito. En ambos casos el saldo_extracto sigue siendo 1000, porque esa es la selección efectivamente verificada; no se confunde con saldo_actual posterior.

Los movimientos posteriores conservan fecha, monto, cuenta, moneda y asiento; la conciliación solo modifica estado/referencias de sus fixtures elegibles. Se contrastó cuadre de asientos y neto de mayor por cuenta contra saldo del banco; el caso secuencial verifica también el libro bancario. El nuevo movimiento no reescribe una cabecera cerrada.

Evidencia privada conservada:

```text
storage/app/private/ac04b2b2r-concurrency-4912a8803acfc3e6.json
storage/app/private/ac04b2b2r-pair-*.json (16 archivos)
```

Contiene IDs/PID distintos, orden real de locks, salidas, banco, cabecera, saldo y fotografía de libros. Se comprobaron persistencia final y cierre de procesos/conexiones. No hubo fallos en esta pasada ni retries financieros.

## 8. Integridad de las tres bases

Nueva línea base anterior al ensayo: `storage/app/private/ac04b2b2r-isolation-before.json`. Comparación completa por hashes de filas, columnas y DDL, normalizando contadores AUTO_INCREMENT:

| Base | Tablas | Migraciones | Filas antes/después | Resultado |
| --- | ---: | ---: | ---: | --- |
| salon_erp | 78 | 74 | 225 / 225 | Estructura y contenido conservados |
| salon_erp_bootstrap_check | 78 | 74 | 202 / 202 | Estructura y contenido conservados |
| salon_erp_test | 87 | 81 | 1364 / 1567 | Todas las filas previas conservadas; solo fixtures nuevos |

Las 203 filas adicionales incluyen 16 extractos, 18 bancos de fixture, 32 movimientos, 28 asientos/60 detalles de los abonos iniciales y escritores ensayados, cuatro transferencias, cuatro sesiones, dos operaciones de cambio/dos saldos de moneda, tres períodos y documentación sintética del piloto (dos políticas, 16 bindings, seis auditorías, cuatro autorizaciones, dos operaciones y cuatro correspondencias).

Los 28 asientos corresponden a 16 abonos de preparación y 12 escritores concurrentes; **ninguno es un asiento emitido por conciliación**. No se modificaron cuentas, saldos, movimientos, asientos, extractos o evidencia previos. ID131 y las carreras anteriores permanecen exactamente como en la línea base. Los ensayos secuenciales y regresiones se revierten transaccionalmente; las carreras nuevas conservan evidencia para revisión.

`SALON_TEST_DB_RESET_ALLOWED=0` comprobado en archivo y restaurado en finally por runner/workers. Nunca se habilitó. Sin cambios de credenciales, permisos, APP_KEY, AGENTS.md o dependencias. No se ejecutaron migraciones ni pruebas contra desarrollo/bootstrap.

## 9. Archivos modificados y verificaciones de código

Funcionales:

- `app/Http/Controllers/BancoController.php`: preparación coherente; referencia requerida, banco primero, lecturas actuales, fórmula prospectiva, marcaje/verificación y rollback Throwable.
- `app/Services/FondosService.php`: helper pequeño de última conciliación con modo actual, conservando el método previo.
- `resources/views/banks/conciliacion.blade.php`: únicamente referencia hidden de base.

Pruebas/soporte:

- Nuevos `BankReconciliationTest.php`, `BankReconciliationConcurrencyTest.php`, `BankReconciliationFixtures.php`, `ac04b2b2r-reconciliation-worker.php`.
- `tests/Feature/BancosMesaCambioTest.php`: tres payloads actualizados al contrato explícito.
- `tests/Support/ac04b1-backup.php`: fase de respaldo allowlisted nueva.
- `tests/Support/ac04b1-isolation.php`: nueva fase/línea base y append permitido solo para las tablas de fixtures autorizadas, con filas anteriores intactas.
- `tests/Support/run-ac04b1-tests.php`: grupos focalizados, nuevo respaldo, preflight estructural y guardia del preparado 131.
- Este informe.

No se tocaron ContabilidadService, BankBalanceService, AccountIntegrityService, piloto, políticas/autorizaciones, divisas, fórmulas, rutas, catálogo o migraciones como parte de B2B2R. Los cambios B2B1 previos ya estaban en el árbol y se conservaron.

Sintaxis PHP y git diff --check correctos. Build `npm.cmd run build`: salida 0, Vite 8.1.5, 12 módulos. Advertencia opcional de fontaine ya existente; no se instaló ni cambió configuración. No hubo cambios CSS globales, JS o UI-01; el build generado está ignorado por Git. La vista se verificó por HTTP de Laravel, sin afirmar revisión manual en navegador.

## 10. Comandos ejecutados

Raíz del proyecto; PHP exclusivo `E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe`, con `-d display_errors=0 -d zend.exception_ignore_args=1`:

```text
tests/Support/run-ac04b1-tests.php admin-preflight
tests/Support/ac04b1-backup.php AC04B2B2R
tests/Support/run-ac04b1-tests.php reconciliation-preflight
tests/Support/ac04b1-isolation.php before AC04B2B2R
tests/Support/run-ac04b1-tests.php reconciliation-core
tests/Support/run-ac04b1-tests.php reconciliation-race
tests/Support/run-ac04b1-tests.php reconciliation-regressions
tests/Support/ac04b1-isolation.php after AC04B2B2R
```

El grupo de carreras rechaza el namespace de bancos de esta fase si ya está ocupado. No repetir ciegamente ni borrar sus fixtures para conseguir otra pasada. El core se repitió al añadir la comprobación explícita del segundo cierre vacío; las cifras finales anteriores corresponden a esa versión.

## 11. Diff específico y reversión segura

Se capturaron los seis archivos existentes afectados al iniciar esta fase, antes de editarlos. El patch compara contra ese estado, **sin incluir cambios anteriores B2B1** en helpers compartidos. Incluye además tres actualizaciones del fixture histórico y cuatro archivos nuevos de pruebas/soporte: 11 archivos, documentación excluida.

```text
storage/app/private/ac04b2b2r-review-verified.diff
43377 bytes
SHA256 a4990781699f55abd941677db5d2cd68cc62ca3286fffb29de323674f89d41c0
```

Verificado con `git apply --reverse --check`, sin aplicarlo. El archivo `ac04b2b2r-review-final.diff` previo es un artefacto intermedio con diferencias espurias de codificación de las copias de revisión; **no usarlo para revertir**. El indicado como verified es el patch entregable, con codificación corregida de esas copias; no se alteró el código funcional para ello.

Reversión: revisar el patch verified y volver a comprobar su aplicación inversa antes de retirar código, sin reset global. Eso no deshace documentos confirmados ni fixtures. Conservar extractos/cabeceras/evidencia; no borrar ni restaurar automáticamente el respaldo. Una restauración reemplazaría evidencia posterior y requiere autorización y destino explícitos. No hay DDL que revertir. Sin commits.

## 12. Límites y dependencias para reversibilidad

1. Contrato provisional del prototipo, no aprobación empresarial. No se permiten múltiples extractos del mismo día, rectificaciones históricas o reaperturas.
2. La primera base cero no certifica aperturas reales no documentadas. Un saldo inicial bancario empresarial requiere evidencia/política propia.
3. Historia previa conserva su saldo_libros original, incluso si utilizó la fórmula antigua. No se corrigió ni reclasificó automáticamente.
4. Moneda/referencias heredadas ambiguas se rechazan; no se completan desde el banco por conveniencia. Podría requerirse conciliación documental separada.
5. La exclusión mutua supone que escritores autorizados sigan BankBalanceService; SQL externo puede eludir el protocolo. No se añadieron triggers, UNIQUE o cambios de privilegios.
6. No se habilitaron reversas. Un futuro reversor deberá fijar el mismo banco antes de leer estado de conciliación y respetar la identidad de corte; requerirá su autorización, evidencia, esquema y reglas para conciliados/períodos según B2B0.
7. El consumidor empresarial del cargo sigue cerrado; las dos operaciones del piloto en esta fase son simulation. No se promovieron políticas sintéticas ni habilitó V2 en desarrollo.

AC-04B2B2R termina aquí. No se continúa con reversión bancaria, habilitación empresarial, AC-05, AC-06, H01-B2B o despliegues.
