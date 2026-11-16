# H01-B2B0 — Políticas y especificación de apertura documentada

Fecha: 2026-11-15. Estado: diseño para aprobación; **H01-B2B no implementado**.

## 1. Resultado y evidencia de esta revisión

Solo se inspeccionaron archivos y estado de Git. No se inició Laravel, no hubo
conexiones SQL, pruebas, migraciones, seeders ni escrituras en ninguna base.
Se comprobó `SALON_TEST_DB_RESET_ALLOWED=0` en el archivo local sin mostrar secretos.
Las 79 migraciones de test y las 74 de los otros destinos son el estado documentado
en H01-B2A y confirmado por el propietario, no una nueva medición de esta fase.

Fuentes concretas revisadas:

| Fuente | Contrato relevante para B2B |
| --- | --- |
| `2026_11_14_000005_create_inventory_opening_documents.php` | Dos tablas, UUID único, hash no único, posiciones y resultados únicos, nueve FK RESTRICT; JSON versionado; prepared/confirmed; confirmación SQL solo estructural |
| Migraciones `2026_11_14_000001` y `000002` | Un abierto positivo por artículo, fotografías de lote, asignaciones restringidas a ml/gr/oz y vínculo compuesto al artículo |
| `InventarioController::datosCatalogo`, `tieneContextoFisico` | Campos físicos prohibidos; consulta historia y documentos retenidos; una fórmula es contexto del catálogo, no prueba por sí sola de movimiento físico |
| `StockLedger::registrarApertura`, `InventarioController::registrarApertura` | Ruta antigua copia un saldo; mutex del artículo, transacción y rechazo V2; no es acta documental |
| `InventarioService::bloquearUsuarios/bloquearArticulos` | Usuarios con bloqueo compartido; artículos con bloqueo exclusivo; IDs únicos ordenados |
| `InventarioFraccionableService::estado/registrar` | Conciliación física y monetaria V2; registrador privado fija fecha actual y no constituye una entrada de apertura |
| `CompraService::registrarFactura/aprobarFactura` | Una compra retenida tiene líneas sin efectos hasta aprobación; fecha documental no demuestra si el material está incluido en el conteo |
| `ContabilidadBaseSeeder`, `CuentasComplementariasSeeder`, `CuentaContable` | Catálogo fuente y atributos de cuenta; no se encontró una cuenta dedicada de saldos iniciales en esos seeders |
| `ContabilidadService::cuentaIdPorCodigo/codigoInventarioArticulo/resolverPeriodo/validarCuadre` | Resolución por código, clasificación de inventario, período y cuadre; no hay método de apertura documental |
| `CuentaContableController`, `AsientoManualController` | Catálogo protegido y selectores activos/de movimiento; el asiento manual no enlaza stock ni reemplaza el nuevo caso de uso |
| `AutorizaPinSupervisor`, `config/salon.php` | PIN estricto devuelve supervisor activo; actualmente rol configurado alvaro; log de autorización no transaccional |
| `CapturaConsumoService::activa` | V2 sigue limitado a testing + destino/usuario de test; este diseño no cambia esa puerta |

El catálogo local efectivo puede diferir del seeder. Su disponibilidad, atributos y
saldo deberán verificarse en el preflight autorizado de B2B; esta revisión estática
no certifica que una cuenta exista o sea utilizable en una base concreta.

## 2. Matriz de decisiones: propuesta, no autorización

Las invariantes ya aprobadas se conservan. Las filas P requieren respuesta humana
expresa; su ausencia bloquea la confirmación, no activa un valor por defecto.

