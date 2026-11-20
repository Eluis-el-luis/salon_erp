# AC-05B3B2 — Persistencia concurrente de borradores sintéticos

Fecha: 20 de noviembre de 2026. Estado: **implementado y verificado exclusivamente en salon_erp_test**. No existe publicación, aprobación económica, clasificación empresarial, edición emitida o integración con reportes operativos. No se crearon commits ni rutas HTTP.

## 1. Preflight, Git y evidencia conservada

Rama `feature/panel`; HEAD inicial/final `330ebd00b8ca2f873e04eed9e20f8e41207c5d31`. Índice vacío. Al iniciar estaban pendientes únicamente el informe G1 y los cinco archivos B1 esperados. Se registraron sus SHA256 y se volvieron a comparar al terminar: **los seis permanecen idénticos**. No se sobrescribieron ni prepararon.

Se solicitó y obtuvo una excepción de lectura PowerShell/rg para esta fase, porque AGENTS.md §9 exige herramientas de workspace y la sesión no disponía de lector local alternativo. No se imprimieron secretos. Se inspeccionaron los auxiliares de seguridad, respaldo, baseline, esquema contable, claves y contrato B1 antes de DDL.

Preflight SQL dedicado, anterior a cualquier escritura:

- PHP **8.3.30**, MySQL **8.4.3**, puerto3306, raíz del proyecto verificada.
- Identidad **salon_erp_tester@localhost**, roles SQL NONE y base exacta **salon_erp_test**.
- Privilegios cotejados contra los existentes: USAGE global y SELECT/INSERT/UPDATE/DELETE/CREATE/DROP/ALTER/INDEX/REFERENCES solo sobre test. No se modificaron permisos.
- SELECT sobre desarrollo/bootstrap denegado con1142; no hay DSN alternativo del repositorio.
- **82 migraciones aplicadas**, cotejadas por nombre con los82 archivos existentes; **89 tablas** iniciales.
- InnoDB y restricciones pertinentes inspeccionadas mediante SHOW CREATE TABLE; users.id es BIGINT UNSIGNED.
- Preparado131: prepared, banco221, resultados financieros NULL.
- SALON_TEST_DB_RESET_ALLOWED=0, comprobado y restablecido en finally en los auxiliares del arnés.

Evidencia privada: `storage/app/private/ac05b3b2-preflight.json`. Contiene estructuras inspeccionadas, no credenciales ni datos de cuentas reales usados para clasificar.

## 2. Respaldo nuevo y línea base

Se amplió mínimamente la lista de fases del auxiliar de respaldo existente para admitir AC05B3B2 con82 migraciones. Los modos anteriores se conservan.

| Dato | Resultado |
| --- | --- |
| Archivo nuevo | `E:/salon-erp-backups/salon_erp_test_AC05B3B2_20261120_080735_fb27aadd9527.sql` |
| SHA256 | `3d33ded994f74b98d9a12bedee6a4e12cebe190b1e9ad4931e7f918a470967ff` |
| Tamaño | **1.536.297 bytes** |
| Contenido inicial | 89 tablas,1.873 filas,82 migraciones |
| Manifiesto | `storage/app/private/ac05b3b2-backup.json` |
| Sidecar | `.sql.sha256` junto al dump |

Exportación single-transaction, sin table locks, nombre único reservado de forma exclusiva, exit0, marcador final y número de tablas comprobados. Filas/DDL fuente iguales antes/después de exportar; tamaño/hash/sidecar verificados de nuevo antes de instalar. Se conservó el aviso conocido de mysqldump con skip-password. No se restauró el respaldo para probarlo.

El primer intento no pudo reservar el archivo externo por el sandbox. No se ejecutó DDL/DML antes de resolver esa restricción. Tras autorización de ejecución ampliada se generó el respaldo indicado, sin sobrescribir ninguno anterior.

