# H01-R — Trazabilidad del inventario y apertura

Fecha: 21 de noviembre de 2026. Revisión estática y pruebas aisladas sin SQL empresarial. **El defecto original del CRUD ya estaba corregido por H01-A.** Se corrigieron tres límites de coordinación/atomicidad todavía presentes en la apertura heredada y las mermas. No se implementa la apertura física H01-B, no se activa V2 y no se continúa H06.

## 1. Estado vigente de H01

Referencias: [auditoría original](../AUDITORIA_MANTENIBILIDAD.md), [AGENTS.md](../AGENTS.md), [H01-A](H01_A_CATALOGO_INVENTARIO.md), [H01-B1](H01_B1_DISENO_APERTURA.md), [H01-B2A](H01_B2A_ESQUEMA_APERTURA.md), [políticas B2B0](H01_B2B0_POLITICAS_APERTURA.md), [contrato B2B1](H01_B2B1_CONTRATO_VALIDACION.md), [H02-A](H02_A_UNIDADES_CONCURRENCIA.md), [H03-B1](H03_B1_ESQUEMA_ADITIVO.md), [H03-B2](H03_B2_IMPLEMENTACION.md), [H04-A](H04_A_ANULACIONES_SIN_EFECTOS.md), [H04-B](H04_B_REVERSIBILIDAD.md), [H06-R](H06_R_FACTURACION_CITAS.md), [H06-P](H06_P_MODELO_PRESTACION_COMPARTIDA.md) y [H06-P1](H06_P1_IMPLEMENTACION_AISLADA.md).

- `InventarioController::store/update` usan `datosCatalogo`, rechazan `existencia_actual/current_volume` incluso con cero/NULL y no persisten `$request->all()`. Crear artículo fija cantidades cero; editar datos comerciales conserva stock, lotes y libro. No se repite H01-A.
- El cambio de interpretación física —tipo, unidad, capacidad o fraccionamiento— requiere ausencia de stock, historia y referencias, bajo lock. No se elimina esa guarda.
- La apertura heredada de kárdex **no ingresa mercancía**. Fotografía el saldo agregado existente una sola vez; no crea lote, compra, CxP, pago o asiento. H01-B continúa pendiente para ingresar existencias iniciales documentadas.
- Se confirmaron con dobles: `Error` escapaba de los controladores de apertura y merma dejando la transacción abierta, y faltaba el prefijo de lock del usuario en apertura. No se atribuye corrupción de producción a estos ensayos.
- Se identifican límites legacy de representación/valoración y vías privilegiadas ajenas al flujo ordinario, sin declararlas corregidas ni reinterpretar datos.

## 2. Matriz de escritores

Inventario físico vive en `items` mediante **Articulo**, no existe otro modelo funcional `Item`. Se buscaron asignaciones, `create/update/save/forceFill`, Query Builder, SQL directo, incrementos/decrementos, mutadores/observadores, rutas, comandos e importadores. No se encontraron `increment/decrement` ni escrituras Query Builder específicas de cantidades de items en `app`/rutas; las consultas contables sobre items son lecturas/clasificación, no movimientos físicos.

### Escritores físicos y de libro

