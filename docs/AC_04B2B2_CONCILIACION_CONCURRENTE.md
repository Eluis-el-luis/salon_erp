# AC-04B2B2 — Conciliación concurrente: inspección y condición de detención

Fecha: 2026-11-16. **Implementación detenida antes de cambios funcionales y pruebas SQL.** Se encontró una decisión pendiente sobre identidad/orden de cortes y arrastre de saldos. Se aplica la condición de detención de los apartados 4 y 9 de la autorización. AC-04B2B2 no se declara completada ni concurrentemente verificada.

## 1. Flujo real inspeccionado

- `BancoController::conciliacion()` (línea 486): GET de preparación. Lee pendientes/en_transito, saldo inicial del último extracto cerrado e historial mediante consultas separadas. No fija una versión de la preparación.
- `resources/views/banks/conciliacion.blade.php`: envía fecha, saldo_extracto, IDs de movimientos y observaciones. No envía identidad del extracto base usado para preparar la selección. El saldo inicial mostrado es informativo; el servidor no recibe uno manipulable como autoridad.
- `BancoController::guardarConciliacion()` (línea 517): valida fecha no futura, saldo no negativo y existencia de IDs; inicia transacción; bloquea únicamente los movimientos seleccionados; comprueba banco/estado/fecha <= corte; obtiene saldo inicial; valida diferencia; crea cabecera cerrada; marca seleccionados y pone otros pendientes anteriores al corte en_transito; commit.
- `FondosService::saldoInicialConciliado()` (línea 220): elige último cerrado por fecha DESC, id DESC, **sin limitarlo a la fecha solicitada ni tomar lectura actual bajo lock**. Sin cerrado devuelve cero.
- La conciliación **no modifica saldo_actual ni crea asiento**. Su período es el corte del extracto; no implementa por sí misma una regla de período fiscal abierto.
- `MovimientoBancario::signo()`: depósito/abono suman; retiro/cargo restan. Estados pendiente, en_transito, conciliado, más booleano legado y referencias fecha_conciliacion/conciliacion_id.
- `ConciliacionBancaria`: estado borrador/cerrada; el endpoint actual solo crea cerrada. No se localizó un flujo operativo de reapertura/edición de extractos cerrados.

La búsqueda de escritores de estado/referencias localizó el marcaje y transición en_transito en guardarConciliacion; cargos, abonos, depósitos, transferencias, piloto y mesa de cambio inicializan estados. Existe un backfill histórico en la migración corporativa; no es un escritor operativo y no se ejecutó. No se encontraron otros métodos de aplicación que reconcilien movimientos en esta inspección.

## 2. Esquema e identidad que faltan

La migración `2026_10_11_000001_bancario_mesa_cambio_corporativo.php` declara:

- Fecha de extracto DATE, no inicio/fin de intervalo ni número externo único del extracto.
- Saldos DECIMAL(15,2), estado borrador/cerrada y usuario.
- FK del banco, sin UNIQUE banco/fecha o identidad de corte.

Las migraciones contables posteriores inspeccionadas no añaden identidad de corte a conciliaciones. Los índices compuestos B1 de movimientos/asientos son de trazabilidad contable, no unicidad de extracto. Los índices/FK aquí descritos son los declarados en código: **no se inspeccionó information_schema en esta fase**.

Las FK históricas de banco a movimientos/conciliaciones incluyen CASCADE. No se alteraron ni se propone habilitar borrado; cualquier endurecimiento de esas FK sería DDL separado. No hace falta modificar esas restricciones para estudiar el lock del banco.

## 3. Riesgos y defectos por inspección

| Hallazgo | Clasificación y evidencia |
| --- | --- |
| Conciliación sin lock del banco | Riesgo concurrente: AC-04B0 serializa escritores por banco, pero conciliación no participa; bloquea solo seleccionados y después toca otros pendientes |
| Último cerrado sin lectura bloqueante | Riesgo de una base inconsistente/obsoleta y de dos cierres construidos sobre bases distintas a las preparadas |
| Fecha anterior al último cierre | Falta de contrato: el helper usa el último cerrado aun si es posterior al corte solicitado |
| Mismo banco/fecha sin identidad adicional | No se puede distinguir cierre duplicado de un segundo extracto legítimo del mismo día sin decidir qué representa fecha |
| Preparación sin referencia de base | La petición no permite detectar expresamente que otro extracto fue cerrado desde que el usuario preparó el formulario |
| saldo_libros acumula historia ya conciliada | Doble cómputo identificable en la expresión de líneas 565–569: saldo inicial del último extracto + todos los movimientos hasta la fecha, incluidos los de extractos anteriores |
| Filtro por estado/banco y fecha <= corte | Protección legítima existente para los seleccionados. No se debe eliminar al añadir locks |
| Diferencia y transacción | Control legítimo: diferencia redondeada a dos decimales debe ser inferior a 0,01; marcaje/cabecera ya comparten transacción. Falta comprobar rollback con fallos inyectados y Throwable |

