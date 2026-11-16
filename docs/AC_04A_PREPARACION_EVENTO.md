# AC-04A — Preparación del primer evento contable configurable

Fecha: 2026-11-16. Base de código revisada: commit `ac46c54` (`Ac3a y 3b`).

## 1. Resultado y alcance de la recomendación

Se recomienda preparar un **cargo bancario por mantenimiento o comisión, en NIO, reconocido como gasto, sobre una cuenta bancaria explícitamente autorizada**. Es un subconjunto del flujo actual de cargo directo; no abarca cualquier cargo, los abonos, las transferencias ni sus comisiones automáticas.

Nombre semántico propuesto para revisión: `bancos.cargo_gasto.nio`, contrato de evento 1. No está registrado ni habilitado. La identificación de mantenimiento/comisión debe ser explícita y verificable desde el documento operativo; no se deducirá del texto de `concepto`, del código 6.9 ni del nombre de una cuenta.

La recomendación es condicional: **el evento todavía no está listo para activarse**. Faltan aprobación del alcance, autorización empresarial persistente, trazabilidad y coordinación de bloqueos/saldo bancario. AC-04A documenta esas dependencias; no las implementa ni concede permiso para modificar otros escritores.

No se generaron asientos ni se activó `AccountingAccountResolver`. Se creó exclusivamente este informe. No se ejecutaron PHP, Artisan, PHPUnit, SQL, migraciones, seeders ni operaciones HTTP. Las conclusiones sobre defectos y locks son estáticas; no se presentan como carreras reproducidas en esta fase.

## 2. Comparación de candidatos

| Candidato inspeccionado | Ventajas | Acoplamientos o limitaciones | Decisión |
| --- | --- | --- | --- |
| Cargo bancario directo NIO de gasto | Movimiento propio, enlace al asiento, saldo y libro bancario verificables, prueba específica existente; no inventario, COGS, nómina ni conversión. | Saldo compartido con otros escritores; clasificación explícita necesaria; conciliación posterior. | Primer piloto recomendado, sujeto a las condiciones descritas. |
| Abono bancario NIO | Movimiento y asiento vinculados. | Admite distintos ingresos, no equivale siempre a intereses; la prueba localizada comprueba rechazo de contrapartida inválida, no una equivalencia positiva completa del piloto. | Posterior al cargo. |
| Gasto de caja chica | Movimiento propio y cuenta por tipo de gasto. | Fondo/disponibilidad, PIN, caja compartida; `index()` crea catálogos/caja y cambia responsable; `store()` no bloquea el saldo y tiene retorno por insuficiencia dentro de una transacción abierta. | Evitar como primera integración; no se corrigió. |
| Retiro del propietario NIO | Documento propio, significado patrimonial definido, número `RET-<id>`. | Ruta también admite USD, pero el método inspeccionado no usa esa moneda para convertir; no se localizó prueba específica de contabilización del retiro, solo accesos. No debe confundirse patrimonio con gasto. | Mantener intacto; no elegir solo por sus dos líneas. |
| Gasto desde diario | Asiento simple mediante ContabilidadService. | No crea documento operativo separado; número `GST-<time()>`, sin referencia documental de origen en ese método; efectivo/bancos genéricos. | No es la mejor demostración de trazabilidad documental. |
| Transferencia, arqueo, venta, compra, merma, nómina | Integración existente. | Bóvedas, divisas, COGS, existencias, cierres o derechos laborales. | Fuera del piloto. |

La selección prioriza identidad documental y verificación de saldos, además de un límite operativo identificable. El número de líneas del asiento no determina la recomendación.

## 3. Flujo actual del candidato y evidencia

### Entrada, documentos y cuentas