| ID / decisión | Regla final propuesta para B2B | Quién debe aprobar / evidencia | Representación existente |
| --- | --- | --- | --- |
| P1 Valoración | Importe NIO por posición respaldado por documento identificable y criterio aprobado. Desconocido, vacío o precio comercial como sustituto: rechazo | Responsable contable: fuentes admitidas, criterio, importes y método para documentos en otra moneda | valuation_evidence + document_value_nio; total de cabecera |
| P2 Costo cero | Propuesta mínima: no confirmar posiciones de valor cero. Nunca convertir ausencia en cero. Excepciones exigirían aprobación específica antes de implementarlas | Contable y propietario: aceptar rechazo o definir excepción y justificación | SQL admite cero, pero no aprueba su uso; validación de servidor puede restringirlo |
| P3 Corte | Un instante de corte C con zona horaria y alcance completos. Conteo representa exactamente C; fecha efectiva=C. Ventana de implantación sin escritores ordinarios. Sin apertura retroactiva libre | Propietario: C, zona, artículos/actas incluidas, ventana y conciliación de documentos | effective_at, counted_at; document_evidence y policy_snapshot |
| P4 Documentos anteriores/pendientes | Rechazar hasta conciliación externa documentada todo pendiente que pueda duplicar entrada/salida del conteo. No aprobar, borrar, anular ni compensar esos documentos automáticamente | Operaciones + contable: listado y resolución de cada documento, incluidas facturas aún no cargadas | Evidencias y snapshot; consultas actuales a documentos existentes |
| P5 Fabricante desconocido | Propuesta: permitir solo con declaración explícita de desconocido y referencia interna inequívoca de acta/posición; nunca presentarla como lote de fabricante | Responsable operativo: aceptar esta excepción o exigir lote de fabricante | manufacturer_lot=NULL; physical_evidence con origen interno y referencia |
| P6 Vencimiento | Exigir declaración known/not_applicable/unknown. Si es aplicable: fecha acreditada posterior al día del corte. Desconocido o vencido: bloquear; no sustituir por fecha actual ni crear merma | Responsable operativo: aplicabilidad por artículo y criterio en el día de vencimiento; cualquier excepción futura requiere otra decisión | expires_on nullable + physical_evidence; no existe cuarentena operativa |
| P7 Conteo y supervisión | El ejecutor autenticado declara haber contado y verifica el acta completa; counted_by=executed_by, supervisor desde PIN estricto. No asignar responsables desde IDs del navegador | Propietario: aceptar declaración propia y decidir si exige persona distinta para supervisar | FK de identidades + document_evidence; no guardar PIN ni hashes |
| P8 Clasificación V2 | Consentimiento individual explícito para cada fraccionable NULL; solo saldos físicos cero, costo almacenado sin saldo inexplicado, sin historia física/documental incompatible. No conversión masiva | Propietario/operaciones: aprobar clasificación y lista de artículos; resolver toda ambigüedad primero | Marcador items existente + fotografía en línea/evidencia; misma transacción |
| P9 Contención heredada | Al activar el escritor nuevo, deshabilitar focalizadamente la primera apertura heredada, tanto en endpoint como método de dominio; mensaje explícito y enlace al flujo documental, sin traducir solicitudes antiguas | Propietario: aprobar retirada de esa escritura; consultas y reportes conservados | No requiere cambiar el esquema B2A |

Una fecha de conteo anterior a C necesita conciliación de los cambios hasta C;
la propuesta mínima la rechaza si no existe esa evidencia aprobada. No afirmar
que una foto antigua representa el saldo al corte. recorded_at lo fija el servidor;
no sustituye counted_at ni effective_at. Fijar explícitamente la semántica de fechas
de solo día: una factura con fecha igual al día de C se considera ambigua hasta
acreditar si sus existencias estaban incluidas, no se ordena por una hora inventada.

## 3. Decisión contable obligatoria

Este apartado describe alternativas de implementación, no un dictamen fiscal ni
una elección patrimonial. No se propone ningún código nuevo automáticamente.

