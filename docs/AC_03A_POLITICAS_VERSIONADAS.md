# AC-03A — Esquema y protección de políticas contables versionadas

Fecha: 2026-11-16. Alcance completado exclusivamente en `salon_erp_test`.

## 1. Resultado y límites

Se instaló una migración aditiva con tres tablas y se implementó un servicio de administración documental. No se sustituyó la resolución actual de cuentas, no se crearon asientos con políticas y no se añadieron rutas ni pantallas operativas. Una versión publicada en estas tablas no activa el motor financiero.

Desarrollo y bootstrap conservan sus 74 migraciones. Test pasó de 79 a 80. No se modificaron migraciones anteriores, documentos históricos, cuentas reales, credenciales, APP_KEY ni AGENTS.md. No se importó el archivo Excel preexistente y no se realizaron commits.

## 2. Persistencia

Migración: `database/migrations/2026_11_16_000001_create_versioned_accounting_policies.php`.

| Tabla | Contenido y restricciones principales |
| --- | --- |
| `accounting_policy_versions` | Número único, contrato 1, tipo explícito demo/enterprise, borrador/publicada, creador, aprobador, referencia, motivo, vigencia, hash SHA256 y manifiesto de cobertura. |
| `accounting_account_bindings` | Versión, papel semántico, ámbito global/artículo/cuenta bancaria, cuenta del catálogo único, fotografías estructurales de reglas y cuenta. |
| `accounting_policy_audits` | Versión, secuencia única, evento, actor autenticado, motivo, hashes anterior/posterior, contenido anterior/posterior versionado y fecha del servidor. |

Las ocho FK utilizan RESTRICT tanto para eliminación como para actualización. Las cuentas se referencian mediante su ID real en `cuentas_contables`; no existe un segundo catálogo. Los recursos específicos tienen FK a `items` o `cuentas_bancarias`.

`scope_key` es una columna generada y almacenada: `global`, `item:<id>` o `bank_account:<id>`. El UNIQUE `(policy_version_id, semantic_role, scope_key)` evita duplicar un papel/ámbito, incluso cuando el ámbito es global y las referencias específicas son NULL.

Los CHECK efectivos de MySQL comprueban estados, versión de contrato, forma de hash y papel, exclusión de recursos incompatibles, objetos JSON versionados, eventos de auditoría y requisitos mínimos de publicación. Una publicación necesita tipo enterprise, aprobador, referencia no vacía y vigencia completa ordenada. Un borrador no admite metadatos que finjan una publicación.

No se añadió referencia a políticas en `asientos_contables`: no hay aún un consumidor que deba persistirla. AC-03B deberá evaluar un enlace nullable y la fijación de versión dentro de cada operación, sin backfill de asientos anteriores. Esta fase no garantiza todavía ese futuro protocolo de contabilización.

### Instalación y reversión

La migración requiere MySQL 8.0.16 o superior, modo estricto, padres InnoDB, esquema previo completo, ausencia de las tres tablas y DDL fuera de transacción. Se verificó con MySQL 8.4.3.

MySQL no ofrece rollback transaccional del conjunto de DDL utilizado. Una instalación parcial se rechaza; no se borra ni se reintenta automáticamente para ocultarla.

`down()` comprueba primero que las tres tablas existen y están vacías. Cualquier versión, asociación o auditoría bloquea la reversión antes del primer DROP. No borra evidencia para permitir el rollback.

## 3. Contrato de versiones, cuentas y cobertura

`AccountingPolicyService` ofrece únicamente `createDraft`, `reviseDraft`, `replaceBindings` y `publish`. Todos los cambios exigen identidad autenticada, transacción y auditoría.

La publicación exige:

- Tipo enterprise explícito: una demostración no puede promoverse ni cambiarse silenciosamente a política empresarial.
- Hash esperado y recomputación del contenido almacenado, para rechazar ediciones obsoletas o contenido incompatible.
- Manifiesto explícito completo para la familia inicial de ventas/inventario; las asociaciones deben coincidir exactamente con ese manifiesto.
- Cuentas existentes, activas, imputables y compatibles con las restricciones estructurales del papel.
- Recursos existentes y válidos. Un artículo archivado o de tipo servicio no es un recurso físico válido.
- Fotografías de cuenta y recurso aún vigentes. Un cambio desde la preparación obliga a revisar y volver a asociar explícitamente.
- Vigencia futura finita y explícita, interpretada en UTC mediante intervalo semiabierto `[desde, hasta)`.
- Ausencia de solapamiento con otra versión publicada. Se permite que una vigencia empiece exactamente al terminar otra.
- Referencia y motivo de publicación, aprobador autenticado y auditoría persistida en la misma transacción.

