# Auditoría de mantenibilidad e integridad — Salon ERP

Fecha: 2026-11-11.

## Dictamen y alcance

La arquitectura MVC con servicios es adecuada para un solo negocio. Conviene mantener un monolito modular: el problema principal es que las invariantes no se cumplen de forma uniforme entre entradas al sistema. No hace falta introducir microservicios, multitenancy, repositorios genéricos ni un framework de eventos para corregirlo.

Revisión estática de rutas, bootstrap, controladores, modelos, servicios de ventas/inventario/compras/nómina/contabilidad, migraciones relacionadas y pruebas existentes. Los escenarios siguientes se deducen del código; no se ejecutaron reproducciones contra SQL ni se inspeccionaron datos reales. Esto no certifica ausencia de otros errores ni cuantifica diferencias existentes en la base.

Stack declarado: PHP ^8.3, Laravel ^13.8, PHPUnit ^12.5.12; Vite ^8.0.0 y Alpine ^3.15.12. PHP CLI observado: 8.5.8.

P1 = corregir antes de ampliar el flujo afectado, por impacto financiero/inventario o acceso a datos. P2 = corrección funcional o estructural posterior. La prioridad no afirma que ya haya ocurrido corrupción de datos.

## Hallazgos

### H01 · P1 · El CRUD de artículos elude el kárdex

**Evidencia:** `app/Http/Controllers/InventarioController.php:38` y `:54`; `app/Models/Articulo.php:16`.

`store()` y `update()` aceptan `existencia_actual` y persisten `$request->all()`. Los campos asignables también incluyen volumen, capacidad y tipo. Se puede cambiar stock sin movimiento, lotes, valoración ni asiento. Después de comprar 10 unidades, editar a 50 conserva el saldo anterior del libro mientras la disponibilidad física muestra 50.

**Corrección:** usar exclusivamente datos validados; separar edición de catálogo de apertura y ajustes. Todo ajuste debe tener motivo, usuario, movimiento físico, kárdex y contrapartida contable cuando corresponda, dentro de una transacción. Bloquear cambios de unidad/capacidad/tipo que reinterpretan existencias con historial sin un proceso explícito de conversión.

**Prueba necesaria:** editar precio/nombre conserva stock; intentar cambiar stock por el CRUD se rechaza; un ajuste autorizado concilia físico, lotes y mayor.

### H02 · P1 · Las salidas permiten stock negativo y actualizaciones perdidas

**Evidencia:** `app/Services/InventarioService.php:115`, `:206`, `:229`; `app/Http/Controllers/MermaController.php:37`.

Productos e insumos no fraccionables se decrementan sin validar disponibilidad. El consumo fraccionado termina el bucle aunque quede volumen negativo. FIFO no falla si sobra cantidad pendiente. Además, se leen/escriben artículos sin `lockForUpdate()`: el lock posterior de lotes o ledger no protege la lectura previa del agregado.

Una venta de 3 unidades con existencia 1 puede persistir -2. Dos cajas pueden leer la misma existencia y sobrescribir cambios aunque ambas registren movimientos.

**Corrección:** cargar y bloquear artículos dentro del caso de uso, en orden estable; acumular demanda por artículo antes de descontar; comprobar disponibilidad y exigir consumo completo. Establecer una política explícita para stock heredado sin lotes, sin confundir compatibilidad con autorización para saldo negativo.

**Pruebas:** insuficiencia en producto, BOM y merma provoca rollback completo; dos conexiones compitiendo por la última unidad no pueden confirmar ambas operaciones.

### H03 · P1 · Compras y consumo interpretan de forma incompatible el volumen

**Evidencia:** `app/Services/CompraService.php:381`; `app/Services/InventarioService.php:130`; `app/Models/StockLedger.php:200`.

Comprar N frascos incrementa tanto `existencia_actual` en N como `current_volume` en N × capacidad. Sin embargo, alertas y apertura calculan disponible como unidades × capacidad + volumen abierto. Una compra de 2 frascos de 1.000 ml produce 2 unidades y 2.000 ml: esa fórmula muestra 4.000 ml, mientras el ledger registra 2.000 ml.

