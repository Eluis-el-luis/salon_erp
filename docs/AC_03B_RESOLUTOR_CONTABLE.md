# AC-03B — Resolutor versionado sin activación financiera

Fecha: 2026-11-16. Alcance completado exclusivamente en `salon_erp_test`.

## 1. Resultado

Se implementó `AccountingAccountResolver` para consultas técnicas internas de servidor. No se conecta a rutas, formularios, ContabilidadService ni servicios operativos. No sustituye códigos, genera asientos, modifica períodos, convierte documentos o habilita una política empresarial.

Las tres bases conservan sus versiones: test 80 migraciones, desarrollo y bootstrap 74. No se creó ni ejecutó una migración en esta fase. No se activó V2 en desarrollo.

## 2. Contrato público

```php
inspect(
    array $requests,
    DateTimeImmutable $at,
    ?int $technicalVersionId = null
): array

resolveForPosting(): never
```

Cada petición técnica contiene exclusivamente `role`, `scope_type` y `resource_id` explícito (NULL para global). Los papeles y ámbitos deben estar registrados en código. Se admite un máximo de cien resoluciones por llamada. El momento se normaliza a UTC.

Los recursos y la versión explícita son parámetros internos para inspección; un futuro controlador no debe tratarlos como autoridad empresarial por proceder de un request. Actualmente no existe endpoint que los acepte ni escritor financiero que consuma el resultado. El servidor vuelve a comprobar existencia, clasificación y fotografías de los recursos, no confía en una cuenta proporcionada por el navegador.

El resultado incluye:

- `contract_version=1`, `mode=technical_simulation`, `posting_authorized=false`.
- Una única `policy_version_id`, su hash, instante consultado, vigencia y auditoría de publicación.
- Por resolución: cuenta, papel, ámbito, recurso, clave de ámbito, ID de asociación, versión, hash y fotografías de cuenta/reglas.

No se devuelve un modelo Eloquent mutable de cuenta o política. La evidencia es un resultado de consulta; no es un permiso ni un token de contabilización. `resolveForPosting()` rechaza incondicionalmente la operación, antes de consultar o escribir SQL. Un desarrollador podría copiar manualmente una cuenta devuelta hacia código ajeno; esta API no constituye una barrera frente a código de aplicación arbitrario. La garantía actual es que no existe esa integración y que el camino de contabilización ofrecido permanece cerrado.

Los roles autorizados para inspección son los mismos de AC-03A: `alvaro` y `contador`, con usuario activo releído bajo bloqueo compartido. No hay nuevo sistema de permisos ni credenciales persistidas.

## 3. Selección y validación

La selección se realiza una sola vez por llamada, no por cada línea. Utiliza lectura actual bajo bloqueo compartido y exige exactamente una versión enterprise publicada cuya vigencia UTC satisfaga `desde <= instante < hasta`. Si se indica una versión explícita, debe coincidir con esa única versión elegible; no permite saltarse la vigencia ni la detección de solapamientos.

Se rechazan ninguna/dos versiones elegibles, borradores, demo, referencias inexistentes o fuera de intervalo, cabecera incompleta, papeles ausentes y contextos incompatibles. Una demo no se publica como enterprise mediante el servicio AC-03A. La versión sintética de 2099 sí puede leerse técnicamente, pero siempre devuelve `posting_authorized=false`.

Se comprueban conjuntamente:

1. Hash canónico completo de contenido y metadatos de publicación.
2. Última auditoría con evento publicado, actor aprobador, referencia y fotografía posterior exactos.
3. Cobertura completa de la familia inicial AC-03A y correspondencia exacta entre manifiesto y asociaciones.
4. Ausencia de duplicados y de mezcla global/específica para un mismo papel.
5. Reglas fotografiadas iguales a las reglas de código vigentes.
6. Cuentas existentes, activas, imputables y de tipo/naturaleza compatibles.
7. Fotografías actuales de cuenta y recurso iguales a las publicadas.

La solicitud exige una asociación **exacta** de papel/ámbito. No hay precedencia, búsqueda por nombre, prefijo, primera cuenta o fallback global. La contrapartida de apertura sigue reservada y bloqueada según AC-03A/H01-B2B.

