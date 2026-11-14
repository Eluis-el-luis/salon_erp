# H04-A — No devolver efectos inexistentes

## Causa y evidencia

VentaController::anular excluía únicamente borrador de la reversión. pendiente_aprobacion también alcanzaba InventarioService::revertirPorVenta aunque store y facturarCita retornan antes del consumo, pagos, comisiones y contabilidad cuando retienen el documento. El rechazo por AprobacionService::rechazarVenta ya cambiaba el estado sin devolver existencias.

Estados actuales:

| Estado | Flujo legítimo | Tratamiento |
| --- | --- | --- |
| borrador | Cotización sin efectos | Anulación documental, sin compensaciones. |
| pendiente_aprobacion | Retención sin efectos; payload_aprobacion es propuesta, no pago ejecutado | Anulación documental o rechazo por Aprobaciones, sin compensaciones. |
| emitida | Crédito: aplica stock, ingreso/CxC y comisiones; puede no tener pagos | Reversión de efectos originales documentados. |
| pagada | Venta inmediata o aprobada; servicios sin BOM pueden no consumir stock | Verificar evidencia de inventario y revertir asientos originales si existen. |
| anulada | Anulación o rechazo ya realizados | Rechazo de repetición, sin nuevas devoluciones. |

Las salidas originales se identifican en stock_ledger_entries por referencia_tipo=venta, referencia_id=sales.id, origen=venta y movimiento=salida. Los pagos usan venta_id; comisiones usan venta_id; los asientos originales usan referencia_id, modulo_origen ventas/inventario y número distinto de REV-*. Se comprobó que los asientos de merma usan modulo_origen=mermas, no este ámbito.

El estado no sustituye la evidencia. Una venta a crédito carece legítimamente de pagos; un servicio sin BOM carece legítimamente de movimientos físicos. Un documento pagado sin salida original para productos que se pretende devolver es ambiguo y se rechaza para conciliación. No se reconstruye historia ni se añade un marcador mediante migración.

## Corrección

Después del bloqueo de agenda y venta, se adquieren usuarios/artículos con el protocolo H02-A antes de consultar evidencia mediante lecturas actuales con bloqueo. Se releen pagos. Así, una espera por aprobación concurrente no utiliza únicamente el snapshot anterior para decidir los efectos.

Una cotización o retención limpia solo se marca anulada, se conserva motivo/usuario/fecha y se limpia payload_aprobacion. Si contiene movimientos, pagos, asientos o comisiones incompatibles con ese estado, se rechaza sin compensar. La agenda de una retención no se reactiva como si se hubiese completado; no hubo efectos originales que deshacer.

Para documentos aplicados, la devolución propuesta se compara por artículo, unidades equivalentes y volumen (cuando corresponda) con las salidas originales del kárdex. En insumos fraccionables se utiliza la capacidad únicamente para comparar con la medida ya registrada; productos directos se comparan por unidades y su volumen equivalente. Se conserva el redondeo de cuatro decimales de los movimientos. Un movimiento de devolución preexistente o una diferencia de artículos/cantidades/volumen impide devolver. Una receta modificada puede bloquear la anulación, pero no se utiliza para inventar una entrada. Esto es contención: no implementa la reversión histórica de H04.

La reversión contable existente solo se invoca si hay asientos originales. No se modifican sus cálculos ni líneas, pagos, impuestos, comisiones, costos o selección del lote de retorno. No se agregan transacciones ni commits intermedios. La excepción de conciliación provoca rollback de la operación completa.

## Archivos

- app/Http/Controllers/VentaController.php: clasificación con evidencia actual y anulación sin efectos ficticios.
- app/Services/InventarioService.php: reutilización del bloqueo H02-A y guardia de correspondencia con salidas originales.
- tests/Feature/SaleCancellationEvidenceTest.php: once casos focalizados, incluidos fraccionables, crédito, receta cambiada y datos inconsistentes.
- tests/Support/run-h16-tests.php: selección h04a explícita (test nuevo + integridad/concurrencia H02-A).
- tests/Support/isolation-snapshot.php: fase H04A, sin ampliar destinos de PHPUnit.
- docs/H04_A_ANULACIONES_SIN_EFECTOS.md: diagnóstico, criterios, resultados y límites.