| ID / entrada vigente | Operación / tablas y campos | Kárdex y origen | Lotes / costo | Estado de protección |
| --- | --- | --- | --- | --- |
| A. `POST /inventario` → `InventarioController::store` | Alta de `items`; `existencia_actual=0`, `current_volume=0`; metadatos validados | Ninguno: no hay entrada física | No crea lotes ni valor inicial | H01-A vigente. Marcador V2 solo mediante gate H03 ya existente; no activado aquí |
| B. `PUT /inventario/{id}` → `update` | Catálogo; no asigna cantidades, costo promedio ni marcador del cuerpo HTTP | Ninguno: modificación comercial | Conserva lotes/costos. Interpretación bloqueada con contexto físico/histórico | Validación + transacción + lock de item; no es ajuste |
| C. `POST /inventario/kardex/apertura` → `StockLedger::registrarApertura` | **Solo** inserta `stock_ledger_entries`; no cambia items | `origen=apertura`, `referencia_tipo=apertura`, ID del artículo, usuario, fecha actual y nota | Reutiliza costo disponible de lotes/promedio heredado; no crea ni corrige lotes/costo de items | Relectura actual y ausencia de ledger bajo mutex; rechaza V2. Prefijo usuario agregado en H01-R |
| D. `POST /inventario/comprar` → `CompraService::registrarFactura/ingresarStock`; aprobación `/aprobaciones/compra/{id}/aprobar` | Factura/líneas; items cantidad y, legacy fraccionable, volumen; costo promedio | Entrada `compra`, `factura_compra`, ID factura | Lote por línea con vínculo a compra; landed cost y promedio móvil existentes | Servicio dueño de transacción; retenida no aplica; aprobación exige estado pendiente |
| E. `POST /compras/{id}/anular` → `CompraService::anularFactura` | Resta cantidad/volumen legacy, agota lotes; estado factura/CxP | Salida `anulacion_compra`, referencia factura original | Lotes conservados, rechazo si consumidos/stock insuficiente/CxP con abonos; costo landed original en movimiento | Documento/CxP/pagos y mutex ordenados. Reversión V2 de compra sigue bloqueada; no se amplía |
| F. `POST /sales`, directa `/sales/bill-appointment/{appointment}`, aprobación `/aprobaciones/venta/{id}/aprobar` → `InventarioService::descontarPorVenta` | Descuenta unidades/volumen en items y lotes | Salida `venta`, ID venta, detalle cuando corresponde H03 | FIFO existente; valor de salida basado en ledger, no precio comercial | Carrito/estado/agenda y demanda/locks H02; H03/H06 nuevos requieren sus gates. Sin cambios aquí |
| G. `POST /mermas` → `MermaController::store/InventarioService::registrarMerma` | Documento merma, item, lotes, valor merma | Salida `merma`, ID del documento | FIFO y costo real del servicio, no precio de venta | Motivo/fecha/cantidad validados; usuario → items → lotes/libro. H01-R cierra también Error y niveles anidados |
| H. `POST /ventas/{id}/anular` → `InventarioService::revertirPorVenta` legacy | Suma items y repone lote reciente; conserva documentos | Entrada `reversion_venta`, referencia a venta, validación contra salidas originales | Legacy conserva restitución/valoración anteriores; **no** garantiza valor original solo por coincidir la referencia | Protocolo existente y límites H04 conservados; no se presenta como corrección de reversibilidad |
| I. `InventarioFraccionableService::ingresar` (rama D) | Lote versionado, cantidad de cerrados de item y costo promedio | Entrada compra V2 + asignaciones; referencia factura | Valor documental exacto, volumen nativo y costo informativo | `estado`, gate y transacción exigidos; no ruta alternativa ni activación |
| J. `InventarioFraccionableService::consumir` (ramas F/G) | Cerrados/abierto de lotes; recalcula agregados de item desde lotes | Salida V2 y asignaciones por lote/detalle | Medida primaria nativa, costo del saldo previo y distribución de centavos | Estado/gate/locks y demanda existentes; no modificado |
| K. `InventarioFraccionableService::restituirVenta` (anulación H04) | Restituye a lotes originales, agrega cerrados/abierto y promedio resultante | Movimiento/asignaciones inversos enlazados al original | Cantidades/valor originales y continuidad física conservadora | Evidencia H04, gate, transacción, ausencia de reversión previa; no modificado |
| L. `StockLedger::registrar`, `InventarioService::registrarLedger` | Solo libro; helpers utilizados por C–H después del cambio físico del llamador | Origen/referencia aportados por el caso de uso; fallback genérico de helper no acredita documento | Calcula saldo/costo legacy y rechaza marcador V2 | No son endpoints autónomos de ajuste; no sustituyen la transacción/mutex del llamador ni impiden SQL arbitrario |

