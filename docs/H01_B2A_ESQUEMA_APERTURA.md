# H01-B2A — Esquema documental, sin escritores de apertura

## Resultado

Completado exclusivamente en salon_erp_test: 79 migraciones, dos tablas nuevas,
ningún escritor operativo, ruta nueva, movimiento, asiento o asignación emitidos.
Las políticas contables y operativas de H01-B2B siguen pendientes.

No se ejecutó RefreshDatabase, migrate:fresh, suite general ni Composer test.
Las pruebas no sembraron ni modificaron padres históricos. El único DML de negocio
de ensayo se limitó a las tablas documentales nuevas; sus fixtures estructurales
se revirtieron transaccionalmente salvo un preparado conservado para la guardia.

## 1. Migración y estructura

Nueva migración: `2026_11_14_000005_create_inventory_opening_documents.php`.
Las 78 migraciones anteriores no cambiaron; no hay backfill ni ALTER de tablas
históricas. Se reutilizan las claves compuestas (id,item_id) que creó H03-B1.

### inventory_openings

- UUID único, canónico en minúsculas, y hash SHA256 de contenido de 64 caracteres.
  El hash no es UNIQUE: su presencia/formato no implementa idempotencia financiera.
- contract_version=1 explícito, status prepared/confirmed sin valor por defecto,
  referencia documental y timestamp de registro explícitos.
- Fecha efectiva y de conteo nullable, pendientes de la política de corte.
- Ejecutor obligatorio, responsable del conteo y supervisor nullable, todos con
  FK RESTRICT a users. No se infiere que ejecutor sea contador/supervisor.
- document_evidence y policy_snapshot JSON nullable, con envoltura versión 1
  cuando se proporcionan. Un objeto vacío, versión ausente/string/null u otra
  versión no satisface el CHECK. No se prellena una aprobación ficticia.
- Contrapartida nullable con FK RESTRICT al catálogo; total_value_nio decimal(15,2)
  nullable; resultado contable nullable con FK RESTRICT y UNIQUE.

El CHECK de confirmación exige fechas, total, evidencia y snapshot de política.
Es una barrera estructural, NO una certificación de autorización, cuenta aprobada,
asiento correcto, estado de hijos o atomicidad. El futuro escritor deberá comprobar
también responsables, cuenta y resultado contable, integridad de todas las líneas,
aprobación real de políticas y pertenencia de los resultados antes del commit.
No hay actualmente un camino de aplicación que confirme estos documentos.

La referencia del acta no se hizo UNIQUE global: esa regla de numeración/corte no
ha sido aprobada. La identidad aprobada en esta fase es el UUID único.

### inventory_opening_lines

- Cabecera con FK RESTRICT y posición positiva UNIQUE dentro de ella.
- Artículo con FK RESTRICT; se permiten múltiples posiciones del mismo artículo.
- Clase física explícita: units, closed u open; fotografía de tipo, unidad,
  capacidad y representación, sin actualizar el artículo real.
- counted_units decimal(12,4), entero positivo cuando aplica; counted_remainder y
  container_capacity decimal(8,2); native_quantity decimal(15,2), exacta según la
  clase física. No se inventa un cerrado para documentar un remanente.
- Un abierto completo sigue siendo open. Capacidad positiva, remanente dentro
  del límite, estados no aplicables NULL y nativo ml/gr/oz para fraccionables.
- Lote externo y vencimiento nullable, sin referencia de fabricante, fecha de
  vencimiento o criterio de desconocidos inventados por defecto.
- Importe documental decimal(15,2) y costo informativo decimal(15,6), nullable;
  evidencia física/de valoración nullable y versionada cuando existe.
- Resultado de lote y de movimiento nullable y UNIQUE por resultado. FKs
  compuestas RESTRICT aseguran que el resultado corresponde al mismo item_id.
  No se permite reutilizar el mismo resultado en otra posición/documento.

Nueve FK en total, todas ON DELETE/ON UPDATE RESTRICT. La prueba comprueba sus
reglas reales en information_schema y rechazos MySQL 1452/1451/1062/3819.
No se agregaron cascadas, triggers, observers o automatismos.

