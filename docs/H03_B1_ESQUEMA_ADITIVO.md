# H03-B1 — Esquema aditivo, sin activación de escritores

## Alcance y compatibilidad

Dos migraciones nuevas; ninguna migración histórica, modelo, controlador, servicio, configuración ni credencial modificados. Los campos nuevos son nulos por defecto. Los modelos existentes no los incorporan a fillable ni los usan para calcular stock. Las asignaciones no tienen endpoint ni escritor de aplicación. No hay observadores, backfills o conversiones históricas.

Versión NULL = no clasificado/heredado, no implica saldo cero. Versión 2 = representación nueva explícita. No se acepta una versión arbitraria. Los escritores actuales, incluidos los de artículos recién creados, continúan generando NULL hasta H03-B2.

Reglas aprobadas para la activación futura: cerrados + remanente del único envase abierto; 0 <= remanente <= capacidad; no convertir automáticamente un abierto completo en cerrado. Prioridad del abierto y después cerrados FIFO: la prioridad del abierto es una excepción operativa al FIFO estricto. Las medidas son nativas; no se convierten ml/gr/oz ni se interpreta oz como dimensión inequívoca. Estas reglas aún no cambian los algoritmos actuales.

## Migraciones y columnas

1. `2026_11_14_000001_add_fractional_inventory_representation.php`:
   - items y stock_ledger_entries: inventory_representation_version TINYINT UNSIGNED NULL.
   - lotes: mismo marcador; volumen_abierto DECIMAL(8,2), volumen_entrada DECIMAL(15,2), capacidad_envase DECIMAL(8,2), unidad_nativa VARCHAR(10), todos NULL.
   - open_item_guard: columna generada almacenada, NULL salvo lote versión 2 con remanente positivo, en cuyo caso contiene item_id. Índice UNIQUE permite muchos NULL, pero un solo abierto conocido por artículo.
   - CHECK condicionados: los datos heredados no se reinterpretan. Versión 2 exige cantidades cerradas enteras/no negativas, metadatos completos, capacidad positiva, remanente entre cero y capacidad, unidad ml/gr/oz y existencia del lote no superior a su volumen físico inicial. La fórmula de disponibilidad no excede 99.999.999.999,99 unidades nativas, compatible con saldo_volumen DECIMAL(15,4).
   - En items versión 2 se exige is_fractionable, capacidad positiva, cerrados no negativos y remanente válido. En kárdex versión 2 se exigen volumen/saldo_volumen presentes y saldos físicos no negativos. No se alteran CHECK ni significados históricos de filas NULL.

2. `2026_11_14_000002_create_stock_ledger_lot_allocations.php`:
   - item_id, stock_ledger_entry_id, lote_id y sale_detail_id opcional.
   - physical_delta DECIMAL(15,2), con signo y distinto de cero, en native_unit; container_capacity DECIMAL(8,2).
   - closed_before/after enteros sin signo; open_before/after DECIMAL(8,2).
   - value_delta DECIMAL(15,2): valor asignado del movimiento existente, sin cálculo nuevo de costos.
   - reverses_allocation_id opcional y único: una reversión completa por asignación original. No define todavía devoluciones parciales.
   - CHECK exige delta = cambio de cerrados × capacidad + cambio de remanente; controla capacidad, unidad y límites físicos. No obliga a que un remanente completo se convierta en cerrado.

Se conservaron los tipos anteriores. La capacidad/remanente admite hasta 999.999,99. El límite de acumulado es inferior al máximo de las columnas anchas nuevas para permanecer compatible con el libro existente. cantidad_entrada/disponible conserva DECIMAL(12,4), pero las filas nuevas versionadas deben almacenar cerrados enteros. No se cambian precios, costos de seis decimales o importes de dos decimales.

## FK, historia y concurrencia

