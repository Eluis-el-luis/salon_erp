# H05-V0 — Preparación de validación SQL aislada

Fecha: 20 de noviembre de 2026. Estado: **preparación terminada; integración SQL
pendiente y no ejecutada**. Este documento no autoriza crear un servidor, esquema,
cuenta, fixture, asiento ni otro dato. Tampoco autoriza destruirlos posteriormente.

Referencia de código inspeccionado: `450f6807775e079a13c5ba2a5abc1518e42ed4c0`.
El árbol estaba limpio al iniciar H05-V0. H05-R permanece sin modificaciones.

## 1. Diagnóstico del flujo integrado

Se inspeccionaron AGENTS.md, H05-R, H03-B2, el estado final de H04-B, controladores,
servicios y modelos implicados, configuración, bootstrap y pruebas existentes.

| Flujo real | Secuencia relevante |
| --- | --- |
| POST `/sales`, pagar inmediato | Caja abierta → validación HTTP → transacción exterior → mutex de agenda → CarritoVentaService → crédito si aplica → bloquearCarrito/composición → venta/detalles/consumos → inventario/kárdex → LibroPagosService/divisas → ContabilidadService → ComisionService → commit |
| POST `/sales`, crédito | Misma preparación; inventario y reconocimiento CxC; sin libro de pagos inicial |
| POST `/sales`, cotizar | Documento, detalles y consumos; sin efectos físicos/pagos/asientos/comisiones; reemplazo de líneas solo de borrador válido |
| POST `/sales`, retenida | Documento/detalles/consumos y payload de pagos; notificación SQL; efectos financieros/físicos al aprobar |
| Aprobar retenida | AgendaService bloquea venta → validarDocumento conserva precios → inventario → pagos/divisas → contabilidad → comisiones → estado/auditoría/notificaciones → completar cita → commit |
| Facturación directa de cita | `/sales/bill-appointment/{appointment}` genera detalles desde servidor; distinto del carrito de POS. Tiene ruta legacy de divisas/contabilidad; no se unifica con POS |
| Cobro de crédito | `/ventas/{id}/cobrar` usa documento existente y libro de pagos; no reconstruye precio desde catálogo |

Las rutas web conservan sesión, CSRF y roles vigentes. Para venta inmediata se
usará un propietario **sintético** `alvaro`, exento según configuración actual;
para retención, un cajero `recepcion` y aprobación con propietario/PIN sintéticos.
No desactivar umbrales ni ampliar roles para obtener éxito artificial.

`CarritoVentaService` resuelve catálogo y calcula centavos; no escribe documentos.
`validarDocumento` no revaloriza precios históricos. LibroPagosService sigue
usando su conversión/vuelto actuales. Se probarán estas implementaciones reales,
sin un segundo motor ni expectativas económicas adaptadas al resultado observado.

## 2. Conexiones, transacciones y efectos externos

### Conexión de los escritores de ventas

VentaController, CarritoVentaService, AprobacionService, LibroPagosService,
InventarioService, StockLedger, ContabilidadService, ComisionService, DivisaService,
AgendaService y NotificationService usan Eloquent/DB de la conexión predeterminada.
No se identificó `new PDO`, conexión con nombre alternativo ni propiedad de modelo
`$connection` específica en ese recorrido. Es un resultado de inspección de las
rutas seleccionadas, no una garantía universal para todo el repositorio.

- VentaController abre/cierra la transacción exterior para emitir/cobrar.
- AprobacionService usa `DB::transaction`; AgendaService también tiene bloques
  transaccionales y locks que deben compartir esa misma conexión.
- ContabilidadService abre bloques internos. Laravel instalado, en
  `Illuminate/Database/Concerns/ManagesTransactions.php`, usa savepoints al anidar
  y llama al commit PDO al nivel exterior. Un commit interno no prueba persistencia.
- Inventario, StockLedger y divisas dependen de la transacción del llamador;
  mantienen locks. No se añadirá una transacción que oculte commits indebidos.
- `resolverPeriodo` puede insertar un período antes del begin interno de
  contabilizarVenta, pero en este flujo ya existe la transacción del controlador.
  Ese período también debe desaparecer al fallar la operación.

### Conexiones independientes fuera del flujo

Existen PDO directos a `127.0.0.1:3306/salon_erp_test` en
`SyntheticFinancialPresentationDraftReader`, `AccountingCatalogueInventoryReader`
y `PilotDemoReadOnly`, además de auxiliares históricos. No están en la ruta de
emisión revisada. Cambiar `database.default` o usar DB::listen **no intercepta**
esos PDO. No invocarlos ni reutilizar esos launchers en H05-V1.