| Alternativa | Hecho y control necesarios | Encaje en B2A / límite |
| --- | --- | --- |
| Cuenta específica autorizada de saldos iniciales | El contable define naturaleza económica, código exacto, nombre, tipo, naturaleza y padre; justifica el crédito y su tratamiento dentro del balance aprobado. No usarla como puente provisional improvisado | Una contrapartida autorizada por acta cabe en counterpart_account_id. Si no existe, creación explícita y reproducible bajo autorización posterior; nada se crea ahora |
| Aportación real documentada | Documento que acredita aportación de inventario, aportante, propiedad, fecha e importe. El catálogo fuente contiene 3.1 Capital Social, pero inventario antiguo no acredita una aportación nueva | Solo usar 3.1 si el contable autoriza expresamente ese hecho y código; no seleccionarla por disponibilidad |
| Balance inicial aprobado | Balance completo firmado, detalle de inventario y asignación de su importe sin duplicar el débito en otro asiento. Conciliar cada partida y el balance completo | Si el contable autoriza una contrapartida única final para este componente, cabe un asiento propio enlazado. Si requiere varios créditos u otros activos/pasivos, excede el contrato de una contrapartida/asiento de apertura: detener y diseñar integración adicional antes de B2B |

En todos los casos, débito por artículo según `codigoInventarioArticulo()`:
1.1.4 para producto_venta no fraccionable; 1.1.5 para internos/fraccionables.
No usar el tipo de operación para cambiar esa clasificación. El crédito es
**pendiente**. Ingresos, proveedores, pagos, retiros, utilidades retenidas y cuentas
puente improvisadas no son alternativas autorizadas. No generar IVA, pasivos o
diferencias cambiarias por inferencia a partir de un documento externo.

El contable debe entregar, antes de implementar la contabilización:

1. Alternativa elegida y documento de aprobación con responsable, fecha y alcance.
2. Código exacto de contrapartida, atributos y fundamento; confirmar existencia o
   autorizar por separado su creación. No basta «usar patrimonio» ni un ID local.
3. Fuentes de valoración admitidas, costo por posición reconocido en NIO, criterio
   de capitalización y respaldo del importe del remanente abierto. Para otra moneda,
   importe NIO y conversión documentada aprobada, sin operar divisas automáticamente.
4. Decisión sobre costo cero, regla de redondeo del importe documental y reparto
   entre posiciones cuando el documento fuente no las desglosa. La aplicación no
   debe inventar esa distribución ni copiar un total a varias posiciones.
5. Corte, período y conciliación con balances/asientos ya existentes; declarar qué
   importes ya fueron reconocidos y cómo evitar duplicarlos sin ajustes automáticos.
6. Confirmación de que el asiento de apertura propuesto, por cuenta y documento,
   corresponde al balance inicial y queda equilibrado sin asientos compensatorios.

El resolutor actual por código usa caché y comprueba existencia, no activa,
permite_movimiento, naturaleza o autorización. El futuro método debe releer y
validar atributos/código aprobados en la transacción, sin fiarse solo de esa caché.
Resolver el período de C con el servicio existente y respetar cerrado; ejecutar
validarCuadre antes del commit. No usar el controlador de asiento manual como
sustituto: no es un contrato atómico con inventario.

## 4. Contrato de datos implementable sin alterar B2A

**Suficiencia condicionada:** dos tablas bastan para una contrapartida, un asiento
por acta y una posición por lote/movimiento. No se requiere otra migración para
las propuestas P1–P9. Las FK garantizan existencia/artículo, no aprobación, origen,
sumas o inmutabilidad: esas invariantes corresponden al futuro servicio.

