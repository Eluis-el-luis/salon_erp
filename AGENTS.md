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
| Panel, agenda y citas | `PanelController`, ruta `/agenda`, `CitaController`, `Cita`, `Cliente`, `Servicio` | Indicadores del negocio, agenda diaria y citas. La agenda web actualmente consulta modelos desde una closure; el API usa `CitaController`. |
| Clientes | `ClienteController`, `Cliente` | Datos de clientes y relaciones con citas, ventas y movimientos de crédito. |
| POS y ventas | `VentaController`, `Venta`, `DetalleVenta`, `Pago` | Venta de servicios/productos, libro de pagos (pagos mixtos y vuelto multimoneda en `pagos`), ticket, facturación de cita vía redirección al POS, inventario, comisiones y asiento contable. Ciclo de vida del documento (`status`): `borrador` (cotización sin efectos), `emitida` (crédito/CxC, cobro posterior en `/ventas/{id}/cobrar`), `pagada`, `anulada` (`/ventas/{id}/anular`, solo admin). |
| Caja y caja chica | `CajaController`, `SesionCaja`, `CajaChicaController`, `CajaChica`, `MovimientoCajaChica` | Apertura/cierre, arqueo (el efectivo teórico se calcula desde el libro de pagos, vía `Venta::efectivoNioNeto()`), movimientos de efectivo y gastos menores. |
| Inventario y compras | `InventarioController`, `Articulo`, `Lote`, `InventarioService` | Existencias, compras, costos, lotes, kardex y alertas de stock. |
| Recetas y mermas | `FormulaController`, `FormulaServicio`, `MermaController`, `Merma` | Consumo de productos fraccionables por servicio y bajas por merma. |
| Empleados, comisiones y nómina | `RrhhController`, `EmpleadoController`, `AusenciaController`, `NominaController`, `NominaService`, `Empleado`, `Turno`/`AsignacionTurno`, `TipoAusencia`/`Ausencia`, `Propina`, `EstructuraSalarial`, `CorridaNomina`/`ColillaNomina`/`DetalleColilla`, `Usuario`, `EsquemaComision`, `EsquemaRango`, `ComisionGenerada`, `Nomina` (legacy), `AsistenciaController` | Dashboard RRHH y Perfil 360 (`/rrhh`), expediente de colaboradores, turnos y asignaciones, permisos/aprobaciones (`/ausencias`), propinas en custodia, wizard de nómina con previsualización, colillas imprimibles, anulación con reversión y estructuras salariales. La asistencia (general) alimenta tardanzas y horas extra. |
| Adelantos / CxC | `AdelantoController`, `Adelanto`, `AdelantoCuota` | Saldos de empleados/clientes, cuotas y reversión de movimientos. |
| Proveedores / CxP | `ProveedorController`, `CuentaPorPagarController`, `CuentaPorPagar`, `PagoProveedor` | Proveedores, obligaciones y abonos. |
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

## 6. Autenticación, autorización y rutas actuales