Las FK compuestas movimiento+artículo y lote+artículo impiden asociar entidades de artículos diferentes. Necesitan índices UNIQUE auxiliares (id,item_id), compatibles con MySQL 8.4 y su exigencia de claves referenciadas únicas. La referencia original incluye item_id y lote_id; una reversión no puede apuntar al original de otro lote/artículo. La FK opcional al detalle de venta exige su existencia.

Todas las FK nuevas utilizan RESTRICT en DELETE y UPDATE. No se propagan borrados de movimientos, lotes, detalles o asignaciones originales referenciadas. No hay borrado en cascada nuevo. Esto no equivale a prohibir todo DELETE/UPDATE SQL directo de una asignación sin referencias: la política de inmutabilidad del escritor y los privilegios operativos deberán respetarse en B2/H04-B.

El índice único generado es una protección SQL por artículo, no una validación visual ni un bloqueo global. Dos transacciones no podrán confirmar dos lotes versión 2 con remanente positivo para el mismo artículo. La prueba estructural intenta abrir un segundo lote y recibe 1062; no se ejecutó una carrera específica de escritores versión 2 en esta entrega. Las carreras reales ejecutadas corresponden a regresiones H02-A con los escritores existentes. H03-B2 deberá además reutilizar el mutex de artículo y verificar el conjunto completo de lotes antes de abrir, consumir o devolver.

La restricción no deduce si un lote heredado contiene un envase abierto: sin conciliación no se debe mezclar una representación desconocida con operaciones versión 2.

## Límites que requieren validación en H03-B2

- DECIMAL almacena dos decimales, pero MySQL puede coercionar una entrada con mayor precisión antes de evaluar CHECK. Una consulta de lectura confirmó CAST('1.001' AS DECIMAL(8,2)) = 1.00. **El esquema por sí solo no demuestra rechazo de toda entrada con precisión excesiva.** El nuevo escritor deberá validar el texto/escala antes de PDO, sin redondear, y operar con centésimas enteras y límites comprobados.
- Las FK no prueban que la unidad/capacidad de una asignación coincida con su lote, que todos los padres estén versionados, que el detalle pertenezca a la venta del movimiento, ni que sumas de asignaciones igualen cantidades/valores del libro. Son invariantes transaccionales del futuro escritor.
- La FK de reversión no prueba por sí sola causalidad o ausencia de autorreferencias/ciclos creados mediante UPDATE directo. H04-B deberá validar original anterior, naturaleza del movimiento y política de reversión, sin recalcular costo histórico.
- El soporte de asignaciones B1 está restringido a las medidas fraccionables ml/gr/oz; no activa asignaciones para unidades no fraccionables.
- No se corrigen H03 físico, H04 histórico, redondeos de valoración ni asignación real de lotes. Los tests de compatibilidad conservan expresamente el comportamiento fraccionable anterior.

## Verificación y ejecución