Dos tablas bastan: las asignaciones V2 existentes ya enlazan lote y movimiento.
No se amplió esa tabla para unidades no fraccionables ni se falsificó und como ml.
La pertenencia del asiento/kárdex a la apertura concreta, más allá de existencia
y artículo, requiere validación del escritor futuro de sus referencias de origen.

## 2. Políticas desconocidas y límites de las restricciones

NULL en cuenta, valor, fechas o política significa pendiente/no establecido;
no significa cuenta 3.1, costo cero, fecha actual o autorización concedida.
SQL admite un importe explícito cero para no decidir ahora la política de costo
cero; ello NO autoriza su valoración o contabilización.

Un DECIMAL define almacenamiento, escala y límites, pero MySQL puede redondear
un parámetro con más decimales antes de evaluar CHECK. La futura validación de
entrada debe rechazar exceso de precisión antes de PDO, como H03-B2; esta fase
no afirma que el tipo DECIMAL sustituya esa validación.

Las posiciones prepared son propuestas. Dos abiertos propuestos no crean dos
abiertos físicos: no se crean lotes ni se actualiza stock. La validación agregada
del acta, el único abierto físico B1, la elegibilidad por historia, sumas de valor,
rol/PIN y reglas de corte quedan para H01-B2B. No se fingió una prueba de
idempotencia transaccional o confirmación indivisible sin ese escritor.

## 3. Instalación y reversión

Antes de DDL: MySQL con CHECK efectivo y modo estricto, sin prefijo y fuera de
transacción; padres InnoDB, esquema previo H03/H04 y claves compuestas esperadas;
ambos nombres nuevos libres. Un estado parcial se rechaza, no se sobrescribe.

down comprueba que existen las dos tablas, que no hay transacción activa y que
ambas están vacías. Cualquier documento, incluso preparado, bloquea la reversión
antes de DROP. Si están sin uso, elimina primero líneas y después cabeceras.
No borra registros para hacer pasar el guardia.

DDL MySQL no es transaccional entre varias tablas. Si falla una instalación real,
hay que inspeccionar el estado exacto antes de intervenir, sin repetir un reset.

Se ensayó también migrate:rollback por la ruta exacta y --step=1, con las dos
tablas vacías y sin tocar las otras 78 migraciones. El framework retiró únicamente
su registro de migración. Después se reinstaló y se verificó de nuevo el esquema.

Incidencia del arnés: la primera prueba combinada instaló y conservó los datos,
pero su filtro SQL no aceptó DROP TABLE terminado justo tras el identificador.
La barrera lo rechazó antes de enviarlo a MySQL. Se corrigió exclusivamente ese
filtro; la reversión vacía y el ensayo completo de instalación/down/up pasaron.
No se borró evidencia ni se reconstruyeron tablas históricas para corregirlo.

## 4. Contención focalizada del mecanismo heredado

Único cambio funcional: StockLedger::registrarApertura exige transacción activa,
relee/bloquea el artículo con FOR UPDATE y comprueba la existencia del kárdex
mediante lectura actual bajo ese mutex. No decide desde la copia cargada antes
de empezar la transacción. Esto evita eludir el rechazo V2 mediante un modelo
obsoleto y prepara el mismo protocolo que deberá emplear H01-B2B.

El controlador existente ya inicia la transacción antes de invocarlo. Se conserva
la semántica de copiar al ledger un saldo heredado; no se acepta conteo ni se
redirige a compras/documentos nuevos. El estado «ya tiene movimientos» sigue
devolviendo null. Los llamadores directos sin transacción ahora se rechazan,
en lugar de usar un lock sin protección transaccional.

No cambian el fallback de costo heredado, la ausencia de asiento de ese mecanismo
o su posibilidad previa de producir un ajuste cero por POST directo. Esas
limitaciones NO lo convierten en una apertura documentada. La contención actual
no autoriza utilizarlo como vía de carga inicial ni habilita H01-B2B.
La futura activación necesita mantener el mutex, comprobar ausencia de historia y
resolver explícitamente la convivencia/retiro de la ruta heredada. Si esta gana
primero y genera historia, el futuro escritor debe rechazar el artículo, no
convertir esa historia o sumar una segunda apertura.

Pruebas de solo lectura/locks: fuera de transacción, copia obsoleta de un V2 y
artículo heredado con ledger previo. No se ejecutó un primer registro heredado
exitoso porque emitiría un movimiento, prohibido en esta etapa.