El consumo por volumen además descuenta unidades completas en `consumirLotesFIFOVolumen()` y vuelve a descontar FIFO cuando abre unidades en `consumirVolumen()`. El consumo parcial no queda registrado en el lote. Se pueden agotar lotes antes de agotar el volumen real y perder la capacidad de detectar compras parcialmente consumidas.

**Corrección:** definir una representación única: frascos cerrados + volumen abierto, o volumen total disponible. Alinear compra, consumo, reversión, alertas, apertura y valoración. Registrar asignaciones de consumo por lote con cantidad/volumen exactos y revertir esas asignaciones. Preparar diagnóstico de datos antes de migrar; no recalcular saldos históricos silenciosamente.

**Prueba:** comprar, consumir parcialmente, cruzar varios frascos y anular debe conciliar físico, lotes y ledger en cada paso.

### H04 · P1 · Anular ventas no revierte el valor original del inventario

**Evidencia:** `app/Services/InventarioService.php:353`, `:369`, `:165`; `app/Models/StockLedger.php:149`; `app/Services/ContabilidadService.php:715`.

La entrada de reversión se valora con el promedio vigente al anular. Contabilidad genera el espejo del costo original. Si se vende una unidad a costo 10, se compra después a otro costo y se anula cuando el promedio es 20, el kárdex devuelve 20 y el asiento devuelve 10.

**Corrección:** vincular reversión a movimientos originales y devolver cantidades y valores originales, incluyendo asignaciones de lotes. El saldo promedio posterior debe derivarse del saldo resultante. Considerar artículos archivados mediante soft delete para no omitir la devolución.

**Prueba:** venta → compra con otro costo → anulación; el valor devuelto por ledger coincide con el espejo contable.

### H05 · P1 · El carrito no valida sus invariantes esenciales

**Evidencia:** `app/Http/Controllers/VentaController.php:210`, `:260`, `:345`.

Se validan consumos y pagos, pero faltan reglas de cantidad positiva, tipo permitido, identificador correspondiente y precio para cada línea. El subtotal y el detalle usan directamente `precio_c` y `quantity` enviados por el cliente. Una cantidad negativa alcanza la resta de inventario y puede transformarla en una entrada. La pertenencia de variante sí se comprueba, pero el precio enviado no se contrasta con ella.

**Corrección:** un FormRequest para la forma del documento y validaciones de dominio para cantidades, artículos vendibles, servicio/variante y descuento compatible con subtotal. Resolver precios en servidor o documentar una política de precio manual con autorización y auditoría. Mantener las invariantes en el servicio para otros puntos de entrada.

**Prueba:** cantidad negativa/cero, precio inválido, tipo desconocido, artículo inexistente y variante ajena deben rechazarse sin efectos.

### H06 · P1 · Facturar citas duplica reglas y puede multiplicar consumos

**Evidencia:** `app/Http/Controllers/VentaController.php:34`, `:90`, `:140`; `routes/web.php:132`.

El endpoint sigue activo y crea una línea de servicio con cantidad 1 por estilista. Inventario consume la receta una vez por línea: una atención con dos estilistas consume dos recetas. Repartir el precio no reparte el consumo; con tres estilistas también puede introducir diferencias de centavos.

El flujo inmediato no pasa por `LibroPagosService`: si se elige USD, trata el total calculado del precio del servicio como dólares físicos y activa la conversión legacy contable. No bloquea la cita ni comprueba que no tenga otra factura, por lo que repetir la solicitud crea otra venta.

**Corrección:** preparar el carrito desde la cita y delegar a un único caso de uso de ventas. Modelar participación de estilistas separada de las unidades de servicio. Definir exclusión de facturación duplicada con lock y vínculo persistido. Unificar libro de pagos y moneda base.

**Pruebas:** cita con varios estilistas consume una receta; misma cita no se factura dos veces; cobrar en USD conserva el precio NIO y registra únicamente los USD realmente recibidos.