| Dato / vínculo | Regla de servidor y conservación |
| --- | --- |
| Identidad | UUID canónico único para la operación; SHA256 canónico calculado por servidor, nunca hash aceptado del cliente como prueba |
| Cabecera | contract_version=1; reference obligatoria; C y zona, conteo, declaración propia, referencia de acta, snapshot de política aprobada (identificador, versión y referencia de aprobación) en JSON con version=1 |
| Posiciones | position estable única; item_id válido/activo, clase units/closed/open, cantidades explícitas, unidad/capacidad fotografiadas desde catálogo validado, evidencia física y de valoración por posición |
| Lote | Crear un lote distinto por posición con purchase_invoice_line_id=NULL; result_lot_id conserva el enlace exacto. No fusionar posiciones aunque compartan fabricante/costo. `lotes.lote` usa referencia interna determinista cuando falta fabricante y conserva su condición en physical_evidence |
| Movimiento | Un movimiento por posición; result_ledger_id único, mismo item_id; origen apertura y referencia_tipo propia inventory_opening + referencia_id de cabecera. El par documento/posición se verifica por la línea, no por coincidencia numérica de IDs |
| Asignación V2 | Movimiento/lote enlazados exactamente, física 0→conteo, valor positivo documental, sin reverses_allocation_id. Las FK compuestas existentes respaldan pertenencia al artículo. Para unidades no fraccionables no fabricar asignaciones ml/gr/oz: línea enlaza lote y movimiento |
| Total | Sumar importes documentales por posición en centavos comprobados; comparar con total declarado/acta y guardar total recalculado. Sin duplicar fuentes compartidas; sin recalcularlo desde un costo unitario redondeado |
| Valor reconocido | valuation_evidence versionada: referencia, emisor/documento, fecha, posición/página, criterio, valor NIO, moneda e importe original si aplica, conversión aprobada y fundamento. Fuente compartida repartida debe tener reparto explícito verificable |
| Resultados | Una sola transacción: líneas, lotes, artículos, kárdex, asignaciones y asiento; al final confirmed, resultados completos, total y evidencias inmutables por las rutas ordinarias |

Un abierto inicial requiere cantidad positiva (0<R<=C); para R=0 no hay posición
open. Un abierto completo sigue abierto. Cerrados y abierto del mismo fabricante
son posiciones/lotes separados para preservar sus valores independientes; el
artículo completo admite como máximo un abierto positivo, no uno por posición.
No simular entrada/salida de un cerrado para producir el remanente.

Límites: enteros de unidades por posición <=99.999.999; capacidad y remanente
decimal(8,2), máximo 999.999,99; cantidad nativa y acumulado físico V2
<=99.999.999.999,99. Importes decimal(15,2) <=9.999.999.999.999,99; costo informativo
decimal(15,6) <=999.999.999,999999. Validar también límites del contador agregado
items entero y de lotes/kárdex, no solo cada línea. Cantidades a centésimas nativas,
dinero a centavos y costo a seis decimales mediante InventoryDecimal/BCMath,
rechazando exceso de precisión antes de PDO; no conversiones automáticas ml/gr/oz.
oz sigue requiriendo interpretación nativa documentada, no se convierte a masa/volumen.

Para una entrada abierta, cantidad_entrada/disponible de lote=0 y volumen_entrada=R;
para cerrados, N y volumen_entrada=N×C. Ambos casos satisfacen B1 sin inventar un
envase recibido completo. Ledger V2 expresa envases equivalentes a cuatro decimales,
no el contador de cerrados; saldo_volumen es la medida primaria. Es necesario un
método delimitado de apertura que acepte fecha e importe exactos: ni registrar()
heredado ni ingresar() de compra son adaptadores correctos para ello.

Insuficiencias que **no** se ocultan tras JSON:

- B2A no impone un corte global. Propuesta mínima: política de servidor única,
  versionada, aprobada e inmutable durante la ventana; todas las actas la fotografían.
  No tomar C del primer documento encontrado ni permitir elegirlo en cada POST.
  Si se exige edición dinámica/distribuida del corte, proponer antes una tabla
  singleton de política/ciclo con clave única y control de versión; no se crea aquí.
- No hay estado de cuarentena ni semántica de mercancía vencida bloqueada en los
  escritores actuales: por ello P6 propone rechazar, no «admitir con observación».
- No hay soporte de varias contrapartidas en el contrato aprobado. Un balance
  integral que las requiera demanda otro diseño, no un JSON que eluda su significado.
- No existe registro digital de documentos externos que aún no ingresaron al ERP:
  ninguna consulta puede garantizar su ausencia. Exige conciliación/atestación
  empresarial y control posterior de facturas anteriores al corte.

## 5. Formulario, hash y reintentos