- `routes/web.php:208`: `POST /bancos/cargo`, sesión web y grupo `role:admin,contador`; el rol `alvaro` accede por el bypass vigente de `RoleMiddleware`. La vista tiene `@csrf`. No cambiar permisos de estos operadores por adoptar políticas.
- `BancoController::registrarCargo()` (`:394`) delega a `registrarMovimientoDirecto()` (`:404`). El método compartido valida cuenta bancaria, monto, concepto, cuenta contable, fecha y referencia bancaria.
- Antes de la transacción comprueba tipo de contrapartida: gasto/costo para cargo, ingreso para abono. Dentro crea `movimientos_bancarios`, llama al motor, guarda `asiento_contable_id`, descuenta `saldo_actual` y confirma.
- `ContabilidadService::contabilizarMovimientoBancario()` (`:1340`) genera `CBA-<id movimiento>`, `modulo_origen=cargos_bancarios`, `referencia_id=<movimiento>`, fecha/período, usuario y dos detalles. Mantiene la validación de partida doble.
- DEBE: la cuenta de gasto/costo que hoy se envía explícitamente, validada también por `AccountIntegrityService`. **6.9 es recomendación de la interfaz, no una cuenta impuesta por el método**.
- HABER: `FondosService::cuentaContableDeBanco()` (`:187`) prioriza `cuentas_bancarias.cuenta_contable_id`; si falta, aplica el fallback histórico BAC `1.1.2.2` / LAFISE `1.1.2.1`.

Para el piloto se propone exigir moneda bancaria NIO igual a la moneda base NIO, cuenta bancaria activa y vínculo explícito al mayor. No usar el fallback para inventar una asociación. Tampoco basta con comprobar que la cuenta no sea USD: debe verificarse NIO de forma positiva.

El cargo genérico admite costo; el piloto propuesto solo admite gasto bancario documentado. Los demás cargos legítimos mantendrán su flujo actual. Antes de AC-04B debe aprobarse cómo se distinguirá este subconjunto mediante una selección operativa cerrada y persistente, validada contra la habilitación del servidor. No clasificar automáticamente los cargos existentes ni reinterpretar referencias libres.

### Magnitudes actuales y efectos indirectos

El controlador y servicio redondean monto a dos decimales; `movimientos_bancarios.monto` y debe/haber son DECIMAL(15,2). La preparación no cambia esa normalización ni añade cálculos, impuestos o pagos. La futura prueba equivalente debe usar el mismo importe documental efectivamente persistido. Rechazar exceso de precisión en vez del redondeo actual requeriría autorización específica, no debe colarse en el resolutor.

Ejemplo de fixture equivalente, no autorización real: cargo C$50,00 por mantenimiento, saldo bancario 1.000,00 → 950,00, DEBE 6.9 por 50,00 / HABER subcuenta bancaria original por 50,00. No hay venta, compra, cuenta por pagar, pago a proveedor ni consumo de existencias.

`BancoController::guardarConciliacion()` (`:511`) utiliza estos movimientos para cerrar el extracto con diferencia cero. El libro bancario usa el signo de cargo como salida. Diario, mayor, resultados, balance y dashboard reflejan los detalles contables; no se cambiarán sus consultas. Cambiar a otra cuenta con clasificación incompatible con los reportes por clases/prefijos sería un bloqueo, no una autorización para reescribir reportes.

**No abrir `/bancos` como inspección inocua:** `index()` llama a `asegurarCatalogoBancario()` (`:604`), que puede crear bancos/cuentas y completar vínculos al mayor. En AC-04A solo se leyó su código; no se llamó por HTTP ni desde Laravel.

### Transacciones y bloqueos efectivos

El controlador posee la transacción exterior; el método contable abre una anidada. Su commit interno no debe convertirse en confirmación independiente de documento/saldo. El período se resuelve antes de la transacción interna del servicio, pero dentro de la exterior del controlador. `validarCuadre()` (`ContabilidadService:1811`) invoca `AccountIntegrityService::posting()`: relee período con lock compartido y valida las cuentas, además del cuadre exacto de centavos.

`registrarMovimientoDirecto()` lee la cuenta bancaria sin `lockForUpdate()` y calcula el nuevo saldo desde ese modelo. Hay riesgo estático de actualización perdida. Añadir un lock solo al piloto no demuestra integridad frente a escritores heredados que ya hayan leído un saldo viejo.

