# H04-B — reversibilidad y evidencia de ejecución

## Estado

Inspección estática terminada. El propietario aprobó la política física conservadora,
el contrato de evidencia, una migración JSON aditiva mínima y la conservación del
costo consumido sin restitución. Esos puntos ya no requieren autorización adicional.
La excepción de agenda y la constatación retrospectiva descritas en las secciones
11 y 12 también fueron aprobadas. Esas secciones conservan el diagnóstico de las
detenciones anteriores, no requisitos de autorización vigentes.

**H04-B está completado y verificado exclusivamente en salon_erp_test.** La
sección 13 describe el contrato, límites, código y verificaciones de esta entrega.
La migración nueva solo se aplicó en salon_erp_test (78 migraciones); no se
desplegó V2 ni se aplicó DDL en las otras dos bases. El launcher restablece
SALON_TEST_DB_RESET_ALLOWED=0 en finally al terminar cada grupo.

## 1. Evidencia disponible

- InventarioService::revertirPorVenta() sigue bloqueando la devolución V2 antes
  del camino heredado. Ese camino devuelve al lote reciente y usa el promedio
  vigente: no debe reutilizarse como devolución histórica V2.
- InventarioFraccionableService::consumir() distingue consumo por volumen de
  salida de envases cerrados y registra las transiciones agregadas de cada lote.
- stock_ledger_lot_allocations guarda lote, movimiento, detalle, cantidad nativa,
  capacidad, unidad, estados cerrados/abierto antes y después y valor asignado.
- reverses_allocation_id es UNIQUE y su FK compuesta exige el mismo artículo y
  lote. Permite una devolución completa por asignación y evita duplicarla.
- La referencia de una asignación inversa permite llegar al movimiento original:
  inversa → asignación original → stock_ledger_entry_id. No es imprescindible
  otra columna de referencia para ese enlace.
- consumption_snapshot conserva cantidades por insumo, modo de captura y medidas.
  No hace falta consultar service_formulas para determinar el consumo original.
- Ninguna columna identifica individualmente cada envase. El lote y su remanente
  son agregados; continuidad física no equivale a igualdad de saldo.
- El UNIQUE lotes_one_open_item_unique impide dos lotes con abierto positivo,
  pero no distingue dos envases abiertos diferentes pertenecientes al mismo lote.

Fuentes: app/Services/InventarioService.php (bloquearEfectosVenta/revertirPorVenta),
app/Services/InventarioFraccionableService.php (consumir/registrar),
app/Services/CapturaConsumoService.php y las dos migraciones aditivas H03-B1.

## 2. El punto funcional que impide implementar una inversión automática

Capacidad 100, un único lote y una venta que consume 20:

| Estado | Cerrados | Abierto | Disponible |
| --- | ---: | ---: | ---: |
| Antes de la venta | 2 | 0 | 200 |
| Después de consumir 20 | 1 | 80 | 180 |
| Inversión literal de estados | 2 | 0 | 200 |
| Restitución al envase que sigue abierto | 1 | 100 | 200 |

Ambos resultados finales concilian volumen y valor. Sin embargo, la inversión
literal volvería a declarar cerrado un envase que ya se abrió. Esto contradice
la decisión V2 de no convertir automáticamente un abierto completo en cerrado.

La alternativa conservadora conserva el envase abierto y repone los 20 hasta 100.
No restaura los contadores cerrados/abierto del pasado, aunque sí el volumen
original y el valor original. El propietario aprobó esta representación, únicamente
cuando exista una restitución física real, documentada y autorizada.

No se implementó ninguna de las dos alternativas.

## 3. Movimientos posteriores: un saldo coincidente no prueba el mismo envase

Continuando el ejemplo, después de la primera venta se consumen otros 100:

| Movimiento | Cerrados antes/después | Abierto antes/después | Consumo |
| --- | --- | --- | ---: |
| Venta original | 2 → 1 | 0 → 80 | 20 |
| Consumo posterior | 1 → 0 | 80 → 80 | 100 |

El segundo consumo agota los 80 del primer envase y utiliza 20 de otro envase.
El abierto actual de 80 pertenece a otro envase del MISMO lote.

Sumarle los 20 de la venta original produciría abierto 100, aritméticamente
válido, pero mezclaría remanentes de dos envases sin evidencia ni política que lo
autorice. El índice de un solo lote abierto no detecta esta diferencia.

La historia de asignaciones permite detectar cambios de envase en situaciones
documentadas; no se debe decidir únicamente por lote, saldo o capacidad sobrante.
Si falta contexto suficiente o no puede demostrarse continuidad, la propuesta es
rechazar toda la anulación, no combinar materiales ni recrear cerrados.

## 4. Política conservadora aprobada, pendiente de contrato de ejecución

Política confirmada por el propietario:

1. Una salida directa documentada de envases cerrados puede devolver esos envases
   cerrados a sus lotes originales, conservando cantidades y valores originales.
2. Un consumo por volumen solo se repone al mismo envase que todavía continúa
   abierto, si puede demostrarse su continuidad y la cantidad cabe sin desbordar.
3. Si queda completo, sigue abierto. No se fabrica un cerrado para restablecer
   el contador anterior.
4. Si se agotó ese envase, se abrió otro, hace falta un segundo abierto o no hay
   evidencia suficiente, se rechaza la anulación completa.
5. No consolidar remanentes entre envases, aunque sean del mismo lote.

Esta aprobación no convierte el motivo o el PIN genérico de anulación en evidencia
de recepción física. Se deben distinguir anulación comercial, devolución real,
corrección de salida errónea y restitución real de volumen. Ese contrato todavía
no existe en el formulario, endpoint o persistencia de anulación.

Alternativa si se necesitan más restituciones: definir una recepción física
documentada y/o identidad individual de envases. Eso requiere otra decisión de
negocio y posiblemente esquema adicional, antes de implementarlo. No es H01-B
ni una autorización para abrir saldos o convertir datos históricos.

## 5. Suficiencia de las asignaciones y casos previstos

