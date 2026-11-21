# H06-P1 — Implementación aislada de prestación compartida

Fecha: 21 de noviembre de 2026. **Código prospectivo implementado con puerta cerrada. Migración preparada, NO ejecutada. No habilitado para operaciones normales.** La validación MySQL, la integración física H03/H04 y la habilitación empresarial siguen pendientes.

Referencias revisadas: [H06-P](H06_P_MODELO_PRESTACION_COMPARTIDA.md), [H06-R](H06_R_FACTURACION_CITAS.md), [H05-R](H05_R_VALIDACION_CARRITO.md), [H03-B2](H03_B2_IMPLEMENTACION.md), [H04-B](H04_B_REVERSIBILIDAD.md), [H16](H16_DISPONIBILIDAD.md) y [AGENTS.md](../AGENTS.md). Diagnóstico de estructura basado exclusivamente en archivos versionados, sin consultar las bases.

## 1. Alcance mínimo aprobado

Se implementa alternativa A: `sale_details` es el ancla canónica de prestación, comercio y fotografía; `service_participations` representa participantes independientes. No hay otro motor de inventario, pagos, contabilidad o nómina. No se activó V2 ni se cambiaron fórmulas, tasas, cuentas o políticas AC-05.

Revisión de **todos** los campos/objetos propuestos antes de cerrar la versión:

| Objeto/campo | Clasificación y decisión |
| --- | --- |
| `sales.service_contract_version` | Obligatorio: NULL legacy, 1 contrato nuevo; versiones desconocidas rechazadas |
| `sales.creation_key` + UNIQUE | Obligatorio: identidad de creación persistente; no se sustituye por estado de cita |
| `sales.creation_request_hash` | Obligatorio: misma clave con otra intención produce conflicto |
| `sales.document_revision` | Obligatorio para edición compatible: revisión optimista bajo lock de venta |
| `sales.legacy_review` | Aplazable: no se crea. No existe en esta fase escritor de clasificación/reconciliación legacy. Las propuestas antiguas no se convierten; sus ambigüedades quedan documentadas para revisión autorizada. No se promete una cola persistente de revisión |
| `sale_details.performance_key` + UNIQUE | Obligatorio: identidad estable de cada prestación; no se reutiliza al sustituir propuesta |
| `sale_details.appointment_id` + FK | Obligatorio para trazabilidad de línea mixta y validación contra cabecera |
| `commercial_identity_snapshot` | Necesario para compatibilidad histórica: ID/nombre de servicio y variante. Sobrevive al cambio de nombre o borrado de variante con FK NULL |
| UNIQUE `(id,sale_id)` en detalle | Obligatorio como soporte de pertenencia con FK compuesta |
| `service_participations.id`, `sale_detail_id`, `sale_id`, `user_id` | Obligatorios. `sale_id` es redundancia controlada exclusivamente para las FK compuestas; usuario sigue siendo `users.id`, como comisiones actuales |
| UNIQUE `(sale_detail_id,user_id)` | Obligatorio: el participante puede estar en varias prestaciones, una sola vez en cada una |
| UNIQUE `(id,sale_id,user_id)` + FK `(sale_detail_id,sale_id)` | Obligatorios para vincular comisión al usuario/venta del detalle correcto |
| `commission_resolution_snapshot` | Necesario para procedencia y resultado cero; NULL en propuesta, congelado al aplicar |
| Timestamps de participación | Necesarios para convención y trazabilidad de propuesta |
| `comisiones_generadas.service_participation_id`, UNIQUE y FK compuesta | Obligatorios: resultado económico único por participante, perteneciente al mismo usuario y venta |
| Otra cantidad de ejecuciones, precio, base o tabla de fotografía | Redundantes: se reutilizan `quantity`, `unit_price`, base congelada de comisión y snapshot H03 |
| Nuevos enlaces de stock/anulación por prestación | Redundantes: detalle → kárdex/asignaciones y evidencia H04 ya conservan el origen |
| Tabla independiente de prestaciones/estados financieros de participante | Aplazables, innecesarios para A; no se introducen |

