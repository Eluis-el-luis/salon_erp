# H08-R — Integridad de conceptos y conciliación de nómina

Fecha: 21 de noviembre de 2026. Revisión del código actual respecto de H08 del 11 de noviembre. Validación exclusivamente pura/aislada, sin conexiones SQL empresariales.

## 1. Estado actual de H08

H08 seguía presente. `NominaController::estructurasStore` permite `fijo` con código libre e importe; `NominaService::calcularColilla` lo incluye en ingresos/deducciones/neto con fuente `manual`. `ContabilidadService::contabilizarColillaNomina` reconocía solamente nueve códigos y calculaba el fondo desde ese subconjunto. Un concepto omitido podía cambiar la liquidación sin cambiar ninguna línea del asiento, que seguía cuadrado.

Se reprodujo con el código previo a la corrección, Eloquent y persistencia en memoria. No es una reproducción SQL ni evidencia de ocurrencia en producción. Después de la corrección se rechazan estructuras/conceptos sin tratamiento y se concilian los importes reales del asiento con la colilla. No se asignaron cuentas nuevas ni se autorizó pagar bonos/deducciones fijos.

Resultado: cierre del acceso funcional a la omisión en los casos aislados, manteniendo las políticas existentes; regresión integrada InnoDB y decisiones sobre fijos pendientes. No se afirma que los documentos históricos estén conciliados ni se modificaron.

## 2. Inventario de conceptos

`E` representa devengado; `D`, deducciones; `N = E − D`. La fórmula y el orden de cálculo continúan en `NominaService`. La matriz se deriva del código, el asiento vigente y `RrhhBaseSeeder` inspeccionado, nunca ejecutado.

| Código / nombre | Tipo / fuente | Importe vigente | E / D / N | Tratamiento contable existente | Correspondencia / verificación |
| --- | --- | --- | --- | --- | --- |
| `SAL_BASE` / Salario base | ingreso / estructura | Salario del empleado; fallback al usuario si es cero | + / 0 / + | DEBE 6.7, sujeto a reducciones salariales; contrapartida según liquidación | Explícita; pura y flujo aislado |
| `HORAS_EXT` / Horas extra | ingreso / asistencia | Tarifa horaria vigente × horas × multiplicador, redondeo existente | + / 0 / + | DEBE 6.7 junto con base | Explícita; pura y calculadora real aislada |
| `COM_VENT` / Comisiones congeladas | ingreso / comisiones | Suma de `monto_comision` pendiente del usuario/período | + / 0 / + | DEBE 5.3 | Explícita; se conserva el monto congelado; pura y flujo aislado |
| `PROPINAS` / Propinas en custodia | ingreso especial / propinas | Suma pendiente del empleado/período | + / 0 / + | DEBE 2.1.6 y HABER 1.1.1.3 íntegros; eventual absorción del déficit por caja | Explícita; pura y flujo con déficit aislado |
| `INSS` / Retención laboral | deducción / ley | Base gravada × tasa configurada vigente | 0 / + / − | HABER 2.1.5 | Explícita; pura y calculadora real aislada; sin cambiar tasas |
| `IR` / Retención de renta | deducción / ley | Fórmula de exento/tasa vigente en configuración | 0 / + / − | HABER 2.1.5 | Explícita; contrato puro positivo; no se cambia el exento para forzar IR en fixtures |
| `SIN_GOCE` / Ausencias sin goce | deducción / ausencias | Prorrateo actual por días aprobados sin goce | 0 / + / − | Reduce DEBE de 6.7 | Explícita; contrato puro; SQL/cálculo de ausencias real pendiente |
| `TARDANZAS` / Tardanzas | deducción / asistencia | Incidencias positivas × importe configurado | 0 / + / − | Reduce DEBE de 6.7 | Explícita; pura y calculadora real aislada |
| `ADELANTOS` / Amortización de cuota | deducción / adelantos | Cuota pendiente limitada al disponible; referencia de cuota | 0 / + / − | HABER 1.1.6 | Explícita; pura, varias referencias y flujo aislado |
| Código libre, p. ej. `BONO` | ingreso / manual (`fijo`) | Valor fijo; antes sumaba también a base gravada | + / 0 / + | Ninguno para código libre | Reproducción roja previa; ahora bloqueado, requiere política |
| Código libre, p. ej. `DESCUENTO_LIBRE` | deducción / manual (`fijo`) | Valor fijo | 0 / + / − | Ninguno para código libre | Reproducción roja previa; ahora bloqueado, requiere política |
| Código conocido usado como `fijo` | ingreso/deducción / manual | Valor manual | Depende del tipo | Antes podía coincidir con filtro por código, sin acreditar origen/política | Ahora bloqueado; nombre/código o cuenta suministrada no autorizan clasificación |
| Calculadora desconocida o `manual` fuera del catálogo HTTP | Sin contrato | Antes podía ignorarse en el switch | No definido | No definido | Ahora se rechaza en servicio, sin eliminar silenciosamente una línea activa |