Formulario futuro: referencia de acta, UUID persistente durante reintentos, versión
de política mostrada, C de solo lectura, momento del conteo, declaración explícita
del ejecutor, posiciones, evidencias y valores; PIN en el modal existente al confirmar.
No seleccionar cuenta arbitraria, supervisor/contador por ID ni enviar resultados
de lote/movimiento/asiento. La cuenta la resuelve el servidor de la política aprobada.
Servidor repite permisos, CSRF, estructura, límites, documentos y conciliación;
rechaza campos autoritativos extra y entradas vacías/NaN/negativas/exceso de precisión.

Canonicalización propuesta con versión explícita: objeto de claves fijas, posiciones
ordenadas por position (no por orden incidental de JSON), IDs enteros, decimales
como cadenas de escala fija y fechas normalizadas con zona definida; conservar
evidencias y referencias que modifican el significado. Incluye versión/identificador
de política, actor ejecutor, C y contenido completo; excluye UUID, PIN/CSRF, timestamps
generados, resultados SQL y supervisor resuelto para no convertir una nueva
autorización en un cambio de contenido. No redondear/truncar entradas al canonicalizar.
El hash guardado se comprueba contra datos/evidencia persistidos antes de devolver éxito.

- Mismo UUID, mismo contenido canónico y ejecutor autorizado: lectura actual del
  confirmed íntegro devuelve los mismos IDs/resultado, sin repetir ningún efecto.
  Para este caso no volver a evaluar «artículo vacío» ni la fórmula/catálogo actual
  como si fuera una apertura nueva. Las cantidades originales ya no están en cero.
- Mismo UUID con otro contenido/actor: 409 sin sobrescribir. No permitir takeover
  de un prepared de ensayo o ajeno; un prepared persistente no es éxito ni autorización.
- UUID distinto con hash igual: hash no es UNIQUE. Historia/mutex impide una segunda
  apertura del artículo; no convertir coincidencia de hash en permiso para publicar.
- Respuesta incierta: conservar UUID y consultar/repetir exactamente el contenido;
  no generar otra identidad automáticamente al refrescar ni reintentar finanzas.

PIN: reutilizar modo estricto y limitador existentes. El supervisor devuelto es
autoritativo; revalidar activo/rol bajo bloqueo. El log actual acredita intento de
autorización, no commit del acta, y no se puede revertir con SQL. La evidencia de
éxito vive en cabecera confirmed; cualquier log de apertura confirmada se emite
después de commit. No persistir PIN, contraseña, hashes ni request completo.
Si se exige doble persona, rechazar ejecutor=supervisor por regla aprobada, no
crear otro sistema de roles. En reintento de lectura se autoriza acceso al resultado;
no se atribuye una segunda aprobación documental ni se modifica el supervisor original.

## 6. Historia, corte y contención de escritores

Elegibilidad se decide una vez por artículo bajo su mutex, no por posición. Exigir
saldos físicos cero y ausencia de lotes (incluso agotados), kárdex, asignaciones,
mermas, aperturas confirmadas/resultados y salidas/entradas documentales previas.
Saldo cero tras una anulación no significa sin historia. Rechazar artículo archivado,
tipo servicio, saldo de costo inexplicado o marcador incompatible. Una receta sola
no es salida física; conservar sus referencias y rechazar documentos de consumo
pendientes, no confundir catálogo con evidencia de movimiento.

Inspeccionar purchase_invoice_lines/purchase_invoices, sale_details/sales,
sale_detail_consumptions y snapshots de consumo retenidos que impliquen al artículo
(incluidos insumos de servicios), mermas y documentación previa. No decidir solo
por estado de documento. Toda referencia no conciliable queda bloqueada; un documento
anulado sin efectos se analiza con evidencia, no se borra para pasar el control.

Para corte: clasificar si el conteo incluye la recepción/salida, no solo la fecha
de factura. Antes del commit releer pendientes y vínculos con lecturas actuales;
la ausencia observada en una consulta snapshot no es garantía contra otra solicitud.
Una venta/compra que compita por el artículo debe serializarse por el mismo mutex.
No adquirir primero artículos y luego bloqueos exclusivos de documentos que sus
aprobaciones adquieren en el orden contrario.

