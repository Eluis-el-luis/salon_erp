# H05-R — Validación del carrito y creación de ventas

Fecha: 20 de noviembre de 2026. Alcance exclusivo H05. No se ejecutaron SQL,
migraciones, seeders, reconstrucciones, restauraciones ni operaciones Git de escritura.

## 1. Estado actual del hallazgo

H05 seguía parcialmente vigente. `VentaController::store` validaba el contenedor
`cart`, consumos y pagos, pero no los campos esenciales de cada línea. Calculaba
`subtotal` mediante `precio_c * quantity` recibido y copiaba ambos valores al
detalle. La pertenencia de variante se comprobaba, pero no su precio ni actividad.
El descuento tenía mínimo cero, sin límite por subtotal, y el total se truncaba
a cero cuando el descuento lo superaba.

Las evoluciones H02/H03 ya protegían algunas cantidades en inventario y el
contrato V2 de composición. No equivalían a validación universal del carrito:
`InventarioService::bloquearCarrito` verificaba unidades para insumos/artículos
no fraccionables; `CapturaConsumoService::foto` requería unidades positivas
cuando su puerta V2 estaba activa. No se eliminan ni duplican esas protecciones.

Fuentes contrastadas: auditoría original H05, VentaController, AprobacionService,
Venta/DetalleVenta, catálogos, POS, LibroPagosService e InventarioService;
documentos H03-B2, H04-A/H04-B, H15/H16 y pruebas de ciclo de vida, pagos mixtos,
clientes/servicios, cadena de suministro y vínculos de citas. No había un documento
de corrección H05 previo en docs. Se respetaron H12-R y las entregas AC-04/AC-05.

## 2. Flujos identificados

| Entrada | Método/caso de uso | Contrato |
| --- | --- | --- |
| POST `/sales` | `VentaController::store` | Carrito POS: pagar, cotizar, crédito; también transformación de borrador |
| POST `/sales/bill-appointment/{appointment}` | `facturarCita` | Facturación directa de cita; genera sus detalles desde servidor, sin carrito HTTP |
| Agenda → `/pos?appointment_id=...` | `posSystem.init` → `/sales` | Servicio de cita; reparto del precio por estilista |
| POST `/aprobaciones/venta/{id}/aprobar` | `AprobacionService::aprobarVenta` | Aplica efectos de documento retenido ya persistido |
| POST `/ventas/{id}/cobrar` | `VentaController::cobrar` | Cobra crédito existente; no reconstruye carrito ni cambia precios |
| GET `/ventas/{id}/documento` | `documento` | Hidrata POS con precios guardados, para borrador/cobro o consulta |

`/sales`, facturación directa y cobro pertenecen al grupo web+auth y
`role:recepcion,contador,admin` (bypass vigente de alvaro). Aprobaciones pertenece
a `role:alvaro` y conserva su confirmación por PIN. No se modificaron rutas ni
permisos. No existe un servicio único de creación de ventas en el código previo;
no se intentó unificar los flujos de H06.

## 3. Matriz de invariantes

A = navegador; B = validación HTTP; C = caso de uso; D = esquema versionado SQL.
La columna D describe migraciones inspeccionadas, no una inspección del servidor SQL.

| Invariante | A actual | B/C antes | Corrección B/C | D |
| --- | --- | --- | --- | --- |
| Carrito no vacío y lista de líneas | Botón deshabilitado vacío | Solo array requerido | Array/lista no vacía; cada fila array | No existe restricción de documento no vacío |
| Tipo | Selectores generan servicio/producto | Comparación `servicio`, lo demás producto | Enumeración y catálogo correspondiente | FKs no comprueban tipo comercial |
| ID y existencia | Catálogo/URL | Sin validación universal por línea | ID entero positivo y búsqueda del catálogo correcto | FKs separadas items/services |
| Estado vendible | Servicios activos; productos no servicio | No universal | Servicio/variante activos, producto existente no archivado y tipo físico permitido | Sin CHECK comercial equivalente |
| Variante | Selector de variantes activas | Solo pertenencia al servicio | Existencia, actividad, pertenencia y precio | FK de variante no prueba pertenencia al servicio del detalle |
| Cantidad | Incrementos enteros | Guardias parciales de inventario/V2 | Entera positiva y representable, en HTTP y servicio | Integer firmado; no garantiza positivo |
| Precio | Se copia de catálogo/variante; no editor manual | Se confiaba en `precio_c` | Escala/rango y coincidencia con catálogo de servidor | DECIMAL(10,2), sin regla de precio autorizado |
| Descuento | Campo no negativo | Mínimo cero | Centavos; no supera subtotal; grupo calculado exactamente | DECIMAL(10,2), sin relación CHECK con subtotal |
| Subtotal/total | Cálculo para visualización | Multiplicación/suma flotante del payload | Centavos enteros, límite y total calculado por servidor | DECIMAL(10,2), sin cuadre automático de cabecera/detalles |
| Impuestos | Plantilla fiscal como metadato | No IVA de venta en este flujo | Sin cambios; no se aceptan impuestos del carrito como autoridad | No se añadió columna ni tratamiento fiscal |
| Consumo real | Editor admite cero y hasta 2 decimales | Validación y pertenencia posteriores; V2 más estricto | Lista, pertenencia, unicidad, precisión y campos permitidos antes de crear | FK/decimales no validan receta completa |
| Campos protegidos | No se envían por UI | Persistencia explícita, pero lectura de carrito crudo | Se filtra cada fila y consumo; cabecera sigue con campos explícitos | Fillable no sustituye validación |

