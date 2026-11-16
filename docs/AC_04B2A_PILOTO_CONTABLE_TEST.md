# AC-04B2A — Cargo bancario configurable en ensayo aislado

Fecha: 2026-11-16. Completado exclusivamente en `salon_erp_test`. No habilita contabilización empresarial ni despliegue. Sin DDL, nuevas migraciones, RefreshDatabase, migrate:fresh, seeders, dependencias o commits.

## 1. Arquitectura y frontera

`AccountingBankChargeSimulation::executeForInternalHarness(int $grantId, array $input, string $contract): object` es el único escritor nuevo. No tiene rutas, botones, controlador público ni comando empresarial. La entrada admite UUID, clasificación explícita `maintenance`/`commission`, importe decimal positivo, fecha contable, referencia y descripción. Reutiliza la normalización y el contenido canónico AC-04B1; rechaza campos autoritativos adicionales.

`AccountingSimulationBoundary` exige simultáneamente CLI, contrato `AC04B2A_INTERNAL_SIMULATION_V1`, bootstrap seguro de pruebas, reset desautorizado, raíz real correcta, entorno testing, única conexión mysql permitida y comprobación SQL efectiva de esquema, cuenta, puerto y roles. Relee al usuario autenticado activo con rol existente alvaro/contador. No basta cambiar APP_ENV o enviar un booleano.

La identidad efectiva verificada fue `salon_erp_test`, `salon_erp_tester@localhost`, TCP `127.0.0.1:3306`, MySQL 8.4.3 y PHP 8.3.30 de Laragon. Cada grupo comprobó USAGE sin privilegios globales y los nueve permisos de esquema previstos; roles NONE y acceso denegado a desarrollo/bootstrap.

Solo se aceptan habilitaciones de propósito `simulation`, entorno testing, evento bancario aprobado técnicamente, banco NIO y ventana vigente, con aprobación padre y hashes válidos. Se conserva cerrado `assertBusinessAuthority()`. Publicar una política enterprise sintética no constituye aprobación empresarial. El informe final cuenta **cero autorizaciones de propósito distinto de simulation**.

## 2. Motor contable y cuentas

Se resuelven conjuntamente `gasto.cargo_bancario` y `fondos.banco` en el ámbito del banco exacto. La cuenta de fondos debe coincidir con `cuentas_bancarias.cuenta_contable_id`. No hay fallback por código, nombre, prefijo o primera cuenta. El piloto rechaza políticas con recursos de otros bancos o artículos; no intenta bloquear dominios adicionales.

`ContabilidadService::contabilizarMovimientoBancario()` conserva su comportamiento con dos argumentos. Su tercer argumento opcional es evidencia tipada `AccountingSimulationPosting`, de constructor privado, que repite las verificaciones internas antes de contabilizar. Esta entrada delimitada utiliza las cuentas resueltas y escribe las referencias nullable ya existentes de la cabecera. Conserva el mismo período, moneda base, importe, dos líneas, signos, validación de cuentas y `validarCuadre()`.

No se creó otro motor. No se cambiaron fórmulas bancarias, tratamiento de sobregiros, FondosService, BankBalanceService, controles de catálogo, publicación, movimientos físicos o resoluciones heredadas de otros eventos. El saldo continúa usando la aritmética y redondeo del escritor bancario existente; el importe del contrato se normaliza exactamente antes de PDO.

## 3. Transacción y orden de bloqueos

Una transacción exterior conserva todos los efectos hasta un único commit físico. Las transacciones anidadas del motor no confirman independientemente el documento.

Orden implementado para una ejecución nueva:

1. Validar frontera, actor, UUID y contenido; consultar si existe un resultado histórico confirmado.
2. Descubrir una única versión candidata vigente y fijar por PK la versión publicada con lectura actual S; comprobar habilitación, aprobación padre, evidencia y ausencia de retirada.
3. Leer bindings del alcance fijado; bloquear el banco X mediante BankBalanceService y releer su saldo.
4. Revalidar publicación, cuentas, recursos y fotografías bajo lecturas actuales. No seleccionar otra versión.
5. Insertar el UUID UNIQUE o leer el existente X tras un conflicto 1062. Banco/cuentas preceden al UUID, igual que en el preparador AC-04B1.
6. Validar el preparado completo y su pertenencia a la misma autorización, actor y contenido. Resolver las dos asociaciones de la versión ya fijada.
7. Crear movimiento y asiento por el motor existente, que valida período abierto y cuentas. Enlazar ambos.
8. Completar referencias de la operación y sus dos correspondencias. El estado se escribe dentro de la transacción antes de las correspondencias por las FK compuestas; ninguna sesión ve una confirmación parcial.
9. Actualizar saldo del banco bloqueado, conciliar documentos, cuentas, moneda e importes y validar el cuadre. Commit exterior.

