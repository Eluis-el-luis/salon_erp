# H06-R — Facturación de citas, participación y consumos

Fecha: 21 de noviembre de 2026. Revisión del código vigente, sin SQL empresarial. Referencias inspeccionadas: auditoría original, AGENTS.md, H05-R, H03-B2, H04-B, H13-D, H15 y H16. Las correcciones de nómina H07/H08/H11 permanecen intactas.

## 1. Estado vigente de H06

**H06 queda parcialmente corregido, con bloqueos explícitos de representación para consumo/participación y comisiones. No se declara éxito total.**

| Parte histórica | Estado encontrado y resultado |
| --- | --- |
| Sin coordinación de agenda al facturar | H16 ya implementó mutex de estilistas, cita y ventas. Se conserva; no se repite la corrección |
| Segunda factura de una cita | La consulta de ventas aplicadas estaba condicionada a V2. Corregida para legacy y V2, bajo los locks existentes |
| Moneda y cobro directo | Persistía el circuito sin libro de pagos y trataba el total NIO como USD recibido. Corregido para solicitudes nuevas mediante el libro existente |
| Precio y centavos | H05 ya había corregido autoridad de catálogo, descuento acotado y reparto exacto. Se conservan |
| Vínculo al convertir borrador | Podía conservar cabecera de cita aunque el carrito ya no la incluyera. Se rechaza antes de mutaciones |
| Varias participaciones, varias recetas | Confirmado con el lector real sobre dobles. No corregido: falta representación inequívoca de prestación/participación compatible con mezcla e históricos |
| Comisión calculada sobre fracción | Confirmado con el motor real sobre memoria; incompatible con la regla ahora confirmada. Corrección conjunta pendiente de representación; no se reescriben comisiones congeladas |
| Consumo explícito cero en legacy | Se conserva el límite H03/H05: si todo es cero vuelve al estándar. No se activa V2 ni se cambia esa política en H06 |

Las reproducciones son aisladas. No se afirma que estos defectos hayan ocurrido en documentos concretos de producción.

## 2. Rutas y consumidores

| Entrada | Consumidor/contrato actual |
| --- | --- |
| Agenda → GET `/pos?appointment_id=...` | `appointments/index.blade.php` genera enlace con servicio, precio, cliente y estilistas; POS precarga una línea por estilista |
| POST `/sales` | `VentaController::store`: POS, mezclas, cotizaciones, crédito y documentos retenidos |
| POST `/sales/bill-appointment/{appointment}` | `facturarCita`: genera detalles desde cita/catálogo. Ruta conservada, también usada por pruebas funcionales históricas |
| POST `/aprobaciones/venta/{id}/aprobar` | `AprobacionService::aprobarVenta`: aplica inventario, pagos, contabilidad y comisiones de un documento retenido |
| `draft_id` en POST `/sales` | Convierte el mismo documento borrador conservando identidad; ahora exige conservar su vínculo de cita en el carrito |
| POST `/ventas/{id}/cobrar` | Cobro posterior de una venta emitida: no crea otra prestación ni repite inventario |

Las rutas de venta siguen dentro de sesión web, autenticación, CSRF y roles `recepcion,contador,admin`, con el bypass vigente de `alvaro`; aprobación conserva su autorización existente. No se introducen rutas ni mecanismos nuevos de autenticación.

La agenda visible usa actualmente POS, pero eso no demuestra que la ruta directa carezca de consumidores externos. Además se identificaron llamadas directas en `AppointmentServicesTest`, `AppointmentAvailabilityTest` e `HistoricalInventoryReturnTest`. No se retira ni se considera innecesaria. Se mantiene HTTP 200 para emisión directa, 201 para retención y 422 para rechazo de dominio.

## 3. Modelo de prestación y participación

El propietario confirmó para H06:

