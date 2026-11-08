# AGENTS.md - Salon ERP

## 1. Propósito del proyecto

Salon ERP es el sistema operativo, administrativo y financiero de Álvaro Rugama Make Up Studio. No debe tratarse como un POS aislado: ventas, caja, inventario, nómina, cuentas por cobrar y pagar, bancos, divisas y contabilidad están relacionados y requieren trazabilidad de extremo a extremo.

El objetivo al modificarlo es conservar integridad financiera y de inventario, compatibilidad con los datos existentes y una operación clara para los roles del salón. Estas instrucciones aplican a cambios de código, esquemas, rutas, interfaz y documentación.

## 2. Entorno y stack verificado

Las versiones declaradas en `composer.json` y `package.json` son la referencia del stack. La presencia de Sanctum en Composer no demuestra por sí sola que una ruta API esté protegida por Sanctum.

## 3. Arquitectura y mapa del código

La aplicación usa MVC de Laravel con servicios para reglas financieras y de inventario. La separación no es completa: algunas pantallas y consultas siguen implementadas directamente como closures en rutas, por lo que antes de asumir una capa uniforme hay que seguir el flujo concreto.

- `routes/web.php`: interfaz administrativa, autenticación y grupos de acceso por rol.
- `routes/api.php`: recursos JSON de clientes, artículos y citas.
- `bootstrap/app.php`: registra rutas, endpoint de salud, alias `role` y formato JSON de errores bajo `api/*`.
- `app/Http/Controllers/`: casos de uso HTTP, validación, persistencia y coordinación de servicios.
- `app/Http/Middleware/RoleMiddleware.php`: autorización por rol; `admin` tiene bypass global.
- `app/Models/`: modelos Eloquent y relaciones del dominio.
- `app/Services/ContabilidadService.php`: asientos, integración financiera, períodos y cuadre.
- `app/Services/DivisaService.php`: saldos físicos de moneda y costo promedio ponderado.
- `app/Services/FondosService.php`: bóvedas de fondos (cajas de turno y cuentas bancarias), disponibilidad de efectivo por turno y resolución de la subcuenta contable de cada ubicación.
- `app/Services/InventarioService.php`: consumo de productos, insumos fraccionados y lotes FIFO.
- `app/Services/NominaService.php`: motor HRMS de nómina (liquidación por colillas con estructuras salariales, cap de adelantos, cierre de comisiones/propinas y anulación con reversión).
- `app/Exceptions/ContabilidadException.php`: error de dominio contable.
- `database/migrations/`: esquema e historial de cambios; revisar migraciones existentes antes de crear otra.
- `database/seeders/`: datos iniciales, incluidos roles y catálogo contable base.
- `resources/views/`: pantallas Blade agrupadas por módulo; `resources/js/app.js` y `resources/css/app.css` son los puntos de entrada frontend.
- `tests/`: pruebas PHPUnit. Actualmente el árbol contiene pruebas de ejemplo; no asumir que los flujos financieros están cubiertos.
- `public/build/`: salida generada por Vite; no editar manualmente.

Los nombres de tablas y algunos campos conservan nombres heredados en inglés (`sales`, `items`, `advances`), mientras que el dominio y la interfaz mezclan español e inglés. Inspeccionar los modelos y migraciones, no deducir el nombre físico de una tabla por el nombre de una clase.

## 4. Módulos y principales puntos de entrada

| Área | Puntos de entrada principales | Responsabilidad |
| --- | --- | --- |
| Panel, agenda y citas | `PanelController`, `DashboardService`, ruta `/agenda`, `CitaController`, `Cita`, `Cliente`, `Servicio` | Dashboard Gerencial BI leído del Libro Mayor (§5.10) con endpoints SPA en `/panel/api/*`, agenda diaria y citas. La agenda web actualmente consulta modelos desde una closure; el API usa `CitaController`. |
| Clientes (Fase 1 Admin) | `ClienteController`, `Cliente`, `ClienteGrupo` (`customer_groups`) | Directorio comercial con Perfil 360 (`/clientes/{id}`: métricas, citas, facturas y CxC), grupos de fidelización con descuento automático en POS (`discount_percent`), límite de crédito (`credit_limit`), días de crédito (`credit_days`) y bloqueo de facturación a crédito por límite excedido o facturas vencidas (ver §5.8). |
| POS y ventas | `VentaController`, `Venta`, `DetalleVenta`, `Pago` | Venta de servicios/productos, libro de pagos (pagos mixtos y vuelto multimoneda en `pagos`), ticket, facturación de cita vía redirección al POS, inventario, comisiones y asiento contable. Ciclo de vida del documento (`status`): `borrador` (cotización sin efectos), `emitida` (crédito/CxC, cobro posterior en `/ventas/{id}/cobrar`), `pagada`, `anulada` (`/ventas/{id}/anular`, solo admin). |
| Caja y caja chica | `CajaController`, `SesionCaja`, `CajaChicaController`, `CajaChica`, `MovimientoCajaChica` | Apertura/cierre, arqueo (el efectivo teórico se calcula desde el libro de pagos, vía `Venta::efectivoNioNeto()`), movimientos de efectivo y gastos menores. |
| Inventario y compras (SCM corporativa) | `InventarioController`, `FacturaCompraController`, `CompraService`, `Articulo`, `Lote`, `StockLedger` (`stock_ledger_entries`), `FacturaCompra` (`purchase_invoices`) + líneas, `InventarioService` | Kárdex INMUTABLE con saldo corrido valorado a costo promedio móvil (toda entrada/salida es un registro auditable; el inventario no se edita), lotes FIFO con caducidad y enlace a su línea de compra, formulario de Ingreso de Mercadería con IVA de compra y landed cost, apertura de kárdex para stock heredado y alertas de stock. El COGS del POS lo dicta el ledger (ver §5.9). |
| Recetas y mermas (Fase 1 Admin) | `FormulaController`, `FormulaServicio`, `ConsumoVenta` (`sale_detail_consumptions`), `MermaController`, `Merma` | BOM dinámico: la fórmula es el consumo ESTÁNDAR para márgenes (`Servicio::costoInsumos()` valorado a costo de lotes) y el consumo REAL se fotografía por línea de venta en el POS; `InventarioService::consumosDeDetalle()` resuelve real→estándar para descontar y revertir (ver §5.8). Bajas por merma. |
| Catálogo de servicios (Fase 1 Admin) | `ServicioController`, `Servicio`, `ServicioVariante` (`service_variants`), `ServicioCategoria` (`service_categories`), `PlantillaImpuesto` (`tax_templates`) | Servicios con inteligencia de precios: categoría para reportes, variantes de precio (Corto/Medio/Largo) con trazabilidad en `sale_details.service_variant_id`, `cleanup_minutes` (buffer de agenda separado de la ejecución) y plantilla fiscal enlazada (metadato; el IVA aún no entra al asiento). |
| Empleados, comisiones y nómina | `RrhhController`, `EmpleadoController`, `AusenciaController`, `NominaController`, `NominaService`, `Empleado`, `Turno`/`AsignacionTurno`, `TipoAusencia`/`Ausencia`, `Propina`, `EstructuraSalarial`, `CorridaNomina`/`ColillaNomina`/`DetalleColilla`, `Usuario`, `EsquemaComision`, `EsquemaRango`, `ComisionGenerada`, `Nomina` (legacy), `AsistenciaController` | Dashboard RRHH y Perfil 360 (`/rrhh`), expediente de colaboradores, turnos y asignaciones, permisos/aprobaciones (`/ausencias`), propinas en custodia, wizard de nómina con previsualización, colillas imprimibles, anulación con reversión y estructuras salariales. La asistencia (general) alimenta tardanzas y horas extra. |
| Adelantos / CxC | `AdelantoController`, `Adelanto`, `AdelantoCuota` | Saldos de empleados/clientes, cuotas y reversión de movimientos. |
| Proveedores / CxP (SCM corporativa) | `ProveedorController`, `CuentaPorPagarController`, `Proveedor` (Perfil 360 `/proveedores/{id}`), `CuentaPorPagar`, `PagoProveedor`, `FacturaCompra` | Directorio con saldo AP en tiempo real (SIEMPRE derivado de las CxP abiertas, nunca un campo guardado), monto vencido, facturas de compra, pagos emitidos, mermas de sus artículos y catálogo suministrado; borrado bloqueado con historia financiera (§5.3). Las CxP del flujo antiguo siguen visibles como histórico. |
| Bancos | `BancoController`, `CuentaBancaria`, `MovimientoBancario`, `Transferencia`, `ConciliacionBancaria`, `FondosService` | Dashboard multimoneda (NIO/USD), libro mayor con saldo corriendo, depósitos, transferencias entre bóvedas con comisión (gasto 6.9), cargos/abonos bancarios con asiento (`CBA-*`) y conciliación contra extracto (sólo cierra con diferencia 0). Cada cuenta bancaria apunta a su subcuenta del mayor vía `cuenta_contable_id`. |
| Retiros | `RetiroController`, `RetiroPropietario` | Retiros patrimoniales (movimiento contra patrimonio `3.3`, nunca gasto operativo). |
| Mesa de cambio | `MesaCambioController`, `Moneda`, `SaldoMoneda`, `OperacionCambio`, `DiferencialCambiario`, `TipoCambio`, `DivisaService`, `FondosService` | Operaciones NIO/USD con trazabilidad de bóvedas (origen que entrega vs. destino que recibe), costo promedio ponderado, tasa oficial BCN (`tipos_cambio`) vs. tasa de calle y diferencial contable al instante (`4.6` ganancia / `7.5` pérdida). |
| Contabilidad | `ContabilidadController`, `ContabilidadService`, `CuentaContableController`, `AsientoManualController`, `PeriodoContableController` | Libro diario/mayor, estados, catálogo, centros de costo, reversión y cierre fiscal. El Estado de Resultados filtra por mes (`?mes=YYYY-MM`, default el mes en curso; `?mes=todo` para el histórico completo) leyendo la fecha del asiento y excluyendo siempre los asientos `cierre_fiscal`. |
| Reportes y respaldos | `ReporteController`, `RespaldoController` | Reportes/exportaciones y respaldo/restauración SQL. Verificar impacto antes de restaurar datos. |
| Portal de colaborador | `PortalColaboradorController`, `PortalColaboradorConfig`, `PortalColaboradorToken` | Consulta de comisiones, nóminas, cuotas y perfil con token de sesión propio. Consultar también la limitación de rutas en §6. |