- Las rutas web administrativas están dentro de `auth`, salvo el bloque inicial de login/logout. Las rutas base incluyen panel, agenda, asistencia y clientes.
- CORRECCIÓN CRÍTICA (octubre 2026): `RoleMiddleware` ahora tiene firma variádica `handle(..., string ...$roles)`. Laravel entrega `role:admin,contador` como argumentos separados, y la firma antigua `string $roles` sólo leía el PRIMER rol: el contador era bloqueado en todas las rutas multi-rol (incluida `role:recepcion,contador`) aunque estuviera listado. No regresar a la firma de un solo parámetro.
- `role:admin,contador` agrupa el bloque financiero: reportes, contabilidad (diario, gasto, mayor, resultados, balance), catálogo de cuentas, asientos manuales, periodos y cierre fiscal, bancos, cuentas por pagar y retiros. `role:recepcion,contador` agrupa POS/ventas, adelantos, arqueo, caja chica y mesa de cambio. `role:admin` conserva exclusivamente RRHH, nómina, inventario, fórmulas/servicios, proveedores y respaldos.
- El menú del layout (`layouts/app.blade.php`) filtra cada elemento con `roles => [...]` según el rol del usuario (el admin ve todo); los enlaces que un rol no puede abrir ya no se le muestran. Si mueves una ruta de grupo, actualizar también su entrada en el menú.
- `RoleMiddleware` permite siempre al usuario cuyo campo `role` sea `admin`, incluso si la ruta enumera otros roles. Al cambiar permisos, evaluar explícitamente ese bypass.
- La migración de septiembre de 2026 amplía `users.role` con `contador`; el enum y la tabla `roles` deben permanecer sincronizados.
- `routes/api.php` declara `apiResource` para `clients`, `items` y `appointments`. No asumir autenticación/autorización de esos endpoints sin verificar middleware y configuración efectiva.
- Hay una discrepancia importante en el portal: `/portal/login` y el resto del grupo `portal` están anidados dentro del grupo web `auth`, aunque el controlador además valida un token de portal propio guardado en sesión. No describir el login del colaborador como independiente del login administrativo hasta corregir y probar esa configuración.
- Antes de alterar rutas, verificar nombres, verbos, middleware y precedencia. Las definiciones históricamente duplicadas del catálogo contable y de la consulta de un período ya fueron consolidadas (octubre 2026); quedan closures con lógica de agenda/POS.
- Rutas bancarias (octubre 2026, grupo `role:admin,contador`): `GET /bancos`, `POST /bancos/depositar`, `POST /bancos/transferir`, `POST /bancos/cargo`, `POST /bancos/abono`, `GET /bancos/{cuenta_bancaria}/libro`, `GET|POST /bancos/{cuenta_bancaria}/conciliacion` (nombres `bancos.*`). El binding del parámetro debe llamarse `cuenta_bancaria` para que Laravel resuelva `CuentaBancaria`.
- Mesa de cambio (grupo `role:recepcion,contador`): `GET /mesa-cambio`, `POST /mesa-cambio/operar` y `POST /mesa-cambio/tasa-oficial` (nombre `mesa.tasa.oficial`). Cobertura de pruebas: `tests/Feature/BancosMesaCambioTest.php` (17 pruebas de cuadre, rollback, comisiones y conciliación) y `tests/Feature/AccesoFinanzasContadorTest.php` (11 pruebas de permisos financieros por rol y filtro mensual del Estado de Resultados).
- HRMS (octubre 2026, grupo `role:admin`): dashboard y perfil `GET /rrhh`, `GET /rrhh/colaborador/{id}`; turnos `GET|POST /rrhh/turnos`, `PUT /rrhh/turnos/{id}`, `POST /rrhh/turnos/asignar`; propinas `GET|POST /rrhh/propinas`; permisos `GET|POST /ausencias` y `POST /ausencias/{id}/aprobar|rechazar|cancelar`; nómina `GET /nomina`, `GET /nomina/wizard`, `POST /nomina/previsualizar`, `POST /nomina/procesar`, `GET /nomina/corrida/{id}`, `POST /nomina/corrida/{id}/anular`, `GET /nomina/colilla/{id}`, `GET|POST /nomina/estructuras`, `POST /nomina/estructuras/{id}/toggle` y la colilla legacy `GET /nomina/legacy/{id}/ticket`. Las rutas antiguas `POST /nomina/generar` y `GET /nomina/{id}/ticket` fueron reemplazadas por el wizard (definir primero las rutas estáticas para no colisionar con `{id}`).
- Asistencia (nivel general, todos los roles): `GET|POST /asistencia` registra la entrada (con cálculo de tardanza contra el turno vigente) y `POST /asistencia/salida` marca la salida calculando horas trabajadas/extras. El expediente de colaboradores (`/empleados`) sincroniza automáticamente el registro `employees` del colaborador al crear, editar o dar de baja.

## 7. Datos, migraciones y documentación

- Las migraciones son la fuente de verdad del esquema versionado. Revisar tablas/constraints/índices actuales y migraciones cercanas antes de modificar modelos o generar migraciones nuevas.
- No editar migraciones que ya se hayan ejecutado en entornos compartidos; agregar migración correctiva reversible, salvo que el usuario confirme que el cambio es sólo local y no desplegado.
- Hay migraciones de compatibilidad que verifican existencia de columnas/tablas. Preservar idempotencia cuando sea parte del propósito de esa migración.
- Revisar seeders antes de cambiar catálogo contable, monedas, roles o datos iniciales. `ContabilidadBaseSeeder` es parte relevante de la configuración del motor contable. Las subcuentas complementarias del motor (`1.1.1.1`, `1.1.1.2`, `1.1.1.3` Propinas en Custodia, `1.1.2.3`, `1.1.2.4`, `2.1.5` Retenciones por Pagar, `2.1.6` Propinas por Pagar, `4.6`, `4.7`, `6.9`, `7.5`) las inserta `CuentasComplementariasSeeder` (idempotente, invocado también por la migración corporativa y por los tests, porque el catálogo base se siembra después de migrar en instalaciones nuevas). `RrhhBaseSeeder` (idempotente, invocado por la migración HRMS, por `db:seed` y por los tests) siembra tipos de ausencia, turnos por defecto y la estructura salarial "Planilla Estándar del Salón".
- `README.md` describe instalación general, pero referencia `PROJECT_CONTEXT.md` y `DOCUMENTACION_TECNICA.md`, que no figuran en la estructura actual del workspace. Confirmar su existencia antes de enlazarlos o tratarlos como fuentes vigentes.
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
