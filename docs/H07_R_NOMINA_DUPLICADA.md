# H07-R — Nómina ordinaria duplicada y períodos solapados

Fecha: 21 de noviembre de 2026. Alcance: diagnóstico actual, corrección sin migraciones y pruebas aisladas. La concurrencia y atomicidad InnoDB quedan pendientes para el laboratorio Debian independiente.

## 1. Estado actual de H07

El hallazgo del 11 de noviembre seguía abierto en `NominaService::liquidar`: cada llamada a `procesar` creaba otra corrida y otras colillas sin consultar cobertura previa. `calcularColilla` incluye nuevamente el salario base aunque ya no existan comisiones pendientes. El número `NOM-{salary_slips.id}` distingue documentos, pero no evita repetir días de un empleado.

Ya estaban implementados: transacción exterior, consumo de comisiones congeladas, selección de propinas/cuotas pendientes, cap de adelantos, asientos por colilla y anulación con reversión. Se conservaron esos contratos. No se encontró una corrección posterior específica de H07 en los documentos del repositorio.

H07-R incorpora protección temporal en el caso de uso y coordinación con anulación. **Corrección implementada y regresión aislada aprobada; validación SQL concurrente pendiente.** No se afirma cierre integrado del hallazgo ni ausencia de duplicados históricos, que no se consultaron.

## 2. Rutas y casos de uso vigentes

Definidas en `routes/web.php`, dentro de `auth` y `role:admin`. `RoleMiddleware` permite también el bypass vigente del propietario `alvaro`; no se cambiaron permisos.

| Ruta | Método/caso de uso |
| --- | --- |
| GET `/nomina`, `/nomina/wizard` | Listado y selección de empleados/estructura |
| POST `/nomina/previsualizar` | `NominaController::previsualizar` → `NominaService::previsualizar` |
| POST `/nomina/procesar` | `NominaController::procesar` → `NominaService::procesar` |
| GET `/nomina/corrida/{id}`, `/nomina/colilla/{id}` | Consulta/impresión |
| POST `/nomina/corrida/{id}/anular` | `NominaController::anular` → `NominaService::anular` |
| GET `/nomina/legacy/{id}/ticket` | Consulta histórica, sin procesamiento |
| GET/POST `/nomina/estructuras`, POST `/nomina/estructuras/{id}/toggle` | Administración de estructuras, no otro motor de liquidación |

Los formularios reales son `resources/views/payrolls/wizard.blade.php` y `preview.blade.php`: fechas, método, `empleado_ids[]` y estructura opcional, con CSRF. La confirmación Alpine no constituye deduplicación. Las búsquedas de escritores de corridas/colillas en `app` identificaron `NominaService` como creador actual; las demás referencias inspeccionadas son relaciones o consultas.

## 3. Modelo temporal y estados

| Entidad | Campos y significado |
| --- | --- |
| `employees` / `Empleado` | PK estable de coordinación; `user_id` nullable y UNIQUE según `2026_10_13_000001_hr_nucleo_rrhh.php` |
| `payroll_runs` / `CorridaNomina` | `periodo_inicio`, `periodo_fin` DATE; `borrador`, `contabilizada`, `anulada`; método, totales, creador, motivo de anulación |
| `salary_slips` / `ColillaNomina` | empleado, usuario, corrida, fechas DATE; `borrador`, `pagada`, `anulada`; salario/totales, fecha de pago y asiento |
| `salary_slip_details` | conceptos, fuente, importe y `referencia_id` (por ejemplo cuota amortizada) |
| `payrolls` / `Nomina` | Legacy por usuario, `start_date`, `end_date`, `status`; solo lectura en el flujo vigente |

Los intervalos son inclusivos. Hay solapamiento cuando `inicio_existente <= fin_solicitado AND fin_existente >= inicio_solicitado`. Compartir el día final sí es solapamiento. Del 1 al 15 y del 16 al 30 son contiguos y compatibles; del 1 al 15 y del 10 al 20 son incompatibles.

La migración `2026_10_13_000002_payroll_runs_colillas_corporativo.php` define importes decimales y enlaces de corrida/empleado, pero no exclusión temporal ni token de operación. No existen campos de tipo de nómina, origen de ajuste ni referencia estructurada de reproceso. Las FK/índices declarados no sustituyen la validación de cobertura. Se inspeccionaron archivos versionados, sin comprobar el esquema físico de las bases protegidas.

## 4. Reglas de cobertura de empleados