## 5. Reglas financieras y de inventario obligatorias

### 5.1 Partida doble y atomicidad

- Toda operación que cambie una posición financiera debe persistir sus movimientos y el asiento contable correspondiente dentro de una operación atómica: ventas, compras, gastos, nómina, adelantos/CxC, pagos a proveedores, caja, bancos, retiros y cambio de divisas cuando aplique.
- Nunca confirmar un asiento desequilibrado. `ContabilidadService::validarCuadre()` suma debe/haber y compara centavos enteros, sin tolerancia. Todo método nuevo que cree asientos debe ejecutar esta validación antes del commit.
- Si falla cualquier paso del caso de uso, se revierten también los cambios de negocio asociados; no dejar venta, pago, stock o saldo parcial.
- Algunas acciones actuales abren transacciones en el controlador y otras también dentro del servicio. Al tocar ese flujo, identifica quién es dueño de la transacción y conserva la atomicidad sin agregar commits prematuros.
- Importes contables se expresan en moneda base y se redondean a dos decimales. Tasas/costos de conversión usan seis decimales cuando la columna así lo define. Evitar cálculos monetarios nuevos con precisión binaria sin normalización explícita.

### 5.2 Catálogo contable y períodos

- Resolver cuentas contra el catálogo; no inventar ni sustituir códigos contables en controladores.
- El servicio usa, entre otras, caja `1.1.1`, banco Lafise `1.1.2.1`, banco BAC `1.1.2.2` y retiros del propietario `3.3`. Confirmar el catálogo seed y las reglas del método concreto antes de añadir mapeos.
- Los retiros del propietario son movimientos contra patrimonio, no gastos operativos.
- `ContabilidadService` resuelve el período de la fecha del movimiento: bloquea períodos cerrados y genera un período mensual abierto si no existe. Una operación fechada en período cerrado no debe saltarse ese bloqueo.
- Conservar referencia de origen, número/concepto de asiento, usuario, fecha y período para auditoría.

### 5.3 Reversión y borrado

- Nunca hacer borrado físico de movimientos financieros, asientos, pagos, CxC/CxP u otros registros que soporten auditoría. Corregir con movimiento y asiento de reversión vinculados o identificables; no editar silenciosamente el historial.
- Las facturas NO se borran ni siquiera por error de caja: `VentaController::anular()` (solo `role:admin`, motivo obligatorio) cambia el estado a `anulada`, revierte el inventario (`InventarioService::revertirPorVenta()`), devuelve las divisas físicas cobradas, anula las comisiones pendientes y genera asientos espejo `REV-*` (`ContabilidadService::reversarVenta()`). Bloquea si alguna comisión ya fue pagada en nómina.
- El flujo de eliminación de adelantos ya genera un nuevo movimiento de naturaleza opuesta y lo contabiliza. Mantener ese patrón para CxC.
- Para catálogos, respetar el comportamiento del modelo y sus relaciones. Hay migraciones de `SoftDeletes` para artículos y fórmulas; no generalizar que todas las entidades los tienen.
- Revisar el controlador, modelo y constraints antes de usar `delete()`, `forceDelete()` o cascadas. Que exista una ruta HTTP `DELETE` no autoriza borrado físico financiero.

### 5.4 Multimoneda y mesa de cambio

- Monedas operativas principales: córdobas NIO (moneda base contable) y dólares USD.
- `DivisaService::ingresarDivisas()` recalcula costo promedio ponderado y redondea a seis decimales; `egresarDivisas()` valida saldo y calcula diferencial con la tasa de venta y el costo promedio. Ambos bloquean la fila de saldo con `lockForUpdate()` y deben ejecutarse dentro de una transacción.
- `saldos_moneda.costo_promedio_ponderado` está definido como `decimal(15,6)`; los saldos monetarios son `decimal(15,2)`. No degradar esa precisión.
- Mantener saldo segregado por moneda y ubicación (polimórfica); no mezclar efectivo físico, caja y banco.
- BÓVEDAS DE FONDOS (octubre 2026): toda operación entre posiciones de efectivo declara bóveda de origen (moneda que entrega) y bóveda de destino (moneda que recibe) a través de `FondosService` (`App\Models\SesionCaja` y `App\Models\CuentaBancaria`). El efectivo NIO disponible de un turno se calcula como apertura + cobros netos − salidas ya registradas (depósitos, transferencias y compras de divisas); no fiarse del saldo teórico crudo del arqueo.
- ASIENTO DE CAMBIO CORREGIDO (octubre 2026): en la venta de USD, la salida de divisas se valora a COSTO PROMEDIO (valor libro) y el diferencial va a la cuenta contraria del mismo asiento: `4.6` Ganancia (haber) o `7.5` Pérdida (debe). La versión anterior cargaba el haber de USD a la tasa de venta y sumaba el diferencial como línea extra, descuadrando el asiento exactamente por el diferencial. No revertir a ese patrón.
- Pagos mixtos (tabla `pagos`): los importes (`subtotal`, `discount`, `total`, `valor_nio`) están SIEMPRE en moneda base NIO. `ContabilidadService::contabilizarVenta()` no debe re-normalizarlos con la tasa de un pago en USD cuando existen líneas de pago; la conversión ya ocurrió por línea en `valor_nio` al registrar el pago.
- El vuelto al cliente es una línea `tipo = 'vuelto'` en `pagos`: NIO acredita caja `1.1.1`; USD acredita caja USD `1.1.1.2` a su valor operativo (`valor_nio`) y descuenta dólares físicos vía `DivisaService::egresarDivisas()` (rechaza con rollback si no hay saldo). El reconocimiento del diferencial cambiario por valor libro sigue siendo dominio de la mesa de cambio.
- Antes de cambiar el tratamiento de una venta cobrada en USD, seguir conjuntamente `VentaController`, `DivisaService` y `ContabilidadService`.

### 5.5 Inventario fraccionado y lotes

- Los servicios consumen insumos definidos en fórmulas; productos físicos consumen unidades. Los artículos fraccionables mantienen volumen abierto y unidades disponibles.
- `InventarioService` descuenta lotes FIFO por unidades/volumen y usa locks `lockForUpdate()` en sus consultas de lote. Los cambios que lo llamen deben ejecutarse dentro de una transacción.
- Mantener sincronizados artículo, lotes, kardex/movimiento correspondiente y contabilidad de costo cuando aplique. No actualizar únicamente el contador agregado.
- No permitir que una venta/merma deje inventario negativo por una ruta nueva; validar el comportamiento y las reglas existentes antes de ajustar cantidades.

### 5.6 Comisiones, RRHH y motor de nómina corporativo (HRMS, octubre 2026)

