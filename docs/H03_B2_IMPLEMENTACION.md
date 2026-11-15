# H03-B2 — implementación física y correspondencia contable

## Estado y alcance

**Completado y verificado exclusivamente en salon_erp_test.**
No está desplegada ni activada en desarrollo. H04-B, H01-B y UI-01 siguen pendientes.

Las dependencias de redondeo, captura completa y correspondencia 1.1.4/1.1.5
se resolvieron conforme a las autorizaciones del propietario. No se modifican
impuestos, precios, pagos, proveedores, cuentas de ingresos ni método de valoración.
No se reclasificaron documentos o movimientos históricos.

La puerta de ejecución exige simultáneamente entorno testing, configuración interna
salon.inventory_fractional_v2=true, base salon_erp_test y usuario salon_erp_tester.
Cambiar una variable de desarrollo no permite activarla. Los artículos V2 tampoco
pueden entrar por un escritor físico heredado cuando esa puerta está desactivada.

## 1. Cuentas de inventario y COGS

Causa corregida: COM resolvía la cuenta por artículo, mientras CST y CMV utilizaban
una cuenta de inventario fija según el tipo de transacción. Por eso una compra
fraccionable podía ingresar en 1.1.5 y su venta directa descargar 1.1.4.

La ruta nueva reutiliza ContabilidadService::codigoInventarioArticulo():

| Artículo | Compra | Venta directa | Consumo de servicio |
| --- | --- | --- | --- |
| Fraccionable | Debe 1.1.5 | Debe 5.1 / Haber 1.1.5 | Debe 5.2 / Haber 1.1.5 |
| Reventa no fraccionable | Debe 1.1.4 | Debe 5.1 / Haber 1.1.4 | Debe 5.2 / Haber 1.1.4 |
| Interno no fraccionable | Debe 1.1.5 | Debe 5.1 / Haber 1.1.5 | Debe 5.2 / Haber 1.1.5 |

Se clasifica cada movimiento y detalle ANTES de agrupar sus centavos.
Cada asiento conserva un débito COGS 5.1 o 5.2 y uno o varios créditos de inventario.
La suma de créditos debe coincidir exactamente con el débito, y validarCuadre()
se ejecuta antes del commit. La merma nueva reutiliza la misma resolución de cuenta.
Las contrapartidas de compra, IVA y fondos no cambiaron.

Ejemplo mixto comprobado, importes en C$:

| Cuenta | Saldo inicial | Compra: debe | Salida: haber | COGS: debe | Saldo de inventario final |
| --- | ---: | ---: | ---: | ---: | ---: |
| 1.1.4 | 0,00 | 20,00 | 20,00 | — | 0,00 |
| 1.1.5 | 0,00 | 60,00 | 33,00 | — | 27,00 |
| 5.1 | 0,00 | — | — | 30,00 | — |
| 5.2 | 0,00 | — | — | 23,00 | — |

Kárdex de la venta: salida C$53,00; COGS total C$53,00; créditos de inventario C$53,00.
CST acredita C$10,00 a 1.1.4 y C$13,00 a 1.1.5; CMV acredita C$10,00 y C$20,00,
respectivamente. No se mezcla el valor de un artículo antes de determinar su cuenta.

La inspección de lectura encontró una compra en desarrollo: reventa no fraccionable,
reconocida en 1.1.4, coherente con el artículo. No se encontró una clasificación
contradictoria en esos registros. H01-A bloquea cambios de tipo, fraccionamiento y
medidas cuando existe historia. La fotografía nueva conserva también el tipo:
una reclasificación no controlada posterior a la retención hace rechazar la aprobación.

Esto no demuestra la clasificación original de cualquier dato importado o anterior
a esas protecciones: el esquema histórico no fotografiaba la cuenta por artículo.
Antes de una futura activación se deben conciliar procedencias históricas dudosas;
no deducirlas ni reclasificarlas automáticamente. Los detalles sin fotografía
verificable no se consumen ni revierten mediante el contrato nuevo. La ruta heredada
y sus asientos anteriores no se reescriben al activar estos lectores.