Baseline administrativo independiente, exclusivamente lector, de las tres bases: `storage/app/private/ac05b3b2-isolation-before.json`. Usa el mecanismo local previo de inspección administrativa, con transacción READ ONLY. **No es una conexión alternativa del repositorio**: todos los escritores/DDL usan la identidad restringida. La comparación final incluye hashes de todas las filas y DDL completo de cada tabla anterior, incluidos sus contadores AUTO_INCREMENT.

## 3. Decisión de esquema mínimo

Se implementaron solo dos tablas nuevas:

1. `test_financial_presentation_drafts`.
2. `test_financial_presentation_draft_audits`.

No se separaron las asignaciones por cuenta: son IDs hipotéticos del contrato en memoria, no asociaciones empresariales hacia cuentas del catálogo. Persistirlas como FK a cuentas reales daría un significado que el ensayo no tiene y exigiría más tablas. Cada revisión conserva el **JSON canónico exacto y su SHA256**; la cabecera tiene la revisión actual y la auditoría conserva sus versiones anteriores completas. No hay tabla de grupos, emisión o aprobación.

No se modificó ninguna tabla financiera existente. Las únicas FK externas nuevas apuntan a users para conservar el actor técnico del arnés, sin modificar esa fila. Las asignaciones ficticias900001 y demás IDs del contrato no se resuelven contra el catálogo ni prueban su cobertura real.

### Cabecera

- ID interno; document_uuid único; family_uuid+version_number únicos.
- actor_id resuelto desde servidor; UNIQUE(id,actor_id) para referencias compuestas.
- purpose, origin, installation_scope y estado explícitos; solo simulation/synthetic_fixture/TEST-FIXTURE-ONLY.
- status draft o frozen_simulation. El segundo existe exclusivamente para el fixture de protección; no es una publicación.
- Número de revisión, LONGTEXT canónico binario y hash ASCII.
- Procedencia JSON generada por el repositorio; timestamps SQL UTC de creación/actualización.
- FK actor→users con RESTRICT; CHECK de UUID, versión/revisión, JSON válido, SHA2 del contenido, correspondencia de identidad/purpose/origin/author del JSON y procedencia del arnés.

### Auditoría

- draft_id+actor_id→cabecera(id,actor_id), FK compuesta RESTRICT: impide cruzar el actor de otro documento.
- request_uuid UNIQUE global de ensayo; UNIQUE(draft_id,revision).
- Evento created/revised, revisión y expected_revision.
- before_content/before_hash y after_content/after_hash íntegros, motivo sintético y fecha SQL UTC.
- CHECK de hashes/JSON y coherencia: creación1/después de0/sin anterior; revisión posterior con expected_revision=revision−1 y contenido anterior obligatorio.

La aplicación valida el contrato completo y la cadena; los CHECK SQL no sustituyen todas sus reglas. No hay triggers ni permisos nuevos. La auditoría no tiene métodos de edición/borrado en el servicio; **no se afirma inmutabilidad criptográfica frente a quien ya posea acceso SQL directo con UPDATE/DELETE**. Ningún hash funciona como firma económica.

## 4. DDL test-only: revisión, instalación y reversión vacía

El DDL reside en `tests/Support/SyntheticPresentationDraftSchema.php`, **no** en database/migrations y no implementa Migration de Laravel. Los82 archivos previos y la tabla migrations permanecen sin cambios. Artisan migrate/migrate:rollback no descubre ni instala este esquema.

Antes de aplicarlo se revisaron los CREATE TABLE completos, tipos, claves, CHECK, dependencias y procedimiento de retirada. El instalador exige contrato CLI, bootstrap controlado, PHP/raíz/entorno, reset0, identidad SQL exacta, denegaciones de otras bases,82 migraciones,89 tablas, respaldo AC05B3B2 válido y baseline inicial.