- Las comisiones históricas se calculan y guardan como `ComisionGenerada` durante el flujo de venta; existen `EsquemaComision` y rangos (`EsquemaRango`) como configuración histórica.
- La nómina debe consumir los montos congelados de comisión, no recalcular ventas históricas usando el porcentaje actual del empleado.
- Cambios en venta, esquema o nómina deben considerar el estado de pago de la comisión, adelantos/cuotas y deducciones (incluidos INSS e impuesto sobre la renta).
- SEPARACIÓN USER / EMPLOYEE: `users` es acceso al sistema; `employees` (`Empleado`) es el expediente RRHH (contrato, fecha de ingreso, salario base, turno, documentos, datos de depósito). `EmpleadoController` sincroniza ambas piezas; la migración HRMS hizo backfill de un `employee` por usuario. No volver a un CRUD plano sobre `users`.
- MOTOR DE NÓMINA (`NominaService`): el flujo corporativo es wizard `previsualizar` (lectura) → `procesar` (atómico: colillas + cierre de comisiones/propinas + amortización de cuotas + asientos `NOM-*` por colilla). La liquidación la define la estructura salarial (`salary_structures` + `salary_structure_details`) con calculadoras dinámicas (`salario_base`, `comisiones`, `propinas`, `horas_extras`, `inss`, `ir`, `sin_goce`, `tardanzas`, `adelantos`, `fijo`); tasas de INSS/IR viven en `config('salon.nomina')`. Las planillas legacy (`payrolls`, estado `pagada`) son solo lectura: no contabilizar ni modificar nuevas sobre ellas; `Nomina::$fillable` ya incluye las columnas de retenciones que antes se perdían en silencio.
- CAP DE ADELANTOS: la amortización de `AdelantoCuota` (pendiente/parcial, vencidas en el período) se limita al disponible (ingresos − otras deducciones) y reparte cuota por cuota dejando el resto `parcial`; una colilla NUNCA queda con neto negativo (falla con excepción si las deducciones estructurales exceden los ingresos). Las ausencias aprobadas sin goce (`leave_applications`) se prorratean contra el salario base.
- PROPINAS EN CUSTODIA: registrar propinas en `/rrhh/propinas` genera asiento `PRO-*` (DEBE `1.1.1.3` Propinas en Custodia / HABER `2.1.6` Propinas por Pagar). La colilla extingue el pasivo `2.1.6` (DEBE) y libera la custodia `1.1.1.3` (HABER); si las deducciones laborales superan el devengado, el déficit se absorbe del dinero custodiado con un DEBE a `1.1.1` dentro del mismo asiento. No pagar propinas por fuera de este circuito ni revertir la cuenta de custodia al modelo anterior.
- ASIENTO DE COLILLA `NOM-*` (por `salary_slips.id`): DEBE `6.7` Gasto de Sueldos (base + extras − sin goce − tardanzas), DEBE `5.3` Gasto por Comisiones, DEBE `2.1.6` Propinas por Pagar; HABER `1.1.6` Anticipos (disminuye activo/CxC), HABER `2.1.5` Retenciones INSS/IR, HABER `1.1.1.3` Propinas en Custodia, HABER `1.1.1`/`1.1.2.x` el neto en efectivo o banco. Cualquier cambio en este asiento debe mantener el cuadre exacto demostrado en `tests/Feature/NominaCorridaTest.php`.
- REVERSIÓN DE NÓMINA: la anulación de una corrida (`NominaService::anular`, motivo obligatorio) genera asientos espejo `REV-NOM-*`, marca colillas/corrida `anulada` y REABRE comisiones (`pendiente`, limpia `salary_slip_id`/`periodo_nomina_id`), propinas (vuelven a custodia pendiente) y cuotas de adelantos (revierte `monto_pagado`). Jamás borrar colillas ni editar corridas contabilizadas.
- ASISTENCIA: `attendances` ahora lleva turno (`shift_id`), `time_out`, `minutos_tardanza` (fuera de la tolerancia del turno vigente vía `AsistenciaController`), `horas_trabajadas` y `horas_extras` (excedente sobre `config('salon.nomina.jornada_horas_diarias')`). El resumen alimenta tardanzas/horas extra de la colilla.

### 5.7 Bancos corporativos: cargos, transferencias y conciliación (octubre 2026)

- Cuentas bancarias MULTIMONEDA: `cuentas_bancarias` admite una cuenta NIO y una USD por banco; cada cuenta apunta a su subcuenta del mayor vía `cuenta_contable_id` (BAC NIO `1.1.2.2`, LAFISE NIO `1.1.2.1`, BAC USD `1.1.2.3`, LAFISE USD `1.1.2.4`). Al crear cuentas nuevas, resolver la subcuenta contra el catálogo; si no existe, añadirla al seeder complementario, no hardcodearla en controladores.
- `MovimientoBancario` lleva `moneda_id`, `referencia_banco`, `asiento_contable_id` y estado de conciliación (`pendiente`, `en_transito`, `conciliado` con `fecha_conciliacion` y `conciliacion_id`). El monto vive en la moneda de la cuenta; el asiento siempre en NIO (las transferencias USD exigen `tasaValoracionUsd`, normalmente el costo promedio de la caja).
- COMISIÓN BANCARIA: toda comisión de transferencia va al gasto `6.9` "Comisiones y Cargos Bancarios" contra la cuenta bancaria que la cobra, dentro del mismo asiento `DEP-*` de la transferencia, y genera además un movimiento tipo `cargo`.
- Cargos/abonos bancarios directos (`/bancos/cargo`, `/bancos/abono`) crean movimiento + asiento `CBA-*`: cargo → DEBE cuenta de gasto/costo (default `6.9`); abono → HABER cuenta de ingreso (default `4.7` Intereses y Rendimientos). Validar el tipo de la cuenta contrapartida en el controlador, no confiar sólo en el formulario.
- CONCILIACIÓN BANCARIA: `ConciliacionBancaria` fotografía el arqueo contra el extracto. El teórico del extracto = saldo inicial conciliado (último extracto cerrado) + movimientos marcados. La conciliación SOLO se guarda cerrada con diferencia C$ 0.00: las diferencias se corrigen registrando el cargo/abono faltante, y lo pendiente con fecha vencida pasa a `en_transito` (cheques no presentados). Nunca auto-contabilizar la diferencia de una conciliación.
- No borrar movimientos bancarios: se anulan o corrigen con movimiento y asiento de reversión, igual que el resto del libro.

### 5.8 Administración corporativa: clientes AR, servicios con precios y BOM dinámico (octubre 2026)

- LÍMITE DE CRÉDITO (CxC): una venta con `action=credito` (estado `emitida`) se bloquea con `ContabilidadException` si el cliente excede `credit_limit` (NULL = sin límite) o tiene facturas emitidas VENCIDAS (vencimiento = `created_at` + `credit_days`). La regla vive en `Cliente::motivoBloqueoCredito()` (dominio, no en la vista) y se evalúa en `VentaController` con `lockForUpdate()` DENTRO de la transacción para evitar carreras entre cajas. No saltarse esta guardia en flujos nuevos de crédito.
- DESCUENTO AUTOMÁTICO POR GRUPO: si el POS/API omite el campo `discount`, `VentaController` aplica el `discount_percent` del `customer_group` del cliente (reparto contable existente: DEBE `4.5` contra-ingreso). Un `discount` explícito (aunque sea 0) siempre prevalece sobre el grupo.
- AR DEL CLIENTE: el saldo pendiente es la suma de ventas `emitida` (`Cliente::saldoPendiente()`); las facturas vencidas se derivan de `credit_days`. No borrar clientes con facturas o citas: el controlador lo bloquea y el borrado físico de registros con historia financiera está prohibido por §5.3.
- VARIANTES DE PRECIO: `service_variants` define precios por tamaño/longitud (con duración opcional). El POS las envía en el carrito como `variant_id` y `VentaController` valida que la variante pertenezca al servicio facturado (rechazo con rollback total si no). `sale_details.service_variant_id` es FK `nullOnDelete`: eliminar una variante del catálogo NO rompe facturas históricas. El precio facturado vive siempre en `unit_price`; la variante es trazabilidad.
- CONSUMO REAL vs ESTÁNDAR (BOM): `service_formulas` es la receta ESTÁNDAR para márgenes; el consumo REAL se captura en el POS (editor por línea del carrito) y se persiste en `sale_detail_consumptions` (`quantity_standard` vs `quantity_actual`). `InventarioService::consumosDeDetalle()` usa los consumos guardados como ÚNICA fuente de verdad cuando existen, y la fórmula estándar como fallback; tanto el descuento como la REVERSIÓN de inventario pasan por ese método, por lo que la reversión es exacta aunque la fórmula del catálogo cambie a posteriori. Solo se aceptan insumos que pertenecen a la fórmula del servicio.
- COSTO DINÁMICO DE INSUMOS: `Articulo::mapaCostosActuales()` calcula el costo de compra actual como promedio ponderado de lotes ABIERTOS (`lotes.costo_unitario`), con fallback al último lote histórico; para fraccionables se divide por `total_volume` (costo por ml/oz). `Servicio::costoInsumos()`/`margenBruto()` valoran la receta estándar con ese costo. No sustituir el costo de lote por el precio de venta (`precio_c`): sobrestaría el costo.
- PLANTILLAS DE IMPUESTOS: `tax_templates` (Exento / IVA 15%) se enlaza al servicio como metadato fiscal; al asiento de venta aún NO le suma IVA. No contabilizar IVA desde esta fase sin rediseñar `ContabilidadService` (ingresos y catálogo `2.x`).
- CORRECCIÓN DE CUADRE (octubre 2026): en `ContabilidadService::desgloseIngresoVenta()` el HABER de ingresos (4.1/4.4) es el BRUTO. El descuento ya se registra como DEBE contra-ingreso `4.5`; si el HABER fuera neto, el descuento se restaba dos veces y el asiento descuadraba exactamente por el descuento (bug latente: ninguna prueba ejercitaba `discount > 0`). No regresar a `servicios_neto = bruto - descuento` en el HABER.

### 5.9 Cadena de Suministro y AP corporativas: kárdex inmutable, compras y COGS (octubre 2026)

