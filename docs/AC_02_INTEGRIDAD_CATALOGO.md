# AC-02 — Integridad del catálogo y protección histórica

Fecha: 2026-11-16. Estado: implementado y verificado en salon_erp_test.
No hay asociaciones AC-03, migraciones nuevas, sustitución de códigos ni activación V2 en desarrollo.

## 1. Resultado y alcance de la evidencia

102 pruebas / 616 aserciones aprobadas con la versión definitiva. Los casos nuevos
de catálogo/manuales usan el rol contador. Las regresiones H03/H04 mantienen sus
roles/fixtures existentes. No se ejecutó la suite general, Composer test, instalación
ni rollback de B2A, RefreshDatabase, migrate:fresh o migraciones.

| Hallazgo AC-01 | Inspección previa y comprobación de esta entrega |
| --- | --- |
| Cuenta no sistema con historia podía cambiar código/tipo/naturaleza/padre/movimiento | Confirmado por ausencia de guardia en update anterior. Pruebas actuales rechazan cada modificación y conservan detalles y resultado histórico |
| Dependencias distintas del diario no estaban cubiertas | Confirmado en destroy/update anteriores. Fixtures SQL explícitos de banco, tipo de gasto y estructura salarial prueban detección y rechazo, sin cambiar datos fuera de test |
| Reversión manual aceptaba automáticos por ID | Confirmado por código: antes solo excluía modulo_origen=reversion. Prueba actual rechaza ventas, compras, inventario, nómina y caja chica; no se ejecutó deliberadamente la reversión insegura anterior |
| Manuales seleccionaban el primer período abierto | Confirmado por código. Ahora una fecha 2031-04-05 usa abril2031 y una fecha cerrada no deja asiento parcial |
| Inactividad/imputabilidad no se comprobaban uniformemente en servicios | Confirmado por resolutores y caminos anteriores. Se comprueban al validar el asiento antes de commit, con pruebas de rollback |
| Autociclos/ciclos simples | Ya existían comprobaciones HTTP: no se presenta como defecto nuevo. Se conservan y se trasladan al control transaccional del servicio |
| Carrera de reparentados | Riesgo estático previo; no se reprodujo con el código inseguro anterior. Con el protocolo nuevo se ejecutaron dos conexiones MySQL independientes y se verificó espera, aceptación/rechazo y árbol persistido |
| Naturaleza opuesta a padre o cuenta imputable con hijos | No son defectos por sí mismos: descuentos/retiros y Caja General son ejemplos legítimos. Se preservan sin convertir cuentas automáticamente |
| Cascadas y vínculos sin FK | Riesgo de esquema existente, no eliminado ni presentado como resuelto a nivel SQL. Se refuerzan los caminos administrativos; no se cambian migraciones |

Las pruebas de protección no se describen como una ejecución antes/después de cada
vulnerabilidad. La confirmación del comportamiento anterior procede de inspección;
la ejecución corresponde a las guardias y resultados de la implementación nueva.

## 2. Protecciones implementadas

AccountIntegrityService concentra:

- Cuenta real, activa e imputable para operaciones ordinarias; validación de tipo
  cuando la operación admite una cuenta variable de gasto/costo/ingreso.
- Período real, abierto y que contenga la fecha del asiento, dentro de transacción.
- Verificación de reversa contra las líneas persistidas del asiento original:
  cuentas, moneda e importes exactamente invertidos. Permite esas cuentas originales
  inactivas/no imputables sin habilitarlas para operaciones nuevas.
- Dependencias: detalle_asientos, cuentas_bancarias, tipos_gasto,
  salary_structure_details y counterpart_account_id de inventory_openings cuando
  esa tabla existe. No requiere instalar B2A en desarrollo para administrar cuentas.
- Protección de código, tipo, naturaleza, padre e imputabilidad de cuentas utilizadas
  o de ramas que contienen cuentas utilizadas. Un saldo cero no elimina historia.
- Desactivación de cuenta no sistema con solo historia contable: permitida sin
  cambiar permite_movimiento ni sus detalles. Si hay asociaciones operativas o
  subcuentas activas, se rechaza para no invalidarlas silenciosamente.
- Cuentas sistema: conserva la regla previa de cambiar solo nombre, y no borrarlas.
  No concede un nuevo permiso de desactivación de cuentas sistema.
- Eliminación: solo hoja no sistema sin uso ni dependencias; rechazo 403 en los
  demás casos. No se borra un movimiento financiero ni se elimina evidencia histórica.