Otros escritores encontrados de ese saldo: `BancoController::depositar()` (`:206`), `transferir()` (`:324`, `:358`), cargos/abonos (`:455`) y `MesaCambioController` (`:328`, `:351`). No se modificaron. Su coordinación o una exclusión de escritores verificable constituye una dependencia previa a activar en operación compartida. Una prueba aislada del piloto no demostraría que esos otros caminos ya son seguros.

## 4. Papeles y ámbito propuestos

| Papel | Función | Restricción propuesta |
| --- | --- | --- |
| `gasto.cargo_bancario` — nuevo | DEBE del gasto bancario clasificado | Gasto, deudora, activa e imputable; ámbito `bank_account` explícito. No costo ni inventario. |
| `fondos.banco` — existente | HABER del banco afectado | Activo, deudora, activa e imputable; mismo recurso bancario NIO. |

El contador debe proporcionar los IDs reales de las asociaciones aprobadas. Los códigos anteriores solo describen el comportamiento que debe reproducirse en el ensayo equivalente. No se crean cuentas ni se eligen equivalencias de Excel.

Para el primer piloto se recomienda una sola cuenta bancaria autorizada y asociaciones equivalentes a las actuales. En particular, `fondos.banco` debe coincidir con el vínculo vigente al mayor de ese banco; no se permite trasladar por configuración un saldo previo a otra cuenta. Si difieren, se detiene la operación y se presenta el problema para una fase coordinada.

Hay una limitación actual importante: `AccountingPolicyRoles::CORE` exige seis papeles de ventas/inventario tanto al publicar como al inspeccionar. El nuevo papel de gasto aún no existe. No basta insertar una asociación ni llamar al resolutor actual.

Opción mínima propuesta para el ensayo: conservar íntegramente el contrato 1 y sus seis papeles globales, añadir los dos papeles bancarios del piloto y habilitar exclusivamente el evento bancario. Esas seis asociaciones seguirán siendo evidencia técnica sin habilitación operativa. Evitar ámbitos de artículos u otros bancos en esa política de ensayo para no incorporar locks físicos ajenos al piloto. Existe acoplamiento de validación a esas cuentas globales y debe reconocerse.

Una cobertura independiente por evento sería más limpia a largo plazo, pero requiere evolucionar explícitamente el contrato de publicación/resolución. No debilitar CORE, cambiar sus fotografías ni modificar versiones publicadas para facilitar el piloto. El propietario debe aprobar cuál alternativa se implementará en AC-04B.

## 5. Trazabilidad histórica: opciones y propuesta

La identidad no puede guardarse en `concepto`, `descripcion` o `numero_asiento`. Se necesita conservar como mínimo:

| Dato | Ubicación propuesta |
| --- | --- |
| Versión única y hash utilizado | Cabecera del asiento: FK nullable RESTRICT a `accounting_policy_versions` y hash nullable. |
| Autorización empresarial y habilitación del evento | Referencias durables a sus registros, desde cabecera/evidencia; nunca un booleano recibido del cliente. |
| Evento y contrato | Identificador cerrado y versión del evento, persistidos junto a la evidencia. |
| Documento | `modulo_origen/referencia_id` actuales y enlace verificable al movimiento bancario en la correspondencia del piloto. |
| Fecha contable | `asientos_contables.fecha` y fecha del movimiento, iguales conforme al contrato operativo. |
| Instante de aplicación | Timestamp UTC del servidor fijado una vez; distinto de la fecha contable, necesario para auditar la vigencia aplicada. |
| Cuenta, papel, ámbito/recurso y asociación exacta | Correspondencia por detalle; cuenta original igual a `detalle_asientos.cuenta_id`, FK al binding y fotografía de papel/ámbito. |
| Importe y lado originales | Detalle existente; correspondencia que concilie exactamente con sus importes, sin recalcularlos. |

### JSON por línea