## 2. Contrato completo de consumos

Por detalle se guarda consumption_snapshot, versión 2:

- standard: ausencia auténtica de captura; se fotografía la composición estándar.
- complete: captura explícita que incluye TODOS los insumos, incluso los de cero.
- product: línea de producto, con identidad y clasificación del artículo.
- NULL: evidencia heredada; no se transforma en una captura completa.

La fotografía de servicio conserva IDs, cantidades estándar y reales, unidad,
capacidad, fraccionamiento y tipo. Su referencia es SHA256 de la composición ordenada.
El POS conserva esa referencia desde la selección; una composición cambiada se rechaza
al enviar. El servidor toma una única fotografía por línea antes de insertar detalles
y cantidades capturadas y la reutiliza para persistir y ejecutar.

| Receta A=100, B=50 | Resultado |
| --- | --- |
| Sin captura / NULL | A=100, B=50 de la fotografía estándar |
| A=0, B=20 | Solo B=20 |
| A=20 sin B; A=0 sin B | Rechazo de captura parcial |
| A=0, B=0 | Captura completa; sin salida física ni COGS |
| A=100, B=50 | Ambas cantidades explícitas |

Vacío, ausente dentro de una captura, inválido, negativo, duplicado, ajeno y exceso
de precisión se rechazan. Se validan también los límites SQL antes de insertar
las cantidades: consumos DECIMAL(10,2), lotes DECIMAL(12,4) con envases enteros,
capacidad DECIMAL(8,2), volumen nativo en centésimas y acumulados del kárdex.
Los artículos no fraccionables continúan exigiendo unidades enteras.

Los ceros se conservan en la fotografía y en sale_detail_consumptions. No se crean
movimientos físicos de cantidad cero ni centinelas para impedir un fallback.
Contabilidad obtiene COGS de los movimientos originales por sale_detail_id.
Una línea sin consumo no hereda costos de otra línea ni de la fórmula vigente.

Las retenciones conservan el documento, la fotografía y las filas capturadas;
payload_aprobacion sigue conservando los pagos sin cambios. AprobacionService utiliza
su transacción existente y ejecuta inventario desde la fotografía aunque después
se modifique o elimine la receta. No se modificaron sus reglas de aprobación.
Rechazo y anulación sin efectos no generan compensaciones; se conserva H04-A.

El POS ya no usa parseFloat(f.real) || 0. Conserva cadenas, valida finitud, signo,
precisión y límites por insumo y no aplica una captura parcialmente válida.
Los errores de validación JSON del POS reciben 422. Una receta con insumo archivado
no inutiliza toda la pantalla: no obtiene referencia válida y el servidor rechaza
esa operación sin descontar inventario.

## 3. Representación física, transacciones y trazabilidad

Para fraccionables V2:

    disponible nativo = existencia_actual × total_volume + current_volume

existencia_actual representa envases cerrados; current_volume, el remanente del
único envase abierto. Se admiten ambos extremos 0 y capacidad. Un abierto completo
permanece abierto. Se consume primero el abierto y luego envases cerrados por
fecha_entrada e ID: la prioridad del abierto es una excepción operativa al FIFO estricto.

Las compras ingresan envases cerrados y remanente cero. Cada lote fotografía capacidad,
unidad nativa y volumen inicial. No se convierten ml, gr u oz; oz no se interpreta
como masa o volumen. La venta directa utiliza envases cerrados y no vende un
remanente como si fuera otro envase cerrado.

InventarioService acumula la demanda de todas las líneas e insumos antes de descontar.
Protocolo conservado: documentos/agenda, usuarios necesarios, artículos por ID
ascendente, lotes en orden estable y kárdex. Se releen cantidades bajo bloqueo.
El mutex primario es el artículo, incluso sin movimientos previos.
El índice UNIQUE de H03-B1 impide dos lotes con remanente positivo.

