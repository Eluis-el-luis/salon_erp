# AC-04B0 — Integridad concurrente del saldo bancario

Fecha: 2026-11-16. Implementación y pruebas focalizadas exclusivamente en `salon_erp_test`.

## 1. Resultado

Se corrigieron los escritores bancarios existentes para bloquear y releer las cuentas antes de utilizar su saldo. Una operación con varias cuentas toma los IDs únicos en orden ascendente. Se conservan las transacciones exteriores de los controladores, los documentos y el motor ContabilidadService.

Pasaron **75 pruebas y 619 aserciones**, incluyendo un caso PHPUnit con **siete carreras MySQL**, catorce solicitudes controladas y conexiones/procesos independientes. No se ejecutó RefreshDatabase, DDL, migrate:fresh, Composer test ni una suite general.

No se activaron asociaciones contables ni el piloto AC-04. No se cambiaron fórmulas de divisas, importes, costos, cuentas, impuestos, comisiones, valoración, inventario, nómina, cierres o conciliaciones confirmadas. No se realizaron commits.

## 2. Escritores reales y riesgos

Se buscaron referencias a `cuentas_bancarias`, `CuentaBancaria`, `saldo_actual`, actualizaciones directas y operaciones increment/decrement en aplicación y seeders. Se siguieron los consumidores del saldo y las transacciones de cada camino.

| Escritor | Comportamiento anterior | Corrección |
| --- | --- | --- |
| `BancoController::registrarMovimientoDirecto` | Lee modelo, crea movimiento/asiento y calcula saldo desde esa copia sin bloqueo exclusivo previo. Incluye cargo y abono. | Obtiene el modelo actual bajo X antes de crear efectos o calcular saldo. |
| `BancoController::depositar` | Lee banco sin lock, procesa caja/divisas/asiento y suma al modelo leído. | Bloquea banco antes de leer/consumir fondos o divisas; usa ese modelo para sumar. |
| `BancoController::transferir` | Resuelve origen/destino sin locks bancarios y valida disponibilidad sobre esas copias. | Resuelve todos los bancos, elimina duplicados y bloquea por ID antes de validar disponibilidad o calcular saldos. |
| `MesaCambioController::store` → `registrarPatasBancarias` | Resuelve bóvedas bancarias sin locks y resta/suma sus saldos después del asiento. | Bloquea todos los bancos participantes en orden estable antes de validar egreso, modificar USD o contabilizar. Las patas utilizan esos mismos modelos. |
| `BancoController::asegurarCatalogoBancario` | Crea cuentas con saldo cero; completa vínculos al mayor en cuentas existentes. | Sin cambios. Su actualización de vínculo solo guarda atributos modificados, no reasigna el saldo cargado. La creación inicial no es una salida/entrada monetaria. |

No se encontraron escritores adicionales de **saldo bancario** por increment/decrement. `DivisaService` modifica `saldos_moneda`, no `cuentas_bancarias`; conserva su implementación y sus locks propios. `FondosService` lee/resuelve bancos y calcula disponibilidad, pero no escribe su saldo.

El riesgo de sobrescritura se confirma por inspección del camino anterior: dos modelos podían leer 1.000; cargo 50 calculaba 950 y cargo 70 calculaba 930, dejando 930 en vez de 880. No se ejecutó código anterior sobre datos para afirmar una reproducción previa. La versión corregida sí se sometió a carreras deterministas y se comprobaron sus resultados persistidos.

Se descartó como escritor del saldo existente el backfill de vínculo bancario: Eloquent no guarda automáticamente todos los atributos de una instancia, sino los modificados. No se asumió lo mismo de las asignaciones explícitas de saldo de los cuatro caminos anteriores.

## 3. Protocolo compartido

Nuevo servicio pequeño: `BankBalanceService::lockAccounts(array $ids)`.

1. Exige una transacción activa. No abre una independiente, no hace commits y no introduce reintentos.
2. Exige IDs enteros positivos, elimina duplicados y los ordena numéricamente.
3. Obtiene cada cuenta mediante `SELECT … FOR UPDATE`, una por una en ese orden. Es una lectura actual de InnoDB; no reutiliza el saldo de una instancia anterior.
4. Carga sus relaciones y comprueba cuenta activa y moneda reconocida NIO/USD. No convierte importes ni inventa una moneda para referencias incompletas.
5. Devuelve modelos bloqueados indexados por ID; los escritores utilizan esos modelos para disponibilidad, cálculo y persistencia.