La ruta directa exacta está registrada en `routes/web.php`; conservar su URL y consumidores no implica que la rama legacy multiestilista haya sido reparada por H01.

### Transacciones, contabilidad, locks y reversibilidad

| ID | Dueño transaccional / locks | Efecto contable | Repetición y reversión |
| --- | --- | --- | --- |
| A | Un INSERT vacío; no efectos coordinados que agrupar | Ninguno | No hay token idempotente de catálogo; repetir alta no ingresa stock. `codigo` tiene validación unique cuando se proporciona |
| B | `DB::transaction`; item FOR UPDATE y lecturas actuales de contexto | Ninguno | Editar nombre/precio no cambia posiciones. No hay conversión física implícita |
| C | Controlador abre/cierra; dominio exige TX; ahora usuario compartido → item exclusivo → ledger actual | Ninguno, por contrato legado | Segundo intento encuentra ledger y devuelve NULL/rechazo web sin otra entrada. Sin anulación de acta, porque no es acta |
| D/I | `CompraService` usa transacción Laravel; documento existente al aprobar, usuarios y todos los items por ID, luego lotes/libro | `COM-*`, CxP si crédito o fondos según contrato existente | Estado impide segunda aprobación. Crear dos facturas nuevas no tiene idempotencia universal por número de proveedor; no se añade aquí |
| E | `CompraService` transaccional: factura → CxP → pagos → usuarios/items → lotes/libro | Espejo `REINV-COM-*` | Ya anulada rechaza; no se borran compras/lotes. V2 no se revierte por esta ruta |
| F/J | TX de emisión/aprobación; prefijos agenda/venta/usuarios y mutex H02/H03; H06-P1 conserva coordinación propia | Ingreso/CxC, pagos y COGS por servicios actuales | Guardias de estado/cita/contrato; no se promete idempotencia global de todas las ventas legacy |
| G | TX de MermaController; conserva nivel previo y revierte todos sus niveles ante Throwable; mutex H02 | `MER-*` con políticas actuales, sin cuentas nuevas | Dos POST de merma no se identifican inequívocamente como un mismo hecho: no hay token persistente. No se inventa deduplicación por monto/motivo |
| H/K | TX de anulación; coordinación de venta/agenda, usuarios, items y evidencia/movimientos originales | Espejo existente, condicionado por intención H04 | Bloqueo por comisión pagada, evidencia y reversión previa; documentos nunca se borran. SQL real pendiente en esta fase |
| L | El helper no es dueño del commit; sus consumidores deben mantener transacción y mutex de item | Ninguno por sí solo | El libro aislado no prueba movimiento real, autorización, conciliación ni idempotencia |

### Localización exhaustiva de asignaciones físicas en aplicación

Once puntos de escritura literal de `existencia_actual`, en el árbol inspeccionado:

- `InventarioController.php:43`: cero al crear catálogo.
- `CompraService.php:310,421`: anulación legacy e ingreso de compra.
- `InventarioService.php:290,306,394`: débitos legacy; `:615,635`: restituciones legacy.
- `InventarioFraccionableService.php:92,148,226`: ingreso, consumo y restitución versionados.

Se inspeccionaron también las escrituras asociadas de `current_volume`, `cantidad_disponible`, `volumen_abierto` y `costo_promedio`, además de `save/forceFill` de lotes y ledger. No se cuenta un comentario, `$fillable`, cast o consulta como escritor funcional.

Excepciones y capacidades fuera del flujo de inventario ordinario:

- `Articulo::$fillable` conserva cantidades para los servicios actuales; **no hay mutador/observer que garantice por sí solo kárdex para cualquier `save` arbitrario**. No se retiran campos requeridos por compras/otros servicios ni se introduce un token global de escritura. El modelo aislado y SQL directo no son una nueva garantía de seguridad.
- `ItemSeeder` usa `withTrashed()->firstOrCreate` con cantidades cero; no actualiza stocks existentes al encontrar el código. No se ejecutó. Migraciones tienen defaults/constraints; no se ejecutaron ni reescribieron.
- Importadores contables y comandos inspeccionados no modifican stock. La API `/api/items` está en web con sesión/CSRF pero `ArticuloController` sigue con métodos vacíos: no es escritor de cantidades ni se le amplía capacidad.
- `DELETE /inventario/{id}` es SoftDelete de catálogo, no decremento, eliminación de movimientos ni regularización de saldos. Puede afectar disponibilidad de lectura; sus políticas de archivo no se rediseñan aquí.
- `POST /backups/restaurar`, `RespaldoController::restore`, admite SQL mediante `DB::unprepared`. Es una vía privilegiada de reemplazo general capaz de eludir toda invariante si el archivo lo hace. No se ejecutó, no es ajuste de inventario y **no se declara protegida por H01**. Su contención requiere revisión separada del contrato de restauración; no sería correcto certificar que toda escritura imaginable queda impedida por estos cambios.
- Fixtures, factories y herramientas históricas bajo tests pueden escribir datos de ensayo. No son rutas de negocio. No se ejecutaron launchers, fixtures SQL, seeders, restauraciones ni helpers de reconstrucción. Solo se seleccionaron explícitamente pruebas Unit sin bootstrap empresarial.

## 3. Evidencia de lo ya corregido y de fallos actuales

H01-A se confirma en el código actual y con `OpeningCatalogueGuardTest` más pruebas nuevas: validación antes de persistencia, datos físicos ausentes y conservación de cantidades al editar nombre/precio. No se modifica esa lógica.

Primera ejecución de los 26 casos iniciales de `H01InventoryTraceTest`, **antes de corregir código funcional**: 22 aprobados y 4 fallos (100 aserciones). Fallaron:

1. Traza de apertura: primer lock observado era items, no users. Al insertar el ledger se necesita FK a usuario; una operación que tome users antes de items podía competir con ese orden inverso. La traza demuestra llamadas invertidas, no reproduce un deadlock InnoDB.
2. `Error` centinela después de insertar apertura: escapaba del catch Exception, con nivel transaccional abierto y fila sintética visible.
3. `Error` después de actualizar item en merma: documento/stock parcial en la transacción no cerrada.
4. `Error` después de insertar salida de kárdex en merma: stock, lote y documento habían alcanzado el punto de fallo y quedaban en la TX abierta.

Los RuntimeException correspondientes sí entraban en el catch anterior. No se modificaron expectativas para convertir fallos en aprobaciones.

Además, la inspección de `ContabilidadService::contabilizarMerma` confirma una transacción manual interna cuyo catch captura Exception. Se añadió un ensayo de Error anidado y se corrigió la frontera dueña del caso de uso, sin modificar ContabilidadService: revertir solo un nivel no basta para deshacer merma/stock del nivel exterior.

## 4. Contrato de inventario inicial

Separaciones conservadas:

| Evento | Qué representa | Qué no debe hacerse |
| --- | --- | --- |
| Alta de artículo | Catálogo vacío, sin movimiento económico | Mandar stock o volumen al CRUD |
| Apertura heredada | Primera fotografía del saldo existente al comenzar el libro, referencia al artículo | Aumentar saldo, crear lotes ficticios, reconstruir historia, simular compra o contabilizar nuevamente |
| Apertura física H01-B | Conteo/acta, posiciones, costo documentado, corte y contrapartida aprobada | Usar el endpoint antiguo como ingreso nuevo, importar saldos sin decisión económica o marcar prepared como éxito |
| Compra | Recepción de proveedor con factura/líneas, landed cost y obligación/fondos | Usarla para representar mercancía antigua y duplicar inventario/CxP |
| Ajuste/merma | Hecho documentado y efectos autorizados según su circuito | Usar un update de modelo o origen libre como autorización |
| Reversión | Corrección trazable desde el documento/movimiento original y límites físicos vigentes | Borrar el original o recalcularlo para esconder diferencias |