### H07 · P1 · La nómina admite repetir un período ya liquidado

**Evidencia:** `app/Services/NominaService.php:168`; `database/migrations/2026_10_13_000002_payroll_runs_colillas_corporativo.php:26` y `:42`.

Cada procesamiento crea corrida y colillas nuevas sin comprobar períodos ya pagados por empleado. Bloquear comisiones pendientes evita reutilizar esas comisiones, pero no impide pagar nuevamente salario base. Los asientos tienen nuevos IDs de colilla, de modo que su número único no evita la duplicación.

**Corrección:** definir nómina ordinaria y ajustes extraordinarios, bloquear por empleado antes de comprobar solapes y exigir idempotencia de procesamiento. Permitir reproceso después de anulación bajo una regla explícita, sin impedir ajustes legítimos mediante un índice demasiado amplio.

**Pruebas:** reenvío de solicitud, período solapado y procesamiento concurrente; solo una liquidación ordinaria activa por cobertura de empleado.

### H08 · P1 · Un concepto salarial fijo puede pagarse sin reconocer su gasto

**Evidencia:** `app/Services/NominaService.php:509`; `app/Services/ContabilidadService.php:1443`; `app/Http/Controllers/NominaController.php:190`.

El motor admite ingresos/deducciones de calculadora `fijo` con código libre y los incluye en neto. Contabilidad solo reconoce códigos cerrados como `SAL_BASE`, `HORAS_EXT`, `COM_VENT`, etc. Un bono fijo `BONO` incrementa el neto de la colilla, pero no el gasto ni el pago del asiento. Este puede quedar perfectamente balanceado con importes diferentes a la liquidación: validar cuadre no detecta la omisión.

**Corrección:** asignar tratamiento contable validado a cada concepto; rechazar conceptos sin clasificación. Comprobar conciliación entre total devengado, deducciones, custodia liberada y pago externo, además del cuadre debe/haber. No resolverlo con un ajuste automático a una cuenta genérica.

**Prueba:** bono fijo y deducción fija llegan a sus cuentas y el desembolso concilia con el neto y la custodia.

### H09 · P1 · Pagar proveedores no serializa el saldo

**Evidencia:** `app/Http/Controllers/CuentaPorPagarController.php:35`.

Se consulta CxP sin lock. Dos abonos de 80 sobre saldo 100 pueden superar individualmente la validación, registrar pagos por 160 y dejar saldo 20 por sobrescritura. La anulación de compra tampoco bloquea explícitamente la fila de CxP al comprobar pagos. El rechazo por sobrepago retorna dentro de una transacción sin cerrarla.

**Corrección:** servicio de pago con `DB::transaction`, lock de CxP y verificación de estado/saldo; coordinar con anulación bajo un orden de locks común. Lanzar excepción de dominio para rechazos, cerrando siempre la transacción.

**Prueba:** dos abonos concurrentes y carrera pago/anulación conservan obligación, pagos y mayor consistentes.

### H10 · P2 · La fotografía del BOM no es completa ni respeta consumo cero

**Evidencia:** `app/Http/Controllers/VentaController.php:527`; `app/Services/InventarioService.php:68`.

Solo se fotografía consumo cuando el cliente envía un arreglo no vacío. Sin fotografía, la reversión consulta la fórmula actual: cambiar la receta entre venta y anulación devuelve insumos distintos. Si todos los consumos fotografiados son cero, el servicio filtra esas líneas y aplica el estándar, aunque cero era una entrada válida.

**Corrección:** guardar siempre la receta efectiva al emitir, incluso cuando usa estándar. Distinguir ausencia de fotografía de fotografía con consumo cero. Para documentos históricos sin fotografía, diseñar una política explícita apoyada en movimientos reales disponibles.

**Pruebas:** cambiar BOM tras emitir no cambia la reversión; consumo explícito cero permanece cero.

### H11 · P2 · Orden de presentación altera cálculos salariales y deja cuotas atrás