Los locks permanecen hasta el commit/rollback exterior. No hay GET_LOCK global ni bloqueo de todo el catálogo bancario. No se confía exclusivamente en el bloqueo implícito del UPDATE final: para entonces el cálculo sobre una copia vieja ya sería incorrecto.

### Orden relativo con otros recursos

En estos escritores se adquieren primero todos los bancos por ID; después los recursos existentes de divisas cuando intervienen, y luego los recursos contables de sus métodos actuales. La mesa no toma primero USD y después banco. Las transferencias no toman primero el origen y después el destino según dirección: toman ambos en orden numérico antes de operar.

No se cambió el orden interno del motor contable: resuelve períodos, valida contrapartida cuando procede, crea líneas y ejecuta `validarCuadre()`. Este invoca AccountIntegrityService, relee el período bajo S y valida cuentas. No se afirma que todos los métodos antiguos tengan un único orden universal período/cuenta; se conservaron sus secuencias y sus locks compatibles existentes.

Los locks de cuentas contables usados aquí son compartidos, y la administración AC-02 no adquiere bancos después de bloquear todo el catálogo para modificar su saldo. Publicación/inspección AC-03 pueden esperar por un banco, pero estos escritores no solicitan locks de políticas: no se introdujo la inversión banco → política identificada para el futuro piloto.

H02/H03/H16 y los escritores físicos de inventario no se tocaron. Esta entrega no aprueba un futuro orden de locks para contabilizar con políticas: AC-04B1 deberá diseñar la fijación de versión antes de integrar ese recurso.

### Disponibilidad y semántica conservadas

- Transferencias: comprobar nuevamente saldo de origen, incluyendo comisión, sobre el modelo bloqueado. Una transferencia posterior ve el saldo ya confirmado por la primera.
- Mesa de cambio: mantener las validaciones de NIO y las reglas USD existentes; la comprobación bancaria usa el modelo actual bloqueado.
- Depósitos: mantener moneda de banco compatible con el depósito y disponibilidad de caja/divisas existente.
- Cargo directo: sigue representando un débito que el banco ya realizó. El flujo anterior permitía que dejara saldo negativo; **no se añadió una política de rechazo por fondos insuficientes a esos cargos**. Hay una prueba explícita que conserva ese comportamiento.

La disponibilidad y concurrencia de efectivo entre **cajas** no se rediseñaron. Serializar un banco no demuestra que dos operaciones sobre bancos distintos no puedan competir por la misma caja. Tampoco se cambió el contrato de autorización de operaciones a uno por propietario de cuenta: siguen aplicando los roles bancarios existentes.

## 4. Atomicidad

El controlador sigue siendo dueño de la transacción exterior. Las transacciones anidadas de ContabilidadService no confirman independientemente el movimiento. Artículo físico/inventario no participa en este cambio.

Se inyectaron fallos después de crear el movimiento y antes del asiento, después del asiento y antes de actualizar banco, y al crear la segunda línea. Para depósitos, transferencias y mesa también se inyectó fallo de actualización bancaria después de los pasos previos. Se verificó rollback de saldo, movimientos, transferencias, operación de cambio, saldo USD, asiento y detalles según el caso.

No se añadieron cuentas puente, asientos compensatorios ni controles de excepción que silencien los fallos. No se alteraron números de documento, referencias históricas, signos o normalización monetaria.

## 5. Carreras MySQL verificadas

El arnés usa dos procesos PHP y conexiones MySQL diferentes. El primero toma los locks y espera en una barrera; el segundo anuncia el intento y entra en el controlador real. Se comprueba que no termina mientras el primero mantiene el bloqueo. Al liberarlo, ambas operaciones finalizan o se produce un rechazo de negocio controlado.

El primer proceso mantiene una transacción exterior de ensayo; los controladores conservan su transacción propia anidada. Se comprueba que no la cierran y se confirma una sola vez al terminar el controlador. El segundo ejecuta una sola solicitud: no hay reintentos. La contención no se simula únicamente mediante mocks o conexiones intercambiadas en un mismo proceso.

