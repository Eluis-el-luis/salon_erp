# H11-R — Orden de cálculo salarial y continuidad de cuotas

Fecha: 21 de noviembre de 2026. Referencias: `AUDITORIA_MANTENIBILIDAD.md`, `AGENTS.md`, H07-R y H08-R. Validación exclusivamente aislada, sin SQL empresarial.

## 1. Estado actual de H11

Los dos comportamientos históricos seguían presentes:

- **A. Orden salarial:** `calcularColilla` recorría detalles por `orden`, que controla la presentación, y utilizaba acumuladores todavía incompletos. Corregido mediante una secuencia explícita de dependencias, conservando fórmulas, tasas y orden visual.
- **B. Cuotas antiguas:** `whereBetween(fecha_vencimiento, inicio, fin)` excluye pendientes/parciales anteriores al período. Confirmado en ejecución aislada, pero **no corregido**: no existe política aplicada de recuperación de atrasos/excepciones ni representación fiable de la anulación del adelanto para decidir exigibilidad. El propietario confirmó: «No hay reglas aplicadas».

H11-R no queda declarado como resolución completa de la continuidad de cuotas. A tiene corrección y regresión aislada; B permanece bloqueado por decisión funcional y contrato de datos pendientes. No se atribuye ocurrencia a documentos de producción ni se consultaron bases históricas.

Se inspeccionaron servicio, administración de estructuras, modelos/migraciones de estructura, colilla, adelantos/cuotas, fuentes, configuración, vistas del wizard/colilla, conciliación H08, integración contable y documentación H07/H08. No se encontró una corrección posterior de H11 que sustituyera esos comportamientos.

## 2. Dependencias entre calculadoras

`E` = ingresos; `D` = deducciones; `N = E − D`. Las órdenes de la tabla son los valores estándar del seeder y del formulario, inspeccionados sin ejecutar. Una estructura personalizada puede elegir otros valores; no son precedencias normativas.

| Concepto / fuente | Base y dependencias reales | Disponibilidad | E / D / N | Orden visual estándar | Fase necesaria / fallo si se adelanta |
| --- | --- | --- | --- | --- | --- |
| SAL_BASE / expediente o fallback usuario | Salario base vigente; sin dependencia de otras líneas | Antes del recorrido | + / 0 / + | 10 | Ingresos; debe integrar base gravada antes de retenciones |
| COM_VENT / comisiones congeladas | Suma pendiente por usuario y fechas; no porcentaje actual | Se consulta una vez al calcular este ingreso | + / 0 / + | 20 | Ingresos gravados; si aparece después de retenciones estas omiten su base |
| PROPINAS / custodia | Suma pendiente por empleado y fechas | Se consulta una vez al calcular este ingreso | + / 0 / + | 30 | Ingresos, excluida de base gravada; debe estar disponible para el cap de adelantos |
| HORAS_EXT / asistencia | Tarifa vigente × horas × multiplicador | Salario y asistencia ya obtenidos | + / 0 / + | 40 | Ingresos gravados; retenciones previas omiten extras |
| INSS / ley configurada | Base gravada completa × tasa actual | Después de ingresos gravados | 0 / + / − | 100 | Retenciones; ejecutarlo antes de ingresos produce base parcial/cero |
| IR / ley configurada | Máximo de cero y base gravada menos exento, por tasa actual | Después de ingresos gravados | 0 / + / − | 110 | Retenciones; no depende del INSS ni de otras deducciones en la fórmula actual |
| SIN_GOCE / ausencias aprobadas | Salario y días sin goce dentro del período | Ausencias y días ya obtenidos | 0 / + / − | 120 | Deducción estructural, antes del cap; no modifica base gravada en el código actual |
| TARDANZAS / asistencia | Cantidad de incidencias × importe vigente | Asistencia ya obtenida | 0 / + / − | 130 | Deducción estructural, antes del cap; no modifica base gravada en el código actual |
| ADELANTOS / cuotas | Pendiente de cada cuota, limitado a ingresos completos menos todas las deducciones estructurales | Después de las anteriores | 0 / + / − | 140 | Última fase económica; ejecutarlo antes puede omitir amortización o consumir disponible que necesitan deducciones posteriores |

Los conceptos `fijo`, desconocidos o duplicados siguen rechazados por H08 antes de ejecutar la secuencia. No se añade una fase que los autorice. Las tasas y umbrales de `config/salon.php` mantienen sus advertencias y valores; no se afirma que constituyan una certificación legal.

## 3. Reproducción del defecto original