**Evidencia:** `app/Services/NominaService.php:395`, `:431`, `:466`.

El motor calcula en una sola pasada por `orden`: INSS colocado antes de salario usa base cero; adelantos antes de otras deducciones usan un disponible incorrecto. El formulario permite esos órdenes. Además, las cuotas se seleccionan con `whereBetween(inicio, fin)`: una cuota parcial del período anterior queda excluida de los siguientes.

**Corrección:** separar fases de cálculo: ingresos → deducciones estructurales → cap de adelantos; dejar `orden` para presentación. Si la regla es recuperar vencidos acumulados, seleccionar pendientes hasta el fin del período, incluidos atrasos. Validar unicidad de calculadoras que no deban repetirse.

**Pruebas:** reordenar conceptos no cambia importes; cuota parcialmente amortizada continúa en la siguiente corrida.

### H12 · P1 · Recursos API activos carecen de autenticación y hay un catálogo inconsistente

**Evidencia:** `routes/api.php:11`, `bootstrap/app.php:17`; `app/Http/Controllers/CitaController.php:25`; `app/Models/Cita.php:43`.

`route:list --path=api/clients -vv` muestra únicamente `SubstituteBindings`, sin middleware de autenticación/rol. Los recursos exponen controladores reales de clientes y citas; Sanctum instalado no los protege. El alta de cita valida `service_id` contra `items`, mientras su relación apunta a `services`: acepta o rechaza IDs contra el catálogo equivocado.

**Corrección:** decidir si estos recursos se usan; retirar rutas sin implementación/consumidores o aplicar autenticación y autorización explícitas según el cliente real. Corregir el catálogo validado, estados permitidos y transacción cita/estilistas. Este hallazgo no sustituye una auditoría de seguridad completa.

**Pruebas:** acceso anónimo/rol denegado y servicio existente exclusivamente en `services`.

## Límites de dominio y extensibilidad

Mantener módulos dentro del mismo Laravel y base SQL:

| Dominio | Responsabilidad | Límite recomendado |
| --- | --- | --- |
| Ventas | Documento, precios, descuento, crédito y transición de estado | Un caso de uso compartido por POS, cita y aprobación |
| Inventario | Disponibilidad, unidades, lotes, movimientos y reversión | Devuelve movimientos/valoración exactos; no decide cobros |
| Compras/CxP | Factura, landed cost, obligación, pago y anulación | Coordina inventario, tesorería y contabilidad atómicamente |
| RRHH/Nómina | Devengado, deducciones, conceptos y liquidación | Resultado tipado con clasificación contable exhaustiva |
| Tesorería | Ubicación de fondos, pagos, divisas y movimientos | Destino explícito, sin inferir banco desde textos dispersos |
| Contabilidad | Catálogo, período, asientos, cuadre y reversiones | Recibe el resultado del negocio; no reconstruye reglas históricas |

`VentaController` combina validación, precios, estados, consumos, pagos, citas, comisión y transacciones; `ContabilidadService` reúne integraciones de casi todo el ERP. Su tamaño importa porque reglas concretas tienen varios propietarios, no por superar un número de líneas.

Extraer gradualmente casos de uso concretos (`EmitirVenta`, `CobrarVenta`, `AnularVenta`) o métodos equivalentes en un servicio cohesivo. Una sola transacción exterior debe coordinar efectos relacionados. Mantener stock, pagos y asientos síncronos; reservar eventos después del commit para notificaciones y efectos accesorios.

La repetición más costosa es funcional: facturación de cita/POS/aprobación; interpretación de volúmenes; catálogo de conceptos salariales separado de su registro contable. Corregir estas tres fronteras reduce los archivos que cambian al agregar una opción.

Después, separar construcción de asientos por dominio conservando un núcleo común de persistencia, período y cuadre. Usar inyección consistente en lugar de mezclar `new`, `app()` e inyección de constructor. No añadir interfaces si no existe una necesidad concreta de sustitución.