- Una cita puede representar **una única prestación compartida** por varios estilistas.
- El cliente paga una vez el precio completo y la receta se consume una vez por prestación efectiva.
- Cada participante comisiona sobre el precio completo, con su porcentaje configurado, no sobre una fracción.
- Las fracciones actuales representan participación heredada. Las líneas completas H16 no acreditan por sí solas prestaciones independientes.
- Se conservan descuentos autorizados, consumos reales, importes congelados, anulación y trazabilidad. No se autorizaron columnas, migraciones ni vínculos nuevos.

Distinciones del modelo actual:

| Concepto | Representación disponible | Límite |
| --- | --- | --- |
| Cita | `appointments`, un `service_id`, cliente, estado y asignación `appointment_user` | Es planificación/asignación; H04 ya documenta que completada o facturada no prueba por sí sola ejecución efectiva |
| Prestación efectiva | No hay entidad/identidad separada de ejecución compartida | No distingue inequívocamente una ejecución de varias, ni su relación con cada línea comercial |
| Línea comercial | `sale_details`: servicio/artículo, cantidad entera, precio, variante y un `stylist_id` | Se usa también como unidad física; no persiste el `appointment_id` que venía en cada fila del carrito |
| Participación | Pivot de asignación en cita y `stylist_id` de cada detalle | Se confunde con varias unidades comerciales al generar n detalles |
| Comisión congelada | `comisiones_generadas`: venta, empleado, base, porcentaje e importe | No tiene referencia a detalle/prestación; no reemplaza un registro independiente de participación |
| Fotografía/consumo | Por `sale_detail_id`; composición V2 donde esté autorizada | No existe fotografía única por prestación compartida identificada |

`sales.appointment_id` es un vínculo de cabecera ya existente. Admite una cita por venta; el controlador ya rechaza carritos que perderían vínculos de varias citas. Una venta mixta puede contener otras líneas; el servicio/importe de una línea no basta para determinar que pertenece a la ejecución de la cita.

## 4. Duplicidad y concurrencia

`AgendaService::validarParaFacturar` ya usa `bloquearCitas`: descubre asignaciones, bloquea usuarios por ID, cita por ID, relee pivot y asignación, y después consulta ventas. La detección de otro documento aplicado usaba `CapturaConsumoService::activa() && ...`, por lo que con V2 desactivado no consultaba ese bloqueo.

Dos pruebas previas a la corrección fallaron: con una venta `pagada` o `emitida` vinculada y V2 desactivado, el servicio no rechazaba otra facturación. Se eliminó únicamente esa condición de activación. La consulta sigue siendo actual/bloqueante, con `FOR UPDATE` y exclusión explícita de la venta propia.

Reglas resultantes:

- Cita pendiente/completada puede facturarse si no existe otra venta aplicada incompatible. Completada manualmente no equivale a facturada.
- Cancelada o `no_asistio` requiere reactivación válida según H16.
- `pagada`/`emitida` bloquean otro documento; la venta propia se excluye al completar/aprobar.
- Borrador o retenida no se consideran automáticamente aplicadas. Pueden existir propuestas/retenciones previas; cuando una se aplica, otra aprobación debe rechazar antes de efectos.
- Las reglas H04 de anuladas con ejecución constatada sin restitución siguen en `ejecucionBloqueada`, sin modificaciones de evidencia ni de su puerta V2.

El guardia compartido alcanza directa, POS, nueva asociación documental y aprobación. Una prueba ejecuta directa y después POS/reenvío directo sobre la misma persistencia en memoria: se conserva una sola venta aplicada y sus efectos. Otra ejecuta el caso de aprobación real hasta el rechazo por otra venta aplicada. Los mutex son dobles; no constituyen una prueba InnoDB simultánea.

No se añade un token de idempotencia ni se devuelve la primera respuesta ante reenvío. Retenciones repetidas todavía pueden crear documentos sin efectos; no se promete deduplicación completa de solicitudes. Todos los escritores deben conservar el mismo protocolo. Persisten riesgos de deadlock entre usuarios/cita/venta y locks posteriores de stock/divisas; no se añaden reintentos financieros ni restricciones SQL.