La ruta antigua no acepta cantidades/costo enviados como autoridad: el controlador solo utiliza item_id y el dominio vuelve a leer el artículo bloqueado. El ensayo manda cantidades 500 y volumen 900 y verifica que sigue fotografiando 5 unidades a costo conocido 3, sin cambiar item/lotes. Solicitud sin item_id se rechaza antes de efectos.

La apertura heredada vacía conserva el ajuste cero preexistente. Cambiarlo forma parte de P9 pendiente, no se utiliza para crear stock. Un costo obtenido por fallback tampoco equivale a evidencia de valoración inicial aprobada; la fotografía no autoriza completar un balance ni suplanta la futura acta.

H01-B2B1 conserva `assertConfirmationUnavailable`: la validación y elegibilidad puras no confirman aperturas. Las dos tablas documentales B2A no tienen escritor funcional nuevo aquí. No se utiliza prepared histórico como fixture ni se lo convierte.

## 5. Relación entre existencias, movimientos y costo

La autoridad depende del contrato: agregado items + lotes describen posición física; ledger describe movimientos y saldo valorado. El precio `precio_c` es comercial, no costo de entrada. Compras conservan landed cost y promedio móvil; el costo de salidas contables sigue leyendo ledger. No se calcula un costo nuevo para H01.

En no fraccionables las pruebas verifican: saldo inicial 5 unidades × costo 3 = 15; compra sintética de 5 a costo 7 deja 10 unidades y valor de ledger 50, promedio del item 5, lote adicional enlazado a la factura y CxP por el circuito real. Merma de una unidad deja agregado/lote/libro en 4 y salida de valor 3, sin tocar la entrada original. El precio comercial 999 no entra en ese costo.

Esto no certifica todo estado histórico: la apertura antigua no concilia por sí sola diferencias entre agregado y lotes. Legacy fraccionable mantiene ambigüedades H03; V2 separa cerrados/abierto, usa volumen nativo, asignaciones y valor documental exacto. No se convierten ni recomponen cantidades históricas. Las restituciones legacy siguen sus límites de valoración; solo referenciar la venta original no prueba devolver su costo original.

## 6. Correcciones implementadas

1. `StockLedger::registrarApertura`: después de exigir transacción, reutiliza `InventarioService::bloquearUsuarios` antes del mutex de item y lectura de ledger. Conserva la relectura bloqueada, rechazo V2, criterio de una sola apertura, cantidades, costos y referencias existentes.
2. `InventarioController::registrarApertura`: catch Throwable para revertir también Error. Respuestas web y mensajes existentes conservados; sin endpoint nuevo ni cambio de permisos.
3. `MermaController::store`: guarda nivel inicial, captura Throwable y revierte hasta el nivel propio en ambos catches, incluyendo niveles internos que hayan quedado abiertos. Si el llamador tiene una transacción exterior, se conserva. No cambia merma, FIFO, costo, cuentas ni asientos.

No se modifica `StockLedger::registrar`, Articulo, servicios físicos, CompraService, ContabilidadService, esquemas, H03/H04 o código H06-P1. No se elimina ni crea una política de ajustes para justificar la intervención.

## 7. Compatibilidad H02/H03/H04

Se mantiene el protocolo de prefijos de documento/agenda cuando corresponde y usuarios → items en orden → lotes → ledger/asignaciones. Apertura usa el helper compartido de usuarios en lugar de otro sistema de locks. La ausencia de filas previas en ledger no es mutex suficiente; el mutex de item existente se conserva. Bajo caller arbitrario que ya haya tomado otros locks no se promete un orden global: todos los escritores deben respetar su contrato.

La corrección de mermas cambia exclusivamente cierre transaccional ante errores, no el algoritmo de inventario. Gate H03 intacto; no se cambia foto ni tratamiento de ceros. H04 conserva evidencia, continuidad de envases, originales y política de restitución/no restitución. Se leen sus documentos y fuentes para delimitar compatibilidad, sin ejecutar sus suites SQL.