Dependencia mínima de activación: mientras se confirma el corte, cerrar escritores
ordinarios del ERP (HTTP/colas/scheduler) mediante el procedimiento de mantenimiento
y canal controlado ya conocidos. Después, los ingresos y aprobaciones de compras
deben rechazar o remitir a conciliación documentos anteriores/al día ambiguo del
corte cuyo material podría estar incluido. Una factura posterior emitida para una
recepción anterior también exige atestación de inclusión. No insertar otra compra
para «regularizar» el conteo. El alcance de esa guarda debe aprobarse expresamente
para B2B; sin ella no habilitar aperturas con facturas externas pendientes.

Al activar B2B, propuesta P9 bloquea la escritura antigua también dentro del método
de dominio, antes de emitir incluso un ajuste cero. No redirigir su POST ni aceptar
cantidades por él. Mantener consultas/reportes. Hasta esa activación el comportamiento
heredado permanece exactamente como H01-B2A; este documento no lo deshabilita.

## 7. Orden transaccional y conciliación

Protocolo propuesto para el futuro servicio pequeño AperturaInventarioService:

1. Sesión/rol/CSRF, formato/canonicalización, política vigente y evidencia completa;
   consulta autorizada de resultado idempotente si existe. Confirmación nueva exige PIN.
2. Transacción única sin commits intermedios ni reintentos financieros automáticos.
   Bloquear primero usuarios necesarios en orden ascendente mediante el helper
   compartido; revalidar identidad/rol. Después bloquear cabecera existente por UUID
   o reservar una nueva por UNIQUE. Mantener usuarios→cabecera en todas las rutas
   nuevas, también en reintentos de lectura, antes de insertar hijos con sus FK.
3. No modificar ni aprobar documentos previos desde este servicio. Si existieran
   pendientes o historia que requieren conciliación, rechazar; si un diseño posterior
   necesita bloquear documentos/agenda, hacerlo antes de usuarios/artículos conforme
   al protocolo existente y revisar esa dependencia antes de implementarlo.
4. Adquirir todos los artículos únicos por ID ascendente antes de insertar líneas
   con FK al artículo; releer saldos/metadatos/historia y pendientes con lecturas actuales.
   Mutex incluso sin lotes/ledger previos. Revalidar política de corte; reunir demanda
   y posiciones por artículo; clasificación individual NULL→2 solo aquí y con consentimiento.
5. Lotes en orden fecha_entrada,ID; kárdex por artículo en orden de ID y asignaciones
   por ID, coherente con estado() V2. En apertura nueva no debe haber historia.
   Orden de posiciones estable para creación/FIFO; fecha de entrada=C en su fecha
   local, sin fabricar antigüedad. Crear cada posición/lote/movimiento/asignación,
   sin pasar por CompraService, con valor y fecha efectivos explícitos.
6. Resolver cuentas aprobadas y período de C, crear un asiento propio enlazado al
   acta, débitos agrupados por cuenta de artículo y crédito autorizado. Verificar
   identidades y códigos efectivos, no IDs de navegador. No modificar pagos/CxP/ventas.
7. Conciliar y validarCuadre; enlazar todos los resultados, total y snapshot de
   aprobación, confirmar cabecera; commit único. Ante fallo, rollback incluso de
   clasificación V2, evidencia de éxito, lotes y asiento. No limpiar historia para repetir.

La disputa de UUID se resuelve mediante la restricción única, no firstOrCreate:
si el INSERT pierde por esa clave concreta, rollback de esa solicitud y lectura
actual autorizada del resultado ganador; comparar contenido/estado y devolverlo
si íntegro. No capturar indiscriminadamente otras violaciones FK/UNIQUE como éxito.
Si no hay resultado confirmado o persiste prepared, conflicto para revisión.
Lectura FOR UPDATE de filas inexistentes sola no implementa esta garantía.

