# AC-01 — Configurabilidad del motor contable

Fecha: 2026-11-16. Estado: diagnóstico y diseño, sin refactorización.

## 1. Conclusión y alcance comprobado

El ERP permite administrar el catálogo, pero **no dispone todavía de una política
central versionada de asociación de cuentas por efecto operativo**. Buena parte
de la automatización depende de códigos concretos; reportes, cierres y validadores
de anulaciones también conocen esos códigos. Cambiar solo el resolutor de cuentas
no produce un motor configurable seguro.

La solución mínima recomendada mantiene ContabilidadService, documentos, líneas
de asiento y servicios de dominio. Añade asociaciones semánticas versionadas,
validación común de cuentas e identificación de la política usada, sin hacer
configurables las fórmulas financieras mediante código o expresiones del contador.

Se inspeccionaron modelos, migraciones, seeders, controladores, servicios, rutas,
vistas, importación/exportación y las restricciones de H03/H04/H01. También se leyó
el Excel local como ZIP/XML, sin extraerlo ni importarlo. Se analizaron valores y
fórmulas almacenados, sin ejecutar fórmulas ni macros o arrancar Laravel.
No hubo consultas SQL, DML/DDL, PHPUnit, migraciones, seeders ni cambios funcionales.
Las capacidades que siguen son evidencia estática, no una certificación de datos
o permisos efectivos de las bases. SALON_TEST_DB_RESET_ALLOWED se comprobó en 0.

## 2. Capacidades configurables y límites actuales

| Área | Ya existe | Límite o dependencia comprobada |
| --- | --- | --- |
| Catálogo | CuentaContable → cuentas_contables; código único, nombre, tipo, naturaleza, padre, nivel, permite_movimiento, activa e is_system_account | No identifica el papel operativo de una cuenta ni versiones de asociación |
| Jerarquía | Padre/hijos por cuenta_padre_id; árbol UI y API | FK autorreferente histórica tiene CASCADE; update evita padre propio y descendientes, pero no hay constraint SQL anticiclos ni protocolo concurrente de edición del árbol |
| Crear/subcuentas | CuentaContableController::store; padre existente y nivel padre+1 | No obliga a coherencia entre código, tipo, padre o naturaleza; no convierte automáticamente un padre imputable en agrupador |
| Edición | Cuentas no sistema permiten cambiar código, naturaleza, tipo, padre, movimiento y estado | No hay guardia por historia en update; nivel solo recalculado para la cuenta editada, no descendientes. Puede reinterpretar reportes históricos |
| Cuentas sistema | update solo permite nombre; destroy prohíbe borrarlas | Tampoco pueden desactivarse por ese endpoint. La protección numérica evita roturas actuales, pero no sustituye asociaciones semánticas |
| Borrado | destroy rechaza sistema, detalles o hijos | No presenta dependencias de bancos, tipos de gasto, estructuras salariales o nuevas aperturas. Hay vínculos sin FK y cascadas históricas: la guardia HTTP no es una garantía global |
| Activa/imputable | Flags y filtros en selectores manuales | El resolutor automático comprueba existencia; no aplica uniformemente activa/permite_movimiento/tipo/naturaleza al contabilizar |
| Importación | Dos pasos: validación estructural y transacción; códigos existentes rechazados; padres/ciclos del archivo revisados | Solo altas, no sustitución. Requiere columnas y padres antes de hijos. No decide equivalencia, clasificación empresarial ni migración de referencias |
| Cuentas elegidas por operación | Asiento manual, cuenta de gasto y contrapartida cargo/abono bancario | Un exists no acredita cuenta activa/imputable o autorizada. Banco valida tipo gasto/costo/ingreso, no todas las condiciones necesarias |
| Bancos | cuentas_bancarias.cuenta_contable_id; FondosService prioriza ese vínculo | También conserva fallback BAC/LAFISE fijo; BancoController crea/enlaza catálogos base por códigos. No hay historial de esa asociación |
| Caja chica | tipos de gasto → cuenta_contable_id, usado al cargar gasto | CajaChicaController::index crea tipos de prueba usando la primera cuenta 6.% si no hay tipos: no es configuración aprobada por el contador |
| Nómina | salary_structure_details.cuenta_contable_id y relación Eloquent | La columna es nullable, sin FK en migración HR; no se utiliza para resolver líneas en contabilizarColillaNomina. Es metadato, no parametrización contable efectiva |
| Centros/moneda/períodos | centro_costo_id, moneda_id, tasa_cambio_id; período y estado de asiento | Son dimensiones ya disponibles; no justifican sustituir documentos o el cálculo de importes |
| Auditoría | Tabla auditoria_asientos; logs de errores y autorización PIN | No se encontraron escritores de auditoria_asientos en app. No hay historial transaccional del catálogo/asociaciones. No usar un log de PIN como prueba de commit |