Un JSON nullable versionado puede conservar toda la fotografía y no obliga a rellenar historia. Es suficiente para expresar las dos líneas del piloto. Sin embargo, IDs dentro del JSON no tienen FK, cuesta impedir referencias cruzadas y no protege por sí solo frente a borrado en cascada de los detalles. JSON no equivale a integridad referencial.

### Tabla relacional mínima — recomendada

Propuesta de nombre: `accounting_entry_line_resolutions`. Una fila por línea del piloto con FK RESTRICT al detalle, asiento, binding y cuenta original; FK al movimiento bancario original para este evento; recurso bancario, papel/clave de ámbito, contrato, versión/hash y metadatos mínimos de resolución. UNIQUE sobre detalle para el piloto, que usa exactamente una asociación por línea. Las fotografías adicionales pueden ir en un JSON versionado, sin sustituir las FK.

El servicio comprobará que detalle/asiento, binding/versión/cuenta, documento y recurso pertenecen a la misma operación. FK independientes no prueban por sí mismas esa igualdad entre filas: se requieren validaciones transaccionales; si se exige imponerla también mediante SQL, evaluar claves compuestas/índices aditivos antes de aprobar la migración.

Se recomienda esta tabla por la trazabilidad y el RESTRICT, no por necesitar una arquitectura nueva. También evita depender únicamente de los vínculos bancarios existentes: la migración corporativa añade `asiento_contable_id` y `cuenta_contable_id` como IDs/index, sin una FK que pruebe la correspondencia completa. Las nuevas referencias no deben borrar ni reparar historia anterior.

La cabecera nullable no atribuye políticas a asientos viejos; la tabla de correspondencias solo se llenará para eventos nuevos autorizados. No habrá backfill. El rollback futuro debe rechazarse con evidencia en uso; no eliminarla para retirar el esquema. No hace falta una lista de múltiples asociaciones por línea para este piloto: si otro evento agrega papeles o partidas, deberá diseñar ese reparto en su propia fase.

## 6. Autorización empresarial verificable

Hay tres hechos diferentes:

1. Publicación técnica: política íntegra y publicable, como en AC-03A.
2. Aprobación empresarial: contador/propietario autorizado aprueba esa versión y hash para esta empresa, con referencia documental comprobable.
3. Habilitación operativa: se autoriza explícitamente este evento/contrato, recurso, moneda, vigencia y entorno, vinculándolo a la aprobación anterior.

Ninguno implica automáticamente los otros. Las versiones sintéticas 2088/2099 carecen de los hechos 2 y 3. No se reclasificarán ni activarán.

Se propone un único registro append-only de autorizaciones, por ejemplo `accounting_policy_authorizations`, que exprese esos hechos administrativos mediante acciones `business_approval`, `event_enablement` y posterior retirada explícita. Cada registro conserva actor autenticado, rol comprobado al autorizar, versión/hash exactos, referencia y evidencia documental, timestamp UTC del servidor, motivo y alcance. Una habilitación referencia su aprobación; una retirada referencia el acto anterior. Las FK y borrados son RESTRICT. No guardar PIN ni secretos.

El alcance habilitado contiene evento/versión de contrato, cuenta bancaria autorizada, NIO, entorno/empresa y vigencia. Los datos proceden de una operación administrativa autenticada de contabilidad y revisión documental, no del booleano `enabled` o de IDs de aprobador enviados por el cajero. Una referencia/digest conserva evidencia declarada; no demuestra por sí sola una firma externa. El responsable debe definir cómo verificará el documento de aprobación.

Reutilizar la infraestructura de auditoría y los roles existentes, sin sistema paralelo de permisos. La tabla pequeña separada se propone porque `accounting_policy_audits` hoy restringe sus eventos y `inspect()` exige que el último sea `published`: añadir allí una aprobación sin diseño haría fallar el contrato AC-03B. No modificar ese historial ni reinterpretar sus eventos. Si se prefiere ampliar la tabla existente, requiere aprobar y probar expresamente esa evolución antes de elegirla.