| Caso | Tratamiento propuesto, sin implementar |
| --- | --- |
| Venta directa fraccionable | Restituir cerrados y valor original a los lotes asignados, tras validar trazabilidad y límites |
| Servicio con abierto continuo y espacio | Restituir volumen al mismo abierto, sujeto a la decisión pendiente |
| Consumo explícito cero | Ninguna devolución física; comprobar que tampoco haya COGS incompatible |
| Retenido sin efectos | Conservar H04-A: anular sin inventar devoluciones o asientos originales |
| Nuevas compras posteriores | No deben cambiar el lote ni el costo de la devolución; comprobar el abierto actual de todo el artículo |
| Lote agotado | Distinguir devolución de cerrados de restitución a un abierto agotado; no deducir un envase disponible por conveniencia |
| Segundo abierto, desbordamiento o continuidad no probada | Rechazo integral |
| Varios detalles/artículos | Prevalidar la operación completa; una incompatibilidad rechaza todas sus partidas |
| Partidas sin asignaciones suficientes | Rechazo para conciliación; no sustituir por el lote reciente |
| Artículo archivado | Consultar y bloquear con withTrashed, sin reactivar el catálogo, si la evidencia es suficiente |
| Fotografía o asignaciones ausentes | Rechazar sin consultar la receta vigente |
| Asignación ya revertida o venta anulada | Rechazar antes de volver a devolver |
| Fallo intermedio | Rollback de inventario, asignaciones, documento y efectos financieros |

La evidencia de lotes V2 no extiende automáticamente la trazabilidad a productos
no fraccionables: sus salidas actuales no tienen estas asignaciones por lote.
Una venta mixta debe rechazarse si alguna partida necesaria no tiene evidencia
suficiente para una devolución completa. No afirmar que una reversión V2 parcial
convierte una anulación mixta en históricamente exacta.

## 6. Valor original y reversión contable

La devolución debe negar exactamente el valor del movimiento original y el valor
de CADA asignación original, incluidos los residuos de centavos. No redistribuir
otra vez el importe entre lotes ni usar el promedio actual.

El nuevo saldo valorado sería saldo actual + valor original devuelto; el promedio
posterior puede derivarse de ese saldo, sin cambiar el costo de devolución.

ContabilidadService::reversarVenta() ya copia debe/haber invertidos de los asientos
originales y valida el cuadre. Debe mantenerse ese mecanismo. Antes de devolver,
habrá que comprobar coherencia con las salidas documentadas y ausencia de reversas
anteriores; el método por sí solo no certifica que la restitución física sea válida.

VentaController::anular() mantiene la transacción exterior, bloqueo de venta,
comisiones pagadas, pagos/divisas y cambio final de estado. H04-B no debe introducir
commits intermedios ni cambiar impuestos, pagos, precios o cálculo de COGS original.

## 7. Plan técnico una vez aprobado el contrato documental

- Conservar venta/agenda → usuarios → artículos por ID → lotes → movimientos y
  asignaciones, con lecturas actuales dentro de la transacción.
- Incluir artículos archivados en el bloqueo de devolución y validar que el conjunto
  completo de artículos originales esté bloqueado antes de escribir.
- Validar la fotografía almacenada, cantidades por detalle, originales de kárdex,
  cobertura de asignaciones, unidades/capacidad, valores y restricciones físicas.
- Comprobar naturaleza original de salida, original anterior, misma venta/detalle,
  mismo artículo/lote y ausencia de reverses_allocation_id ya utilizado.
- Planificar todas las restituciones antes de escribir y aplicarlas en orden que
  respete la historia y el UNIQUE de abierto. No expandir bloqueos fuera de orden.
- Crear movimientos y asignaciones inversas con vínculos a cada original; conservar
  signo contrario exacto de cantidades y valores, sin recalcular recetas/costos.
- Verificar saldos artículo/lotes/kárdex y suma de asignaciones, y luego permitir la
  reversión financiera existente dentro de la misma transacción exterior.

Archivos potencialmente afectados: InventarioService, InventarioFraccionableService,
lectura histórica de CapturaConsumoService y puntos mínimos de VentaController.
ContabilidadService solo requeriría guardas si falta una comprobación de duplicados
o coherencia; no cambiar su inversión de líneas originales. No se ha determinado
necesaria una migración para la propuesta conservadora.

## 8. Pruebas preparadas como plan, no ejecutadas

- Retorno de cerrado directo y retorno de volumen al mismo abierto, incluidos
  abierto completo, 0,01, varios detalles y varios lotes con costos distintos.
- Compras y consumos posteriores: continuidad demostrable o rechazo sin cambios.
- Agotamiento del abierto, cambio de envase dentro del mismo lote, segundo abierto,
  falta de evidencia y datos heredados: rechazo integral verificable.
- Cero explícito, retención sin efectos, artículo archivado y repetición de anulación.
- Valor original de cada asignación, cuentas y asientos espejo sin duplicados.
- Fallo inyectado después de las primeras escrituras: comparar todos los efectos.
- Dos conexiones MySQL reales: anulación–salida y anulación–anulación, comprobar
  persistencia de ambas solicitudes y cierre de conexiones.
- Regresiones H04-A, H02-A, H03-B1 y H03-B2 focalizadas.

Antes de cualquier ejecución: preflight existente, 77 migraciones, tester restringido,
destino salon_erp_test, respaldo y SHA256, sin conexiones alternativas y restauración
del indicador en finally. No cambiar los guardias para aceptar otras bases.

## 9. Integridad, Git y reversión de esta fase

Esta inspección no abrió conexiones SQL ni ejecutó aplicación/pruebas. Por eso no
se presenta una comparación nueva de hashes de las tres bases como si se hubiera
realizado: queda pendiente junto al ensayo de implementación.

No hubo DML, DDL, cambios de usuarios/permisos, commits ni despliegues. La única
modificación de esta inspección es este informe; no hay cambios funcionales que
revertir. H03-B2 conserva sus protecciones y H04-B no se declara implementado ni probado.

## 10. Dependencia confirmada: intención y evidencia física inexistentes

### 10.1 Evidencia del flujo actual

| Punto inspeccionado | Qué registra o ejecuta | Qué no demuestra |
| --- | --- | --- |
| resources/views/sales/index.blade.php:139-158 | Motivo libre y PIN de supervisor; el texto promete que el inventario regresará | Recepción real, tipo de restitución y material verificado |
| VentaController::anular(), líneas 693-698 | Valida motivo y autoriza la anulación por PIN | Autorización específica de devolución o de corrección física |
| VentaController::anular(), línea 740 | Para documento con efectos invoca revertirPorVenta sin otra decisión física | Que los materiales hayan regresado o que la salida fuera errónea |
| VentaController::anular(), línea 781 | Revierte los asientos originales si existen | Que corresponda reintegrar también el costo y activo de inventario |
| VentaController::anular(), líneas 789-793 | Guarda estado, fecha, usuario ejecutor y motivo | Identidad persistida del receptor/verificador y supervisor físico, ni evidencia de recepción |
| AutorizaPinSupervisor::autorizarPin() | Devuelve el supervisor y escribe un log de autorización de la ruta | Una recepción física vinculada a los movimientos concretos y confirmada atómicamente |
| Venta::$fillable y migraciones de sales | Auditoría de anulación y payload de pagos retenidos | Un contrato estructurado de restitución física |