Fuentes: app/Models/{CuentaContable,AsientoContable,DetalleAsiento,CuentaBancaria,
TipoGasto,DetalleEstructuraSalarial}.php; CuentaContableController::store/update/
destroy/importar/validarEstructuraCatalogo; FondosService::cuentaContableDeBanco;
AsientoManualController; migraciones base 2026_08_04_041607/041610/041613/041830,
2026_09_22_000001 y 2026_10_13_000001.

Los asientos guardan cuenta_id e importes, pero no fotografía del código/nombre,
clasificación o versión de política. El detalle tiene DECIMAL(15,2); no hay un
CHECK de cuadre agregado entre filas. El cuadre y la inmutabilidad dependen de los
caminos de aplicación. Hay CASCADE desde asiento a detalles/auditoría: no implica
autorización de borrado, pero impide afirmar que el esquema por sí solo lo prohíbe.

## 3. Inventario de códigos fijos y clasificación

Clasificación: **C** asociación configurable; **R** interpretación/regla de negocio;
**H** conservar referencia histórica; **M** dependencia compartida entre módulos;
**T** integridad técnica. Ningún número de cuenta concreto es una necesidad técnica
intrínseca. Lo técnico son identidad/FK, tipos, restricciones, transacción y precisión.
Las combinaciones C/R/H/M siguientes no autorizan modificar reglas.

| Códigos actuales | Uso/consumidores concretos | Clase y precaución |
| --- | --- | --- |
| 1.1.1 | Caja NIO: ContabilidadService ventas/cobros/gasto/arqueo/proveedores/caja chica/nómina/adelantos/retiros; FondosService; DashboardService | C/H/M; moneda y bóveda deben conservarse |
| 1.1.1.2 | Caja USD: pagos/vuelto/transferencias, FondosService, dashboard | C/R/H/M; no mezclar caja operativa NIO con inventario de divisas |
| 1.1.1.3 | Propinas en custodia: registro y colilla | C/R/H/M; circuito junto a 2.1.6 |
| 1.1.2 | Fallback de banco agregado en contabilizarVenta | C/M; revisar camino heredado, no convertir agrupador en cuenta válida por defecto |
| 1.1.2.1 / 1.1.2.2 | LAFISE/BAC NIO: getBancoIdPorMetodo, getCuentaBancoDestino, libro de pagos, FondosService, BancoController, dashboard | C/H/M; banco genérico suele caer a LAFISE |
| 1.1.2.3 / 1.1.2.4 | BAC/LAFISE USD: BancoController y catálogo complementario | C/R/H/M; el vínculo por ubicación ya ofrece una base reutilizable |
| 1.1.3 | CxC clientes: ventas crédito/cobro/adelantos | C/R/H/M; conservar saldo y origen de deuda |
| 1.1.4 / 1.1.5 | Inventario reventa / insumos: compras, salidas, mermas, AnulacionVentaService | C/R/H/M crítico; H03/H04 concilian por estas cuentas |
| 1.1.6 | Anticipos de empleados: adelantos y nómina | C/R/H/M; no confundir con inventario 1.1.5 |
| 1.1.7 | IVA crédito fiscal de compras y su anulación | C/R/H/M; no cambiar impuesto/capitalización al parametrizar |
| 2.1.1 | Proveedores: compra crédito, pago y anulación | C/R/H/M; cuentas por pagar no son contrapartida de apertura |
| 2.1.5 | Retenciones laborales: nómina | C/R/H/M; no cambiar tasas/deducciones |
| 2.1.6 | Propinas por pagar: registro y colilla | C/R/H/M; no liberar custodia fuera del circuito |
| 3.2 | Cierre fiscal: PeriodoContableController::cierreFiscal | C/R/H; es un papel de cierre, no apertura automática |
| 3.3 | Retiros patrimoniales: contabilizarRetiroPropietario | C/R/H; debe seguir siendo contra-patrimonio, nunca gasto |
| 3.99 | BalanceGeneral, cuenta virtual en memoria de utilidad del ejercicio | R/presentación; no es un destino persistido de contabilización |
| 4.1 / 4.4 | Ingreso bruto servicios/productos: registrarLineasIngreso | C/R/H; mantener bruto y descuento separado |
| 4.5 | Contra-ingreso por descuentos: registrarLineasIngreso | C/R/H; naturaleza deudora no la convierte en gasto |
| 4.6 / 7.5 | Ganancia/pérdida cambiaria: contabilizarOperacionCambio | C/R/H/M; conservar costo libro y signo del diferencial |
| 4.7 / 6.9 | Abono bancario por defecto / cargo y comisión: BancoController, ContabilidadService | C/R/H; default visual y asociación aprobada son cosas distintas |
| 5.1 / 5.2 | COGS mercadería/servicio: crearAsientosCostoVenta, AnulacionVentaService | C/R/H/M crítico; importe original del ledger, no receta vigente |
| 5.3 / 6.7 | Comisiones/sueldos en nómina corporativa y camino legacy | C/R/H/M; no recalcular comisiones congeladas |
| 6.8 | Merma: contabilizarMerma | C/R/H/M; valor del movimiento, no precio comercial |
| 7.3 / 7.4 | Sobrante/faltante de arqueo: contabilizarArqueo | C/R/H; registrar su semántica real, no deducirla del nombre de clase 7 |