| Carrera | Saldo inicial A/B | Solicitudes y resultado persistido | Saldo final A/B |
| --- | --- | --- | --- |
| Cargo–cargo | 1.000 / 1.000 | Cargos 50 y 70; ambos confirmados. | **880 / 1.000** |
| Cargo–depósito | 1.000 / 1.000 | Cargo 50 y depósito de caja 100; ambos confirmados. | **1.050 / 1.000** |
| Cargo–transferencia | 1.000 / 1.000 | Cargo 50; transferencia A→B 80 + comisión 5; ambas confirmadas. | **865 / 1.080** |
| Transferencias inversas | 1.000 / 1.000 | A→B 80 y B→A 70; orden de locks ascendente en ambas. | **990 / 1.010** |
| Cargo–mesa | 1.000 / 1.000 | Cargo 50; compra de USD10 a tasa 10 desde A NIO hacia caja; ambas confirmadas. | **850 / 1.000** |
| Transferencia–transferencia insuficiente | 100 / 1.000 | Primera A→B 80 confirma; segunda por 80 se rechaza. | **20 / 1.080** |
| Cargo–transferencia insuficiente | 100 / 1.000 | Cargo 50 confirma; transferencia por 80 se rechaza. | **50 / 1.000** |

Se comprobaron códigos de salida, PID y CONNECTION_ID distintos, orden efectivo de locks, número exacto de movimientos/asientos/transferencias y saldos releídos tras terminar los procesos. Los errores de negocio esperados son dos; no son fallos de PHPUnit.

Además del cuadre total, se comparó por cuenta bancaria: variación del saldo = neto de sus movimientos = neto debe–haber de su subcuenta contable en los asientos de esas operaciones. En mesa se comprobó también USD10 y costo promedio 10 de la nueva caja, sin modificar su fórmula.

Los catorce procesos terminaron, se cerraron sus pipes y las conexiones se desconectaron en `finally`. Los marcadores de IPC con IDs de conexión fueron temporales; sus desigualdades y orden se comprobaron mediante aserciones. No se presenta un listado durable de CONNECTION_ID como si se hubiera conservado. El manifiesto privado `storage/app/private/ac04b0-fixtures-*.json` conserva los catorce IDs de bancos de ensayo para revisar los documentos persistidos.

## 6. Pruebas y regresiones

PHP exacto: `E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe`.

Se añadieron modos `bank-core` y `bank-race` al runner focalizado existente. Cada ejecución hace preflight y usa PHPUnit con `phpunit.xml`; no Composer ni Artisan test.

| Grupo final | Pruebas aprobadas | Aserciones |
| --- | ---: | ---: |
| `BankBalanceIntegrityTest` | 15 | 133 |
| `BankBalanceConcurrencyTest` — siete carreras | 1 | 238 |
| AC-02, incluida carrera de jerarquía | 15 | 101 |
| AC-03A, contrato/publicación | 18 | 83 |
| AC-03B, resolutor técnico | 26 | 64 |
| **Total de casos distintos** | **75** | **619** |

Los funcionales cubren cargo y abono, depósito/transferencia con comisión, insuficiencia, cuenta inactiva, rechazo de moneda cruzada, recarga de modelo obsoleto, orden único de IDs, autorización por sesión/roles, rollback de los cuatro caminos, depósito USD a valor libro y compra de divisas. No se creó una política para probar ninguna de esas operaciones bancarias.

Los casos funcionales usan DatabaseTransactions; el caso de carreras conserva sus documentos confirmados en nuevas cuentas sintéticas. No usa RefreshDatabase y no elimina evidencia financiera para limpiar.

No se ejecutaron `BancosMesaCambioTest` completo ni sus seeders/RefreshDatabase. Sus escenarios directamente relevantes se reprodujeron mediante fixtures focalizados nuevos. No se repitieron instalación/rollback o carreras de publicación sintéticas AC-03 con intervalos ocupados. H03/H04 se preservan por ausencia de cambios y comparación estructural/histórica, no por una nueva ejecución de todas sus suites.

### Incidencias del arnés