PHP 8.3.30 de Laragon, phpunit.xml y bootstrap existentes. El launcher comprueba identidad salon_erp_tester@localhost, destino salon_erp_test, puerto 3306, permisos exactos, ausencia de roles, denegación de ambas otras bases y SHA256 del respaldo existente. Restablece SALON_TEST_DB_RESET_ALLOWED=0 mediante finally. No se utilizó root como ejecutor de pruebas/migraciones; los snapshots administrativos solo usan SELECT/SHOW.

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 tests/Support/isolation-snapshot.php before H03B1
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-h16-tests.php h03b1-install
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-h16-tests.php h03b1-guards
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-h16-tests.php h03b1
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 tests/Support/isolation-snapshot.php after H03B1
```

La instalación es de una sola ejecución desde el estado previo: si encuentra la columna nueva, se detiene. No repetir el modo install sobre el esquema ya instalado. El snapshot before tampoco se sobrescribe. La selección guards ejecuta únicamente el método no destructivo de rechazo de los down.

La prueba de instalación comprobó autorización e identidad mediante TestCase e invocó explícitamente TestDatabaseSafety::assertResetAuthorized antes del DDL. Ejecutó migrate con las dos rutas exactas, rollback de esas dos migraciones tras comprobar nombres/batch y ausencia de datos nuevos, y reinstalación. Comparó los campos previos de items/lotes/kárdex y verificó valores nulos. No se necesitó RefreshDatabase, no se borró/recreó la base ni se usó migrate:fresh. Se conservaron los datos previos de test.

| Selección | Pruebas | Aserciones |
| --- | ---: | ---: |
| Instalación, rollback vacío y reinstalación | 1 | 23 |
| Rechazo de ambos down ante metadatos versionados | 1 | 5 |
| Esquema + regresiones H01-A/H02-A/H04-A | 59 | 374 |
| Total | **61** | **402** |

Todas finalizaron con salida 0. La selección principal incluye siete pruebas de estructura, 21 de catálogo, 17 de integridad no fraccionable, once de anulación y tres carreras MySQL existentes. Se verificaron FK inválidas, mezcla de artículos, borrados/actualizaciones de padres, ecuación física, límites, segundo abierto, remanente completo, referencias de reversión y conservación de escritores antiguos. No se ejecutó la suite completa ni Composer test.

Los fixtures versionados son explícitos, locales y se revierten con DatabaseTransactions. No son una conversión de datos de aplicación ni nuevos escritores productivos. Los documentos confirmados de las carreras se conservan solo en test.

| Base | Antes: tablas / filas / migraciones | Después | Comparación |
| --- | --- | --- | --- |
| salon_erp | 78 / 225 / 74 | 78 / 225 / 74 | Conteos, contenido y estructura idénticos. |
| salon_erp_test | 78 / 508 / 74 | 79 / 593 / 76 | Dos migraciones nuevas y fixtures autorizados. |
| salon_erp_bootstrap_check | 78 / 202 / 74 | 78 / 202 / 74 | Conteos, contenido y estructura idénticos. |

Al cierre, asignaciones = 0 y marcadores/metadatos nuevos clasificados = 0 en test. Las FK instaladas reportan RESTRICT en ambas acciones. El comparador salió 0; indicador local nuevamente 0. PHP lint y git diff --check pasaron.

## Activación posterior y reversión

Antes de B2 se requiere autorización separada, respaldo vigente y exclusión de escritores durante el despliegue. Las migraciones son DDL MySQL con commits implícitos: cada sentencia es independiente; no se afirma atomicidad de toda la serie. Un fallo parcial debe inspeccionarse, sin repetir ni limpiar automáticamente. No se modificó desarrollo ni se proporcionó autorización implícita para aplicarlas allí.

En B2: habilitar modelos/casts y escritor explícito; validar escala, versiones, dimensiones y conciliación; adquirir bloqueos H02-A; actualizar lote/artículo/libro/asignaciones atómicamente. Mantener el valor original del movimiento al distribuirlo entre lotes. Activar solo artículos autorizados y conciliados. Preservar las guardas H04-A y no habilitar H01-B por el mero hecho de tener estas columnas.

Los down requieren exclusión de escritores y ausencia total de asignaciones/metadatos versionados. Ambos comprueban antes del DDL; el segundo verifica también los datos de la primera migración para evitar desmontar una parte y fallar después. Los guardias no sustituyen esa exclusión frente a un administrador/escritor concurrente. Si B2 ya registró datos, conservar el esquema y preparar una corrección hacia delante; no eliminar historia.

Archivos nuevos: dos migraciones, FractionalSchemaInstallationTest, FractionalTraceSchemaTest y este documento. Solo se modificaron los dos auxiliares de selección/snapshot existentes. No hubo cambios de modelos ni archivos funcionales.

Diff completo: storage/app/private/h03b1-review.diff (ignorado). Su reversión de código puede comprobarse con `git apply --reverse --check storage/app/private/h03b1-review.diff`; **no desinstala las migraciones aplicadas en test**. La reversión SQL es un procedimiento distinto y no debe combinarse con la de código sin revisar el estado. No se hicieron commits ni se revirtió la entrega final.