Las calculadoras conocidas emiten códigos canónicos; el código libre de configuración no modifica ese comportamiento. Una estructura alternativa sigue siendo nómina ordinaria. Las líneas inactivas no participan, conforme al comportamiento previo.

En anulación, todos los conceptos de un documento ya emitido conservan el tratamiento de sus **líneas contables originales**, intercambiando debe/haber mediante `REV-NOM-*`. No se vuelve a clasificar cada concepto con la estructura actual. Se reabren las fuentes según el circuito existente. Para conceptos históricos omitidos esto revierte lo efectivamente registrado, sin reparar automáticamente una omisión anterior.

## 3. Correspondencias contables existentes y AC-03

Las cuentas 6.7, 5.3, 2.1.6, 1.1.6, 2.1.5, 1.1.1.3 y fondos 1.1.1/1.1.2.x proceden del método de colilla vigente y AGENTS.md. Se resuelven contra el catálogo por los métodos actuales de `ContabilidadService`. No se hardcodean IDs empresariales ni se utiliza una cuenta de compensación nueva.

`docs/AC_03A_POLITICAS_VERSIONADAS.md` declara que su familia inicial no cubre nómina. `docs/AC_03B_RESOLUTOR_CONTABLE.md` describe inspección técnica sin activación financiera y `resolveForPosting(): never`. Se inspeccionaron también `AccountingPolicyService` y `AccountingAccountResolver`; no se encontró un contrato operativo que autorice conceptos salariales fijos. H08-R no activa ese resolutor, versiones demo, V2 ni clasificación empresarial AC-05.

`salary_structure_details.cuenta_contable_id` existe como columna nullable y relación del modelo, pero el formulario/controlador de estructuras no ofrece un protocolo autorizado para resolver su semántica económica. Su presencia o una cuenta no nula no bastan para decidir reconocimiento de gasto, pasivo, activo, impuesto o custodia. No se consumió ese campo como permiso implícito.

## 4. Conceptos sin tratamiento explícito

Se bloquea cualquier calculadora activa distinta de las nueve existentes, incluida `fijo`, incluso si usa `SAL_BASE`, `INSS` o una cuenta conocida. Se rechazan líneas desconocidas y combinaciones incompatibles de código/tipo/fuente, sin aproximar por nombre.

No se encontró bono fijo conocido ni deducción fija conocida con clasificación autorizada verificable. Los casos positivos correspondientes quedan bloqueados por esa ausencia; los ingresos y deducciones estándar sí se conservan. Un fijo desconocido de valor cero tampoco adquiere una política por no tener importe. El cero de un concepto **reconocido** es válido y las calculadoras conservan su omisión habitual de líneas cero.

## 5. Riesgos demostrados

Antes de modificar código se ejecutaron dos pruebas de rechazo que fallaron porque el servicio aceptaba los conceptos. Datos íntegramente sintéticos: base 1.000, sin otras fuentes.

| Caso previo | Neto de colilla observado | Haber del fondo observado | Debe / haber observado | Resultado del test de rechazo |
| --- | --- | --- | --- | --- |
| `BONO` fijo de ingreso 100 | 1.100 | 1.000 | 1.000 / 1.000 | Falló: aceptó concepto sin tratamiento |
| `DESCUENTO_LIBRE` fijo de deducción 100 | 900 | 1.000 | 1.000 / 1.000 | Falló: aceptó concepto sin tratamiento |

