# AC-04B2B3A — Devolución acreditada de cargo bancario, solo test

Fecha: 2026-11-16. Implementación y ensayo concluidos exclusivamente en `salon_erp_test`. **No acredita un reembolso real ni una autorización económica empresarial.** No hay rutas, formularios o comandos empresariales nuevos.

## 1. Contrato delimitado

`BankChargeRefundSimulation::execute(originalOperationId, input, contract)` acepta únicamente una devolución completa acreditada de un cargo AC-04B2A confirmado, propósito simulation, evento `bancos.cargo_gasto.nio`, classification maintenance/commission, banco NIO, política/autorización históricas, hash documental y dos correspondencias originales verificables.

Se exige contrato interno `AC04B2B3A_CREDITED_REFUND_SIMULATION_V1` y la frontera existente de test, sin debilitarla: CLI, bootstrap seguro, raíz correcta, testing, única conexión permitida, esquema salon_erp_test, identidad salon_erp_tester@localhost/3306, roles NONE y actor contable activo alvaro/contador. Un booleano, ID de actor o APP_ENV no sustituyen estas comprobaciones.

Entrada permitida: UUID propio, reason_code=`credited_full_refund`, referencia bancaria sintética, referencia de autorización sintética, descripción concreta de evidencia, fecha efectiva y fecha contable. Referencias obligatorias con prefijo `SYNTHETIC-`; no se generan referencias empresariales. Campos desconocidos o autoritativos, incluidos importe, cuenta, moneda o usuario, se rechazan. Esos valores se obtienen del original validado y del actor autenticado.

No se acepta error interno sin acreditación, rectificación de conciliación, reclasificación, devolución parcial, original prepared, movimiento legacy sin operación B2A, abono/transferencia como original, banco USD o traza incompleta. No se intenta reconocer un original por coincidencia de importes, concepto o código de cuenta.

## 2. Esquema aditivo y migración

Nueva migración: `2026_11_16_000003_create_bank_charge_refunds.php`. No se modificaron las 81 anteriores ni se añadieron columnas/índices a tablas históricas. Test queda con **82 migraciones y 89 tablas**.

`bank_charge_reversals` conserva UUID/hash/contenido, operación/movimiento/asiento originales, versión/hash/habilitación de procedencia, banco, importe NIO, motivo, fechas, ejecutor, timestamp UTC, evidencia propia, estado prepared/confirmed y resultados inversos.

`bank_charge_reversal_lines` conserva cada detalle original y su inverso, asientos de ambos, cuenta, moneda y cuatro importes debe/haber. No depende de textos para identificar origen.

Restricciones:

- UUID UNIQUE y original_operation_id UNIQUE; movimiento y asiento originales también UNIQUE. Una sola devolución completa por original, con independencia del UUID.
- Movimiento/asiento inversos UNIQUE.
- FK compuesta a la identidad original AC-04B1: operación, política/hash, habilitación, banco, movimiento y asiento deben pertenecer al mismo resultado.
- FK compuesta al movimiento inverso con asiento/banco, y FK al asiento inverso.
- Líneas enlazadas por FK compuesta a cabecera, detalle/asiento/cuenta originales y detalle/asiento/cuenta inversos; cada detalle solo puede aparecer una vez. Moneda con FK propia.
- **Nueve FK RESTRICT**, comprobadas en MySQL, para borrado/actualización.
- CHECK de UUID/hash/JSON versionado, simulation/NIO, importe positivo, motivo de devolución completo y fechas iguales. Prepared requiere resultados NULL; confirmed exige ambos resultados.
- CHECK de líneas: una sola cara original positiva, y cantidades inversas exactamente intercambiadas.

Las FK no verifican por sí solas todos los valores monetarios o la moneda de un detalle: el servicio contrasta los valores originales, banco/moneda y pertenencias, y AccountIntegrityService compara el espejo completo antes del commit. No se afirma que un CHECK de cabecera garantice dos líneas sin la validación transaccional.

La migración tiene guardia explícita de destino de ensayo, usuario, entorno y servidor. Rechaza destino ocupado/parcial. `down()` solo elimina las dos tablas si ambas están vacías; con evidencia rechaza **antes de DDL**. No se borran filas para permitir rollback.

## 3. Instalación y respaldo anterior al DDL

