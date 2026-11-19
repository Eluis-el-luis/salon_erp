# AC-05A — Auditoría de clasificación y reportes contables

Fecha: 19 de noviembre de 2026. Alcance: inspección estática del código; sin ejecución de PHP, Artisan, PHPUnit, SQL, cierres ni servidores. Único entregable nuevo: este documento. No se implementa AC-05B.

## 1. Resultado y alcance de la evidencia

La selección económica de resultados, dashboard y cierre fiscal depende de `4.%`, `5.%`, `6.%` y `7.%`. El balance depende del primer carácter del código y clasifica cualquier código restante como «otros». Diario y mayor conservan una lectura por asiento/cuenta, sin esa clasificación. Cambiar únicamente una de estas consultas puede romper la concordancia entre reportes y cierre.

Convenciones de este informe:

- **H — Hecho observado:** condición directamente localizada en código. No significa que se haya observado un defecto en datos reales.
- **R — Riesgo inferido:** consecuencia estática o ejemplo numérico propuesto; no reproducido mediante ejecución en esta fase.
- **D — Decisión pendiente:** significado económico, presentación o tratamiento histórico que debe aprobar el contador antes de implementarse.

Se siguieron rutas, controladores, servicios, modelos, migraciones, vistas y exportadores existentes. Las referencias de línea corresponden al árbol inspeccionado, no a una nueva versión desplegada. No se consultaron saldos, catálogo real, número actual de migraciones ni reportes emitidos de ninguna base. Las 82 migraciones y los resultados de AC-04 son antecedentes aportados, no verificaciones SQL de AC-05A.

## 2. Inventario real de reportes y consumidores

| Reporte o consumidor | Entrada y fuente | Alcance observado |
| --- | --- | --- |
| Libro diario | `routes/web.php:246`; `ContabilidadController::diario`, línea 17; `accounting/diario.blade.php` | Asientos con detalles/cuentas, ordenados por `created_at` descendente; sin filtro económico por código, período o estado del asiento. |
| Libro mayor | `ContabilidadController::mayor`, línea 72; `accounting/mayor.blade.php` | Cuenta individual, fechas opcionales, búsqueda de concepto y centro de costo. Sumas y saldo D−H; no consolidación de descendientes. |
| Estado de resultados | `ContabilidadController::resultados`, línea 199; `accounting/resultados.blade.php` | Mes por `a.fecha`, mes actual por defecto, o `mes=todo`; selección por prefijos y exclusión de cierres. Navegación mensual, sin comparativo formal paralelo. |
| Balance general | `routes/web.php:250`; `PeriodoContableController::balanceGeneral`, línea 87; `accounting/balance.blade.php` | Acumulado hasta `fecha_corte`, incluido cierre; clasificación por primer carácter y resultado residual virtual en patrimonio. |
| Dashboard financiero | `PanelController::index/datos`; `DashboardService::panel/kpisRango/serieTemporal/cobrosPorMetodo/metaMes` | KPIs del mayor, series diarias/mensuales, cobros por cuatro códigos de fondos y avance de meta. Ranking/tickets provienen de ventas. |
| Consulta de períodos | `PeriodoContableController::index/show`; `accounting/periodos/*` | Lista y detalles del período, sumas globales D/H de sus asientos; no balanza por cuenta. |
| Cierre fiscal | `PeriodoContableController::cierreFiscal`, línea 175 | **Escritor financiero**, no solo reporte: selecciona resultados y genera asiento `CIE-*` contra `3.2`. Se inspeccionó sin ejecutarlo. |
| Alta de período | `PeriodoContableController::store`, línea 39 | Crea intervalo abierto; no genera asiento de apertura ni traslado financiero. |
| Consulta de asientos manuales | `AsientoManualController::index/show`; vistas de asientos | Lista filtrada al origen manual; detalle de asiento. La lista manual no representa todas sus reversas ni todo el diario. |
| Reportes operativos | `ReporteController::index`, línea 16; `reports/index.blade.php` | Ventas devengadas por fecha de creación, pagos, rendimiento/comisiones, inventario a precio comercial y diferencial de cambio documental. No son un estado de resultados del mayor. |
| Exportación «Excel» de ventas | `ReporteController::exportExcel`, línea 84; `/reportes/excel` | CSV de facturas y totales; no exportación del diario, mayor o balance. |
| Impresión «PDF» de ventas | `ReporteController::exportPdf`, línea 130; `reports/pdf.blade.php` | HTML preparado para imprimir desde navegador; no archivo contable certificado con versión de clasificación. |
| Catálogo y exportación | `CuentaContableController::index/arbol/exportar`; `CuentasContablesExport` | Árbol y Excel de código, nombre, tipo, naturaleza, padre, imputabilidad y actividad. No sumas de saldos. |
| Libro bancario | `BancoController::libro`, línea 90; `banks/libro.blade.php` | Movimientos en moneda nativa, asiento relacionado y saldo corriendo. Se limita a 300 movimientos. |
| Extractos de conciliación | `BancoController` y `FondosService::saldoInicialConciliado`, línea 220 | Documentos de conciliación, base del último extracto cerrado o cero; independientes de la clasificación del balance. Contrato AC-04 conservado. |
| Kárdex y exportaciones | `InventarioController`, `KardexExport`, rutas `/inventario/kardex/*` | Cantidades y valoraciones documentales de inventario. Son auxiliares de conciliación, no clasificación de estados financieros. |