El `validarCuadre` real había aceptado ambos asientos en el doble. No se simula una clasificación para obtener ese resultado; son las calculadoras y el emisor previos ejecutados sobre memoria. Los mismos tests ahora pasan esperando rechazo previo a insertar corrida.

También se caracteriza el truncamiento previo de reducciones: `max(0, salario + extras − sin_goce − tardanzas)` podía ocultar un gasto salarial negativo y separar fondo de neto cuando otras fuentes mantenían neto positivo. Ahora se rechaza ese caso para revisión; no se inventa una contrapartida ni se cambia el cálculo de la deducción.

## 6. Correcciones realizadas

Se añade `ConciliacionNomina`, contrato puro y específico del asiento HRMS actual:

1. Valida estructura activa: calculadora reconocida, tipo coherente cuando está declarado, sin duplicación de calculadoras/códigos y sin parámetros negativos/no finitos de horas extra o tardanzas.
2. Valida cada línea generada: código/tipo/fuente exactos, importe representable no negativo y referencias válidas para amortización. Rechaza duplicados; permite varias líneas `ADELANTOS` únicamente para cuotas distintas.
3. Contrasta suma de conceptos con totales de colilla y prepara los grupos contables existentes en centavos.
4. Contrasta el asiento realmente generado con las cuentas resueltas, moneda e importes esperados, sin compensar debe/haber de una misma cuenta para ocultar una diferencia.

`NominaService` verifica estructura antes del cálculo y concilia todas las colillas antes de crear corrida. Se rechazan salario base no finito/negativo, ingresos calculados negativos y fuentes congeladas negativas/no representables que antes podían desaparecer o compensarse en una suma.

`ContabilidadService` aplica el mismo contrato a los detalles persistidos y totales de colilla antes de resolver período. Así un consumidor directo no puede saltarse la validación del servicio de nómina. La aritmética de agrupación/conciliación se concentra en el contrato; el método existente sigue creando las líneas y ejecutando el cuadre habitual. No hay un segundo motor de liquidación.

## 7. Conciliación de devengado, deducciones y neto

Se reutiliza `InventoryDecimal::scaled/text`, convención exacta ya usada por H05/H09, sin modificar ese helper. Importes por detalle: escala 2 y límite de `salary_slip_details.monto` DECIMAL(10,2). Totales por colilla: límite DECIMAL(12,2). Los cálculos de fórmulas, orden, redondeo salarial y tasas permanecen en las calculadoras existentes; H08 compara sus importes resultantes como centavos enteros.

El contrato exige:

```text
E = suma de ingresos reconocidos
D = suma de deducciones reconocidas
N = E - D >= 0
total_ingresos = E; total_deducciones = D; neto_pagar = N

S = SAL_BASE + HORAS_EXT - SIN_GOCE - TARDANZAS >= 0
C = COM_VENT; P = PROPINAS; R = INSS + IR; A = ADELANTOS
F = max(0, S + C - R - A)              (haber del fondo)
X = max(0, R + A - S - C)              (absorción desde custodia hacia caja)
X <= P
N = F + P - X
```

`S < 0` se rechaza; no se trunca. `F` por sí solo no tiene por qué ser igual al neto: parte del dinero del colaborador sale de custodia. Exigir `F = N` en presencia de propinas cambiaría una política que H08 no autoriza.

No se acepta precisión excesiva, NaN/Infinity, notación exponencial ni importes negativos como conceptos monetarios. El validador no redondea una entrada inválida para hacerla conciliable. El neto cero y los importes reconocidos cero se mantienen válidos.

## 8. Conciliación de cuentas y asiento

Se verifica el vector existente de importes, por cuenta resuelta y moneda base:

| Cuenta/papel | Debe | Haber |
| --- | --- | --- |
| 6.7 / Sueldos | S | 0 |
| 5.3 / Comisiones | C | 0 |
| 2.1.6 / Propinas por pagar | P | 0 |
| 1.1.6 / Anticipos | 0 | A |
| 2.1.5 / Retenciones | 0 | R |
| 1.1.1.3 / Custodia | 0 | P |
| 1.1.1 / Absorción en caja | X | 0 |
| Fondo efectivo/banco según método vigente | 0 | F |