El primer intento funcional falló al crear un período con timestamp 2086, fuera del rango TIMESTAMP del servidor. Se corrigió únicamente el fixture a 2030; las carreras usan 2031. No se amplió el esquema ni se cambiaron fechas de registros anteriores.

El comparador de aislamiento inicialmente rechazó las filas nuevas de `bancos` porque faltaban en su allowlist del ensayo. El fixture crea BAC/LAFISE si no existen. Se añadió esa tabla exclusivamente a la lista de **adiciones** permitidas; se mantiene la comprobación exacta de todas las filas previas y no se relajó su protección contra actualización/borrado.

Tras esas correcciones pasaron las verificaciones finales. Lint de los nueve archivos PHP afectados y `git diff --check` sin errores.

## 7. Respaldo, guardias y estado de las bases

Preflight: PHP 8.3.30, MySQL 8.4.3, TCP 127.0.0.1:3306, esquema exacto `salon_erp_test`, cuenta efectiva `salon_erp_tester@localhost`, roles NONE, nueve privilegios exactos limitados al esquema y rechazo de acceso a desarrollo/bootstrap. Las ochenta migraciones coinciden con los archivos del proyecto.

Respaldo existente verificado en cada grupo:

`E:/salon-erp-backups/salon_erp_test_20261112_074028_3813668.sql`

SHA256: `8778320250e84322267a7d99bf86e18f70c099d0117ea5b73dad67c2c32aa531`.

No se sobrescribió ni restauró. Es un respaldo previo a las últimas fases contables; comprobar su SHA no lo convierte en respaldo del estado actual de ochenta migraciones. No usarlo para deshacer automáticamente esta entrega o recuperar su evidencia reciente.

Se capturó una línea base nueva e independiente: `storage/app/private/isolation-AC04B0-before.json`. El comparador administrativo solo ejecuta lecturas para las tres bases; migraciones/pruebas no se ejecutan con esa identidad.

| Base | Antes: tablas / filas / migraciones | Después | Resultado |
| --- | --- | --- | --- |
| `salon_erp` | 78 / 225 / 74 | 78 / 225 / 74 | Contenido y estructura exactos sin cambios. |
| `salon_erp_test` | 84 / 574 / 80 | 84 / 660 / 80 | Todas las filas previas y estructura conservadas; 86 filas nuevas de ensayo. |
| `salon_erp_bootstrap_check` | 78 / 202 / 74 | 78 / 202 / 74 | Contenido y estructura exactos sin cambios. |

Las adiciones persistidas son dos bancos de fixture, catorce cuentas, siete cajas, un período, cinco transferencias, diecisiete movimientos bancarios, doce asientos, veintiséis detalles, una operación de cambio y un saldo USD. Son datos de prueba, no saldos empresariales iniciales. Los saldos iniciales de las cuentas/cajas son bases hipotéticas de fixture; la conciliación comprueba sus variaciones, no los presenta como apertura contable real.

En test solo se exceptúa el avance de AUTO_INCREMENT producido por fixtures revertidos y las adiciones permitidas del ensayo. Se compararon también las filas de políticas y auditorías AC-03 previas: permanecen intactas. No hubo tablas nuevas, migraciones o backfill.

`SALON_TEST_DB_RESET_ALLOWED=0` se mantuvo y se comprobó/restableció mediante el `finally` del runner. Nunca se habilitó recreación. No se modificaron credenciales, permisos, APP_KEY o AGENTS.md.

Comandos ejecutados, anteponiendo el PHP indicado y `-d zend.exception_ignore_args=1`:

```text
tests/Support/run-ac03a-tests.php preflight-current
tests/Support/isolation-snapshot.php before AC04B0
tests/Support/run-ac03a-tests.php bank-core
tests/Support/run-ac03a-tests.php bank-race
tests/Support/run-ac03a-tests.php ac02
tests/Support/run-ac03a-tests.php core
tests/Support/run-ac03a-tests.php resolver
tests/Support/isolation-snapshot.php after AC04B0
```

Se repitieron el grupo funcional tras ajustar/ampliar sus pruebas y el comparador tras corregir su diagnóstico/allowlist. La carrera se ejecutó una vez, con una solicitud por worker, sin reintentos automáticos.

## 8. Archivos modificados y compatibilidad