Literales adicionales del catálogo, sin una asociación automática central detectada:
raíces 1–7 y grupos 1.1/1.2/2.1/2.2/3; 1.1.1.1 (caja NIO de compatibilidad),
1.2.1/1.2.2/1.2.3 (activos fijos), 2.1.2/2.1.3/2.1.4/2.2.1,
3.1, 4.2/4.3, 5.4, 6.1–6.6, 7.1 y 7.2. Los seeders son una plantilla concreta,
no prueba de que todas esas cuentas sean consumidas por un escritor automático.
7.2 se conserva pero el seeder actual la desactiva y quita movimiento; 3.3 es el
destino de retiros. No reactivar 7.2 al incorporar una referencia externa.

Inventario de localizaciones ejecutables (líneas de la revisión; no incluye como
ejecutable un número contenido solo en comentarios/documentación):

- ContabilidadService: resolutor 119–140; bancos 160–183; ventas/pagos 232–275;
  ingresos 480–532; COGS y whitelist 535–618; cobros/CxC 622–749;
  arqueo 864–921; compras/anulación/clasificador 931–1112; proveedores/caja chica
  1115–1202; transferencias/comisiones 1213–1325; nómina 1391–1634;
  propinas 1694–1727; adelantos 1733–1791; retiros 1832–1881;
  merma 1887–1949; diferencial 1959–2055.
- AnulacionVentaService:98,117,128; MermaController:66–67;
  BancoController:77–78,613–614 y asegurarCatalogoBancario;
  FondosService:146,168,197; DashboardService:40,142–144;
  PeriodoContableController:155 (virtual),185 (cierre).
- Prefijos 1/2/3/4/5/6/7 y 4.%–7.%: balance, cierre, resultados, DashboardService
  y resources/views/accounting/resultados.blade.php. CajaChicaController:24 usa
  primera 6.%; ContabilidadController:26–27 filtra 5.%/6.% para gastos.
- H01-B2B-1 no resuelve una cuenta fija de apertura; su validación comprueba
  metadatos de una política y excluye patrones 3.2/3.3. Es una guarda semántica
  conservadora pendiente de aprobación, no una asociación activa.

La búsqueda cubrió literales PHP en app/config y revisó seeders, prefijos SQL y
vistas. Nombres de métodos, cadenas construidas, IDs de BD y configuración externa
no pueden certificarse por un barrido de literales: deben incluirse en el preflight
de dependencias posterior. No se consultó una base para enumerar sus IDs actuales.

## 4. Acoplamientos y riesgos que bloquean un cambio directo

| Prioridad | Evidencia | Consecuencia y control recomendado |
| --- | --- | --- |
| Crítica | AnulacionVentaService::comprobarAsientos distingue 5.1/5.2 e inventario por códigos; también usa codigoInventarioArticulo del artículo actual | Una nueva asociación aislada puede hacer que H04-B rechace o clasifique mal historia. Mantener verificador legacy y usar referencias/versión original para documentos nuevos |
| Crítica | crearAsientosCostoVenta/acreditarInventarioCosto agrupa por código y acepta solo 1.1.4/1.1.5 | No sustituir números de forma parcial: entrada, saldo valorado, salida, COGS, comprobación y reversión forman un conjunto |
| Crítica | contabilizarAnulacionCompra reconstruye líneas según clasificación/cuentas actuales, a diferencia del espejo de venta/nómina | Al cambiar asociaciones, una anulación de compra podría usar cuentas distintas al COM original. Debe quedar histórica antes de permitir ese cambio |
| Alta | El inventario usa promedio móvil global por artículo; las asignaciones físicas no son submayores de valoración por cuenta | Cambiar cuenta con saldo puede descargar otra cuenta y dejar el activo anterior intacto. No usar FIFO físico para asignar costos contables |
| Alta | update de cuenta no sistema permite tipo/código/padre/movimiento con historia | El mismo cuenta_id puede aparecer reclasificado en balances anteriores; no se pierde la FK, pero sí el significado/presentación históricos |
| Alta | Resultados/balance/dashboard/cierre leen código y permite_movimiento actuales | Renumerar o hacer agrupadora una cuenta con detalles puede ocultar o mover historia sin tocar asientos. Desactivar debe prohibir nuevas imputaciones, no borrar saldos de reportes |
| Alta | getCuentaIdByCodigo usa caché por código y solo verifica existencia; FondosService devuelve vínculo directo sin validar atributos contables | Una cuenta desactivada/agrupadora puede seguir recibiendo imputaciones o una caché prolongada quedar obsoleta |
| Alta | update tiene anticiclos HTTP, no control concurrente; store permite padre imputable | No es una jerarquía completamente validada; dos ediciones simultáneas pueden invertir padres tras comprobaciones separadas |
| Alta | AsientoManualController valida cuenta por exists; usa primer período abierto, no período de fecha solicitada | No certificar control de fecha/período uniforme. UI filtra activa/imputable, endpoint no repite todo; debe corregirse focalizadamente antes de ampliar administración |
| Alta | AsientoManualController::reversar acepta por ID y solo excluye modulo_origen=reversion | No hay filtro visible que limite a manual en ese método: ruta genérica puede eludir coordinación de documento/inventario si se usa sobre automáticos. Restringir como prerequisito futuro, sin modificarlo aquí |
| Media | saldo/resultado no guarda versión de asociación ni snapshot del catálogo | No se puede reconstruir qué configuración eligió la cuenta, salvo por los IDs efectivamente registrados y evidencia documental existente |
| Media | Seeders marcan cuentas base/suplementarias sistema y presuponen códigos | No reemplazar el plan empresarial con seeder ni presentar su ejecución como aprobación; también revisar inicializadores en GET de banco/caja chica |