`CarritoVentaService::reglas` comparte las reglas estructurales entre HTTP y
`preparar`. El servicio vuelve a validarlas para consumidores internos; la
autoridad de precio y el cálculo económico están en ese servicio, no duplicados
en controlador. Las excepciones de validación/domain se traducen a 422.

## 4. Fallos reproducidos y nivel de evidencia

- Caracterización pura del límite original: las reglas relevantes admitían
  una línea con quantity=-1 y precio_c=0.01; la expresión antigua producía -0.01.
  La nueva validación rechaza el mismo payload. No se afirma haber persistido
  esa venta ni demostrado una entrada de stock en una base real.
- Precios alterados, negativos, de precisión excesiva o fuera de rango; cantidad
  cero/negativa/fraccionaria/no numérica/no finita; tipos e IDs incompatibles;
  variantes ajenas/inactivas/inexistentes; descuento superior a subtotal: se
  rechazan mediante reglas/caso de uso reales con catálogos en memoria.
- El reparto anterior de C$100 entre tres estilistas generaba 33.33 por línea:
  99.99 de detalle frente a 100 de precio base. El cálculo y la prueba frontend
  actuales conservan 33.34 + 33.33 + 33.33.
- La nueva comprobación de aprobación rechaza detalles/cabeceras incoherentes
  antes de inventario y sin cambiar el precio guardado.

La reproducción es local y sin SQL. No se probaron ataques contra el servidor
del ERP ni se emitieron documentos reales para obtener evidencia.

## 5. Correcciones realizadas

1. Nuevo `CarritoVentaService`: reglas estructurales, resolución de catálogo,
   comprobación económica, normalización y totales exactos.
2. `VentaController::store` usa exclusivamente el carrito normalizado y sus
   importes. Ya no obtiene subtotal/precio de campos crudos para persistir.
   Se retiró el cálculo flotante duplicado de descuento por grupo.
3. `bloquearCarrito` y la preparación de composición se ejecutan antes de crear
   venta/detalles o reemplazar líneas del borrador. Se conserva el orden
   venta → usuarios/artículos al bloquear un borrador existente.
4. `AprobacionService::aprobarVenta` comprueba cantidades, identidad exclusiva
   item/service, precios guardados y cuadre del documento antes de efectos.
5. En `facturarCita`, descuento acotado y reparto exacto del precio del servicio;
   se rechaza servicio ausente/inactivo. Es una corrección de importes de H05;
   no se cambió la ejecución/consumo de H06, pagos ni transiciones de agenda.
6. POS reparte los centavos igual que el servidor al precargar una cita.

No hay dependencias, migraciones, cuentas ni cambios de política de inventario.

## 6. Política de precios encontrada y aplicada

No se encontró editor de precio unitario en el POS, permiso de override,
auditoría de precio manual ni política que lo autorice. `Como_trabajar.md`
indica no confiar en montos del frontend. Aceptar un precio distinto por el
solo hecho de enviar un request no constituye una autorización.

Se adopta la opción conservadora indicada por el encargo: producto a
`items.precio_c`, servicio a `services.price` o `service_variants.price` activa
y perteneciente. `precio_usd` no es la base del carrito: sus importes están en
NIO, aunque los pagos sean USD. Un precio recibido distinto se rechaza, incluso
si lo envía un supervisor; no se creó un permiso manual nuevo. Si el catálogo
cambió desde que se abrió POS, se requiere recargar el artículo/servicio.