No se introdujo una resolución por papel/versión. ContabilidadService mantiene
sus códigos, importes, signos y fórmulas. validarCuadre añade la validación de
cuenta/período y conserva la igualdad exacta por centavos. No se considera la
naturaleza deudora/acreedora como prohibición de usar el lado contrario del asiento.

Las cuentas variables de gastos, caja chica y cargos/abonos bancarios repiten el
control de tipo en el servicio contable, además de la validación general del asiento.
Los destinos fijos siguen usando las cuentas de sistema protegidas existentes.

## 3. Árbol y protocolo de concurrencia

Las modificaciones del catálogo usan un mutex MySQL GET_LOCK identificado por la
base de datos, con espera máxima de10 segundos y liberación en finally. Este mutex
solo coordina escritores administrativos del catálogo; no se incorpora al flujo
ordinario de ventas o inventario ni reemplaza sus bloqueos.

Dentro de la transacción se relee el catálogo mediante FOR UPDATE e ID ascendente,
antes de validar y escribir. Así dos ediciones de ramas no toman decisiones sobre
dos árboles independientes obsoletos. Se comprueban padre existente/activo, tipo
compatible y ancestros sin repetición, incluido el propio nodo.

Una naturaleza contraria sigue siendo válida para correctoras; sus valores continúan
limitados por las validaciones y el catálogo. No se fuerza igualdad de naturaleza
padre/hijo ni se cambia el significado de descuentos o retiros.

Reparentar una rama sin uso actualiza sus niveles descendientes en la misma
transacción. Si la rama contiene historia/dependencias, se rechaza la alteración;
no se reestructura para acomodar un cambio. Los agrupadores no reciben nuevas
imputaciones. Se conserva la particularidad ya existente de cuentas imputables con
subcuentas; agregar una subcuenta no transforma automáticamente al padre en agrupador.

El importador existente reutiliza este servicio y el bloqueo administrativo para
no ofrecer un segundo camino que eluda validaciones. No se importó el Excel real
ni se ejecutó una importación en esta fase; esa integración se revisó estáticamente.

Carrera ejecutada: dos procesos PHP/conexiones MySQL independientes intentan A→B
y B→A. El primero mantiene el mutex; el segundo comunica su intento y no finaliza
mientras aquel lo retiene. Tras liberarlo, el primero confirma y el segundo rechaza
el ciclo. Se verifican IDs de conexión distintos, padre/nivel persistidos y salida
limpia de procesos/conexiones. Solo se eliminan después las cuentas de fixture sin
uso mediante la eliminación protegida; no se limpian documentos financieros.

## 4. Manuales, períodos y reversas de origen

AsientoManualController::reversar bloquea y relee el original; solo acepta un manual
contabilizado. Rechaza automáticos, borradores/reversas y una reversión ya existente.
La búsqueda actual del resultado y el bloqueo del original evitan decidir con una
copia obsoleta. Mantiene los permisos de rutas actuales y no añade un PIN o rol nuevo.

La reversión manual legítima copia las líneas originales invertidas, mantiene sus
cuentas incluso inactivas y registra la operación en el período de la fecha actual.
Los nuevos manuales resuelven el período de su fecha mediante el mismo resolver
mensual existente de ContabilidadService: no se elige el primer abierto. No se
cambia la política de generación de períodos ni se permite escribir en uno cerrado.

El cierre relee/bloquea el período y repite las mismas condiciones previas de abierto
y no futuro dentro de su transacción. El final del cierre valida también cuentas
imputables y pertenencia de fecha. No cambian el cálculo del resultado, la cuenta
de cierre o sus signos. La prueba conserva el Estado de Resultados antes/después
del cierre y rechaza nuevas operaciones fechadas en ese período.

Las reversas de venta/nómina pasan al validador el asiento original obtenido por
sus servicios. Para compras se identifica el COM original por módulo, documento y
numeración existente; se exige concordancia exacta de líneas antes de confirmar.
No se cambia la resolución de cuentas ni se recalcula el importe original. Una
compra sin asiento original verificable se rechaza, no se compensa inventando uno.

H04-B continúa decidiendo restitución física/evidencia y conservación de CST/CMV.
AC-02 no amplía qué materiales pueden regresar ni modifica costos, lotes, fotografías,
asignaciones, impuestos, pagos o comisiones. La reversión de automáticos sigue en
su servicio de dominio; el endpoint manual ya no puede sustituirlo.

## 5. Archivos modificados o creados