El propietario confirmó: **«El wizard es solo para nómina ordinaria»**. Por ello cambiar la estructura salarial no convierte una corrida en extraordinaria ni permite eludir cobertura.

La autoridad de cobertura es cada colilla del empleado que no esté `anulada`, incluyendo `borrador` como reserva conservadora. No se usa la ausencia de comisiones pendientes ni la coincidencia de importes como señal de que el salario ya fue pagado. La consulta no depende de una relación cargada anteriormente.

También se consulta, sin modificarlo, el histórico legacy pagado por `user_id`: estados `pagado` (enum histórico) y `pagada` (terminología histórica conservada). Un borrador legacy no representa pago. Una coincidencia pagada bloquea y exige revisión de cobertura; no se resuelve borrando o reescribiendo historia.

Se normalizan/deduplican IDs y se exige que exista toda la selección. La cobertura de todos los empleados se verifica antes de calcular o crear efectos de cualquiera. Un error no produce una corrida parcial.

## 5. Riesgos de concurrencia encontrados

Antes no había fila de coordinación por empleado. Dos solicitudes sin colillas previas podían calcular salario y crear documentos diferentes. Bloquear comisiones o una colilla todavía inexistente no impide esa carrera. Además, `anular` decidía si una corrida era anulable a partir del modelo recibido, antes de la transacción, sin volver a bloquear/releer su estado.

Ahora `cargarEmpleados` adquiere locks exclusivos por PK de `employees`, en orden numérico ascendente y con transacción activa. Primero se bloquea toda la selección. Después se relee cobertura con `FOR UPDATE`, para decidir sobre el estado actual aun si hubo una lectura previa de previsualización o de otra entidad.

Orden aplicado:

| Operación | Orden de coordinación |
| --- | --- |
| Procesar | Empleados por PK → cobertura de colillas/legacy → fuentes pendientes → nueva corrida/colillas → fuentes → asiento |
| Anular | Descubrir IDs de empleados de la corrida → empleados por PK → corrida actual → colillas por empleado/ID → fuentes/cuotas → asiento espejo |

El descubrimiento inicial de anulación no decide estados; el conjunto se contrasta con las colillas bloqueadas antes de modificar fuentes. Los enlaces de empleado/corrida no se editan en el flujo vigente. Procesar no solicita después un lock sobre una corrida antigua, evitando invertir ese orden con anulación.

El mutex funciona aunque no exista ninguna colilla y serializa también períodos compatibles de un mismo empleado. Empleados distintos no comparten ese mutex. No se garantiza ausencia absoluta de deadlocks: pueden intervenir locks de índices, fuentes, cuentas y períodos, especialmente con otros módulos. No se añadieron reintentos automáticos ni se modificaron índices.

La justificación de diseño utiliza lecturas bloqueantes dentro de una transacción; sus bloqueos duran hasta commit/rollback. Es una inferencia sobre el comportamiento esperado del código bajo InnoDB, no una medición local. Referencia: [MySQL 8.4 — Locking Reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html).

## 6. Cambios realizados

1. `NominaService::liquidar` comprueba cobertura antes del cálculo/persistencia; la previsualización también informa del conflicto, sin reservar ni bloquear.
2. `cargarEmpleados` valida existencia completa y adquiere el mutex estable; se conserva el orden visual por código al calcular.
3. `validarCobertura` aplica los intervalos inclusivos, estados activos y cobertura legacy pagada. Rechaza con excepción de dominio antes de crear corrida.
4. `anular` participa en el mismo mutex y relee corrida/colillas bajo lock. Rechaza estados incompatibles, colillas pagadas sin referencia contable, motivo vacío o selección inconsistente antes de reabrir fuentes. Antes podía liberar cobertura omitiendo la reversión si faltaba esa referencia.
5. Los dos métodos contables de colilla capturan ahora `Throwable`. Antes un `Error` dentro de su transacción anidada podía saltarse su rollback y dejar un nivel abierto tras el único rollback exterior.
6. HTTP admite opcionalmente `tipo_nomina=ordinaria`; el servicio también rechaza cualquier tipo explícito diferente. El payload existente no necesita ese campo.

No se cambiaron fórmulas salariales, INSS/IR, fuentes, catálogo, cuentas, desembolso, H08, H11, esquemas ni migraciones.

## 7. Ajustes extraordinarios

No hay un circuito de ajustes extraordinarios identificado ni aprobado para el wizard. La confirmación del propietario elimina la ambigüedad de usar estructuras especiales para ese propósito. No se añadió una restricción UNIQUE general sobre período/empleado.