Preparar/aprobar política seguirá restringido a contabilidad. Ejecutar el cargo conservará los roles actuales del banco, incluido admin. No se puede llamar sin más a `inspect()` desde el controlador: hoy exige rol contable y rechazaría operadores legítimos. El futuro camino de imputación deberá comprobar autorización del evento y del ejecutor por separado, sin impersonar un contador ni conceder a admin administración de políticas.

Ausencia de habilitación significa evento configurable cerrado. No crear una aprobación de demostración por defecto. Tampoco permitir que, ante una política inválida en un evento ya habilitado, el operador fuerce el camino heredado omitiendo un campo: la decisión de usar el piloto pertenece al servidor. Las demás clases de operación fuera del alcance aprobado seguirán intactas; no se cambia su clasificación para hacerlas entrar en el piloto.

## 7. Protocolo transaccional propuesto

No reutilizar una respuesta anterior de `inspect()` como autorización, ni abrir/cerrar una transacción de consulta antes de empezar la operación financiera. El futuro método específico de resolución para imputación debe exigir transacción exterior activa y fijar recursos hasta el commit.

### Inversión detectada por inspección

Publicación AC-03A adquiere versión X → asociaciones X → versiones publicadas X → recursos bancarios S → cuentas S. Un futuro cargo que tome banco X → versión S puede formar un ciclo con publicación que ya tenga versión X y espere banco S. Además, llamar primero a `inspect()` y después elevar su banco S a X permite conflictos entre dos lectores que intenten la misma elevación.

Por tanto, no copiar literalmente el orden de simulación ni la pauta genérica de «documento primero» a este caso. El protocolo siguiente es específico del evento bancario nuevo y debe ensayarse; no cambia el orden de H02/H03/H16.

### Secuencia para el piloto

1. Validar sesión, rol operativo, CSRF, clasificación explícita, referencia bancaria y monto conforme al contrato existente. Obtener desde servidor la cuenta bancaria/recurso y congelar fecha contable e instante UTC de aplicación. Ningún ID de cuenta o versión del navegador es autoritativo.
2. Abrir la única transacción exterior del cargo. Mantener los checks de usuario y un período abierto bajo lectura actual compartida, antes de bloquear cuentas contables, compatible con período → cuentas de AC-02/cierre. No cambiar creación de períodos o reglas de cierre; si falta uno, reutilizar ContabilidadService dentro de la misma transacción y volver a validar. La carrera de creación/cierre de períodos debe probarse, no presumirse resuelta.
3. Seleccionar una sola versión publicada/vigente mediante lectura actual S y fijarla. Verificar bajo el protocolo su aprobación y habilitación de evento/recurso/hash. Una operación administrativa de habilitación/retirada debe tomar primero esa versión X, para serializarse con la operación financiera; un registro append-only sin ese lock no basta para impedir una retirada concurrente inadvertida.
4. Bloquear la cuenta bancaria seleccionada directamente X y releer saldo, moneda, estado y vínculo al mayor. Para el piloto de un banco no hay colección de bancos que reordenar. No tomar antes un S del banco que luego deba elevarse. Las publicaciones que toman versión antes de banco quedan en el mismo orden relativo.
5. Leer y fijar bindings/auditoría y resolver los dos papeles conjuntamente con la versión ya fijada. Separar conceptualmente fijación de cabecera y validación de recursos; reutilizar la canonicalización/fotografías del resolutor, sin convertir el array de simulación en un permiso. No permitir una segunda selección por línea ni leer recursos ajenos al piloto en orden incompatible.
6. Bloquear/verificar las cuentas contables requeridas en orden ascendente mediante AccountIntegrityService; comprobar fotografías, tipo/naturaleza, imputabilidad, cuenta bancaria exacta y autorización. Rechazar asociaciones incompletas, cuentas desactivadas o una cuenta bancaria distinta de la que mantiene el saldo anterior.
7. Crear movimiento, cabecera y líneas mediante **ContabilidadService**, conservando `CBA-*`, importes, signos, documento y moneda base. Añadir evidencia y correspondencias en la misma transacción; enlazar el movimiento al asiento y actualizar el saldo desde el valor releído bajo X. No calcular otro asiento en el resolutor.
8. Comprobar versión/hash/habilitación únicos, referencias de cada línea, conciliación movimiento–asiento–banco y cuadre exacto con `validarCuadre()`. Confirmar solo desde el dueño de la transacción exterior. Ante fallo, rollback de movimiento, asiento, detalles, evidencia y saldo. Sin commits intermedios ni reintentos financieros automáticos.