- KÁRDEX INMUTABLE (`stock_ledger_entries`, modelo `StockLedger`): el inventario NO se edita. Todo cambio de stock físico genera un registro de entrada (compra/apertura/ajuste), salida (venta/merma/anulación de compra) o reversión con fecha, origen, referencia al documento, costo de valoración, valor con signo y SALDO CORRIDO (unidades, volumen para fraccionables y valor en C$). `StockLedger::registrar()` bloquea la última fila del artículo (`lockForUpdate()`) para serializar el saldo: debe ejecutarse DENTRO de la transacción del caso de uso. Medida primaria de valoración: VOLUMEN (ml) para fraccionables, UNIDAD para el resto. Los movimientos antiguos al ledger se documentan con el botón "Registrar saldo de apertura" (`POST /inventario/kardex/apertura`), que crea un registro real de apertura solo si el artículo no tiene movimientos; nunca reconstruir historia ficticia.
- COGS DICTADO POR EL LEDGER: en `ContabilidadService::desgloseIngresoVenta()` el costo de insumos (5.2) y de mercadería (5.1) se toma de las entradas `origen = venta` del `stock_ledger_entries` de esa venta (costo promedio del saldo PREVIO a la salida, es decir el costo real al momento de vender). Fallback al mapa `Articulo::mapaCostosActuales()` solo si la venta no tiene kárdex. NUNCA regresar a valorar el COGS con `precio_c` (era precio de venta, no costo) ni recalcular con el promedio de lotes POST-consumo (descuadra kárdex y asiento: el ledger registró el valor con el saldo previo).
- CICLO DE COMPRA (`CompraService::registrarFactura`, dueño de la transacción): factura de compra (`purchase_invoices` + `purchase_invoice_lines`) → inventario físico y costo promedio móvil → lotes FIFO con caducidad enlazados a su línea (`lotes.purchase_invoice_line_id`) → kárdex → CxP si es a crédito (vencimiento = fecha + `providers.credit_days`) → asiento `COM-*`. DESCUENTO Y GASTOS DE ENVÍO se reparten proporcionalmente entre las líneas (factor landed) y se capitalizan al costo unitario final: el valor del kárdex/lotes SIEMPRE cuadra con el DEBE de inventario. Corrección de redondeo del landed se aplica a la línea de mayor valor.
- ASIENTO DE COMPRA `COM-*`: DEBE inventario (`1.1.4` reventa no fraccionable / `1.1.5` insumos y fraccionables, mismo criterio que las salidas contables) + DEBE `1.1.7` IVA Crédito Fiscal (si la compra trae impuesto); HABER `2.1.1` Proveedores (crédito) o `1.1.1`/`1.1.2.1` (contado). El método anterior `contabilizarCompraInventario` (ref time(), sin CxP desglosada) fue ELIMINADO; no revivir rutas de compra que omitan factura, kárdex o CxP.
- ANULACIÓN DE COMPRA (`CompraService::anularFactura`, §5.3 reversión sin borrado): bloqueada si la CxP tiene abonos o si los lotes de la compra ya tuvieron consumos o el stock resultante sería negativo; si procede, revierte inventario/lotes (agotados, conservados como auditoría), registra salida en kárdex valorada al costo landed, marca la CxP `anulada` (enum ampliado) y genera asiento espejo `REINV-COM-*`. La factura nunca se borra.
- MERMA AL COSTO REAL: el descuento físico, lotes FIFO, kárdex y valor de la merma los resuelve `InventarioService::registrarMerma()` (MermaController ya no calcula valores ni toca stock por su cuenta). El valor va al gasto `6.8` al costo de valoración, NUNCA al precio de venta. Nota: la cuenta `6.8` solo existía si el catálogo estaba sembrado al migrar; `CuentasComplementariasSeeder` la asegura de forma idempotente.
- INTEGRIDAD DE INVENTARIO: los insumos NO fraccionables dentro de una fórmula (BOM) se consumen por UNIDADES (el bucle de volumen anterior drenaba toda la existencia); la reversión es el espejo exacto del descuento (volumen ↔ ml, unidades ↔ unidades). `CompraService` rechaza comprar artículos `type = servicio`.
- GUARDIA DE BORRADO DE PROVEEDORES (§5.3): no se puede eliminar un proveedor con CxP abiertas, historial de CxP, facturas de compra o artículos vinculados.
- PAGOS DE COMPRA (contado): la salida de caja/banco se registra contablemente en el asiento de compra, pero el flujo NO valida fondos disponibles de la bóveda (igual que el flujo legacy que reemplazó); si se exige control de disponibilidad, integrarlo vía `FondosService` explícitamente.

### 5.10 Dashboard Gerencial BI (Centro de Mando) y metas dinámicas (octubre 2026)

- ÚNICA FUENTE DE LA VERDAD: el financiero del dashboard se lee EXCLUSIVAMENTE del Libro Mayor vía `DashboardService` (`detalle_asientos`), con el MISMO criterio que `ContabilidadController::resultados`: se excluyen SIEMPRE los asientos `modulo_origen = 'cierre_fiscal'`, solo participan cuentas `permite_movimiento = true` de las clases 4-7, y la convención de signo es clase 4 = HABER - DEBE y clases 5/6/7 = DEBE - HABER. Así el panel coincide al 100% con el Estado de Resultados: ventas anuladas restan por su espejo `REV-*`, mermas (6.8) y diferenciales (7.5) pesan en gastos, y las compras de inventario (activo 1.1.x) JAMÁS se confunden con COGS. No regresar a sumas de `sales` menos egresos de caja chica (ignoraba reversiones, mermas y depreciaciones).
- COBROS POR MÉTODO DESDE EL LEDGER: el donut "Cobros por Método" agrupa el DEBE neto (`debe - haber`) de las cuentas de fondos `1.1.1`/`1.1.1.2` (Efectivo), `1.1.2.1` (LAFISE) y `1.1.2.2` (BAC) dentro de asientos `modulo_origen = 'ventas'`. Las ventas a crédito (CxC) no cuentan hasta su cobro (`COB-*`); es coherente con `Venta::efectivoNioNeto()` del arqueo. Solo el ranking de servicios (unidades/monto por línea, `sale_details`) y los recuentos operativos (agenda, stock crítico) quedan fuera del ledger, porque el mayor no los desagrega.
- ENDPOINTS SPA (`/panel/api/*`, en `routes/web.php` dentro de `auth`, NO en `api.php` a propósito): `GET /panel/api/datos?rango=hoy|semana|mes|anio` (nombre `panel.datos`: payload completo de KPIs, serie, métodos, ranking y meta), `GET /panel/api/movimientos/{id}` (nombre `panel.movimiento`: Quick View JSON del ticket con items, libro de pagos, anulación y asientos `ING-*`/`COG-*`/`REV-*` vinculados) y `POST /panel/api/meta` (`panel.meta`, solo `role:admin`). Viven en web.php porque la sesión web + CSRF protege los datos y el frontend no usa tokens Sanctum. `bootstrap/app.php` amplió `shouldRenderJsonWhen` a `panel/api/*`: sin sesión el SPA recibe 401 JSON (nunca un redirect HTML que `fetch()` seguiría en silencio).
- INTERACCIÓN ASÍNCRONA: los filtros Hoy/Semana/Mes/Año son botones Alpine (`x-data="dashboard(@js($estado), @js([...flags]))"`) que hacen `fetch()` y mutan el estado sin recargar; los gráficos Chart.js (dependencia npm `chart.js`, importado en `resources/js/dashboard.js`, SIN CDN) se crean una vez y solo mutan `data` + `update()`. REGLA DE INTEGRACIÓN ALPINE+CHART.JS: las instancias de Chart viven en variables DE MÓDULO (`let chartVentas/chartPagos`), jamás en el estado reactivo del componente — el Proxy de Alpine intercepta los objetos internos de Chart.js y `update()` explota con "Maximum call stack size exceeded" o "Cannot set properties of undefined (setting 'fullSize')" al cambiar de rango. `renderizarGraficos()` destruye y recrea la instancia si el canvas quedó desconectado del DOM. El filtro actualiza la URL con `history.replaceState` (deep-link `?rango=`) y `resolverRango` acepta `año` por compatibilidad y lo normaliza a `anio`. Serie temporal: hoy → últimos 7 días; semana → lunes-domingo; mes → días del mes; año → 12 meses (periodos futuros en cero). Hidratación con `@js()` (nunca `@json` en atributos) y fallbacks SSR dentro de cada `x-text` para evitar destello vacío antes de hidratar. Tras tocar `resources/js/*` correr `npm run build` y recargar con Ctrl+F5 (el bundle cambia de hash).
- DRILL-DOWN: todo KPI financiero enlaza al Estado de Resultados del mes (`/contabilidad/resultados?mes=YYYY-MM`), Inventario crítico enlaza a `/inventario?stock=critico` (nuevo filtro `whereColumn('existencia_actual','<=','stock_min')` en `InventarioController::index`) y Agenda a `/agenda`. Los enlaces solo se renderizan si el rol tiene acceso (flags `puedeFinanzas`/`puedeCaja`/`puedeInventario`/`puedeEditarMeta` calculados en `PanelController::index`; el admin con bypass nunca ve 403 desde el panel). Clic en una fila de "Últimos Movimientos" abre el modal Quick View con detalle del ticket, pagos (incl. vueltos), motivo de anulación y enlaces a los asientos contables (`/asientos/{id}`, solo finanzas).
- META MENSUAL DINÁMICA: tabla `company_targets` (migración `2026_10_15_000001_create_company_targets_table`, reversible; `periodo` = primer día del mes, UNIQUE) sustituye el valor fijo de `config('salon.meta_mensual')`. La resolución vive en `CompanyTarget::montoParaMes()` (regla de dominio): meta exacta del mes → última meta anterior heredada → fallback `config` para instalaciones nuevas. El admin la edita inline en el widget (lapiz → `POST /panel/api/meta`), y el AVANCE siempre es ingreso clase 4 del mes según el mayor (no sumas de `sales`), independiente del rango activo.
- RESILIENCIA Y DIAGNÓSTICO DEL PANEL (octubre 2026): `DashboardService::metaMes()` atrapa `QueryException` y cae al fallback de `config` — el dashboard NUNCA debe devolver 500 por una migración pendiente de `company_targets` (lección del 25/10/2026: la vista 500-eaba en producción local porque la migración existía solo en `salon_erp_test`; el SPA lo mostraba como banner rojo). Al desplegar la rama `feature/panel` en una base nueva o actualizada, correr `php artisan migrate` ANTES de usar el panel. El banner de error del SPA ahora autodiagnostica: 401 → "Tu sesión expiró. Recarga la página…"; otros estados → "No se pudo actualizar el panel (HTTP xxx)"; y deja `console.error('[Dashboard BI] …')` con el detalle para el diagnóstico en consola.
- Optimizado contra N+1: ranking en UNA consulta agrupada con join (`sale_details` + `services` + `sales` filtradas por `ESTADOS_DEVENGADOS` y rango), Últimos Movimientos con eager loading de columnas mínimas (`cajero:id,name,role`, `cliente:id,name`) + `withCount` de pagos, y KPIs/serie/métodos en consultas únicas con `CASE` por clase contable.
- Cobertura: `tests/Feature/DashboardGerencialTest.php` (9 pruebas: KPIs del mayor excluyendo compras/cierre fiscal, venta anulada = 0 vía espejo, serie diaria semanal y mensual anual, cobros por método desde cuentas de fondos, meta dinámica con guardia de rol y fallback config, Quick View con ticket/pagos/asientos, filtro de inventario crítico y 401 sin sesión).