Partida doble es necesaria, no suficiente: dos líneas en cuentas equivocadas pueden
cuadrar. El servicio compara centavos enteros en validarCuadre. El manual usa una
validación final propia a dos decimales, además de un prechequeo con tolerancia;
esa duplicación no justifica relajar la igualdad ni usar floats nuevos sin control.

## 5. Modelo configurable mínimo propuesto

### 5.1 Separación de responsabilidades

| Capa | Permanece / extensión propuesta |
| --- | --- |
| A Evento | Venta, compra, consumo, merma, devolución, colilla, cobro, retiro, cambio, apertura; documento e identidad operativa existentes |
| B Regla | Métodos actuales determinan cuándo contabilizar, importes, signos, moneda, períodos y efectos. Identificador/versionado de regla, no código ejecutable ni fórmulas arbitrarias editables |
| C Asociación | Resolver un papel semántico dentro de una política aprobada: ingreso.servicio, ingreso.producto, descuento.venta, inventario.reventa, inventario.insumos, costo.producto, costo.servicio, anticipo.empleado, propina.custodia/pasivo, etc. Devuelve cuenta_id validado |
| D Historia | Detalles originales con cuenta_id/importes; versión de política/regla y datos necesarios de clasificación. Anulaciones usan el origen, no asociaciones nuevas |

Añadir un resolutor pequeño integrado con ContabilidadService. Por ejemplo,
resolverCuenta(papel, contextoValidado, versionFijada), sin inferir papel desde un
código/nombre ni aceptar cuenta arbitraria del navegador. El contador cambia
asociaciones y políticas aprobables; no define por configuración «una venta retenida
descuenta stock», «un retiro es gasto» o «una anulación devuelve material».

Reutilizar cuentas_contables como única identidad de cuenta; no crear otro catálogo.
Reutilizar cuentas_bancarias y tipos de gasto para sus contextos, validando y
versionando cambios de sus asociaciones; no duplicar sus recursos. La columna de
estructura salarial no se vuelve operativa automáticamente: la interpretación de
componentes y las invariantes HRMS necesitan integración y pruebas específicas.

### 5.2 Persistencia mínima a diseñar, no creada

Las tablas actuales no guardan asociaciones versionadas suficientes. Propuesta
inicial: dos tablas nuevas, justificadas por publicación atómica e historia:

1. accounting_policy_versions: versión, estado draft/approved, vigencia futura,
   empresa única implícita (sin multisucursal), autor/aprobador, timestamps, motivo,
   referencia de aprobación y hash del contenido. Un demo no es approved empresarial.
2. accounting_account_bindings: versión, papel estable, ámbito tipado/clave canónica
   (por ejemplo recurso bancario/moneda o clase física), cuenta_id con RESTRICT y
   UNIQUE(version,papel,ámbito). Sin «primera cuenta disponible», solapamientos o
   comodines con prioridad ambigua. Metadatos de validación de papel definidos en
   código, no SQL libre almacenado.

Añadir en asientos_contables una referencia nullable a esa versión y evidencia de
regla/clasificación necesaria; cuenta_id/importes ya bastan para el destino histórico
del detalle, pero no para justificar su selección. Guardar resolución por línea/papel
en metadata explícita si un asiento combina papeles; no esconderla en descripcion.
Conservar NULL como legado, sin backfill de aprobaciones ni clasificación inferida.

Requiere además auditoría durable de edición de catálogo y publicación. La tabla
auditoria_asientos exige asiento_id y no sirve para cambios de cuentas sin inventar
un asiento. Evaluar una tabla de auditoría de configuración (actor, acción, versión/
cuenta, antes/después, motivo, timestamp) como extensión adicional delimitada; no
considerar Log::info sustituto de ese registro transaccional. No se crea hoy.