Un futuro ajuste legítimo necesita contrato explícito: tipo, motivo, procesamiento/colilla de origen, autorización y conceptos que pueden corregirse, además de su tratamiento contable aprobado. Esa representación no existe hoy; queda pendiente de diseño y autorización independiente si se necesita. No se promete que H07-R implementa ajustes ni se registra uno sin trazabilidad para sortear el rechazo.

## 8. Nóminas anuladas

Una colilla `anulada` no bloquea cobertura; el caso de uso libera esa cobertura solo junto con la reversión y reapertura de fuentes dentro del commit de anulación. Mientras la anulación tiene el empleado bloqueado, otro procesamiento incompatible debe esperar; si anulación falla, permanece la cobertura original.

Se conservan corrida, colillas, conceptos y asientos originales. La anulación mantiene su motivo y genera `REV-NOM-*`. Un reenvío de anulación relee el estado actual y no debe revertir otra vez. Se permite reprocesar el intervalo después de una anulación válida. La nueva corrida no tiene un campo dedicado de enlace al reproceso original; no se inventó ni migró uno.

El guard se basa en estados persistentes coherentes obtenidos mediante estos casos de uso. No certifica estados históricos modificados externamente, ni habilita marcar una colilla anulada sin reversión.

## 9. Idempotencia y limitaciones

No existe token persistente de operación en formulario, corrida o colilla. Una segunda solicitud ordinaria sobre cobertura activa se rechaza; no devuelve el resultado exitoso de la primera. Esto previene duplicidad ordinaria por cobertura y no representa idempotencia completa.

Un período contiguo, otro empleado o un reproceso después de anulación son operaciones distintas. No se deduplica por importe aproximado. Un eventual token necesitaría definir emisor, alcance por actor/operación, payload inmutable, unicidad persistente y respuesta de reintento; no se implementa en H07-R.

## 10. Comisiones, propinas, adelantos, pagos y contabilidad

Se conserva `calcularColilla`: comisión desde montos congelados `monto_comision`, pendiente y por fechas/usuario; propinas pendientes por empleado/período; cuotas pendientes/parciales por vencimiento, con cap del disponible. Bajo procesamiento se mantienen sus locks existentes. Las comisiones/propinas se vinculan a la colilla; las amortizaciones quedan en conceptos referenciados.

El recorrido vigente es corrida → colillas/conceptos → cierre de fuentes/amortización → asiento `NOM-*` → colilla pagada → corrida contabilizada. La transacción exterior pertenece a `NominaService`; contabilidad usa niveles anidados en la misma conexión por defecto. Los modelos revisados no declaran conexiones alternativas y estos métodos no abren PDO independiente. La configuración efectiva de producción no se arrancó ni se modificó.

El método efectivo/banco determina el haber del fondo en el asiento. Este flujo no registra por sí mismo filas en el libro POS `pagos` ni otro desembolso separado; no se añadió un segundo pago. Se mantienen gasto de sueldo/comisiones, pasivo/custodia de propinas, retenciones y anticipos según el asiento actual. No se identificaron envíos de correo, jobs o escrituras externas propias en los métodos revisados; el manejo de error sí utiliza el logger.

El cuadre por centavos sigue siendo obligatorio, pero no sustituye la comprobación de cobertura. Los tests sustituyen resolución de período/moneda/cuentas y la validación de clasificación `AccountIntegrityService::posting`; conservan generación/reversión de líneas y `validarCuadre` real. **No constituyen aprobación económica AC-05** ni validación del catálogo empresarial.

## 11. Pruebas y aserciones ejecutadas

Ejecución focalizada: **31 pruebas, 150 aserciones aprobadas**, PHP 8.3; sin bootstrap empresarial ni PHPUnit XML del proyecto.

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' `
  -d memory_limit=128M -d max_execution_time=40 -d zend.exception_ignore_args=1 `
  vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php `
  --do-not-cache-result --no-progress `
  tests/Unit/NominaCoverageTest.php tests/Unit/NominaWizardContractTest.php
```

`NominaCoverageTest` usa `NominaService`, calculadoras y Eloquent reales, con conexión en memoria que intercepta sentencias compiladas y prohíbe abrir PDO. Los snapshots/rollbacks del doble no representan el motor MySQL. Se ejercitan asientos/reversión reales con búsquedas técnicas de configuración sustituidas. `NominaWizardContractTest` usa controlador, Request y validador reales con servicio/presencia/sesión en memoria, sin kernel HTTP completo.

