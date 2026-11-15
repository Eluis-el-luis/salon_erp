# H01-B1 — Diagnóstico y diseño de apertura documentada

Estado: diseño para revisión, sin implementación H01-B2. La contrapartida contable
no está decidida. No se autoriza usar una cuenta por su nombre o disponibilidad.

## 1. Alcance y verificaciones realizadas

Inspección de rutas, controlador, H01-A, StockLedger, Articulo, Lote,
InventarioService, InventarioFraccionableService, CompraService, H04-B,
migraciones B1, catálogo/seeders contables, autorización PIN y lectores del kárdex.
No se ejecutaron pruebas, DML, DDL, migraciones, seeders o conversiones.

Única inspección SQL: consultas SELECT sobre salon_erp_test y metadatos de su
esquema, usando PHP 8.3.30 y salon_erp_tester@localhost. Se confirmó MySQL 8.4.3,
puerto 3306, CURRENT_ROLE()=NONE y 78 migraciones. No se inició Laravel para esas
consultas. No se consultaron datos de personas ni credenciales en los resultados.
SALON_TEST_DB_RESET_ALLOWED se mantuvo en 0.

Desarrollo/ensayo conservan las 74 migraciones indicadas por el propietario;
no se consultaron ni modificaron esas bases en esta fase. No se afirma una nueva
comparación completa de sus contenidos. No se desplegó V2/H04-B.

Git estaba limpio al comenzar. El único archivo añadido en esta fase es este
informe; no hay cambios funcionales, configuración, CSS, AGENTS.md o migraciones.

## 2. Qué hace la apertura heredada

Evidencia: routes/web.php:335; InventarioController::registrarApertura, línea 239;
StockLedger::registrarApertura, línea 205; inventory/kardex.blade.php.

- La ruta pertenece al grupo web autenticado role:admin; RoleMiddleware permite
  también el bypass existente de alvaro. El formulario incluye CSRF.
- El endpoint solo valida item_id. No recibe conteo, acta, fecha efectiva,
  procedencia de costo, lotes, vencimientos o PIN supervisor.
- Lee el Articulo antes de iniciar la transacción, sin bloquearlo.
- Si existe cualquier kárdex devuelve null; si no, copia existencia_actual y,
  para fraccionables, E × capacidad + current_volume.
- Usa StockLedger::registrar con costo null: obtiene un costo por fallback de
  lotes/costo promedio; no es una valoración inicial documentada.
- Registra fecha=now, origen=apertura y referencia_tipo=apertura,
  referencia_id=ID del artículo, no ID de un documento de conteo.
- No aumenta el stock del artículo, no crea lotes ni asignaciones y no genera
  asiento, compra, CxP o pago. Solo incorpora al libro el saldo que ya existía.
- Rechaza explícitamente artículos inventory_representation_version=2.
- La vista solo muestra el botón para saldo heredado positivo sin kárdex. El
  endpoint no impone esa misma condición: con cero puede crear un ajuste cero.

No es una entrada física inicial compatible con H01-A: después de crear un
artículo vacío no existe stock que ese método pueda incorporar. Registrar el
kárdex solo tampoco resuelve la cobertura FIFO de las salidas H02-A.

La comprobación exists no se serializa con el mutex del artículo ni dispone de
una clave documental única. Es posible plantear dos solicitudes que lean ausencia
antes de que la primera confirme; una lectura posterior del último saldo no
repite aquella condición. El resultado concreto de carreras/bloqueos no se ensayó
en esta fase. No debe presentarse como idempotencia garantizada.

Recomendación para B2: mantener diferenciados «documentar saldo heredado» y
«ingresar existencias iniciales verificadas». No reutilizar silenciosamente el
POST antiguo con una semántica nueva ni dejarlo como vía alternativa del contrato.
Su contención/retiro deberá incluirse expresamente al autorizar B2. No se cambia aquí.
No basta ocultar el botón ni confiar en el marcador leído antes de la transacción:
esa ruta puede haber cargado un Articulo heredado antes de que una apertura
documentada lo clasifique/bloquee. No habilitar el escritor nuevo mientras exista
un camino de apertura que no participe del mismo protocolo de servidor.