## 5. Contratos de inventario y diagnóstico físico

`InventarioService::operacionesVenta` y `descontarPorVenta` recorren cada detalle. Para servicios multiplican su consumo por `quantity`. `consumosDeDetalle` usa fotografía V2 si corresponde; en legacy toma consumos reales positivos o vuelve a fórmula estándar si no queda ninguno.

Lecturas reales sobre un catálogo sintético con receta de 10 unidades nativas:

| Participantes/detalles actuales | Cantidad comercial sumada | Suma del precio repartido | Estándar interpretado | Real 7 por línea | Todo cero capturado, legacy |
| --- | ---: | ---: | ---: | ---: | ---: |
| 1 | 1 | 100,01 | 10 | 7 | 10 |
| 2 | 2 | 100,01 | 20 | 14 | 20 |
| 3 | 3 | 100,01 | 30 | 21 | 30 |

Estas cantidades son las operaciones físicas interpretadas por el lector; no se ejecutaron salidas reales de stock/lotes. Las pruebas verdes de caracterización **confirman un defecto pendiente**, no aprueban consumir n recetas para una prestación. Se caracteriza asimismo una mezcla con producto independiente, cuya cantidad física no debe desaparecer al corregir la agrupación futura.

H03-B2 conserva captura completa y ceros mediante su composición V2, limitada a su entorno autorizado; no se activa en esta fase. POS admite captura real por línea. La ruta directa no tiene editor propio de consumos reales: construye servicios desde servidor y conserva su captura estándar existente. No se copia ni modifica una fotografía histórica.

No se corrige con `quantity=0` (viola H05), precios cero, notas, un estilista primario inventado, suma/reparto arbitrario de capturas o receta cero para participantes secundarios (además del fallback legacy). Tampoco se agrupa por coincidencia de servicio/precio: en mezclas y líneas completas H16 puede borrar prestaciones legítimas sin evidencia.

## 6. Comparación POS y facturación directa

| Contrato | POS | Directa después de H06-R |
| --- | --- | --- |
| Precio | H05 valida catálogo/variante y reparto; no confía en total HTTP | Catálogo del servicio de la cita, activo; ignora precio/cliente/total comerciales enviados |
| Descuento | Explícito o grupo si se omite | Explícito, default cero conservado; no se cambia unilateralmente a descuento por grupo |
| Participación | n líneas al precargar cita; también acepta líneas completas históricas H16 | n detalles desde asignación actual, sin cambio de representación en esta fase |
| Consumo | Captura por línea, H03 o lector legacy | Estándar por cada detalle; multiplicación pendiente |
| Pagos | `LibroPagosService` | Ahora el mismo servicio, con vuelto y divisas del mismo contrato |
| Retención | Guarda payload sin efectos hasta aprobación | Guarda importes/monedas realmente declarados, no total NIO etiquetado USD |
| Borrador/crédito | Acciones existentes | No se añaden acciones nuevas a la ruta directa |

No se realizó una refactorización general del controlador. Se reutiliza el caso de registro de pagos existente, evitando otro conversor o escritor de divisas. La unificación completa del caso de venta queda condicionada a resolver la representación de prestación/participación y captura.

## 7. Moneda, pagos y comisiones

La base comercial y contable es NIO. La cabecera puede describir el primer pago como USD, pero subtotal/descuento/total permanecen NIO. Antes, directa ingresaba `venta.total` como dólares y no creaba `pagos`; `ContabilidadService` entraba a su conversión legacy sin libro y volvía a aplicar la tasa.

Ahora se crean las líneas mediante `LibroPagosService` y se cargan antes de contabilizar. El método contable existente detecta el libro y conserva factor 1 para importes ya expresados en NIO. No se modifica contabilidad ni el servicio de divisas.

Ejemplos observados con el libro real y dobles de persistencia/divisas:

- Total NIO 100,01, cobro legacy NIO: pago `valor_nio=100,01`.
- Total NIO 100,01, entrega USD 3 a tasa sintética 40: pago base 120, vuelto NIO 19,99; se solicitan USD físicos 3 al escritor, nunca 100,01.
- NIO 60,01 + USD 1 a tasa 40: suma exacta por línea 10.001 centavos.

La ruta directa admite `payments` con el contrato del POS (`metodo`, `moneda`, `monto`, `tasa`) y `change_currency`. Exige lista e importes/tasas positivos finitos. Un pedido legacy `currency=usd` sin importe explícito devuelve 422 y solicita `payments` o POS: convertir automáticamente o inventar dólares recibidos sería inseguro. NIO sin arreglo mantiene su compatibilidad. El cálculo/conversión y disponibilidad de vuelto continúan en los servicios existentes.

**Base vigente de comisión:** `ComisionService` calcula `cantidad × precio_unitario` bruto de cada detalle, sin restar `sales.discount`. Prioriza rango/esquema vigente y utiliza `users.comision_servicio` como fallback. `EmpleadoController` sincroniza la configuración del perfil con esquemas versionados; no se eliminó esa resolución ni se cambiaron porcentajes. `ComisionGenerada` conserva los montos congelados que consume nómina.

Reproducción aislada con el motor real, fallback de perfiles 10%/15%, precio 1.000:

| Representación | Comisión observada | Regla confirmada |
| --- | --- | --- |
| Un estilista, línea 1.000 al 10% | 100 | Compatible |
| Dos estilistas, líneas 500/500 al 10%/15% | 50 y 75 | Incompatible: deben ser 100 y 150 para la prestación compartida |

Se repite con descuento de cabecera 0 y 100: las comisiones observadas no cambian, confirmando base bruta vigente. No se inventa una regla de comisionar neto ni se recalculan montos históricos. Cambiar la base de las participaciones exige identificar y conservar qué prestación corresponde a cada una; queda pendiente junto con el consumo, en lugar de usar catálogo actual o total de toda la venta mixta como base.

## 8. Correcciones efectuadas

1. Guardia de venta aplicada independiente de V2, bajo coordinación existente de agenda y lectura actual bloqueante.
2. Directa usa libro de pagos para NIO/USD explícito/mixtos, carga pagos antes del asiento y elimina su ingreso manual duplicado de divisas.
3. Retenida directa conserva el payload real de pagos/vuelto para el servicio existente de aprobación.
4. Rechazo del USD legacy ambiguo y validación de lista/valores finitos del nuevo payload directo.
5. Conversión POS de borrador vinculado rechaza carrito que haya eliminado su cita, antes de borrar detalles o cambiar cabecera. La prueba previa alcanzaba el límite estricto del doble y devolvía 500; ahora recibe el rechazo específico 422 sin mutaciones.

El contrato NIO de respuestas se conserva. No se modifican precios de catálogo, tasas, bancos, stock, recetas, comisiones, nómina, políticas AC-05, V2 o documentos históricos.

## 9. Decisiones comerciales y diseño pendientes

**La regla compartida ya está confirmada; no queda pendiente decidir si debe consumirse una o n recetas para ese caso.** El bloqueo es de representación segura y trazabilidad, no una autorización para mantener n recetas como regla correcta.

El modelo actual mezcla participación y unidad comercial. Falta un vínculo persistido e inequívoco entre prestación, líneas comerciales, participantes y fotografía física. `sale_details` pierde el vínculo de cita por fila; una cabecera mixta no resuelve esa pérdida. Asignación de agenda no sustituye participación histórica; comisiones no sustituyen un registro independiente de participación (porcentajes cero, varios servicios, cambios posteriores y anulación). Las líneas completas H16 necesitan evidencia explícita para acreditar varias prestaciones reales, sin reinterpretación automática.

Propuesta para aprobación separada, sin DDL ni implementación:

- Identidad estable de prestación nueva, con servicio/cantidad real y vínculo autorizado a cita, sin backfill ni transformación de documentos emitidos.
- Participantes y base comercial completa persistidos por prestación; porcentajes e importes de comisión congelados mediante la resolución vigente. Descuentos mantienen la base bruta actual salvo autorización diferente.
- Fotografía única de receta/consumo real por prestación, con ceros explícitos y criterio de captura único cuando el editor heredado trae varias capturas por participante. Evitar elegir/sumar capturas contradictorias sin regla.
- Relación verificable de líneas comerciales con esa prestación para mezclas, aprobación y anulación; registrar inventario una vez y revertir desde los movimientos/fotografías originales H03/H04.
- Tratamiento explícito de borradores/retenidas anteriores ambiguos: revisión, sin reprecificar ni fabricar ejecución o participantes. No basta aprobarlos para corregir la representación.

Si estas relaciones requieren columnas/tablas, presentar migración y contrato antes de pedir autorización. No se escondieron vínculos nuevos en `observations`, notas o `payload_aprobacion`, ni se activó V2 como atajo.

## 10. Pruebas y resultados

Resultado final: **195 pruebas PHP y 720 aserciones aprobadas**, más **12 pruebas JavaScript aprobadas**. PHP comprende 42 casos H06 y 153 regresiones seleccionadas H05/H07/H08/H11. No son suites generales.

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' `
  -d memory_limit=128M -d max_execution_time=40 -d zend.exception_ignore_args=1 `
  vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php `
  --do-not-cache-result --no-progress `
  tests/Unit/FacturacionCitaGuardTest.php tests/Unit/FacturacionCitaPaymentsTest.php `
  tests/Unit/CitaConsumptionCharacterizationTest.php tests/Unit/CitaCommissionCharacterizationTest.php `
  tests/Unit/CarritoVentaTest.php tests/Unit/CarritoVentaHttpTest.php `
  tests/Unit/NominaOrdenCuotasTest.php tests/Unit/ConciliacionNominaTest.php `
  tests/Unit/NominaConceptosIntegrityTest.php tests/Unit/NominaCoverageTest.php `
  tests/Unit/NominaWizardContractTest.php