## 6. Autenticación, autorización y rutas actuales

- Las rutas web administrativas están dentro de `auth`, salvo el bloque inicial de login/logout. Las rutas base incluyen panel, agenda, asistencia y clientes.
- **REESTRUCTURACIÓN RBAC (octubre 2026)**: Se crea el rol supremo **"Álvaro" (Propietario)** con bypass global a cualquier ruta, incluida toda la contabilidad. El rol **"Administrador" (operativo)** se enfoca en gestión operativa (inventario, RRHH, caja, proveedores, servicios, respaldos) y **SE LE PROHÍBE el acceso a submódulos de Contabilidad** (Libro Diario, Libro Mayor, Estado de Resultados, Cierres Fiscales, Catálogo, Asientos Manuales, Periodos). El rol **"Contador" (Auditoría Financiera)** tiene acceso completo al bloque financiero. El middleware `RoleMiddleware` (firma variádica) implementa: `alvaro` = bypass global; `contador` + `alvaro` = contabilidad; `admin` + `contador` = bancos, CxP, retiros, reportes; `recepcion` + `contador` + `admin` = POS, caja, adelantos, mesa de cambio.
- CORRECCIÓN CRÍTICA (octubre 2026): `RoleMiddleware` ahora tiene firma variádica `handle(..., string ...$roles)`. Laravel entrega `role:admin,contador` como argumentos separados, y la firma antigua `string $roles` sólo leía el PRIMER rol: el contador era bloqueado en todas las rutas multi-rol (incluida `role:recepcion,contador`) aunque estuviera listado. No regresar a la firma de un solo parámetro.
- `role:alvaro,contador` agrupa el bloque contable exclusivo: contabilidad (diario, gasto, mayor, resultados, balance), catálogo de cuentas, asientos manuales, periodos y cierre fiscal. `role:admin,contador` agrupa operación financiera diaria: bancos, cuentas por pagar, retiros, reportes. `role:recepcion,contador,admin` agrupa POS/ventas, adelantos, arqueo, caja chica y mesa de cambio. `role:admin` conserva exclusivamente RRHH, nómina, inventario, fórmulas/servicios, proveedores y respaldos.
- El menú del layout (`layouts/app.blade.php`) filtra cada elemento con `roles => [...]` según el rol del usuario (el rol `alvaro` ve todo por bypass); los enlaces que un rol no puede abrir ya no se le muestran. Si mueves una ruta de grupo, actualizar también su entrada en el menú.
- `RoleMiddleware` permite siempre al usuario cuyo campo `role` sea `alvaro`, incluso si la ruta enumera otros roles. Al cambiar permisos, evaluar explícitamente ese bypass.
- La migración de octubre 2026 (`2026_10_26_000001_seguridad_rbac_aprobaciones`) amplía `users.role` con `alvaro`; el enum y la tabla `roles` deben permanecer sincronizados (seeder `RoleSeeder` incluye 'Álvaro (Propietario)', 'Administrador', 'Contador', 'Recepción / Caja', 'Estilista').
- `routes/api.php` declara `apiResource` para `clients`, `items` y `appointments`. No asumir autenticación/autorización de esos endpoints sin verificar middleware y configuración efectiva.
- Hay una discrepancia importante en el portal: `/portal/login` y el resto del grupo `portal` están anidados dentro del grupo web `auth`, aunque el controlador además valida un token de portal propio guardado en sesión. No describir el login del colaborador como independiente del login administrativo hasta corregir y probar esa configuración.
- Antes de alterar rutas, verificar nombres, verbos, middleware y precedencia. Las definiciones históricamente duplicadas del catálogo contable y de la consulta de un período ya fueron consolidadas (octubre 2026); quedan closures con lógica de agenda/POS.
- Rutas bancarias (octubre 2026, grupo `role:admin,contador`): `GET /bancos`, `POST /bancos/depositar`, `POST /bancos/transferir`, `POST /bancos/cargo`, `POST /bancos/abono`, `GET /bancos/{cuenta_bancaria}/libro`, `GET|POST /bancos/{cuenta_bancaria}/conciliacion` (nombres `bancos.*`). El binding del parámetro debe llamarse `cuenta_bancaria` para que Laravel resuelva `CuentaBancaria`.
- Mesa de cambio (grupo `role:recepcion,contador,admin`): `GET /mesa-cambio`, `POST /mesa-cambio/operar` y `POST /mesa-cambio/tasa-oficial` (nombre `mesa.tasa.oficial`). Cobertura de pruebas: `tests/Feature/BancosMesaCambioTest.php` (17 pruebas de cuadre, rollback, comisiones y conciliación) y `tests/Feature/AccesoFinanzasContadorTest.php` (pruebas de permisos financieros por rol y filtro mensual del Estado de Resultados).
- HRMS (octubre 2026, grupo `role:admin`): dashboard y perfil `GET /rrhh`, `GET /rrhh/colaborador/{id}`; turnos `GET|POST /rrhh/turnos`, `PUT /rrhh/turnos/{id}`, `POST /rrhh/turnos/asignar`; propinas `GET|POST /rrhh/propinas`; permisos `GET|POST /ausencias` y `POST /ausencias/{id}/aprobar|rechazar|cancelar`; nómina `GET /nomina`, `GET /nomina/wizard`, `POST /nomina/previsualizar`, `POST /nomina/procesar`, `GET /nomina/corrida/{id}`, `POST /nomina/corrida/{id}/anular`, `GET /nomina/colilla/{id}`, `GET|POST /nomina/estructuras`, `POST /nomina/estructuras/{id}/toggle` y la colilla legacy `GET /nomina/legacy/{id}/ticket`. Las rutas antiguas `POST /nomina/generar` y `GET /nomina/{id}/ticket` fueron reemplazadas por el wizard (definir primero las rutas estáticas para no colisionar con `{id}`).
- Asistencia (nivel general, todos los roles): `GET|POST /asistencia` registra la entrada (con cálculo de tardanza contra el turno vigente) y `POST /asistencia/salida` marca la salida calculando horas trabajadas/extras. El expediente de colaboradores (`/empleados`) sincroniza automáticamente el registro `employees` del colaborador al crear, editar o dar de baja.
- Fase 1 Administración (octubre 2026): `GET /clientes/{id}` (Perfil 360, nivel general) en el grupo `auth`; `POST /clientes/grupos` (grupos de fidelización), `POST /servicios/categorias` (categorías) y `PUT /formulas/{id}` (edición inline del consumo estándar) en el grupo `role:admin`. La carga del POS (`GET /pos`, `role:recepcion,contador,admin`) ahora eager-carga `variantes` y `formulas.articulo` para el selector de variantes y el editor de consumo real; el carrito acepta `variant_id` y `consumo[]` y `/ventas/{id}/documento` los devuelve. Cobertura: `tests/Feature/AdminClientesServiciosTest.php` (12 pruebas: límite/vencidas de crédito, descuento por grupo, variantes y BOM real/estándar con reversión exacta).
- Cadena de Suministro / AP (octubre 2026, grupo `role:admin`): compras `GET /inventario/comprar` + `POST /inventario/comprar` (crea `purchase_invoices` completa vía `CompraService`; respuesta JSON con `factura_id`), factura `GET /compras/{id}` (nombre `compras.show`) y `POST /compras/{id}/anular` (`compras.anular`, motivo obligatorio), kárdex `POST /inventario/kardex/apertura` (`inventario.kardex.apertura`) y proveedor `GET /proveedores/{id}` (`proveedores.show`). El kárdex (`GET /inventario/kardex`) lee el ledger inmutable con saldo corrido y mantiene las exportaciones `inventario.kardex.excel|pdf`. Cobertura: `tests/Feature/CadenaSuministroApTest.php` (9 pruebas: ciclo de compra con IVA/landed, costo promedio móvil, COGS real en venta, merma al costo real, Perfil 360 + guardia de borrado, bloqueos y reversión de anulación, apertura de kárdex y humo de vistas).
- **MESA DE APROBACIONES (gobernanza, octubre 2026, grupo `role:alvaro,admin`)**: `GET /aprobaciones`, `POST /aprobaciones/venta/{id}/aprobar`, `POST /aprobaciones/venta/{id}/rechazar`, `POST /aprobaciones/compra/{id}/aprobar`, `POST /aprobaciones/compra/{id}/rechazar`. Las transacciones retenidas en `pendiente_aprobacion` NO tocan contabilidad ni inventario hasta que un supervisor (Álvaro/Administrador) las apruebe con **doble confirmación por PIN**.
- Dashboard BI (octubre 2026, grupo `auth`, ver §5.10): `GET /panel/api/datos`, `GET /panel/api/movimientos/{id}` (nombres `panel.*`) y `POST /panel/api/meta` con `role:admin`. El drill-down de inventario usa `GET /inventario?stock=critico` (grupo `role:admin`). Los endpoints BI sin sesión devuelven 401 JSON (no redirect). Cobertura: `tests/Feature/DashboardGerencialTest.php`.