El catálogo se lee con sus valores originales SQL (`getRawOriginal`), evitando
que los casts float de Servicio/Variante/DetalleVenta sean la autoridad del
cálculo nuevo. La comparación y suma usan centavos enteros. Se reutiliza
`InventoryDecimal`, incluido su redondeo decimal mitad hacia arriba para el
porcentaje de grupo. Los límites corresponden a DECIMAL(10,2): C$99,999,999.99.

El descuento monetario explícito continúa permitido, incluido cero, y prevalece
sobre el grupo; NULL/ausencia usa su porcentaje. Se admite descuento hasta el
100% del subtotal y precio cero si así figura en el catálogo. No se introdujo
un mínimo comercial ni autorización de descuento que no existiera.

`validarDocumento` de aprobación verifica los importes congelados sin buscar
precios actuales. El cobro/consulta de documentos emitidos no se cambió. Un
borrador sometido nuevamente a creación/emisión se valida contra catálogo vigente;
no se reescriben documentos históricos automáticamente.

## 7. Política de cantidades

- Productos y servicios se venden por número entero de unidades/ejecuciones,
  coherente con `sale_details.quantity` integer y los incrementos del POS.
- Rango: 1 a 2,147,483,647; además el importe total debe caber en la factura.
  El stock disponible conserva sus comprobaciones de inventario existentes.
- Se aceptan representaciones exactamente enteras como `1.0`, normalizadas a 1.
  No se redondean/truncan fracciones; 0.5 o 1.001 son inválidas.
- NaN/Infinity numéricos, sus cadenas, exponentes, booleanos, arrays y texto
  no son cantidades/precios decimales ordinarios válidos.
- Un producto fraccionable vendido directamente sigue siendo un envase/unidad
  entera. Su consumo interno en recetas conserva la escala de medida existente.
- Servicios ordinarios pueden acumular cantidad 2 o mayor, como hace la UI.
  El reparto parcial de precio de una cita usa cantidad 1 por estilista.
- Una cantidad negativa nunca se interpreta como devolución. Anular/restituir
  conserva sus rutas y reglas propias, sin modificaciones de H04.

## 8. Compatibilidad de productos, servicios y citas

Los tipos de línea admitidos son `servicio`, `producto` (POS) y los alias físicos
`producto_venta`/`producto_interno` usados por pruebas/consumidores anteriores.
Estos últimos deben coincidir con el tipo del catálogo; se normalizan a producto.

El selector actual de POS incluye tanto producto_venta como producto_interno,
incluso fraccionables. H03-B2 también documenta su venta directa. Se conserva
esa capacidad: no se declaró unilateralmente invendible todo insumo interno.
Un item de tipo servicio o archivado sí es invendible como producto físico.
No existe un campo is_active de artículos equivalente al de servicios.

Las variantes pertenecen a **servicios**, no a productos físicos. El caso
solicitado de «variante perteneciente al producto» se aplica al servicio con
variante comercial real; enviar variante de servicio en un producto se rechaza.

Para citas, se valida la identidad del servicio. El reparto parcial solo es
válido si sus cantidades/precios corresponden al reparto exacto y sus líneas
incluyen una vez a cada estilista asignado. Se conserva aparte el contrato H16
de varias líneas completas a precio de catálogo vinculadas a una misma cita;
no se rediseña aquí la política de ejecución/facturación de H06. La expectativa
existente de `AppointmentSaleLinksTest` de dos líneas completas se conserva y
tiene una comprobación pura adicional. No se alteraron pruebas históricas para
adaptarlas a resultados económicos nuevos.

## 9. Validación en servidor y consumos

La forma del carrito se valida en el límite HTTP y en `preparar`. Los IDs se
resuelven contra su catálogo; la variante y el precio se verifican antes de
persistir. Los colaboradores deben existir; no se añadió una nueva política
de disponibilidad/roles de estilistas.

Consumos explícitos deben ser únicos, corresponder a la fórmula disponible y
tener cantidades no negativas, con hasta dos decimales en fraccionables y
unidades enteras en insumos indivisibles. Los ceros se conservan como valores
válidos, no como ausencia. Se filtran campos protegidos de cada fila de consumo.
La puerta V2, su exigencia de captura completa y la comprobación de referencia
de composición siguen en CapturaConsumoService/InventarioService.