## 5. Pruebas y compatibilidad

| Grupo autorizado | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| Reversión por ruta exacta de esquema nuevo vacío | 1 | 9 | Aprobado |
| Instalación y down/up sin uso; conservación histórica | 1 | 16 | Aprobado |
| Esquema, FK, CHECK, tipos, JSON y contención | 31 | 116 | Aprobado |
| Guardia con documento preparado persistido, no confirmado | 1 | 8 | Aprobado |
| Total de grupos aprobados | 34 | 149 | Sin fallos pendientes |

Los ensayos estructurales usaron solamente las tablas documentales como destino
de escrituras, con barreras SQL que rechazaban DML sobre los padres históricos.
Los resultados/lotes existentes se leyeron como referencias de fixtures, no se
crearon movimientos para fabricar esas referencias ni se migró su significado.
Los fixtures temporales no acreditan elegibilidad para una apertura real.

Compatibilidad H01-A: las peticiones con cantidades físicas siguen recibiendo
422 antes de escribir catálogo. H03-B1/B2/H04-B: datos, DDL, FK y evidencias
históricas permanecieron iguales; estado V2 y lectores existentes se consultaron
sin mutación. No se ejecutaron ventas, compras, mermas o anulaciones nuevas,
ni se presenta esta verificación estructural como una regresión financiera completa.

No se creó un modelo App para estas tablas, servicios de ingreso, comandos de
registro, rutas nuevas ni interfaces operativas. La colección de rutas conserva
una sola acción registrarApertura con su URL antigua. Las guardias existentes
de PHPUnit no se relajaron ni admiten una base alternativa.

## 6. Aislamiento y estado final

Preflight antes de instalar: PHP absoluto 8.3.30; salon_erp_test,
salon_erp_tester@localhost, 3306, roles NONE; USAGE global y exactamente los nueve
privilegios previstos sobre el esquema de test. Ambas bases ajenas devolvieron
el rechazo MySQL esperado. Las 78 migraciones aplicadas coincidían por nombre con
los archivos existentes; únicamente estaba pendiente la nueva.

Respaldo existente, comprobado sin sobrescribir:
`E:/salon-erp-backups/salon_erp_test_20261112_074028_3813668.sql` y su SHA256
`8778320250e84322267a7d99bf86e18f70c099d0117ea5b73dad67c2c32aa531`.

Línea base nueva: `storage/app/private/isolation-H01B2A-before.json`.
La inspección independiente de las tres bases utiliza solo SELECT/SHOW; la cuenta
administrativa no ejecutó DDL ni fue identidad de ejecución de PHPUnit.

| Base | Antes: tablas/filas/migraciones | Después | Verificación |
| --- | --- | --- | --- |
| salon_erp | 78/225/74 | 78/225/74 | Conteos, contenido y DDL idénticos |
| salon_erp_bootstrap_check | 78/202/74 | 78/202/74 | Conteos, contenido y DDL idénticos |
| salon_erp_test | 79/542/78 | 81/544/79 | Solo dos tablas nuevas, registro de migración y un preparado de ensayo |

También se compararon todos los conteos/hashes de contenido/DDL de las tablas
históricas de test y los 78 registros previos de migraciones: idénticos. En la
tabla migrations solo varían el registro nuevo y su secuencia autoincremental
normal del ensayo de rollback/reinstalación.

Permanece un documento prepared de guardia, referencia
`H01-B2A STRUCTURAL FIXTURE ONLY`, sin líneas, sin cuenta, política, valor,
resultado contable o efectos de stock. No es una apertura confirmada. Se conserva
para revisión; no se eliminó para permitir rollback.

El launcher nuevo restaura SALON_TEST_DB_RESET_ALLOWED=0 mediante finally incluso
si falla un grupo. Se confirmó el indicador en cero al terminar. Usa el mismo
bootstrap/TestCase y allowlist SQL existente. Los modos de instalación exigen
78 migraciones y los posteriores 79; no deben confundirse ni repetirse a ciegas.

## 7. Decisiones pendientes H01-B2B