Se añadieron dos pruebas y se ejecutaron antes de modificar `NominaService`. Resultado rojo: una aserción fallida y una excepción. Se utilizó el servicio real con persistencia sintética en memoria y PDO prohibido.

Fixture definido previamente: base 200.000, comisión congelada 250,25, propina 30,50, dos horas extra al multiplicador estándar, una ausencia sin goce y una incidencia de tardanza a 3,21. Se usaron los defaults actuales de INSS/IR, días y jornada; la base alta permite IR positivo sin cambiar el exento. No se retocó la configuración económica tras observar los fallos.

| Orden previo | Ingresos | Deducciones | Neto / comportamiento |
| --- | --- | --- | --- |
| Estándar, sin cuotas | 203.614,08 | 43.124,93 | 160.489,15; INSS 14.250,85 e IR 15.537,54 |
| IR e INSS antes de los ingresos | 203.614,08 | 13.336,54 | 190.277,54; desaparecen ambas retenciones por base cero |
| Cuota 200.000 después de deducciones | 203.614,08 | 203.614,08 | Neto cero; amortiza solo 160.489,15 |
| Misma cuota después de ingresos pero antes de deducciones | 203.614,08 | 243.124,93 | Excepción: deducciones exceden ingresos |

H08 podía conciliar un conjunto que ya había omitido INSS/IR durante el cálculo, porque comprobaba las líneas efectivamente generadas. H11 corrige que la base esté completa; no relaja ni reemplaza esa conciliación.

## 4. Contrato de fases implementado

Se conserva un único recorrido del switch de calculadoras y sus fórmulas. La nueva constante privada `NominaService::ORDEN_CALCULO` determina la secuencia exclusivamente por calculadora, después de la validación H08:

1. Validar cobertura H07, estructura y fuentes iniciales. Se mantiene la obtención previa de salario, asistencia y ausencias.
2. Ingresos: salario, comisiones congeladas, propinas, horas extra. Se completa la base gravada con los mismos ingresos anteriores; propinas siguen fuera de esa base.
3. Retenciones INSS e IR sobre esa base completa, con las fórmulas y tasas existentes. La secuencia entre ellas reproduce la estructura estándar, sin introducir una deducción de INSS sobre la base IR.
4. Ausencias sin goce y tardanzas como deducciones estructurales según fórmulas existentes.
5. Obtener cuotas mediante la selección actual y calcular disponible con todos los ingresos menos todas las deducciones estructurales.
6. Aplicar cap, en el mismo orden de vencimiento/ID de cuotas, sin neto negativo.
7. Cerrar totales y ordenar las líneas por el `orden` visual para preview/persistencia. La conciliación H08 sigue ejecutándose antes de crear la corrida y al contabilizar.

La secuencia reproduce la estructura estándar vigente para evitar una nueva política. No se convierten estos números internos en una interfaz configurable de precedencia tributaria. No se extrajo otro motor, no se incorporaron dependencias y no se multiplicaron las consultas de fuentes: cada calculadora admitida sigue ejecutándose una vez por empleado y selección.

## 5. Independencia del orden visual

`salary_structure_details.orden` permanece administrable por el formulario y validado como entero entre 0 y 999. El modelo de estructura conserva su relación ordenada; `ColillaNomina::detalles` sigue ordenando por el campo persistido. Las vistas actuales separan ingresos/deducciones conservando ese orden relativo.

El cálculo ahora ordena por dependencia, manteniendo el valor visual original en cada línea. Solo después de calcular totales se ordena el array de líneas para previsualización y creación de conceptos. Así cambiar presentación no altera el orden de sumas ni la base disponible. Empates visuales tampoco cambian importes; no se atribuye significado económico a la posición de un empate.

La prueba metamórfica usa 18 permutaciones (rotaciones de la secuencia y de su inversa) en una estructura alternativa: idénticos conceptos, parámetros y fuentes; únicamente cambia `orden`. Para todas se comparan conceptos/importes/referencias y totales, se verifica el orden visible y se emite una corrida con el emisor real y conciliación H08 sobre memoria.

## 6. Política documentada de cuotas vencidas

El contrato encontrado en AGENTS.md describe cuotas pendientes/parciales vencidas **en el período**, bajo cap. El código selecciona:

```text
adelanto.user_id = usuario del empleado
fecha_vencimiento entre inicio y fin, inclusive
estado en pendiente/parcial
orden de recuperación: fecha_vencimiento ASC, id ASC
pendiente = monto - monto_pagado, descartando pendiente no positivo
amortización <= disponible
```

