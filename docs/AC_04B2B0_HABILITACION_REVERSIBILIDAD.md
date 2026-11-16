# AC-04B2B0 — Habilitación empresarial y reversibilidad bancaria

Fecha: 2026-11-16. **Diseño propuesto; no habilitación ni política empresarial aprobada.** Solo inspección estática. No se ejecutaron PHP, Artisan, SQL, pruebas, migraciones ni operaciones financieras.

## 1. Estado y alcance

AC-04B2A acreditó en test equivalencia, trazabilidad e idempotencia del cargo NIO sintético: 115 pruebas, 817 aserciones y seis carreras finales. Estas cifras son antecedentes, no ejecuciones de esta fase. Su escritor exige CLI, bootstrap seguro, esquema/cuenta SQL de test y autorización simulation; no debe convertirse en escritor empresarial eliminando esas condiciones.

Primer evento futuro: `bancos.cargo_gasto.nio`, clasificación persistente `maintenance` o `commission`, un banco autorizado NIO, dos líneas: DEBE `gasto.cargo_bancario` / HABER `fondos.banco`. La cuenta de fondos debe ser exactamente la vinculada al banco. No se amplía a abonos, transferencias, divisas, inventario, COGS, nómina o ventas. Un movimiento inverso propuesto abajo es una corrección de dominio del cargo original, no la habilitación configurable del evento general de abonos.

Fuentes inspeccionadas: AccountingPolicyService, AccountingAccountResolver, AccountingEventAuthorizationService, AccountingEventIdentityService, AccountingBankChargeSimulation, AccountingSimulationPosting/Boundary, BankBalanceService, AccountIntegrityService, ContabilidadService, FondosService::saldoInicialConciliado, BancoController, AsientoManualController, PeriodoContableController, modelos bancarios/contables, rutas y las dos migraciones contables de 2026-11-16.

### Hallazgos comprobables en el código

| Evidencia | Consecuencia para la implementación futura |
| --- | --- |
| `resolveForPosting()` y `assertBusinessAuthority()` rechazan; los métodos ForSimulation exigen testing | Hace falta un contrato empresarial propio, no reutilizar una inspección técnica como permiso |
| `operation_contract_ck` solo admite prepared/confirmed con resultados NULL/completos respectivamente | No escribir rejected/cancelled/reversed en esa columna sin una migración autorizada |
| UUID UNIQUE; FK compuestas vinculan operación, habilitación, banco, asiento y correspondencias | Reutilizar identidad y prueba de pertenencia; no relajar las FK |
| `auth_parent_action_uq` permite una sola enablement y revocation por padre/acción | Una habilitación retirada no se reactiva sobrescribiéndola; requiere un nuevo ciclo documental |
| BancoController::guardarConciliacion bloquea movimientos, pero no toma BankBalanceService ni bloquea primero el banco | Antes de una reversión concurrente será necesaria coordinación focalizada con conciliación |
| FondosService toma el último extracto cerrado por fecha/ID; no hay revisión versionada de conciliaciones | No reabrir/recalcular extractos cerrados como efecto secundario |
| MovimientoBancario carece de relación estructurada de reversión; signo() considera abono/depósito positivos | Un abono suelto o una descripción REV no garantizan reversión única y trazable |
| AsientoManualController::reversar exige origen manual y contabilizado | Un CBA automático debe seguir el dominio bancario |
| AccountIntegrityService::posting permite cuentas históricas solo al contrastar líneas inversas con un original; exige período destino abierto | Puede reutilizarse la validación histórica; no basta crear un asiento equilibrado sin origen |
| cierreFiscal bloquea período X; posting relee período S; resolverPeriodo empieza con lectura no bloqueante | El futuro escritor debe mantener una validación actual de período dentro de su transacción |

Son hallazgos estáticos. No se reprodujeron carreras ni se comprobó el estado SQL actual.

## 2. Aprobación empresarial verificable

Separar cuatro actos, cada uno autenticado y auditable:

1. **Preparación/publicación técnica:** AccountingPolicyService conserva borrador, manifiesto, fotografías, hash y publicación inmutable. Ningún cargo queda habilitado por publicar.
2. **Revisión y aprobación empresarial:** contador/alvaro revisa versión/hash completos, las dos cuentas efectivas, naturaleza/tipo/imputabilidad, banco y moneda, vigencia y documento de aprobación. Registra aceptación expresa con referencia y motivo. El servidor relee todo bajo locks y obtiene actor/timestamp de sesión/reloj del servidor.
3. **Habilitación del evento:** acto separado ligado a la aprobación, para evento, banco, NIO, entorno autorizado e intervalo UTC [desde,hasta) contenido en el de aprobación/política. El servidor comprueba unicidad de autoridad elegible. No inferir habilitación de una aprobación.
4. **Retirada:** append de revocation del acto exacto, con actor, referencia, motivo y hora UTC del servidor. Efectiva al commit; no retroactiva. Consultas y documentos confirmados conservan autoridad histórica, sin permiso para cargos nuevos.

Las columnas y JSON versionado de `accounting_policy_authorizations` ya permiten propósito business, referencia, actor, alcance, ventanas y cadena approval→enablement→revocation. La futura aplicación debe comprobar también los límites de ventanas y la evidencia padre; una FK no prueba aprobación válida. No alterar purpose, hash, padre o alcance de filas existentes. Retirar aprobación invalida sus habilitaciones para operaciones nuevas; conservar toda la cadena. Para una nueva habilitación, cerrar documentalmente el ciclo anterior y crear una aprobación nueva cuando sea necesario; no violar la unicidad padre/acción ni editar una retirada.

### Procedencia empresarial frente a sintética

`kind=enterprise` no distingue procedencia: las versiones sintéticas también lo usan. No clasificar por etiqueta, año 2088/2099, nombre de cuenta o referencia libre.

Propuesta mínima: el futuro flujo de creación empresarial registra **desde el origen**, dentro del acto created de auditoría existente, metadatos estructurados de procedencia empresarial y contrato/instalación, asignados por el servidor. La evidencia de aprobación vincula el ID y hash canónico de esa procedencia, además del hash exacto de política y documento aprobado. Una versión preexistente sin procedencia verificable no es elegible; no añadirla retrospectivamente. Si se copia contenido de referencia, crear un borrador nuevo y revisarlo íntegramente, sin heredar autorizaciones.

El JSON de auditoría puede conservar esos metadatos sin convertir un evento antiguo ni modificar el hash de una versión publicada; su verificación requerirá una extensión explícita del contrato de aplicación. No añadir eventos fuera de los cuatro permitidos por CHECK. Si se exige procedencia relacional/FK en lugar de la cadena documental, proponer una migración antes de implementar, no usar descripciones como sustituto.

La autoridad empresarial deberá verificar además la identidad de instalación/esquema y entorno autorizados por configuración administrativa aprobada. APP_ENV, un ID del cliente o un booleano no bastan. No admitir trasplante de filas simulation, ni conversión manual de purpose. No hay configuración real aprobada en esta entrega. La evidencia durable protege dentro de los servicios autorizados, no frente a SQL administrativo que reescriba historia.

### Confirmación de revisión

La pantalla muestra cuenta/ID/código, papel, banco, moneda, período de vigencia y hash; código/nombre sirven para revisión, no para resolver automáticamente. Un selector de versión es una referencia no confiable: el servidor verifica permisos y reconstruye contenido. Hash esperado/challenge de revisión asociado a sesión y contenido permiten rechazar una revisión obsoleta; no constituyen por sí mismos autoridad. No recibir IDs de aprobador, ejecutor o una cuenta contable como resultado autoritativo del formulario.

## 3. Permisos e interfaz propuesta

Las rutas reales de bancos admiten admin/contador y el bypass alvaro. Las contables admiten alvaro/contador; RoleMiddleware actual **no da bypass a admin**. No ampliar cajera/recepcion ni crear roles.

| Acción propuesta | Identidad/alcance |
| --- | --- |
| Preparar asociaciones, publicar, aprobar, habilitar y retirar | contador/alvaro activos; controles del servicio además del middleware |
| Registrar cargo y consultar su UUID | operador bancario actual admin/contador/alvaro; banco habilitado y permiso actual comprobados |
| Solicitar corrección | operador bancario autorizado, sobre documento visible y alcance permitido |
| Aprobar/confirmar corrección de dominio | propuesta contador/alvaro; requiere ratificación del propietario |