## 2. Diferencias respecto de H06-P

Se conserva el diseño de integridad, claves, revisiones y FK. La única columna candidata diferida es `legacy_review`: añadirla sin un caso autorizado de revisión no resuelve ambigüedad. No se transforma ni se aplica automáticamente un documento heredado mediante el contrato nuevo.

El DTO se implementa como arrays normalizados por `PrestacionCompartidaService::entrada`, siguiendo el contrato de arrays del carrito existente: versión/clave/revisión, filas canónicas y listas de IDs de participantes. No acepta porcentajes, comisiones o capturas individuales aportados como autoridad por el cliente. H05 sigue validando catálogo, precio, unidades, variante y descuento. El servicio compartido añade identidad, pertenencia y tratamiento documental; no duplica el cálculo monetario H05.

La puerta exige además entorno `testing`: no existe habilitación de producción en esta entrega. `sql_verified` es una condición cerrada de autorización, **no** una certificación automática de SQL.

Para borradores se compararon A y B. B, retirada en el mismo documento sin perder el ancla, exigiría estado de retirada, reglas adicionales en sumadores/lectores y conservación separada de la línea comercial. Se conserva A: cancelación explícita de propuesta y creación de otra. No se disfraza una retirada mediante cantidad/precio cero ni se reconstruyen los otros detalles.

## 3. Archivos funcionales modificados

| Archivo | Responsabilidad H06-P1 |
| --- | --- |
| `app/Services/PrestacionCompartidaService.php` — nuevo | DTO, puerta, huella, coordinación, validación, edición, persistencia de identidad/participantes y lectura de metadatos |
| `app/Models/ServiceParticipation.php` — nuevo | Participantes y procedencia congelada de resolución |
| `app/Models/DetalleVenta.php` | Relaciones, snapshot comercial y nombre documental dual |
| `app/Models/Venta.php` | Caché efímera de resoluciones bajo locks; no columna ni otro importe persistido |
| `app/Models/ComisionGenerada.php` | Enlace nullable a participante, sin cambiar campos históricos |
| `app/Services/ComisionService.php` | Rama nueva dentro del servicio existente; bruto completo, decimal exacto, esquema/rango/perfil y congelación |
| `app/Services/AgendaService.php` | Usuarios/participantes antes de venta nueva también al aprobar/anular documentos sin cita |
| `app/Services/CarritoVentaService.php` | Validar representación nueva antes de aprobar, conservando H05 |
| `app/Services/InventarioService.php` | Guardia de documento nuevo antes del recorrido físico existente |
| `app/Services/AprobacionService.php` | Resolver participantes y limpiar niveles internos si falla el nuevo contrato |
| `app/Http/Controllers/VentaController.php` | Adaptador directo a store, creación/reenvío, edición estable, captura única y lectura dual |
| `app/Http/Controllers/PanelController.php` | Solo lector de ticket: mostrar participantes y etiqueta congelada; sin tocar métricas o cálculos |
| `config/salon.php` | Puerta explícita cerrada, sin variable de entorno nueva |
| `routes/web.php` | Datos de participantes del POS solo cuando el contrato esté disponible; sin rutas ni permisos nuevos |
| `resources/views/sales/pos.blade.php` | Una línea por prestación, participantes, clave/revisión y bloqueo de retirada de propuesta guardada |
| `resources/views/sales/documento.blade.php`, `ticket.blade.php` | Participantes y snapshot comercial; ticket nuevo muestra consumo documentado por ejecución, no receta actual |

Otros archivos de esta fase: migración de §4; `tests/Support/H06SaleMemory.php`, `tests/Unit/PrestacionCompartidaTest.php`, `tests/Unit/PrestacionFisicaTest.php`, `tests/Frontend/pos-shared-service.test.cjs` y este informe. El documento H06-P ya existía sin seguimiento al comenzar; se conserva sin modificación. No se modificaron tests históricos ni sus expectativas económicas.

## 4. Migración preparada, no ejecutada

`database/migrations/2026_11_21_000001_prepare_shared_service_contract.php` contiene exclusivamente cambios aditivos de esquema, sin backfill/DML/seeders. No se invocaron `up`, `down`, Artisan migrate ni Schema/DB reales.