Se ejecutó expresamente:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/ac05b3b2-install.php install-and-verify-empty-rollback
```

Resultado: instalación de las dos tablas → comprobación de vacío → retirada únicamente de ambas tablas vacías → reinstalación → preflight con91 tablas y82 migraciones. Todo ello ocurrió **antes de crear borradores**.

La retirada consulta ambas tablas y rechaza cualquier documento o auditoría; la prueba con evidencia confirmó ese rechazo. No borra filas para poder hacer rollback. DDL MySQL no es transaccional: ante instalación parcial se debe detener y revisar, no limpiar automáticamente. El procedimiento de retirada vacía es de mantenimiento con el arnés detenido; **no se certifica una carrera de DROP contra nuevos escritores**. Ahora contiene evidencia y la retirada está bloqueada.

## 5. Frontera del repositorio

`SyntheticFinancialPresentationDraftRepository::forHarness()` crea internamente una única PDO con DSN fijo test y credencial restringida del bootstrap. No recibe PDO, DSN, base o actor desde el cliente. Constructor privado.

Exige simultáneamente CLI, PHP8.3.30, raíz correcta, bootstrap test, APP_ENV testing, reset deshabilitado y contrato interno `AC05B3B2_SYNTHETIC_DRAFTS_CLI_V1`. Comprobar solo APP_ENV sería insuficiente. Revalida la frontera, DATABASE/CURRENT_USER/puerto/roles/versión y actor al entrar en cada operación pública. Un test puro, con PDO deshabilitado y sin bootstrap de test, comprobó rechazo antes de abrir SQL.

El arnés selecciona en servidor el primer usuario activo alvaro/contador. Guarda su FK como actor técnico y exige author=`SYNTHETIC:executor_<id>` en el contrato. No acepta un ID de actor o aprobador como parámetro. **Es selección controlada de actor de ensayo CLI, no prueba de que una persona se autenticó o aprobó económicamente el documento.** Se registra ese mecanismo en provenance; no hay sesión/permiso empresarial nuevo ni bypass de roles generales.

El contenido no puede promoverse a business cambiando purpose, origin, instalación o JSON: se rechaza al validar, verificar y mediante controles de cabecera. No hay métodos de publicación/aprobación, eventos emitidos o integración con AC-03. VALID no concede autoridad; REQUIRES_ACCOUNTING_APPROVAL puede persistirse como hipótesis draft con advertencia, nunca como aprobación.

## 6. Contrato de persistencia y recuperación

API interna:

```php
create(string $documentUuid, array $content, string $reason): array
get(string $documentUuid): array
revise(string $documentUuid, string $requestUuid, int $expectedRevision,
       string $expectedHash, array $replacement, string $reason): array