Distinguir aprobación de política y ejecución del cargo aunque una persona tenga ambos permisos. No imponer silenciosamente dos personas distintas: la segregación obligatoria de funciones es una decisión empresarial pendiente. No usar `confirmedResult()` técnico como ACL empresarial suficiente: hoy su contrato de consulta está diseñado para ensayo y no cubre por sí solo todos los operadores bancarios.

Interfaz mínima futura: revisión de política con historial; aprobación y habilitación separadas; cargo con banco/clasificación/importe/fecha/referencia; resumen de cuentas resueltas de solo lectura; pantalla de preparado/resultado consultable por UUID; enlaces a movimiento, asiento, política y evidencia original; solicitud de corrección. Sin selector de contrapartida para el piloto. Ninguna ruta/vista se crea ahora.

Sesión web, CSRF real y permisos en todos los endpoints de mutación; respuestas JSON explícitas si la interfaz usa fetch. Contrato propuesto: 401 sesión, 403 permiso, 422 datos inválidos, 409 conflicto de UUID/política/preparado, 419 sesión/CSRF; error transitorio con estado financiero desconocido exige consultar UUID antes de otra acción. No mostrar SQL, trazas, cookies o credenciales. Un UUID no es un token de acceso.

Los otros cargos/abonos siguen su camino heredado. No decidir que todo cargo cuyo concepto mencione mantenimiento pertenezca al piloto. La futura selección del contrato configurable debe ser explícita; tampoco debe permitirse que un fallo de su autorización derive silenciosamente al camino heredado. La obligación de usarlo para ciertas operaciones/bancos requiere decisión empresarial expresa.

## 4. Documentos preparados e identidad

Mantener los dos estados actuales. `confirmed` expresa resultado original íntegro e inmutable, no “sigue sin reversión”. Una futura reversión se consulta mediante su propio documento enlazado.

| Situación | Contrato propuesto |
| --- | --- |
| Prepared completo | Mostrar pendiente de confirmación; no reservar saldo ni afirmar éxito; requiere nueva validación actual de permiso, autoridad, banco, cuentas y período |
| Prepared incompleto o hash/referencias inválidos | Bloquear y enviar a revisión; no reconstruir usando la política vigente ni reparar contenido silenciosamente |
| Mismo UUID/contenido/actor | Si confirmed: mismo resultado histórico; si prepared: continuar solo con contrato válido; nunca dos cargos |
| Mismo UUID con otro contenido/actor/autoridad | Rechazar; no reemplazar preparado ni reasignar propietario |
| Respuesta HTTP perdida | Consultar resultado por UUID con autorización; si confirmado devolver IDs originales, incluso tras revocación posterior |
| Interrupción antes del commit | Rollback de efectos; puede subsistir un prepared creado previamente; consultar y continuar explícitamente, sin retry automático |
| Revocación antes de confirmar | Mantener prepared bloqueado sin efectos; no atribuir permiso antiguo a una ejecución nueva |
| Cambio/fin de vigencia de política | No sustituir versión/hash en el preparado; revisión expresa y nueva identidad si el contrato debe cambiar |
| Período cerrado al continuar | Rechazo sin efectos; no cambiar fecha automáticamente. Una fecha nueva altera contenido y requiere documento/revisión nuevos |
| Solicitudes simultáneas | UUID UNIQUE, lectura actual del resultado y locks de recursos; consulta posterior tras contención, sin repetir cargo automáticamente |

El preparado fija la intención/versionado revisado, pero **no garantiza derecho a contabilizar después**. Se propone seleccionar la autoridad definitiva en la aplicación financiera UTC; si difiere de la revisada, detener y pedir revisión. No migrar un preparado a otra política. La ventana de autorización y la fecha contable son datos distintos: la política de fechas retroactivas/futuras aún debe aprobarse. No introducir esta regla en ventas retenidas.