No se acortan ni se modifican automáticamente versiones publicadas. La vigencia finita es una limitación explícita de este contrato inicial; no constituye una fecha de implantación elegida para la empresa.

El hash canónico incluye número, tipo, cabecera, creador, estado, metadatos de aprobación, vigencia, manifiesto y fotografías de asociaciones. Las claves y asociaciones se ordenan determinísticamente. El hash publicado cambia al incorporar su aprobación. La auditoría conserva el contenido anterior y posterior y sus hashes; no solo un mensaje de log.

### Papeles admitidos

| Papel | Restricción estructural | Ámbitos |
| --- | --- | --- |
| `inventario.reventa` | Activo, deudora | Global o artículo compatible |
| `inventario.insumos` | Activo, deudora | Global o artículo compatible |
| `costo.producto` | Costo, deudora | Global o artículo |
| `costo.servicio` | Costo, deudora | Global |
| `ingreso.producto` | Ingreso, acreedora | Global o artículo |
| `ingreso.servicio` | Ingreso, acreedora | Global |
| `fondos.banco` | Activo, deudora | Cuenta bancaria |
| `apertura.inventario.contrapartida` | Reservado sin decidir clasificación patrimonial | Global; publicación bloqueada |

Los seis primeros papeles forman la cobertura mínima de esta familia. No se afirma cobertura completa de nómina, impuestos, cierres u otros eventos. La selección de cuentas no está codificada en el registro de papeles. Los códigos presentes en fixtures son hipótesis de prueba y no configuración empresarial.

No se admiten ámbitos global y específicos simultáneos para el mismo papel: no se inventó una precedencia entre ellos. Una cobertura específica expresa solo los recursos declarados, no todos los artículos del salón.

La clasificación inventario reventa/insumos reutiliza el criterio vigente: reventa no fraccionable frente a insumos y fraccionables. No se modifica H03-B2 ni la valoración. Las restricciones tipo/naturaleza no prohíben signos en debe/haber ni implementan fórmulas de asientos.

Las cuentas podrán cambiarse por configuración para estos papeles en futuras versiones. Introducir una semántica operativa nueva seguirá requiriendo código de dominio; no se aceptan fórmulas arbitrarias o código ejecutable.

## 4. Permisos e inmutabilidad

Se reutilizan los roles contables existentes `alvaro` y `contador`, comprobados contra el usuario activo mediante una lectura actual bajo bloqueo compartido. No se introdujeron roles paralelos ni se asumió que el bypass HTTP de admin concede automáticamente esta función interna.

Preparación y publicación son operaciones distintas y dejan eventos distintos. El creador y el aprobador se resuelven desde autenticación; no se aceptan sus IDs, hashes, snapshots ni estados autoritativos desde el payload. No se añadió una política de doble persona: el mismo usuario autorizado puede preparar y publicar. Exigir separación de personas sería una decisión posterior explícita.

No se almacenan PIN, contraseñas ni hashes de credenciales. La referencia de aprobación es una declaración del usuario autorizado, no una prueba criptográfica de un documento externo.

Los tres modelos impiden guardar o borrar directamente mediante sus operaciones ordinarias. El servicio rechaza todo cambio de una versión publicada y solo permite reemplazar asociaciones en borrador; la auditoría preserva el antes/después de ese reemplazo.

**Límite de seguridad:** inmutabilidad, manifiesto completo y exclusión de vigencias se hacen cumplir por el servicio y su protocolo. No hay triggers que impidan UPDATE/DELETE arbitrarios mediante SQL privilegiado o Query Builder fuera del servicio. Las FK/UNIQUE/CHECK sí son restricciones SQL efectivas, pero no equivalen a una auditoría inviolable. SHA256 detecta divergencias comprobadas por la aplicación; no es una firma ni protege frente a alguien capaz de reescribir simultáneamente datos y hashes.

## 5. Concurrencia y compatibilidad con AC-02

Se reutiliza el GET_LOCK administrativo existente de `AccountIntegrityService`, derivado del esquema. Se extrajo `withAdministrationLock()` sin cambiar el comportamiento del CRUD del catálogo: este sigue bloqueando el conjunto de cuentas para sus operaciones de jerarquía.

Las políticas usan ese mismo mutex y una transacción, pero no adquieren un bloqueo exclusivo de todo el catálogo. Bloquean la versión y sus asociaciones, releen las vigencias publicadas mediante lectura actual y adquieren bloqueos compartidos de recursos/cuentas referenciados en orden estable. Los recursos se toman antes de las cuentas; los IDs se ordenan ascendentemente. Los actores se comprueban dentro de la operación.