Se cotejaron estáticamente las definiciones de `sales`, reconstrucción versionada de `sale_details`, `comisiones_generadas`, variantes y H03-B2. IDs y FK usan BIGINT UNSIGNED; `quantity` y `unit_price DECIMAL(10,2)` existentes no cambian. Porcentaje de comisión conserva DECIMAL(5,2), base/importe DECIMAL(10,2). La rama nueva rechaza resultados no representables. UUID ASCII binario y hash, CHECK de versión/forma y FK compuestas según §1. La tabla nueva declara InnoDB y un índice por `sale_id`, que no queda cubierto como primera columna en sus FK compuestas. Las nuevas columnas son nullable para no reescribir legacy.

La migración exige MySQL fuera de transacción, tablas requeridas, columnas H03 y ausencia de instalación H06 parcial. No instala ni activa H03. DDL MySQL puede confirmar implícitamente: una falla de instalación exige diagnóstico; no se reintenta ni se hace rollback destructivo automático. La compatibilidad física, CHECK/FK efectivos, motor InnoDB y colisiones deben verificarse en Debian antes de autorizar su uso.

`down` rechaza si cualquier columna nueva conserva evidencia o existen participantes; solo permite retirar objetos nuevos todavía sin uso. No elimina documentos ni propone limpiar evidencia para permitir un downgrade. Una vez utilizado, el rollback de despliegue consiste en cerrar puerta y conservar esquema/datos, sujeto a revisión.

## 5. Contrato de versión y activación

Configuración entregada: `salon.shared_services.enabled=false`, `sql_verified=false`. `exigirHabilitado` requiere ambos booleanos estrictamente true **y** `app()->environment('testing')`; los campos nuevos en HTTP no permiten saltar la puerta. Entorno testing por sí solo no basta. El POS no consulta el nuevo catálogo de participantes con puerta cerrada.

`exigirAplicacion` exige además `CapturaConsumoService::activa()`. Esa función no se modificó: actualmente exige V2 y la identidad histórica restringida de H03. **No es compatible todavía con el laboratorio independiente pendiente.** No se utilizó esa identidad ni se abrió esa base para superar el bloqueo. Ninguna emisión nueva puede usar captura legacy como fallback. Incluso cotización nueva requiere la capacidad H03 para obtener fotografía completa desde su origen.

Los tests configuran una Application mínima en memoria y sustituyen explícitamente solo la capacidad H03, sin activar V2. Esto prueba la rama y su rechazo cerrado; no autoriza ejecución física. No se modificaron `.env`, cache de configuración, resets ni perfiles AC-05.

## 6. Prestación y participantes

- Una línea nueva de servicio tiene `performance_key`, `stylist_id=NULL`, precio íntegro y participantes en filas independientes. Producto no admite participantes ni identidad de prestación.
- Un usuario puede participar en dos prestaciones distintas. Duplicarlo en la misma prestación se rechaza en DTO y UNIQUE propuesto.
- `quantity=q` declara ejecuciones homogéneas: igual servicio, variante, precio, participantes y consumo por unidad; la fotografía se multiplica por q, nunca por n participantes.
- Cita ordinaria: cantidad uno, una sola línea vinculada y participantes exactamente iguales a la asignación bloqueada. Cabecera y detalle deben coincidir. No se habilitan varias citas por venta.
- Dos prestaciones sin cita necesitan claves distintas; una key distinta no acredita por sí sola una segunda ejecución de la misma cita.
- La identidad comercial no se calcula desde nombres ni precios. El snapshot conserva servicio/variante aunque cambie su etiqueta o desaparezca la FK de variante.

## 7. Fotografía única de consumo

Se reutiliza `CapturaConsumoService::foto/guardar` y el campo H03; no hay fotografía por participante. Modo estándar congela todos los ingredientes; modo completo exige todos, incluidos ceros. La proyección `sale_detail_consumptions` se conserva únicamente para captura completa.