No se necesita un nuevo status para mostrar “rechazado”: distinguir rechazo de un intento HTTP de rechazo terminal de un documento. La UI puede mostrar “prepared bloqueado: habilitación retirada” mediante una evaluación actual, sin mentir sobre el estado persistido. Si se aprueban abandono definitivo, reemplazo o expediente de intentos, añadir un registro append-only de ciclo de vida con UUID/operación, causa, actor y referencia sucesora; no reutilizar auditoría de políticas para eventos operativos ni sobrescribir content/hash. Esa mejora requiere diseño/migración autorizados y no forma parte de las 81 actuales.

**ID 131, banco 221:** el informe B2A documentó prepared, resultados NULL, saldo 1000.00 y cero movimientos. Se usa solo como ejemplo de inspección y bloqueo, no como éxito. No se consultó, confirmó, eliminó ni reparó en esta fase.

## 5. Reversión bancaria de dominio

No existe hoy un reversor bancario completo. Se propone limitar el primero a **reversión total de un cargo confirmado del piloto**, con toda su evidencia verificable. No extenderlo a cargos legacy sin clasificación/política ni a reversas parciales. Un cargo realmente cobrado no deja de existir porque el operador lo considere improcedente: distinguir error de registro constatado de devolución/cancelación real del banco, con comprobante y fecha efectivos. Las condiciones documentales admisibles necesitan aprobación contable.

### Contrato y evidencia

- UUID/hash propios de la corrección; operación, movimiento y asiento originales resueltos desde servidor.
- Motivo estructurado y descripción; referencia de banco/acta y evidencia del error o devolución; fecha efectiva y fecha contable aprobadas.
- Solicitante, aprobador y ejecutor autenticados, hora UTC del servidor y autorización de corrección. La habilitación original no autoriza por sí sola una reversa nueva.
- Versión/hash/habilitación **originales como procedencia**, no como política vigente aplicada de nuevo.
- Correspondencia exacta entre cada línea original y su inversa, con cuenta, moneda, centro de costo y demás metadatos contables originales pertinentes.
- UNIQUE del original cubierto para impedir dos reversiones completas, además del UUID propio. Una corrección posterior de la reversa queda fuera de este primer contrato.

No usar `accounting_event_operations` para emitir un nuevo “cargo negativo”: su CHECK exige importe positivo, las referencias describen un cargo y las correspondencias actuales están vinculadas al asiento original. No cambiar la operación original de confirmed a reversed ni cambiar su resultado.

### Persistencia mínima propuesta, no autorizada aún

Dos tablas nuevas de dominio: cabecera `bank_charge_reversals` y líneas `bank_charge_reversal_lines` (nombres propuestos). Cabecera con UUID UNIQUE/hash, FK RESTRICT y UNIQUE de operación/movimiento original, evidencia versionada, actores, fechas y enlaces únicos a movimiento/asiento inversos. Líneas con FK al detalle original y al inverso, UNIQUE para cobertura única y pertenencia al asiento de cada cabecera mediante claves compuestas cuando sea posible. Preparación/aprobación/confirmación de esta corrección deben tener contratos propios; solo un resultado completo puede ser confirmed.

La cabecera original ya enlaza su política y habilitación: esas FK sirven como origen histórico. No insertar las líneas inversas como si fueran resoluciones nuevas del evento cargo ni inventar una habilitación vigente. La cabecera de asiento inverso puede conservar NULL en los tres campos de selección de política y vincular su procedencia mediante la tabla de reversión; debe quedar explícito en consultas que es reversa del asiento con política, no un asiento empresarial sin trazabilidad. Si se elige duplicar metadatos de política en ella, se requiere otro contrato explícito que distinga procedencia de selección; no copiar campos por conveniencia.

Las 81 migraciones no proporcionan unicidad/FK de reversión bancaria ni referencias por línea. Por tanto, **la reversibilidad completa requiere una migración nueva aprobada**, no basta un JSON en concepto o un número REV. No se crea aquí.

### Efectos propuestos y límites