Artículo, lote, kárdex, asignaciones y documento comparten la transacción existente.
No hay commits intermedios ni reintentos financieros automáticos. Se verifica la
conciliación de cantidades y valores; datos NULL/heredados y V2 incompatibles se rechazan.
No se crean lotes ficticios para cubrir insuficiencias.

Cada asignación conserva movimiento, lote, detalle opcional, delta físico, unidad,
capacidad, cerrados y abierto antes/después, valor y referencia de reversión futura.
Las FK RESTRICT preservan la historia. La suma física y monetaria de asignaciones
debe coincidir con el movimiento.

## 4. Valoración y redondeo

Promedio móvil contable; FIFO físico no lo sustituye.

Entrada V2: el valor autoritativo es purchase_invoice_lines.valor_neto, después del
reparto landed y de la corrección documental de centavos. Ese mismo importe se
capitaliza en COM. El IVA se contabiliza por separado. No se cambió el cálculo del
documento ni sus descuentos, impuestos, CxP o pagos.

Ejemplo: 100 envases × 750 unidades nativas a C$17,23:
capitalización y kárdex C$1.723,00. Multiplicar un costo nativo previamente redondeado
a seis decimales producía C$1.722,98. La entrada nueva toma el importe documental.

Salida V2: valor previo × cantidad nativa / cantidad previa, con BCMath y centavos
enteros, y un único redondeo monetario final. El costo unitario informativo conserva
seis decimales del promedio previo; nunca se utiliza su redondeo para recalcular valor.
El agotamiento total toma exactamente el valor remanente del saldo.

Las asignaciones distribuyen el valor ORIGINAL en proporción a la cantidad nativa:
parte entera de centavos y residuos en orden estable de consumo. No se inventan
asientos de ajuste. Se rechazan estados incompatibles o costos nativos que no
pueden representarse en DECIMAL(15,6), con rollback integral.

## 5. Esquema, lectores y compatibilidad

La migración nueva B2 es:
database/migrations/2026_11_14_000003_add_consumption_composition_evidence.php

Añade únicamente sale_details.consumption_snapshot JSON nullable y
stock_ledger_entries.sale_detail_id nullable con FK RESTRICT. No hace backfill.
Las dos migraciones B1 y las 74 anteriores no se modificaron.
Solo test tiene las 77 migraciones; desarrollo y bootstrap_check siguen en 74.

La fotografía adicional es indispensable: las filas históricas de consumos no
distinguen captura completa, parcial y ausencia, ni congelan una receta estándar
sin captura. No se agregaron tablas o migraciones para la corrección de cuentas.

Lectores adaptados: stock crítico, panel, reporte, notificaciones, costos orientativos
del catálogo, kárdex y caducidad. Los lotes con solo remanente abierto siguen visibles
en kárdex y alertas, con unidad nativa. No se cambió el criterio del reporte existente
basado en precio de venta, solo sus unidades equivalentes V2.

Las altas de catálogo activadas son vacías. Un alta V2 sin historia puede dejar de
ser fraccionable sin generar movimientos; no se reclasifica un artículo con historia.
Los campos físicos siguen protegidos por H01-A. No se convierten automáticamente
artículos heredados, ni siquiera por una edición comercial.

Bloqueos deliberados: devolución/anulación con inventario V2 aplicado hasta H04-B,
y apertura heredada sobre V2 hasta H01-B. Se rechazan antes de compensar cantidades,
lotes o costos que no puedan restituirse documentalmente. No se deshabilita la venta
de otros artículos ni se sustituyen aperturas por compras.

## 6. Archivos de la entrega

- Servicios nuevos: CapturaConsumoService, InventarioFraccionableService; precisión:
  app/Support/InventoryDecimal.php.
- Servicios existentes: CompraService, InventarioService, ContabilidadService,
  NotificationService.
- Modelos: Articulo, DetalleVenta y StockLedger.
- Controladores: InventarioController, MermaController, VentaController,
  PanelController y ReporteController. AprobacionService no requirió modificación.