**H:** no se localizaron ruta, vista o implementación de balanza de comprobación ni módulo formal de comparativos contables en el recorrido y búsquedas de `balanza`, `balance de comprobación`, `trial balance` y `comparativ`. No se propone crear esos módulos en esta fase. Tampoco se localizaron exportadores dedicados de los cuatro estados/libros contables principales; imprimir una página no equivale a conservar una edición histórica.

## 3. Dependencias exactas de selección y presentación

### 3.1 Diario, mayor y centros de costo

**H:** `ContabilidadController::diario` carga todos los asientos y sus cuentas sin discriminar `estado`, origen o período. El selector de gasto de esa misma pantalla usa `permite_movimiento=true` y `codigo LIKE '5.%' OR '6.%'` en líneas 23–28. Este selector afecta la captura operativa, no el total del diario; no debe confundirse con una clasificación de reportes. `contabilizarGasto` mantiene sus validaciones de cuenta y período.

**H:** `mayor` ofrece cuentas imputables con detalles, sin exigir `activa=true`; consulta por `cuenta_id` exacto, no por prefijo ni por hijos. Sus filtros de fecha usan `asiento.fecha`; la búsqueda `LIKE` sobre concepto es textual, no económica. El saldo es D−H y la vista lo denomina deudor/acreedora según su signo; no toma valor absoluto ni lo invierte usando `naturaleza`.

**R:** el saldo corriendo empieza en cero para el conjunto filtrado. Un rango de fechas muestra el neto del rango, no necesariamente el saldo inicial acumulado más sus movimientos. Cambiarlo exige distinguir ambos conceptos en el contrato del reporte. El selector de centros ofrece centros activos; los detalles y su agrupación incluyen referencias históricas y «Sin asignar». No deben perderse movimientos por añadir un `INNER JOIN` a centros activos.

**R:** las lecturas generales no filtran el estado del asiento. No se ha comprobado que existan borradores con detalles o anulados con efectos duplicados. Antes de introducir `estado=contabilizado` debe caracterizarse la semántica real de cada productor y reversa, sin borrar sus originales.

### 3.2 Estado de resultados

**H:** `ContabilidadController.php:213` selecciona cuentas imputables por `4.%/5.%/6.%/7.%`; filtra sus detalles por fecha y `modulo_origen != 'cierre_fiscal'`. No usa `tipo`, `naturaleza` o `activa` para clasificarlas. En líneas 242–253 aplica:

| Grupo actual | Selección económica | Aportación |
| --- | --- | --- |
| Ingresos | Prefijo 4 | H−D |
| Costos | Prefijo 5 | D−H |
| Gastos operativos | Prefijo 6 | D−H |
| Otros egresos | Resto de la selección, actualmente 7 | D−H |

Resultado bruto = ingresos − costos; operativo = bruto − gastos operativos; neto = operativo − otros egresos. La vista repite los prefijos en `resultados.blade.php:71,86,107,126,129`: sustituir solo el controlador puede producir totales sin renglones visibles.

**R:** `!=` no incluye valores SQL NULL. `modulo_origen` es nullable en la migración original: un asiento con origen NULL podría aparecer en diario/mayor/balance y quedar fuera de resultados/dashboard/cierre. No se afirma que existan tales registros. Debe probarse y decidirse su tratamiento explícito.

**H:** el filtro mensual usa fecha, mientras el encabezado busca un período; `mes=todo` puede mostrar el primer período abierto o el último como referencia, aunque agrega toda la historia. El cierre utiliza `periodo_id`, no exactamente ese universo por fecha. No se debe identificar automáticamente «mes», «período» e «histórico completo».

### 3.3 Balance general

**H:** `PeriodoContableController.php:91` incluye cuentas imputables y detalles hasta el corte, sin filtrar actividad, estado del asiento ni origen de cierre. En líneas 117–145 clasifica por el primer carácter: 1 activo D−H; 2 pasivo H−D; 3 patrimonio H−D; 4 ingresos H−D; 5 costos D−H; 6 gastos D−H; **cualquier otro código** va a otros D−H. Este último grupo no está limitado a `7.%`.

**H:** el resultado residual acumulado se añade como objeto no persistido con código visual `3.99`, líneas 153–161. No es una cuenta creada ni una contrapartida autorizada para apertura. La vista recibe las colecciones; sus rótulos «Clase 1/2/3» también expresan la convención numérica actual. Se omiten saldos con magnitud no superior a 0,005.

**R alto:** una cuenta de activo con código alternativo puede desaparecer de activos y compensarse artificialmente mediante el residual. Incluso un balance aparentemente cuadrado no demuestra clasificación correcta. Podría existir además coincidencia visual con una cuenta real `3.99`; no se verificó el catálogo.

### 3.4 Dashboard y consumidores indirectos

**H:** `DashboardService.php:50,78,275` repite selección y signos de resultados mediante `CASE ... LIKE`, `NOT LIKE` y filtro de cierre. No se localizaron `SUBSTRING`/`LEFT` económicos en estos reportes; el equivalente PHP relevante es `str_starts_with`. Las series incluyen ingreso/costo; los KPIs también gastos/otros. La meta mensual depende del ingreso calculado: un cambio de clasificación afecta su avance.