```

Se reutiliza FinancialPresentationContractValidator; este utiliza FinancialPresentationCanonicalizer. No se modifica B1 ni se copia su algoritmo de canonicalización. El servidor calcula canonical_json/hash; un hash extra dentro del contenido es inválido. Referencias/motivos son sintéticos explícitos. Los UUID identifican solicitudes/documentos, no autorizan acceso.

La cabecera se crea draft/revisión1; actor, procedencia, fecha SQL y auditoría created se generan dentro de la transacción. El campo prepared_at del **contenido hipotético** sigue siendo fecha declarada del fixture, distinta de created_at/recorded_at SQL del servidor. No se presenta aquel timestamp como autenticación contemporánea de un acto real.

Al recuperar se verifica:

- Contrato sintético válido, identidad/procedencia/actor y canonicalización exacta.
- Hash de cabecera igual al JSON canónico, familia/versión iguales a los campos relacionales.
- Cadena de auditoría completa, ordenada y sin saltos; actor, evento y número esperado correctos.
- before de cada revisión igual al after anterior, bytes y hash.
- Identidad/procedencia/autor/fecha de preparación invariantes en todas las revisiones.
- Última auditoría igual al contenido/hash/revisión actual; creación ligada al UUID documental.

La lectura no reconstruye grupos desde cuentas actuales ni produce estados financieros. Bloquea únicamente su propio documento y auditorías para no combinar revisiones distintas.

## 7. Revisión optimista y transacciones

El repositorio es dueño de una sola transacción PDO exterior; rechaza una transacción externa. Orden: documento → auditorías del documento en orden de revisión. No adquiere locks de catálogo, banco, inventario, períodos, políticas o GET_LOCK administrativo.

Creación concurrente se arbitra con INSERT y UNIQUE, evitando SELECT de ausencia que introduciría gap locks innecesarios. Un1062 de identidad se relee con FOR UPDATE; no dispara un reintento de escritura.

Revisión: bloquear/releer cabecera, verificar cadena actual, comprobar request_uuid previo, estado SQL draft y **revisión+hash esperados**, reutilizar la revisión de B1 para impedir cambio de identidad, actualizar contenido/revisión e insertar auditoría. No se recibe estado SQL del llamador como autoridad.

Se hace un único commit. Cualquier Throwable provoca rollback de cabecera y auditoría. No se introdujeron retries automáticos, efectos financieros, correcciones contables o commits parciales.

## 8. Idempotencia

| Solicitud | Resultado |
| --- | --- |
| Mismo document_uuid, contenido de creación y motivo | Recupera el mismo documento, sin otra creación/auditoría. Compara contra la auditoría original, aunque el borrador tenga revisiones posteriores. |
| Mismo document_uuid y contenido/motivo distintos | identity.content_conflict; sin efectos. |
| Otro UUID con misma familia/versión | identity.family_version_conflict. |
| Mismo request_uuid de revisión y mismo contenido/precondiciones/motivo | Devuelve el documento actual con replay y número de revisión reconocida; no repite el cambio. |
| Mismo request_uuid con contenido, documento o precondiciones distintos | request.content_conflict. |
| Nueva solicitud con revisión/hash obsoletos o estado no draft | revision.stale_or_frozen. |

El replay devuelve la versión actual del borrador con referencia a la revisión reconocida, **no** afirma que el documento mutable siga idéntico a aquella respuesta antigua. Todas las versiones anteriores permanecen en auditoría. No existe idempotencia financiera: no hay escritor financiero en esta fase.

## 9. Pruebas secuenciales, estados y rollback

`SyntheticPresentationDraftPersistenceTest`:17 pruebas,59 aserciones definitivas. Crea exclusivamente UUID/familias nuevos en las tablas test_ de esta fase; no utiliza modelos financieros, seeders, TestCase Laravel o datos AC-04 como fixtures.

Cobertura: creación/recuperación exacta, timestamps SQL, cadena/hash, falta de autoridad, duplicado idéntico/divergente, unicidad familia/versión, dos lectores con conexiones distintas, actualización obsoleta y replay, autor/purpose/hash manipulado, estructura inválida, cambio de identidad, FK/CHECK/RESTRICT, ausencia de huérfanos y rechazo de retirar un esquema con evidencia.

Fallos inyectados: después de cabecera, después de actualizar, después de auditoría y antes de commit. Error/Throwable revierte todo; se comprueban recuentos y documento anterior. No se alteran expectativas para aceptar efectos parciales.

Para no-draft se utilizó **un borrador nuevo exclusivo de fixture**, marcado temporalmente frozen_simulation por el test, comprobado el rechazo y devuelto a draft en finally. No se publicó, congeló o reabrió una clasificación histórica ni una política empresarial. Esa manipulación controlada no es un método operativo del repositorio y no se presenta como auditoría de una publicación real. La retirada de clasificaciones no se implementó.

## 10. Carreras MySQL definitivas

Dos procesos PHP con PDO independientes, barreras stdin/stdout y UUID nuevos. El primer proceso conserva su lock; el observador verifica en SHOW FULL PROCESSLIST la consulta conflictiva del segundo antes de liberar el primero. La consulta pertenece al mismo usuario restringido: no se añadió PROCESS. Se verifica persistencia después de terminar ambos procesos, no únicamente sus respuestas.

| Carrera | Conexiones A/B | Documento | Resultado persistido |
| --- | --- | ---: | --- |
| Dos revisiones divergentes | 296/298 | 41 | Revisión2, dos auditorías; segundo rechazado por obsoleto. |
| Creación idéntica | 302/304 | 42 | Un documento/revisión1/una auditoría; segundo replay. |
| Misma identidad, contenido distinto | 308/310 | 44 | Primer contenido conservado; segundo identity.content_conflict. |
| Misma familia/versión, UUID distinto | 314/316 | 46 | Un documento; segundo identity.family_version_conflict. |
| Repetición concurrente de revisión | 320/322 | 48 | Revisión2 y dos auditorías; segundo replay, sin duplicado. |
| Rollback del primer creador | 326/328 | 50 | A revierte tras auditoría; B crea documento completo, una auditoría, sin huérfanos. |

**6 pruebas,193 aserciones**, todas aprobadas. El arnés registra distinct connection IDs, consulta concurrente observada, hashes/revisiones, outcomes y procesos cerrados. La inspección de procesos posterior no encontró padres/workers de la fase activos.

Archivos privados definitivos `storage/app/private/`:

```text
ac05b3b2-race-result-c8dbff44dac7f231.json
ac05b3b2-race-result-69869181f392ef51.json
ac05b3b2-race-result-0ea317ae42199a6f.json
ac05b3b2-race-result-ce8d2ae51c0d8063.json
ac05b3b2-race-result-07226a8be6fc1538.json
ac05b3b2-race-result-f310469a9b3d1643.json
```

### Incidencias del arnés y evidencia adicional conservada

Las primeras seis carreras fallaron porque el observador esperaba Command=Query; PDO nativo usa Execute. Un ensayo diagnóstico nuevo confirmó la consulta y se corrigió la identificación, sin cambiar reglas del repositorio o expectativas documentales.

El siguiente intento detectó que pipes proc_open de Windows no respetaban la lectura no bloqueante esperada: el padre retrasaba RELEASE y el competidor terminaba por timeout. No se consideró prueba de éxito. Se retiró esa lectura incompatible y se conservó la observación de la consulta conflictiva más el estado del proceso. Se inspeccionaron los procesos; Stop-Process falló en el entorno y, al comprobar nuevamente para taskkill, el padre ya había terminado. No se afirma que esa detención fallida los cerrara: la comprobación final verificó su ausencia.

Un caso aislado con fixture nuevo aprobó antes de ejecutar las seis carreras definitivas. Quedó una séptima evidencia positiva adicional; **no se suma** a los resultados definitivos. Se conservan los diagnósticos y cualquier borrador confirmado durante iteraciones fallidas: no se borraron filas ni se reutilizaron sus identidades para repetir. La verificación final revisó también esas cadenas, no solo las seis elegidas.

## 11. Pruebas puras, regresiones y comprobaciones

| Grupo definitivo | Pruebas | Aserciones |
| --- | ---: | ---: |
| Persistencia SQL nueva | 17 | 59 |
| Carreras SQL nuevas | 6 | 193 |
| B1 puro + clasificador previo + frontera CLI nueva | 98 | 333 |
| **Total** | **121** | **585** |

Las98 puras contienen las97/331 de B1 y su regresión más una prueba/2 aserciones de frontera. Se ejecutaron con PDO/mysqli deshabilitados, vendor/autoload.php y sin phpunit.xml. Las pruebas SQL utilizan `ac05b3b2-bootstrap.php`, que fija contrato/identidad y comprueba91 tablas/82 migraciones; no inicia Laravel ni ejecuta migraciones.

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap tests/Support/ac05b3b2-bootstrap.php --do-not-cache-result --no-progress tests/Feature/SyntheticPresentationDraftPersistenceTest.php
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap tests/Support/ac05b3b2-bootstrap.php --do-not-cache-result --no-progress tests/Feature/SyntheticPresentationDraftConcurrencyTest.php
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d disable_classes=PDO,mysqli -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result --no-progress tests/Unit/FinancialPresentationContractTest.php tests/Unit/SyntheticPresentationDraftBoundaryTest.php tests/Feature/AccountingPresentationTest.php
```