No incorporar GET_LOCK administrativo al cargo. El mutex AC-02/AC-03A permanece administrativo. Una publicación incompatible no puede cambiar la versión ya fijada: la publicación solapada debe rechazarse al aplicar las reglas de AC-03A; si otra publicación toca filas fijadas, deberá esperar o producir un rechazo controlado por timeout/deadlock, nunca continuar con una mezcla de versiones. No prometer que jamás habrá un deadlock sin ejecutar las carreras.

El nuevo lock del banco no basta ante los otros escritores heredados enumerados. Antes de activar con acceso compartido, se requiere autorización separada para sincronizarlos sin cambiar sus cálculos, o demostrar una exclusión de escritores sobre el recurso. No ampliar automáticamente esta fase a mesa de cambio, fondos o transferencias. Si no se autoriza esa dependencia, AC-04B solo podrá preparar/probar el mecanismo aislado y mantendrá la activación operativa bloqueada.

## 8. Historia, reversión y documentos retenidos

Una reversión histórica toma las líneas, cuentas, importes, documento y evidencia originales. No selecciona política vigente ni añade a una operación vieja una versión que no utilizó. Las cuentas inactivas originales pueden intervenir en la reversa autorizada conforme a `AccountIntegrityService::posting(..., originalId)`, sin permitirlas para imputación ordinaria nueva.

El cargo directo inspeccionado **no tiene un flujo de reversión bancaria de dominio identificado**. `AsientoManualController::reversar()` (`:125–129`) rechaza asientos automáticos. No levantar esa protección ni usar una reversión de venta para bancos. El caso futuro de reversión positiva exige autorización para un flujo bancario propio que revierta simultáneamente documento/saldo y asiento original, definiendo también el tratamiento de movimientos conciliados. Hasta entonces, el criterio verificable es rechazar la ruta manual y conservar el documento; el diseño de equivalencia inversa puede probarse como simulación sin confirmación.

Retenciones: se mantiene sin cambios AprobacionService. Propuesta solicitada para una fase autorizada: fijar la versión definitiva al aplicar los efectos financieros; si difiere de la revisión inicial, exigir revisión expresa con evidencia del cambio antes de aprobar. No basta un confirm enviado por el navegador. No implementado y no es condición para este piloto, que no usa ventas retenidas.

## 9. Plan de pruebas para AC-04B — no ejecutado