## 3. Protección de catálogo y elegibilidad propuesta

H01-A permanece: datosCatalogo usa missing para existencia_actual/current_volume
(rechaza incluso cero/null recibidos); store inicializa ambos en cero; update
solo utiliza campos validados. Tipo, unidad, fraccionamiento y capacidad quedan
protegidos si hay stock, lotes, kárdex, documentos, consumos, fórmulas o mermas.
Los fillable físicos se conservan por los escritores documentales legítimos.

La apertura no será una actualización del catálogo ni enviará un fill completo al
modelo. El servicio documental validará el conteo y escribirá cantidades explícitas.
No cambiará tipo, unidad o capacidad para hacer coincidir datos recibidos.

Propuesta conservadora para una primera apertura por artículo:

1. Artículo activo, físico, unidad/capacidad inequívocas; nunca type=servicio.
2. Agregados físicos cero y sin valor heredado pendiente de explicación.
3. Sin lotes, movimientos o asignaciones previos, aunque estén agotados o en cero.
4. Revisar documentos/consumos/mermas y capturas históricas que lo referencien,
   incluidos documentos pendientes; no aprobar una apertura sobre historia ambigua.
5. No usar la receta vigente para reconstruir referencias históricas: revisar las
   fotografías documentadas y los enlaces reales. Una fórmula de catálogo por sí
   sola no es una salida física y puede coexistir si sus medidas no se modifican.
6. Releer todo bajo el mutex del artículo antes de escribir. Si una compra u otra
   operación ganó la carrera y creó historia, rechazar la apertura completa.

Artículo fraccionable vacío con marcador NULL: no clasificar por lote masivo ni
por inferir el significado de antiguos saldos. B2 debería exigir una aceptación
explícita y documentada del modelo V2, verificando ausencia total de historia y
cantidades cero antes de marcarlo 2 dentro de la misma transacción. Esta
habilitación individual de un artículo vacío es una propuesta a aprobar con B2,
no una conversión histórica autorizada por este análisis. Con historia, bloquear.

Un artículo que tuvo apertura o actividad no vuelve a ser «nuevo» al agotarse.
La apertura no sustituye ajustes, conciliaciones o devoluciones.

## 4. Modelo documental y evidencia

Un acta completa produce una operación atómica, con UUID único y todas sus líneas.
No confirmar parcialmente los artículos válidos si otro es incompatible.

Cabecera propuesta: versión de contrato, UUID/idempotency key, referencia de acta,
fecha efectiva/corte, fecha y responsable del conteo, ejecutor autenticado,
supervisor resuelto por PIN, timestamp de registro del servidor, motivo/descripción,
hash canónico de la solicitud, total NIO, política/cuenta de contrapartida aprobada
y enlace al asiento confirmado.

Detalle por posición/lote real: artículo, clase unidades/cerrados/remanente,
cantidad verificada, unidad y capacidad fotografiadas desde catálogo, código
físico del lote cuando conste, vencimiento conocido o ausencia justificada,
procedencia documental del costo, importe NIO reconocido y costo informativo,
estado físico inicial, lote ERP y movimiento de kárdex resultantes.

No inventar un número de lote de fabricante. Si no se conoce, distinguir
expresamente la referencia interna «acta + posición» del lote físico desconocido,
documentando la limitación y requiriendo aprobación de esa excepción antes de B2.
El esquema actual exige lotes.lote no null, máximo 100 caracteres: no disfrazar
ese requisito técnico como evidencia de un lote de fabricante.