node --test tests/Frontend/pos-cart.test.cjs tests/Frontend/pos-consumption.test.cjs
```

| Casos solicitados | Evidencia y límite |
| --- | --- |
| 1. Válida | Controlador directo real y libro real, detalles/pagos en memoria, sin kernel completo |
| 2–3. Reenvío/ya facturada | Dos reproducciones rojas legacy previas; guardia actual y flujo directa→POS→directa sobre la misma persistencia en memoria rechazan |
| 4. Anulada | Cita cancelada/no_asistio rechaza; no se inventa estado `anulada` de cita. Venta anulada se distingue de aplicada; evidencia H04 V2 no se ejecuta aquí |
| 5–7. Dos/tres estilistas y centavos | Se conservan sumas exactas 100,01: 50,01+50,00 y 33,34+33,34+33,33. No se afirma que esa representación física esté corregida |
| 8–10. Receta/real/cero | Lector y generador de operaciones reales sobre catálogo sintético: multiplicación y fallback cero caracterizados, pendientes de corrección |
| 11–12. Servicio/precio | Inactivo o ausente rechaza antes de documentos; datos comerciales HTTP ignorados por directa; H05 rechaza precio manipulado POS |
| 13–15. NIO/USD/mixto | Libro real; USD físico y contabilidad sustituidos. Cobertura/vuelto/base verificados por centavos; no prueba de asiento ni movimiento real |
| 16. Directa y POS | Controladores reales con guardia compartida y datos persistidos en el doble; segundo intento no altera primera factura ni efectos |
| 17. Retenida/aprobación | Payload conserva cobro real y no aplica efectos. Caso de aprobación real rechaza antes de inventario si existe otra aplicada; aprobación completa positiva queda pendiente de MySQL |
| 18–20. Fallos tras inventario/pagos/asiento | Dobles estrictos registran efectos sintéticos y lanzan centinela; controlador revierte filas/efectos y queda en nivel cero. El asiento es un doble, no un asiento InnoDB real |
| 21. H05 | 53 pruebas previas y 12 JS sin cambiar expectativas económicas, incluido contrato H16 de líneas completas |
| 22. Vínculos | `appointment_id` conserva identidad en emisión; convertir borrador sin esa cita en carrito rechaza antes de mutación |
| 23. Citas ajenas | Ventas de otra cita no bloquean esta; filas y efectos del documento anterior permanecen intactos ante rechazo |
| 24. Interleavings | Primera consulta sin aplicada, primera caja confirma en el doble y siguientes relecturas rechazan. Mutex simulado, no dos conexiones InnoDB |

Pruebas nuevas separadas:

- `FacturacionCitaGuardTest`: servicio/consulta Eloquent reales, adquisición de mutex simulada; consulta compila `FOR UPDATE`. Incluye aprobación negativa real.
- `FacturacionCitaPaymentsTest`: controlador y libro reales con almacenamiento sintético; inventario, divisas, contabilidad, comisiones y notificaciones sustituidos explícitamente. Las pruebas se aíslan en procesos para esos dobles; no hay correos ni notificaciones reales.
- `CitaConsumptionCharacterizationTest`: lector y operaciones físicas reales, sin ejecutar salidas; confirma límites pendientes.
- `CitaCommissionCharacterizationTest`: cálculo/creación de comisiones reales interceptadas en memoria; confirma base bruta y defecto pendiente de fracciones.

Los snapshots no prueban rollback InnoDB. Las pruebas de caracterización verdes no convierten defectos en políticas correctas. No se ejecutaron los tests históricos con `RefreshDatabase`, seeders o bootstrap empresarial; no se cambiaron sus expectativas. Lint PHP y `git diff --check` verificados. Sin cambios frontend, no se requiere build.

## 11. Compatibilidad H05/H03/H04/H16

H05 conserva cantidades positivas, precios de catálogo y reparto exacto; no se modifica el carrito ni su JS. H03 mantiene fotografía por detalle, captura completa autorizada y puerta V2, sin alterar lectores/escritores físicos. H04 mantiene reversión original, guardia de ejecución constatada y estados/evidencia de anulación; no se reconstruye historia. H13-D conserva catálogo `services` correcto. H15/H16 conservan edición, estados, asignación y orden de locks.

El nuevo guardia usa columnas existentes `sales.appointment_id/status`; no lee columnas V2 en legacy ni necesita migración. La ruta directa incorpora pagos nuevos correctamente identificables por los flujos ya existentes. Las ventas directas históricas sin libro no se convierten, y payloads USD retenidos antiguos no se normalizan automáticamente.

H07/H08/H11 se ejecutaron como regresión focalizada y sus archivos no se modificaron. No se cambian fuentes congeladas que consume nómina.

## 12. Regresiones SQL pendientes

Cuando el laboratorio Debian independiente exista y esté autorizado, probar dos conexiones reales: directa vs POS, dos cajas, dos aprobaciones y anulación vs facturación. Comprobar espera sobre mutex/cita, relectura actual, rechazo del segundo aplicado y orden completo usuarios→cita→venta→fuentes físicas/financieras, sin transacción exterior de PHPUnit que oculte commits.

Ejecutar emisión NIO/USD/mixta y aprobación positiva con libro, saldos de divisas, fondos y asiento reales; comprobar centavos, COGS, comisiones y ausencia de duplicados. Provocar errores después de inventario, pagos y asiento; capturar el punto alcanzado y leer ausencia de residuos desde otra conexión tras rollback/nivel cero. Registrar AUTO_INCREMENT aparte.

Para consumo único y comisiones completas de participantes, primero resolver/autorizar la representación. Verificar fotografía única, ceros, varios servicios/variantes, líneas completas H16 y capturas contradictorias sin reinterpretar documentos anteriores. No ensayar esos cambios sobre las tres bases actuales ni activar V2 para eludir el bloqueo.

## 13. Archivos modificados

| Archivo | Cambio |
| --- | --- |
| `app/Services/AgendaService.php` | Guardia de aplicada independiente de V2 |
| `app/Http/Controllers/VentaController.php` | Libro/payload de pagos directos, USD explícito y conservación del vínculo al convertir borrador |
| `tests/Support/H06SaleMemory.php` | Persistencia estricta en memoria sin PDO, exclusivamente tablas sintéticas de venta/pagos |
| `tests/Unit/FacturacionCitaGuardTest.php` | Guardia, estados, vínculo y carrera simulada |
| `tests/Unit/FacturacionCitaPaymentsTest.php` | Controladores/libro, payload, centavos, enlace y fallos |
| `tests/Unit/CitaConsumptionCharacterizationTest.php` | Recetas y límite cero legacy |
| `tests/Unit/CitaCommissionCharacterizationTest.php` | Base bruta y fracciones de comisión pendientes |
| `docs/H06_R_FACTURACION_CITAS.md` | Este informe |

Sin cambios en inventario, captura, comisiones, libro/divisas, contabilidad, nómina, modelos, catálogos, migraciones, rutas, configuración ni documentos históricos. Los tests previos y sus expectativas permanecen intactos.

## 14. Preservación de bases

No se abrieron conexiones ni se escribieron datos en `salon_erp`, `salon_erp_test` o `salon_erp_bootstrap_check`. No se ejecutaron consultas SQL empresariales, DDL, DML, migraciones, seeders, restauraciones o suites generales. Las lecturas de archivos de migración son inspección estática, no ejecución.

Los tests usan autoload sin `phpunit.xml`, `.env`, `.env.testing` ni bootstrap empresarial. PDO está prohibido en los resolutores/dobles; las sentencias Eloquent se interceptan en memoria. No se cargaron credenciales ni se modificaron evidencias AC-04/AC-05, registros históricos o storage empresarial. No hubo operaciones Git de escritura.

## 15. Riesgos residuales

- **Consumo y comisiones compartidas siguen incorrectos en la representación heredada multiestilista.** No se presenta el paso de los tests como corrección de esos defectos. Se necesita intervención de modelo autorizada antes de declarar H06 cerrado.
- Líneas completas H16 sin evidencia no distinguen varias prestaciones. No se eliminaron ni revaloraron automáticamente; su contrato requiere revisión y representación explícita.
- Consumo todo cero legacy sigue volviendo al estándar; directo no sustituye el editor real del POS.
- Retenciones repetidas pueden existir sin ser facturas aplicadas; la idempotencia de solicitudes no está implementada. Payloads USD antiguos pueden ser ambiguos y requieren conciliación antes de aprobar, sin reparación automática.
- Las pruebas no validan contabilidad empresarial AC-05 ni concurrencia/rollback real MySQL. El libro conserva sus conversiones/redondeos existentes; no se rediseñan reglas bancarias o de divisas.
- El bloqueo depende de que todos los escritores respeten `AgendaService` y la misma transacción. No hay nueva restricción SQL de exclusión por ejecución.
- No se redefine quién puede pagar por otro cliente ni se homogenizan descuentos de grupo entre directa y POS; no se inventó una política comercial sobre esos casos.

## 16. Recomendación de continuación

Preparar para aprobación el contrato mínimo persistente de prestación, participación y captura única, con base de comisión completa/bruta y compatibilidad de aprobación/reversión. La regla comercial compartida ya está confirmada; la propuesta no debe usar comisiones o texto como sustituto de esos vínculos. Mantener separados documentos nuevos e históricos sin migración de datos implícita.

Ejecutar regresión integrada únicamente en el laboratorio independiente cuando esté disponible y autorizado. H06-R termina aquí con duplicidad/pagos/vínculo corregidos y consumo/comisiones explícitamente pendientes; no se inician otras fases, migraciones, despliegues ni SQL.