No editar una versión publicada: crear una nueva, validar conjunto completo y
activar en un único cambio aprobado. El resolutor debe fijar una sola versión por
operación antes de contabilizar, con caché por versión/papel/ámbito y validación
actual de la cuenta, sin mezclar dos publicaciones en un asiento. Una publicación
concurrente no debe cambiar una operación a mitad ni exigir un lock global exclusivo
de todo el ERP. Diseñar bloqueos de publicación/catálogo compatibles con el orden
documentos/usuarios/artículos/lotes/kárdex de H02/H03/H16 antes de implementarlos.

Para retenidas, proponer fotografiar la versión aceptada originalmente y validarla
al aprobar, junto con su composición H03. Decidir expresamente si una versión
desactivada puede terminar pendientes o requiere nueva autorización: nunca cambiar
silenciosamente al mapeo vigente al aprobar. La elección es empresarial/contable.

### 5.3 Validación y modificaciones prospectivas

Una asociación nueva requiere cuenta existente, activa, imputable, papel/tipo y
naturaleza compatibles, moneda/recurso válidos, versión aprobada y período abierto.
Naturaleza no es sinónimo de signo único del asiento: descuentos y retiros son
contra-cuentas y deben conservar su interpretación. «Seleccionable en UI» no basta.

Las cuentas agrupadoras se separan de las imputables. Para el plan actual puede
conservarse temporalmente su numeración; admitir otros prefijos requiere un grupo
de presentación estable, distinto de codigo y más fino que tipo (gasto operativo
y otros egresos son ambos gasto). Proponer atributo/clasificación explícita y su
evidencia histórica; sustituir prefijos también en vistas, dashboard y cierre en una
entrega coordinada. No basta cambiar a naturaleza ni sumar todos los descendientes
si ya se contabilizan padres/hijos: provocaría doble conteo.

Congelar código/tipo/naturaleza/jerarquía/grupo de cuentas con historia en el alcance
mínimo; permitir desactivar nuevas imputaciones conservando consulta y reversión
autorizada de originales. Si se necesita renumeración histórica, diseñar aliases/
versiones y presentación separadas antes, sin UPDATE masivo de asientos.

No confundir «cambios prospectivos» con descargar inventario antiguo en una cuenta
nueva. Bloquear cambios del papel de inventario de artículos con posición valorada,
evidencia pendiente o devoluciones aún viables que puedan reintroducir valores del
origen. Saldo físico cero aislado no demuestra elegibilidad. Si se pretende migrar
esa posición hace falta una política de traslado/reclasificación aprobada y otra
entrega; aquí no se autorizan ajustes. La opción mínima es mantener la asociación
original hasta conciliar su ciclo, sin añadir submayores por cuenta ni cambiar
promedio móvil a FIFO contable.

## 6. Administración, permisos y auditoría

Estado actual: grupo web auth + role:alvaro,contador para catálogo, manuales,
mayor, resultados y períodos. RoleMiddleware da bypass al propietario alvaro;
admin no tiene ese bypass contable. PIN estricto vigente solo admite el rol
configurado alvaro. No crear rol paralelo ni ampliar acceso por este informe.

| Acción futura | Propuesta mínima para aprobación |
| --- | --- |
| Crear cuenta/subcuenta | Contador/propietario con permisos actuales; validar árbol, tipo, imputabilidad y auditoría |
| Editar cuenta sin historia | Misma autorización, dependencias y anticiclos bajo control concurrente |
| Cuenta con historia | No cambiar semántica; desactivar para uso futuro con control de pendientes y asociaciones, sin ocultar reportes |
| Borrado | Mantener prohibición con historia/sistema/dependencias; preferir desactivación. Revisar RESTRICT de relaciones en migración futura antes de asumir protección total |
| Preparar asociaciones | Contador; validación y vista de impacto por evento/documento/recurso |
| Publicar política | Aprobación contable explícita y referencia durable; decidir si requiere también PIN del propietario/separación de personas. No atribuir al PIN competencia para definir cuentas |
| Reversión | Flujo del documento original, con motivo/evidencia/autorización existentes; no revertir automáticos desde manual genérico |
| Importación | Previsualización/validación y aprobación específica antes de ejecutar; nunca sustituir catálogo con historia |

Una consulta de dependencias deberá incluir detalles/asientos y períodos, vínculos
bancarios, tipos de gasto, estructuras salariales, políticas/versiones, actas de
apertura, pendientes y reportes/códigos legacy. No basta «tiene detalles». Mostrar
qué operación quedaría sin cuenta y bloquear publicación incompleta. Auditoría
sin PIN/password/hashes y con evidencia de publicación confirmada dentro de la
transacción; los intentos de PIN conservan su semántica de autorización intentada.

## 7. Excel del contador: referencia analizada, no importable automáticamente