El futuro arnés debe combinar lista única de conexiones Laravel, controles antes
de resolver/reconectar PDO, revisión de código y bloqueo de salida de red del
proceso hacia 3306. Un listener posterior a consultas sirve como evidencia,
no como barrera que impida la primera consulta indebida.

### Efectos que no se revierten con SQL

- NotificationService persiste en `notifications` por Eloquent: incluir esas filas
  en los snapshots transaccionales. No se encontró envío de correo/HTTP en sus
  métodos de notificación de venta revisados.
- VentaController y ContabilidadService escriben logs; ContabilidadService puede
  flashear mensajes de sesión. Un rollback de filas no los deshace.
- Configurar sesión/cache en memoria y logging null en el proceso de ensayo.
  Mail array, broadcast null y cola sync; **sync ejecuta jobs**, no los neutraliza:
  revisar y abortar ante un job/listener externo inesperado.
- Sin navegador/ticket/impresión, servidor HTTP abierto, scheduler, workers,
  exportaciones, respaldos ni Storage compartido. HTTP se ejercita con el kernel
  real en proceso. Red externa bloqueada y almacenamiento temporal propio.
- AppServiceProvider registra morphMap; no abre SQL ni publica mensajes en boot.
  Revalidar providers, descubrimiento de paquetes y hooks antes de ejecutar.

## 3. Dependencias SQL y origen de estructura

Tablas principales: `sales`, `sale_details`, `pagos`, `sale_detail_consumptions`,
`items`, `lotes`, `stock_ledger_entries`, `services`, `service_variants`,
`service_formulas`, `clients`, `customer_groups`, `users`, `roles`,
`cash_sessions`, `monedas`, `saldos_moneda`, `cuentas_contables`,
`periodos_contables`, `asientos_contables`, `detalle_asientos`,
`comisiones_generadas`, `esquemas_comision`, `esquema_rangos`, `notifications`,
`appointments` y `appointment_user`. Confirmar nombres físicos de todas las
relaciones al construir el manifiesto de fixtures, sin inferirlos del español.

La vía elegida evita ejecutar migraciones/seeders históricos: varias migraciones
ejecutan DML o invocan seeders (RRHH, catálogo, cuentas complementarias), y una
migración antigua reconstruye sales/sale_details. No son un generador inocuo de
DDL. No ejecutar migrate, migrate:fresh, DatabaseSeeder ni composer setup.

Fuente técnica propuesta para el esquema vacío:

`tests/Support/fixtures/ac05-schema-v1.json`, sección **original_tables**.

SHA256 comprobado mediante lectura de archivo:
`0c139a1fc6627958f3827c9062e76bf6a3685b67eb731e379c78b872202a4dfc`.

El contrato versionado describe 89 tablas originales, 82 nombres de migración y
dos tablas sintéticas adicionales en otra sección. Su DDL está separado de las
filas históricas. Se propone generar un artefacto DDL **nuevo** a partir de las
89 definiciones originales; no usar `synthetic_tables`, dumps, datos, grants ni
la identidad histórica del manifiesto. No se generó ni importó ese artefacto ahora.

La autorización de comparación AC-05 no se convierte en autorización de importación
o aprobación contable: el uso de estas estructuras en el servidor descartable
debe aprobarse expresamente en V1. No editar ese manifiesto ni sus guardias.

El futuro generador deberá validar cada sentencia, dependencia FK, columnas,
índices, engine InnoDB, charset/collation y hashes; rechazar sentencias ajenas,
calificadores de otras bases, triggers/rutinas/eventos/DEFINER o DATA DIRECTORY.
No copiar valores de contadores ni identidad del servidor. Ordenar dependencias;
si hay ciclos, preparar DDL revisado en fases, sin desactivar comprobaciones FK.
No asumir que un importador o un orden alfabético ya están verificados.

Crear un perfil **H05** independiente para la comparación estructural de la
instancia nueva. `AccountingTestSchemaProfiles::assertSchema` comprueba identidad
histórica y no sirve directamente para el nuevo destino. No relajar sus perfiles
89/91 ni ejecutar su verificador de backup, que pertenece a evidencia protegida.
Las migraciones no se ejecutan; no fingir que una fila en migrations prueba que
se ejecutaron en esa instancia. Registrar procedencia en el manifiesto del ensayo.

## 4. Estrategia de aislamiento propuesta