**H:** `cobrosPorMetodo`, línea 129, usa únicamente origen `ventas`, neto D−H, y códigos `1.1.1`, `1.1.1.2`, `1.1.2.1`, `1.1.2.2`; agrupa efectivo, LAFISE y BAC. Es una asociación fija entre cuenta y método/recurso, diferente de clasificar gasto/ingreso. El cargo piloto no es un cobro de ventas y no debe añadirse al donut por utilizar fondos bancarios.

**R:** una nueva subcuenta de fondos legítima puede quedar fuera de ese gráfico. Un papel `fondos.banco` no basta para decidir el método comercial ni su etiqueta. Debe aprobarse el mapeo por recurso y conservar importes NIO del mayor, sin sumar dólares nativos a córdobas.

Como dependencias de captura, no como agrupadores del reporte, también se localizaron `CajaChicaController::index` (`codigo LIKE '6.%'` para cuenta de gasto inicial) y `BancoController.php:78–79` (cuentas sugeridas `6.9` y `4.7`). `ContabilidadService::getCuentaIdByCodigo` conserva la resolución heredada por código para los productores financieros. Esos puntos siguen vigentes: permitir mostrar una cuenta alternativa en un estado no autoriza cambiar qué cuenta utiliza una operación. Los códigos de moneda NIO/USD y los conceptos salariales `SAL_BASE`, `COM_VENT`, etc. son identidades de otros dominios, no prefijos de clasificación de reportes.

**H/R:** `PanelController::movimiento` consulta asientos de origen `ventas` y referencia de venta. `ContabilidadService::crearAsientosCostoVenta`, líneas 540–577, utiliza origen `inventario` para CST/CMV. Por inspección, ese detalle del panel puede omitir asientos de costo aunque los KPIs sí los incorporen. La incidencia depende del documento consultado; no se ejecutó un caso.

El ranking de servicios usa detalles de ventas, cantidades y precio, no el mayor; sus fechas y descuentos no son un sustituto del ingreso contable. La lista de tickets tampoco es una lista de todos los asientos.

## 4. Metadatos existentes y tratamiento de jerarquías

**H:** `CuentaContable` conserva ID, código, nombre, seis tipos (`activo`, `pasivo`, `patrimonio`, `ingreso`, `gasto`, `costo`), naturaleza, padre, nivel, imputabilidad, actividad y cuenta de sistema. No tiene un filtro global que elimine inactivas de la historia. `DetalleAsiento` conserva cuenta, centro opcional, moneda, tasa, debe/haber; `AsientoContable` conserva fecha, período, origen y referencias.

`AccountIntegrityService::save`, línea 81, protege código/tipo/naturaleza/padre/imputabilidad de cuentas o ramas utilizadas; revisa detalles, bancos, gastos, estructuras salariales y dependencias adicionales, incluidas asociaciones contables. Impide ciclos, padres inexistentes/inactivos o de tipo incompatible. Admite naturaleza opuesta para correctoras. No exige que el prefijo coincida con el tipo. Las cuentas de sistema conservan sus restricciones. La lectura histórica y reversas verificadas pueden conservar cuentas inactivas; una imputación nueva debe validarse de forma distinta.

**H:** catálogo y componente `cuenta-row.blade.php` presentan el árbol; los estados actuales suman movimientos directos de cada cuenta imputable. No consolidan automáticamente los hijos. Es posible representar padre imputable e hijo imputable; crear un hijo no convierte silenciosamente al padre en agrupadora.

**R:** sumar un subtotal del padre y después sumar nuevamente sus hijos duplica importes. Con padre directo 10 e hijo 20, el total es 30; agregar subtotal padre 30 más hijo 20 produce 50. Una agrupadora sin detalles e hijo 20 no debe sumar 40. Debe definirse si se permite mostrar ambos niveles y cuál contribuye al total.

**D:** `naturaleza` expresa orientación normal, no la prohibición de registrar al lado contrario ni el signo final del grupo. Un retiro patrimonial deudor reduce patrimonio H−D; no debe convertirse en incremento por tomar su valor absoluto. Lo mismo aplica a descuentos contra ingreso y correctoras de activo. Tipo y naturaleza son útiles, pero no resuelven por sí solos gastos operativos frente a otros, corriente/no corriente, orden o subtotales.

## 5. Cierres, aperturas y reversos

### 5.1 Cierre y resultado del balance

**H:** `PeriodoContableController::cierreFiscal` fija origen `cierre_fiscal`, referencia al período, fecha final y contrapartida literal `3.2`. Selecciona imputables `4.%` a `7.%`, detalles por `periodo_id` y excluye cierres. Bloquea el período, crea líneas, valida cuadre y lo cierra dentro de transacción. No establece `tipo_asiento='cierre'`: el valor por defecto es `diario`. Sustituir el filtro de origen por tipo puede incluir cierres inadvertidamente.

**H:** el balance incluye el cierre para que el resultado trasladado a patrimonio deje de figurar en el residual. Resultados y dashboard lo excluyen para mostrar el desempeño del período. Son contratos distintos y deliberadamente no deben compartir un filtro universal.

**R — ejemplo derivado, no ejecutado:** ingreso 100, gasto 20 y banco neto 80. El cierre debita ingreso 100, acredita gasto 20 y patrimonio 80. Después: balance con banco 80, patrimonio 80 y residual cero; resultados sin cierre sigue mostrando 80. Si el balance mantuviera patrimonio 80 y añadiera el resultado sin cierre de 80, mostraría 160. Excluir cierres indiscriminadamente también alteraría la presentación del patrimonio trasladado.