Antes de aplicar se comprueban versión, modo, referencia, ingredientes únicos/importes representables y coincidencia exacta de proyección/JSON en estándar y real. La fotografía completa cero sigue siendo completa; no se sustituye por estándar. Capturas por participante o campos desconocidos se rechazan; duplicados y faltantes los validan los contratos existentes. Una propuesta sin efectos puede revisar su foto y proyección dentro de la misma transacción y revisión explícita. Nada modifica fotografías de documentos aplicados.

No se seleccionan ni suman capturas contradictorias heredadas. El contrato nuevo rechaza convertir sus líneas; resolverlas exige una intervención explícita fuera de esta fase.

## 8. Comisiones congeladas

Cada participante resuelve esquema vigente, primer rango por orden que contenga la base, porcentaje del esquema o fallback de perfil, dentro de `ComisionService`. Conserva el criterio bruto: `quantity × unit_price`; no resta descuento de cabecera. Multiplicación/porcentaje/redondeo utilizan `InventoryDecimal` y centavos, no el float del modelo como autoridad.

Ejemplo verificado: precio 1.000, dos participantes 10% y 15% → venta 1.000, una fotografía/receta, comisiones 100 y 150. Descuento 100 → cliente 900; comisiones siguen 100 y 150. Tres participantes añaden su propia comisión, sin otra línea comercial.

La resolución de esquema/rango se bloquea y guarda en caché efímera antes de aplicar efectos; base, porcentaje y monto se guardan en la comisión existente. Snapshot técnico conserva procedencia; resolución cero queda explícita aunque no exista fila económica, como la política actual. UNIQUE/FK propuestos y estado de resolución impiden aplicar otra comisión al mismo participante. No se recalculan comisiones al leer ni desde nómina. La rama legacy permanece como estaba; la compatibilidad SQL de selección de rangos y redondeo sigue pendiente.

## 9. POS y facturación directa

POS nuevo: una fila completa al precargar una cita; lista de participantes y un editor de consumo. Agregar otro servicio crea otra key; los productos conservan agregación existente. Payload lleva versión, clave y revisión, conservadas en reenvíos. Etiquetas `producto`/`participants_display` no son autoridad y se excluyen de la huella; IDs de participantes se normalizan y ordenan.

La ruta directa nueva construye una línea desde la cita/catálogo y llama a **store**. No implementa inventario ni cobros alternativos. LibroPagosService sigue registrando NIO/USD/mixtos; USD sin importe recibido explícito se rechaza. La respuesta de creación nueva es 201; un reenvío reconocido devuelve 200 con `sale_id`, estado y `replayed=true`. Conserva también `pendiente_aprobacion` para que el POS no presente una retención repetida como aplicada. El contrato legacy conserva sus respuestas previas.

La huella es estricta sobre payload normalizado: mismo actor/clave/intención devuelve documento existente, otra intención o actor produce 409. La key UNIQUE resuelve la colisión de inserción propuesta; después de rollback se intenta recuperar el documento. No se promete equivalencia entre todas las representaciones JSON monetarias, ni idempotencia universal de cobros, anulaciones o ediciones. Un reintento de transformación de borrador ya aplicado se rechaza por estado, sin nueva venta. La prueba de colisión simultánea requiere MySQL.

Lectores adaptados: recarga POS, ticket, documento y ticket del panel. Los campos financieros de reportes siguen midiendo líneas comerciales; comisiones/portal/nómina leen resultados existentes. `Usuario::serviciosRealizados` sigue siendo relación legacy y no representa participantes nuevos; no se encontraron consumidores en el árbol inspeccionado. Consultas externas y lectores no versionados deben revisarse antes de habilitación empresarial; una búsqueda no demuestra inexistencia de terceros.

## 10. Borradores, retenidas y crédito

Edición nueva: lock de usuarios/contexto y venta, status borrador, clave, revisión y conjunto exacto de anclas. Conserva IDs; permite revisar cantidades, variante, captura y participantes de una propuesta sin efectos. Cambiar identidad de artículo/servicio o añadir/retirar líneas requiere anulación explícita y propuesta nueva. No se borran/recrean los detalles restantes. La UI informa esa condición y conserva el carrito; la anulación sigue requiriendo motivo/PIN/permisos vigentes en su flujo actual.