**Límite heredado preservado:** con V2 desactivado, el lector legacy de
InventarioService puede volver a la fórmula estándar cuando todos los consumos
guardados son cero. H03 documenta la solución V2, que sigue restringida a testing.
H05-R comprueba la aceptación/transmisión del cero y no certifica su aplicación
física correcta por ese lector legacy. No se activó V2 ni se cambió inventario
para ocultar esta limitación.

## 10. Atomicidad y efectos secundarios

El controlador conserva la propiedad de la transacción. Antes de crear venta,
detalles, consumo o actualizar/borrar líneas del borrador se ejecutan las reglas
económicas y los contratos de `bloquearCarrito`. Agenda adquiere sus mutex con
el orden anterior. Se añade rollback y respuesta 422 para ValidationException
producida dentro de la preparación; las excepciones de dominio ya usaban 422.

Una solicitud inválida estructuralmente no inicia transacción. Un rechazo de
dominio después de las lecturas/bloqueos revierte la transacción sin crear la
venta. No se añadieron commits ni escrituras desde el servicio de carrito.

La prueba del controlador real prohíbe llamadas create de venta/detalles,
pagos, consumos, kárdex, comisiones y asientos, y prohíbe completar cita. La
prueba de aprobación ejecuta el servicio real con documento inválido y comprueba
que no llega a inventario/escrituras. Son dobles de persistencia; no demuestran
un rollback real de MySQL ni todos los interleavings de concurrencia.

Las etapas posteriores de inventario, pagos, comisiones, contabilidad y agenda
permanecen en la transacción existente. Su éxito/rollback integrado requiere el
ensayo SQL separado descrito a continuación, no ejecutado en esta intervención.

## 11. Pruebas y resultados