**R alto — saldos de signo contrario:** con descuento deudor 10 en `4.5`, el algoritmo de cierre calcula H−D = −10 y escribe `debe=-10`, `haber=0`. Las vistas de diario/detalle muestran celdas solo cuando el importe es mayor que cero, aunque las sumas incluyen el importe firmado. Un gasto con saldo acreedor puede producir haber negativo. El esquema original define DECIMAL firmado; este caso se deduce del código, no se ha probado contra SQL ni se afirma su presencia histórica. Debe caracterizarse antes de autorizar cualquier modificación del cierre o su visualización; AC-05A no lo corrige.

### 5.2 Aperturas y períodos

**H:** abrir un período no emite asiento financiero. La apertura heredada del kárdex no establece una política patrimonial de balance. H01-B2B sigue sin escritor físico/contable autorizado. Ni `3.2` del cierre ni `3.99` virtual constituyen una decisión de contrapartida inicial.

La resolución de período de `ContabilidadService::resolverPeriodo` bloquea períodos cerrados y puede crear un período mensual faltante para una operación. Los reportes inspeccionados no necesitan invocarla para leer historia. La futura lectura configurable no debe crear períodos ni exigir que estén abiertos.

### 5.3 Reversiones y documentos automáticos

**H:** `AsientoManualController::reversar`, línea 119, exige origen manual y estado contabilizado, impide repetición y crea otra cabecera de origen `reversion`, con cuentas/centros/monedas y lados invertidos. No elimina el original. La lista de origen manual no incluye esa nueva cabecera; diario y mayor sí pueden incluir ambos.

**H:** `ContabilidadService::reversarVenta`, línea 764, trabaja con asientos originales de ventas/inventario; H04-B permite conservar costos cuando no hubo restitución. Una venta anulada no autoriza eliminar sus líneas del mayor ni suponer que CST/CMV quedó en cero. Se deben conservar los movimientos realmente confirmados y sus reversas efectivas.

**H:** `ContabilidadService::reversarCargoBancarioSimulado`, línea 1821, crea un asiento de origen `bank_charge_refunds` en período destino abierto, con cuentas/importes históricos invertidos. Una devolución acredita gasto y debita banco; no es ingreso nuevo por ser un movimiento bancario de entrada. El cargo original y la devolución permanecen separados, incluso con cuenta original inactiva o política retirada. No debe resolverse otra cuenta con la política actual para mostrar ese historial.

**R/D:** filtros por descripción, número `REV-*`, estado comercial de venta o autorización vigente pueden omitir reversas legítimas y costos conservados. El tratamiento debe usar el origen y vínculos documentales verificados, conservando fecha y cuenta original. La aprobación de categorías de presentación no concede autorización de reversión.

## 6. Reportes auxiliares y divergencias de significado

**H:** `ReporteController::index/exportExcel/exportPdf` usa `sales.created_at` y `Venta::ESTADOS_DEVENGADOS`; pagos por libro de pagos y comisiones por fecha documental. No incluye todos los asientos manuales, bancarios o de cierre. Las sumas de comisiones mostradas no filtran su estado: no equivalen automáticamente a gasto, obligación pendiente o comisión efectivamente pagada.

**H/R:** la tarjeta de inventario multiplica `unidadesEquivalentesDisponibles()` por `precio_c`, precio comercial, no por valor histórico del kárdex/promedio móvil. Diez unidades a precio 50 muestran 500 aunque el activo histórico a costo 20 sea 200. No deben ajustarse costos ni inventario para hacer coincidir medidas distintas. Corresponde decidir y rotular su propósito antes de presentarla como valoración contable.

**H/R:** `BancoController::libro` ordena ascendente y toma los primeros 300 movimientos; resta su neto del saldo actual para derivar el inicial. Si hay movimientos posteriores omitidos, puede desplazar el saldo histórico del tramo mostrado. Ejemplo hipotético: apertura 1.000 y 301 cargos de 1 dejan 699; el subconjunto de 300 tiene neto −300 y calcula inicial 999, en lugar de 1.000. Es un riesgo de reporte localizado, no una nueva incidencia demostrada de escritura bancaria. No se reabre ni cambia AC-04 en esta fase.

Los saldos bancarios auxiliares están en moneda nativa; el mayor usa importes contables NIO. El saldo inicial conciliado procede del último extracto cerrado o cero según el contrato provisional, no de una cuenta virtual del balance. No se alteran extractos, cortes, saldo_actual o signos de movimientos.

## 7. Matriz diagnóstica de clasificación

Referencias abreviadas: CC = `ContabilidadController`; PC = `PeriodoContableController`; DS = `DashboardService`; RC = `ReporteController`. Las clases económicas de esta matriz describen el **contrato vigente**; no aprueban una reclasificación futura. «Prueba» remite a la sección 11.