Solo se resuelven las cuentas de grupos con efecto, además del fondo que ya se resolvía. Si varios papeles tienen la misma cuenta se acumulan debe y haber por separado. Se rechaza una moneda/cuenta inesperada o importe diferente, aunque el asiento esté balanceado. Se conservan `AccountIntegrityService::posting`, el control de período y `validarCuadre` existentes; H08 no sustituye su validación estructural.

Tres controles distintos: (A) cuadre debe/haber habitual; (B) cobertura económica de todos los conceptos; (C) conciliación de liquidación y desembolso/custodia. Los tres son necesarios. Una inyección en el doble redujo a 900 tanto el gasto como el fondo de una colilla de 1.000: seguía balanceado y la nueva verificación del emisor real lo rechazó, revirtiendo los dos niveles simulados.

## 9. Anulación y reversión

No se cambia `reversarColillaNomina`. La reversión copia las cuentas e importes originales con signos intercambiados; no consulta el nuevo contrato de conceptos para reclasificar documentos emitidos ni recalcula con una estructura modificada. La reapertura de comisiones, propinas y cuotas permanece en la transacción de anulación protegida por H07.

Se prueba emitir una ordinaria válida, añadir después un fijo no soportado a su estructura y anular: el original permanece intacto y el espejo conserva cuentas/importes. Esa estructura ya no se podría usar para una emisión nueva. Las omisiones históricas requieren revisión específica, sin correcciones automáticas.

## 10. Compatibilidad con H07 y consumidores

Los locks por empleado en orden de PK, comprobación inclusiva de cobertura y coordinación de anulación no se modificaron. La cobertura sigue revisándose antes de calcular conceptos. No se habilita una vía de ajustes extraordinarios ni otra ruta de procesamiento.

Se mantienen firmas públicas, rutas y payloads del wizard y administración de estructuras. El formulario puede seguir guardando una estructura fija; eso no la autoriza a liquidarse: preview/procesamiento devuelve el error de tratamiento no definido mediante los redirects existentes. No se borran ni ocultan esos conceptos para producir una colilla parcial.

Las modificaciones de `ContabilidadService` de H08 se limitan a importar el helper decimal y al cuerpo de `contabilizarColillaNomina`: reemplazo de sumas parciales por conciliación exhaustiva y verificación de líneas. Se preservan las capturas `Throwable` de H07 y la implementación de pagos de proveedor de H09.

## 11. Pruebas ejecutadas y aserciones

Resultado final: **69 pruebas y 259 aserciones aprobadas**: 38 casos H08 (27 puros y 11 de flujo con doble), más las 31 regresiones H07 existentes. No se modificaron sus expectativas ni sus fixtures.

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' `
  -d memory_limit=128M -d max_execution_time=40 -d zend.exception_ignore_args=1 `
  vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php `
  --do-not-cache-result --no-progress `
  tests/Unit/ConciliacionNominaTest.php tests/Unit/NominaConceptosIntegrityTest.php `
  tests/Unit/NominaCoverageTest.php tests/Unit/NominaWizardContractTest.php