PHP 8.3.30, ejecución exclusiva sin phpunit.xml, tests/bootstrap.php ni .env.testing:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d memory_limit=128M -d max_execution_time=45 -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result --no-progress tests/Unit/CarritoVentaTest.php tests/Unit/CarritoVentaHttpTest.php
```

Resultado: **53 pruebas aprobadas, 99 aserciones**, código 0. Incluye datasets.

| Grupo | Alcance |
| --- | --- |
| Puras de dominio | Productos/servicios/mixtas, aliases, variantes propias/ajenas/inactivas/ausentes, cantidades inválidas y límites, precios negativos/manipulados, IDs, actividad/archivo, campos protegidos, consumo cero/ajeno/duplicado, descuento explícito/grupo, desbordamiento y totales exactos |
| Aprobación con dobles | Precio congelado distinto del catálogo conservado; detalles/cabecera inválidos bloqueados antes de efectos |
| Límite HTTP aislado | VentaController real, Request/Validator/JsonResponse reales; caja y dominio simulados, persistencia prohibida, siete payloads rechazados con 422 |

No se presenta la última prueba como petición a un servidor ni como prueba
con kernel, autenticación o SQL. No se afirma alta HTTP exitosa integrada:
los casos válidos se ejercitan en dominio y consumidor frontend.

```powershell
node --test tests/Frontend/pos-cart.test.cjs tests/Frontend/pos-consumption.test.cjs
```

Resultado: **12/12 aprobadas**, código 0. Ejecuta el JavaScript real del POS en
VM con dobles DOM/HTTP. Comprueba productos/servicio/variante, payload, CSRF,
sesión, consumo cero, error de precio conservando carrito, reparto de cita y
cobro que envía solo pagos sin reconstruir precios históricos. No hay red ni
navegador real.

Los primeros ensayos detectaron dos defectos del arnés (originales Eloquent
sin sincronizar y mock auth sin contrato/tipado), y un caso de implementación:
NaN/Infinity debían detener la validación antes de la regla min numérica de
Laravel. Se añadió `bail` y se repitió la selección focalizada; el resultado
arriba corresponde a la versión corregida. Se validó sintaxis de los cinco
archivos PHP de la entrega y `git diff --check`.

No se ejecutaron las pruebas SQL históricas de VentaCicloVida, VentaPagosMixtos,
AdminClientesServicios, AppointmentSaleLinks o inventario, ni suites generales.
No se cambiaron sus expectativas ni sus fixtures económicos.

## 12. Pruebas SQL pendientes y preservación de bases

No se abrió ninguna conexión SQL: ni siquiera preflight, SHOW, snapshots o
respaldo. No se leyó/modificó .env.testing ni su indicador de reset. Las pruebas
usan únicamente vendor/autoload y objetos en memoria, con resolver Eloquent
que prohíbe obtener una conexión y dobles de DB donde corresponde.

`salon_erp`, `salon_erp_bootstrap_check` y la evidencia íntegra AC-04/AC-05 de
`salon_erp_test` quedaron fuera de ejecución. No se afirma igualdad de hashes
medida hoy: la preservación se basa en no acceder a esas bases. No hubo cambios
de esquema, permisos, datos históricos ni archivos de evidencia.

Plan SQL, **pendiente de autorización específica**, sin ejecutarlo automáticamente:

1. Verificar cuenta restringida, base/puerto/grants, perfil de esquema vigente
   y `SALON_TEST_DB_RESET_ALLOWED=0`; detener si difieren de lo aprobado.
2. Comprobar disponibilidad y SHA256 del respaldo aprobado e integridad de los
   registros históricos protegidos antes de cualquier fixture. No reutilizar
   un backup antiguo sin confirmar su idoneidad para la evidencia vigente.
3. Inspeccionar los efectos de cada prueba seleccionada. Preparar casos nuevos
   con DatabaseTransactions y fixtures identificables, sin RefreshDatabase,
   DDL, seeders, truncado ni limpieza de evidencia.
4. Ensayar éxito de producto/servicio/mixta/variante/descuento en pagar, crédito,
   borrador y retención/aprobación; comprobar total/detalles/pagos/asiento y las
   cantidades físicas con la política vigente, sin cambiar costos.
5. Ensayar todos los rechazos antes de efectos y fallos posteriores forzados;
   comprobar que ventas, detalles, ledger, consumos, pagos, comisiones, asientos
   y estados de citas/borradores conservan el estado previo después del rollback.
6. Revertir todas las transacciones y comprobar nuevamente la evidencia histórica.
   No asumir que AUTO_INCREMENT revierte; revisar esa consecuencia antes de
   autorizar escrituras en el esquema que conserva evidencia.

## 13. Archivos modificados

- `app/Services/CarritoVentaService.php` — nuevo validador y cálculo del carrito.
- `app/Http/Controllers/VentaController.php` — integración y corrección mínima de importes de cita.
- `app/Services/AprobacionService.php` — comprobación del documento antes de efectos.
- `resources/views/sales/pos.blade.php` — reparto exacto al precargar cita.
- `tests/Unit/CarritoVentaTest.php` — pruebas puras y aprobación con dobles.
- `tests/Unit/CarritoVentaHttpTest.php` — rechazo HTTP aislado sin persistencia.
- `tests/Frontend/pos-cart.test.cjs` — contrato del consumidor POS.
- `docs/H05_R_VALIDACION_CARRITO.md` — este informe.

No se modificaron modelos, migraciones, inventario, LibroPagosService,
contabilidad, bancos, nómina, CxP, conciliación, agenda, anulaciones ni evidencia.
No se crearon commits ni se alteró el índice Git.

## 14. Riesgos restantes

- Falta la regresión SQL autorizada de éxito y atomicidad real. Las pruebas
  en memoria no acreditan comportamiento del servidor MySQL ni concurrencia.
- Un documento retenido antiguo incoherente quedará bloqueado al aprobar;
  no se normaliza ni revaloriza en silencio. Requiere revisión del documento.
- Un consumidor externo que enviara precios libres sin política autorizada
  recibirá 422. No se identificó consumidor legítimo interno de precio manual.
- Las líneas completas repetidas de una cita siguen admitidas por compatibilidad
  H16. La unidad de ejecución/consumo entre estilistas es materia H06, no resuelta.
- La captura cero tiene la limitación legacy descrita en §9; no se certifica
  su efecto físico fuera del contrato V2 ya existente.
- Los pagos/divisas conservan interfaces float heredadas. La autoridad nueva
  de subtotal/descuento/total es exacta; no se auditó ni rediseñó aquí la
  conversión, cobertura o vuelto de H07/H09.
- La validación usa una lectura de catálogo dentro de la transacción y los
  mutex existentes de agenda/inventario. No se declara una prueba nueva de
  carreras contra cambios concurrentes de tarifas o recetas.

## 15. Recomendación de continuación

Revisar el diff y decidir si se autoriza el ensayo SQL transaccional descrito,
con preservación demostrable de evidencia y sin ejecutar las suites históricas
destructivas. Cualquier política de precio manual requiere autorización explícita,
permisos y trazabilidad antes de implementarse.

H05-R se detiene con esta entrega. No se inicia H06, H07, H09, una intervención
contable, migración, activación V2 ni despliegue.