Conciliación obligatoria antes de commit:

- Unidades: artículo=sum(lotes disponibles)=suma de posiciones units; ledger refleja
  unidades y valor, sin volumen ficticio.
- V2: cerrados=sum(cerrados de lotes), abierto=sum(remanentes), máximo un abierto;
  Q=cerrados×capacidad+abierto=sum(Q de posiciones/lotes)=saldo_volumen final.
  Envases equivalentes del ledger no se comparan con el contador entero de cerrados.
- Para cada posición V2: asignación identifica exactamente su lote/movimiento,
  delta físico=Q, valor asignado=importe documental. Ninguna referencia de reversión.
- Suma de importes de posiciones=movimientos=asignaciones V2 más movimientos de
  unidades=total del acta=débitos de inventario=crédito aprobado, en centavos exactos.
  Promedio móvil inicial=valor acumulado/Q, costo informativo a precisión existente;
  no reconstruir importe multiplicando ese costo redondeado. Ejemplo: dos posiciones
  de 100 ml valoradas en C$10/C$20 son dos entradas con esos importes, no un único
  movimiento que reparta C$15/C$15 por volumen y pierda la valoración documental.
- El asiento, lotes y movimientos deben apuntar al acta y posiciones correctos;
  confirmar no basta con que sus FK existan. Fuente del importe permanece auditable.

## 8. Entregas pequeñas, archivos y criterios verificables

| Entrega futura | Alcance mínimo | Criterio de aceptación |
| --- | --- | --- |
| B2B-1 Contrato | Request/validación, servicio pequeño de apertura, modelos de las dos tablas si son útiles, política de servidor aprobada | Validaciones decimales antes de PDO; políticas incompletas bloquean; preparado no emite stock; datos autoritativos solo de servidor |
| B2B-2 Entradas y asiento | Extensiones delimitadas de InventarioFraccionableService y StockLedger para fecha/valor explícitos; método de apertura en ContabilidadService | Una posición/un lote/un movimiento, asignación solo V2, valor exacto, sin compra/CxP/pago, transacción indivisible y H04 conservado |
| B2B-3 Acceso y corte | Controlador/vista/JS de apertura bajo sesión/CSRF/roles existentes; PIN; ruta vieja contenida; guardas mínimas de compras/aprobación para el corte | Sin vía alternativa ni ingreso duplicado anterior al corte; UUID preservado en refresh; errores comprensibles; sin cambios UI-01 |
| B2B-4 Verificación aislada | Tests focalizados y auxiliares seguros, runner/preflight adaptados expresamente a 79 migraciones, documentación | Concurrencia MySQL real, conciliación por cuenta y rollback integral; flag0 en finally, bases ajenas intactas |

No modificar las 79 migraciones ni crear otra por anticipado. Configurar una cuenta
o desplegar políticas/V2 en desarrollo es autorización separada de implementar y
probar en test. No se programa anulación de actas de apertura en esta entrega:
H04-B sigue cubriendo ventas; no atribuirle una reversión genérica del acta.

Pruebas futuras, sin repetir instalación/rollback de B2A:

| Grupo | Casos y evidencia esperada |
| --- | --- |
| Contrato | Costo ausente/cero según política; valor distinto del precio comercial; fuentes no admitidas; exceso de precisión y límites individuales/acumulados; campos autoritativos manipulados; fechas/zona/corte |
| Física | Unidades, cerrados, abierto solo y cerrado+abierto; abierto completo no sellado; dos abiertos propuestos rechazados; múltiples posiciones con costos distintos; fuente externa desconocida diferenciada; vencimiento según política |
| Historia | Saldos cero con historia, lotes agotados, documentos retenidos de productos/servicios, clasificaciones NULL ambiguas, archivados y coincidencias de IDs; rechazo sin limpiar ni convertir historia |
| Corte | Compra anterior, fecha igual ambigua y factura posterior con recepción ya contada; aprobación diferida; conteo antiguo sin conciliación; política cambiada entre vista y POST; endpoint viejo y llamada directa al método bloqueados |
| Idempotencia | Misma petición dos veces y respuesta de red perdida: mismos resultados y un solo asiento; UUID igual contenido distinto:409; UUID distinto artículo ya abierto:rechazo; prepared incompleto no es éxito; hash estable con escalas/orden canónicos |
| Concurrencia MySQL | Dos conexiones mismo UUID; UUID distintos mismo artículo; actas con artículos compartidos en distinto orden; apertura contra compra/aprobación/merma/salida/ruta heredada: solo secuencia elegible confirma, sin duplicación ni deadlock por orden invertido |
| Atomicidad/contabilidad | Fallos tras primer lote, ledger/asignación y asiento; rollback también clasificación; débitos 1.1.4/1.1.5 y contrapartida aprobada exactos; período cerrado; cuenta desactivada/cambiada; sin ingreso/CxP/pago/impuesto ficticio |
| Posteriores/regresiones | Consumo/merma/venta de inventario abierto inicial con trazabilidad; H04-B permite solo restitución físicamente viable; compras futuras y reportes concilian; H01-A, H02-A, H03-B2 y H04-B focalizados; CSRF/roles/PIN y JS; no suite completa |

Cada carrera debe verificar persistencia de ambas solicitudes y cerrar conexiones.
El preflight futuro debe verificar tester/destino/permisos, esquema, respaldo SHA256,
líneas base, ausencia de efectos externos y finally del indicador; ninguna prueba
de negocio se ejecuta hoy. No declarar idempotencia o concurrencia demostradas
por la existencia de UUID UNIQUE o por este diseño.

## 9. Autorización humana necesaria y condiciones de detención

Para comenzar H01-B2B hace falta una autorización explícita que incluya:

1. **Responsable contable:** alternativa, código y documento de aprobación de la
   contrapartida; bases/fuentes y valoración NIO, reparto y costo cero (P1/P2).
2. **Propietario/operaciones:** corte C y zona, ventana sin escritores, alcance de
   actas/artículos y conciliación de pendientes/externos (P3/P4); políticas de lote
   desconocido/vencimiento (P5/P6); declaración de conteo y separación de personas
   si corresponde (P7); clasificación individual vacía a V2 (P8).
3. **Alcance de código y pruebas:** aprobar P9 y la guarda de corte en ingresos/
   aprobaciones, implementación únicamente en test, pruebas focalizadas/carreras
   y recreación de test si es necesaria bajo los guardias existentes. No implica
   autorización de registrar una apertura real ni desplegar V2 en desarrollo.

No basta «continuar» para elegir un código contable, fecha o fuente de costo que
siguen pendientes. Si se quiere implementar primero solo validación sin escrituras,
puede autorizarse separadamente ese subconjunto, manteniendo confirmación bloqueada.

Detener ante política ausente/contradictoria, falta de evidencia, cuenta o período
incompatible, imposibilidad de excluir escritores, documento ambiguo, límite SQL,
mezcla de versiones o necesidad de balance multicuentas/operaciones nuevas. No
resolverlo con costo cero, precio comercial, compras, cuentas puente, backfill,
compensaciones o apagado de controles. No desplegar ni activar V2 en desarrollo.

## 10. Estado de archivos y cierre

Al inicio de esta fase Git no mostraba cambios. Único archivo creado:
`docs/H01_B2B0_POLITICAS_APERTURA.md`. Se conservan sin modificaciones tablas,
migraciones, código funcional, configuración y credenciales. No hubo commits.
No se repitieron instalación/rollback ni se tocó el preparado de ensayo de B2A.
Sin SQL ejecutado, no se afirma una nueva comparación de integridad de las tres
bases: esta fase no realizó operaciones sobre ellas. El indicador local permanece en 0.

Reversión documental: retirar únicamente este informe si se descarta el diseño;
no ejecutar rollback ni eliminar la evidencia de B2A. H01-B2B sigue pendiente de
las aprobaciones anteriores y no hay escritor físico o financiero nuevo activado.