Se confirmó la ausencia de una regla aplicada para recuperar remanentes de períodos anteriores. La auditoría propone continuidad, pero no define las excepciones de exigibilidad, suspensión ni anulación. La respuesta del propietario «No hay reglas aplicadas» no se interpretó como autorización para cobrar toda cuota anterior automáticamente.

Distinciones observadas:

| Caso | Selección actual y decisión H11-R |
| --- | --- |
| Pendiente/parcial dentro del período y del usuario | Se conserva, con cap y vencimiento/ID |
| Pendiente/parcial anterior al inicio | Se excluye actualmente; exclusión demostrada, ampliación bloqueada |
| Pagada | Se excluye, aunque la fecha esté dentro del período |
| Futura respecto del fin solicitado | Se excluye; no se anticipa exigibilidad |
| De otro usuario/cliente | El filtro por usuario no la incorpora al empleado actual |
| Adelanto anulado/revertido | No existe enlace estructurado que lo identifique de forma fiable en esta selección |
| Cuota suspendida/anulada | El enum versionado solo tiene pendiente/parcial/pagada; no hay un estado autorizado de suspensión/anulación |

`Adelanto` utiliza `advances`, movimientos `debe/haber`, sin estado de anulación ni referencia estructurada a su reversión. `AdelantoController::destroy` crea el movimiento contrario con una descripción textual y asiento, pero no marca/reprograma cuotas. Interpretar esa descripción como vínculo o deducir que cualquier movimiento contrario cancela una cuota sería inventar un contrato. Se inspeccionaron también los modelos y las migraciones de `advances` y `adelanto_cuotas`.

## 7. Correcciones realizadas y parte detenida

Cambios funcionales limitados a `NominaService`: constante de dependencias, uso de esa secuencia en el recorrido y reordenamiento visual de las líneas al terminar. No se modifican fórmulas ni selección/actualización/reversión de cuotas.

Se deja explícitamente pendiente B. La prueba de continuidad caracteriza una cuota de 200.000 del período 1–15, amortizada parcialmente en 160.489,15; su remanente 39.510,85 sigue sin seleccionarse para 16–30. La prueba de movimiento contrario sin vínculo confirma que no identifica exigibilidad de cuotas del original. Son evidencias de límites pendientes, **no expectativas aprobadas de continuidad ni autorización de cobro de anulados**.

Propuesta sujeta a decisión posterior: definir exigibilidad y excepciones antes de considerar una consulta con vencimiento hasta el fin y sin límite inferior. Esa selección necesitaría filtrar obligaciones realmente vigentes, conservar cap/orden/referencias y coordinar cancelación/reapertura. Si no puede representarse sin nuevos estados/vínculos, se requiere diseño y autorización de esquema independientes. No se implementa ni ejecuta aquí.

## 8. Compatibilidad H07

Se mantienen mutex por empleado en PK ascendente, lectura de cobertura inclusiva dentro de transacción, rechazo de ordinaria repetida y coordinación de anulación. El nuevo orden de calculadoras actúa después de esos controles; no hay otra entrada que los evite.

Se prueban reenvío rechazado sin cambios, anulación válida, reapertura de cuota/fuentes y reproceso sobre la misma cobertura después de cambiar orden visual. No se recalculan los conceptos de la colilla histórica ni se sobrescriben las líneas del asiento original. No se introduce idempotencia por token ni ajustes extraordinarios.

## 9. Compatibilidad H08

No se modifica `ConciliacionNomina` ni `ContabilidadService`. Se conservan códigos canónicos, rechazo de fijos/desconocidos/duplicados, validación de importes y conciliación exacta de devengado, deducciones, neto, custodia, fondo y cuentas reales del asiento.

El cap ahora utiliza el disponible final. Si las deducciones estructurales por sí solas exceden ingresos, el caso sigue rechazándose antes de persistir. No se financia un neto negativo ni se cambia la reducción de gasto salarial de H08. El origen de la comisión continúa siendo el monto congelado y la custodia de propinas conserva su circuito.

Las fórmulas salariales mantienen sus redondeos existentes. La secuencia canónica vuelve determinista su acumulación frente al orden visual; H08 sigue contrastando los resultados en centavos. No se alteran base gravada, periodicidad, tasas INSS/IR, tratamiento contable o políticas AC-05.

## 10. Pruebas y aserciones