El controlador ignora el usuario devuelto por autorizarPin(); anulada_por se toma
del usuario de sesión. El log distingue al supervisor, pero describe autorización
de la ruta, no recepción o corrección material, y no sustituye evidencia física
persistida dentro de la transacción del documento.

La búsqueda en controladores, modelos, rutas, vistas y migraciones no encontró
otro flujo de devolución física aplicable que proporcione esa evidencia. Un motivo
como "cliente canceló" o "error de digitación" es ambiguo; no debe analizarse como
si fuera un modo de operación o una confirmación material.

Las asignaciones V2 prueban el débito registrado, lote, cantidades y valor. La
fotografía de consumos prueba lo declarado al vender. Ninguna acredita por sí sola
que el material regresó posteriormente ni que un consumo capturado fuera un error.

### 10.2 Dependencia contable: no basta omitir la devolución de stock

Ejemplo derivado del código, no una prueba ejecutada:

- Consumo real: salida de 20 unidades nativas valorada en C$2,00.
- Original CST: Debe 5.2 C$2,00 / Haber 1.1.5 C$2,00.
- Anulación comercial sin material devuelto: inventario físico y valor del kárdex
  deben permanecer descontados.
- reversarVenta() actual incluye también CST/CMV: generaría Debe 1.1.5 C$2,00 /
  Haber 5.2 C$2,00, aumentando el activo contable sin movimiento físico de retorno.

Por tanto, no sería correcto añadir simplemente un booleano para saltarse
revertirPorVenta() y conservar la reversión indiscriminada de todos los asientos.
La autorización posterior permite diferenciar esos asientos; todavía no se cambió
el código ni se implementaron ajustes compensatorios.

Tratamiento posteriormente aprobado: conservar el costo realmente incurrido cuando
no vuelve material, sin revertir automáticamente sus asientos CST/CMV. La reversión
comercial restante deberá respetar las reglas existentes. Esto no edita el COGS
histórico ni lo traslada a otra cuenta. Se autoriza bifurcar el alcance de la
anulación actual conservando sus líneas originales.

El controlador también anula comisiones pendientes y restaura la cita a pendiente.
La nueva distinción no determina por sí sola qué hacer con esos efectos cuando
el servicio sí fue prestado. No se modifican por inferencia; cualquier excepción
a sus reglas actuales necesita definición dentro del alcance correspondiente.

### 10.3 Contrato mínimo posteriormente autorizado, NO implementado

El propietario autorizó reutilizar el modal y autorización existentes, con las
tres intenciones explícitas y sin devoluciones parciales. El contrato autorizado
incluye los siguientes requisitos; no se han implementado mientras se resuelve
la dependencia de la sección 11:

1. **Intención explícita sin valor por defecto cuando haya salidas aplicadas:**
   anulación sin restitución, devolución física completa o corrección completa
   de una salida errónea. Los tipos físicos cerrado/volumen se obtienen de los
   originales; una corrección no se confunde con recepción de material.
2. **Evidencia de la acción material:** referencia del acta/comprobante o verificación,
   descripción y fecha de recepción o constatación. Una casilla "sí, devolver"
   o el motivo general no basta. El responsable debe confirmar lo comprobado.
3. **Autorización vinculada a esa intención:** recuperar del método existente el
   supervisor identificado por PIN, mostrar el alcance a autorizar y conservar
   su identidad. No guardar PIN, contraseña ni hash en la evidencia.
4. **Alcance completo:** incluir todos los movimientos/asignaciones originales
   necesarios. El servidor determina cantidades y valores; no los acepta como
   importes editables del navegador. Si una partida no es restituible o carece
   de prueba, se rechaza la restitución completa, sin tratarla como devolución parcial.
5. **Persistencia atómica:** guardar decisión, ejecutor, verificador/receptor,
   supervisor, fechas del servidor, referencia documental y originales cubiertos
   junto a las asignaciones inversas y la anulación. Los identificadores de
   responsables se resuelven en el servidor, no se confían al payload.

Las cotizaciones/retenciones sin efectos y los consumos documentados todos en cero
conservan H04-A: no deben exigir una recepción inexistente ni generar devoluciones.

Una corrección de salida errónea debe declarar qué dato fue erróneo y qué estado
físico se comprobó. No autoriza por sí sola a recrear cerrados ni a afirmar que un
envase nunca se abrió: no se añadirá esa deducción como regla automática.

### 10.4 Persistencia aditiva propuesta, todavía sin crear migración

Para una única anulación completa por venta, el mínimo a evaluar es un campo
estructurado nullable en sales, por ejemplo cancellation_inventory_evidence,
con versión de contrato, intención, evidencia y responsables, ligado a la operación
completa. Las asignaciones inversas siguen usando reverses_allocation_id.

motivo_anulacion y observations son texto libre con otros significados;
payload_aprobacion contiene pagos retenidos y se limpia al anular. No se deben
sobrecargar con este contrato ni deducir datos de recepción de textos históricos.

El eventual cambio sería una migración nueva y aditiva, NULL para las ventas
anteriores, sin backfill ni clasificación automática. No se creó ni aplicó ninguna
migración. El esquema definitivo y sus validaciones deben revisarse antes de
aplicarse únicamente en salon_erp_test. No se propone identificar individualmente
envases en esta etapa conservadora.

### 10.5 Continuidad: condición necesaria, independiente de la evidencia de recepción

Incluso con autorización y acta válidas, habrá que leer bajo bloqueo las asignaciones
del lote desde la apertura correspondiente hasta el estado actual, comprobar sus
enlaces y distinguir consumo de volumen de salida de cerrados. Un agotamiento o
una apertura posterior interrumpe la continuidad; coincidencia de saldos no la restaura.
Compras en lotes distintos no acreditan continuidad ni permiten devolver a otro lote.

Después de comprobar continuidad se debe simular la restitución completa, sin
sobrecapacidad, sin segundo abierto y sin conversión automática a cerrado. Si algún
evento no tiene contexto suficiente, se rechaza sin completar historia ficticia.
Este algoritmo sigue siendo un diseño, no código ni verificación ejecutados.

### 10.6 Resultado y siguientes autorizaciones necesarias

El contrato explícito y el alcance contable sin restitución fueron aprobados por
el propietario. La detención vigente responde a la sección 11, no a esos puntos.
No se añadieron controles, formularios, columnas o comportamientos nuevos. Las
pruebas funcionales, carreras, regresiones y comparaciones SQL de las tres bases
quedan pendientes de la implementación.

## 11. Nueva dependencia confirmada: cita prestada y refacturación automática

### 11.1 Qué está aprobado y es viable en el diseño