## 6.1. Doble Confirmación por PIN (Supervisor Override) — octubre 2026

**Objetivo**: Acciones críticas de caja y finanzas requieren autorización mediante PIN de 4-6 dígitos (hasheado con `Hash::make()`) almacenado en `users.pin_seguridad`. El PIN lo define la administración al crear/editar el perfil del usuario.

**Implementación**:
- **Trait reutilizable**: `App\Traits\AutorizaPinSupervisor` con método `autorizarPin(Request $request, bool $soloSupervisor = false)` que valida el PIN contra el usuario autenticado (modo estándar) o SOLO contra roles supervisores configurados en `config('salon.seguridad.roles_supervisor', ['alvaro', 'admin'])` (modo estricto).
- **Rate limiting**: 5 intentos fallidos por minuto por usuario (llave `pin-supervisor:{userId}`).
- **Auditoría**: Cada uso exitoso se registra en log (`Log::info('Acción crítica autorizada con PIN de supervisor', [...])`) con usuario que ejecuta, autorizador, roles, modo y ruta.
- **Modal Alpine.js global**: Componente `pinAutorizacion` (registrado en `resources/js/app.js`, vista `components/pin-modal.blade.php`, incluido en `layouts/app.blade.php`). Se abre despachando evento `open-pin` con `{ mensaje?, form?, callback? }`.

**Acciones protegidas (modo estricto `soloSupervisor: true`)**:
- Anulación de facturas (`VentaController::anular`)
- Aprobación/rechazo de ventas retenidas (`AprobacionController::aprobarVenta`, `rechazarVenta`)
- Aprobación/rechazo de compras retenidas (`AprobacionController::aprobarCompra`, `rechazarCompra`)

**Acciones protegidas (modo estándar - PIN propio O supervisor)**:
- Apertura de arqueo de caja (`CajaController::open`)
- Cierre de arqueo de caja (`CajaController::close`)
- Salidas de caja chica / gastos menores (`CajaChicaController::store`)

**Configuración**: `config/salon.php` → `seguridad.roles_supervisor` y `aprobaciones.roles_supervisor` (ambos `['alvaro', 'admin']`).

## 6.2. Flujo de Pre-Aprobación de Transacciones Sensibles — octubre 2026

**Objetivo**: Operaciones sensibles (ventas/compras sobre umbrales configurables o flag explícito) se RETIENEN en estado `pendiente_aprobacion` SIN contabilizar, SIN mover inventario, SIN registrar libro de pagos, SIN mover divisas, SIN generar comisiones. Solo un supervisor (Álvaro/Administrador) las despacha a contabilidad tras doble confirmación por PIN.

**Estados y campos**:
- **Ventas** (`sales`): nuevo estado `pendiente_aprobacion` (constante `Venta::ESTADO_PENDIENTE_APROBACION`). Campos de auditoría: `aprobada_at`, `aprobada_por` (FK users). `payload_aprobacion` (JSON) guarda el libro de pagos original (`payments[]`, `change_currency`) para ejecutarlo íntegro al aprobar.
- **Compras** (`purchase_invoices`): enum `estado` ampliado con `pendiente_aprobacion` (default `registrada`). Campos de auditoría: `aprobada_at`, `aprobada_por` (FK users). Las líneas se crean pero no tienen efectos físicos hasta aprobar.

**Umbrales**: `config('salon.aprobaciones.monto_venta')` y `monto_compra` (en C$, default 0 = desactivado). En ventas, roles supervisores (`alvaro`, `admin`) están exentos del umbral; en compras el umbral aplica a cualquier creador. Flag explícito `requiere_aprobacion` en el request fuerza la retención siempre.

**Servicios y controladores**:
- `AprobacionService`: lógica de decisión (`requiereAprobacionVenta`, `requiereAprobacionCompra`), cola de pendientes (`pendientes()`), aprobación (`aprobarVenta` ejecuta inventario + libro de pagos + asiento + comisiones), rechazo (`rechazarVenta` marca `anulada` con motivo, sin reversión porque nunca tuvo efectos).
- `CompraService::registrarFactura` acepta `requiere_aprobacion`; `aprobarFactura`/`rechazarFactura` delegan en `AprobacionController`.
- `VentaController::store` y `facturarCita` evalúan `AprobacionService::requiereAprobacionVenta` y crean la venta en `pendiente_aprobacion` con `payload_aprobacion`.
- `AprobacionController`: expone `index` (vista `aprobaciones.index`), `aprobarVenta`, `rechazarVenta`, `aprobarCompra`, `rechazarCompra` — **todas con doble confirmación por PIN (modo estricto)**.

**Vista**: `resources/views/aprobaciones/index.blade.php` con tabla de ventas/compras retenidas, botones Aprobar/Rechazar que disparan el modal PIN global, y modal de motivo obligatorio para rechazo.

**Rutas**: `GET /aprobaciones` (`aprobaciones.index`), `POST /aprobaciones/venta/{id}/aprobar`, `POST /aprobaciones/venta/{id}/rechazar`, `POST /aprobaciones/compra/{id}/aprobar`, `POST /aprobaciones/compra/{id}/rechazar` — grupo `role:alvaro,admin`.

## 6.3. Reestructuración RBAC: Rol Supremo "Álvaro" y Administrador Operativo — octubre 2026

**Cambios en `RoleSeeder`**:
```php
Rol::create(['name' => 'Álvaro (Propietario)']);  // ID 1 - bypass global
Rol::create(['name' => 'Administrador']);         // ID 2 - operativa, SIN contabilidad
Rol::create(['name' => 'Contador']);              // ID 3 - auditoría financiera completa
Rol::create(['name' => 'Recepción / Caja']);      // ID 4 - POS, caja, adelantos
Rol::create(['name' => 'Estilista']);             // ID 5 - agenda, servicios, portal
```

**Cambios en `UserSeeder`**: Crea usuarios para cada rol con emails y contraseñas distintas. El propietario (`admin@salon.com`) queda con `role='alvaro'`.

**Migración** (`2026_10_26_000001_seguridad_rbac_aprobaciones`):
- Añade `pin_seguridad` nullable a `users`.
- Amplía enum `users.role` a `('alvaro','admin','estilista','recepcion','contador')`.
- Crea rol 'Álvaro (Propietario)' en tabla `roles` y migra usuario `admin@salon.com` a `role='alvaro'`.
- Añade `aprobada_at`, `aprobada_por`, `payload_aprobacion` a `sales`.
- Añade `pendiente_aprobacion` a enum `purchase_invoices.estado` y `aprobada_at`, `aprobada_por` a `purchase_invoices`.

**Matriz de accesos resultante**:

| Módulo / Ruta | alvaro | contador | admin | recepcion | estilista |
|---|---|---|---|---|---|
| Contabilidad (diario, mayor, resultados, balance, catálogo, asientos, periodos, cierre) | ✅ | ✅ | ❌ 403 | ❌ 403 | ❌ 403 |
| Bancos, CxP, Retiros, Reportes | ✅ | ✅ | ✅ | ❌ 403 | ❌ 403 |
| POS, Ventas, Arqueo, Adelantos, Mesa de Cambio, Caja Chica | ✅ | ✅ | ✅ | ✅ | ❌ 403 |
| Inventario, Compras, Proveedores, Mermas, Kárdex, Fórmulas, Servicios, Respaldos | ✅ | ❌ 403 | ✅ | ❌ 403 | ❌ 403 |
| RRHH, Nómina, Empleados, Turnos, Ausencias, Propinas, Estructuras | ✅ | ❌ 403 | ✅ | ❌ 403 | ❌ 403 |
| Aprobaciones Pendientes | ✅ | ❌ 403 | ✅ | ❌ 403 | ❌ 403 |
| Agenda, Asistencia, Clientes (general), Portal Colaborador | ✅ | ✅ | ✅ | ✅ | ✅ |

**Tests actualizados**: `AccesoFinanzasContadorTest` (ahora usa usuarios `alvaro`, `admin`, `contador`, `recepcion` y verifica que `admin` NO accede a contabilidad), `CatalogoCuentasTest` y `ContabilidadVistasTest` (usan usuario `contador` para rutas contables).

## 6.4. Correcciones Adicionales (noviembre 2026)

### 6.4.1. Campo PIN en Perfil de Colaborador
- **EmpleadoController**: Añadido campo `pin_seguridad` (nullable, 4-6 dígitos, hasheado) en validación de `store` y `update`. Solo se actualiza si se envía valor nuevo.
- **Vista employees/index.blade.php**: Campo "PIN de Seguridad (4-6 dígitos)" en modal de creación/edición con validación solo numérica (`pinKeydown`).
- El PIN se guarda encriptado con `Hash::make()` y nunca se muestra en edición (campo vacío por seguridad).