No se suman ejecuciones intermedias. La persistencia se repitió tras el ajuste final de verificación de identidad de auditorías; las carreras definitivas usan ese mismo servicio. Lint sin errores de14 PHP nuevos/modificados; diff check aprobado. No se ejecutaron suites generales, Composer test, RefreshDatabase, migrate:fresh, db:wipe, seeders o carreras AC-04 antiguas.

No se repitieron regresiones financieras con escritores: el repositorio no los invoca y la comparación de todas sus tablas originales es exacta. Las protecciones B1 se reutilizan sin cambios. No se certifica mediante estas pruebas la concurrencia de reportes emitidos o de publicaciones empresariales inexistentes.

## 12. Estado inicial/final de las tres bases

La comparación final aprobó **OTHER_DATABASES_EXACT=true** y **ALL_ORIGINAL_ROWS_AND_DDL_EXACT=true**.

| Base | Tablas antes/después | Filas antes/después | Migraciones antes/después |
| --- | ---: | ---: | ---: |
| salon_erp | 78/78 | 225/225 | 74/74 |
| salon_erp_test | 89/91 | 1.873/1.963 | 82/82 |
| salon_erp_bootstrap_check | 78/78 | 202/202 | 74/74 |

Los82 registros de migrations y las89 tablas iniciales conservan filas y DDL completos, incluidos AUTO_INCREMENT, sin normalizarlos para ocultar cambios. No se modificaron usuarios, cuentas, asientos, períodos, políticas/autorizaciones, saldos, bancos, inventario, nómina, extractos o preparado131. Los huecos de secuencia se limitan a las tablas nuevas por rollbacks/duplicados; no se restauraron contadores.