En una transacción: verificar original íntegro/contabilizado, relaciones, dos correspondencias, inexistencia de reversa, condiciones bancarias y autorización; crear movimiento inverso positivo tipo abono sobre el **mismo banco y moneda**, ligado al documento de corrección; crear asiento espejo por ContabilidadService; registrar enlaces por línea; aumentar saldo en el importe original una sola vez; conciliar; commit. Para C$50 originales: saldo corriente +50 y DEBE banco/HABER gasto por exactamente 50. No volver a resolver mediante AccountingAccountResolver ni costo/tasa vigentes.

No llamar simplemente a `contabilizarMovimientoBancario(abono, gasto)`: ese camino exige ingreso para abonos. Se necesita un método delimitado de reversión histórica en ContabilidadService que copie/invierta las líneas y termine con `validarCuadre(inverso, original)`, reutilizando AccountIntegrityService. No cambiar las reglas ordinarias de abonos para conseguirlo.

Cuenta contable original inactiva: AC-02 permite reversa histórica exacta con origen verificado, no imputación nueva arbitraria. Banco inactivo: BankBalanceService lo rechaza; detener para decisión explícita, sin añadir un bypass. Si cambió `cuenta_contable_id` del banco respecto de su línea original, detener: acreditar/debitar otra cuenta actual o migrar saldos excedería el alcance. Un mero cambio de asociación de política no cambia las cuentas históricas y no debe afectar la reversa.

Preservar original, conciliación y líneas; mostrar la relación de corrección sin borrar/desconciliar ni cambiar importes. Si falla cualquiera de las partidas/referencias, rollback completo. No generar ajustes para cuadrar diferencias.

## 6. Conciliaciones y períodos

| Condición | Regla conservadora propuesta y aprobación pendiente |
| --- | --- |
| Original pendiente/en_transito, sin extracto cerrado que cubra la corrección | Permitir solo con evidencia y aprobación; original e inverso conservan identidad y se revisan en conciliación, nunca se marcan conciliados automáticamente |
| Original conciliado | No reabrir ni alterar extracto. Proponer reversa como movimiento nuevo en un extracto posterior, solo si el banco documenta el efecto y el contador aprueba fecha/tratamiento |
| Error de conciliación histórica, sin devolución bancaria nueva verificable | Bloquear este reversor; requiere procedimiento separado de rectificación de conciliación, todavía no definido |
| Período original abierto | Fecha de corrección explícita en período abierto; no imponer igual fecha ni backdate automático |
| Período original cerrado | Proponer corrección en período abierto autorizado, conservando fecha/origen históricos. No reabrir ni alterar cierre; el contador debe aprobar si procede y la fecha de reconocimiento |
| Período destino cerrado/inexistente/ambiguo | Cerrado o ambiguo: rechazar. Creación mensual automática solo si conserva la regla existente y está aprobada para el evento; nunca usarla para eludir un cierre |
| Política o habilitación original retiradas | No invalidan historia; la corrección necesita autorización propia vigente, no una nueva selección de cuentas. Ratificar esta regla antes de implementar |

Compensar dos movimientos no conciliados que nunca estuvieron en el extracto requiere decidir su evidencia/selección en la próxima conciliación. No inventar referencias de banco ni excluir filas de los reportes para cuadrar. Las conciliaciones seguirán exigiendo diferencia cero; no contabilizan diferencias automáticamente.

## 7. Protocolo de bloqueos propuesto

No está probado por esta entrega. Debe contrastarse con ejecución real antes de activación. Mantener transacción exterior única y ninguna operación de administración mientras se mantiene el banco bloqueado.

**Administración:** mutex AC-02 únicamente en edición/publicación/aprobación/retirada; versión X antes de recursos y cuentas. Nuevas aprobaciones/habilitaciones del mismo alcance deben serializarse en la versión y revalidar ausencia de otra autoridad elegible. Retirada X de versión frente a cargo S fija qué acto confirma primero; no publicar vigencias solapadas ni mutar políticas publicadas.

**Cargo empresarial futuro:** descubrimiento sin lock de recorrido; versión seleccionada S por PK → aprobación/habilitación y evidencia S → bindings del alcance → banco X → período destino actual S → cuentas S ordenadas por ID → UUID/operación X → nuevos movimiento/asiento/detalles/correspondencias. Revalidar todo antes de escribir y al comprobar el resultado. La posición exacta del período es una precaución para coordinar cierre y cuentas; requiere revisar la integración de los métodos existentes, no supone que B2A ya use ese orden completo.