Las comprobaciones de cobertura, reglas y ambigüedad se probaron incluso simulando corrupción SQL de fixtures con hashes recalculados. Un hash coincidente por sí solo no basta. No se afirma protección ante un administrador capaz de alterar de forma coordinada todos los datos y la auditoría.

## 4. Trazabilidad histórica: sin ALTER en esta fase

No se añadió aún `asientos_contables.accounting_policy_version_id`: ningún asiento está autorizado a usar el resolutor y no hay un escritor que pueda garantizar la correspondencia del nuevo enlace. Se evitó añadir una columna que pudiera interpretarse como activación parcial. Todos los asientos y detalles conservan exactamente su contenido anterior; no hubo backfill.

La evidencia devuelta ya identifica versión, hash y asociación de forma verificable. Antes de AC-04/AC-06 se propone:

- Referencia nullable desde el asiento a la versión, con FK RESTRICT y sin backfill.
- Evidencia estructurada por línea que conserve papel, ámbito/recurso, asociación, hash y referencias documentales/movimientos originales.
- Si una línea agrupa varios papeles o artículos, conservar todas sus correspondencias e importes originales; no asignarle un papel único ficticio. Un JSON versionado nullable podría expresarlo, pero no impone FK por elemento. Si se exige integridad relacional y consulta por asociación, evaluar una tabla de enlaces por línea en esa fase.
- Validar que todas las líneas de un evento comparten la versión fijada y que sus cuentas coinciden con la evidencia, dentro de la transacción del documento.

Son propuestas de integración futura, no cambios instalados ni autorizaciones para escribirlas. La reversa debe conservar cuentas, importes y referencias originales, incluyendo su versión si existiera; nunca consultar la política nueva para decidir una cuenta histórica. H04-B continúa usando su mecanismo actual.

## 5. Fijación, transacciones y orden de bloqueos

`inspect()` abre una transacción Laravel. Si el llamador ya tiene una transacción, los bloqueos permanecen hasta que termine la exterior; no se introduce un commit de esa transacción. Sin transacción exterior, los bloqueos terminan al devolver el resultado. **Conservar el array después del retorno no mantiene un bloqueo vivo.**

Orden de consulta: identidad autenticada; versiones elegibles en orden de ID; asociaciones de la versión en orden estable; auditoría; recursos por tipo e ID; cuentas por ID. Las lecturas son compartidas y actuales. Se reutiliza `AccountIntegrityService::account()` y la misma fotografía de cuentas/recursos usada al publicar. No se adquiere GET_LOCK ni se bloquean documentos, inventario agregado, lotes o kárdex para realizar una consulta técnica.

AC-03A conserva su mutex administrativo y sus bloqueos exclusivos de publicación. La consulta compartida impide que la versión seleccionada cambie mientras permanece fijada en su transacción. Una publicación concurrente puede esperar por la lectura de versiones publicadas que realiza AC-03A. No se añadió el mutex administrativo a ventas ni a otros flujos financieros.

La carrera real utilizó conexiones MySQL independientes: un lector resolvió los seis papeles, mantuvo abierta su transacción, otro proceso intentó publicar y quedó pendiente; el lector repitió la consulta con idéntica evidencia, terminó y la publicación pudo confirmarse. Se verificaron IDs de conexión distintos y estados/auditoría persistidos. Esto prueba fijación técnica durante esa transacción, no una operación financiera habilitada.

### Protocolo futuro, pendiente de integración autorizada

Antes de contabilizar se deberá resolver una vez el conjunto completo de papeles y fijar la versión dentro de la transacción que gobierne el documento. Los bloqueos de documentos/agenda, usuarios, artículos, lotes, kárdex y cuentas de H16/H02-A/H03-B2 deben conservar su orden; no se debe insertar una consulta técnica previa a dichos recursos y conservar sus locks mientras se adquieren recursos en orden inverso.

AC-03B no conecta `inspect()` a esos caminos ni declara validado un orden financiero nuevo. AC-04 deberá revisar cada caso concreto, adquirir primero los recursos del protocolo existente, hacer las lecturas actuales necesarias y mantener la fijación hasta el commit. No se permitirán versiones distintas dentro del mismo evento ni volver a seleccionar para cada asiento. La validación de período abierto y partida doble seguirá siendo responsabilidad del servicio contable al contabilizar; una simulación sin fecha/documento contable no abre ni valida períodos operativos.