Anular compra ya rechaza una factura anulada, retenida, CxP con abonos, lotes consumidos y stock insuficiente; V2 de compra sigue bloqueado según su contrato. No se implementa en H01 una anulación nueva de actas ni se recalcula la reversión legacy de ventas.

## 8. Compatibilidad H06-P1

No se modifican `PrestacionCompartidaService`, sus modelos/DTO, migración preparada, puertas o participantes/comisiones. Configuración `enabled/sql_verified` permanece false, y H03 no se activa. El test nuevo comprueba esa indisponibilidad y que una resolución PDO está prohibida.

Se ejecuta solo una regresión aislada de sus pruebas ya existentes y del carrito/lector de consumos. No es continuación funcional de H06 ni ensayo de su DDL. Una prestación compartida no recibe otra ruta de entrada/ajuste de inventario por esta entrega.

## 9. Pruebas y aserciones

Clasificación de evidencia:

- **Pura:** contratos/identidad/elegibilidad de apertura previos. Sus políticas y códigos son hipótesis explícitas en memoria, no aprobación contable empresarial.
- **Servicio real con persistencia simulada:** StockLedger, CompraService y el inventario de merma reales, interceptados mediante MySqlConnection sintético; no se resuelve PDO. El helper solo admite tablas/consultas de ensayo y no puede ejecutar SQL general.
- **Controlador/validación aislados:** InventarioController y MermaController con Request/fábrica Laravel reales; contabilidad, respuestas web y presencia de IDs de validación son dobles. No hay kernel, middleware, sesión HTTP, correo ni servidor empresarial.
- **SQL integrada:** no ejecutada. Snapshots de memoria y trazas de `for update` no son rollback ni exclusión InnoDB.