El preparador B1 tomó recursos/cuentas antes de UUID; conservar ese orden relativo. No tomar UUID y después esperar al banco. Si una operación requiere simultáneamente varias versiones/bancos/cuentas, orden ascendente por ID dentro de cada clase, sin introducir ahora operaciones multibanco. No hacer un nuevo scan bloqueante de todas las políticas después de tomar recursos.

**Conciliación futura:** banco X primero → última conciliación/cabeceras relevantes → movimientos por ID con lecturas actuales; calcular de nuevo saldo inicial y selección; validar cero; guardar. El mismo lock del banco debe cubrir marcación en_transito y prevenir dos cierres simultáneos del mismo corte. No hay que tomar versión o cuentas contables porque no crea asientos. El método actual debe adaptarse focalizadamente antes de compartir este protocolo con el reversor; no basta cambiar solo la reversa.

**Reversa futura:** preleer referencias como pistas → versión original S por PK solo para procedencia/serialización cuando corresponda → banco X → conciliación relevante y movimiento original X → asiento original/detalles y documento de corrección X → período destino S → cuentas históricas S en orden → identidad/resultados de reversión y escrituras. Releer todo el conjunto bajo locks; el asiento original automático no puede ser alterado por el flujo manual. No requerir que la autorización original siga vigente para copiar historia. No adquirir versión después de banco.

Catalogación AC-02 usa mutex y cuentas X; no debe añadirse espera de banco/versión desde ese camino mientras conserva cuentas X. Cierre fiscal toma período X antes de sus cuentas; la nueva integración debe probar su convivencia con los caminos heredados que toman cuentas antes de validar período. La ausencia de una inversión directa por inspección no demuestra ausencia de deadlocks con colas de locks/terceras sesiones. Si las pruebas requieren cambios fuera de sincronización autorizada, detener antes de activar.

No usar GET_LOCK administrativo en cargos/reversas ni reintentos financieros automáticos. Ante timeout/deadlock: rollback, resultado controlado y consulta de identidad; no suponer que una respuesta HTTP perdida significa rollback. Los asientos/detalles nuevos quedan dentro de la transacción; no commits intermedios.

## 8. Cobertura y versiones

Contrato actual v1: seis CORE obligatorios en publicación **y** resolución; manifiesto exacto, sin precedencia global/específica y sin solapamientos de vigencia entre políticas publicadas. Puede conservarse provisionalmente con CORE global y dos slots específicos del único banco piloto; solo el evento habilitado utilizará sus asociaciones. No implica activar ventas/inventario. Su desventaja es exigir al contador revisar asociaciones ajenas al piloto y poder bloquear el cargo por fotografías CORE incompatibles.

No habilitar en v1 una política que haga al piloto bloquear artículos u otros bancos: B2A restringe ese alcance para no mezclar protocolos físicos. No debilitar CORE para resolver la incomodidad.

Recomendación de evolución posterior: contrato v2 con manifiesto por familia/evento y selección única dentro de ese contrato, sin precedencias implícitas. Nueva migración para admitir contract_version=2 (actual CHECK solo admite 1), identificador de familia en cobertura/selección y restricciones de solapamiento acordes; hash incluye el contrato nuevo. Lectores v1 conservan su significado y sus restricciones; versiones publicadas v1 y documentos anteriores no se convierten ni recalculan. El despliegue debe probar coexistencia, rechazo de consumidores incompatibles y selección no ambigua antes de admitir publicaciones v2. Esa evolución y su priorización requieren autorización separada.

## 9. Cambios mínimos y secuencia posterior