Evidencia final: `storage/app/private/ac05b3b2-isolation-after.json`. No hubo DDL/DML sobre desarrollo/bootstrap. El auxiliar administrativo solo los leyó para comparar; la identidad restringida no puede acceder a ellos.

## 13. Inventario exacto de altas sintéticas

Solo hay **42 cabeceras y48 auditorías nuevas**, todas en las dos tablas test_. Incluyen fixtures secuenciales y las iteraciones de arnés conservadas, no42 operaciones empresariales. La suma90 explica exactamente el aumento de filas de test.

IDs de cabecera:

```text
1,2,5,7,8,10,11,12,13,14,15,16,17,19,21,24,26,28,29,30,32,34,36,39,41,42,44,46,48,50,51,52,55,57,58,60,61,62,63,64,65,66
```

IDs de auditoría:

```text
1,2,3,4,5,6,7,8,10,12,13,14,16,17,18,19,21,22,23,24,25,26,27,28,29,30,32,33,34,35,36,37,38,39,41,42,43,44,45,46,47,48,49,51,53,54,55,57
```

Todas las cabeceras quedaron draft; seis tienen revisión2 y las demás revisión1. `ac05b3b2-final.php` recuperó cada una a través del repositorio: todas las cadenas, hashes y referencias son íntegras, sin auditorías huérfanas. El manifiesto conserva UUID, familia, versión, actor, hashes, solicitudes, revisión y timestamps exactos de cada alta:

- Archivo privado: `storage/app/private/ac05b3b2-final-manifest.json`.
- SHA256: `1c4c04578b0d3b8af6f606f5792214f8c9a2ba9c1a879c2fc49807f05ecd9eb0`.

Manifiestos, baselines, diagnósticos y respaldo permanecen ignorados por Git; no se copian datos privados a una aprobación o configuración empresarial.

## 14. Archivos propios de B2 y compatibilidad