Antes del esquema se verificaron 81 migraciones, tester restringido, MySQL 8.4.3, PHP 8.3.30 de Laragon, roles NONE, nueve privilegios de esquema más USAGE y denegación de acceso a las otras bases. Se creó respaldo nuevo consistente:

```text
E:/salon-erp-backups/salon_erp_test_AC04B2B3A_20261116_161840_7903dd6d5868.sql
1278551 bytes; 87 tablas; 81 migraciones; 1567 filas
SHA256 65708f2f3b4d79559d34548c637891d80eff6c2eba4d122af5d2071d0b3ad143
```

Exportación single-transaction con rutinas/eventos/triggers. Salida, tamaño, estructura, finalización y SHA256/sidecar correctos; fuente sin cambios durante exportación. Archivo único y respaldos previos conservados. La identidad administrativa solo hizo respaldo/inspecciones de lectura; DDL y pruebas usaron tester. No se imprimieron credenciales ni se ensayó restauración.

La prueba de instalación, sin trait de recreación, ejecutó up → comprobación de restricciones → down vacío → up y registro de la migración nueva. Se detendría ante un fallo, sin limpieza/reparación automática. Fue el ensayo vacío autorizado, no una recreación de las tablas del ERP. Tras crear evidencia se probó que down queda bloqueado antes de cualquier DDL.

## 4. Evidencia y autorización propias

La cabecera guarda evidencia JSON v1 con synthetic=true, economic_authority=false, contrato, actor autenticado, referencia propia de autorización, referencia de acreditación, descripción, hora UTC de registro y hash del contenido. Se comprueba que evidencia, contenido y columnas coincidan. No se almacena PIN, contraseña, cookies, tokens o hashes de credenciales.

Este acto documental **es sintético y propio de la corrección**, no una reutilización de autoridad vigente del cargo. El servidor conserva la habilitación antigua como procedencia y comprueba la integridad de aprobación padre, habilitación, publicación y snapshots originales. No exige que sigan vigentes o sin retirada ahora; una retirada posterior no borra un gasto ni prohíbe consultar su historia.

No se llama a inspect/resolve actuales para escoger cuentas ni se crea una aprobación business. `assertBusinessAuthority()` y `resolveForPosting()` siguen cerrados. El esquema y servicios de autorizaciones AC-04B1/B2B1 no cambiaron.

## 5. Movimiento y asiento espejo

Se crea un movimiento nuevo `tipo=abono`, módulo `bank_charge_refunds`, referencia al documento de devolución, mismo banco/NIO e importe completo original, fecha efectiva acreditada y estado pendiente. No se modifica el movimiento del cargo, aunque ya esté conciliado.

Nuevo método delimitado `ContabilidadService::reversarCargoBancarioSimulado(documentId, movement, contract)`:

1. Revalida frontera y documento preparado, fuente y movimiento inverso.
2. Usa el período destino verificado.
3. Crea asiento automático `RBC-SIM-<documentId>`.
4. Copia cuenta, moneda, centro de costo y referencia de tasa originales, intercambiando exactamente debe/haber.
5. Llama `validarCuadre(inverso, original)`, que reutiliza la validación histórica de AccountIntegrityService.

Ejemplo demostrado:

```text
Cargo:      DEBE gasto 50.00 / HABER banco 50.00
Devolución: DEBE banco 50.00 / HABER gasto 50.00
Saldo:      950.00 → 1000.00
```

No se usa `contabilizarMovimientoBancario(abono, gasto)` ni se relaja su regla de ingresos. No se recalculan costos, tasas, cuentas o importes. Los métodos contables existentes mantienen sus fórmulas y resoluciones.

La cabecera del asiento inverso no afirma haber seleccionado una política nueva: conserva NULL en los campos de selección. Su procedencia exacta está en el documento de devolución y sus FK al original; las asociaciones originales se recuperan por los detalles originales y sus correspondencias AC-04B1. No se copian metadatos para fingir una habilitación vigente.

## 6. Períodos, fechas e inactividad

Contrato conservador del ensayo: **fecha efectiva y contable documentadas iguales**, no futuras, no anteriores al cargo y estrictamente posteriores al último extracto cerrado del banco. No se toma automáticamente la fecha original. Variantes con fechas distintas se rechazan, pendientes de otro alcance.