| Reporte / sección | Selección y fuente actuales | Tipo / orientación esperados | Código fijo | Riesgo de sustitución | Metadatos disponibles | Falta definir | Aprobación contable necesaria | Prueba |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Diario | CC17; asientos + detalles, todos los orígenes | Todos; D/H originales | Ninguno económico | Excluir originales/reversos por nuevo filtro de estado | Cuenta, origen, fecha, período | Universo de estados y edición emitida | Universo documental del libro | 8,14,16 |
| Mayor | CC72; cuenta exacta, fechas, centro | Todos; saldo D−H | Ninguno; orden por código | Cambiar neto de rango por saldo de cierre; omitir inactivas | ID, centro, D/H, fecha | Saldo inicial por rango, centros históricos | Presentación de saldo/rango | 6,7,14 |
| Resultados / ingreso | CC213/242; vista71; detalles sin cierre | Ingreso, contribución H−D, incluidas correctoras | `4.%`, prefijo4 | Omitir ingreso alternativo o invertir descuentos | Tipo, naturaleza, cuenta | Grupo aprobado, correctoras | Ingreso y contraingreso | 2,8,12 |
| Resultados / costo | CC215/245; vista86 | Costo D−H | `5.%` | Mover costo a gasto cambia margen bruto | Tipo costo, historia | Subgrupos de costo y alcance de comisiones | Distinción costo/gasto | 4,13 |
| Resultados / operación | CC217/248; vista107 | Gasto D−H | `6.%` | Mezclar gasto operativo/otro; ambos pueden ser tipo gasto | Tipo/naturaleza | Dimensión operación/no operación | Subtotales y grupos | 3,4 |
| Resultados / otros | CC218/251; vista126/129 | Grupo histórico 7, D−H | `7.%` | Fallback silencioso oculta no clasificados | Tipo/naturaleza | Cobertura explícita de otros | Conceptos admitidos; sin inferir por nombre | 3,13 |
| Balance / activo | PC117; detalles hasta corte, con cierre | Activo D−H, correctoras negativas | Primer carácter1 | Activo alternativo tratado como «otro» | Tipo, ID, jerarquía | Rubros y corriente/no corriente | Clasificación de activo y correctoras | 1,5,7 |
| Balance / pasivo | PC124 | Pasivo H−D | Primer carácter2 | Omisión o signo incorrecto | Tipo/naturaleza | Rubros, corto/largo plazo | Clasificación de pasivo | 13,14 |
| Balance / patrimonio | PC131/153 | Patrimonio H−D + residual | Prefijo3, virtual`3.99` | Doble utilidad; confundir cuenta real y fila virtual | Tipo, origen cierre | Rubros, residual acumulado y etiqueta | Patrimonio/retiros y resultado no trasladado | 12,13 |
| Cierre / selección | PC218–270; detalles del período | Cuentas nominales; inversión de saldos | `4.%`–`7.%`; destino`3.2` | **Cambia asientos**, no solo presentación; negativos | Período, cuenta, D/H, origen | Tratamiento de correctoras y universo nominal | Autorización específica de cierre, fuera de cambio meramente visual | 11,12 |
| Dashboard / KPI-serie-meta | DS50/78/160/275 | Mismos grupos que resultados | `LIKE 4–7` | Dashboard, meta y estado divergentes | Mayor y fecha | Versión común de presentación | Mismas definiciones y cortes | 2–4,13 |
| Dashboard / métodos | DS129; origen ventas, D−H | Fondos netos de cobros | Cuatro códigos `1.1.1*`, `1.1.2.1/.2` | Omitir nuevo banco; confundir cargo con cobro | Cuenta y banco/recurso | Método y etiquetas por recurso | Mapeo de medios, sin alterar valoración | 9,13,17 |
| Períodos / resumen | PC index/show; asientos por período | D/H globales; incluye cierre | Sin grupo económico | Confundir volumen de movimientos con resultado | Período y detalles | Rótulos y universo | Alcance del resumen | 11,12,14 |
| Catálogo / árbol / Excel | CuentaContableController; exportador | Tipo/naturaleza y jerarquía actuales | Código como identidad visible/orden | Sumar niveles dos veces; reinterpretar historia | Padre/nivel/imputabilidad/actividad | Orden y consolidación de reporte | Política de agregación | 5,6,15 |
| Reportes / ventas y comisiones | RC16/84/130; documentos y pagos | Indicadores operativos, no total del mayor | Sin clasificación económica por prefijo | Forzar igualdad de medidas o fechas distintas | Estado/fecha documental/pagos | Conciliación de devengo/cobro y comisión | Definición de cada indicador | 13,17 |
| Reportes / inventario | RC59; equivalentes × precio_c | Valor comercial actual | No usa cuenta | Confundir con activo a costo | Cantidades V2; kárdex independiente | Propósito y etiqueta | Valor comercial frente a libro | 17 |
| Libro bancario / extractos | Banco90; Fondos220; movimientos/extractos | Moneda nativa y saldo documental | Cuenta bancaria vinculada; no prefijo | Tramo300; confundir NIO contable y moneda nativa | Banco, moneda, asiento, conciliación | Conciliación auxiliar/mayor y paginación | Sin cambiar contrato de cierre bancario | 9,10,17 |

## 8. Configurabilidad disponible y límites AC-02/AC-03

**H:** AC-02 ya protege referencias y atributos estructurales de cuentas usadas, valida cuentas nuevas y permite reversas históricas verificadas. Se puede reutilizar su integridad, permisos y auditoría. Los nombres aún pueden cambiar: reconstruir una pantalla histórica con el nombre actual no conserva necesariamente la etiqueta que tuvo una edición emitida.