Para el mínimo inicial, proponer que el ejecutor autenticado confirme el conteo
bajo su responsabilidad; registrar por separado counted_by y executed_by aunque
coincidan. Si el contador físico es otra persona, definir su constatación antes
de admitirla: no aceptar un ID arbitrario que simule su confirmación.
El PIN resuelve al supervisor real por el mecanismo existente; no guardar PIN,
contraseñas o hashes. Su log acredita autorización del intento, no el commit.

Fecha efectiva no futura y período abierto según ContabilidadService. Guardar
recorded_at separado: un conteo retrospectivo no se presenta como contemporáneo.
Debe acreditarse que no hubo cambios físicos externos entre conteo y confirmación;
los locks SQL no impiden que una persona utilice material fuera del ERP.

## 5. Modelo físico, precisión y ejemplos

No fraccionable: unidades enteras positivas; current_volume=0. La representación
NULL existente corresponde aquí al flujo de unidades, no se fuerza marcador 2.

Fraccionable V2: E=envases cerrados; R=remanente del único abierto; C=capacidad;
Q=E×C+R. E entero no negativo, 0≤R≤C; abierto completo sigue abierto.
Un único lote por artículo puede tener R>0. No sumar dos abiertos para simular uno.
La prioridad del abierto sobre cerrados FIFO sigue siendo la excepción aprobada
H03-B2. No cambiar el algoritmo ni añadir conversiones ml/gr/oz.

Valores de ejemplo, no operaciones ejecutadas (C=100 ml; C$10 por envase):

| Conteo | Artículo E / R | Lotes iniciales | Q | Valor |
| --- | --- | --- | --- | --- |
| No fraccionable: 3 unidades a C$15 | 3 / 0 | Entrada/disponible=3, campos V2 NULL | 3 unidades | C$45 |
| Dos cerrados | 2 / 0 | Entrada/disponible=2; volumen_entrada=200; abierto=0 | 200 ml | C$20 |
| Solo abierto con 60 ml | 0 / 60 | Entrada/disponible=0; volumen_entrada=60; abierto=60 | 60 ml | C$6 |
| Dos cerrados y abierto de 60 ml | 2 / 60 | Dos posiciones: cerrados 200 y remanente 60 | 260 ml | C$26 |
| Abierto completo | 0 / 100 | Entrada/disponible=0; volumen_entrada=100; abierto=100 | 100 ml | C$10 |

Para el abierto inicial NO se registra entrada de un cerrado, salida ficticia
del resto, compra ni merma de 40 ml. B1 admite directamente:
cantidad_entrada=0, cantidad_disponible=0, volumen_entrada=60,
volumen_abierto=60, capacidad_envase=100, unidad_nativa=ml, versión=2.
El CHECK 0×100≤60 y disponible 60≤volumen_entrada 60 se cumple.
Una asignación inicial (cerrados 0→0, abierto 0→60) tiene physical_delta=+60.

Las posiciones de cerrado y abierto pueden documentar el mismo lote externo,
pero son posiciones ERP distintas identificadas como tales, sin duplicar cantidades.
No se inventan dos lotes de fabricante. Separarlas permite fotografiar sus costos
y evitar suponer que el remanente procede de los envases cerrados contados.

Límites actuales comprobados en SQL y CHECK:
E agregado ≤2147483647; por lote cerrado ≤99999999; C/R decimal(8,2), máximo
999999.99; Q físico máximo de las guardas 99999999999.99. Importes decimal(15,2)
máximo 9999999999999.99; costos informativos decimal(15,6), máximo
999999999.999999. Validar también acumulados y todos los costos derivados antes
de PDO, con enteros escalados/BCMath, sin float para nueva aritmética monetaria.

No redondear cantidades con más de dos decimales; unidades no fraccionables enteras.
No interpretar oz como masa o volumen ni convertirla automáticamente.

## 6. Kárdex, lotes, asignaciones y valoración

Recomendación: un movimiento inicial por posición/lote, enlazado al mismo acta.
Cada uno conserva su importe documental propio. Para V2, una asignación por ese
movimiento/lote, con estados 0→conteo, valor positivo exacto y sin referencia de
reversión. La suma de movimientos por artículo y de asignaciones debe conciliar
con acta, artículo, lotes y asiento.