Eficiencia secundaria: `NominaController::wizard()` consulta comisiones, propinas y cuotas por empleado; agrupar por empleado y cargar relaciones evita crecimiento de consultas. Paginar historiales actualmente cargados con `get()`. Medir consultas y tiempos antes de introducir caché; nunca usar caché como autoridad para saldos mutables.

## Fortalezas que conviene conservar

- CompraService concentra documento y efectos en una transacción.
- LibroPagosService y ComisionService ya reducen duplicación en parte de los flujos.
- El bloqueo del cliente al validar crédito y las comisiones congeladas son buenas decisiones.
- Los asientos verifican cuadre exacto y existen reversiones identificables.
- Hay pruebas funcionales financieras de ventas, compras, bancos, nómina y dashboard. La mención de «solo pruebas de ejemplo» en documentación está desactualizada.

## Secuencia de mejora

1. Cerrar modificaciones directas de stock y acceso API sin control; añadir validación del carrito.
2. Resolver primero la representación de volumen; proteger salidas y pagos con locks y disponibilidad.
3. Unificar facturación de citas con ventas y hacer reversiones desde movimientos originales.
4. Bloquear nómina ordinaria duplicada, cubrir conceptos fijos y separar las fases de cálculo.
5. Consolidar casos de uso y mapeos contables al corregir cada flujo, conservando rutas y datos existentes cuando sea posible.
6. Optimizar consultas observadas y actualizar documentación con el comportamiento realmente verificado.

Cada entrega debe incluir un escenario que falle antes y pase después, rollback ante fallo contable y conciliación de importes entre subsistemas. Un asiento balanceado por sí solo no demuestra que el negocio esté correctamente registrado.

## Verificación realizada y límites

- Inspección estática y contraste con migraciones y pruebas existentes; no se modificó código de aplicación ni datos.
- `php --version`: PHP CLI 8.5.8.
- `php artisan route:list --path=api --except-vendor`: completado.
- `php artisan route:list --path=api/clients -vv`: completado; confirma ausencia de auth/role en esos recursos.
- `php vendor/phpunit/phpunit/phpunit --list-tests`: bloqueado porque falta `mbstring`; ni siquiera inició el listado de pruebas.
- No se ejecutó la suite financiera. Usa `RefreshDatabase`; AGENTS.md §9 exige autorización explícita y confirmación del entorno/base antes de recrear tablas. `phpunit.xml` declara `salon_erp_test`, pero esa declaración no reemplaza confirmar la configuración efectiva. El candado de `tests/TestCase.php` está después de `parent::setUp()`, por lo que debe revisarse su ejecución previa a cualquier inicialización destructiva.
- Las propuestas no incluyen validar tasas legales de nómina ni emitir un dictamen fiscal; se evaluó coherencia interna del código.

## Mejoras visuales transversales pendientes

### UI-01 · Pendiente · Desbordamiento horizontal en tablas administrativas

**Origen:** comprobación manual del propietario durante H16. No se realizó una
verificación visual adicional al registrar este hallazgo.

**Comportamiento observado:** en la agenda, cuando hay citas con distintos
estados y algunas filas presentan varias acciones, la columna Acciones puede
extenderse fuera de la tarjeta y cortar botones o texto. El propietario también
ha observado problemas similares en Aprobaciones pendientes y Asistencia.

**Planificación:** atender después de los hallazgos funcionales de la auditoría.
No corregirlo dentro de H15/H16 ni añadir cambios CSS por este hallazgo.

**Revisión futura:** analizar el patrón compartido de tablas, anchos de columnas,
contenedores, overflow y adaptación a pantallas pequeñas. Buscar una solución
reutilizable que conserve las reglas de negocio y acciones de cada módulo.

**Criterios de aceptación futuros:** botones y texto completos y accesibles en
las tres pantallas, con distintas combinaciones de estados/acciones y tamaños
de pantalla; comportamiento de tablas y navegación comprensible, sin cambios
en la lógica funcional. La causa concreta y la solución quedan por verificar.