Un único JSON nullable y versionado en sales es suficiente para una anulación
completa por venta: intención, ejecutor, supervisor, verificador, referencia,
fecha de constatación validada, fechas del servidor y alcance original. No se
necesita otra tabla para este alcance ni modificar referencias de asignaciones.
Los registros previos seguirían en NULL, sin reconstrucción ni backfill.

Las cantidades, lotes, valores y movimientos cubiertos se resolverían desde los
originales bloqueados, no del navegador. Para restitución o corrección, el usuario
autenticado confirmaría la verificación; la identidad del supervisor saldría del
resultado del PIN existente. La evidencia definitiva solo existiría si se confirma
la transacción completa. No contendría secretos de autenticación.

El log de AutorizaPinSupervisor se emite antes de la transacción de anulación:
acredita validación/autorización de la solicitud, no ejecución confirmada ni
recepción de material. Esa semántica debe conservarse y documentarse; la evidencia
JSON transaccional sería la autoridad sobre el resultado persistido. No hace falta
convertir ese log externo en una prueba de commit ni alterar el sistema de PIN.

### 11.2 Inventario de asientos y vínculos revisado antes de la bifurcación

| Origen en ContabilidadService | Propiedad estructurada | Tratamiento autorizado |
| --- | --- | --- |
| contabilizarVenta / contabilizarVentaCredito | referencia_id de venta, modulo_origen ventas; ING y líneas de ingreso/fondos/CxC | Reversión comercial conforme al documento original y reglas compatibles |
| contabilizarCobroVenta | referencia_id de venta, modulo_origen ventas; COB y líneas de fondos/CxC | Revisar como cobro original; conservar las reglas existentes, sin confundir con COGS |
| crearAsientosCostoVenta, rama CST | referencia_id de venta, modulo_origen inventario; débito 5.2 y créditos de inventario verificados contra kárdex | Conservar sin restitución; revertir líneas originales solo con restitución válida |
| crearAsientosCostoVenta, rama CMV | referencia_id de venta, modulo_origen inventario; débito 5.1 y créditos de inventario verificados contra kárdex | Conservar sin restitución; revertir líneas originales solo con restitución válida |

La selección futura deberá comprobar propiedad por venta y módulo, estructura de
líneas, cuentas e importes contra los movimientos originales por detalle. Los
prefijos deterministas sirven como comprobación adicional; no bastan por sí solos.
No se debe usar una descripción libre como clasificador.

Venta::asientosContables() solo filtra modulo_origen=ventas: no incluye CST/CMV.
Por tanto, no bastaría esa relación para certificar todas las partidas de costo.
reversarVenta() actualmente incluye ambos módulos y genera espejos de todos ellos;
todavía no distingue la modalidad sin restitución.

Conservar el CST/CMV original sin generar su espejo mantendría su débito y crédito
equilibrados. Revertir un asiento comercial completo también mantiene partida doble.
No se ha encontrado que esa separación, por sí sola, necesite nuevas cuentas,
impuestos o cambios de método de valoración. Sigue pendiente su prueba en MySQL.

### 11.3 Regla de agenda actual que entra en conflicto

Evidencia estática concreta:

1. VentaController::anular(), líneas 783-785, llama restaurarTrasAnulacion() para
   una venta aplicada vinculada a cita.
2. AgendaService::restaurarTrasAnulacion(), líneas 198-202, cambia completada a
   pendiente. El comentario expresa que conserva la regla de refacturación.
3. resources/views/appointments/index.blade.php, líneas 100-107, muestra Facturar
   para la cita pendiente y la envía al POS con appointment_id.
4. AgendaService::validarParaFacturar(), líneas 164-176, acepta tanto pendiente
   como completada. Dejar la cita completada solamente ocultaría ese enlace:
   no impediría una solicitud directa ni un carrito antiguo.
5. VentaController::facturarCita() y store() pasan por esa validación y vuelven
   a llamar InventarioService::descontarPorVenta(). La aprobación de una venta
   retenida también descuenta inventario.
6. AppointmentAvailabilityTest::test_annulment_keeps_refactoring_state_and_releases_financial_block_only()
   exige explícitamente que la cita vuelva a pendiente tras anular. No se trata
   de una suposición ni de un cambio visual accidental.

Esta es una incompatibilidad de integración al habilitar la nueva modalidad, no
una devolución V2 ya ejecutada: el guardia actual sigue bloqueándola.

Ejemplo verificable por el flujo, todavía sin ejecutar:

- Una cita se completó y produjo consumo real de 20 unidades, COGS C$2,00.
- Se anula comercialmente sin restitución: deben seguir descontadas esas 20
  unidades y mantenerse COGS C$2,00.
- Si se conserva íntegra la regla actual, la cita pasa a pendiente y permite
  facturar de nuevo desde agenda/POS.
- Si se vuelve a registrar la misma ejecución, el flujo normal descuenta otras
  20 unidades y contabiliza otro costo, aun cuando el servicio no se repitió.

Cambiar solo el estado a completada tampoco cierra el servidor, pues ese estado
también es facturable. Prohibir globalmente facturar todas las completadas rompería
el caso legítimo de una cita completada manualmente que aún no fue facturada.
Tampoco sería correcto introducir una factura de reemplazo sin consumo como si
esa operación ya estuviera definida y autorizada.

### 11.4 Política mínima propuesta para aprobación

Para una cita efectivamente prestada que se anula SIN restitución:

- Conservar su estado completada y su ocupación histórica; no devolverla
  automáticamente a pendiente ni abrir por ese motivo la edición comercial H15.
- Bloquear por servidor la refacturación/reconsumo automático de esa ejecución,
  usando el vínculo con la venta anulada y su evidencia persistida. El bloqueo
  debe aplicarse a facturarCita, POS/carritos antiguos y aprobación de retenidas.
- Mantener la facturación legítima de otras citas completadas sin esa operación
  previa. No introducir un estado nuevo ni un bloqueo global de facturación.
- Dejar una eventual factura comercial de reemplazo como operación explícita
  por definir, sin habilitarla automáticamente ni inventar otro consumo.

Esta propuesta cambia la regla existente de reapertura/refacturación en ese caso
concreto. El modo sin restitución describe inventario; no debe usarse por sí solo
como prueba universal de que cualquier servicio se prestó o se dejó de prestar.
Hace falta confirmar el tratamiento de la ejecución de cita, en lugar de deducirlo
de un motivo libre, un PIN o una mera conservación de costo.

No se han modificado AgendaService, sus validaciones, la vista de agenda ni sus
pruebas para introducir esta decisión. Conforme a las condiciones 4 y 9 de la
última autorización, la implementación queda detenida antes de alterar esa política.