Archivo: docs/CONTABILIDAD ALVARO RUGAMA.xlsx, 21.243 bytes.
SHA256: 03005277d86e89a2124e2cb5f511b91d97f6754e31b00de532fe78840fcad58d.
Hojas: Libro Diario (B2:I181, 179 filas no vacías) y catalogo de cuenta (B3:B40,
38 filas). La segunda contiene textos descriptivos en una sola columna, no los
encabezados de importación codigo/nombre/tipo/naturaleza/codigo_padre. Tiene notas
entre corchetes y una descripción final incompleta; no tomar esas notas como datos.

El catálogo de esa hoja es conceptualmente próximo al seed actual: clases 1–6,
caja/bancos/CxC/inventarios, pasivos, capital, ingresos, costos y gastos. No incluye
todo el catálogo complementario del ERP (custodia, multimoneda, nómina, etc.). La
similitud y coincidencia de código son candidatas a revisión, no equivalencias
autorizadas ni prueba de una misma historia económica.

### 7.1 Divergencias verificables entre hojas y ERP

| Evidencia del diario | Comparación y decisión pendiente |
| --- | --- |
| C5 y otras celdas numéricas almacenan 1.1000000000000001 para CAJA | Catálogo B5 usa 1.1.1; no redondear ese número para asignar una cuenta. C55 usa el mismo valor numérico con BANCOS: ambigüedad real del folio |
| C6/C11 y muchas otras usan 1.2 para BANCOS | Catálogo B10 es Activo No Corriente; no coincide semánticamente |
| C110/C151 usan 1.2.1 BANCO LAFISE; C79/C158, 1.2.2 BANCO BAC | En catálogo B11/B12 son mobiliario/equipo. ERP usa bancos NIO 1.1.2.1/1.1.2.2. Candidatas por recurso, no mapeo automático |
| C117/C135:1.4 CUENTAS POR COBRAR; C38/C82/C91:1.5 INSUMOS | Catálogo usa 1.1.3 y 1.1.5; revisar qué saldos/documentos representan |
| C12 y otras:2.1 PROVEEDORES; C26 numérico 2.2999999999999998 IMPUESTOS | Catálogo 2.1 es agrupador; proveedores 2.1.1 e impuestos 2.1.3. Código numérico no es identidad segura |
| C7 y otras:7.6 VENTAS DE SERVICIOS; C128:7.6 DESCUENTO POR VENTA | Un mismo código no identifica un papel único. Catálogo propone 4.1–4.3 servicios; ERP actualmente usa 4.1 y 4.5 para descuento |
| C31 y otras:7.5 VENTAS DE PRODUCTO; C118/C162:7.5 ADELANTO DE SALARIO | ERP 7.5 es pérdida cambiaria; productos van 4.4 y anticipos 1.1.6. No asignar un código externo a tres efectos |
| C141:7.7 DESCUENTO; C152 numérico 4.0999999999999996 INGRESOS SERVICIOS | Requieren corrección/confirmación del contador; ni número ni nombre justifican reescribir historia |
| C18 y otras:7.2 GASTOS PERSONALES | ERP conserva 7.2 inactiva y usa retiros patrimoniales 3.3. Si eran retiros es una decisión sobre el hecho, no un reemplazo textual |
| C97:1.1.2 ROBO EFECTIVO EN CAJA; C83:7.4 COSTOS | Colisionan con bancos/pérdida por robo del catálogo vigente; no trasladar sus significados por código |
| C27:2.2.1 préstamo largo; C170:2.2.2 préstamo corto; C28 etc.:5.4 PAGO A MODELO | Algunos conceptos parecen cercanos al catálogo/seed y otros divergen (corto sería 2.1.2 en catálogo). Exigen documento y confirmación del papel, aun cuando el código coincida |

### 7.2 Referencias y precisión

- Libro Diario!G30 tiene fórmula `+#REF!` y error almacenado; G32 suma G22:G31 y
  también contiene #REF!. No inferir la venta que falta a partir del cuadre.
- Códigos de folio están almacenados a veces como números binarios, no texto;
  preservar el XML original y pedir códigos textuales explícitos. No normalizar
  1.1000000000000001 como si fuera una corrección contable aprobada.
- Importe F6=10211.6114 y G7=32405.611400000002; formato monetario de estilo9
  `[$C$-4C0A]#,##0.00` oculta precisión interna. Se usan tasas literales como
  36.6243 y 36.5 en fórmulas. No equipararlas automáticamente a tasa oficial o
  costo de divisas del ERP.
- Totales almacenados: F50=10002.5946/G50=10002.587199999998; F93=28913.264200000001/
  G93=28913.25; F106=13671.5/G106=13671.2; F153=49350/G153=49850.
  Hay diferencias subcentavo y diferencias materiales, no todas atribuibles a
  precisión binaria o formato visual.