No se introduce GET_LOCK administrativo en el cargo ni reintentos financieros. El mutex administrativo existente permanece en publicación/retirada. Contención 1205/1213 produce rechazo controlado y rollback.

La selección inicial de candidatos es una lectura de descubrimiento sin locks de recorrido completo; la decisión se revalida por PK con locks mantenidos hasta el commit. Repetir un full scan FOR SHARE después de tomar recursos podía alcanzar un borrador bloqueado por un publicador que esperaba la versión seleccionada. La inmovilidad y ausencia de solapamientos se apoyan en el protocolo AC-03A de publicación, que bloquea las versiones publicadas. Esto no pretende proteger frente a un administrador SQL que escriba políticas incompatibles fuera de ese protocolo.

## 4. Identidad, repetición y trazabilidad

- Mismo UUID, actor, autorización y contenido normalizado: devuelve movimiento y asiento originales sin volver a descontar.
- Mismo UUID con otro contenido/actor/autorización: rechazo.
- UUID nuevo: documento independiente.
- Un preparado no es un éxito financiero. Solo un preparado íntegro puede continuar dentro del arnés; uno incompleto o manipulado se rechaza.
- Dos solicitudes del mismo UUID se serializan y solo una crea efectos. El manejo del INSERT duplicado no vuelve a ejecutar el cargo.
- Un resultado confirmado puede consultarse/repetirse después de retirar su habilitación: se verifica la evidencia histórica y no se resuelven cuentas actuales ni se compara contra el saldo bancario posterior.

Se conservan versión/hash, habilitación exacta, contrato/evento, clasificación, banco/moneda, actor, UUID/hash canónico, fecha contable, movimiento y asiento. Cada línea tiene su fila en `accounting_entry_line_resolutions` con binding, papel, cuenta y referencias compuestas. El ámbito/recurso se obtiene del binding inmutable referenciado. `recorded_at` de las correspondencias registra el instante UTC de aplicación; el de la operación conserva su creación documental si fue preparada antes. Los relojes futuros de los fixtures son exclusivamente sintéticos.

No se modificó ni atribuyó política a ningún asiento previo. Una retirada posterior no reescribe sus líneas. La reversión manual del automático sigue bloqueada; no existe reversión bancaria genérica nueva.

## 5. Respaldo y aislamiento

Nuevo respaldo consistente anterior a las pruebas, sin sobrescribir los anteriores:

```text
E:/salon-erp-backups/salon_erp_test_AC04B2A_20261116_132030_f5b65ea23b33.sql
296534 bytes; 87 tablas; 81 migraciones; 676 filas
SHA256 cd85eb0497ef63bb3a4a1a42373166c0ccc442e36951bb3c28fcebff335a7a93
```

Verificados salida, estructura, tamaño, SHA256, sidecar y fuente sin cambios durante la exportación. Exportación con single-transaction y opciones de rutinas, eventos y triggers mediante el mecanismo seguro existente. La identidad administrativa solo se empleó para respaldo/inspecciones de lectura; los cargos y pruebas usan el tester. No se probó restauración ni se imprimieron credenciales.

Línea base independiente: `storage/app/private/ac04b2a-isolation-before.json`. Comparación final por hashes de todas las filas previas, columnas y DDL, normalizando únicamente contadores AUTO_INCREMENT en el DDL:

| Base | Tablas antes/después | Migraciones antes/después | Filas antes/después | Resultado |
| --- | --- | --- | --- | --- |
| salon_erp | 78 / 78 | 74 / 74 | 225 / 225 | Estructura y contenido conservados |
| salon_erp_bootstrap_check | 78 / 78 | 74 / 74 | 202 / 202 | Estructura y contenido conservados |
| salon_erp_test | 87 / 87 | 81 / 81 | 676 / 1267 | Filas previas conservadas; solo evidencia sintética adicional |

Se comprobó la conservación de las cuentas y los asientos anteriores. Test contiene finalmente 25 operaciones confirmadas, 50 correspondencias y 25 cabeceras atribuidas por el arnés; no son operaciones empresariales. Se conservan también políticas, autorizaciones, bancos y períodos hipotéticos de las distintas ventanas ensayadas. Las pruebas transaccionales secuenciales se revierten; las carreras conservan evidencia intencionalmente.

## 6. Pruebas definitivas

Se ejecutaron estos grupos con el código definitivo, sin recrear esquema:

| Grupo | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| pilot-core | 20 | 153 | Aprobado |
| pilot-race-verified | 1, con seis carreras reales | 152 | Aprobado |
| pilot-regressions | 94 | 512 | Aprobado |
| Total final | **115** | **817** | **Sin fallos** |

Regresiones: AccountIntegrityTest, AccountHierarchyConcurrencyTest, AccountingPolicyTest, AccountingAccountResolverTest, BankBalanceIntegrityTest, AccountingEventDocumentationTest y AccountingEventRollbackGuardTest. Se adaptaron expectativas de conteo global de AC-03B/B1 para admitir nuevos documentos sintéticos legítimos; se conserva la comparación de historia antes/después. No se reejecutaron suites generales H03/H04 o nómina; sus escritores no cambiaron y se verificó preservación estructural/documental.

Cobertura del piloto: cargo equivalente, dos cuentas/asociaciones exactas, demo/incompleta/ambigua/fuera de vigencia, habilitación inexistente, cuenta inactiva/no imputable, enlace bancario incompatible, período cerrado, UUID repetido/divergente/nuevo, preparado manipulado, revocación posterior, fronteras de raíz/configuración, ruta ordinaria sin activación y rechazo de reversión manual. Fallos inyectados tras movimiento, cabecera, primera línea y correspondencias revierten operación, saldo, movimiento, asiento y detalles; también se probó Throwable dentro del motor anidado.

### Carreras de la pasada definitiva

Ventanas hipotéticas nuevas enero–junio de 2031, bancos y UUID nuevos. Evidencia: `storage/app/private/ac04b2a-concurrency-8991675a39a248bf.json` y seis archivos `ac04b2a-pair-*.json`.

| Carrera | CONNECTION_ID independientes | Persistencia comprobada |
| --- | --- | --- |
| Mismo UUID | 1125 / 1126 | Una operación, movimiento 155/asiento 1345; ambos reciben el mismo resultado; saldo 950 |
| Cargos distintos | 1127 / 1128 | Dos cargos y asientos; saldo 900 |
| Cargo / retirada | 1129 / 1130 | Cargo completo antes de retirada; nueva operación rechazada, consulta histórica válida; saldo 950 |
| Cargo / publicación solapada | 1131 / 1132 | Cargo completo; publicación espera y se rechaza; borrador intacto; saldo 950 |
| Cargo piloto / heredado | 1133 / 1134 | Dos cargos íntegros, solo uno atribuido a política; saldo 900 |
| Preparación B1 / ejecución | 1135 / 1136 | Un preparado y su confirmación, mismo ID 140; un movimiento/asiento; saldo 950 |

Barreras del arnés comprueban que el segundo proceso no termina mientras el primero mantiene los locks. Se comprobaron códigos de salida, IDs/PID distintos, documentos persistidos y cierre de procesos/conexiones; no se simuló concurrencia mediante una sola conexión.

### Incidencias intermedias conservadas

Hubo dos pasadas anteriores de cinco carreras en ventanas 2035/2036. En una pasada posterior 2037, las cinco carreras originales aprobaron y la nueva carrera preparación/ejecución falló. La inspección identificó una lectura consistente del preparado anterior a su commit; se eliminó esa dependencia y el preparado ahora se relee X después de fijar recursos y autorización. Se alineó además banco/cuentas antes de UUID con AC-04B1. La pasada definitiva se ejecutó en una ventana nueva, sin reutilizar ni borrar la evidencia anterior.

Queda explícitamente el preparado sintético **ID 131, banco 221**, con referencias de resultado NULL, saldo **1000.00 y cero movimientos bancarios**. No se interpreta como confirmación ni se limpió automáticamente. El fallo no dejó un cargo parcial. Una incidencia inicial del test de reversión fue una URL incorrecta de la prueba; se corrigió a `/asientos/{id}/reversar`, sin cambiar la ruta de la aplicación.

## 7. Equivalencia financiera y reportes

Para C$50.00 sobre C$1000.00: saldo final C$950.00, DEBE gasto C$50.00 y HABER cuenta exacta del banco C$50.00, dos correspondencias. La proyección cuenta/moneda/debe/haber coincide exactamente con el cargo heredado equivalente, cuya cabecera permanece sin política.

Se leyeron las vistas reales de diario, mayor, resultados, balance y libro bancario. El piloto aumenta gastos en 50, reduce activo y saldo del mayor bancario en 50, agrega un asiento equilibrado y muestra saldo corrido 950. El cargo heredado equivalente provoca las mismas diferencias adicionales. No se cambiaron reportes ni se reclasificaron saldos.

## 8. Comandos y archivos