La regla actual de comisiones sigue siendo bloquear si ya fueron pagadas y anular
las pendientes al anular la venta. No se decidió ninguna excepción laboral ni se
modificaron pagos o nómina por conservar costos de materiales.

### 11.5 Estado final de esta inspección

Solo se actualizó este informe. No se creó migración, formulario, campo, servicio,
guardia de refacturación ni nueva regla contable. No se ejecutaron PHPUnit, pruebas
concurrentes, migraciones o consultas SQL, y no se modificó ninguna base desde esta
fase. SALON_TEST_DB_RESET_ALLOWED sigue en 0.

La integridad SQL de las tres bases no se certifica con una comparación nueva de
hashes en esta inspección estática; esa verificación permanece pendiente antes
y después de las pruebas de implementación. No se hicieron commits ni despliegues.
La única reversión de esta fase sería documental; no hay código funcional que
deshacer ni motivo para restaurar una base.

## 12. Excepción de agenda aprobada: falta la fuente de constatación

### 12.1 Alcance aprobado

La sección 11 registra una detención anterior, ya resuelta por el propietario.
Está aprobada la protección por servidor de una ejecución efectivamente prestada
con venta anulada sin restitución. Debe conservarse completada, sin refacturación,
reconsumo, reapertura comercial ni pérdida de ocupación histórica. Las citas
completadas nunca facturadas siguen siendo facturables. No se propone cambiar
esta decisión ni solicitar nuevamente su aprobación.

La autorización nueva exige evidencia verificable de prestación. La modalidad
sin restitución, el PIN, el motivo libre y el estado de venta no la sustituyen.

### 12.2 Evidencia estática revisada

| Archivo / método | Dato persistido | Limitación para probar prestación |
| --- | --- | --- |
| CitaController::update y AgendaService::cambiarEstado | status y updated_at de la cita | La acción manual no conserva una constancia de ejecución, su autor ni la procedencia de la transición |
| AgendaService::completarDesdeVenta | status=completada | También se ejecuta automáticamente al facturar y aprobar; no certifica atención real |
| VentaController::facturarCita y store | Venta, detalles y relación appointment_id | El vínculo acredita facturación, no prestación |
| AprobacionService::aprobarVenta | Aprobador y fecha de aprobación comercial; completa la cita | La aprobación autoriza la venta, no una constatación de atención |
| CapturaConsumoService::guardar | consumption_snapshot y consumos por insumo | Fotografía de composición y cantidades; no contiene una declaración verificable de prestación |
| appointments y appointment_user | Datos de cita y estilistas asignados | No existe un registro separado de quién constató la ejecución ni de su documento de respaldo |
| AutorizaPinSupervisor::autorizarPin | Log de autorización y usuario supervisor devuelto | Prueba autorización de una solicitud, no prestación ni commit |

Se revisaron las migraciones y los modelos relacionados, los cambios manuales de
estado, la confirmación JavaScript de agenda y los caminos de facturación y
aprobación. La agenda solo envía `{status}` en su transición; no entrega referencia
documental ni constatación de ejecución. No se consultaron logs con datos de
clientes ni se intentó reconstruir una prestación desde textos libres.

Caso que demuestra la insuficiencia del estado: facturar una cita pendiente
llama completarDesdeVenta y deja completada, aunque esa escritura no reciba
ninguna confirmación de atención. La misma combinación de cita completada,
venta aplicada y captura estándar puede producirse sin una constatación de
prestación. Ni una lectura bajo bloqueo ni una fotografía de insumos resuelven
esa ausencia semántica.

### 12.3 Contrato mínimo propuesto, pendiente de confirmar

Reutilizar el JSON de anulación previamente autorizado y el modal existente,
sin otra tabla, nuevos estados ni identificación individual de ejecuciones:

- Para una venta vinculada a cita, exigir una decisión explícita de constatación
  de prestación, independiente de la intención física de anulación y sin valor
  predeterminado. Ausencia de evidencia no se interpreta como no prestación.
- Para declarar prestación constatada: referencia documental, descripción de lo
  verificado, fecha y confirmación expresa del ejecutor autenticado, además de la
  autorización del supervisor que ya exige la anulación.
- Guardar en `appointment_execution` la versión del contrato, cita, servicio y
  detalles vinculados resueltos por el servidor, fecha de constatación, referencia,
  declaración y las identidades de constatador, ejecutor y supervisor. No aceptar
  IDs de esas identidades, cantidades ni vínculos históricos del navegador.
- Esta sería una constatación documental registrada al anular, no una evidencia
  previa ya existente ni un hecho deducido automáticamente de consumos o estados.
- Si el propietario exige un registro de prestación previo en vez de esta
  constatación, será necesario definir ese registro antes de activar H04-B.
- Mientras el caso no pueda clasificarse de forma verificable, rechazar la
  anulación vinculada a esa ejecución antes de cualquier efecto. No usar la falta
  de constancia para reabrir automáticamente la cita.

La elección pendiente es si esta constatación documentada es evidencia suficiente
para el negocio o si debe existir un registro previo de prestación. Se solicitó
esa precisión; no se añadió un checkbox que por sí solo certifique ejecución ni
se convirtió el PIN en prueba de atención.

### 12.4 Integración preparada y verificaciones pendientes

Una vez fijada la fuente de constatación, el guardia compartido de AgendaService
deberá consultar las ventas anuladas y su evidencia mediante lectura actual bajo
el bloqueo de estilistas/cita, antes de crear o aprobar otro documento. La
anulación conserva ese mismo orden antes de bloquear su venta y registrar la
evidencia, por lo que ambas operaciones se serializan sobre la cita.

El guardia cubrirá validarParaFacturar, facturarCita, store y aprobarVenta. Los
borradores vinculados también deberán comprobarse antes de persistir una nueva
asociación prohibida, sin esperar al descuento de inventario. cambiarEstado y
la edición comercial no deben poder eludir la protección volviendo a pendiente
una ejecución ya constatada. Las notas siguen editables conforme a H15.

La regresión de anulación de AppointmentAvailabilityTest se conserva hasta
implementar su reemplazo por modalidades. Deben probarse por separado:
prestación constatada sin restitución; restitución válida; completada nunca
facturada; evidencia insuficiente; carrito antiguo; retenida; anulación frente
a facturación concurrente con snapshot previo; y ausencia de consumo/COGS doble.
La reversión física y contable completa mantiene las pruebas exigidas en las
secciones anteriores; ninguna está declarada aprobada en esta inspección.

### 12.5 Estado verificable de esta continuación