| Archivo | Cambio |
| --- | --- |
| app/Services/AccountIntegrityService.php (nuevo) | Validación compartida, dependencias, transacciones y árbol |
| app/Http/Controllers/CuentaContableController.php | Delega altas/edición/borrado e integra protección del importador; errores de dominio JSON422 sin trazas |
| app/Imports/CuentasContablesImport.php | Usa el mismo servicio para cuentas importadas, sin ejecutar el Excel |
| app/Services/ContabilidadService.php | Validador previo al cuadre, controles de tipo variable y referencia original en reversas; exposición del resolver de período existente |
| app/Http/Controllers/AsientoManualController.php | Período por fecha y reversión exclusivamente manual con origen/repetición controlados |
| app/Http/Controllers/PeriodoContableController.php | Relectura bloqueante del período y validación final de imputación |
| tests/Feature/AccountIntegrityTest.php (nuevo) |14 casos funcionales con rol contador, todos transaccionales |
| tests/Feature/AccountHierarchyConcurrencyTest.php (nuevo) | Carrera MySQL real de jerarquía, fixtures sin uso y verificación persistida |
| tests/Support/ac02-hierarchy-worker.php (nuevo) | Worker aislado, barreras y cierre de conexión |
| tests/Support/run-ac02-tests.php (nuevo) | Preflight y selección fija de casos sin reset |
| tests/Support/isolation-snapshot.php | Fase AC02, comparación de contenido/estructura y secuencias de test diferenciadas |
| docs/AC_02_INTEGRIDAD_CATALOGO.md (nuevo) | Este informe |

CuentaContable y sus migraciones/relaciones existentes se reutilizan sin cambios.
No hubo modificaciones de AGENTS.md, configuración, credenciales, APP_KEY o dependencias.

## 6. Pruebas finales y límites de cobertura

| Grupo | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| AccountIntegrityTest |14|87|Aprobado |
| FractionalInventoryV2Test + InventoryAccountConsistencyTest + HistoricalInventoryReturnTest |87|515|Aprobado |
| AccountHierarchyConcurrencyTest |1|14|Aprobado |
| Total final |102|616|Sin fallos pendientes |

Cobertura nueva: alta/subcuenta/correctora, padre incompatible, ciclos/autodescendencia,
niveles, historia propia y descendiente, banco/gasto/estructura salarial, inactividad,
cuenta no imputable, sistema, período correcto/cerrado, cierre, automáticos desde
manual, reversión manual válida/repetida, compra con cuenta histórica inactiva,
rollback de encabezado/detalles y rama, tipo de cuenta de gasto y consulta histórica.

Regresiones: compras/landed cost, ventas mixtas y aprobación, consumo cero/positivo,
valoraciones y asignaciones V2, insuficiencia/rollback y anulaciones históricas con
y sin restitución. No se ejecutó la suite completa de nómina/bancos, ni carreras
financieras H02/H04 adicionales; la carrera nueva es exclusivamente administrativa.
No se presenta esa cobertura como una certificación de todos los flujos del ERP.