Motivo: el registrador V2 privado distribuye un valor entre cambios por proporción
física. Una única entrada de dos lotes de igual volumen valorados C$10 y C$20
repartiría C$15/C$15 si se reutilizara indiscriminadamente. Entradas separadas
conservan C$10 y C$20, dejando saldo global C$30 y promedio móvil correcto.
Esto no cambia la valoración contable posterior a FIFO.

No usar CompraService::registrarFactura para crear la apertura. Crear lotes con
purchase_invoice_line_id=NULL; el nuevo documento los enlaza directamente.
La fecha de entrada al ERP será el corte documentado, no una compra histórica
inventada. Si se necesita un orden FIFO anterior acreditado, documentarlo antes
de confirmar; no inferir fechas desde nombres o vencimientos. FIFO no es FEFO.

El importe total verificado de cada posición será autoritativo, pendiente de
aprobar la base de valoración. No usar precio de venta ni un costo por fallback.
Para un remanente de 60 ml valorado en C$6, lote.costo_unitario guarda C$10 por
envase equivalente, no C$6 por envase. El kárdex guarda C$0,10 por unidad nativa;
el importe de entrada sigue siendo C$6. No recalcularlo multiplicando un costo
informativo previamente redondeado. La procedencia y los residuos se conservan.

StockLedger::registrar no acepta importe total autoritativo ni fecha efectiva;
recibe floats/costo unitario, redondea a seis decimales y fija fecha=now.
Se necesita un método de apertura delimitado en el servicio/modelo existente,
con valor y fecha explícitos; no alterar los cálculos de los otros escritores.
En V2, proponer un método de ingreso de apertura en InventarioFraccionableService,
reutilizando sus verificaciones/registrador para una posición por vez. No adaptar
una línea de compra ficticia. Toda esta extensión queda sin implementar.

En V2 saldo_cantidad/cantidad son envases equivalentes a cuatro decimales,
NO el contador de cerrados; saldo_volumen es Q nativo. Ejemplo mixto: entradas
2.0000/+200/C$20 y 0.6000/+60/C$6; saldo 2.6000/260/C$26, pero artículo E=2,R=60.

Las asignaciones B1 solo permiten ml/gr/oz y capacidad positiva. No caben unidades
no fraccionables: NO insertar und como ml ni capacidad ficticia. Para unidades,
la nueva línea documental enlaza lote y movimiento; permanece el flujo H02-A.
Ampliar la tabla V2 para unidades sería otro alcance, innecesario para esta apertura.

## 7. Contrapartida contable: decisión pendiente

El catálogo fuente y el consultado en test contienen 1.1.4 Productos, 1.1.5 Insumos,
3.1 Capital Social, 3.2 Utilidades Retenidas y 3.3 Retiros del Propietario.
No se encontró una cuenta específica de apertura ni una configuración que autorice
esa contrapartida. La consulta en test no certifica cuentas locales adicionales
que pudieran existir en desarrollo, cuyo catálogo no se consultó.

Débito propuesto: inventario 1.1.4/1.1.5 según codigoInventarioArticulo(), igual
que entradas/salidas existentes. Crédito: PENDIENTE de decisión contable.
No usar ingresos, compras/proveedores, pagos, 3.3 o 7.2 como sustitutos.

Alternativas para el propietario/responsable contable:

1. Cuenta patrimonial específica de saldos iniciales, con código y uso aprobados
   expresamente. No atribuye automáticamente el saldo a una nueva aportación.
2. 3.1 solo si el hecho real es una aportación del propietario y está documentado;
   tener físicamente inventario antiguo no demuestra ese hecho.
3. Integrar el inventario en un balance de apertura completo aprobado por el
   responsable contable. Si requiere otros activos/pasivos históricos, pertenece
   a otro alcance; no inventarlos desde esta operación de inventario.