No se ejecutaron reproducciones HTTP/MySQL ni carreras. Los siguientes son contraejemplos aritméticos del código, no resultados de pruebas ni registros encontrados en las bases.

### A. Arrastre contado dos veces

Cuenta hipotética con apertura cero:

1. Día 10: abono +100; se selecciona y cierra extracto 100. saldo_inicial=0, saldo_libros=100.
2. Día 20: abono +20; se selecciona solamente ese nuevo movimiento. El helper devuelve 100. El cuadre del extracto acepta 100+20=120.
3. El campo saldo_libros se guarda como **100 + (100+20) = 220**. Esto sucede incluso sin concurrencia.

No se corrige ese saldo en extractos anteriores. La entrega necesitaría acordar la definición prospectiva de saldo_libros, sin reconstruir historia ni ajustar saldo_actual.

### B. Cierre repetido con selección vacía

Después del primer extracto 100 del día 10, otra petición del mismo banco/fecha con saldo 100 y sin movimientos supera el cálculo: 100+0=100. No existe rechazo de fecha/base duplicada. El lock del banco haría las dos peticiones secuenciales, pero **por sí solo no impide ese segundo cierre**.

El sistema tampoco distingue de ese caso un posible segundo extracto bancario del mismo día: solo guarda DATE. No se impone unilateralmente “un extracto diario” como política nueva.

### C. Movimiento en tránsito anterior al cierre

Primer corte: abono +100 seleccionado y cargo -10 no seleccionado; saldo_extracto=100 y cargo queda en_transito. En un corte posterior ese cargo puede aparecer realmente en el banco: el cierre debe poder considerar 100-10=90 aunque la fecha original del cargo sea anterior al primer corte.

Filtrar todos los candidatos por fecha > último_cierre eliminaría indebidamente ese movimiento legítimo. Por eso no se debe resolver la ambigüedad añadiendo solo un filtro de fechas. Los movimientos ya conciliados y los pendientes/en_transito requieren tratamiento distinto.

## 4. Decisión mínima requerida antes de implementar

| Decisión | Alternativa conservadora propuesta | Alternativa con mayor alcance |
| --- | --- | --- |
| Identidad y orden del corte | Un cierre de fin de día por banco, con fecha estrictamente posterior al último cerrado; repetir/retroceder rechaza | Varios extractos por día o cortes rectificativos requieren identidad externa/orden explícitos y evaluar esquema aditivo |
| Arrastre de pendientes/en_transito | Mantener elegibles los no conciliados con fecha <= nuevo corte, incluidos los antiguos; no mover ni alterar los ya conciliados | Cambiar la semántica de tránsito necesita una política empresarial separada |
| Significado prospectivo de saldo_libros | Último saldo de extracto + neto de **todos los movimientos todavía no conciliados hasta el corte**, fotografiado antes del marcaje, seleccionados o no | Saldo absoluto del libro a fecha con base inicial documentada e independiente del extracto; necesita definir esa base y su evidencia |
| Preparación obsoleta | Referencia explícita al último cierre revisado, validada bajo lock; si cambió, rechazar y pedir recarga | Recalcular y aceptar otra base sin revisión expresa puede cambiar el significado de la selección |

No se eligió ninguna opción en código. Se solicita ratificar específicamente identidad/orden, arrastre, definición de saldo_libros y rechazo de preparación obsoleta. No hace falta crear una cuenta ni un asiento para resolver estas decisiones.

La primera opción de identidad podría implementarse con el esquema actual y serialización por banco, sin DDL. Si se necesitan múltiples extractos del mismo día, se debe presentar primero una migración mínima para identidad/orden y su unicidad; no modificar migraciones históricas ni añadir una UNIQUE banco/fecha que invalide historia existente.

El saldo inicial de una primera conciliación sigue siendo cero según el código actual. Este diagnóstico no autoriza sustituirlo por saldo_actual, por un dato del formulario o por una apertura ficticia. Cuentas con saldo inicial no documentado requieren identificar esa dependencia en el ensayo, no inventar su base.

## 5. Protocolo compatible propuesto, aún no adoptado

Después de aprobar el contrato de corte:

1. Validar sesión/rol bancario actual, banco/corte y payload. Comenzar transacción exterior.
2. `BankBalanceService::lockAccounts([bankId])`: banco X antes de fijar extracto o movimientos. Usar el modelo releído, no el recibido por route binding.
3. Releer el último cerrado bajo lectura actual/lock, comprobar identidad del corte y referencia de preparación. No confiar en un snapshot consistente anterior a la espera.
4. Leer los movimientos relevantes por banco y corte mediante lectura actual y orden estable por ID; validar pertenencia, moneda, estados y referencias coherentes. La selección no permite reasignar movimientos conciliados.
5. Calcular saldo inicial desde el documento autorizado y netos desde el conjunto bloqueado, usando exactamente el contrato monetario existente; aplicar la definición de saldo_libros aprobada. Validar diferencia cero sin ajustes.
6. Crear la cabecera y marcar/referenciar seleccionados; conservar la transición legítima de otros pendientes a en_transito. Todo dentro de la misma transacción.
7. Comprobar pertenencia de resultados y confirmar una vez. Ante Exception/Throwable, rollback total; sin reintentos financieros automáticos.

La preparación GET deberá ofrecer una vista coherente y la referencia de base revisada. Esa referencia es una precondición de concurrencia, **no un saldo inicial autoritativo del cliente**. Se propone un campo de contrato explícito para ID base, con ausencia de extracto representada expresamente; una petición sin esa precondición no debe pasar por una compatibilidad silenciosa. Es una ampliación focalizada de formulario/servidor pendiente de ratificación, sin CSS o UI-01.

No se propone GET_LOCK administrativo, locks de política, cuentas contables o período fiscal en la conciliación: no genera asientos. No se incorpora una regla de “período fiscal abierto” para marcar un extracto; si fuese necesaria, requiere decisión separada. Los escritores de cargos y demás operaciones conservan sus validaciones de período por ContabilidadService/AccountIntegrityService.

## 6. Coordinación con los escritores inspeccionados

| Camino | Orden observado | Convivencia propuesta |
| --- | --- | --- |
| Cargos/abonos directos | Transacción; BankBalanceService; movimiento/asiento; saldo | Conciliación espera banco o precede al cargo; no toca política/cuentas |
| Depósitos | Banco antes de caja/origen y efectos | Conciliación toma solo su banco y después extracto/movimientos, sin esperar caja |
| Transferencias | Bancos deduplicados y ordenados antes de recursos/asiento | Conciliación no toma un segundo banco; no invertir bancos por ID |
| Mesa de cambio | Bancos por ID antes de bóvedas, divisas y cuentas | No cambiar fórmulas ni agregar espera de divisas desde conciliación |
| Piloto B2A | Versión/autorización → banco → cuentas → UUID/efectos | Conciliación empieza en banco y no pide versión; no introducir banco → política |
| Preparación B1/administración B2B1 | Contratos documentales separados, sin marcaje de conciliación | No convertir consultas de política en autoridad ni cambiar métodos de simulación |

Por inspección, el lock local del banco es el punto compartido apropiado y no exige rediseñar FondosService. No demuestra ausencia de deadlocks ni carreras. Se deben ejecutar las parejas en ambos órdenes y verificar resultados antes de afirmarlo.

El único uso de saldoInicialConciliado encontrado en aplicación fue el flujo de conciliación, pero cualquier cambio del helper debe conservar el modo de consulta y exigir explícitamente el modo transaccional actual cuando corresponda. No modificar las resoluciones de cuentas de fondos.

## 7. Historia e integridad

No corregir cabeceras antiguas con saldo_libros inconsistente, no cambiar selección de un extracto ya cerrado y no poner a cero diferencias creando movimientos. Conservar origen, asiento, monto, banco, moneda y referencias originales.

La coordinación propuesta solo evita cambios mientras se prepara el nuevo cierre. Un cargo registrado **después** del cierre, incluso con fecha antigua, no forma parte retrospectiva de aquella fotografía. Debe conservarse pendiente para un cierre posterior conforme al contrato aprobado; no reabrir automáticamente el extracto. Si se desea prohibir o reclasificar esa contabilización retroactiva, excede esta corrección.

Los estados borrador/cerrada del esquema y pendiente/en_transito/conciliado de movimientos mantienen significado. No se añade estado de reversión. El futuro reversor de dominio deberá tomar el mismo banco antes de leer/marcar conciliación; no está implementado ni habilitado aquí.

## 8. Ensayos pendientes tras autorización