| Alternativa | Evaluación |
| --- | --- |
| A. Instancia MySQL separada | **Elegida**: binario disponible, datadir/UUID/puerto/cuentas independientes; evita compartir el servidor que contiene las tres bases protegidas |
| B. Nueva base en servidor actual | Menos pasos de provisión, pero comparte proceso y requiere DDL/gestión de usuarios sobre el servidor histórico. Un desvío con credenciales ordinarias tiene mayor alcance. No elegida |
| C. SQLite, transacción exterior sobre test o contenedor | SQLite no reproduce MySQL/ENUM/locks. La transacción exterior sobre test altera contadores y puede ocultar commits. Contenedor posible, pero no se comprobó runtime disponible y no hace falta instalarlo para reutilizar el binario existente |

Parámetros propuestos, **todavía inexistentes y no reservados**:

| Elemento | Valor |
| --- | --- |
| Binario existente inspeccionado | `E:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqld.exe` |
| Raíz de ejecución nueva | `E:/salon-erp-h05-disposable/run-h05v1` |
| datadir | `E:/salon-erp-h05-disposable/run-h05v1/mysql/data` |
| Configuración exclusiva | `<raíz>/mysql/my.ini` |
| Endpoint TCP | `127.0.0.1:3307` |
| Esquema exclusivo | `salon_erp_h05_disposable` |
| Usuario de ejecución | `h05_runner@127.0.0.1` |
| Código/caches/storage de ensayo | `<raíz>/source`, `<raíz>/runtime` |

El binario fue inspeccionado como archivo, no ejecutado. La consulta local de
listeners no mostró resultado para 3307; esto no reserva el puerto ni sustituye
una comprobación al iniciar. Si está ocupado o la ruta ya existe, abortar, sin
reutilizar directorios ni cambiar automáticamente de puerto.