No elegir automáticamente 3.2 ni reclasificar patrimonio existente. La apertura
de inventario aislada no acredita el patrimonio neto de todo el negocio.
También deben aprobarse fuentes de costo admitidas, eventual costo cero con
justificación y valoración NIO de documentos externos en otra moneda. No crear
IVA, diferencias cambiarias o revaluaciones por inferencia.

La futura contabilización deberá usar el importe exacto del acta, resolver el
período de su fecha efectiva, validar cuenta activa/de movimiento y ejecutar
validarCuadre() en centavos dentro de la transacción. Módulo/referencia propios
de apertura, evitando colisiones de IDs con ventas/compras. No producir balance
de inventario sin su asiento ni un asiento sin stock/lotes confirmados.

Esta decisión bloquea la habilitación de H01-B2. El diseño físico no decide una
política patrimonial nueva y no se creó ni seleccionó ninguna cuenta.

## 8. Autorización, idempotencia y protocolo transaccional

Proponer sesión web + CSRF + roles actuales de inventario y PIN estricto supervisor.
No ampliar acceso a recepción ni crear roles paralelos. Las identidades del
ejecutor/supervisor se resuelven en servidor. Las cantidades son nuevos datos de
conteo: deben validarse y constatarse, no derivarse de campos ocultos del catálogo.

Idempotencia durable: UUID único y hash de contenido canónico (acta, fecha,
artículos, posiciones, medidas, cantidades, importes y evidencias). Excluir PIN,
CSRF y timestamps generados al procesar. Mantener el mismo UUID tras refresh o
respuesta de red incierta; conservar el orden de posiciones si determina el FIFO.

- Mismo UUID/mismo contenido y actor autorizado: devolver resultado original,
  sin repetir stock, asiento o autorizaciones documentales persistidas.
- Mismo UUID/contenido distinto: conflicto, no sobrescribir.
- UUID nuevo/artículo ya abierto: rechazar por historia, aunque el saldo sea cero.
- Dos UUID para el mismo artículo: el mutex permite una apertura y la otra falla.

Secuencia propuesta: validar formato/rol/PIN; iniciar transacción; bloquear usuarios
necesarios en orden estable; reclamar cabecera por UUID con UNIQUE; bloquear todos
los artículos por ID ascendente; releer elegibilidad e historia una sola vez por
artículo; registrar posiciones/lotes, artículo, kárdex y asignaciones; crear asiento,
enlazar resultados y verificar conciliación; commit único.
Reutilizar bloquearUsuarios/bloquearArticulos y el orden de lotes/kárdex de H02/H03.
No bloquear primero un artículo distinto por cada línea del formulario.

Si dos inserts del UUID compiten: una violación UNIQUE identificada debe deshacer
la transacción de la segunda solicitud y resolver el resultado mediante una lectura
actual del documento confirmado y comparación de hash/permisos. Eso no reintenta
la operación financiera. Si la primera revierte, el otro insert puede ser el único
que confirme. No usar firstOrCreate como garantía suficiente ni un lock de una
fila de movimiento inexistente. No añadir reintentos financieros automáticos.

Cabecera con asiento/vínculos incompletos no se considera éxito idempotente:
rechazar para conciliación. No persistir documentos «confirmados» fuera de la
transacción, ni utilizar el log PIN como prueba de confirmación.

## 9. Migración mínima propuesta, no creada

Una migración aditiva podría crear dos tablas; no necesita modificar columnas B1:

- inventory_openings: ID, UUID UNIQUE, contrato/evidencia versionados, hash,
  referencia de acta propuesta UNIQUE dentro del negocio, fechas, responsables,
  total/cuenta aprobada y asiento FK RESTRICT. El asiento puede estar pendiente
  solo dentro de la transacción; un resultado confirmado debe tenerlo completo.