Publicar y administrar el catálogo quedan serializados por el mismo mutex. No se añade ese mutex a operaciones financieras. Las asociaciones pasan a formar parte de `AccountIntegrityService::dependencies()`: no pueden eliminarse, desactivarse ni alterarse estructuralmente sus cuentas eludiendo las protecciones AC-02. Los cambios de metadatos permitidos por AC-02 se detectan al comparar fotografías antes de publicar.

Se comprobó una carrera real de publicación con procesos y conexiones MySQL independientes: una versión se publicó y la otra fue rechazada por solapamiento. Se comprobaron IDs de conexión distintos, resultados persistidos, bloqueo del segundo intento y cierre de procesos/conexiones.

La modificación de una cuenta entre preparación y publicación se verificó secuencialmente mediante cambio de fotografía y rechazo; la desactivación/borrado con asociación se rechazó. Las carreras de jerarquía de AC-02 se ejecutaron de nuevo. No se afirma haber ejecutado una carrera adicional publicación–desactivación ni una contabilización concurrente usando políticas: el motor operativo todavía no las consulta.

## 6. Pruebas ejecutadas

PHP CLI: `E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe`.

Todos los modos SQL del runner verifican raíz, identidad, destino, puerto, roles, privilegios, migraciones y respaldo antes de PHPUnit. Los comandos se ejecutan con `-d zend.exception_ignore_args=1`.

| Modo de `tests/Support/run-ac03a-tests.php` | Resultado final | Aserciones |
| --- | ---: | ---: |
| `install` | 1 prueba aprobada | 20 |
| `core` | 18 pruebas aprobadas | 83 |
| `concurrency` | 1 prueba aprobada | 17 |
| `guard` | 1 prueba aprobada | 10 |
| `ac02` | 15 pruebas aprobadas | 101 |
| **Total de casos finales distintos** | **36 aprobados** | **231** |

La instalación verificó aplicación, rollback vacío y reinstalación de la migración exacta, preservando tablas anteriores y las 79 filas anteriores de migraciones. No se utilizó RefreshDatabase para reconstruir todo el esquema en esta fase.

El grupo core cubrió borrador/publicación, hashes y orden canónico, auditoría, demo, inmutabilidad de los modelos/servicio, autoridad de campos, asociaciones duplicadas/ambiguas/incompletas, cuenta inexistente/inactiva/agrupadora/incompatible, cambios durante preparación, vigencias consecutivas/solapadas, recurso artículo, apertura bloqueada, FK/UNIQUE/CHECK/RESTRICT y rollback ante fallo inyectado de auditoría.

El guardia final rechazó `down()` con evidencia en uso, antes de cualquier DDL. La carrera de publicación conserva para revisión dos versiones sintéticas, doce asociaciones y cinco auditorías: una versión publicada y otra en borrador con intervalo de ensayo en 2099. No son una aprobación empresarial real ni están activadas en el ERP. La carrera no debe repetirse ciegamente sobre el mismo intervalo; detecta su evidencia previa y se detiene.

Los casos core usan transacciones y una barrera de escrituras. El grupo AC-02 conserva sus pruebas focalizadas de catálogo, asiento manual, documentos relacionados y concurrencia de jerarquía. No se ejecutaron la suite general, Composer test ni suites financieras adicionales de H03/H04. La compatibilidad H03/H04 de esta entrega es estática y estructural, además de las regresiones contables directamente seleccionadas; no se sustituyen sus resultados previos por una afirmación de nueva cobertura completa.

La última repetición de `core` y `guard` confirmó los resultados de la versión definitiva. El lint de los 15 archivos PHP afectados y `git diff --check` finalizaron correctamente.

## 7. Aislamiento, respaldo e integridad

Preflight real: `salon_erp_test`, `salon_erp_tester@localhost`, puerto 3306, MySQL 8.4.3, roles NONE, privilegios exactos del esquema y rechazo de acceso a las otras dos bases. Antes del DDL se verificaron las 79 migraciones previas; después, las 80 exactas. No se cambiaron usuarios ni permisos.

Respaldo existente comprobado, sin sobrescribir ni generar otro:

`E:/salon-erp-backups/salon_erp_test_20261112_074028_3813668.sql`

SHA256: `8778320250e84322267a7d99bf86e18f70c099d0117ea5b73dad67c2c32aa531`.