| Caso | Resultado que se debe comprobar |
| --- | --- |
| Configuración equivalente | Fixture C$50: movimiento y dos líneas idénticos en importe/cuenta a la referencia actual, saldo 950 y evidencia exacta de ambas asociaciones. |
| Asociación válida / cuenta incorrecta | Resolución correcta permitida solo con aprobación/habilitación; gasto a activo/costo, banco distinto o ID del cliente rechazados antes de confirmar. |
| Política ausente/incompleta | Rechazo sin documento, saldo, asiento o evidencia parcial; sin fallback silencioso al código fijo. |
| Demo / sintética publicada | Rechazo de imputación aunque `inspect()` sea técnicamente válido; ninguna versión previa se activa. |
| Fecha/vigencia | UTC [desde,hasta), fronteras y fecha contable local; no elegir política antigua manipulando fecha del formulario. |
| Cuenta inactiva/no imputable/fotografía cambiada | Rechazo bajo lectura actual; carrera catálogo–evento con resultado persistido. |
| Publicación concurrente | Dos conexiones MySQL reales: mismo orden versión→banco, selección estable hasta commit; publicación solapada rechazada, sin mezcla ni bloqueo permanente. |
| Habilitación retirada concurrentemente | La retirada se serializa por la misma versión; no confirma un evento basado en una lectura obsoleta. |
| Fallo tras cabecera | Rollback de movimiento, cabecera, correspondencias, saldo y cualquier período creado en la operación. |
| Fallo tras primera línea | Igual rollback; cero detalles huérfanos, evidencia sin confirmar o efectos físicos/financieros parciales. |
| Versiones mezcladas / FK cruzadas | Rechazo de cuenta, binding, recurso o versión que no correspondan al mismo asiento/evento. |
| Concurrencia de saldo | Cargo–cargo y cargo–cada escritor autorizado del banco; suma de movimientos igual al cambio de saldo. No dar por aprobada por probar solo dos cargos. |
| Período cerrado / cierre concurrente | Rechazo o serialización coherente sin modificar las reglas de cierre ni dejar un asiento en período cerrado. |
| Cuadre exacto | Débitos=créditos en centavos y por cuentas esperadas; no solo suma total. |
| Historia/reversión | Asientos anteriores inalterados y sin backfill; ruta manual rechaza automáticos. Reversa bancaria positiva solo si se autoriza su dominio y conciliación. |
| Reportes | Comparar mayor, diario, resultados, balance, KPI/libro bancario y saldo antes/después. Igual configuración → iguales diferencias contables, sin reclasificar historia. |
| Límites y seguridad | USD, abonos, costo, otros bancos/eventos y ejecución no habilitada quedan fuera; sesión/CSRF y roles operativos conservados. |
| Repetición de solicitud | No duplicar el cargo del piloto; preparar clave idempotente y comparar contenido canónico en el servidor. La referencia bancaria libre no es UNIQUE fiable ni prueba suficiente de idempotencia. |

Prueba existente de referencia: `BancosMesaCambioTest::test_cargo_bancario_va_al_gasto_6_9_y_ajusta_saldo()` (`:485`). Usa RefreshDatabase; **no se ejecutó**. También se inspeccionó el rechazo de abono inválido (`:513`) y las pruebas AC-02/AC-03A/AC-03B. Su existencia/cobertura previa no demuestra todavía el piloto configurable.

Las pruebas futuras deben ser focalizadas, inspeccionadas y autorizadas sobre `salon_erp_test`, con preflight de identidad/privilegios/migraciones/respaldo, nueva línea base de las tres bases y flag restablecido en `finally`. No reutilizar a ciegas intervalos/documentos sintéticos durables de AC-03A/B, no borrar su auditoría para limpiar y no ejecutar la suite bancaria multimoneda completa por comodidad.

## 10. Archivos mínimos potenciales y migraciones a evaluar

No se modificó ninguno de los siguientes. Son puntos futuros, no una autorización automática de cambios:

| Área | Archivos o esquema potencial | Límite |
| --- | --- | --- |
| Contrato y autorización | `AccountingPolicyRoles`, `AccountingAccountResolver` y un servicio pequeño de autorización si se aprueba | Añadir papel/evento, separar aprobación/habilitación y fijación transaccional; preservar simulación y políticas publicadas. |
| Contabilización | `ContabilidadService::contabilizarMovimientoBancario` | Parametrizar únicamente las cuentas del piloto con evidencia verificada; conservar fórmulas, signos, período y cuadre. |
| Documento/UI | `BancoController::registrarMovimientoDirecto`, `MovimientoBancario`, modal actual de `banks/index.blade.php` | Clasificación explícita e identidad idempotente; ningún CSS transversal ni cambio a todos los cargos/abonos. |
| Historia | `AsientoContable`, correspondencias por línea y, si procede, relaciones de `DetalleAsiento` | Persistencia nullable y FK; no atribución retroactiva. |
| Esquema futuro | Una migración nueva para referencias nullable de cabecera, tabla de correspondencias y registro mínimo de autorizaciones si se aprueban | No creada en AC-04A; revisar tamaños, CHECK/FK/RESTRICT y rollback con evidencia. No cambiar las 80 migraciones. |
| Regresiones | Pruebas focalizadas nuevas del evento, resolver y carreras; caso existente de cargo NIO | Sin suites generales ni efectos en desarrollo/bootstrap. |