El período destino debe existir, ser único y estar abierto. El reversor no lo autogenera para eludir validación. Si el período original está cerrado, la fecha destino debe superar su fin y pertenecer a otro período abierto; la prueba cubre original de marzo y devolución de abril. No se reabre ni altera el período/asiento originales.

Cuenta original de gasto inactiva: admitida únicamente como espejo histórico exacto por AccountIntegrityService, sin imputaciones arbitrarias. Banco inactivo: rechazo por BankBalanceService. Banco vinculado a otra cuenta del mayor: rechazo, sin mover saldos a la nueva cuenta. Banco/moneda USD: rechazo.

## 7. Atomicidad y protocolo de locks

Una única transacción exterior:

1. Validar frontera, payload y UUID. Un resultado ya confirmado se consulta como historia autorizada, sin nuevos efectos.
2. Preleer la referencia original como pista; fijar versión histórica S por PK **antes del banco**.
3. Banco X por BankBalanceService; releer operación original X, política/evidencia, movimiento y asiento/detalles/correspondencias originales mediante lecturas actuales. Confirmar que banco y contexto coinciden con los fijados.
4. Último extracto S actual; validar fechas. Períodos destino/original S; cuentas históricas en orden de ID.
5. INSERT de identidad UNIQUE de devolución, sin SELECT de ausencia con gap lock como reserva global. Estado prepared solo dentro de la transacción.
6. Crear movimiento y asiento inversos; completar resultado y dos enlaces por línea. El orden interno satisface las FK compuestas sin exponer confirmación parcial fuera del commit.
7. Aumentar saldo una vez con el importe original y comprobar el documento/resultado completo. Commit.

No se toma política después del banco, no se añade GET_LOCK a la operación y no hay reintentos financieros. Las revalidaciones del motor reutilizan locks ya tomados, sin seleccionar otra política. Se conservan protocolos AC-02/03/04B0 y banco → extracto → movimientos de B2B2R.

Excepción/Throwable en cualquier fase revierte todo hasta el nivel transaccional de entrada. Timeout/deadlock producen rechazo sin retry. El resultado dentro de transacción usa lecturas actuales también para líneas/movimientos, evitando un snapshot anterior al commit de una solicitud competidora.

## 8. Idempotencia e historia

- Mismo UUID/contenido normalizado/actor/original: mismo documento, movimiento y asiento, sin repetir saldo.
- Mismo UUID con otro contenido/original/actor: rechazo.
- Otro UUID para el mismo original: rechazo por unicidad; no segunda devolución.
- Consulta posterior: `confirmedResult(uuid, contract)` exige actor autorizado y propietario documental, hashes y dos líneas completas. No exige habilitación actual o banco activo para volver a leer un resultado ya confirmado.
- Un prepared no es éxito y no existe un flujo de continuación/confirmación empresarial de preparados de devolución.

No hay devoluciones parciales ni corrección de la propia devolución. Referencias de otra reversa estructurada del original son motivo de rechazo. No se intenta deducir correcciones externas por similitud de importes o conceptos.

## 9. Coordinación con conciliación y carreras MySQL

Cinco parejas nuevas, diez procesos/conexiones independientes. Las barreras se detienen **después del SELECT banco X real**, no con un lock ficticio que sustituya al servicio. Se comprobaron espera, IDs/PID distintos, resultados persistidos, cuadre por cuenta, cierre de procesos y conexiones.

Cada fixture tiene banco nuevo inicialmente cero, abono documentado sintético de 1000, cargo configurable 50 y extracto inicial 950 cerrado el día 11. Políticas/ventanas nuevas mayo–septiembre 2034; no se reutilizó evidencia anterior.

| Carrera | CONNECTION_ID | Resultado persistido |
| --- | --- | --- |
| Mismo UUID | 1681 / 1682 | Ambos reciben documento 13/movimiento384/asiento1596; una devolución; saldo1000 |
| UUID distintos, mismo original | 1683 / 1684 | Uno confirma y otro rechaza; saldo1000 |
| Devolución día15 antes de conciliación día15 | 1685 / 1686 | Ambos confirman; devolución no seleccionada pasa a en_transito; nuevo extracto950/libros1000 |
| Conciliación día15 antes de devolución día15 | 1687 / 1688 | Cierre confirma; devolución se rechaza por corte ya cerrado; saldo950, sin documento ni efectos parciales |
| Conciliación día15 antes de acreditación día16 | 1689 / 1690 | Ambos confirman; devolución pendiente para un corte posterior; saldo1000; extracto anterior/libros950 conservados |