| Caso solicitado | Evidencia actual |
| --- | --- |
| 1. Primera ordinaria | Preview sin mutación; corrida contabilizada, colilla pagada, fuentes y asiento `NOM-*`; referencia/período |
| 2–3. Reenvío y período exacto | Segundo proceso rechazado, una colilla y un asiento; historia sin cambios |
| 4–5. Solape parcial/contenido | Rechazo inclusive del intervalo envolvente y del día compartido, antes de insertar corrida |
| 6. Contiguos | Procesamiento 1–15 seguido de 16–30 permitido |
| 7. Empleados distintos | Mismo período permitido para empleado 2 |
| 8. Anulado | Anulación real sobre doble, reapertura, preservación originales y reproceso permitido |
| 9. Ajuste legítimo | No existe ese flujo; rechazo de tipo extraordinario HTTP y servicio. Caso positivo pendiente de contrato, no declarado aprobado |
| 10. Estado incompatible | Anulación relee borrador/anulada aunque el modelo recibido diga contabilizada; colilla borrador bloquea cobertura; colilla pagada sin referencia contable no libera cobertura |
| 11–13. Fuentes utilizadas | Comisión/propina pagadas y cuota pagada no vuelven al cálculo; repetición rechazada antes de nuevos cierres |
| 14. Error tras colilla | Centinela después de insertar detalle; todas las filas del doble restauradas y nivel cero |
| 15. Error tras asiento | Centinelas tras cabecera y tras asiento completo cuadrado; rollback interior/exterior y nivel cero; también en reversión |
| 16. Mismo empleado concurrente | Interleaving simulado: B previsualiza, A confirma, B vuelve a consultar y rechaza; tras anulación confirmada puede procesar. SQL real pendiente |
| 17. Empleados distintos concurrentes | Orden de locks por PK y emisión de ambos en prueba aislada; ausencia de bloqueo cruzado real pendiente |
| 18. Duplicidad/idempotencia | Reenvío produce rechazo, no replay exitoso; sin token |
| 19. Historia | Rechazo conserva todas las filas; legacy pagado solo lectura; originales de corrida anulada permanecen |
| 20. Formulario/respuesta | Payload previo sin tipo aceptado, estructura opcional, campos protegidos excluidos por validador; redirects de éxito/error conservados |

Adicionalmente: selección incompleta rechazada sin corrida parcial, IDs repetidos normalizados, locks de empleados antes de consultar cobertura, segunda anulación rechazada y descuadre inyectado de un centavo revierte todo el doble. Se comprobó sintaxis PHP en los seis archivos PHP de H07 y ausencia de errores de whitespace del diff.

No se ejecutó `tests/Feature/NominaCorridaTest.php`: utiliza `RefreshDatabase` y seeders. Sus expectativas económicas no se alteraron. No se ejecutaron suites generales ni build, pues no se modificó frontend.

## 12. Pruebas SQL pendientes para Debian

Requieren laboratorio independiente disponible y autorización específica; ninguna preparación de infraestructura H05 se reabre aquí. Usar solo esquema revisado y datos sintéticos, sin evidencia de las bases históricas, con todas las conexiones y escritores confinados al laboratorio.

| Escenario real futuro | Barrera y resultado exigido |
| --- | --- |
| Dos procesos, mismo empleado sin colillas | A bloquea empleado antes de insert; B intenta procesar y espera. A confirma; B relee cobertura y rechaza. Una colilla activa y un pago contable |
| A revierte antes de confirmar | B espera mutex; tras rollback de A puede crear exactamente una ordinaria |
| Solapes exactos, parciales y contenidos | Mismo resultado que arriba, independientemente de comisiones pendientes |
| Períodos contiguos | Ambos pueden confirmar sin días solapados, aunque se serialicen por empleado |
| Empleados distintos | A/B avanzan con empleados diferentes; observar locks de índices/cuentas/períodos, sin afirmar independencia absoluta a priori |
| Selecciones [1,2] y [2,1] | Locks por PK en ambos procesos; no orden inverso de mutex; medir posibles deadlocks en fuentes |
| Pago frente a anulación | Si anulación confirma primero, reproceso permitido; si procesamiento ve cobertura primero, rechaza; anulación fallida conserva cobertura |
| Dos anulaciones | Una reversión por colilla; segunda relee corrida anulada y rechaza sin reabrir fuentes otra vez |
| Fallo después de colilla/asiento y durante reversión | Probar llegada al punto y efectos visibles dentro de transacción; excepción centinela; rollback real; nivel cero y lectura de verificación desde segunda conexión |