**H:** `AccountingPolicyRoles::CORE` define seis papeles de contabilización; `fondos.banco` y `gasto.cargo_bancario` identifican efectos/recurso del piloto. `AccountingPolicyService::accountSnapshot`, línea 151, fotografía ID/código/nombre/tipo/naturaleza/actividad/imputabilidad y recurso. Versiones, hashes y auditoría permiten probar qué asociación se utilizó. Cabeceras/resoluciones del piloto guardan procedencia; los asientos anteriores conservan referencias nullable.

**R:** esos papeles no cubren toda la contabilidad manual, saldos heredados, correctoras o presentación financiera. Una cuenta puede tener varios bindings, recursos o versiones. Unir detalles a bindings solo por cuenta puede multiplicar una línea D50 por dos asociaciones y sumar D100. Exigir asociación vía `INNER JOIN` también elimina historia con referencia NULL. Una fotografía de selección de cuenta no equivale a una edición histórica del estado financiero.

**D:** faltan dimensiones explícitas de presentación: sección/subsección, contribución de correctoras, orden, corriente/no corriente cuando se requiera, operación/otros, cobertura completa y vigencia de una clasificación emitida. No deben deducirse por nombre, prefijo o simple publicación de una política. No modificar el manifiesto CORE ni las versiones AC-03 para convertirlos en un clasificador universal.

Permisos propuestos: reutilizar los roles contables `alvaro` y `contador`, actualmente aplicados al grupo contable de rutas, con comprobación de actor en el servicio y auditoría durable de preparación/publicación de la clasificación. No ampliar privilegios ni considerar el bypass administrativo general como aprobación económica del contador. La eventual administración de presentación necesita autorización de alcance, no nuevos permisos financieros implícitos.

## 9. Alternativas mínimas para una transición futura

| Alternativa de diseño | Ventaja | Límite/riesgo y autorización |
| --- | --- | --- |
| A. Usar tipo/naturaleza existentes, con grupos adicionales explícitamente aprobados | Cambio pequeño; ID de cuenta estable, sin renumerar | Tipo no separa 6/7 ni todos los rubros; cambios sin versión no preservan ediciones. Requiere revisar correctoras y cobertura; no activar por inferencia. |
| B. Clasificación de presentación versionada y asignación explícita por cuenta/estado | Conserva historial de clasificación y permite reportes futuros coherentes | Exige contrato y posible esquema aditivo autorizado en otra fase. Reutilizar patrones de hash/auditoría/permisos, no reutilizar bindings como relación universal. |
| C. Edición emitida con manifiesto/snapshot y hash | Permite conservar reporte «tal como se emitió» | Complementa A/B; requiere acordar conservación, cortes y evidencias. No permite reconstruir con certeza una edición antigua que nunca se archivó. |

Recomendación técnica para aprobación: B de alcance pequeño, complementada por C cuando se requiera preservar una edición formal. No crear otro motor financiero. El código de cuenta permanece como etiqueta/identidad existente; el agrupador utiliza IDs y asignaciones aprobadas.

Contrato mínimo propuesto, **no implementado**:

1. Una contribución principal por cuenta, estado y versión; cobertura inequívoca y rechazo/informe explícito de cuentas sin clasificación. Nunca fallback económico «otros» para ocultar faltantes.
2. Grupos y signos definidos por el contrato de presentación; conservar los D/H originales. No tomar valores absolutos por naturaleza.
3. Agregación jerárquica sin duplicar detalles; cualquier herencia de clasificación de padre debe aprobarse, no asumirse como precedencia.
4. Separar perfil de consulta (fecha, período, estado/origen, cierre, centros y moneda) de clasificación de grupos. Compartir la clasificación entre controlador, vistas y dashboard; conservar sus universos documentales distintos cuando correspondan.
5. Edición identificable por versión/hash, fecha de corte, filtros, universo de detalles o evidencia verificable, etiquetas utilizadas, emisor y fecha de emisión. El detalle financiero original no se reescribe.
6. Un cambio futuro puede presentar otra agrupación sin cambiar asientos; mostrar esa diferencia expresamente. Reexpresar comparativos o reclasificar una edición anterior requiere decisión específica y nunca sustituye el archivo original.
7. Lecturas de reportes no adquieren autoridad de contabilización ni resuelven cuentas históricas mediante una política vigente. No incorporan locks financieros o GET_LOCK administrativos por defecto.
8. El cierre es consumidor financiero de clasificación: cambiarlo requiere una intervención separada aprobada. La fase inicial de reportes no debe cambiar su selección o contrapartida silenciosamente.

No se propone una migración concreta en AC-05A; la elección entre campos adicionales, versiones/asignaciones y archivo de ediciones debe seguir a las decisiones contables. No se ha creado ninguna estructura.

## 10. Decisiones que requiere el contador