MySQL documenta instancias Windows con configuración, datadir y puerto separados.
Se reutiliza esa capacidad sin registrar otro servicio ni modificar Laragon.
[Manual MySQL 8.4: instancias Windows](https://dev.mysql.com/doc/refman/8.4/en/multiple-windows-command-line-servers.html).

Provisión futura en una ventana autorizada:

1. Validar rutas absolutas, ausencia de junctions/enlaces, ACL, puerto libre,
   versión y hash del binario. Crear una raíz exclusiva y un manifiesto fuera
   del ERP; no modificar `.env`, `.env.testing` ni configuración de Laragon.
2. Crear my.ini exclusivo: bind loopback, puerto/datadir/PID/logs/tmp/secure-file-priv
   propios; deshabilitar named pipe, shared memory, MySQL X, eventos, binlog y
   local_infile. Confirmar opciones admitidas por el binario antes de arrancar.
3. Inicializar datadir nuevo con contraseña administrativa temporal aleatoria;
   no usar initialize-insecure ni copiar el directorio de datos histórico.
4. Lanzar únicamente ese proceso, oculto, sin servicio instalado. Capturar PID,
   ruta, línea de comando y UUID de su `auto.cnf`, con ACL y manifiesto propios.
   El UUID esperado se vincula a ese datadir nuevo, no se toma ciegamente del
   primer servidor que responda al puerto.
5. La fase administrativa verifica identidad/datadir, crea solo esquema y cuentas
   de esa instancia, aplica el DDL aprobado y retira credenciales administrativas
   del proceso PHP. No reutilizar root ni contraseña del servidor de Laragon.
6. Crear fixtures sintéticos mínimos; capturar baseline local. Ejecutar después
   solo la selección explícita autorizada, nunca todo tests/Feature.

La versión se fija a 8.4.3, disponible localmente; no se propone actualizar
MySQL. La estructura elegida y `inventory_fractional_v2=false` permiten ensayar
el camino legacy sin activar V2 ni falsificar su base/cuenta para eludir la puerta.

## 5. AUTO_INCREMENT y conservación histórica

En InnoDB los valores asignados a AUTO_INCREMENT pueden perderse al hacer
rollback; el contador no es una fila de negocio que se revierta junto con el
INSERT. No se exige que el próximo ID sea consecutivo ni que el rollback lo
reutilice. [Manual MySQL 8.4: AUTO_INCREMENT](https://dev.mysql.com/doc/refman/8.4/en/innodb-auto-increment-handling.html).

El manifiesto/migraciones muestran claves automáticas en sales, sale_details,
pagos, sale_detail_consumptions, stock_ledger_entries, comisiones_generadas,
asientos_contables, detalle_asientos, periodos_contables, saldos_moneda y
notifications. Los fixtures también pueden consumir IDs de roles/users/clients,
grupos, items/lotes/services/variantes/fórmulas, caja, citas y su pivote. Algunas
tablas solo se actualizan durante la venta, pero su preparación inserta filas.
Las asignaciones V2 tienen claves automáticas, aunque no se usarán en este plan.

Por tanto, DatabaseTransactions sobre salon_erp_test **no conserva íntegramente**
su estado: puede dejar iguales las filas y distintos los contadores/DDL observado.
Los guardias históricos comparan antes/después sin normalizar AUTO_INCREMENT.
No restablecerlo con ALTER TABLE ni limpiar fixtures en las tres bases existentes.

Las baselines AC-04/AC-05, cadenas de auditoría y borradores sintéticos deben
seguir intactos, incluidos contadores y hashes. La instancia nueva absorbe los
saltos de IDs. Para rollback allí se comparan filas/esquema lógico y nivel de
transacción, registrando separadamente los saltos de IDs como efecto permitido;
esa excepción **no se aplica** a ninguna baseline histórica.

## 6. Credenciales y permisos mínimos

- Cuenta administrativa nueva, solo en la instancia descartable, para inicializar
  y aplicar esquema. Separada de los procesos de pruebas; no crear roles o cuentas
  en el servidor histórico. Su alcance se limita mediante aislamiento de instancia.
- Runtime `h05_runner@127.0.0.1`, contraseña aleatoria nueva, solo
  SELECT/INSERT/UPDATE/DELETE sobre el esquema exclusivo. Sin DDL, FILE, PROCESS,
  SUPER, SYSTEM_USER, CREATE USER, GRANT OPTION, roles o privilegios globales,
  salvo el USAGE sin acceso a datos. Sin cuentas host `%` ni credenciales heredadas.
- Los grants de esquema deben ser exactos: comprobar el tratamiento de `_` como
  comodín según partial_revokes y SHOW GRANTS; no dar por literal un patrón por
  haberlo entrecomillado. Normalizar y verificar privilegios efectivos antes de
  autorizar escrituras. Este lector de grants aún no está implementado.
- Secretos fuera del código y del argv/log, archivo privado ACL del run o canal
  seguro al hijo. No leer/copiarlos de `.env` o `.env.testing` históricos. No
  habilitar credenciales administrativas en el runtime ni en sus reconexiones.

El runner debe abrir con DSN fijo, opciones sin persistencia, sin URL/socket/
read-write alternativos. Las consultas de verificación no requieren acceso a
los datos históricos. Un observador independiente de persistencia, si se usa,
también apunta exclusivamente a 3307/esquema nuevo y trabaja en sesión read only;
es una conexión explícita de verificación, no otra conexión de escritura Laravel.

## 7. Datos mínimos sintéticos

Todo dato será nuevo, identificable por run/caso, con emails `example.test` y
contraseñas/PIN ficticios. No se importan filas, IDs, adjuntos ni números de
documento reales, saldos históricos o borradores sintéticos de AC-05.

| Fixture | Contenido previsto |
| --- | --- |
| Identidad | Roles mínimos y usuarios alvaro, recepcion y estilista activos; caja abierta del actor; comisión simple del perfil, sin esquema escalonado para esta selección |
| Clientes | Cliente sin mora con límite suficiente, otro para crédito/bloqueo, grupo con 10% de descuento técnico |
| Catálogo comercial | Producto P con precio NIO 100.00; servicio S activo 200.00; variante propia 250.00; servicio ajeno y variante ajena/inactiva; IDs de items/services distintos |
| Inventario | Producto P: 5 unidades a costo 20.00; insumo fraccionable F: lote de capacidad 1000 y costo de envase 20.00, representación legacy 1; receta S consume 10 unidades de volumen por ejecución |
| Captura cero | Segundo insumo G con receta positiva para caso cero parcial y caso todos cero; guardar ambas cantidades explícitas |
| Contabilidad | Monedas NIO/USD, árbol mínimo y cuentas activas/imputables con códigos y metadatos del catálogo fuente: fondos 1.1.1/1.1.1.2/1.1.2.1/1.1.2.2; CxC 1.1.3; inventarios 1.1.4/1.1.5; ingresos 4.1/4.4; descuento 4.5; costos 5.1/5.2. Confirmar mapeo exacto antes de cargar |
| Períodos | Mes abierto de una fecha congelada válida; caso adicional sin período para verificar que la autogeneración también revierte |
| Divisas | USD y saldo de caja de prueba coherente cuando haya vuelto USD; tasa sintética declarada 36.50, no consulta a BCN |
| Agenda | Dos citas no solapadas con servicio/cliente/estilistas sintéticos: una relacionada y otra centinela no relacionada |

La preparación de stock debe dejar artículo, lotes y kárdex coherentes antes del
caso. Usar un cargador de fixtures explícito, revisado y limitado a la instancia,
no un segundo algoritmo de emisión. El stock de arranque es un estado técnico
sintético, no un documento real de apertura, aportación ni aprobación económica.
Registrar esa distinción y sus cantidades/costos en el manifiesto, sin fabricar
asientos de apertura ni usar las políticas pendientes AC-05. Si se exige además
una prueba de origen de compras, requiere ampliar la autorización de fixtures;
no se añadirá automáticamente el ciclo de CompraService/CxP a esta selección.

El cargador no invocará seeders generales. Puede tomar **definiciones de código**
de catálogos ya existentes para construir exclusivamente lo requerido. No copiar
filas empresariales ni alterar códigos/tipos para que cuadren. La aprobación
de estos ejemplos técnicos no aprueba balances ni clasificación AC-05.

ComisionService registra montos congelados en comisiones_generadas; no crea en
la emisión el asiento de nómina 5.3. Ese asiento y su contrapartida no forman
parte de las expectativas de venta ni se incorporan como fixture de nómina.

## 8. Escenarios de regresión propuestos

Todos usan el código real H05-R, kernel en proceso, autenticación/CSRF reales
con identidades sintéticas. Preparación y baseline de cada caso se confirman
antes de la petición; **sin una transacción exterior de DatabaseTransactions**
que convierta el commit del controlador en otro savepoint. Casos seriales,
reidentificados y sin suite general; no necesitan truncar tablas entre casos.

| Nº | Escenario | Fixtures y resultado esperado antes de limpiar/destruir |
| --- | --- | --- |
| 1 | Producto válido | P, caja, propietario, inventario/cuentas; quantity=1, subtotal=total=100.00, pago neto=100.00, stock -1, costo=20.00 |
| 2 | Servicio válido | S, F, fórmula, estilista; total=200.00, consumo 10 de volumen; ingreso y COGS real conforme al kárdex |
| 3 | Mixta | P+S: subtotal=300.00, separar mercadería/servicios y sus costos; pagos netos=total |
| 4 | Variante | Variante S=250.00 aceptada y fotografiada en detalle; variante ajena/inactiva rechazada sin filas nuevas |
| 5 | Descuento admitido | Mixta, discount=30.00 → total=270.00; bruto 300.00 y contra-ingreso 30.00. Caso grupo 10% omitido y explícito cero que prevalece |
| 6 | Precio manipulado | P a 0.01 frente a catálogo 100.00 → 422, sin nuevos efectos |
| 7 | Cantidad inválida | 0, -1, texto, 0.5 y precisión excesiva → 422, sin nuevos efectos |
| 8 | Crédito | Cliente válido: emitida, CxC=total, inventario aplicado, sin pagos iniciales. Caso límite/mora se rechaza; no inventar reglas nuevas |
| 9 | Borrador | Documento/detalles/consumos presentes; inventario, pagos, comisiones, asientos y agenda intactos; transformar solo borrador válido por ruta real |
| 10 | Retener/aprobar | Cajero genera pendiente con payload y notificación; sin efectos. Propietario/PIN aprueba: efectos una vez, validarDocumento, estado pagada y cita según contrato actual |
| 11 | Consumo real cero | Captura F=0/G>0: filas cero conservadas y solo G consumido según lector legacy. Caso F=0/G=0 debe demostrar ausencia de consumo; hoy tiene bloqueo descrito abajo, no cambiar expectativa |
| 12 | Pago mixto | Mixta total 300.00: USD efectivo 5.00 × 36.50=182.50 + BAC NIO 117.50; total neto=300.00. Caso de sobrepago/vuelto en NIO verifica libro neto; sin suplantar política cambiaria |
| 13 | Fallo tras inventario | Decorador de LibroPagosService falla antes de registrar pagos; verificar que stock/lotes/ledger ya cambiaron dentro de la transacción y después vuelven a baseline |
| 14 | Fallo tras pagos | Decorador ejecuta parent::registrar completo y luego falla; capturar existencia de pagos/divisas antes del fallo; después no quedan efectos de esa emisión |
| 15 | Fallo tras asiento | Decorador de ComisionService invocado después del servicio contable real constata asientos/líneas y cuadre, ejecuta parent y falla antes del commit exterior; también revierte comisiones |
| 16 | Rollback completo | Para 13–15: mismas filas/valores previos, ningún documento/consumo/pago/ledger/asiento huérfano; si se autogeneró período, no queda; nivel Laravel=0 y PDO sin transacción |
| 17 | Cita no relacionada | Hash de fila/pivote centinela igual en éxitos y fallos. La relacionada sigue únicamente el comportamiento vigente; no rediseñar H06 |
| 18 | Totales/pagos/asientos | Sum(quantity × unit_price)=subtotal, subtotal−discount=total; pagos netos NIO=total cuando pagada; sum(debe)=sum(haber) exactos por asiento y COGS=ledger; crédito/borrador/retención tienen expectativas propias |

Los importes son ejemplos sintéticos fijados antes de ejecutar. Si el contrato
actual produce otro resultado, registrar el fallo y detener ese escenario;
no editar precios, tasas, recetas, cuentas ni expectativas para obtener verde.

### Fallos controlados sin motor alternativo

Los decoradores son exclusivos del arnés y delegan al servicio real, o fallan
en un límite posterior identificado. No implementan su propia contabilidad,
inventario o pagos. Se instalarán con bindings del contenedor solo donde el código
actual ya usa `app(...)`; no añadir flags/hooks a producción para esta fase.
Contabilidad se instancia con `new`: el fallo posterior se inyecta en el siguiente
ComisionService, sin sustituir el motor contable.

Usar una excepción centinela distinguible y constatar el punto alcanzado mediante
lecturas en la transacción; no confundir un error de fixture anterior con éxito
del test de rollback. El controlador de ventas devuelve error genérico 500 para
Throwable; aprobación web puede redirigir con error. Assertar el contrato real
de cada ruta, sin equiparar toda redirección a éxito.

Después de la petición, verificar el nivel transaccional y un observador de solo
lectura separado en la **misma instancia descartable** para confirmar persistencia
de los éxitos y ausencia de efectos en los fallos. No consultar con ese observador
las bases protegidas. Capturar todas las tablas del esquema sintético, incluido
notifications, además de las esperadas; sin registrar contraseñas/PIN/hashes de login.

## 9. Protección contra destinos históricos

El bootstrap nuevo deberá ser independiente. No modificar Tests\TestCase,
TestDatabaseSafety, tests/bootstrap.php ni phpunit.xml actuales: fijan explícitamente
salon_erp_test/3306/usuario tester. No heredar ese TestCase ni definir constantes
para engañar sus guardias. Tampoco usar perfiles/launchers AC-04/AC-05 como permiso
de escritura para H05.

Controles exigidos, en orden:

1. Proceso hijo con entorno permitido explícito, sin credenciales históricas,
   DB_URL, MYSQL_PWD, APP_BASE_PATH ajeno ni variables que activen simulaciones.
   Código copiado con manifiesto, sin `.env*`, storage/cache históricos, uploads,
   dumps o secretos; usar vendor existente revisado sin instalar dependencias.
2. Archivos temporales/cache/session/log y APP_KEY públicos de prueba propios.
   SALON_TEST_DB_RESET_ALLOWED debe ser exactamente `0`; V2 debe ser false.
   No aceptar configuración/rutas cacheadas de otra instalación.
3. Solo conexión Laravel mysql con host 127.0.0.1, puerto 3307, esquema/usuario
   del plan, sin URL/socket/prefix/read/write/opciones alternativas; auditar
   conexiones ya resueltas y cada creación/reconexión antes de aceptar un PDO.
4. Crear la conexión restringida solo después de validar la configuración; antes
   de cualquier fixture/escritura consultar identidad y contrastarla con un
   manifiesto confiable de provisión, nunca con valores autodetectados como permiso:

```sql
-- CONSULTA FUTURA: no ejecutada en H05-V0.
SELECT DATABASE() AS database_name, CURRENT_USER() AS account_name,
       @@port AS server_port, @@server_uuid AS server_uuid,
       @@datadir AS data_directory, CURRENT_ROLE() AS role_name,
       VERSION() AS mysql_version;
```

5. Verificar grants efectivos, InnoDB, FKs exclusivamente locales, estructura
   exacta del artefacto H05, `foreign_key_checks=1`, versión, configuración de
   aislamiento y ausencia de triggers/rutinas/eventos. No admitir fallback a root,
   3306 o al antiguo esquema si falla la conexión/identidad.
6. Antes de DML, identificar proceso/UUID/datadir y demostrar restricción de red:
   PHP solo puede acceder a 127.0.0.1:3307; denegar 3306, otros endpoints y servicios
   remotos mediante aislamiento de proceso/OS a revisar en V1. No modificar el
   firewall compartido ni permisos del ERP sin autorización adicional. Si el
   mecanismo no está disponible, **no ejecutar**; APP_ENV y booleanos no lo sustituyen.
7. Rechazar llamadas a otros servicios/SQL calificado fuera del esquema. No usar
   expresiones regulares de SQL ni DB::listen como única protección: grants,
   credenciales, instancia separada y restricción de proceso son las barreras.
8. El runner nunca carga credenciales con acceso al servidor histórico. Una
   comprobación de preservación histórica, si se desea antes/después, debe ser
   otro procedimiento de solo lectura autorizado que no comparta sus credenciales
   con el runner. En V0 no se hicieron esos snapshots ni se verificó un backup SQL.

Preparación comprobada hoy: `H05DisposableSafety` contiene predicados **puros**
de configuración, identidad y un inventario de grants ya normalizado.
`H05DisposableSafetyTest` usa mocks: **33 pruebas, 55 aserciones aprobadas**.
Rechaza las tres bases protegidas, 3306, root, redirecciones, conexiones extra,
Redis, V2/reset habilitados, datadir/UUID/cuenta inesperados y privilegios amplios.

No es un bootstrap ejecutable, un conector, un lector SHOW GRANTS ni una barrera
del sistema operativo. `external_network=false` es un requisito del plan, no
evidencia de un bloqueo aplicado. Faltan las verificaciones operativas enumeradas.
El guard no se conecta y no puede crear datos. No hay autorización de escritura
porque una matriz simulada haya sido aceptada por el predicado.

## 10. Comandos propuestos y comandos ejecutados

### Futuros: NO ejecutados, requieren autorización H05-V1

Fragmentos orientativos después de crear y revisar configuración/ACL/manifiesto.
No son una receta actualmente lista ni un script ya existente. No incluyen
contraseñas ni autorizan CREATE/GRANT/importación.

```powershell
# Inicializar ÚNICAMENTE datadir nuevo con my.ini exclusivo ya aprobado.
& 'E:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqld.exe' --defaults-file=E:/salon-erp-h05-disposable/run-h05v1/mysql/my.ini --initialize

# Arranque futuro, sin registrar servicio ni mostrar una consola.
Start-Process -FilePath 'E:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqld.exe' -ArgumentList '--defaults-file=E:/salon-erp-h05-disposable/run-h05v1/mysql/my.ini' -WindowStyle Hidden -PassThru

# Interfaz PROPUESTA: el runner no existe aún y debe implementarse/revisarse.
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' 'E:/salon-erp-h05-disposable/run-h05v1/h05-v1-runner.php' preflight
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' 'E:/salon-erp-h05-disposable/run-h05v1/h05-v1-runner.php' fixtures
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' 'E:/salon-erp-h05-disposable/run-h05v1/h05-v1-runner.php' scenarios
```

La fase administrativa de CREATE DATABASE/USER/GRANT y aplicación del DDL se
preparará como artefacto separado, limitada al UUID/datadir nuevo. El runtime
no dispondrá de esa cuenta. `--defaults-file` debe ocupar su posición requerida;
my.ini debe dirigir también los logs de inicialización a una ruta privada, pues
pueden contener la contraseña administrativa temporal. No imprimir esos logs.

No usar composer test, artisan test con configuración ordinaria, migrate,
migrate:fresh, db:wipe ni ningún launcher histórico. No ejecutar el comando
de escenarios hasta completar todos los controles de preflight y autorización.

### Ejecutados en V0

Solo inspección de archivos/metadata/listeners/Git de lectura, consulta de la
documentación oficial MySQL y las pruebas puras siguientes:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d memory_limit=128M -d max_execution_time=30 -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result --no-progress tests/Unit/H05DisposableSafetyTest.php
```

Resultado **33/33, 55 aserciones, código 0**. Sin bootstrap Laravel, .env,
conexión SQL ni escenarios de emisión. Lint de los dos archivos PHP nuevos y
`git diff --check` completan la comprobación local de esta preparación.

## 11. Criterios de aceptación, aborto y destrucción posterior

Para declarar integrada H05 en V1 deberán existir:

- Identidad de instancia/configuración/ACL/red/grants y perfil de esquema
  verificados antes de escribir; manifiesto de código y fixtures con hashes.
- Ejecuciones reales de éxito y fallos 1–18 con evidencia del punto alcanzado,
  filas persistidas en éxitos, rollback observado desde conexión independiente,
  cero transacciones abiertas y cuadre exacto de cabecera/detalles/libro/asientos.
- Separación de casos bloqueados y fallos reales; **no** declarar 18/18 si el
  caso de todos los consumos cero falla o no puede acreditarse sin cambiar V2.
- Ninguna escritura/acceso del runner a las tres bases existentes: destino único,
  red/grants efectivos y trazabilidad de conexiones, sin secretos históricos.
  La ausencia de cambios no se inferirá únicamente de conteos iguales.
- Informe de resultados, limitaciones y residuos locales. Sin decisiones
  económicas AC-05 ni modificaciones de H06/H04 para completar el ensayo.

Abortar ante identidad/ruta/UUID/grants/perfil inesperados, cachés ajenas,
credenciales heredadas, intento de conexión adicional, tabla no transaccional,
V2 activo, SQL calificado externo, job/efecto externo, DDL durante escenarios,
transacción perdida o fixture/evidencia cuya procedencia no sea verificable.
No probar otro destino, relajar guardias ni reconstruir automáticamente tras fallo.
Conservar evidencia sintética del run; no efectuar limpieza destructiva automática.

**Destrucción futura: autorización independiente.** Después de revisar/exportar
solo resultados mínimos sintéticos, solicitar aprobación para detener el PID
exacto asociado a UUID/datadir y eliminar únicamente la raíz de run. Antes de
eliminar, resolver ruta absoluta, comprobar padre permitido, ausencia de enlaces
y que no sea un datadir/config/directorio del ERP o Laragon. Usar PowerShell
con rutas literales y un solo shell; no lanzar borrado recursivo por patrones.
No DROP DATABASE en 3306, no resetear contadores históricos, no restaurar backups.
No se incluye aquí un comando ejecutable de eliminación para evitar confundirlo
con la autorización de preparación o con una limpieza automática.

## 12. Bloqueos y límites concretos

1. V0 no autoriza provisión ni escritura. No existen todavía instancia, DDL H05
   generado/aprobado, cuentas, secretos, bootstrap, runner SQL o fixtures revisados.
2. Falta demostrar la restricción de proceso/OS hacia 3306 y la separación de
   secretos/rutas. No se propone eludir permisos del sistema con otro mecanismo.
3. Captura F=0/G=0: con V2 false, InventarioService legacy vuelve a receta si no
   obtiene consumos positivos. El éxito físico «cero consumo» no puede certificarse
   con ese contrato. Se preparará como detector del fallo, no como verde esperado.
   No activar V2 ni renombrar el esquema/cuenta para pasar su puerta rígida.
4. H06 mantiene diferencias entre facturación directa y POS, y líneas repetidas
   de cita a precio completo. Probar compatibilidad observada; no cambiar unidad
   de ejecución, receta o H04 ni atribuir al ensayo una corrección de H06.
5. No se verificó equivalencia del esquema vivo actual con el manifiesto: solo
   origen versionado/hash. Antes de V1 validar que ese DDL contiene los contratos
   que el código actual exige, sin migrar las tres bases para acomodarlo.
6. Casos de USD, vuelto y comisiones pueden revelar defectos heredados. No ajustar
   tasas, cuentas, configuración de aprobación o precios para hacerlos pasar.
7. No hay nuevos hashes/baselines SQL ni comprobación del respaldo aprobado en
   esta fase. El aislamiento evita usar las bases protegidas como área de ensayo;
   un control de preservación con lecturas históricas requiere proceso separado.

## 13. Archivos modificados

Tres archivos nuevos exclusivamente:

1. `docs/H05_V0_PLAN_SQL_AISLADO.md`.
2. `tests/Support/H05DisposableSafety.php` — predicados del plan, sin I/O SQL.
3. `tests/Unit/H05DisposableSafetyTest.php` — pruebas puras con mocks.

Sin cambios de código funcional, bootstrap existente, .env, perfiles/baselines,
manifiesto AC-05, migraciones, permisos o registros SQL. Ninguna base o entorno
descartable fue creado. No se ejecutaron commits ni otras escrituras Git.

## 14. Autorización exacta necesaria para H05-V1

La siguiente fase requiere una instrucción explícita equivalente a:

> Autorizo implementar y revisar el bootstrap/runner H05-V1 y crear una instancia
> MySQL 8.4.3 descartable en E:/salon-erp-h05-disposable/run-h05v1, exclusivamente
> en 127.0.0.1:3307, con datadir/configuración/logs/cuentas nuevos. Autorizo crear
> solo salon_erp_h05_disposable en esa instancia y cuentas locales separadas de
> provisión/runtime, aplicar un artefacto DDL vacío revisado derivado del manifiesto
> indicado, cargar los fixtures sintéticos mínimos aprobados y ejecutar únicamente
> los escenarios H05 listados, con commits y rollback reales allí. No autorizo
> acceso del runner ni cambios a salon_erp, salon_erp_test o salon_erp_bootstrap_check,
> cambios económicos AC-05, activar V2 o modificar H06/H04. Cualquier control OS
> necesario fuera de la raíz o ajuste de permisos requiere aprobación específica.
> La destrucción del entorno se autorizará por separado tras revisar resultados.

Esta formulación es una **propuesta para aprobación posterior**, no una solicitud
de permiso para continuar dentro de V0 ni autorización ya recibida.

H05-V0 termina aquí, con el plan y guardias simulados. No se ejecuta H05-V1.