1. Ratificar decisiones de la sección 11. No hay autorización empresarial efectiva por aprobar este diseño.
2. Implementar primero administración empresarial/documentación de procedencia: extensiones delimitadas de PolicyService/AuthorizationService y validaciones, sin convertir ForSimulation ni abrir escritores financieros. UI/rutas con roles contables actuales.
3. Diseñar/aprobar esquema aditivo de reversión y, solo si se requiere abandono durable, ciclo de vida. Preservar las 81 migraciones históricas; instalación/rollback vacíos y rechazo con evidencia en test.
4. Coordinar conciliación bancaria con BankBalanceService y desarrollar reversa histórica en ContabilidadService, sin ampliar reglas de abonos. Probar período/catálogo/conciliación y corrección completa.
5. Crear escritor empresarial propio y entrada HTTP delimitada: reutilizar normalización/identidad, resolver y motor; frontera empresarial independiente de la de ensayo. Consulta autorizada de UUID y comprobación actual de roles del operador en servicio.
6. Probar integración completa y reportes en test; revisión humana de evidencias y plan de despliegue/respaldo separado. Ninguna activación automática desde una política publicada o un fixture.

Archivos potenciales: servicios contables/identidad/autorización/resolución, BankBalanceService para las validaciones estrictamente necesarias, BancoController::guardarConciliacion, un servicio pequeño de corrección bancaria, requests/controladores/vistas específicos bajo rutas web protegidas, modelos de reversión y migración nueva autorizada. No modificar AsientoManualController para admitir automáticos, ni cambiar FondosService, inventario, nómina o fórmulas de divisas. Una migración de cobertura v2 sería otra entrega explícita.

## 10. Pruebas y aceptación futura

| Grupo | Escenarios y resultado exigido |
| --- | --- |
| Autoridad | Sesión contador/alvaro activa, revisión de hash/cuentas, referencia válida; actor/propósito manipulados, política demo/sintética/sin procedencia o borrador: rechazo; aprobación y habilitación auditadas por separado |
| Operador | admin/contador/alvaro según banco habilitado; cajera/recepcion/anónimo/inactivo: rechazo; aprobación de política no sustituye permiso de ejecutar; IDs de cuenta/actor del cliente no gobiernan asiento |
| Cargo | 50 sobre 1000 produce 950, dos líneas y correspondencias exactas, período abierto; política incompleta/inactiva/fecha no admitida: cero efectos; camino heredado conserva sus reglas |
| HTTP/identidad | CSRF real activado, sesión expirada, respuesta perdida y consulta por UUID; misma identidad devuelve original, contenido/actor distintos rechazan, prepared inválido no se completa silenciosamente |
| Preparado | Revocación, nueva versión, corte de vigencia y cierre antes de continuar: bloqueo sin migrar contenido; prepared completo legítimo confirma solo una vez; ejemplo ID131 no se usa como fixture mutable |
| Carreras | Dos UUID iguales; cargos distintos; cargo/heredado; publicación y retirada antes/durante cargo; aprobación/habilitación incompatibles; prepared/confirmación; conexiones MySQL distintas y persistencia final comprobada |
| Reversa | Antes de conciliación; original conciliado con evidencia bancaria válida; período original cerrado/destino abierto o cerrado; nueva política posterior; cuentas originales inactivas; banco inactivo o vínculo al mayor cambiado: aplicar regla aprobada o detener, nunca sustituir cuentas |
| Duplicación | Reversa repetida con mismo UUID devuelve mismo documento; otro UUID sobre original ya cubierto rechaza; dos reversiones concurrentes solo una confirma; cargo/reversa y conciliación/reversa se serializan |
| Atomicidad | Fallo después de movimiento, cabecera, primera línea, correspondencias, saldo y evidencia: ningún cambio financiero/documental parcial; original preservado; no retry del arnés que duplique movimientos |
| Conciliación/cierre | Dos cierres bancarios del mismo corte, conciliación durante cargo/reversa y período cerrándose durante operación; cero diferencias artificiales y ninguna reapertura implícita |
| Reportes/historia | Diario/mayor/balance/resultados/libro: cargo -50 banco/+50 gasto; reversa +50 banco/-50 gasto en fecha aprobada; originales y extractos cerrados intactos; cuadre exacto por cuenta, no solo totales |
| Compatibilidad | Regresiones focalizadas AC-02/03A/03B/04B0/04B1/04B2A, 81 migraciones previas intactas, CORE v1 conservado; no escrituras de inventario/nómina ni cambios de fixtures históricos para hacer pasar pruebas |