- inventory_opening_lines: cabecera, posición UNIQUE por cabecera, artículo,
  clase física y fotografías tipadas/JSON, cantidades/valor, procedencia del costo,
  lote y movimiento resultantes con referencias UNIQUE y FKs RESTRICT.

Una línea representa un lote/posición; un artículo puede tener varias líneas.
No poner UNIQUE(item_id) en líneas, que impediría varios lotes reales. La apertura
única por artículo se garantiza por su mutex y la historia inmutable releída.
Los pares lote/artículo y movimiento/artículo pueden enlazarse con las claves
compuestas (id,item_id) que B1 ya creó, evitando referencias cruzadas de artículos.

Crear líneas al disponer de lote y movimiento dentro de la misma transacción;
los IDs del resultado no los suministra el navegador. Las asignaciones V2 ya
enlazan esos movimientos y lotes; no precisan una columna nueva de apertura.
Los registros existentes no se rellenan ni clasifican. down solo sobre instalación
sin documentos/evidencia: si hay uso, rechazar antes de DDL, sin cascadas.

## 10. Operaciones posteriores y límites de compatibilidad

| Flujo | Compatibilidad que debe probar B2 |
| --- | --- |
| Compra posterior | Otro lote cerrado; no aumenta el abierto; concilia promedio móvil con apertura |
| Consumo/merma V2 | Usa primero el abierto inicial, después cerrados FIFO; cada salida una sola vez |
| Venta directa fraccionable | Solo cerrados; un abierto completo sigue sin contar como cerrado vendible |
| Venta/merma de unidades | Conserva H02-A, enteros, cobertura completa FIFO y locks |
| Anulación de venta V2 | H04-B puede reintegrar al lote de apertura si conserva continuidad y evidencia; no requiere compra original |
| Abierto inicial de 60, consumo 20 y devolución viable | 60→40→60; no 100 ni un cerrado; se respeta también volumen_entrada=60 |
| Original agotado/nuevo abierto | Se rechaza restitución conforme a H04-B, incluso dentro del mismo lote |
| Devolución de venta no fraccionable con V2 activo | Sigue la limitación H04-B: salidas sin asignaciones V2 suficientes no admiten devolución exacta; la apertura no la resuelve |
| Anulación de compra | Solo lotes de su factura; no alcanza lotes de apertura con purchase_invoice_line_id=NULL |
| Reportes | Deben mostrar acta/unidad nativa, saldo equivalente frente a cerrados, valor y lotes abiertos iniciales |

H04-B revierte ventas, no el documento de apertura. No reutilizar esa anulación
para «deshacer» una apertura ni restar saldos que ya pudieron consumirse. Una
corrección/anulación de apertura necesita un contrato propio a aprobar; no se
incluye automáticamente en B2 ni se permite editar/borrar lo confirmado.

Mantener una fecha de corte operativa: documentos antiguos incluidos en el conteo
no deben ingresarse después otra vez como compras físicas. Los escritores actuales
no garantizan por sí solos esa política de implantación. Antes de activar B2 hay
que aprobar cómo se validarán fechas/documentos pendientes en sus puntos de entrada,
sin cambiar el algoritmo físico o contable H03/H04. No reconstruir el pasado.

KardexExport conserva encabezados «ml» y «Und.»: requerirá etiquetas documentales
y nativas adecuadas para que una apertura en gr/oz no se interprete mal. No cambiar
valoración ni usar este trabajo para resolver overflow UI-01.

## 11. Plan incremental H01-B2, condicionado a autorización

1. Aprobar contrapartida y base de costo, corte, identidad del contador físico,
   tratamiento de lotes desconocidos/caducados y clasificación individual de vacíos.
   No habilitar existencias mientras falte el criterio contable.
2. Diseñar/probar la migración aditiva y contrato documental, con FKs, idempotencia
   y bloqueo de down en uso; exclusivamente en test y con autorización de ejecución.
3. Implementar apertura de unidades y escritor de valor/fecha explícitos, en los
   servicios actuales. Verificar que no se alteran otros escritores/valoración.