## Pruebas

PHP 8.3.30 de Laragon, phpunit.xml y el procedimiento existente. Antes de cada ejecución se verificaron destino salon_erp_test, identidad salon_erp_tester@localhost, puerto 3306, permisos exactos, ausencia de roles, rechazo de las otras dos bases y SHA256 del respaldo de test. Los procesos concurrentes mantienen los mismos guardias. No se utilizaron RefreshDatabase, migraciones ni recreación de esquema en esta fase.

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 tests/Support/isolation-snapshot.php before H04A
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-h16-tests.php h04a
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 tests/Support/isolation-snapshot.php after H04A
```

Resultado final: **31 pruebas / 226 aserciones / salida 0**. Once casos nuevos, 17 de integridad H02-A y tres carreras MySQL con conexiones independientes. Una ejecución previa, antes de añadir los casos de crédito, servicio sin BOM y receta cambiada, aprobó 28 casos. No se ejecutó la suite completa ni Composer test. El launcher restauró SALON_TEST_DB_RESET_ALLOWED=0 mediante finally; se confirmó por lectura posterior del archivo.

Se comprobaron anulación de cotización y retención en ambos tipos de artículo, rechazo de retención, aprobación seguida de anulación efectiva, repetición sin duplicados, crédito sin pagos, servicio sin stock, estado contradictorio, falta de evidencia histórica y receta incompatible. Las aserciones comparan artículo, lotes, kárdex, pagos, asientos/líneas y comisiones, además del estado del documento. Las carreras H02-A conservan los resultados venta–venta, venta–merma y salida–anulación y verifican cierre de conexiones.

La selección se repitió al cerrar las guardas; el resultado final siguió siendo 31 pruebas / 226 aserciones / salida 0. El snapshot final salió con código 0:

| Base | Antes: tablas / filas / migraciones | Después | Verificación |
| --- | --- | --- | --- |
| salon_erp | 78 / 225 / 74 | 78 / 225 / 74 | Conteos, contenido y estructura idénticos, incluidas sesiones. |
| salon_erp_test | 78 / 259 / 74 | 78 / 508 / 74 | Solo fixtures y operaciones autorizadas. Sin recreación ni migraciones. |
| salon_erp_bootstrap_check | 78 / 202 / 74 | 78 / 202 / 74 | Conteos, contenido y estructura idénticos. |

Los fixtures financieros confirmados de las carreras permanecen exclusivamente en test; las nuevas pruebas focalizadas revierten sus fixtures mediante DatabaseTransactions. PHP lint, git diff --check y comprobación de aplicabilidad del diff inverso pasaron. No se modificaron .env ordinario, APP_KEY, cuentas SQL, permisos, migraciones ni algoritmos financieros.

## Límites y reversión

H03 conserva sus algoritmos y problemas físicos. H04 conserva el costo vigente de devolución y el retorno al lote más reciente. No se reconstruyen consumos antiguos, recetas originales, asignaciones de lote o cobros históricos ausentes. Las diferencias se rechazan para conciliación; no se supone que coincidencia de estado pruebe ejecución ni que ausencia de pagos pruebe ausencia de consumo.

No se modifican AprobacionService ni las reglas de aprobación. Rechazar aprobación y anular una retención tienen distintos puntos de entrada y notificaciones, pero ninguno debe crear movimientos físicos/contables. No se implementa H01-B.

Reversión solo de código, sin restaurar bases ni ejecutar migraciones. Diff completo: storage/app/private/h04a-review.diff, ignorado por Git. Comprobar primero `git apply --reverse --check storage/app/private/h04a-review.diff`; una aplicación posterior sin --check requiere revisión/autorización y reabre el defecto. No se aplicó la reversión ni se hicieron commits.