- Análisis auxiliar decimal sobre valores almacenados, sin modificar celdas:
  redondear los importes F/G de líneas con folio a centavos (mitad hacia arriba)
  deja diferencia
  Debe-Haber de +0,01 en filas34–41, +0,02 en87–92, -10,00 en95–98, +0,30 en101–105
  y -500,00 en149–152. En la fila99 «SUMAS IGUALES» no hay totales F/G almacenados.
  Los bloques44–49 y77–84 no muestran diferencia al sumar líneas así redondeadas,
  aunque sus totales almacenados difieren. Es una comparación bajo una regla
  ilustrativa, no aprobación de esa política ni reparación de los asientos.

No se recalcularon fórmulas en Excel ni se verificaron comprobantes, fechas reales,
tipos de cambio, cobros, impuestos o titularidad del material. Los caches pueden
estar desactualizados; solo se afirma lo leído del archivo y la comparación decimal
indicada. Los números del ejemplo no prueban el estado de los libros del salón.

Tratamiento futuro: conservar original/hash; pedir catálogo tabular de texto y
diario corregido por el contador; matriz externo→cuenta/papel ERP con evidencia,
confirmación, alcance/vigencia y ambigüedades explícitas. Separar propuesta de
homologación de importación. Antes de aprobar una cuenta, verificar evento y
contrapartida, recurso/moneda, tipo/naturaleza e imputabilidad. Sin coincidencia
inequívoca, bloquear; no asociar por nombres parecidos o números coincidentes.

## 8. Compatibilidad obligatoria con H03-B2 y H04-B

Mantener físicamente intactos cantidades, envases, capacidad, unidad, asignaciones,
fotografías de consumo, orden de bloqueos y valores originales. El nuevo resolutor
no recibe permiso para valorar con costo FIFO nominal, receta actual o precio de
venta. El costo promedio móvil y valor reconocido del documento/ledger permanecen
fuera de la asociación de cuentas.

Para futuros documentos, conservar la cuenta/papel por artículo/detalle y versión
original junto al asiento/efecto; un inventario mixto no se agrupa antes de resolver
su cuenta. H04-B debe comprobar cantidades/asignaciones y la cuenta realmente usada,
no comparar solo códigos de la política vigente. Historia sin versión permanece
en camino legacy estricto, sin backfill ni supuesto de que «se usó la configuración
actual». Si no es determinable, conciliación y rechazo controlado.

La anulación sin restitución conserva CST/CMV realmente incurrido; una configuración
nueva no debe autorizar devolver material ni revertir ese costo. Con devolución
válida se invierten importes/cuentas originales conservando continuidad física y
referencias. No convertir una cuenta inactiva histórica en una cuenta nueva al
revertir: diseñar excepción acotada de reversión autorizada y período abierto,
sin reactivar uso ordinario ni desviar crédito a otro inventario.

Las ventas y colillas ya invierten líneas originales en sus reversas. La anulación
de compra y el verificador de H04-B deben compatibilizarse explícitamente antes de
habilitar cambios de asociación afectados. No modificar H04-B como simple cambio
de nombres ni levantar sus rechazos para hacer pasar un nuevo plan.

## 9. Apertura H01-B2B: selección explícita, sin activación

Tres estados separados:

1. Plantilla de demostración: códigos/roles sugeridos del seed o fixtures. No tiene
   aprobación empresarial ni puede activar un writer en desarrollo.
2. Política empresarial aprobada: referencia del contador, versión, vigencia/corte,
   contrapartida y alcance; activos/inventario y criterio de valoración autorizados.
3. Operación real: acta/UUID/contenido canónico, conteo/evidencia y supervisor;
   resultados confirmados en una única transacción. Aún no autorizada/implementada.

Papel propuesto: apertura.inventario.contrapartida, resuelto a una cuenta de la
política aprobada; sin fallback a 3.1/3.2/3.3, ingresos, proveedores o cuenta puente.
El contable decide entre cuenta específica autorizada, aportación real documentada
o integración en balance inicial. Un balance multicuentas sigue siendo otra
dependencia; no cabe fingirlo en una única contrapartida.

Reutilizar counterpart_account_id y policy_snapshot de inventory_openings para la
cuenta resuelta/versionada y aprobación; no cambiar B2A por anticipado. H01-B2B-1
solo prepara valores/hash e interpreta identidad: no demuestra cuenta SQL, PIN,
período ni autorización humana con sus booleanos de fixtures. P1–P9 y los bloqueos
actuales siguen vigentes, sin emisión de stock ni asiento de apertura.

## 10. Plan incremental propuesto y pruebas, sin ejecutar