## 6. Documentos retenidos: decisión empresarial pendiente

| Alternativa | Consecuencia | Control necesario |
| --- | --- | --- |
| Fijar al crear/retener | Conserva interpretación inicial, aunque cambie la política antes de aprobar. | Evidencia persistente al crear; decidir si una versión ya no vigente permite aprobación posterior y cómo se autoriza. |
| Fijar al aprobar | Usa la configuración del momento en que se aplican efectos. | Revalidación y nueva revisión del documento si cambian las cuentas; no sustituir silenciosamente una propuesta previa. |

No se eligió una alternativa. No se migraron retenidas ni se alteró AprobacionService. La aprobación empresarial de configuración y la habilitación por evento siguen pendientes de un contrato separado; `kind=enterprise` y `status=published` no las sustituyen.

## 7. Pruebas, preflight y resultados

PHP: `E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe`.

Se amplió el runner focalizado existente con modos `preflight-current`, `resolver` y `resolver-race`. Los grupos mantienen `phpunit.xml`, bootstrap y guardias SQL existentes, sin Composer test, suite general, RefreshDatabase ni paralelismo indiscriminado. La carrera utiliza dos procesos controlados porque necesita conexiones reales distintas.

Antes de ejecutar: identidad `salon_erp_tester@localhost`, base exacta `salon_erp_test`, puerto 3306, MySQL 8.4.3, roles NONE, privilegios exactos restringidos, acceso denegado a las otras bases y 80 migraciones exactas. No hubo DDL.

Respaldo comprobado:

`E:/salon-erp-backups/salon_erp_test_20261112_074028_3813668.sql`

SHA256: `8778320250e84322267a7d99bf86e18f70c099d0117ea5b73dad67c2c32aa531`.

Comandos principales, anteponiendo el PHP indicado y `-d zend.exception_ignore_args=1`:

```text
tests/Support/run-ac03a-tests.php preflight-current
tests/Support/isolation-snapshot.php before AC03B
tests/Support/run-ac03a-tests.php resolver
tests/Support/run-ac03a-tests.php resolver-race
tests/Support/run-ac03a-tests.php core
tests/Support/run-ac03a-tests.php ac02
tests/Support/isolation-snapshot.php after AC03B
```

| Grupo final | Pruebas aprobadas | Aserciones |
| --- | ---: | ---: |
| Resolutor | 26 | 64 |
| Concurrencia resolución/publicación | 1 | 16 |
| AC-03A contrato/publicación | 18 | 83 |
| AC-02 integridad y concurrencia de jerarquía | 15 | 101 |
| **Total de casos distintos** | **60** | **264** |

Los casos del resolutor incluyen vigencia semiabierta y conversión UTC, cobertura global/específica, contexto manipulado, draft/demo, ausencia/duplicidad de versión, cuenta inactiva/no imputable, cambios de fotografía y reglas, integridad de auditoría/hash, fijación por lote y rechazo de contabilización. Una barrera SQL durante `inspect()` permite únicamente SELECT/SHOW y rechaza GET_LOCK/RELEASE_LOCK. Otra barrera limita los fixtures a las tablas de políticas y metadatos de cuenta/usuario bajo transacción; no permite escrituras físicas/financieras o DDL.

Las pruebas de corrupción son exclusivamente fixtures transaccionales, no vías de administración de versiones publicadas. Todos esos cambios se revierten. La carrera conserva una publicación sintética adicional, seis asociaciones y tres auditorías (diez filas). Su vigencia de ensayo es enero de 2088, sin habilitación operativa. No repetir ciegamente la carrera: rechaza una corrida previa para no reutilizar ni borrar evidencia.

El grupo final del resolutor se repitió tras añadir la validación de evidencia incompleta. La carrera y las regresiones ya habían pasado; esa validación adicional no cambia el protocolo de bloqueos. No se repitieron instalación/rollback de AC-03A ni suites completas H03/H04. No hubo cambios frontend que requirieran Vite.

## 8. Integridad de las bases y compatibilidad

Línea base nueva: `storage/app/private/isolation-AC03B-before.json`. Se comparó contenido y estructura, preservando además cada fila de evidencia previa de AC-03A.