### 6.4.2. Umbral de Aprobación Activado por Defecto
- **config/salon.php**: `aprobaciones.monto_venta` cambiado de `0` a `1` (C$), `monto_compra` a `10000`. Esto hace que **cualquier venta de mostrador por un rol no supervisor (recepcion) quede en `pendiente_aprobacion`** automáticamente, requiriendo PIN de Álvaro/Administrador para despachar a contabilidad.
- Los roles supervisores (`alvaro`, `admin`) siguen exentos del umbral.

### 6.4.3. Fix Botones PIN: `$event.target` vs `$el`
- **Problema**: En Alpine.js, `$el` refiere al elemento raíz del componente, no al botón clickeado. `$el.closest('form')` fallaba al no encontrar el formulario.
- **Solución**: Cambiado a `$event.target.closest('form')` en todas las vistas:
  - `resources/views/cash/index.blade.php` (abrir/cerrar caja)
  - `resources/views/petty_cash/index.blade.php` (gasto caja chica)
  - `resources/views/sales/index.blade.php` (anular factura)
  - `resources/views/aprobaciones/index.blade.php` (aprobar/rechazar ventas y compras)

### 6.4.4. Bloqueo de Logout con Caja Abierta
- **AutenticacionController::logout()**: Antes de cerrar sesión, verifica si el usuario tiene una `SesionCaja` abierta (`estado='abierta'`). Si existe, **bloquea el logout** y redirige a `/login` con error: *"Tienes una caja abierta. Debes cerrar tu turno (arqueo) antes de cerrar sesión."*
- Esto evita que la caja quede abierta en la base de datos sin control y previene confusión al iniciar sesión con otro usuario.

### 6.4.5. Caja NO se Auto-Abre ni Auto-Cierra
- **Comportamiento**: La `SesionCaja` permanece en `estado='abierta'` hasta que el usuario hace clic explícito en "Cerrar Turno y Arqueo" (con PIN). No hay lógica de auto-cierre en logout ni auto-apertura en login.
- Si un usuario cierra sesión sin cerrar caja, la caja sigue abierta en BD. Al intentar entrar otro usuario, ve su propia caja (o formulario para abrirla). El bloqueo de logout (§6.4.4) obliga a cerrar caja antes de salir.

### 6.4.6. Bloqueo Efectivo de Logout con Caja Abierta (noviembre 2026)
- **Problema**: `AutenticacionController::logout()` verificaba si había caja abierta y flasheaba un error, pero **seguía cerrando la sesión** y redirigiendo a `/login`.
- **Corrección**: Ahora el método **previene el logout completamente** si existe una `SesionCaja` con `estado='abierta'` para el usuario. Retorna `redirect()->back()->withErrors(...)` sin llamar a `Auth::logout()`. Solo permite el logout si no hay caja abierta.
- **Archivo modificado**: `app/Http/Controllers/AutenticacionController.php` (método `logout`).

### 6.4.7. Flujo de Pre-Aprobación Obligatorio para Ventas (noviembre 2026)
- **Objetivo**: TODA venta de mostrador (rol `recepcion` / `estilista` / `contador`) queda **retenida en `pendiente_aprobacion`** al confirmar el pago. NO se descuenta inventario, NO se registra libro de pagos, NO se mueven divisas, NO hay comisiones y NO hay asiento contable hasta que **Álvaro (Propietario)** la apruebe.
- **Configuración** (`config/salon.php`):
  - `aprobaciones.monto_venta = 1` (C$) → cualquier venta ≥ 1 C$ se retiene
  - `aprobaciones.roles_supervisor = ['alvaro']` → SOLO Álvaro está exento (sus ventas no se retienen)
  - `seguridad.roles_supervisor = ['alvaro']` → SOLO Álvaro puede autorizar con PIN
- **Flujo en POS** (`resources/views/sales/pos.blade.php`):
  1. Usuario (recepcion) arma el carrito y hace clic en "Proceder al Pago"
  2. Completa el modal de checkout (método, moneda, montos)
  3. Al confirmar, el backend crea la venta con `status = 'pendiente_aprobacion'` y guarda el `payload_aprobacion` con los pagos
  4. El frontend detecta `data.pendiente_aprobacion` y muestra: **"Factura hecha con éxito, a espera de la confirmación del gerente para realizar la venta y guardarla en contabilidad."**
  5. NO se abre el ticket, NO se afecta caja/contabilidad
  6. Álvaro entra a **Aprobaciones Pendientes** (`/aprobaciones`, solo visible para él), ingresa su PIN y aprueba
  7. Al aprobar (`AprobacionService::aprobarVenta`): se ejecuta inventario, libro de pagos, divisas, asiento contable y comisiones; la venta pasa a `pagada`
- **Rutas y acceso** (`routes/web.php`): grupo `role:alvaro` para `/aprobaciones/*` (antes `role:alvaro,admin`)
- **Menú** (`layouts/app.blade.php`): "Aprobaciones Pendientes" solo visible para rol `alvaro`
- **Archivos modificados**:
  - `config/salon.php` (umbral y roles)
  - `resources/views/sales/pos.blade.php` (manejo de respuesta `pendiente_aprobacion` en `checkout()` y `enviarCredito()`)
  - `routes/web.php` (middleware `role:alvaro` para aprobaciones)
  - `resources/views/layouts/app.blade.php` (visibilidad menú solo `alvaro`)

### 6.4.9. Vista "Mis Aprobaciones" para Cajeros/Recepción (noviembre 2026)
- **Objetivo**: El vendedor (recepcion/cajera) ve SOLO sus propias ventas retenidas, con estado en tiempo real.
- **Ruta**: `/mis-aprobaciones` (grupo `role:recepcion,contador,admin`)
- **Controlador**: `VentaController::misAprobaciones()` → filtra `cashier_id = auth()->id()` y estados `pendiente_aprobacion`, `pagada`, `anulada`
- **Vista**: `resources/views/sales/mis-aprobaciones.blade.php`
- **Menú**: "Finanzas → Mis Aprobaciones" (icono reloj) visible para `recepcion`, `contador`, `admin`
- **Estados mostrados**:
  - **Pendiente aprobación** (ámbar): "Esperando..." - botón deshabilitado
  - **Aprobada y contabilizada** (verde): Botones "Ver Ticket" (abre `/ventas/{id}/ticket` en nueva pestaña) y "Detalle"
  - **Rechazada/Anulada** (rojo): Botón "Ver motivo" (abre `/ventas/{id}/documento`)
- **Flujo completo**:
  1. Recepción hace venta en POS → queda `pendiente_aprobacion`
  2. Ve en "Mis Aprobaciones" → estado "Pendiente aprobación"
  3. Álvaro aprueba en `/aprobaciones` → venta pasa a `pagada` (inventario + asiento + ticket)
  4. Recepción recarga "Mis Aprobaciones" → ve "Aprobada y contabilizada" + botón **"Ver Ticket"**
  5. Click "Ver Ticket" → genera/imprime ticket normal
- **Archivos nuevos/modificados**:
  - `app/Http/Controllers/VentaController.php` (método `misAprobaciones()`)
  - `resources/views/sales/mis-aprobaciones.blade.php` (nueva vista)
  - `routes/web.php` (ruta `GET /mis-aprobaciones` nombre `ventas.mis-aprobaciones`)
  - `resources/views/layouts/app.blade.php` (menú "Mis Aprobaciones")

### 6.4.10. Sesiones Multi-Rol (Limitación del Navegador)
- **Problema**: Cookies de sesión se comparten entre pestañas del mismo navegador. No se pueden mantener dos roles simultáneos (ej. `recepcion` y `alvaro`) en pestañas distintas.
- **Soluciones para testear**:
  1. **Navegadores distintos**: Chrome (recepcion) + Firefox (alvaro)
  2. **Modo Incógnito/Privado**: Una ventana normal + una incógnita
  3. **Perfiles de usuario**: Chrome "Añadir perfil" → perfiles aislados con cookies separadas
  4. **Extensiones**: "SessionBox", "Multi-Account Containers" (Firefox)
- **En producción**: No es problema real, cada usuario físico usa su propio dispositivo/sesión.
- **Objetivo**: Informar a cada rol lo que requiere su atención, en dos canales:
  1. **Campanita general (Navbar)**: dropdown con lista personalizada por usuario/rol
  2. **Badges contextuales (Menú lateral)**: contadores rojos en cada item del menú indicando cuántas notificaciones pendientes hay para esa vista

- **Arquitectura**:
  - **Tabla**: `notifications` (migración `2026_11_06_191459`)
  - **Modelo**: `App\Models\Notification` (scopes `unread`, `forType`, relación `user`)
  - **Servicio**: `App\Services\NotificationService` (centraliza creación, consulta, badges)
  - **Controlador API**: `App\Http\Controllers\NotificationController` (`/notificaciones/api/*`)
  - **Componente Alpine**: `resources/js/notification-bell.js` → `Alpine.data('notificationBell')`