Una propuesta con pagos, kárdex, asientos, comisiones o snapshot de resolución se rechaza. Revisión vieja produce 409. Editar participantes no aplicados puede retirar sus filas de propuesta; esto no borra comisiones ni evidencia aplicada. No se convierten borradores legacy ni se permite cargar una propuesta nueva por la ruta legacy para borrar sus anclas.

Retención persiste documento/foto/participantes y payload de pagos, sin aplicar stock, pagos o comisiones. `AprobacionService` valida los términos guardados, resuelve participantes y aplica los servicios existentes en su transacción. No revaloriza según tarifa actual. Crédito conserva el lock/regla de límite y vencidos del cliente; inventario/comisiones/asiento de crédito siguen el flujo existente, sin inventar cobro.

## 11. Anulación

Se agrega guardia de contrato y coordinación de participantes a la ruta vigente; no se reemplaza H04. Se conservan PIN/motivo, prohibición por comisiones liquidadas, reversión de movimientos originales, fondos y espejo contable. Detalle y participantes permanecen; comisiones pasan a anuladas, no se borran ni recalculan. Propuesta/retención sin efectos utiliza la cancelación documental existente, sin devolución ficticia.

El nuevo contrato depende de H03/H04 habilitados correctamente; no se ejecutó una anulación física positiva nueva mediante el fallback legacy. Queda expresamente pendiente comprobarla con el motor real y evidencias originales en laboratorio independiente. Las pruebas de lectura/identidad y rechazo no equivalen a esa validación.

## 12. Compatibilidad con documentos legacy

Versión NULL mantiene lector y operación anteriores; no se escribe versión, claves, participantes o snapshots sobre ventas históricas. No hay backfill. H16 conserva líneas completas sin reinterpretarlas: el contrato nuevo no las adopta ni las declara ejecuciones adicionales. Borradores y retenciones antiguos no se convierten automáticamente; su revisión requiere determinar prestación/captura y sustituir propuesta sin efectos solo con autorización y trazabilidad.

Puerta cerrada mantiene la operación actual; por ello los límites de consumo multiplicado y comisión fraccionada **siguen presentes en el circuito legacy multiestilista** descrito en H06-R. Esta entrega prepara su reemplazo prospectivo, no declara reparado el histórico ni cerrado H06 empresarial.

## 13. Pruebas ejecutadas

Sin `phpunit.xml`, `tests/bootstrap.php` ni TestCase empresarial: autoload de Composer y lista explícita de tests Unit. Application manual sin `.env`/credenciales. El Connection de memoria lanza excepción si se intenta resolver PDO; solo admite tablas sintéticas, con escrituras acotadas a memoria. Inventario físico, divisas, asiento y notificaciones son dobles expresos en los tests del controlador. Carrito, fotografía/guardado, modelos, libro, comisiones y aprobación son código real; consulta relacional de cita se dobla en la prueba de carrito, y se ejercita también el coordinador real sin cita.

Resultados focalizados:

- Nuevas pruebas PHP: **60 aprobadas, 219 aserciones** (`PrestacionCompartidaTest`: 42; `PrestacionFisicaTest`: 18).
- Regresión PHP H06/H05/H07/H08/H11: **195 aprobadas, 720 aserciones**, sin cambiar expectativas.
- Total de esas selecciones: **255 pruebas PHP, 939 aserciones**.
- Frontend: **19 pruebas aprobadas**; 12 previas + 7 nuevas, mediante VM/fetch sintético sin servidor.
- Lint PHP de archivos modificados/nuevos: aprobado; migración solamente analizada, no ejecutada. Build Vite aprobado mediante `npm.cmd run build`; `npm.ps1` fue rechazado por ExecutionPolicy, sin cambiar dicha política. Advertencia existente de fuente opcional `fontaine`, sin instalar dependencias.
- Un intento conjunto excedió el límite PHP de 40 s del proceso padre; se repitió la selección separada en dos procesos acotados a 60 s. No hubo SQL ni escrituras parciales empresariales.