La idempotencia del nuevo piloto puede expresarse mediante UUID de operación persistido y UNIQUE en el registro documental/evidencia, junto al hash de contenido y enlace al movimiento. Debe resolverse bajo la misma transacción: misma clave/contenido devuelve el resultado existente; misma clave/contenido diferente rechaza. Su ubicación concreta requiere revisar la migración propuesta; no deducirla de `CBA-<id>`, que solo evita repetir el número de un mismo movimiento, no dos movimientos por dos POST.

Sincronizar otros escritores bancarios o crear una reversión bancaria de dominio requiere un alcance adicional concreto. No esconder esas modificaciones como parte de la lista mínima del resolutor. FondosService, divisas, compras, inventario, ventas, nómina, cierres y H04-B mantienen su comportamiento actual.

## 11. Aprobaciones necesarias antes de AC-04B

1. Aceptar o sustituir el piloto propuesto y definir la clasificación explícita/documento admisible para gasto bancario; aprobar banco/recurso NIO permitido. No clasificar por nombre o código.
2. El contador debe confirmar cuentas reales y la equivalencia inicial con el vínculo bancario existente. No se autoriza cambiar saldos ni crear cuentas.
3. Aprobar el mecanismo durable de aprobación empresarial/habilitación, quién verifica su referencia documental y cómo se registra una retirada. Reutilizar roles existentes, sin deducir aprobación por publicar.
4. Elegir entre mantener CORE global más papeles del piloto o evolucionar explícitamente cobertura por evento, sin reinterpretar versiones publicadas.
5. Aprobar referencia nullable de cabecera y trazabilidad relacional por línea, o aceptar expresamente las limitaciones de JSON. Autorizar por separado la futura migración, primero en test.
6. Aprobar el instante para seleccionar vigencia: se propone el de aplicación financiera UTC, conservando por separado la fecha contable del documento. Resolver el tratamiento de fechas retroactivas; no elegirlo implícitamente.
7. Autorizar la preparación específica del camino de imputación y su diferencia de permisos respecto de `inspect()`, con el orden de locks descrito y pruebas reales. Hasta entonces `resolveForPosting()` continúa rechazando todo.
8. Resolver la dependencia de concurrencia del saldo con sus otros escritores antes de cualquier activación compartida; no modificar mesa de cambio/fondos/transferencias sin autorización.
9. Definir si una futura entrega incluye una reversión bancaria propia y cómo tratar conciliaciones; si no, conservar su bloqueo y no prometer reversión positiva operativa.
10. Autorizar exclusivamente implementación/pruebas que se especifiquen para AC-04B. La autorización de diseño actual no habilita políticas, registros financieros o despliegues.

## 12. Comprobaciones realizadas y cierre

Se realizaron búsquedas y lecturas estáticas del flujo, migraciones, modelos, permisos, pruebas y contratos AC-03A/B. Se utilizó la excepción de lectura por terminal autorizada expresamente por el usuario para esta fase, ante la ausencia de herramientas locales de workspace. No se usó la terminal para ejecutar la aplicación ni consultar SQL.

Git estaba limpio al comenzar. `SALON_TEST_DB_RESET_ALLOWED=0` se comprobó por lectura filtrada, sin imprimir credenciales; no se cambió. No se consultaron las bases: sus últimas cifras verificadas siguen siendo las documentadas en AC-03B (test 80 migraciones; desarrollo/bootstrap 74), **no una comparación SQL nueva en AC-04A**.

El único cambio de esta fase es `docs/AC_04A_PREPARACION_EVENTO.md`. No hubo DML, DDL, pruebas destructivas, importación Excel, cambios funcionales, cuentas nuevas, commits o despliegues. Para retirar la propuesta basta revertir este documento, conservando AC-03A/B y sus evidencias.

AC-04A termina con selección propuesta y condiciones explícitas. No se inicia AC-04B, AC-05, AC-06 ni H01-B2B.