4. Implementar ingreso inicial V2 cerrado/remanente sin inventar cerrado previo,
   con un movimiento/asignación por posición y conciliación por artículo/acta.
5. Incorporar el asiento aprobado dentro de la misma transacción y comprobar sus
   cuentas. Ninguna compra, CxP, pago, ingreso, comisión o movimiento de divisas.
6. Añadir formulario de conteo/previa/confirmación y estado consultable por UUID,
   autorización existente y contención explícita de la ruta heredada incompatible.
7. Ejecutar focalizadas y regresiones inspeccionadas, revisar diff y resultados.
   Mantener guardas y no activar en desarrollo. Despliegue, conciliación histórica
   y anulación de apertura requieren autorización separada.

## 12. Pruebas propuestas y decisiones que quedan abiertas

No ejecutadas en esta fase:

- Unidades enteras; cerrados; abierto inicial menor/igual a capacidad; combinación
  y varios lotes de costos diferentes. Rechazar dos abiertos sin sumarlos.
- Foto de capacidad/unidad, límites acumulados, entradas vacías/negativas,
  exceso de precisión, oz sin conversión y ceros sin movimientos ficticios.
- Lotes/ledger/asignaciones/acta/asiento conciliados exactamente; promedio móvil
  y residuo de centavos, sin recalcular valores por un costo unitario redondeado.
- Elegibilidad con saldos/lotes agotados/kárdex cero/historia NULL incompatible,
  documentos pendientes y capturas de servicios; fórmulas solas sin movimiento.
- Mismo UUID/mismo contenido, contenido distinto, nueva clave sobre mismo artículo,
  refresh, respuesta perdida y consulta del resultado sin duplicar cantidades.
- Carreras con conexiones MySQL independientes: misma clave, distinta clave y
  artículo, apertura–compra, apertura–venta/merma y multiartículo en orden inverso.
  Comprobar resultados persistidos y cierre limpio de todas las conexiones.
- Fallos después de lote, kárdex, asignación, línea, asiento y enlace final:
  rollback completo, también ante Throwable, sin cabecera parcial confirmada.
- Roles, anónimo/CSRF real, PIN inválido y actores manipulados; ausencia de PIN/hash
  en evidencia; log de intento frente a documento confirmado.
- Período cerrado/fecha futura, cuenta inactiva/no imputable y contrapartida sin
  aprobar: rechazo sin stock ni asientos. No inventar costo ante evidencia ausente.
- Compra/consumo/merma/venta y H04-B sobre lotes de apertura, agotamiento/reemplazo
  de abierto, artículo archivado, no retorno y protección de ejecución de cita.
- Regresiones H01-A/H02-A/H03/H04 y exportaciones, sin cambiar sus reglas.
- Instalación limpia/up/down sin uso, rechazo de down con documentos, ninguna
  clasificación/backfill de registros previos.

Antes de futuras pruebas: preflight de identidad/permisos/respaldo y autorización
del esquema/migraciones esperadas; solo salon_erp_test, indicador temporal en
finally y vuelta a cero. No ejecutar este plan con el launcher que exige 78 sin
actualizar explícitamente su preflight al nuevo esquema autorizado.

Riesgos pendientes: definición contable de saldos iniciales; evidencias de costo y
propiedad; captura retrospectiva sin conteo verificable; documentos previos al
corte; lotes desconocidos/caducados; límites de precisión de costos y del escritor
no fraccionable; historial ambiguo; alcance todavía limitado de devoluciones no
fraccionables y ausencia de anulación propia de apertura. No se solventan con
compras ficticias, cuentas puente elegidas automáticamente o borrados históricos.

Conclusión: el remanente inicial cabe en B1 sin inventar un cerrado y el documento
puede diseñarse sin reconstruir el ERP. No se ha seleccionado contrapartida ni
se ha implementado H01-B2. El único cambio revisable de esta fase es este informe.