Selección H01: `H01InventoryTraceTest`, `OpeningDocumentContractTest`, `OpeningEligibilityTest`, `OpeningIdentityTest`, `OpeningCatalogueGuardTest`, todos bajo tests/Unit, con autoload sin phpunit.xml ni bootstrap empresarial.

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d memory_limit=128M -d max_execution_time=60 -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result --no-progress tests/Unit/H01InventoryTraceTest.php tests/Unit/OpeningDocumentContractTest.php tests/Unit/OpeningEligibilityTest.php tests/Unit/OpeningIdentityTest.php tests/Unit/OpeningCatalogueGuardTest.php
```

Resultado final H01: **138 pruebas / 327 aserciones**, todas aprobadas (30 nuevas / 143 aserciones y 108 previas / 184). La regresión de compatibilidad pasó: **123 pruebas / 349 aserciones**, selección explícita de `PrestacionCompartidaTest`, `PrestacionFisicaTest`, `CitaConsumptionCharacterizationTest`, `CarritoVentaTest` y `CarritoVentaHttpTest` con esos mismos flags. Total de ambas selecciones: **261 pruebas PHP / 676 aserciones**, sin suites generales. Frontend existente: `node --test tests/Frontend/inventory.test.cjs`, **3 aprobadas**. Lint de los cinco PHP de esta fase y `git diff --check` aprobados. No se modifican vistas/JS/CSS; no hizo falta recompilar artefactos.

| Caso solicitado | Aserción o límite |
| --- | --- |
| 1. Alta vacía | Cantidad/volumen cero; no lote, libro o asiento; costo/marcador del cuerpo ignorados |
| 2. Alta con stock | Rechazo antes de persistencia, incluidos cero/NULL en ambos campos, alta y edición |
| 3. Inicial de costo conocido | Apertura heredada real: 5 × 3 = 15, referencia al item/actor y sin crear existencias |
| 4. Costo inicial inválido | Contrato puro previo rechaza importes/fuentes inválidos; no existe escritor H01-B autorizado para confirmarlos. No se inventa costo sustituto |
| 5. Repetición | Dos llamadas secuenciales desde argumento inicial: una sola apertura; segunda devuelve NULL. Sin promesa de SQL simultáneo |
| 6–7. Edición/stock directo | Nombre/precio cambian sin alterar cantidades/lotes/libro; campos físicos rechazados y promedio no asignable por HTTP |
| 8. Mercadería de compra | CompraService real sobre memoria, factura/línea/lote/entrada/CxP enlazados y promedio/valor coherentes; contabilidad doble |
| 9. Ajuste autorizado | Merma documentada real y ajuste cero legado caracterizado. No se inventa ajuste positivo libre ni autorización económica |
| 10. Sin origen | Apertura sin item_id y contrato sin evidencia/política rechazan; no existe endpoint ordinario de ajuste arbitrario. Helper genérico/SQL privilegiado no se declaran protegidos por este ensayo |
| 11–12. Libro/lotes/costo | Igualdad física por unidades y valor en compra/merma/apertura; precio comercial no participa; no se retocan lotes originales |
| 13–15. Fallos y rollback | Centinela después de UPDATE de stock/INSERT de ledger; estado visible capturado antes del error y ausencia de filas/cambios tras rollback de memoria. Error contable anidado conserva nivel del caller |
| 16. Concurrencia | Copia obsoleta frente a saldo cambiado antes del lock y segundo intento serial simulan interleaving; traza usuario/item/ledger. Dos conexiones pendientes |
| 17. Anulación/restitución | Inspección de rutas/guardias/referencias originales H04; prueba física/contable completa pendiente. No se fuerza V2 ni se afirman valores originales en legacy |
| 18. Originales | Entrada de compra anterior permanece idéntica tras merma; errores restauran filas sintéticas previas. No se leen/escriben documentos históricos |
| 19. Formularios/rutas | Rutas y validación sin cambios de permisos; tres pruebas frontend del formulario real pasan. CSRF/roles/kernel completo no se vuelven a probar |
| 20. Rama prospectiva | Puertas cerradas, H01-B sin confirmación y regresión aislada H06-P1/H05/lector legacy |

La prueba de Error contable utiliza un doble que abre un nivel interno y falla; no afirma haber persistido un asiento real ni validar su cuadre. La recepción del comando contable se observa para comprobar el recorrido existente sin inventar cuentas.

## 10. Regresiones SQL pendientes para Debian

Con autorización nueva y laboratorio independiente, nunca las tres bases actuales:

1. Dos conexiones: apertura heredada/apertura, apertura/compra, apertura/merma y apertura/edición. Barreras observables, mutex users → items, relectura y un solo ledger; no envolver con una TX exterior que oculte commits.
2. Compras/mermas reales: conciliar items, lotes, ledger, CxP y asiento en centavos; período cerrado y costo inválido rechazan sin residuos. Probar fallo después de item, lote, movimiento y líneas contables; leer desde otra conexión y exigir nivel cero al terminar cada caso autónomo.
3. Reversión según contrato H04, cambio de costo posterior, artículo archivado, no restitución y documento ya anulado. No modificar expectativas para ocultar el límite legacy ni activar V2 en producción.
4. Apertura física H01-B únicamente después de políticas aprobadas y autorización de implementación: acta/UUID, corte, elegibilidad, fuentes, lotes, valor/asiento y concurrencia con ingresos pendientes. No transformar la apertura vieja ni reconstruir kárdex.
5. Verificar índices, FK, motores InnoDB, planes de bloqueo y rollback real. Registrar avances AUTO_INCREMENT por separado: rollback de filas no asegura restaurar contadores. Ningún fixture debe avanzar contadores históricos para realizar esta prueba.

No se reutilizan los launchers de documentos anteriores que admitían escritura/recreación de salon_erp_test; aquella autorización histórica no aplica a H01-R.

## 11. Decisiones de valoración/contabilidad pendientes

P1–P9 de H01-B2B0 permanecen pendientes: contrapartida exacta/aprobación del contador, base/fuentes de valoración y costo cero, corte/zona/pendientes, lotes desconocidos/vencimientos, evidencia y responsables, clasificación individual V2 y contención de la apertura antigua al activar la nueva.

El saldo físico existente no demuestra compra, aportación nueva o ingreso. No se carga a proveedores/caja/ingresos ni se usa una cuenta puente por conveniencia. No se implementa el escritor de apertura ni un ajuste positivo mientras falten esas decisiones. Tampoco se crea nueva política de idempotencia de compras/mermas sin identidad inequívoca de solicitud.

## 12. Archivos modificados

| Archivo | Cambio |
| --- | --- |
| `app/Http/Controllers/InventarioController.php` | Solo catch Throwable en apertura heredada |
| `app/Http/Controllers/MermaController.php` | Catch Throwable y rollback hasta nivel dueño |
| `app/Models/StockLedger.php` | Lock compartido de actor antes del mutex de item en apertura |
| `tests/Support/H01InventoryMemory.php` — nuevo | Persistencia sintética con PDO prohibido, trazas y snapshots explícitos |
| `tests/Unit/H01InventoryTraceTest.php` — nuevo | Casos reales aislados, fallos centinela y limitaciones |
| `docs/H01_R_TRAZABILIDAD_INVENTARIO.md` — nuevo | Este informe |

Árbol Git limpio al comenzar. No se modificaron archivos de H06-P1, carrito, nómina o pagos a proveedores, ni configuración, rutas, vistas, dependencias, migraciones o catálogos empresariales. Los archivos compartidos InventarioController/StockLedger solo cambian en la apertura heredada indicada; no se modificaron algoritmos de consumo, fotografía o reversión H03/H04. No se retocaron pruebas previas para obtener verde.

## 13. Preservación de bases

No se abrieron conexiones SQL a `salon_erp`, `salon_erp_test` o `salon_erp_bootstrap_check`. No hubo DDL/DML empresarial, migraciones, seeders, restauraciones, cambios de credenciales o uso de datos reales. Eloquent genera SQL únicamente como entrada al doble; el doble intercepta select/insert/update y prohíbe resolver PDO tanto de lectura como de escritura. No se inicia el bootstrap de Laravel del ERP ni se cargan credenciales .env.

Evidencia AC-04/AC-05, preparados de H01-B2A, inventarios/asientos/lotes originales y configuraciones de Laragon permanecen intactos por ausencia de operaciones sobre ellos. No se afirma una comparación SQL nueva de hashes de las bases. No se ejecutaron Git de escritura, servicios, infraestructura o despliegues.

## 14. Riesgos residuales

- H01-B sigue sin escritor autorizado: crear catálogo vacío funciona, pero ingresar stock inicial físico nuevo necesita políticas y caso de uso posteriores.
- Apertura heredada usa costos disponibles y agregado previo sin acta/corte/conciliación integral; no es autorización para corregir diferencias ni prueba de que todo lote/costo histórico sea válido.
- Rama legacy fraccionable y reversión legacy mantienen limitaciones documentadas H03/H04; el aislamiento de esta fase no certifica su corrección empresarial.
- Origen de negocio, existencia de referencia y valor original son comprobaciones distintas. Un asiento balanceado o una FK válida no prueban que el hecho físico ocurrió.
- No hay idempotencia universal de nuevas compras/mermas; dos solicitudes distintas pueden ser dos documentos. No se promete deduplicación por coincidencia de campos.
- Modelo/SQL/restore privilegiado pueden eludir contratos; no se instala un sistema general de interceptación de escrituras ni se oculta ese límite.
- Trazas y memoria no prueban MySQL. Esperas, deadlocks, constraints, cuadratura contable y ausencia de residuos desde segunda conexión siguen pendientes de laboratorio independiente.

## 15. Recomendación de continuación

Resolver las decisiones P1–P9 de apertura física con propietario/contador y revisar por separado el contrato privilegiado de restauraciones. Después, con autorización expresa, implementar el ingreso inicial documental ya diseñado y validar el protocolo completo en Debian independiente. No presentar una compra ficticia o actualización directa como solución temporal.

H01-R termina aquí: CRUD previamente protegido conservado, atomicidad/orden de apertura y merma corregidos, sin activación o ampliación contable. No se comienzan otras fases, migraciones, laboratorio o despliegues.