Resultado final: **100 pruebas y 479 aserciones aprobadas**. Incluye 31 casos nuevos H11 de servicio real con dobles, más las 69 pruebas previas H07/H08 sin cambiar sus expectativas económicas.

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' `
  -d memory_limit=128M -d max_execution_time=40 -d zend.exception_ignore_args=1 `
  vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php `
  --do-not-cache-result --no-progress `
  tests/Unit/NominaOrdenCuotasTest.php tests/Unit/ConciliacionNominaTest.php `
  tests/Unit/NominaConceptosIntegrityTest.php tests/Unit/NominaCoverageTest.php `
  tests/Unit/NominaWizardContractTest.php
```

| Casos solicitados | Evidencia / límite |
| --- | --- |
| 1–4. Base, extras, INSS e IR adelantados visualmente | Dos reproducciones rojas previas y permutaciones verdes; INSS e IR positivos con defaults vigentes |
| 5. Adelantos antes de legales | Antes excedía ingresos; ahora usa disponible final y conserva cap |
| 6–7. Estructuras/órdenes distintos, misma economía | 18 permutaciones en otra estructura; totales, conceptos, referencias y emisor conciliado idénticos económicamente |
| 8. Repetidos/estructura inválida | Fijo y calculadora duplicada siguen rechazados por H08; no se alcanza una precedencia alternativa |
| 9. Salario cero | Fuentes cero y orden inverso: neto cero válido |
| 10. Comisión/propinas | Montos congelados/pasivo custodia presentes en todas las permutaciones |
| 11. Deducciones estructurales excesivas | Rechazo antes de corrida y estado del doble intacto |
| 12. Vencida anterior | Exclusión caracterizada; recuperación sin política queda bloqueada |
| 13. Parcial | Dentro del período se aplica solo remanente; parcial anterior se conserva excluida, pendiente B |
| 14–15. Pagada/futura | Excluidas; filas sin cambios |
| 16. Cap insuficiente y varias cuotas | Inserción contraria al orden de recuperación: paga ID 1 por 90.000, ID 2 por 70.489,15; deja ID 3 pendiente; referencias únicas |
| 17. Reintento ordinario | H07 rechaza, sin nueva colilla/asiento ni cambio de cuota |
| 18–19. Anulación y reproceso | Cuota vuelve a pendiente/importe previo, fuentes reabiertas, nuevo proceso conserva importe y deja originales intactos |
| 20. H08 tras reordenar | Cada permutación se procesa con conciliación real del emisor en memoria; las 69 regresiones previas pasan |
| 21. Error tras cálculo antes de asiento | Centinela al insertar corrida, habiendo calculado el lote; snapshot restaurado y nivel cero |
| 22. Error después de asiento | Centinela después de sus líneas/fondo; captura nivel 2, asiento y nueve conceptos; rollback simulado completo |
| 23. Históricos | Conceptos/asiento de la primera colilla sin reescritura tras cambio de estructura, anulación y reproceso |
| 24. Wizard/presentación | Regresiones HTTP aisladas previas; mismo payload, redirects y validación. Preview/persistencia de líneas respetan orden visual |

Se comprueban asimismo empates visuales, cuotas de otro usuario y el límite de reversión no vinculada. No se usa un fixture de cuota suspendida inventando un estado que el esquema no admite.

Clasificación de evidencia: las 27 pruebas puras de conciliación y las cuatro HTTP aisladas son las existentes. H11 añade ejecuciones del servicio/calculadoras/emisor reales con persistencia en memoria, no otro motor financiero. El doble mantiene PDO prohibido; se amplía solo para leer tipos de ausencia sintéticos y respetar `ORDER BY` en las selecciones. Esta última capacidad es necesaria para comprobar recuperación por vencimiento/ID con filas insertadas al revés.

Los tests sustituyen búsquedas técnicas de cuenta/moneda/período y `AccountIntegrityService::posting`. No validan clasificación empresarial AC-05 ni esquema real. Los snapshots acreditan el comportamiento del caso de uso frente al doble, **no rollback o serialización InnoDB**. No se ejecutaron tests con `RefreshDatabase`, seeders, suites generales ni kernel empresarial. Sintaxis PHP de los tres archivos PHP afectados y `git diff --check` verificados.

## 11. Regresiones pendientes de MySQL

Cuando el laboratorio Debian independiente esté disponible y autorizado: verificar permutaciones con las fuentes/colillas/cuotas/asientos persistidos, fechas inclusivas, tipos decimales y orden SQL real. Confirmar locks de empleado/fuentes/cuotas y coordinación entre procesamiento/anulación con dos conexiones, sin envolver todos los casos en una transacción exterior que esconda commits.