Solo se modificó este documento. No se cambió código funcional, migraciones,
modelos, modal, configuración ni guardias. No se ejecutaron SQL, PHPUnit,
migraciones, seeders o carreras concurrentes. El indicador local se leyó en 0.
Las 77 migraciones y la integridad SQL son antecedentes del cierre H03-B2, no
comprobaciones nuevas de esta continuación. Se verificarán con el preflight y
las líneas base antes de ejecutar cualquier prueba autorizada de implementación.

No se desplegó V2 ni se modificó ninguna de las tres bases mediante esta sesión.
H04-B sigue sin implementar; su bloqueo V2 existente permanece vigente. La
reversión de esta continuación consiste únicamente en retirar estos cambios
documentales preservando el informe anterior; no requiere restaurar datos.

## 13. Implementación H04-B tras aprobar la constatación retrospectiva

### 13.1 Contrato y responsables

Se añadió un único JSON nullable `sales.annulment_evidence`, versión 1, sin backfill.
Las ventas anteriores conservan NULL. No se modificaron migraciones históricas.

La intención de inventario es obligatoria, sin valor predeterminado:
`sin_restitucion`, `devolucion_completa` o `correccion_completa`. Una devolución o
corrección requiere referencia, descripción concreta, fecha no futura y confirmación
del ejecutor de los lotes y condiciones físicas. La confirmación incluye que los
envases descargados como cerrados siguen cerrados y que el volumen pertenece al
mismo abierto, sin mezclar remanentes. La modalidad no demuestra ese estado físico.
El JSON fotografía la versión de esa declaración y sus condiciones.

Para ventas aplicadas vinculadas a cita, `appointment_execution` exige además una
declaración independiente: `prestada`, `no_prestada`, `no_determinada`; tampoco hay
opción predeterminada. Referencia, descripción, fecha de constatación y confirmación
son obligatorias para constatar prestación o no prestación. La fecha acreditada de
atención es opcional y no puede ser posterior a la constatación. El servidor añade
su timestamp y `retrospective=true`: no se presenta como registro contemporáneo.

El ejecutor y constatador son el usuario autenticado que declara haber verificado;
el supervisor es el usuario devuelto por AutorizaPinSupervisor. La petición no
decide esas identidades ni los vínculos, cantidades, lotes o valores originales.
Se guarda el alcance con venta, cita, servicio, detalles, movimientos, asignaciones
y asientos originales resueltos por el servidor. La constatación de cita incluye
los detalles correspondientes a su servicio; no clasifica otros servicios de una
venta mixta como si pertenecieran automáticamente a esa cita.

El PIN se valida por el mecanismo existente, pero ni él ni credenciales o hashes
se guardan en el JSON. El log del PIN conserva su semántica de autorización de
solicitud, emitido antes de la transacción; no acredita recepción ni commit.
La evidencia definitiva se guarda con los efectos y el estado final en la misma
transacción. También se eliminó el registro de cookies de facturarCita.

### 13.2 Declaraciones y agenda

| Declaración / contexto | Resultado |
| --- | --- |
| Prestada, sin restitución, evidencia válida | Conserva completada, ocupación y notas editables; bloquea facturación ordinaria, nueva asociación documental, consumo, cambios de estado evasivos y edición comercial |
| No prestada, sin restitución, con salida registrada | Rechaza para conciliación; no inventa devolución ni decide otra política sobre el material |
| No prestada constatada, sin salida ni costo originales y con fotografía verificable | Puede anular comercialmente y reabrir; no genera recepción, devolución ni COGS ficticios |
| No determinada en una venta aplicada vinculada a cita | Rechaza sin cambios; no interpreta ausencia como no prestación |
| Con restitución completa válida | Conserva la regla autorizada de reapertura de completada a pendiente, con la misma ocupación; exige una salida física real y todas sus partidas recuperables |
| Cita completada manualmente y nunca facturada | Sigue siendo facturable; no hay prohibición global de completadas |
| Borrador o retenida sin efectos | No genera devoluciones ni clasifica prestación; documenta sin restitución y effects_applied=false |

AgendaService consulta ventas anuladas y la evidencia mediante lectura actual
`FOR UPDATE`, bajo los mutex de estilistas y la cita. No decide por un snapshot
previo ni solo por el estado actual. El guardia compartido cubre facturarCita,
store (incluidas cotizaciones vinculadas y carritos antiguos), completarDesdeVenta
y la aprobación retenida, que ya llama bloquearVenta/validarParaFacturar.
CambiarEstado y la edición comercial no pueden eludirlo. Las notas siguen
permitidas conforme a H15. Una evidencia persistida incompatible requiere
conciliación; NULL no se reclasifica retrospectivamente.

Se comprueba también una venta ya aplicada antes de que la anulación termine:
si el carrito antiguo entra primero, se rechaza por la ejecución ya facturada.
La comprobación excluye únicamente el documento propio que se está completando
o aprobando, cuyo ID proviene del servidor, no de un dato nuevo del navegador.
Esto conserva la aprobación legítima de la retenida y cierra ambos órdenes de
llegada de la carrera anulación–facturación.

La prueba histórica H16 de anulación/reapertura se conservó y parametrizó:
comportamiento heredado, restitución documentada y prestación sin restitución.
La restitución usa ahora una compra real de fixture V2 y un consumo de 20 unidades;
comprueba el reintegro al abierto sin resellarlo. Una captura completa con ceros
no puede declarar devolución o corrección vacía para eludir el guardia: si no
existen salidas, debe elegirse sin restitución. Prestada queda completada y
bloqueada; no prestada constatada sin consumo puede reabrirse sin inventar recepción.
No se creó factura de reemplazo, estado adicional o sistema paralelo de roles.

### 13.3 Restitución física exacta y límites

Los documentos aplicados sin fotografía V2 verificable se rechazan para
conciliación, incluso antes de intentar una restitución. No se reconstruyen ni
se reclasifican desde la receta vigente.
La salida se comprueba contra la fotografía original por detalle e insumo y
contra los movimientos y asignaciones originales. No se consulta la receta vigente.
Se admiten artículos archivados mediante withTrashed solo en los caminos de
restitución/bloqueo; no se reactivan ni se permiten nuevas ventas de archivados.

Los cerrados vuelven a sus lotes originales, con confirmación documental de su
condición. El volumen vuelve únicamente al abierto original que sigue teniendo
continuidad verificable. Cada transición posterior del lote debe enlazar con la
anterior. El agotamiento o una apertura posterior rompe la continuidad, incluso
si el nuevo abierto pertenece al mismo lote y sus saldos coinciden. Las salidas
documentadas de cerrados que no alteran el abierto no lo sustituyen. Las compras
en otros lotes no prueban ni interrumpen por sí solas la continuidad del abierto.