| Decisión | Evidencia que debe aprobarse antes de cambiar reglas |
| --- | --- |
| Grupos de los estados | Lista de secciones y cuentas por ID; tipos compatibles y excepciones justificadas; no correspondencias por parecido de nombre. |
| Costos frente a gastos | Ubicación de costo de producto/servicio/comisiones y tratamiento operativo/otros; efecto explícito en margen bruto y operativo. |
| Correctoras y saldos contrarios | Contribución de descuentos, retiros, contraactivos y devoluciones; visualización de saldos negativos y caso del cierre de `4.5`. |
| Balance y patrimonio | Corriente/no corriente si se desea; resultado residual acumulado, utilidades trasladadas y representación no confundible con cuenta real. |
| Universo documental | Inclusión por estado/origen, NULL heredado, fecha frente a período; conservación de reversas y CST/CMV no restituidos. |
| Historia y vigencia | Aplicación prospectiva, posibilidad de comparativo reexpresado y formato de edición emitida; tratamiento explícito de cuentas sin clasificación. |
| Jerarquía e inactivas | Nivel de presentación, subtotales, padres con imputaciones y garantía de inclusión de saldos históricos inactivos. |
| Cobros y auxiliares | Mapeo por banco/método; propósito de inventario a precio comercial y reportes de comisiones; conciliación con mayor sin igualar medidas diferentes. |
| Cierre y apertura | Cualquier cambio de reglas de cierre o de su contrapartida debe autorizarse aparte. La contrapartida de apertura H01-B2B continúa pendiente. |

Estas decisiones no quedan aprobadas por AC-05A ni por las políticas sintéticas del piloto. No se seleccionan nuevas cuentas o códigos.

## 11. Plan de pruebas propuesto para AC-05B

Ninguna prueba se ejecutó en esta fase. Los siguientes son fixtures futuros, no hechos empresariales. Las expectativas requieren el contrato de clasificación aprobado; primero caracterizar el comportamiento heredado, después comparar explícitamente las diferencias autorizadas. Los importes se contrastarán en centavos y con el mismo universo de movimientos.

| Caso | Fixture y verificación mínima |
| --- | --- |
| 1. Activo no convencional | Cuenta `ACT-X` de tipo activo, D100, capital H100. Diario D/H100; mayor activo100. Regla actual la envía a otros y puede mostrar activo0/patrimonio neto0. Con clasificación de activo aprobada: activo100/patrimonio100. No basta que el balance cuadre. |
| 2. Ingreso fuera de prefijo | Ingreso alternativo H120, banco D120. Tras aprobación expresa del grupo: ingreso120 en resultados/KPI/serie; detalle original idéntico. |
| 3. Gasto de código alternativo | Gasto D30, banco H30. Clasificación aprobada aporta30 al rubro previsto, sin convertirlo en costo ni ocultarlo como no clasificado. |
| 4. Costo diferenciado | Ingreso100, costo20, gasto operativo10: bruto80, operativo70. Verificar subtotales y cuentas correctoras sin cambiar D/H. |
| 5. Padre agrupador | Padre no imputable sin detalle, hijo D40: total40; mostrar subtotal padre no añade otros40. |
| 6. Subcuenta y padre imputable | Padre directo10 e hijo20: contribución30; mayor individual conserva10/20. No subtotal50. Probar centro activo/inactivo y sin asignar. |
| 7. Inactiva con historia | Cuenta histórica con saldo50 permanece en reporte tras desactivarse; nuevas imputaciones ordinarias se rechazan según AC-02. No filtrar historia por estado actual. |
| 8. Manual y reversa | Asiento manual D/H100 y espejo íntegro: cada documento aparece una vez; en misma ventana neto0, en ventanas distintas conservar ambos efectos fechados. |
| 9. Cargo configurable | Fixture autorizado de ensayo: banco1000, cargo50, saldo950, gasto D50/banco H50; dos resoluciones exactas. Incluir gasto en grupo aprobado, no como cobro de ventas. No activar política empresarial. |
| 10. Devolución acreditada | Espejo D banco50/H gasto50: mismo corte neto gasto0 y banco1000 en fixture completo. Meses distintos: gasto+50 y −50 respectivamente; nunca ingreso nuevo. Consultar cuentas originales tras retirada/desactivación/cambio de asociaciones. |
| 11. Períodos | Lectura de abierto y cerrado conserva importes/ediciones; el lector no crea períodos. Escritura en cerrado sigue bloqueada en su dominio. Probar diferencias fecha/periodo_id sin corregir historia. |
| 12. Cierre y contrapartida | Fixture ingreso100/gasto20: resultados80 antes/después; banco80 y patrimonio trasladado80 después, residual0. Caracterizar aparte descuento deudor10 en `4.5`, saldos contrarios y celdas negativas; no normalizar cierre sin autorización. |
| 13. Concordancia de estados | Mismo corte/clasificación: resultados y KPIs coinciden en grupos; reconciliar residual de balance con cierres incluidos. Documentar diferencias legítimas con ventas por created_at y cobros. |
| 14. Diario/mayor/balanza | Sumatoria de detalles por cuenta conserva D/H del diario; sumas globales D=H. Usar agregación de comprobación como oráculo de prueba: no afirmar que existe una balanza en la aplicación. |
| 15. Sin duplicación | Árbol con padres/hijos y una cuenta con dos bindings/versiones: una línea D50 sigue aportando50, no100. Cuenta sin clasificación o ambigua produce diagnóstico, no fallback automático. |
| 16. Ediciones históricas | Si se implementa archivo: edición/hash y cuentas originales permanecen inmutables; nueva clasificación genera versión/edición distinta y rotulada. Historia nullable no recibe backfill de política. |
| 17. Regresiones de dominio y auxiliares | AC-02/03, H03-B2/H04-B y cargos/devoluciones/conciliaciones AC-04: valoración/promedio/COGS originales intactos, cuentas exactas y auxiliares conciliables. Probar libro con más de300 movimientos, moneda nativa frente a NIO, inventario comercial500 frente a valor libro200 y comisiones por estado. |