```text
app/Services/SyntheticFinancialPresentationDraftRepository.php
tests/Support/SyntheticPresentationHarness.php
tests/Support/SyntheticPresentationDraftSchema.php
tests/Support/ac05b3b2-preflight.php
tests/Support/ac05b3b2-install.php
tests/Support/ac05b3b2-isolation.php
tests/Support/ac05b3b2-bootstrap.php
tests/Support/ac05b3b2-worker.php
tests/Support/ac05b3b2-inspect.php
tests/Support/ac05b3b2-final.php
tests/Feature/SyntheticPresentationDraftPersistenceTest.php
tests/Feature/SyntheticPresentationDraftConcurrencyTest.php
tests/Unit/SyntheticPresentationDraftBoundaryTest.php
docs/AC_05B3B2_PERSISTENCIA_SINTETICA.md
tests/Support/ac04b1-backup.php
```

Los primeros14 son nuevos; el último contiene únicamente la adición de fase AC05B3B2 a dos listas del respaldo. No se modificaron los cinco archivos B1, informe G1, clasificador/extractor/comparador, controladores, reportes, cierre, bancos, rutas, configuración, AGENTS.md o dependencias. No hay migración nueva.

**Compatibilidad de auxiliares:** algunos preflights antiguos de reportes esperan exactamente89 tablas y ahora rechazarán91 de forma conservadora. No se relajaron ni se ejecutaron para ocultar esa diferencia. El arnés B2 exige explícitamente las dos tablas nuevas. Una futura integración de esos preflights debe reconocer este esquema autorizado de forma específica, no eliminar sus controles. Esto no cambia los lectores o reportes operativos.

El árbol conserva los seis pendientes previos más los cambios B2, sin staging/commit/push. Revisar íntegramente los nuevos archivos, porque git diff ordinario solo muestra el auxiliar rastreado. No se mezcló el informe G1 en un commit automático.

## 15. Riesgos y límites

- Esto demuestra borradores sintéticos, no gobierno contable empresarial, aprobación, publicación o edición emitida.
- El actor del arnés es un usuario técnico seleccionado en servidor; no se atribuye una firma o intervención económica a esa persona.
- Los IDs de cuenta son hipótesis; no hay FK al catálogo o garantía de cobertura real. Las decisiones económicas siguen pendientes.
- No se permite promover procedencia; una futura vía empresarial deberá tener contrato/creación/aprobación independientes.
- Los hashes comprueban integridad de contenido, no autenticidad contra modificación coordinada por un administrador SQL. El servicio detecta incoherencias y no ofrece borrado de auditorías, pero los privilegios SQL heredados no son append-only.
- La historia completa se materializa al leer un borrador; no se certificó rendimiento de familias enormes o un archivo documental empresarial.
- No se ensayó publicación, retirada persistente, acceso HTTP o concurrencia de DDL con escritores. El mantenimiento de esquema exige detener el arnés.
- Los fallos del arnés se conservaron y documentaron; solo los resultados definitivos sustentan las conclusiones.

## 16. Reversión segura y cierre

No ejecutar una retirada ahora: las tablas contienen evidencia y `removeEmpty` debe rechazarla. Conservar respaldo, manifiestos y todas las filas. No borrar borradores/auditorías para permitir DROP ni restaurar una base antigua. Cualquier eliminación con evidencia requiere una autorización posterior y un plan de archivo/preservación propio.

Si se autoriza retirar únicamente código, revisar los14 archivos nuevos y las dos adiciones de fase del auxiliar, conservando B1/G1 y evidencia privada. Quitar código no autoriza tocar SQL. Artisan rollback no debe usarse para estas tablas fuera de migraciones. No hay asientos o clasificaciones empresariales que revertir.

La reinstalación futura no debe repetir ciegamente este instalador sobre91 tablas: fallará por diseño. Los helpers de evidencia usan creación exclusiva y no sobrescriben baselines/manifiestos. Para nuevos ensayos se requieren UUID y evidencias nuevas, nunca limpieza de las existentes.

AC-05B3B2 termina aquí con persistencia sintética y concurrencia verificadas. No se inicia publicación empresarial, emisión, sustitución de reportes, AC-06, H01-B2B, activación económica o despliegue.