PHP exclusivo: `E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe`. Invocaciones desde la raíz, con `-d display_errors=0 -d zend.exception_ignore_args=1`:

```text
tests/Support/ac04b1-backup.php AC04B2A
tests/Support/ac04b1-isolation.php before AC04B2A
tests/Support/run-ac04b1-tests.php pilot-core
tests/Support/run-ac04b1-tests.php pilot-race
tests/Support/run-ac04b1-tests.php pilot-race-final
tests/Support/run-ac04b1-tests.php pilot-race-current
tests/Support/run-ac04b1-tests.php pilot-race-verified
tests/Support/run-ac04b1-tests.php pilot-regressions
tests/Support/ac04b1-isolation.php after AC04B2A
tests/Support/ac04b2a-audit.php
```

Los modos de carreras rechazan ventanas con evidencia previa; no repetir ciegamente esos comandos. El runner ejecuta PHPUnit focalizado con phpunit.xml, sin caché de resultados y deteniéndose ante fallo. No ejecutar el arnés desde una instalación ordinaria.

Archivos nuevos:

- `app/Services/AccountingBankChargeSimulation.php`.
- `app/Support/AccountingSimulationBoundary.php` y `AccountingSimulationPosting.php`.
- `tests/Feature/AccountingBankChargeSimulationTest.php` y `AccountingPilotConcurrencyTest.php`.
- `tests/Support/AccountingPilotFixtures.php`, `ac04b2a-pilot-worker.php` y `ac04b2a-audit.php`.
- Este informe.

Modificados:

- `AccountingAccountResolver`, `AccountingEventAuthorizationService`: fijación/revalidación interna sin reselección global bajo recursos bloqueados.
- `AccountingEventIdentityService`: constructor canónico compartido y lecturas actuales del resultado dentro de transacción.
- `ContabilidadService`: entrada opcional tipada y referencias de cabecera.
- Tests `AccountingAccountResolverTest`, `AccountingEventDocumentationTest` y fixture `AccountingEventFixtures`.
- Auxiliares `ac04b1-backup.php`, `ac04b1-isolation.php` y `run-ac04b1-tests.php`: fase allowlisted B2A, comprobaciones de respaldo/aislamiento y grupos focalizados.

No cambiaron rutas, controladores, migraciones, configuración, credenciales, APP_KEY, AGENTS.md, publicación AC-03A, AccountIntegrityService, fondos, inventario, nómina ni anulaciones. No hubo cambios de frontend; no fue necesario compilar Vite. Sintaxis PHP verificada y `git diff --check` correcto. `SALON_TEST_DB_RESET_ALLOWED=0` comprobado en archivo; todos los runners lo restablecen en finally y nunca lo habilitaron.

## 9. Diff y reversión

Patch final de código/pruebas, **18 archivos**, excluyendo este informe para evitar autorreferencia:

```text
storage/app/private/ac04b2a-review-final.diff
79846 bytes
SHA256 75bbb49021b15d2b50de81f43f357fd8f1f182abda5026f6936c15c380b07bfc
```

`git apply --reverse --check` comprobado sin aplicarlo. El patch intermedio anterior se conserva y no representa la versión final. Git queda con 10 archivos modificados y nueve nuevos incluyendo este informe; sin commits.

Para retirar código: revisar el patch final, confirmar que no haya trabajo posterior y volver a comprobar su aplicación inversa antes de autorizarla. Conservar esquema y evidencia en uso. Revertir código no deshace los cargos sintéticos persistidos; no borrar evidencia ni restaurar automáticamente. Cualquier recuperación desde dump requiere autorización explícita, destino aislado y verificación posterior; no se ensayó restauración en esta fase. La reversión de la migración B1 sigue bloqueada con evidencia, sin ejecutar DDL.

## 10. Límites para activación empresarial

Se probó un piloto financiero sintético, no una aprobación real. Faltan autorización empresarial verificable, habilitación operativa explícita, plan de despliegue y procedimiento de reversión de dominio. No se decidió versión de documentos retenidos ni se implementó reasignación de saldos, precedencias de ámbitos o factura de reemplazo.

La fijación se verifica bajo el contrato de publicación/inmutabilidad existente; no es protección contra PHP confiable o SQL administrativo que deliberadamente eludan el sistema. No se ampliaron permisos ni se añadieron triggers. Mantener separados los datos hipotéticos y las autorizaciones empresariales.

AC-04B2A termina aquí. No se activa el piloto en rutas normales ni se continúa con AC-05, AC-06, H01-B2B o despliegues. Desarrollo/bootstrap conservan 74 migraciones y V2 no está activado allí.