- Vistas: sales/pos, inventory/index y inventory/kardex. Sin cambios CSS ni corrección UI-01.
- Migración B2 indicada arriba y esta documentación.
- Pruebas: FractionalInventoryV2Test, InventoryAccountConsistencyTest,
  FractionalInventoryV2ConcurrencyTest, ConsumptionSnapshotInstallationTest,
  ConsumptionSnapshotRollbackTest y ConsumptionSnapshotGuardTest.
- Fixtures explícitos en FractionalTraceSchemaTest; auxiliares:
  FractionalV2Fixtures, h03b2-concurrency-worker, run-h16-tests e isolation-snapshot.
- JavaScript: tests/Frontend/pos-consumption.test.cjs.

El trabajo B1 ya estaba sin commit al comenzar: sus dos migraciones, su documentación
y su prueba de instalación se conservan. El diff específico B2 se genera contra
HEAD más ese avance previo, para no revertirlo accidentalmente.

## 7. Pruebas y comprobaciones finales

Preflight en cada grupo: PHP 8.3.30, salon_erp_test,
salon_erp_tester@localhost, puerto 3306, sin roles/globales, privilegios exactos de
esquema y rechazo de acceso a ambas otras bases. Respaldo existente comprobado
por SHA256. Los modos B2 verifican las tres migraciones aditivas y un total de 77.
TestCase valida raíz y conexión antes de RefreshDatabase y elimina conexiones alternativas.

| Selección / comprobación | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| h03b2: física V2, captura, lectores, límites y rollback | 51 | 277 | Aprobado |
| h03b2-accounts: conciliación por cuenta y documentos | 10 | 76 | Aprobado |
| h03b2-races: compra–consumo, consumo–consumo, consumo–merma | 3 | 63 | Aprobado |
| h03b2-guards: evidencia en uso rechaza down antes del DDL | 1 | 6 | Aprobado |
| h03b2-rollback: reversión vacía y reinstalación aditiva | 1 | 21 | Aprobado |
| h03b1: esquema B1, H01-A, H02-A, H04-A y carreras H02-A | 59 | 374 | Aprobado |
| h02a-regressions: compras y catálogo | 30 | 233 | Aprobado |
| Node: POS, catálogo y agenda | 38 | — | Aprobado |

Todos los grupos PHP terminaron con código 0. No se certificó la entrega con
resultados anteriores a las correcciones finales: se repitieron los grupos afectados.
La regresión de compras reconstruyó exclusivamente test mediante RefreshDatabase;
las comprobaciones nuevas usan transacciones/rollback o carreras explícitamente acotadas.

Ejecutor utilizado para las selecciones indicadas:

    & 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-h16-tests.php <seleccion>

JavaScript ejecutado con node --test sobre los tres archivos explícitos de
tests/Frontend: pos-consumption.test.cjs, inventory.test.cjs y agenda.test.cjs.
PHP -l: 33 archivos aprobados; git diff --check sin errores.

Los grupos comparten algunas pruebas de catálogo; no deben sumarse como casos únicos.
El ensayo de reversión solo quitó y reinstaló la tercera migración con evidencia
vacía: conservó filas y las dos migraciones B1. Con fotografías en uso, down() fue
rechazado antes de emitir DDL; un segundo bloqueo de emergencia del ensayo garantizó
que ninguna sentencia DDL pudiera ejecutarse si fallaba el guardia inspeccionado.

Concurrencia: dos procesos y conexiones MySQL independientes, barrera controlada,
snapshot anterior deliberado, espera real de FOR UPDATE, verificación de resultados
persistidos y desconexión de ambas conexiones. Compra–consumo confirma ambas;
consumo–consumo y consumo–merma confirman solo una, sin documentos parciales.
También se repiten las carreras H02-A venta–venta, venta–merma y venta–anulación.

Evidencia local ignorada:
storage/app/private/h03b2-account-evidence.jsonl,
storage/app/private/h03b2-race-evidence.jsonl y logs PHP por selección.
Los saldos contables de los fixtures se registran en centavos, antes y después.
No contienen credenciales reales.