Comando usado para la selección nueva (sin bootstrap empresarial):

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d memory_limit=128M -d max_execution_time=60 -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result --no-progress tests/Unit/PrestacionCompartidaTest.php tests/Unit/PrestacionFisicaTest.php
```

La selección previa utilizó esos mismos flags y únicamente `FacturacionCitaGuardTest`, `FacturacionCitaPaymentsTest`, `CitaConsumptionCharacterizationTest`, `CitaCommissionCharacterizationTest`, `CarritoVentaTest`, `CarritoVentaHttpTest`, `NominaOrdenCuotasTest`, `ConciliacionNominaTest`, `NominaConceptosIntegrityTest`, `NominaCoverageTest` y `NominaWizardContractTest`, todos en `tests/Unit/`. Frontend: `node --test tests/Frontend/pos-cart.test.cjs tests/Frontend/pos-consumption.test.cjs tests/Frontend/pos-shared-service.test.cjs`.

Matriz de cobertura y límites:

| Casos solicitados | Evidencia |
| --- | --- |
| 1, 2, 3, 4: uno/dos/tres, una línea/foto, bruto por participante | Controlador real + persistencia/efectos sintéticos; assertions de precio/base/importes y cantidad de líneas |
| 5, 6, 7: mixtas, prestaciones independientes y q>1 | Carrito/controller reales; mismo participante permitido en dos prestaciones. Bucle físico real con lector/escritor dobles en 18 combinaciones n/q/cantidad |
| 8, 9, 10: estándar, completo/cero y conflictos | Foto/guardar reales, proyección completa, duplicados/campos contradictorios y lectura cero; sin invocar resolver H03 autorizado ni lotes reales |
| 11: variantes | Pertenencia H05, precio y snapshot; etiqueta conservada con FK NULL y catálogo cambiado |
| 12: borrador/edición | ID estable, revisión, participante cambiado, retiro bloqueado, paso a pagada una sola vez y rechazo de conversión legacy |
| 13: retenida/aprobación | Aprobación real con memoria; ningún efecto previo, una aplicación y rollback simulado en cuatro puntos |
| 14: crédito | Estado emitida, comisiones e integración contable doble, sin pagos; validación H05/cliente existente |
| 15: anulación | Guardia/enlaces revisados estáticamente, sin cambio de política; escenario físico completo reservado a laboratorio H03/H04 |
| 16, 17: cero y rangos | Snapshot cero sin fila económica; resolver real con modelos/rangos sintéticos, límites decimales y precedencia |
| 18, 19, 20: reenvíos e intención/cita distinta | Misma clave retorna ID sin repetir efectos; cambio de descuento o de cita produce 409 |
| 21, 22: H16/puerta | Regresión legacy y rechazo de dos líneas para misma cita nueva; puerta cerrada/H03 no disponible/versión desconocida rechazan |
| 23–26: errores posteriores a inventario/pagos/comisiones/asiento | Error centinela alcanzado y marcado; efectos y filas en memoria restaurados y nivel cero, también con nivel anidado y en aprobación |
| 27–29: H05/H03/H04/H07/H08/H11 | Regresiones puras H05/nómina y caracterización legacy; H03/H04 por contrato/fotografía y análisis, sin afirmar integración física SQL |

No hay prueba de middleware/kernel HTTP completo: son invocaciones aisladas de controlador/Request y servicios. La traza de llamadas `for update`, los snapshots de memoria y el interleaving simulado previo **no demuestran serialización ni rollback InnoDB**. No se ejecutaron tests Feature con SQL ni suites generales.

## 14. Pruebas SQL pendientes

Laboratorio Debian independiente, con autorización nueva y sin acceso a bases históricas:

1. Revisar/aplicar migración en perfil sintético H03/H04 autorizado; comparar tipos/índices/FK/CHECK reales, modos SQL y efectos de DDL no transaccional.
2. Emitir productos/servicios/mixtas, participantes 0/1/2/3, q homogénea, variantes, descuentos, crédito, estándar/completo/cero y costo real. Conciliar detalles/totales, pagos netos NIO, COGS/kárdex y montos congelados; no modificar políticas AC-05 para cuadrar.
3. Dos conexiones: misma key/misma intención, key/intención distinta, directa vs POS por cita, dos aprobaciones, aprobación vs reenvío, edición vs aprobación/anulación y usuarios compartidos/distintos. Comprobar espera, relectura y orden; no usar transacción exterior de PHPUnit que oculte commits.
4. Fallar después de inventario/pagos/comisiones/asiento; evidenciar punto alcanzado, nivel cero y ausencia de residuos desde segunda conexión. Registrar AUTO_INCREMENT aparte, sin pretender restaurarlo por rollback.
5. Anular propuesta sin efectos y emitida con evidencia H04 real; comisiones pagadas bloquean, se conservan participantes/foto, restitución/no restitución y asiento espejo siguen documentos originales. Fórmula/tarifa/porcentaje modificados después no reescriben el original.
6. Revisión/adopción de propuestas antiguas ambiguas: sin transformación automática, capturas contradictorias ni inferencia de múltiples ejecuciones H16.

## 15. Riesgos y bloqueos

El principal bloqueo es la identidad/gate H03 actual incompatible con laboratorio independiente y la falta de regresión SQL. No se cambia para eludirlo. Configuración de producción deliberadamente no habilitable en esta fase.

Orden nuevo: usuarios de contexto/participantes → citas si existen → venta; el carrito conserva sus locks de catálogo/stock, y la resolución de comisiones se completa antes de aplicar salidas/pagos/asiento. Aprobación/anulación ya no invierten participantes/venta frente a reenvíos sin cita. Selección de esquema/rangos y FK pueden añadir locks; demostrar ausencia de ciclos/deadlocks requiere laboratorio, no la traza de memoria. Todos los escritores deben respetar el caso de uso; SQL directo no obtiene las validaciones de cobertura por sí solo.

Las restricciones SQL propuestas no demuestran que una prestación ocurrió, ni sustituyen validar versión de raíz/tipo de detalle, fotografía, cita o clasificación de documentos legacy. No hay índice de exclusión por período ni nueva definición de múltiples ejecuciones de una cita.

Pendientes de aceptación: revisión física de DDL, circuitos completos H03/H04, lectores externos, comportamiento de schema/rangos reales y política de transición para propuestas antiguas. No existen decisiones comerciales nuevas adoptadas: se usa exclusivamente la regla de bruto completo confirmada. No se ha aprobado clasificación AC-05 ni habilitación empresarial.

## 16. Protección de bases

No se conectó mediante SQL a `salon_erp`, `salon_erp_test` o `salon_erp_bootstrap_check`. No hubo DDL/DML, migraciones, seeders, restauraciones, escrituras SQL de tests ni lectura de credenciales históricas. Evidencia AC-04/AC-05 y datos históricos intactos. No se modificaron perfiles, resets, usuarios o infraestructura del laboratorio.

No se ejecutaron operaciones Git de escritura, servicios HTTP, workers, scheduler ni despliegues. Build generó únicamente artefactos Vite locales; no se editaron manualmente ni se publicaron.

## 17. Pasos de habilitación futura

Requiere autorización independiente para: preparar laboratorio aislado; habilitar allí el contrato H03/H04 con identidad nueva mediante una intervención revisada; revisar y ejecutar DDL exclusivamente en ese laboratorio; cargar fixtures sintéticos y ejecutar la matriz SQL. No utilizar la base histórica exigida actualmente por el gate para conseguir pruebas verdes.

Solo tras resultados SQL y revisión de lectores/transición, proponer autorización separada de migración empresarial y activación controlada, con respaldo, identidad de destino y despliegue explícitos. Si se necesita persistir revisión legacy, proponer entonces su contrato/columna; no añadirla implícitamente durante el despliegue. Con datos nuevos en uso no ejecutar un down que elimine evidencia.

H06-P1 termina con implementación aislada, puerta cerrada y DDL pendiente de revisión física. No se inicia Debian, migraciones, SQL, despliegues ni otro hallazgo.