En cada caso comprobar corridas, colillas, conceptos, comisiones, propinas, cuotas, asiento original/espejo, importes de fondos y referencias; comparar centavos y estados, no solo debe/haber. Si un colaborador del lote falla, ninguno debe quedar parcialmente liquidado. Las identidades AUTO_INCREMENT pueden avanzar pese al rollback y no deben restablecerse para aparentar ausencia de efectos.

Antes de ejecutar: verificar esquema real, motor InnoDB, índices, conexión efectiva única sin fallback/read-write splitting, nivel de aislamiento y guardias del laboratorio; instrumentar barreras sin sustituir el motor de nómina ni envolver todas las pruebas en una transacción exterior. Registrar esperas, conexiones y commits reales. Abortarlo ante destino no autorizado. No se incluyen comandos ejecutables que puedan apuntar al entorno actual.

## 13. Archivos nuevos o modificados de H07-R

| Archivo | Cambio |
| --- | --- |
| `app/Services/NominaService.php` | Mutex, cobertura, selección completa y coordinación/relectura de anulación |
| `app/Http/Controllers/NominaController.php` | Validación opcional de tipo exclusivamente ordinario |
| `app/Services/ContabilidadService.php` | Solo dos capturas `Throwable` en emisión/reversión de colilla |
| `tests/Support/H07MemoryConnection.php` | Doble de persistencia estricto, sin PDO |
| `tests/Unit/NominaCoverageTest.php` | Regresión de cobertura, fuentes, anulación y errores transaccionales simulados |
| `tests/Unit/NominaWizardContractTest.php` | Compatibilidad HTTP aislada |
| `docs/H07_R_NOMINA_DUPLICADA.md` | Este informe |

El árbol ya tenía modificaciones H09 y documentos H05 sin commit; se preservaron. En particular, `ContabilidadService.php` contiene cambios H09 anteriores que no forman parte de H07. No se modificaron rutas, vistas, modelos, migraciones, configuración, launchers históricos ni expectativas de tests anteriores.

## 14. Preservación de bases

No se abrió conexión SQL, no se arrancó Laravel empresarial ni se cargaron `.env`/`.env.testing`. El único bootstrap ejecutado fue `vendor/autoload.php`; el contenedor de las pruebas es mínimo. La conexión de pruebas recibe exclusivamente tablas sintéticas en memoria; cualquier intento de resolver PDO lanza excepción. Los tests HTTP prohíben resolver conexiones.

No se hicieron consultas, DDL, DML, restores, migraciones, seeders, snapshots ni cambios de contadores en `salon_erp`, `salon_erp_test` o `salon_erp_bootstrap_check`. La evidencia AC-04/AC-05 no se utilizó ni modificó. Esto acredita ausencia de operaciones de esta intervención sobre esas bases, sin presentar una comparación SQL de sus contenidos históricos. No hubo operaciones Git de escritura.

## 15. Riesgos residuales

- Serialización, locks de rangos/índices, savepoints y rollback de filas necesitan las pruebas InnoDB independientes indicadas.
- La protección requiere que todo escritor ordinario utilice el mutex del empleado y la misma conexión transaccional. Un escritor externo/directo puede saltarla; no hay constraint SQL de exclusión temporal.
- No se auditó ni reparó cobertura histórica real. Inconsistencias de estados o solapamientos anteriores requieren revisión autorizada, sin borrado ni arreglo automático.
- Las consultas de cobertura no añaden índices nuevos; su plan y contención deben medirse en el laboratorio antes de valorar una migración separada.
- No hay token persistente de idempotencia, circuito de ajuste extraordinario ni enlace dedicado de reproceso. Son límites explícitos, no operaciones nuevas implícitamente autorizadas.
- Se mantiene la fórmula vigente de salario base para cada intervalo aceptado. H07 impide volver a pagar los mismos días mediante ordinarias activas; no valida si la periodicidad elegida o el prorrateo salarial existente cumplen una política laboral determinada.
- H08 (conceptos fijos y mapeo contable), H11 (separación de cálculo), clasificación AC-05 y concurrencia de otros módulos permanecen fuera de esta corrección. El doble contable no valida esas decisiones.

## 16. Recomendación del siguiente hallazgo

Validar primero los casos concurrentes H07 en el futuro laboratorio Debian cuando esté disponible y autorizado. Como siguiente revisión funcional separada, H08 requiere comprobar el tratamiento de conceptos fijos sin inventar clasificación contable ni variar fórmulas. No se inicia esa revisión ni infraestructura, migraciones, H05-V1, H06, H11 o despliegues en H07-R.