| Entrega para autorización futura | Cambio acotado | Aceptación y pruebas focalizadas |
| --- | --- | --- |
| AC-02 Integridad del catálogo | Validador de cuenta por papel y dependencias, guardas de cambios con historia, anticiclos concurrentes, manuales/períodos y reversión solo del origen compatible | Activa/imputable/tipo/naturaleza, cuenta con historia, padre/hijo, dos reparentados, fecha cerrada, intento de reversión manual de automático; sin cambiar saldos |
| AC-03 Asociaciones versionadas | Esquema aditivo de políticas/asociaciones y auditoría, resolutor por papel, snapshot/version en nuevos asientos | Política demo no ejecutable, versiones sin solapamiento, publicación atómica, falta de cuenta rechazada, concurrencia publicación-contabilización, caché por versión; no conversiones legacy |
| AC-04 Primer evento y lectura comparada | Un evento de bajo acoplamiento elegido por contador; resolución en paralelo sin duplicar asientos y luego uso controlado en test | Mismas cuentas/importes que baseline, cambios prospectivos, trazabilidad y rollback; ningún fallback silencioso. No empezar por mover saldos de inventario |
| AC-05 Reportes/clasificación | Grupo de presentación explícito y preservado; eliminar dependencias de prefijos en controlador/vista/dashboard/cierre | Mismo diario/mayor/balance/resultados antes/después, contra-cuentas, cierre excluido, cuenta desactivada con saldo visible, ausencia de doble conteo |
| AC-06 Integración por familias | Fondos/cobros, compras/inventario/anulaciones coordinados, luego HRMS; no sustituir fórmulas | H02/H03/H04 por cuenta y documento, ventas mixtas, consumos cero, retención/aprobación, salario/propinas/adelantos, moneda/bóveda, compras anuladas con mapeo posterior; rollback y carreras reales |
| H01-B2B independiente | Solo tras decisiones P1–P9 y futura autorización del escritor | Contrapartida aprobada y exacta, idempotencia concurrente, stock/asignaciones/asiento atómicos, historia incompatible rechazada, sin compra/pago/pasivo ficticio |

AC-05 debe preceder a cualquier autorización de numeración externa incompatible;
no es necesario renumerar para ensayar AC-03 con el plan actual. La primera versión
de compatibilidad no se presume aprobada por extraerla del seed: contador debe
confirmar asociaciones efectivas y dependencias en una futura inspección SQL de
lectura con destino autorizado.

Toda ejecución posterior requiere su autorización, preflight de test79 y cuenta
restringida, permisos, respaldos/SHA256, líneas base e indicador0 en finally.
Pruebas nuevas de migración solo para nuevas extensiones aprobadas; no repetir
innecesariamente instalación/rollback de B2A. No suites completas ni despliegue V2.

## 11. Cambios que no conviene realizar

- Buscar/reemplazar códigos globalmente, importar el Excel directamente, sustituir
  catálogo/seed sobre una base con historia o usar nombres como identificadores.
- Convertir código en número decimal, recalcular importes históricos para hacerlos
  cuadrar, redondear referencias, resolver #REF! por diferencia de totales.
- Hacer libremente editables los códigos de sistema antes de eliminar dependencias;
  permitir cuentas agrupadoras/inactivas por omisión o usar «primera cuenta» como política.
- Crear motor de reglas genérico, lenguaje de expresiones, arquitectura nueva o
  catálogo duplicado. El contador configura destinos/políticas aprobables, no código.
- Cambiar promedio móvil, costos, impuestos, comisiones, fórmulas o continuidad de
  envases como parte de «configurabilidad»; la asociación no altera el hecho económico.
- Decidir reversión según configuración vigente, mezclar contabilidad nueva con
  validadores históricos de códigos fijos o ignorar reportes por centrarse en asientos.
- Configurar apertura desde fixtures, generar una cuenta patrimonial por defecto,
  activar escritores/V2 o aprovechar esta auditoría para implementar H01-B2B/UI-01.

## 12. Autorizaciones necesarias y cierre

Pendientes del contador/propietario:

1. Qué plan es la referencia empresarial y qué hoja del Excel debe corregirse;
   catálogo tabular definitivo, tratamiento de folios ambiguos y política de precisión.
2. Papeles operativos y asociaciones por recurso/moneda, clasificación de reportes,
   cuentas imputables/agrupadoras y contrapartidas permitidas; aprobación formal de
   versión inicial, sin equivalencias tácitas por nombre/código.
3. Publicación, responsables, eventual separación de personas/PIN, vigencia y
   tratamiento de retenidas, cuentas inactivas y documentos legacy.
4. Alcance de congelación/reclasificación de cuentas con historia; cambios de cuenta
   de inventario con posición valorada permanecen bloqueados hasta diseño autorizado.
5. Autorización de una entrega concreta AC-02/AC-03 y de sus futuras migraciones/
   pruebas aisladas. Este diagnóstico no la concede.
6. Decisiones de apertura P1–P9 por separado: esta auditoría no escoge contrapartida,
   fuente de valoración o fecha de corte ni levanta bloqueos de H01-B2B.

Único archivo creado: este informe. El Excel ya era un archivo local sin seguimiento
al comenzar y se preserva sin cambios. No se consultaron ni modificaron las tres
bases y no se afirma una nueva comparación de integridad. No hubo commits.
Reversión de esta entrega: retirar solo el informe si se descarta; ningún rollback,
importación, restauración o modificación financiera es necesario ni está autorizado.