El cargo original sigue conciliado en los cinco casos y el extracto inicial permanece idéntico. La devolución no se marca automáticamente conciliada. Su eventual selección en un cierre posterior también se probó secuencialmente.

Evidencia:

```text
storage/app/private/ac04b2b3a-concurrency-b62a8c82fed8a9bd.json
storage/app/private/ac04b2b3a-pair-*.json (cinco parejas)
```

No hubo fallos ni repetición de carreras sobre ventanas ocupadas. El grupo rechaza bancos del namespace AC04B2B3A ya existentes para evitar una repetición ciega.

## 10. Pruebas y aserciones

| Grupo | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| refund-install | 1 | 24 | Instalación/rollback vacío/reinstalación aprobados |
| refund-core | 25 | 437 | Aprobado |
| refund-race | 1, cinco carreras | 212 | Aprobado |
| refund-guard | 1 | 3 | Rollback con evidencia bloqueado antes de DDL |
| refund-regressions | 145 | 934 | Aprobado |
| Total | **173** | **1610** | **Sin fallos** |

El core cubre espejo de 50, relaciones por línea, saldo, original preservado, gasto inactivo, banco inactivo/cambiado/USD, tipo de movimiento incompatible, prepared, referencia legacy/inexistente, evidencia incompleta, motivo distinto, fechas futuras/distintas, campo importe manipulado, documento original adulterado, destino cerrado, original cerrado/destino abierto, original conciliado y autorización retirada.

Fallos inyectados después de movimiento, cabecera, primera línea, enlaces antes de saldo y saldo antes de commit dejan iguales documento, líneas, movimiento, asiento y banco. Se usan Error/Throwable y comparación de filas, no solo un código de salida.

Se verificaron FK/RESTRICT, CHECK de importes inversos y rechazo de referencias inválidas. El original legacy no se acepta como simple movimiento: debe existir y verificarse la operación B2A; las FK compuestas de los contratos previos, cubiertas también por regresiones B1, impiden atribuir política a un asiento legacy sin esa identidad.

Se comprobaron diario, resultados, balance, mayor y libro. Resultados reduce el gasto exactamente50 y balance aumenta activo50; el libro muestra1000. En carreras, neto del mayor bancario coincide con saldo y debe/haber cuadra en cada asiento. El automático inverso sigue rechazado por la reversión manual AC-02.

Regresiones: AccountIntegrityTest, AccountHierarchyConcurrencyTest, AccountingPolicyTest, AccountingAccountResolverTest, BankBalanceIntegrityTest, AccountingEventDocumentationTest, AccountingEventRollbackGuardTest, AccountingBankChargeSimulationTest, AccountingAdministrationTest y BankReconciliationTest. No se ejecutó la suite general, Composer test ni carreras antiguas. Las pruebas transaccionales se revierten; las carreras nuevas conservan evidencia.

El core se repitió al reforzar la comparación del importe preparado contra el original y añadir rechazos de banco USD/tipo incorrecto; cifras finales indicadas arriba. Los locks y protocolo de carreras no cambiaron. Sintaxis PHP y git diff --check correctos. Sin frontend nuevo; no se necesitó build Vite en esta fase.

## 11. Tres bases e historia conservada

Nueva línea base previa al DDL: `storage/app/private/ac04b2b3a-isolation-before.json`. Comparación final completa de hashes de filas, columnas y DDL, normalizando AUTO_INCREMENT:

| Base | Tablas antes/después | Migraciones | Filas antes/después | Resultado |
| --- | --- | --- | --- | --- |
| salon_erp | 78 / 78 | 74 / 74 | 225 / 225 | Estructura/contenido conservados |
| salon_erp_bootstrap_check | 78 / 78 | 74 / 74 | 202 / 202 | Estructura/contenido conservados |
| salon_erp_test | 87 / 89 | 81 / 82 | 1567 / 1739 | Solo esquema autorizado y fixtures nuevos; filas previas intactas |

Se conservan ID131, sus resultados NULL y las evidencias anteriores. No se alteraron extractos, políticas, cuentas, operaciones, asientos o movimientos previos. Los nuevos datos persistentes incluyen cinco cargos sintéticos originales con sus fixtures, **cuatro devoluciones confirmadas y ocho enlaces inversos**, ocho extractos nuevos y la nueva fila de migración. El caso de devolución rechazada tras cierre no dejó cabecera preparada ni abono.