- **Tipos de notificación estándar** (`NotificationService::TYPE_*`):
  | Tipo | Roles destinatarios | Item de menú (badge) | Prioridad |
  |---|---|---|---|
  | `venta_pendiente_aprobacion` | `alvaro` | `/aprobaciones` | high |
  | `compra_pendiente_aprobacion` | `alvaro` | `/aprobaciones` | high |
  | `caja_sin_cerrar` | `recepcion, contador, admin, alvaro` | `/caja/arqueo` | normal |
  | `cxp_vencida` | `admin, contador, alvaro` | `/cuentas-por-pagar` | high |
  | `cxc_vencida` | `recepcion, contador, admin, alvaro` | `/adelantos` | high |
  | `stock_critico` | `admin, alvaro` | `/inventario` | high |
  | `nomina_pendiente` | `admin, alvaro` | `/nomina` | normal |
  | `ausencia_pendiente` | `admin, alvaro` | `/ausencias` | normal |

- **Flujo automático**: al crear venta en `pendiente_aprobacion` (`VentaController::store` y `facturarCita`), se llama a `$this->notifications->createByType(...)` que:
  1. Resuelve roles destino según `$typeToRoles`
  2. Crea una `Notification` por cada usuario activo de esos roles
  3. Al cargar cualquier página, el componente `notificationBell` hace `fetch('/notificaciones/api/count')` y `fetch('/notificaciones/api/list')`
  4. El menú lateral (en `layouts/app.blade.php`) llama a `$notificationService->getContextualCounts()` y pinta badges rojos en cada `<a>` según la URL

- **Endpoints API** (`routes/web.php`, grupo `auth`):
  - `GET /notificaciones/api/count` → `{ count: N }`
  - `GET /notificaciones/api/list` → `{ notifications: [...] }`
  - `POST /notificaciones/api/{id}/read` → marca una como leída
  - `POST /notificaciones/api/read-all` → marca todas como leídas

- **Dashboard oculto para recepción/cajera**: en `layouts/app.blade.php`, el enlace "Dashboard" (`/`) solo se renderiza si `in_array($rolUsuario, ['alvaro', 'admin', 'contador'])`. Recepción y estilista ven directamente el menú de operaciones.

- **Archivos nuevos/modificados**:
  - `database/migrations/2026_11_06_191459_create_notifications_table.php`
  - `app/Models/Notification.php`
  - `app/Services/NotificationService.php`
  - `app/Http/Controllers/NotificationController.php`
  - `resources/js/notification-bell.js`
  - `resources/js/app.js` (registro componente)
  - `resources/views/layouts/app.blade.php` (campanita + badges en menú + ocultar Dashboard)
  - `routes/web.php` (rutas API notificaciones)
  - `app/Http/Controllers/VentaController.php` (inyecta `NotificationService`, crea notificaciones al retener venta)

## 7. Datos, migraciones y documentación

- Las migraciones son la fuente de verdad del esquema versionado. Revisar tablas/constraints/índices actuales y migraciones cercanas antes de modificar modelos o generar migraciones nuevas.
- No editar migraciones que ya se hayan ejecutado en entornos compartidos; agregar migración correctiva reversible, salvo que el usuario confirme que el cambio es sólo local y no desplegado.
- Hay migraciones de compatibilidad que verifican existencia de columnas/tablas. Preservar idempotencia cuando sea parte del propósito de esa migración.
- Revisar seeders antes de cambiar catálogo contable, monedas, roles o datos iniciales. `ContabilidadBaseSeeder` es parte relevante de la configuración del motor contable. Las subcuentas complementarias del motor (`1.1.1.1`, `1.1.1.2`, `1.1.1.3` Propinas en Custodia, `1.1.2.3`, `1.1.2.4`, `1.1.7` IVA Crédito Fiscal (Compras), `2.1.5` Retenciones por Pagar, `2.1.6` Propinas por Pagar, `4.6`, `4.7`, `6.8` Mermas y Desperdicios, `6.9`, `7.5`) las inserta `CuentasComplementariasSeeder` (idempotente, invocado también por la migración corporativa y por los tests, porque el catálogo base se siembra después de migrar en instalaciones nuevas). Nótese que `6.8` y `1.1.7` solo existían antes si el catálogo ya estaba sembrado al correr su migración original: NO depender de migraciones que inserten cuentas condicionadas a que el catálogo exista, usar el seeder. La migración `2026_10_14_000001_scm_ap_corporativo` crea `purchase_invoices`, `purchase_invoice_lines` y `stock_ledger_entries`, amplía `providers`/`cuentas_por_pagar`/`lotes` y es reversible (`down()` revierte enum y FKs en orden). `RrhhBaseSeeder` (idempotente, invocado por la migración HRMS, por `db:seed` y por los tests) siembra tipos de ausencia, turnos por defecto y la estructura salarial "Planilla Estándar del Salón". `AdminCatalogoSeeder` (idempotente, invocado por la migración de fase 1 de Administración, por `db:seed` y por los tests) siembra grupos de clientes (Regular/VIP/Mayorista), categorías de servicios (Cabello/Maquillaje/Uñas/Tratamientos/Otros) y plantillas de impuestos (Exento/IVA 15%).
- `README.md` describe instalación general, pero referencia `PROJECT_CONTEXT.md` y `DOCUMENTACION_TECNICA.md`, que no figuran en la estructura actual del workspace. Confirmar su existencia antes de enlazarlos o tratarlos como fuentes vigentes.
- METAS DEL DASHBOARD (octubre 2026): la migración `2026_10_15_000001_create_company_targets_table` crea `company_targets` (`periodo` date UNIQUE = primer día del mes, `monto` decimal(15,2), `moneda`, `creado_por` FK nullable a users) y es reversible. No requiere seeder: sin filas el panel usa el fallback `config('salon.meta_mensual')` vía `CompanyTarget::montoParaMes()` (§5.10). `package.json` incluye la dependencia npm `chart.js` (importada por `resources/js/dashboard.js`); tras actualizarla correr `npm run build` — el CDN de Chart.js del dashboard fue eliminado.
- `Como_trabajar.md` aporta mandamientos de negocio adicionales. Mantenerlo consistente con este archivo cuando se modifiquen reglas financieras; si hay contradicción, verificar implementación y pedir precisión antes de relajar una restricción financiera.

## 8. Convenciones para implementar cambios

1. Lee instrucciones aplicables y sigue el flujo desde ruta hasta controlador, servicio, modelos y migración. No infieras reglas sólo por el nombre de una clase.
2. Mantén el cambio enfocado y compatible con estilo/idioma local. La interfaz y comentarios existentes mezclan español e inglés; no hagas conversiones masivas no solicitadas.
3. Valida entrada en el límite HTTP, pero mantén invariantes financieras/de inventario en la capa de dominio/servicio para que no dependan de una sola pantalla.
4. Usa transacciones y locks al coordinar saldos, existencias, lotes y asientos que puedan sufrir concurrencia.
5. Para cambios de base de datos, contempla `up()` y `down()`, datos existentes, FKs, precisión decimal e idempotencia cuando corresponda.
6. Añade pruebas cercanas al comportamiento afectado. Prioridad: cuadre exacto, rollback, reversión, saldo insuficiente, límites de rol y consistencia de inventario.
7. No afirmar que una regla está cubierta por tests si sólo existe prueba de ejemplo. Ejecuta el test más específico disponible y reporta bloqueos de entorno.
8. No tocar `vendor/`, `public/build/`, secretos ni `.env` salvo solicitud explícita.
9. BLADE (Laravel 13): no usar la forma inline `@php($var = ...)`: el compilador la emite como `<?php(` sin cerrar y desalinea el resto de directivas del archivo (los `@for` quedan sin compilar y la vista lanza ParseError). Usar siempre la forma multilínea `@php ... @endphp`. Además, escapar las expresiones de Alpine.js que Blade confundiría con PHP (`@{{ ... }}`), nunca `{{ expresionAlpine }}`.

## 9. Comandos de trabajo en Windows

Usar PowerShell. Para inspeccionar código, buscar texto o leer archivos, usar las herramientas de workspace; no usar comandos de terminal ni utilidades GNU como `grep`, `cat`, `sed`, `awk` o `wc` para esa tarea. La terminal se reserva para comandos del ecosistema.

- Instalar dependencias: `composer install` y `npm install`.
- Preparación definida por Composer: `composer setup` (revisar `.env` y la base configurada antes; el script ejecuta migraciones con `--force`).
- Pruebas: `composer test` o `php artisan test --filter=NombreDePrueba`.
- Formato PHP: `vendor/bin/pint --test app/Services/ContabilidadService.php` (ajustar ruta al código tocado).
- Compilar frontend: `npm run build`.
- Desarrollo: `composer run dev` levanta Laravel, cola, logs y Vite según el script de Composer.

No ejecutar `migrate:fresh`, restauraciones, seed destructivo ni comandos que borren/reemplacen datos sin autorización explícita y confirmación del entorno/base destino.

## 10. Verificación mínima por tipo de cambio

- Regla financiera: prueba de asiento balanceado/desbalanceado y rollback; comprobar referencia y período.
- Venta/caja/divisas: verificar pagos mixtos, vuelto, moneda base, cuentas destino y saldos por ubicación.
- Inventario: verificar artículo, volumen abierto, FIFO de lotes, existencia negativa y costo.
- Nómina/comisión: verificar comisión congelada de la venta, cuotas/deducciones y asiento.
- Permisos/rutas: verificar usuario sin sesión, rol permitido, rol denegado y bypass admin intencional.
- Blade/JS/CSS: compilar con Vite y comprobar estados vacíos, errores de validación y uso responsive del flujo afectado.
- Migración: comprobar aplicación y reversión en una base de prueba apropiada; no usar producción como entorno de validación.