| Base | Antes: tablas / filas / migraciones | Después | Resultado |
| --- | --- | --- | --- |
| `salon_erp` | 78 / 225 / 74 | 78 / 225 / 74 | DDL y contenido exactos sin cambios |
| `salon_erp_test` | 84 / 564 / 80 | 84 / 574 / 80 | Historia y estructura conservadas; diez filas sintéticas adicionales |
| `salon_erp_bootstrap_check` | 78 / 202 / 74 | 78 / 202 / 74 | DDL y contenido exactos sin cambios |

En test solo se exceptúa el avance de AUTO_INCREMENT de fixtures revertidos y la adición de evidencia sintética a las tres tablas de políticas. Las filas anteriores de esas tablas, todas las migraciones y los datos de negocio coinciden con la línea base.

`SALON_TEST_DB_RESET_ALLOWED=0` se mantuvo y comprobó en `finally`. No se cambiaron permisos, usuarios SQL, credenciales, configuración ordinaria, APP_KEY ni AGENTS.md.

No se modificaron ContabilidadService, AnulacionVentaService, CompraService, InventarioService, AgendaService, FondosService, NominaService, rutas, cierres, reportes o migraciones. Por ello H03-B2/H04-B conservan sus contratos y resolución actual; esta es una comprobación estática y estructural, apoyada por las regresiones seleccionadas, no una nueva ejecución completa de sus suites.

## 9. Archivos y diff específico

Nuevos:

- `app/Services/AccountingAccountResolver.php`.
- `tests/Feature/AccountingAccountResolverTest.php`.
- `tests/Feature/AccountingResolverConcurrencyTest.php`.
- `tests/Support/ac03b-resolution-worker.php`.
- Este informe.

Modificados respecto al cierre AC-03A:

- `app/Services/AccountingPolicyService.php`: lectura de contenido compartida y reutilización de la fotografía de cuenta/recurso. La publicación conserva sus locks y su hash original.
- `tests/Support/run-ac03a-tests.php`: tres modos focalizados; no amplía destinos ni altera los guardias de PHPUnit.
- `tests/Support/isolation-snapshot.php`: fase AC03B y conservación de huellas de evidencia anterior.

Diff incremental de los siete archivos de código/pruebas: `storage/app/private/ac03b-review.diff`, 39.503 bytes. SHA256: `6f721e35ae3b22734b0db213864de7cc8ed3164fbfc26a5f39a6fdca87e525d1`.

Se construyó contra el patch verificado del cierre AC-03A, porque esos archivos todavía estaban sin commit al comenzar. No incorpora como cambios propios los archivos anteriores de AC-03A ni el Excel ajeno a la tarea. Se comprobó `git apply --reverse --check` sin aplicar la reversión. El informe queda fuera del patch. Lint de los siete archivos PHP y `git diff --check` aprobados.

## 10. Riesgos para AC-04/AC-06 y reversión

Antes de activar se requiere aprobación explícita sobre política empresarial, evento habilitado, documentos retenidos, fijación/persistencia por asiento y línea y cobertura de cada módulo. También deben comprobarse los saldos de inventario: cambiar una asociación prospectivamente no autoriza descargar una cuenta distinta de la que conserva el valor anterior. Cualquier tratamiento de ese caso necesita diseño coordinado y autorización; esta fase no reclasifica saldos.

Las reversas futuras deben leer el documento y sus líneas originales. Nunca usar este resolutor para recalcular cuentas de una anulación histórica, ni alterar importes originales de kárdex/COGS. Los controles de períodos, partida doble, pagos, nómina y valoración no se sustituyen por un hash de política.

La evidencia y la inmutabilidad de políticas siguen teniendo los límites AC-03A frente a SQL privilegiado directo. No se añadieron triggers ni cambios de privilegios. Una inspección fuera de una transacción prolongada es una fotografía técnica, no una garantía posterior de imputación.

Reversión de código: revisar y aplicar inversamente solo el patch incremental AC-03B si se decide retirarlo; preservar los cambios previos AC-03A y el trabajo ajeno. No hay migración que revertir. Conservar la evidencia sintética durable; no borrar versiones o auditorías, restaurar dumps o forzar el rollback AC-03A para limpiar el ensayo.

No hubo commits ni despliegues. AC-03B termina aquí, sin iniciar AC-04, AC-05, AC-06 o H01-B2B.