Línea base nueva: `storage/app/private/isolation-AC03A-before.json` (ignorada por Git). El comparador usa consultas de lectura y comprueba todas las filas y estructura. La cuenta administrativa utilizada por ese comparador no fue la identidad de ejecución de migraciones o pruebas.

| Base | Antes: tablas / filas / migraciones | Después | Comparación |
| --- | --- | --- | --- |
| `salon_erp` | 78 / 225 / 74 | 78 / 225 / 74 | Contenido y DDL exactos sin cambios |
| `salon_erp_test` | 81 / 544 / 79 | 84 / 564 / 80 | Tablas anteriores y 79 migraciones conservadas; solo tres tablas nuevas y una migración |
| `salon_erp_bootstrap_check` | 78 / 202 / 74 | 78 / 202 / 74 | Contenido y DDL exactos sin cambios |

En test se excluye exclusivamente el avance de secuencias AUTO_INCREMENT de la comparación estructural: rollback de fixtures no revierte esos contadores. Las veinte filas adicionales son diecinueve registros sintéticos de las tablas nuevas y una fila de migración.

`SALON_TEST_DB_RESET_ALLOWED=0` fue restablecido y comprobado mediante `finally` por el runner, también tras los últimos grupos. No se activó V2 ni se aplicó esta migración en desarrollo/bootstrap.

## 8. Archivos y revisión

Nuevos:

- La migración indicada arriba.
- `app/Support/AccountingPolicyRoles.php`.
- `app/Services/AccountingPolicyService.php`.
- `app/Models/AccountingPolicyVersion.php`, `AccountingAccountBinding.php`, `AccountingPolicyAudit.php`.
- `tests/Feature/AccountingPolicyInstallationTest.php`, `AccountingPolicyTest.php`, `AccountingPolicyConcurrencyTest.php`, `AccountingPolicyRollbackGuardTest.php`.
- `tests/Support/AccountingPolicyFixtures.php`, `ac03a-publish-worker.php`, `run-ac03a-tests.php`.
- Este informe.

Modificados:

- `app/Services/AccountIntegrityService.php`: dependencia de asociaciones y extracción del mutex administrativo compartido.
- `tests/Support/isolation-snapshot.php`: fase AC03A y comparación de sus cambios permitidos.

No se modificaron `ContabilidadService`, servicios de inventario, fondos, nómina, agenda, fórmulas financieras, rutas, configuración ni migraciones históricas.

Diff específico de código, esquema y pruebas: `storage/app/private/ac03a-review.diff`, 69.915 bytes, 15 archivos (13 nuevos y 2 modificados). SHA256: `1688ca983a58b76ac1e4b456643b36b55b35ee56048548a6314a2b68b94eaa8b`. Se comprobó con `git apply --reverse --check`; no se aplicó su reversión. El informe y el libro Excel ajeno a esta tarea quedan fuera de ese patch. No se añadieron archivos al índice ni se realizó commit.

## 9. Riesgos y continuación sujeta a autorización

1. Ninguna política está conectada aún al motor financiero. AC-03B deberá definir la fijación de versión, cobertura operativa completa y referencias históricas nullable antes de adoptar asociaciones en documentos nuevos.
2. La protección ante SQL privilegiado directo requiere controles adicionales si se pretende convertirla en una garantía de base de datos; esta entrega no cambió privilegios ni añadió triggers.
3. Los roles nuevos o ámbitos con precedencias no están admitidos automáticamente. La cobertura inicial no debe presentarse como configuración completa de todos los módulos.
4. La contrapartida de apertura permanece sin elegir y su publicación está bloqueada. No se levantaron las restricciones de H01-B2B.
5. La referencia documental y la aprobación autenticada conservan evidencia declarada, no verifican por sí mismas la autenticidad externa del documento.
6. El test contiene evidencia sintética durable. No eliminarla para conseguir un rollback ni confundir su tipo enterprise de fixture con una autorización empresarial.

### Reversión segura

Antes de uso se demostró el rollback con las tres tablas vacías. En el estado final hay evidencia y el rollback está intencionalmente bloqueado. Conservar esquema y auditoría; no restaurar automáticamente un dump ni borrar registros.

La reversión exclusiva de código puede revisarse con el patch indicado. Debe detenerse cualquier futura administración de políticas y evaluarse expresamente la conservación de sus dependencias antes de revertir también el pequeño enlace de `AccountIntegrityService`. No ejecutar a ciegas un rollback de migraciones o `git reset` sobre trabajo ajeno.

AC-03A termina aquí. No se inicia AC-03B, AC-04, H01-B2B ni un despliegue.