| Decisión | Opciones para aprobación | Implicación conservadora propuesta, aún no activada |
| --- | --- | --- |
| Contrapartida y código | Cuenta específica de saldos iniciales; 3.1 solo ante aportación real; balance inicial integral | No seleccionar cuenta ni habilitar contabilización mientras falte aprobación |
| Base documental de valoración | Costos externos acreditados; acta de valoración aprobada con criterio explícito y valor NIO | Sin precio de venta/fallback ni compra, IVA, pasivo o cambio ficticio |
| Costo cero | Rechazar; o excepción documentada y autorizada | SQL puede representarlo; nunca equivale a aprobarlo o usarlo por falta de dato |
| Corte y facturas anteriores | Corte único; o implantación por etapas documentada | No duplicar como compra un saldo ya incluido; resolver pendientes y fechas en servidor |
| Lote de fabricante desconocido | Bloquear; o referencia interna explícita bajo acta, diferenciada de fabricante | No inventar un lote externo ni tomar la fecha actual como procedencia |
| Vencimiento desconocido/vencido | Exigir cuando corresponda; excepción documentada o tratamiento separado | No dar por vigente el desconocido ni generar mermas/cuarentena por inferencia |
| Responsable físico | Ejecutor declara conteo propio; o constancia verificable de otro responsable | No atribuir confirmación a otro ID enviado por navegador; resolver supervisor por PIN existente |
| Artículo vacío a V2 | Mantener bloqueado hasta clasificación; o aceptación individual explícita con ausencia de historia y saldos cero | Nunca backfill, conversión masiva o reinterpretación de remanentes heredados |

H01-B2B deberá implementar el contenido canónico, autorización efectiva, lectura
actual de la clave idempotente, restricciones de historia/corte, conciliación por
artículo/lote/acta/asiento y carreras MySQL. Ninguna de esas decisiones se ha tomado
porque existan columnas o fixtures capaces de almacenar sus valores.

## 8. Archivos, riesgos y reversión

Archivos de código: una migración nueva; StockLedger.php (solo mutex/lectura actual
del mecanismo heredado); isolation-snapshot.php (fase y comparación ampliadas);
run-h01b2a-tests.php y OpeningDocumentFixtures.php; cinco pruebas Feature nuevas.
Este informe es nuevo. H01_B1_DISENO_APERTURA.md ya estaba sin seguimiento al
comenzar y se conserva sin cambios. No se hicieron commits ni cambios al índice.

Diff específico de los diez archivos PHP: `storage/app/private/h01b2a-review.diff`
(46.776 bytes), SHA256
`51e0e29278c937e9b0f3c03911fcede0cdb7650fd1130a33e40d9b67e3bd6524`.
Incluye archivos nuevos y cambios seguidos; excluye ambos informes Markdown y
el documento H01-B1 preexistente. `git apply --reverse --check` pasó sin aplicar
la reversión. Los diez archivos pasaron PHP lint y `git diff --check` pasó.

Riesgos/límites: falta la política contable/operativa; DECIMAL no valida por sí solo
la precisión del request; FK no prueba origen/autorización del resultado ni suma
de hijos; la contención heredada no constituye el escritor nuevo ni una prueba
de sus carreras; no hay garantía de idempotencia transaccional completa todavía.
No se activó V2/H04-B o esta apertura en desarrollo. UI-01, H01-B2B, stock inicial,
cuentas, configuración global, credenciales y dependencias permanecen fuera del cambio.

Reversión actual: no ejecutar rollback con el preparado conservado; down debe
rechazarlo. Mantener tablas/datos y revisar el diff si se retira el código; no
borrar evidencia para forzar la reversión. La reversión sin uso ya se comprobó
antes de crear esa evidencia. Cualquier limpieza/reconstrucción posterior de test
necesita su autorización y procedimiento propios, no una reparación automática.

Comandos de los grupos ejecutados con PHP 8.3.30 de Laragon:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-h01b2a-tests.php preflight
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/isolation-snapshot.php before H01B2A
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-h01b2a-tests.php empty-rollback
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-h01b2a-tests.php install
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-h01b2a-tests.php structure
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-h01b2a-tests.php guard
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/isolation-snapshot.php after H01B2A
```

La incidencia inicial se explica arriba; empty-rollback se ejecutó con las tablas
nuevas todavía vacías. No repetirlo ahora: la guardia con evidencia debe bloquear.