Extensiones necesarias: origen NULL, fechas límite y `mes=todo`, grupos vacíos/cuentas en cero, código `4` frente a `4.%` y códigos con primer carácter similar, centros históricos, asientos automáticos y estado borrador/anulado. Ninguna expectativa debe modificarse para justificar una clasificación no autorizada.

Antes de ejecutarlas se inspeccionarán fixtures y efectos. Algunas pruebas existentes, como las de dashboard con `RefreshDatabase`, no son ejecutables ciegamente sobre el test con evidencia acumulada. No reutilizar ventanas persistentes AC-04 ni preparado131; no son fixtures desechables. No se promete concurrencia demostrada mediante este plan documental.

## 12. Orden recomendado y cambios que no conviene realizar

1. Aprobar grupos, universos documentales y tratamiento histórico de la sección 10; mantener el clasificador vigente hasta entonces.
2. Autorizar caracterización focalizada de lecturas con los casos 1–17 y diferencias documentadas, sin cambiar productores financieros.
3. Elegir contrato mínimo de presentación y conservación de ediciones; justificar cualquier DDL antes de solicitarlo. No reutilizar asociaciones de contabilización como categorías universales.
4. Preparar una lectura en paralelo de resultados/dashboard/vistas y balance, compartiendo asignaciones pero preservando el tratamiento específico de cierres. Comparar por cuenta y por centavos antes de sustituir salidas.
5. Integrar jerarquía, centros, etiquetas y exportaciones existentes; separar indicadores operativos de cifras del mayor. No crear balanza o comparativos solo para completar esta auditoría.
6. Tratar las incidencias de libro300, detalle del panel y líneas negativas como hallazgos separados, con pruebas y autorización correspondiente; no introducir su corrección dentro de un cambio genérico de clasificación.
7. Evaluar el cierre **en otra autorización** antes de admitir contabilización general con cuentas fuera de sus prefijos. Una presentación configurable no hace configurable el cierre ni autoriza apertura H01-B2B.

No conviene renumerar cuentas, reasignar detalles, cambiar importes/signos, editar versiones publicadas, reconstruir períodos cerrados, importar Excel, inferir clases por nombres, excluir cuentas inactivas con saldo ni decidir automáticamente costo/gasto. Tampoco conviene reemplazar el cierre por un total de resultados: sus universos y efectos son distintos.

Archivos potenciales de una futura fase, sujetos al alcance aprobado: `ContabilidadController`, lectura de `PeriodoContableController::balanceGeneral`, `DashboardService`, vistas `accounting/resultados`, `accounting/balance`, componentes de presentación y pruebas focalizadas. `cierreFiscal`, `ContabilidadService`, inventario, nómina y escritores bancarios quedan fuera de un refactor puramente lector. Cualquier nueva persistencia de clasificación/ediciones requiere autorización independiente.

## 13. Comprobación de alcance, bases, Git y reversión

Se usaron únicamente lectura de archivos, búsquedas con `rg`, hashes y consultas de estado/diff de Git. No se ejecutaron SQL, PHP, Artisan, PHPUnit, migraciones, procesos de cierre, servidores, importaciones o comandos de respaldo/restauración. No se accedió a ninguna base; no hay una nueva certificación de sus datos o migraciones. Por este método de trabajo no se emitieron movimientos, asientos, autorizaciones ni cambios de saldo en `salon_erp`, `salon_erp_test` o `salon_erp_bootstrap_check`.

`SALON_TEST_DB_RESET_ALLOWED=0` se comprobó mediante lectura puntual, sin modificar configuración. Credenciales, APP_KEY, permisos, dependencias y AGENTS.md permanecen fuera de edición. No se hicieron commits ni se abrió AC-05B.

La línea base Git contenía cambios y documentos de AC-04B2B4C: `AccountingHttpSimulationBoundary.php`, cuatro auxiliares de tests y archivos no rastreados de auditoría/navegador. Se conservaron. HEAD de referencia: `ad5fa5e7f2012156bf04a1a6787bf03c4b23114b`.

Se obtuvo una huella agregada de 492 archivos protegidos de aplicación, recursos, rutas, pruebas, configuración, migraciones/seeders, manifiestos, AGENTS.md y antecedentes AC-04, incluyendo solo el hash de archivos de entorno, nunca su contenido secreto. SHA256 inicial y final coincidentes: `9125e97e5873221eba895823dac60d6ebaee40d396ec956d1e712d961710e63f`. La comparación de Git añade únicamente este documento al estado inicial. `git diff --check` no informó errores de espacios; emitió advertencias de conversión CRLF/LF sobre tres auxiliares ya modificados antes de AC-05A, que no se editaron.

Diff propio de AC-05A: adición de `docs/AC_05A_AUDITORIA_REPORTES.md`. Reversión: retirar únicamente este documento si se decide descartar el diagnóstico; no restaurar bases, no revertir cambios previos de AC-04 ni ejecutar un reset general. No existe cambio funcional que desplegar o revertir.

Conclusión de alcance: diagnóstico completado; una clasificación futura requiere decisiones del contador y autorización expresa de AC-05B. La habilitación empresarial y las demás fases permanecen cerradas.