Se conserva `0 <= abierto <= capacidad`; 80 + 20 = 100 sigue abierto. No se reclasifica
como cerrado, no se mezclan abiertos y no se utiliza el lote más reciente como
sustituto. La restricción SQL de B1 y el mutex del artículo siguen vigentes.

Cada asignación inversa tiene `reverses_allocation_id` UNIQUE, el lote y detalle
originales y los signos contrarios exactos de `physical_delta` y `value_delta`.
La devolución conserva el valor y costo unitario informativo originales del
movimiento; no reparte otra vez los centavos usando los costos actuales.
El saldo valorado y su promedio resultante se calculan con el valor histórico
reintegrado, conservando promedio móvil. No se cambia el costo del original.

Los estados cerrados/abierto de la asignación inversa describen la restitución
real, no la inversión literal de estados que resellaría un envase. Por eso el
movimiento puede recuperar todo su volumen y valor dejando un abierto completo.

Se rechaza toda restitución completa con partidas sin asignaciones V2 suficientes,
incluida una mezcla que requiera devolver productos no fraccionables heredados sin
trazabilidad de lote original. No se inventan asignaciones ni se utiliza la
devolución heredada al lote reciente para presentar ese caso como exacto.
La modalidad sin restitución no necesita inventar esa devolución.

### 13.4 Contabilidad, bloqueos y atomicidad

AnulacionVentaService comprueba pertenencia por venta/módulo, líneas y cuentas,
cuadre y concordancia de costos originales contra el kárdex por artículo/detalle.
No clasifica CST/CMV por descripción libre ni solo por prefijo. Un costo o una
cuenta incompatible se rechaza antes de confirmar efectos.

Sin restitución no se registra entrada de stock, asignación inversa ni aumento
del kárdex. Se mantienen CST/CMV y sus cuentas originales 5.1/5.2 e inventario.
Se revierten íntegramente los asientos comerciales originales reconocidos,
conservando las reglas existentes de pagos/divisas y de comisiones: las pendientes
se anulan y las pagadas en nómina bloquean la anulación. No se introdujo excepción
laboral, fiscal o de caja basada en la nueva declaración.

Con restitución válida se usa el espejo existente de las líneas originales,
incluidos CST/CMV, sin cambiar cuentas, impuestos, precios, pagos o COGS originales.
Los asientos y sus líneas se releen con bloqueo para no omitir una aprobación
recién confirmada por tener un snapshot previo. La reversión anidada ahora también
deshace su savepoint ante Throwable, no solo Exception, antes del rollback exterior.

Orden compartido: mutex de estilistas/usuarios y cita, venta y sus detalles
actuales, usuarios necesarios de inventario, artículos únicos por ID ascendente,
lotes FIFO, kárdex/asignaciones y asientos. La lista de artículos se descubre
desde la fotografía y los vínculos originales; se vuelve a comprobar el alcance
actual antes de restituir. La anulación conserva el bloqueo de cita durante toda
la operación, por lo que una nueva factura debe esperar y después leer su evidencia.

No hay commits propios en la restitución ni reintentos financieros nuevos.
Si una partida posterior falla, también se revierten las restituciones anteriores,
asientos, cita, JSON y estado de venta. Las pruebas inyectan tanto excepción de
dominio como TypeError durante las líneas de reversión y comprueban datos y nivel
de transacción anterior/posterior.

Las fotografías de detalle/consumo se leen con bloqueo compartido: son inmutables
en esta operación y la venta permanece exclusiva. Así una anulación puede leer
la historia de otro detalle cuya venta está esperando el mismo artículo, sin
crear una inversión entre las dos ventas. El catálogo común de cuentas también
se lee compartido, sin adquirir bloqueos X incidentales mediante un JOIN.
Una carrera específica comprueba dos anulaciones de documentos distintos sobre
el mismo envase continuo, con ambos commits y conciliación persistida.

### 13.5 Archivos y aislamiento

- Nuevo AnulacionVentaService: validación documental y comprobación de originales.
- InventarioFraccionableService: restitución V2 y continuidad, valores históricos.
- InventarioService: bloqueo explícito de archivados en reversión; salidas intactas.
- AgendaService: guardia de ejecución, transiciones y restauración diferenciadas.
- AprobacionService: pasa la identidad del documento propio al completar la cita; no cambia la contabilización o los pagos.
- VentaController: contrato, supervisor devuelto por PIN, lecturas actuales y coordinación.
- ContabilidadService: selección sin costos cuando corresponde y reversión actual/atómica.
- Venta: cast JSON, sin añadir evidencia al fillable de entrada del cliente.
- Migración 2026_11_14_000004_add_sale_annulment_evidence: un JSON nullable; down rechaza evidencia en uso o transacción activa antes del DDL.
- Vista sales/index, resources/js/annulment.js y app.js: contrato en modal existente y comprobación previa al PIN. El camino heredado no depende del bundle nuevo cuando V2 está inactivo.
- Tests nuevos de instalación, contrato/restitución, carreras, guardia y frontend; actualización de las regresiones H16/H03-B2 y auxiliares existentes.

Se reutiliza la puerta H03-B2: solo testing + conexión salon_erp_test/tester y
activación explícita en fixtures. No se amplió a desarrollo ni bootstrap_check.
El runner valida PHP 8.3.30, identidad, puerto, roles, privilegios exactos y el
rechazo de acceso a ambas bases; verifica el respaldo SQL y su SHA256 y exige las
77 migraciones previas o las 78 tras instalar la nueva, incluyendo sus nombres.
No se añadieron dependencias ni se modificaron credenciales, APP_KEY o AGENTS.md.

Respaldo preexistente verificado, sin sobrescribirlo:
`E:/salon-erp-backups/salon_erp_test_20261112_074028_3813668.sql`.
SHA256: `8778320250e84322267a7d99bf86e18f70c099d0117ea5b73dad67c2c32aa531`.

### 13.6 Verificaciones y cierre

La ejecución definitiva `h04b-final`, después de la última modificación funcional,
terminó con código 0: **223 pruebas PHP y 1.714 aserciones aprobadas**, sin fallos.
Incluye H12, H13, H14-A, H15, H16, H01-A, H02-A, H04-A, H03-B1 y H03-B2,
contrato y restitución H04-B, guardia de evidencia en uso y carreras MySQL.
No se ejecutó la suite general ni Composer test. La instalación aditiva se probó
por separado antes de las regresiones: 1 prueba y 9 aserciones aprobadas; se
verificaron los valores previos, NULL y down/up de la columna aún sin evidencia.