Para fallos después del cálculo, fuentes y asiento: demostrar que se alcanzó el punto, capturar cambios dentro de transacción, lanzar centinela y comprobar nivel cero; leer desde segunda conexión que no queden filas/consumos parciales. Registrar avances de AUTO_INCREMENT aparte sin resetearlos. Mantener comparación por centavos y referencias históricas.

La recuperación de cuotas anteriores, suspensión y anulación solo debe probarse como comportamiento nuevo después de definir y autorizar su contrato. No se traslada ese ensayo a `salon_erp_test` ni se reabre infraestructura H05-V1.

## 12. Decisiones laborales o económicas pendientes

Para B se necesita definir expresamente: qué remanentes anteriores son exigibles; si hay límite temporal/porcentaje o excepciones; prioridad de recuperación; tratamiento de cuotas suspendidas, reprogramadas, pagadas por fuera o adelantos anulados; relación verificable de reversión con original; autoridad para decidir y trazabilidad de cambios. El cap actual y vencimiento/ID no resuelven esas preguntas.

Si se necesita estado/vínculo persistente, presentar el cambio de esquema antes de implementarlo y obtener autorización independiente. No se deduce cancelación de texto, coincidencia de importes o movimiento contrario aproximado.

Las tasas/fórmulas actuales se preservan sin declararlas correctas legalmente. H11-A no modifica la base de IR para restar INSS, ausencias u otras deducciones, porque tal precedencia no está en la fórmula vigente ni fue autorizada.

## 13. Archivos modificados

| Archivo | Cambio H11 |
| --- | --- |
| `app/Services/NominaService.php` | Secuencia de dependencias y orden visual posterior al cálculo |
| `tests/Support/H07MemoryConnection.php` | Lectura de tipos de ausencia y orden SQL en memoria; sin PDO |
| `tests/Unit/NominaOrdenCuotasTest.php` | Reproducciones, metamorfismo, cuotas actuales, límites B, anulación y fallos |
| `docs/H11_R_ORDEN_CALCULO_Y_CUOTAS.md` | Este informe |

Sin cambios en controladores, vistas, modelos, conciliación, contabilidad, migraciones, configuración, catálogos, tests históricos o documentación anterior. El resto de la corrección consiste en pruebas y diagnóstico; no se modifican H05/H06/H09, bancos ni inventario.

## 14. Preservación de bases

No se abrieron conexiones a `salon_erp`, `salon_erp_test` o `salon_erp_bootstrap_check`. No se arrancó Laravel empresarial ni se cargaron `.env`, `.env.testing`, credenciales o configuración cacheada. PHPUnit se ejecutó con `--no-configuration` y autoload únicamente; los contenedores y filas son sintéticos y PDO lanza excepción si se intenta resolver.

No se ejecutaron consultas SQL, DDL/DML, migraciones, reset, seeders, restauraciones ni escrituras de fixtures en esas bases. Se conserva evidencia AC-04/AC-05; no se hicieron lecturas de registros históricos para certificar su contenido ni se copiaron datos empresariales. No hubo operaciones Git de escritura.

## 15. Riesgos residuales

- **B sigue abierto y bloqueado:** una cuota parcial anterior continúa fuera de selección, y la exigibilidad tras reversión no tiene vínculo estructurado. Las pruebas verdes de caracterización no significan que esté corregido.
- Persistencia y concurrencia MySQL siguen pendientes; los locks y rollbacks del doble no prueban aislamiento real.
- No se auditaron ni repararon nóminas ya emitidas con retenciones omitidas por órdenes antiguos. Cualquier corrección histórica requiere circuito autorizado.
- Otras escrituras de asistencia, ausencias o adelantos pueden no compartir el mutex de nómina; no se rediseñó esa coordinación ni se garantiza ausencia absoluta de deadlocks.
- La secuencia admite solo las calculadoras conocidas por H08. Un concepto futuro requiere tratamiento contable y dependencias explícitas; no se introduce fallback de orden.
- Se conserva aritmética/redondeos de fórmulas existentes y conciliación H08 en centavos; esto no certifica cumplimiento laboral, tributario, periodicidad ni presentación AC-05.

## 16. Recomendación del siguiente trabajo funcional

Resolver primero el contrato funcional de continuidad/exigibilidad de cuotas y su enlace de reversión, mediante intervención independiente si requiere esquema. Ejecutar después las regresiones integradas de nómina cuando el laboratorio Debian esté autorizado y disponible. No iniciar H06, H05-V1, migraciones, clasificación AC-05 ni despliegues desde esta fase. H11-R termina con A corregido y B explícitamente pendiente.