Producción:

- Nuevo `app/Services/BankBalanceService.php`: únicamente adquisición/validación de locks bancarios.
- `app/Http/Controllers/BancoController.php`: obtener los bancos bloqueados en los tres escritores; conservar sus cálculos y asientos.
- `app/Http/Controllers/MesaCambioController.php`: obtener ambos bancos bloqueados antes de operar; conservar cálculos y métodos de divisas/patas.

Pruebas:

- Nuevos `tests/Support/BankBalanceFixtures.php`, `tests/Support/ac04b0-bank-worker.php`.
- Nuevos `tests/Feature/BankBalanceIntegrityTest.php`, `tests/Feature/BankBalanceConcurrencyTest.php`.
- `tests/Support/run-ac03a-tests.php`: dos grupos focalizados.
- `tests/Support/isolation-snapshot.php`: fase y huellas de filas previas de AC04B0; mensajes de diagnóstico limitados a nombres de tabla.
- Este informe.

No cambiaron ContabilidadService, FondosService, DivisaService, AccountIntegrityService, AccountingPolicyService, AccountingAccountResolver, inventario/anulaciones, modelos financieros históricos, rutas, permisos, cierres, configuración o migraciones. No se llamó al resolutor para contabilizar.

`docs/AC_04A_PREPARACION_EVENTO.md` ya existía sin seguimiento al comenzar y se dejó intacto. Git no se añadió al índice ni se realizaron commits.

## 9. Riesgos restantes y límites para AC-04B1

1. El piloto configurable sigue sin activación: faltan clasificación, aprobación empresarial, habilitación y trazabilidad de AC-04A. El recurso política no se adquirió aquí; no insertar su lock después del banco sin resolver el orden identificado.
2. Las reglas de caja/efectivo compartido y los escritores de `saldos_moneda` fuera del caso bancario no fueron rediseñados. No se afirma seguridad de todas las combinaciones caja–caja o bancos distintos contra una misma caja.
3. La transferencia USD con origen bancario conserva su limitación previa si no dispone de tasa de valoración exigida por ContabilidadService. Los cargos/abonos USD conservan su tratamiento anterior; esta fase no rediseña su conversión contable. No usar la integridad del contador bancario como prueba de corrección de todas las políticas multimoneda.
4. No se probaron todas las combinaciones USD, una carrera de cierre fiscal ni operaciones de conciliación concurrentes. No se cambió una conciliación confirmada. Se conservaron validación de períodos y protecciones AC-02.
5. El bloqueo evita actualizaciones perdidas; no es idempotencia de negocio ante dos POST iguales. Los cargos directos repetidos siguen siendo dos operaciones distintas según el contrato previo. El arnés no reintenta y comprueba exactamente los documentos esperados; no promete que el endpoint ya tenga UUID idempotente.
6. Pueden existir bloqueos/timeout por contención. No se añadieron reintentos de documentos financieros ni se promete ausencia universal de deadlocks. El protocolo de bancos en orden estable y las carreras verificadas resuelven el alcance probado.
7. Datos heredados bancarios ya inconsistentes no se conciliaron ni ajustaron automáticamente. El cambio solo protege escrituras posteriores; no crea asientos para diferencias antiguas.

## 10. Diff y reversión segura

Patch específico de código y pruebas: `storage/app/private/ac04b0-review.diff`, nueve archivos, 38.831 bytes.

SHA256: `1f48e5d1703634cf2f2d4cc69d5da6c5f0d8917655c9d337d243845cefa34d20`.

Se comprobó `git apply --reverse --check` sin aplicarlo. El patch excluye este informe y el documento AC-04A preexistente. No contiene credenciales.

No hay migración que revertir. Una retirada de código debe revisarse con el patch y excluir escritores mientras se despliega; restaurar el código anterior reintroduciría el riesgo de concurrencia. No borrar los movimientos/asientos sintéticos, restaurar automáticamente un dump o alterar conciliaciones para revertir pruebas. Conservar los datos de ensayo y sus IDs para revisión. No tocar cambios ajenos ni usar un reset global.

AC-04B0 termina aquí. No se inicia AC-04B1, AC-04B2, AC-05, AC-06 o H01-B2B y no se despliega V2.