No considerar suficiente una prueba secuencial de concurrencia, un postJson con CSRF desactivado ni un asiento que solo cuadre globalmente. Cada carrera debe dejar IDs de conexión, resultado persistido, salida de procesos y evidencia de cierre. Antes de futuras pruebas SQL: autorización de fase, preflight, respaldo/SHA256 y nuevas líneas base; ningún escenario toca desarrollo/bootstrap.

## 11. Decisiones humanas requeridas

| Decisión | Responsable / propuesta conservadora |
| --- | --- |
| Cuentas reales y banco piloto, equivalencia con su mayor | Contador aprueba versión/hash y asociaciones exactas; sin crear/migrar cuentas |
| Procedencia y documento de aprobación empresarial | Contador/propietario ratifican evidencia admisible y responsable de revisión; versiones antiguas sin origen verificable no se promueven |
| Entorno/instalación y fechas permitidas | Propietario y contador fijan destino empresarial, vigencias, fecha contable, límites de retroactividad y fecha definitiva de aplicación |
| CORE temporal o contrato por evento | Aprobar conservar v1 con su costo de revisión o autorizar evolución v2 separada; nunca relajar v1 en silencio |
| Segregación | Ratificar roles actuales, autoaprobación permitida/prohibida y quién confirma correcciones; no introducir nuevos permisos por inferencia |
| Preparados retirados/reemplazados | Aprobar conservación bloqueada y, si se necesita abandono durable, autorizar su registro aditivo; no reutilizar UUID ni cambiar hash |
| Evidencia de reversión | Contador define error constatado vs devolución bancaria completa y documentación suficiente; una solicitud del operador no prueba retorno bancario |
| Conciliados | Ratificar reversa posterior documentada o bloqueo; rectificar conciliación histórica requiere otro procedimiento, no desconciliar automáticamente |
| Períodos | Aprobar tratamiento en período abierto posterior y fecha de reconocimiento; nunca reabrir/cambiar cierre implícitamente |
| Autoridad retirada/cuentas inactivas | Ratificar corrección histórica autorizada independiente de nueva política, y bloqueo de bancos inactivos/vínculos contables cambiados hasta decisión específica |
| Implementación | Autorizar migraciones nuevas, coordinación de conciliación, servicios/UI y pruebas focalizadas por etapas; despliegue y habilitación real requieren aprobación posterior independiente |

Hasta resolver estos puntos, el consumidor empresarial permanece cerrado. No hay una cuenta seleccionada, habilitación real, reversor operativo ni fecha de activación aprobados por este documento.

## 12. Verificaciones, riesgos y cierre

Se hicieron solo lecturas/búsquedas y revisión de Git. El único archivo creado es este documento; Git estaba limpio al iniciar. `SALON_TEST_DB_RESET_ALLOWED=0` se comprobó por lectura filtrada y no se cambió. No se ejecutó ningún comando de aplicación o SQL, ni se accedió al contenido de las bases.

El último estado verificado por B2A sigue siendo test 81 migraciones y desarrollo/bootstrap 74; **no es una comprobación nueva de integridad en B2B0**. ID131 y todas las evidencias sintéticas se conservan sin intervención. No hubo cambios funcionales, migraciones, credenciales, configuración, APP_KEY, AGENTS.md, Excel, cuentas o commits.

Riesgos pendientes: procedencia empresarial aún no implementada; corrección bancaria sin esquema propio; coordinación de conciliación/cierre no ensayada; alcance CORE y permisos del operador diferentes del arnés; decisiones contables de fechas/extractos. Las propuestas de locks no acreditan concurrencia real y deben pasar las carreras descritas antes de activar. Ante una dependencia fuera de sincronización/documentación autorizada, detener la implementación futura con evidencia.

Reversión de esta fase: retirar únicamente este documento si se descarta el diseño; no tocar esquema ni evidencia de AC-04B2A. Se termina AC-04B2B0 sin continuar con habilitación empresarial, AC-05, AC-06, H01-B2B o despliegues.