JavaScript definitivo: **45 pruebas aprobadas** (contrato/modal, agenda,
inventario y captura POS). El build Vite terminó con código 0 en una carpeta
privada, sin desplegar assets. Las pruebas del formulario incluyen validación
antes de abrir el PIN, conservación de entradas, render HTTP de Blade y fallback
heredado. No equivalen a una comprobación manual completa en navegador.

Cinco carreras H04-B con conexiones independientes, consultas iniciales
deliberadamente antiguas y comprobación del estado realmente persistido:

| Carrera | Conexiones finales | Commits A / B | Resultado físico |
| --- | --- | --- | --- |
| Anulación sin restitución → carrito antiguo de cita | 2510 / 2511 | sí / no | Se conservan 80 unidades nativas, valor C$8; no segundo consumo |
| Carrito antiguo → anulación | 2513 / 2514 | no / sí | La venta aplicada ya impide duplicar la ejecución; queda el mismo saldo |
| Devolución de cerrado → salida nueva | 2516 / 2517 | sí / sí | Se reintegra una vez y se consume una vez; saldo y valor cero |
| Dos anulaciones de la misma venta | 2519 / 2520 | sí / no | Una única devolución de 100 unidades, valor C$10 |
| Dos anulaciones distintas del mismo abierto continuo | 2522 / 2523 | sí / sí | Abierto de 100, sin resellar; valor C$10 y sin inversión de locks |

Los workers terminaron y sus conexiones desaparecieron de PROCESSLIST. La
evidencia resumida está en `storage/app/private/h04b-races.jsonl`; las regresiones
también repiten las carreras H02-A y H03-B2. No se empleó paralelismo de suite.

Conciliación por cuenta comprobada: sin restitución permanecen los saldos de
5.2 y 1.1.5 del consumo y se revierten los movimientos comerciales 4.1/1.1.1 de
la venta; cada espejo sigue equilibrado. Con restitución se verifica la inversión
exacta de 5.1/5.2 e inventario por sus cuentas originales. El caso de lotes con
costos distintos devuelve C$30,01 tras una compra posterior de C$40: saldo
C$70,01, sin usar el promedio vigente como costo de devolución. Cada asignación
recupera exactamente los centavos del original.

Comparación final de todos los conteos, hashes de contenido y DDL contra
`storage/app/private/isolation-H04B-before.json`:

| Base | Antes: tablas / filas / migraciones | Después | Comparación |
| --- | --- | --- | --- |
| salon_erp | 78 / 225 / 74 | 78 / 225 / 74 | Contenido, conteos y estructura idénticos |
| salon_erp_bootstrap_check | 78 / 202 / 74 | 78 / 202 / 74 | Contenido, conteos y estructura idénticos |
| salon_erp_test | 79 / 859 / 77 | 79 / 542 / 78 | Cambios autorizados de esquema y fixtures; reconstruida por RefreshDatabase |

El launcher imprimió `SALON_TEST_DB_RESET_ALLOWED=0` desde finally y se confirmó
también leyendo exclusivamente ese indicador. No se modificaron usuarios o
privilegios SQL; no se restauraron respaldos ni se desplegaron cambios en desarrollo.
Los 18 archivos PHP nuevos/modificados pasaron `php -l`; `git diff --check` pasó
sin errores de whitespace (Git avisa únicamente de normalización CRLF/LF en el
launcher). Configuración, dependencias, AGENTS.md, CSS y public/build no tienen
cambios en el diff de esta entrega.

Comandos principales (PHP absoluto de Laragon en todos los casos):

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-h16-tests.php h04b-preflight
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/isolation-snapshot.php before H04B
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-h16-tests.php h04b-migrate
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-h16-tests.php h04b-final
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/isolation-snapshot.php after H04B
node --test tests/Frontend/annulment.test.cjs tests/Frontend/agenda.test.cjs tests/Frontend/pos-consumption.test.cjs tests/Frontend/inventory.test.cjs
npm.cmd run build -- --outDir storage/app/private/h04b-build-20261114-02
```

`h04b-preflight`/`h04b-migrate` son pasos de instalación ya ejecutados que exigen
77 migraciones; no deben repetirse sobre las 78 actuales. Los grupos intermedios
functional/races/regressions/close permitieron localizar fallos y validar los
cambios sucesivos; sus resultados no sustituyen la pasada definitiva anterior.

Los fallos iniciales de desarrollo se corrigieron antes del cierre: el test no
debe asumir que supervisor y ejecutor son la misma persona (los fixtures antiguos
comparten PIN), y el guardia de refacturación pertenece a la validación de
facturación, no al bloqueo genérico que también necesita la edición de notas.
No se desplegó ninguno de esos estados intermedios.

Vite definitivo compiló en `storage/app/private/h04b-build-20261114-02`, sin actualizar
public/build. Se mantiene la advertencia opcional de fontaine; no se instaló.

### 13.7 Riesgos y reversión segura

La constatación es una declaración operativa autorizada con referencia documental;
el sistema no valida la autenticidad de un acta externa ni acredita atención
contemporánea. No hay devolución parcial ni factura comercial de reemplazo.
Las historias sin evidencia suficiente y los casos de continuidad interrumpida
permanecen bloqueados; requieren conciliación, no una reparación automática.

La lectura de historia V2 conserva el costo de recorrer movimientos/asignaciones
bajo bloqueo; no se hizo una prueba de carga de historiales extensos. Las carreras
controladas acreditan los escenarios ejecutados, no todos los interleavings posibles.
No se certifica por estas pruebas la entrega física de efectivo a un cliente.

Diff completo de código, incluidos los archivos nuevos:
`storage/app/private/h04b-code-review-verified.diff` (105.983 bytes).
SHA256: `d06df37f3c8def7e91e43763ba736178dfaa3bbf39e9c05d9077f2febc2bbf13`.
Se ejecutó correctamente `git apply --reverse --check` sobre ese artefacto;
no se aplicó su reversión. El documento de auditoría, ya existente sin seguimiento
al inicio, se conserva fuera de ese parche para no borrarlo al revertir código.
Git mantiene 14 archivos de código modificados, 8 archivos nuevos de código/pruebas
y este informe sin seguimiento; no se crearon commits ni se alteró el índice.

Reversión: mantener V2 inactivo fuera de test, conservar respaldo y JSON/asignaciones.
No ejecutar un down con evidencia usada ni borrar datos para hacerlo pasar. Revisar
el diff antes de retirar exclusivamente los cambios de H04-B; no usar reset global
ni sobrescribir otros trabajos. Si se vuelve al código previo, no permitir que ese
código procese citas con esta evidencia, pues no conoce el guardia nuevo: conservar
el entorno V2 cerrado hasta resolver su compatibilidad. Las bases originales no
necesitan restauración al no haber sido modificadas por esta entrega.