Comandos del procedimiento (PHP absoluto8.3.30):

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 tests/Support/run-ac02-tests.php preflight
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 tests/Support/isolation-snapshot.php before AC02
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 tests/Support/run-ac02-tests.php core
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 tests/Support/run-ac02-tests.php regressions
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 tests/Support/run-ac02-tests.php concurrency
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 tests/Support/isolation-snapshot.php after AC02
```

Los grupos se repitieron cuando hubo correcciones relevantes, nunca se amplió la
selección a suites generales. Lint de los11 archivos PHP y diff --check aprobados.

Incidencias corregidas del ensayo: las excepciones de catálogo necesitaban una
respuesta JSON explícita bajo el renderizado web existente; stream_select sobre
pipes Windows no acreditaba espera y se reemplazó por barrera/marcador del worker;
SHOW CREATE TABLE omite AUTO_INCREMENT=1 en tablas nunca usadas, lo que requirió
comparar correctamente la aparición de esa cláusula tras fixtures revertidos.
No se restauró un dump, borró historia o recreó el esquema para resolverlas.

## 7. Aislamiento, respaldo y comparación de bases

Preflight: PHP8.3.30, salon_erp_test, salon_erp_tester@localhost, puerto3306;
CURRENT_ROLE()=NONE; USAGE global y exactamente los nueve privilegios de esquema
previstos. Lecturas de las otras bases rechazadas por MySQL. Se compararon por
nombre las79 migraciones del árbol con las aplicadas. No se ejecutó DDL.

Respaldo disponible y SHA256 comprobado:
E:/salon-erp-backups/salon_erp_test_20261112_074028_3813668.sql
`8778320250e84322267a7d99bf86e18f70c099d0117ea5b73dad67c2c32aa531`.
No se sobrescribió ni generó otro respaldo para este ensayo transaccional.

Línea base nueva sin sobrescribir: storage/app/private/isolation-AC02-before.json.
El comparador independiente usa administración exclusivamente para SELECT/SHOW en
las tres bases fijas; ningún test, worker o escritura del ERP se ejecutó como root.

| Base | Antes: tablas / filas / migraciones | Después | Comprobación |
| --- | --- | --- | --- |
| salon_erp |78 /225 /74|78 /225 /74|Conteos, contenido y DDL completo idénticos |
| salon_erp_bootstrap_check |78 /202 /74|78 /202 /74|Conteos, contenido y DDL completo idénticos |
| salon_erp_test |81 /544 /79|81 /544 /79|Conteos y contenido de todas las tablas idénticos; estructura sin cambios, secuencias de fixtures pueden avanzar |

El comparador no ignora DDL arbitrariamente: normaliza solo el valor de la cláusula
AUTO_INCREMENT. Cuando antes esa cláusula no existía, exige que el hash del DDL
actual sin esa cláusula sea exactamente el hash del DDL original. Así se verificaron
bancos/cuentas_bancarias/tipos_gasto, vacíos antes y después. No se rebobinó su secuencia.
El preparado documental de B2A y las79 filas de migraciones se conservaron.

SALON_TEST_DB_RESET_ALLOWED nunca se habilitó; el runner lo mantiene en0 mediante
finally y se comprobó el archivo al finalizar. Sin pruebas o migraciones sobre
desarrollo/bootstrap, sin activación V2 ni despliegues.

## 8. Riesgos pendientes y límites

- La protección es de aplicación para los caminos revisados; no pretende impedir
  DML administrativo externo o llamadas directas de código confiable que omitan
  el servicio. Siguen existiendo cascadas/vínculos sin FK. Cualquier endurecimiento
  SQL adicional requiere análisis de datos y autorización de migración separada.
- Las ediciones administrativas serializan el catálogo y toman locks de sus filas.
  Es una solución acotada para un catálogo pequeño; puede causar espera/contención
  con imputaciones. No se añaden reintentos financieros automáticos. Una espera o
  deadlock debe fallar/rollback, no permitir una edición parcial. No se certificó
  una carrera de cierre contra venta ni cada combinación catálogo–finanzas.
- No se rediseñan reportes por prefijo ni se permiten renumeraciones de cuentas
  utilizadas: eso sigue siendo AC-03. Tampoco se clasificó historia previa dudosa.
- Cualquier consumidor que intentase una nueva imputación a cuenta inactiva o
  agrupadora ahora se rechaza; no se lo remapea a otra cuenta. Incluye caminos
  heredados potenciales que usen agrupadores. Las operaciones legítimas inspeccionadas
  de ventas/compras V2 y reversión pasaron sus regresiones.
- La importación quedó integrada por código, no ensayada con el archivo real.
  No hay asociaciones versionadas, política de apertura aprobada o escritor H01-B2B.
- La auditoría durable/versionado de modificaciones continúa pendiente de AC-03;
  esta entrega no inventa un asiento ni una tabla de auditoría para registrar edición.

## 9. Diff, Git y reversión

Diff específico de11 archivos PHP (modificados y nuevos), excluyendo documentación
y Excel preexistentes: storage/app/private/ac02-review.diff,57.842 bytes.
SHA256: `2dfd7b0b5f37ace2f199f7d3934882934031e0a5cb924520f72551287f0595bd`.
git apply --reverse --check aprobado sin aplicarlo. El informe AC-01 y el Excel
ya estaban sin seguimiento al iniciar; se preservaron y no se incluyeron en el parche.

Reversión: revisar el parche, revertir únicamente AC-02 y retirar sus archivos nuevos
y este informe. No hay migración que revertir ni respaldo que restaurar. No modificar
datos para bajar secuencias ni eliminar evidencia de otras fases. Si después se usa
el código para nuevas operaciones reales, revisar sus documentos antes de decidir
cualquier corrección; retirar código no autoriza revertirlos automáticamente.

No se realizaron commits ni se continuó con AC-03, H01-B2B, UI-01 o despliegues.