| Grupo | Casos y aceptación |
| --- | --- |
| Secuenciales | Cierre válido; diferencia rechazada; otro banco/corte futuro; conciliado ya vinculado preservado; última base válida; corte igual/anterior según decisión; preparación obsoleta |
| Arrastre | Dos extractos sucesivos del ejemplo 100→120; pendiente/en_transito antiguo que se liquida después; saldo_libros sin doble conteo y sin modificar saldo_actual |
| Dos cierres | Mismo banco/corte, conjuntos iguales, vacíos y disjuntos; solo resultados compatibles con la política aprobada, sin dos confirmaciones de un mismo cierre |
| Escritores heredados | Conciliación/cargo, abono, depósito y transferencia con conexiones independientes; ambos órdenes de adquisición; movimientos incluidos solo en el instante/documento correcto |
| Mesa de cambio | Fixture aislado autorizado que respete banco/divisas; no inventar tasa/costo ni cambiar fórmula. Si no es posible, declarar ese caso pendiente |
| Piloto sintético | Versión → banco frente a conciliación banco → extracto; autoridad simulation y UUID original intactos; nunca un consumidor empresarial |
| Rollback | Fallo después de cabecera, después de primer marcaje y antes del commit; cabeceras, booleanos, estado y referencias sin cambios parciales, incluyendo Throwable |
| Historia/reportes | Fotografías cerradas y asientos originales iguales; banco/libro/mayor conciliados; no nuevos asientos ni variaciones de saldo producidas por conciliar |
| Regresiones | AC-04B0/B2A y contratos afectados AC-02/03A/B/04B1/B2B1; sin repetir carreras persistentes ni alterar fixtures históricos |

Cada carrera debe dejar IDs de conexión/PID distintos, orden de barreras, salida/cierre de procesos y resultado persistido. Los resultados de AC-04B2A/B1 no sustituyen estas pruebas.

Existe una prueba secuencial de primer extracto en `tests/Feature/BancosMesaCambioTest.php` (saldo_libros 300); no acredita la sucesión de extractos ni concurrencia. No se ejecutó ni modificó. Las pruebas nuevas deben evitar RefreshDatabase, usar fixtures nuevos y respetar las evidencias existentes.

## 9. SQL, respaldo, guardias y bases

No se ejecutó SQL ni PHP/Artisan/PHPUnit. No hubo DML, DDL, copias/restauraciones, carreras o escrituras persistentes. Por tanto:

- No se afirma un nuevo preflight SQL ni una comparación actual de las tres bases.
- El último estado verificado es el de B2B1: test 81 migraciones/87 tablas/1364 filas; desarrollo 74/78/225; bootstrap 74/78/202. Son antecedentes, no mediciones nuevas.
- Antes del futuro ensayo persistente hay que crear el **respaldo actualizado** autorizado por B2B2, verificar salida/tamaño/estructura/SHA256, conservar los anteriores y tomar nuevas líneas base independientes.
- No se verificó nuevamente el SHA256 del respaldo antiguo ni se lo presenta como copia actual. No era necesario crear un dump para una inspección estática detenida antes de los escritores.
- El preparado ID131 y las carreras anteriores no se consultaron, confirmaron, eliminaron ni reutilizaron.

`SALON_TEST_DB_RESET_ALLOWED=0` se comprobó por lectura filtrada y no cambió. No hubo ejecución de pruebas que requiriera habilitarlo o un finally de ejecución SQL. No se cambiaron credenciales, permisos, APP_KEY, AGENTS.md, dependencias o configuración.

## 10. Cambios, diff y reversión

Único archivo añadido por esta fase: `docs/AC_04B2B2_CONCILIACION_CONCURRENTE.md`.

El trabajo AC-04B2B1 y documentos B2B0/B1 ya estaban modificados/sin seguimiento al comenzar. Se conservaron; no son cambios nuevos B2B2. No se tocaron BancoController, FondosService, BankBalanceService, modelos, rutas, vista de conciliación, pruebas o migraciones.

El diff específico de B2B2 es exclusivamente este documento. Para descartar esta fase, retirar solo el documento nuevo después de revisarlo; no hacer reset global ni revertir trabajo anterior. No existe reversión SQL o financiera que ejecutar. Sin commits.

## 11. Dependencias para la reversión bancaria

La reversión de dominio seguirá bloqueada. Necesita que la conciliación tenga un contrato inequívoco de corte/base y comparta el lock de banco; preservar extractos conciliados y períodos originales. Este bloqueo no autoriza reabrir extractos, emitir abonos, copiar asientos o activar el piloto. Tampoco autoriza las migraciones de reversión propuestas en B2B0.

## 12. Punto de detención y autorización requerida

La ausencia de política explícita de cortes y la expresión de saldo_libros impiden declarar íntegro un cierre solo añadiendo locks. Se detiene la implementación antes de imponer restricciones o cambiar un campo financiero/documental histórico.

Para reanudar, ratificar o sustituir las cuatro propuestas de la sección 4: cierre cronológico único por banco/fecha, conservación de pendientes/en_transito antiguos, fórmula prospectiva de saldo_libros y rechazo de una preparación cuya base cambió. Confirmar que la corrección solo se aplicará a extractos nuevos y que no se reconstruirán los anteriores.

Con esas decisiones se podrá implementar el protocolo banco → extracto → movimientos y ejecutar el alcance B2B2 autorizado. Si se eligen múltiples extractos diarios o rectificaciones históricas, presentar primero el contrato y eventual migración para autorización específica. No se continúa con reversión bancaria, habilitación empresarial, AC-05/06, H01-B2B ni despliegue.