```

| Casos solicitados | Evidencia y límite |
| --- | --- |
| 1–6. Base, extras, comisión, propina, legal, adelanto | Contratos puros; combinación con calculadoras y emisor reales sobre memoria. INSS positivo real, IR positivo puro |
| 7. Bono fijo conocido | Positivo bloqueado: no se identificó clasificación autorizada. No se inventa una para probarlo |
| 8. Bono desconocido | Reproducción previa fallida; ahora rechazo antes de corrida |
| 9. Deducción fija conocida | Positivo bloqueado por ausencia de contrato; retenciones legales existentes sí se prueban |
| 10. Deducción libre | Reproducción previa fallida; ahora rechazo antes de corrida; código INSS/cuenta explícita no eluden guardia |
| 11. Duplicados | Código duplicado y calculadora repetida rechazan. Varias cuotas distintas válidas; misma cuota repetida rechaza |
| 12. Cero válido | Código reconocido cero puro y salario cero procesado en doble, sin crear conceptos desconocidos |
| 13. Negativos | Línea negativa, base negativa y fuente de propina negativa rechazan; parámetros/valores no finitos o precisión inválida también |
| 14. Un centavo | Diferencias en totales y fondo detectadas; prueba H07 de cuadre inyectado de un centavo permanece verde |
| 15–16. Omisión balanceada y fondo incompatible | Vector puro y emisor real sobre doble rechazan gasto/fondo 900 frente a neto 1.000; saldo de filas restaurado |
| 17. Varias fuentes | Neto 968,77 = fondo 948,77 + custodia 20,00; conserva comisión congelada, INSS y cuota. Caso con déficit: custodia 100 − absorción caja 70 = neto 30, sin haber de banco |
| 18. Fallo de asiento | Centinelas H07 tras cabecera y asiento completo; nivel transaccional cero y rollback de snapshots |
| 19. Anulación | Espejo de líneas originales aunque cambie la estructura; H07 verifica reapertura y reproceso |
| 20. H07 | 31 casos originales aprobados, incluyendo interleaving simulado y locks; no es prueba InnoDB concurrente |
| 21. Sin parcialidad | Fijo inválido en empleado 2 rechaza todo el lote antes del primer INSERT; consumidor contable directo también rechaza |
| 22. Formularios | Regresión HTTP aislada del controlador mantiene payload sin tipo explícito, estructura opcional, redirects y rechazo de campos protegidos |

Los tests usan `PHPUnit\Framework\TestCase`, sin `tests/TestCase.php`, bootstrap empresarial ni `.env`. `ConciliacionNominaTest` es puro. El flujo usa `H07MemoryConnection` con PDO prohibido; solo se le añade un callback opcional para inyectar una omisión de filas en memoria. No es una implementación alternativa de nómina: calcula y genera asientos con los servicios reales.

Se sustituyen búsquedas de cuentas/moneda/período por fixtures técnicos y `AccountIntegrityService::posting` por un doble. Las líneas y `validarCuadre` son reales, pero esto **no valida ni aprueba el catálogo empresarial AC-05**. Las pruebas HTTP son aisladas, sin kernel completo. El rollback de snapshots no prueba rollback InnoDB.

Se ejecutó lint PHP de los seis archivos PHP de H08 y `git diff --check`. No se ejecutó `NominaCorridaTest` con `RefreshDatabase`, suites generales ni build de frontend (sin cambios de vistas/JS).

## 12. Pruebas SQL pendientes

Cuando exista el laboratorio VirtualBox + Debian y se autorice su uso, validar sobre esquema revisado y fixtures sintéticos: cada concepto estándar positivo/cero, varias cuotas, combinación completa, custodia con/sin déficit y rechazo de fijos/duplicados. Comprobar importes persistidos en centavos, cuentas activas/imputables y período abierto reales, sin sustituir `AccountIntegrityService`.

Inyectar errores después de colilla, movimientos de fuentes y asiento, demostrando llegada al punto de fallo y efectos dentro de la transacción. Leer desde segunda conexión tras rollback: ninguna corrida/colilla/concepto/asiento huérfano ni fuente consumida; nivel cero. Registrar AUTO_INCREMENT aparte. Validar anulación fiel al original con configuración posterior distinta y concurrencia H07 sobre el mismo empleado, sin transacción exterior que oculte commits.

No se preparó ni ejecutó infraestructura, filtros, servidor descartable, migraciones o escrituras SQL. Estas pruebas no deben trasladarse a las tres bases actuales.

## 13. Decisiones económicas pendientes

Para habilitar bonos/fijos y deducciones personalizadas se necesita autorización contable que determine, por concepto: identidad estable, tipo/fuente, significado económico, cuenta/contrapartida, efecto en devengado, base gravada, retenciones, neto, custodia y reversión; vigencia, origen, aprobación y forma de conservar el tratamiento histórico. Después deberá evaluarse si el esquema actual puede persistir ese contrato o requiere cambio autorizado independiente.

No bastan una etiqueta `BONO`, un código `SAL_BASE`, una cuenta elegida en un fixture ni `cuenta_contable_id` rellenado manualmente. Los casos positivos de fijo conocido permanecen bloqueados hasta contar con ese contrato. También se requiere decidir el tratamiento si reducciones exceden salario/extras; H08 lo rechaza en vez de reinterpretar comisiones o propinas como gasto salarial.

No se solicitan cambios de tasas, clasificación AC-05 ni aprobación implícita de fixtures. No se modifican estructuras existentes para acomodar las pruebas.

## 14. Archivos modificados

| Archivo | Cambio H08 |
| --- | --- |
| `app/Services/ConciliacionNomina.php` | Nuevo contrato puro de cobertura, totales y cuentas/importes |
| `app/Services/NominaService.php` | Validación de estructura/fuentes y conciliación antes de persistir |
| `app/Services/ContabilidadService.php` | Conciliación de colilla persistida y del asiento real, solo método de colilla |
| `tests/Support/H07MemoryConnection.php` | Callback opcional de fallo en memoria; PDO continúa prohibido |
| `tests/Unit/ConciliacionNominaTest.php` | Contrato puro de conceptos, importes y cuentas |
| `tests/Unit/NominaConceptosIntegrityTest.php` | Reproducción roja previa y regresión del flujo real con doble |
| `docs/H08_R_CONCILIACION_CONCEPTOS_NOMINA.md` | Este informe |

No se modificaron controladores, modelos, formularios, rutas, migraciones, catálogos, configuración o documentos históricos H07/H09/AC-03/AC-05. El diff H08 conserva los cambios previos de H07/H09 que ya estaban en la referencia del repositorio al iniciar esta fase.

## 15. Preservación de bases

No se abrieron conexiones SQL empresariales ni se cargaron credenciales. No hubo consultas, INSERT/UPDATE/DELETE, DDL, migraciones, seeders, restauraciones o resets en `salon_erp`, `salon_erp_test` o `salon_erp_bootstrap_check`. Las sentencias Eloquent del test fueron interceptadas exclusivamente en memoria; cualquier resolución de PDO lanza excepción.

Se preservó evidencia AC-04/AC-05 sin copiarla como fixture. No se utilizó storage empresarial para datos de ensayo. No se hicieron operaciones Git de escritura. La preservación se sustenta en ausencia de acceso SQL de esta intervención; no se presenta un cotejo de registros históricos que requeriría abrir esas bases.

## 16. Riesgos residuales

- Falta validación integrada MySQL de persistencia, transacciones, cuentas/períodos y concurrencia; 69 pruebas aisladas no la sustituyen.
- No se conoce cuántos documentos históricos, si alguno, tienen omisiones. No se consultaron ni repararon; corresponde una revisión posterior autorizada.
- Los fijos quedan indisponibles para liquidación hasta resolver su política, incluso con códigos antes coincidentes. Es el cierre conservador autorizado del acceso inseguro, no una reclasificación.
- El contrato permite solo el asiento actual. Añadir un concepto exige ampliar cobertura explícita con política aprobada; no habrá fallback por nombre/cuenta.
- Las fórmulas y redondeos internos salariales conservan su implementación y orden H11. H08 concilia resultados; no certifica fórmulas legales/laborales, periodicidad o clasificación económica empresarial.
- Los códigos configurados de calculadoras estándar siguen sin modificar los códigos canónicos emitidos. No se transforma una calculadora estándar en un fijo autorizado mediante etiquetas.
- El desembolso comprobado es el representado contablemente en el circuito actual; no se demuestra una transferencia bancaria externa ni se crea otro libro de pagos.

## 17. Recomendación de continuación

Obtener primero la decisión contable sobre conceptos fijos si deben utilizarse y, cuando esté disponible y autorizado el laboratorio Debian, ejecutar los escenarios integrados pendientes de H08/H07. Mantener bloqueada cualquier activación de conceptos sin contrato. La revisión H11 del orden/separación del cálculo requerirá una intervención propia; no se inicia aquí, ni H06, H05-V1, migraciones, cambios AC-05 o despliegues.