Build Vite aprobado, salida privada y sin desplegar public/build. Existe un aviso
del plugin de fuentes sobre fontaine opcional; no impide el build y no se instaló
ninguna dependencia para ocultarlo.

Comando final del build (código 0):

    npm.cmd run build -- --outDir storage/app/private/h03b2-final-build-20261114-03

Manifest y recursos quedaron en ese directorio ignorado, con Vite 8.1.5.

## 8. Integridad y guardias

Comparación final contra la línea base H03B2C: hashes de contenido de todas las
filas, conteos y SHOW CREATE TABLE de cada tabla.

| Base | Tablas | Filas | Migraciones | Resultado |
| --- | ---: | ---: | ---: | --- |
| salon_erp | 78 | 225 | 74 | Estructura y contenido idénticos |
| salon_erp_test | 79 | 859 | 77 | Modificada solo por las pruebas autorizadas |
| salon_erp_bootstrap_check | 78 | 202 | 74 | Estructura y contenido idénticos |

Los fixtures concurrentes se conservaron en test para revisión; no se eliminaron
al terminar. Todas las conexiones de los workers fueron cerradas y se comprobó
su desaparición de PROCESSLIST. SALON_TEST_DB_RESET_ALLOWED=0 confirmado al cierre.

Comprobación utilizada:

    & 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 tests/Support/isolation-snapshot.php after H03B2C

No hubo migraciones ni solicitudes de pruebas contra desarrollo o bootstrap_check.
Su inspección se realizó mediante SELECT/SHOW independientes, sin iniciar Laravel.
No se cambiaron usuarios, permisos, APP_KEY, .env ordinario, rutas o seeders.
SALON_TEST_DB_RESET_ALLOWED se restaura mediante finally por cada runner.
No hubo commits ni ejecución de suite completa, Composer test o paralelismo indiscriminado.

## 9. Riesgos y activación futura

- H04-B debe restituir lotes/asignaciones y costos originales; H01-B debe documentar
  aperturas. No están implementados y sus operaciones V2 permanecen bloqueadas.
- El origen contable de importaciones/historia ambigua necesita conciliación previa.
  No hay reclasificación automática ni reconstrucción de capturas desde recetas actuales.
- La validación de estado recorre historia bajo bloqueo: no se midió rendimiento con
  kárdex de gran volumen. Las pruebas de concurrencia cubren carreras controladas,
  no constituyen una prueba de carga.
- La verificación visual manual en navegador y UI-01 quedan fuera de esta entrega.
- Precisión y unidades son nativas; no hay conversiones físicas implícitas.
- Todo error intermedio revierte documentos, pagos, asientos, lotes, artículo y asignaciones.

Para una activación posterior: autorización separada, excluir escritores, respaldo
nuevo verificado, inspección/conciliación de artículos y cuentas, instalar las tres
migraciones aditivas y clasificar explícitamente únicamente datos comprobados.
No inferir saldos NULL ni crear lotes ficticios. La puerta actual, limitada a testing,
debe cambiarse de forma coordinada y revisada; no basta activar una variable local.
Después realizar pruebas de humo autorizadas y revisar antes de reabrir.

## 10. Diff y reversión

Diff específico de B2: storage/app/private/h03b2-review.diff, incluidos archivos nuevos,
con B1 preservado. Comprobación textual sin modificar archivos:

    git apply --reverse --check storage/app/private/h03b2-review.diff

No aplicar una reversión de código mientras un escritor pueda interpretar datos V2
con código heredado. Mantener primero el entorno de ensayo sin escritores y conservar
respaldo/evidencia; la puerta de prueba puede desactivarse y el indicador permanece 0.
No retirar columnas con evidencia en uso: las migraciones lo rechazan.
Una restauración o recreación posterior requiere autorización; no se hizo automáticamente.

El diff inverso es una herramienta de revisión, no un procedimiento para borrar datos
ni un sustituto de la conciliación. No usar git reset --hard ni el diff acumulado que
incluye las migraciones y archivos B1.