La suma de172 filas nuevas comprende bancos5, períodos5, políticas5/bindings40/auditorías15/autorizaciones10/operaciones5/correspondencias10, asientos14/detalles28/movimientos14, devoluciones4/enlaces8, extractos8 y migración1. No son aprobaciones empresariales ni movimientos de bancos reales.

`SALON_TEST_DB_RESET_ALLOWED=0` comprobado en archivo y restaurado en finally de runners/workers. Nunca se habilitó. Sin RefreshDatabase, migrate:fresh, modificaciones de desarrollo/bootstrap, permisos, credenciales, APP_KEY, AGENTS.md o dependencias. V2 no desplegado.

## 12. Archivos y comandos

Nuevos: migración de dos tablas; `app/Services/BankChargeRefundSimulation.php`; tests `BankChargeRefundInstallationTest`, `BankChargeRefundTest`, `BankChargeRefundConcurrencyTest`, `BankChargeRefundRollbackGuardTest`; fixture `BankChargeRefundFixtures`; worker `ac04b2b3a-refund-worker.php`; este informe.

Modificados en esta fase: ContabilidadService solo con método de espejo delimitado; auxiliares ac04b1-backup.php, ac04b1-isolation.php y run-ac04b1-tests.php para fase/guardias/grupos de 82 migraciones. Los cambios previos B2B1/B2B2R del árbol se conservaron y no se incluyen como trabajo nuevo aquí.

PHP exclusivo `E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe`, opciones `-d display_errors=0 -d zend.exception_ignore_args=1`, desde raíz:

```text
tests/Support/run-ac04b1-tests.php reconciliation-preflight
tests/Support/ac04b1-backup.php AC04B2B3A
tests/Support/run-ac04b1-tests.php refund-preflight
tests/Support/ac04b1-isolation.php before AC04B2B3A
tests/Support/run-ac04b1-tests.php refund-install
tests/Support/run-ac04b1-tests.php refund-core
tests/Support/run-ac04b1-tests.php refund-race
tests/Support/run-ac04b1-tests.php refund-guard
tests/Support/run-ac04b1-tests.php refund-regressions
tests/Support/ac04b1-isolation.php after AC04B2B3A
```

No repetir install o race sobre el estado ocupado. No hubo comandos administrativos de migración sobre otra base ni commits.

## 13. Diff y reversión segura

Patch específico contra los archivos capturados al inicio, sin revertir modificaciones de fases anteriores; 12 archivos de código/pruebas, documentación excluida:

```text
storage/app/private/ac04b2b3a-review-final.diff
63617 bytes
SHA256 9c8e6a963d90180ea13e43e47223086bfbf139f98a9bf5a655a4254876b5876f
```

`git apply --reverse --check` comprobado, sin aplicarlo. Revisar de nuevo antes de retirar código y conservar esquema/evidencia en uso. No hacer reset global, borrar documentos ni ejecutar down con evidencia. La prueba del guardia impide ese down antes de DDL.

Restaurar el respaldo eliminaría también evidencia posterior; requiere autorización y destino explícitos. No se probó ni ejecutó restauración. Retirar el código no constituye una devolución económica inversa ni permite borrar la historia.

## 14. Riesgos y cierre

- Solo acreditación completa sintética. No se demuestra recepción real desde el banco, ni aprobación económica del contador por una referencia de fixture.
- Fechas efectivas/contables distintas, corrección interna sin fondos, reclasificación, rectificación de extractos y devolución parcial permanecen bloqueadas.
- Banco inactivo o cambiado de cuenta exige una decisión fuera de este reversor; no hay bypass o migración automática de saldos.
- El esquema preserva referencias; no es inmune a SQL administrativo que falsifique toda la evidencia. No hay triggers ni permisos nuevos.
- La guardia de migración/escritor está limitada al ensayo actual. Su futura implantación requiere plan autorizado de respaldo, compatibilidad y autorización empresarial; no debe activarse quitando guardias.
- No existe reversión de la propia devolución ni una ruta bancaria genérica. El servicio solo reconoce originales del evento B2A delimitado.

Se termina AC-04B2B3A sin habilitación empresarial, AC-05, AC-06, H01-B2B ni despliegues.
