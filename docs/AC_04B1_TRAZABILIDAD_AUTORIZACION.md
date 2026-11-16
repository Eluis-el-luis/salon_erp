# AC-04B1 — Autorización, trazabilidad e identidad documental

Fecha: 2026-11-16. Verificado exclusivamente en `salon_erp_test`.

## 1. Resultado y límites

Se instaló una migración aditiva y se implementaron servicios documentales de ensayo. Test pasa de **80 a 81 migraciones**. Desarrollo y bootstrap conservan 74 y no se desplegaron sus esquemas.

Se separan publicación técnica, aprobación, habilitación y retirada. Los escritores implementados están nombrados `ForSimulation`, fijan `purpose=simulation` desde servidor y rechazan otros entornos antes de consultar SQL. No hay rutas administrativas nuevas, emisión empresarial real habilitada, aprobación real por defecto ni consumidor financiero activo.

El esquema contempla autorizaciones `business` para integración futura, pero esta etapa no tiene un escritor público de aprobación empresarial real. Esa capacidad requiere una integración explícita posterior; no se obtiene cambiando un booleano, un propósito del payload o el estado de una versión. `assertBusinessAuthority()`, `AccountingAccountResolver::resolveForPosting()` y `AccountingEventIdentityService::confirm()` continúan rechazando el uso financiero.

No se cambió ContabilidadService ni la contabilización bancaria heredada. Ningún asiento tiene atribuida una política y no se emitieron movimientos o asientos mediante las asociaciones nuevas.

## 2. Respaldo nuevo y preflight previo al DDL

Respaldo nuevo, fuera del repositorio:

`E:/salon-erp-backups/salon_erp_test_AC04B1_20261116_115137_35d135bf6bbc.sql`

SHA256:

```text
c000086a76c0dca7e3cfe0775d78028d4c9aff637b5c57a13d553f208f4e0f5f
```

Tamaño: **255.144 bytes**. Origen verificado: **84 tablas InnoDB, 660 filas y 80 migraciones**.

Se utilizó mysqldump 8.4.3 con `--single-transaction`, `--quick`, `--skip-lock-tables`, `--routines`, `--events`, `--triggers`, `--hex-blob`, `--no-tablespaces`, `--set-gtid-purged=OFF`, `--column-statistics=0`, UTF8MB4 y exclusión de DROP/LOCK de tablas en el archivo. Se exportó exclusivamente `salon_erp_test`, sin `--databases`, CREATE DATABASE o USE. No se incluyeron usuarios/permisos SQL ni configuración de Laravel.

El script reservó un nombre `.partial` exclusivo, comprobó salida 0, contenido no vacío, 84 definiciones CREATE TABLE, marcador de fin y coincidencia de filas/DDL antes y después de exportar. Después finalizó el archivo sin sobrescribir, creó un sidecar SHA256 exclusivo y volvió a calcular el hash. El manifiesto está en `storage/app/private/ac04b1-backup.json`, ignorado por Git.

Dos exportaciones previas quedaron conservadas como `.partial`: el verificador rechazaba cualquier stderr. El diagnóstico mostró salida 0 y el aviso genérico de MySQL por `--skip-password`. Se corrigió el verificador para admitir **solo ese texto exacto** con salida 0 y las demás comprobaciones satisfechas. No hubo contraseña en argv; no se imprimió contenido SQL. Se realizó otra exportación con un nombre nuevo y se validó como respaldo definitivo. Los parciales y todos los respaldos anteriores se conservaron. No se ejecutó DDL hasta completar esa validación.

La exportación usó la identidad administrativa local exclusivamente para lecturas/respaldo. Las migraciones y pruebas usaron `salon_erp_tester@localhost`, TCP 127.0.0.1:3306, PHP **8.3.30**, MySQL **8.4.3**, roles NONE y los nueve privilegios exactos limitados a test. Se comprobó rechazo de acceso a desarrollo/bootstrap. No se modificaron cuentas o permisos SQL.

El respaldo no se restauró para ensayarlo: SHA256 y comprobaciones de exportación no equivalen a una prueba completa de restauración. Cualquier recuperación requiere destino explícito, permisos adecuados, revisión de objetos/definers y autorización separada; no restaurar automáticamente sobre ninguna base.

## 3. Migración instalada y justificación

`database/migrations/2026_11_16_000002_add_accounting_event_documentation.php`.

Tres tablas nuevas:

| Tabla | Responsabilidad |
| --- | --- |
| `accounting_policy_authorizations` | Actos append-only de aprobación, habilitación y revocación, con evidencia y referencias al acto anterior. |
| `accounting_event_operations` | Identidad UUID, hash y contenido canónico del cargo preparado; clasificación explícita y vínculos del futuro resultado. |
| `accounting_entry_line_resolutions` | Una asociación por detalle del piloto, enlazada a su operación, política, habilitación, binding, cuenta y documento originales. |

Se añadieron a `asientos_contables` tres campos nullable: `accounting_policy_version_id`, `accounting_policy_hash` y `accounting_authorization_id`. Una columna generada identifica el tipo de autorización requerido (`enablement`) cuando existe vínculo. Un CHECK exige los tres campos presentes o los tres NULL; los históricos conservan NULL, incluida la columna derivada.

Se añadieron índices UNIQUE compuestos a versiones de política, bindings, cabeceras, detalles y movimientos bancarios. No cambian valores históricos: permiten que las FK comprueben identidades completas, no solo que los IDs existan.

No se modificó ninguna de las ochenta migraciones anteriores. No hubo backfill, clasificación histórica, cuentas nuevas o cambio de importes. El DDL se ejecutó mediante la ruta exacta de esta migración, bajo preflight; no mediante RefreshDatabase, migrate:fresh o un comando genérico que aplique otras migraciones.

### Restricciones relevantes

- Todas las FK nuevas usan RESTRICT para actualización y borrado.
- La autorización referencia conjuntamente versión/hash, y su padre debe coincidir también en propósito, evento, banco, moneda y entorno. Una habilitación exige un padre `approval`; una revocación exige aprobación o habilitación.
- UNIQUE `(parent_id, action)` impide habilitación duplicada de una misma aprobación y retirada duplicada de un mismo acto. El servicio serializa además la detección de aprobaciones incompatibles.
- La cabecera referencia una **habilitación** de su misma versión/hash; no basta una aprobación o un ID de otra política.
- UUID de operación único, formato canónico UUID v4, hash hexadecimal, importe DECIMAL(15,2) positivo, clasificación `maintenance`/`commission` y JSON versionado.
- Estado `prepared`: resultado financiero NULL. Estado `confirmed`: ambos IDs de resultado obligatorios, con FK compuestas a movimiento/banco/asiento y a versión/hash/habilitación de la cabecera.
- Correspondencia: FK de siete columnas hacia el resultado de operación, de tres hacia detalle/asiento/cuenta y de seis hacia binding/versión/cuenta/papel/ámbito/banco. UNIQUE sobre detalle y contrato 1; solo `fondos.banco` o `gasto.cargo_bancario`.

La clave de ámbito se genera como `bank_account:<id>`, sin una precedencia implícita respecto de global. Una operación preparada no puede sustentar correspondencias financieras: sus resultados NULL no satisfacen esas FK.

Los CHECK y FK no validan por sí solos todos los hechos económicos: el futuro escritor deberá comprobar las dos líneas, signos, importes, períodos y cuadre. No se usa el esquema como sustituto de ContabilidadService.

## 4. Aprobación, habilitación, retirada y evidencia

`AccountingEventAuthorizationService` ofrece:

- `approveForSimulation(versionId, bankId, reference, reason)`.
- `enableForSimulation(approvalId, reference, reason)`.
- `revokeForSimulation(targetId, reference, reason)`.
- `inspectSimulation(id, instant)` dentro de una transacción.
- `assertBusinessAuthority(id)`: siempre rechaza en esta fase.

Los IDs son referencias internas de servidor para operaciones documentales autenticadas; no son autoridad financiera que pueda conceder un navegador. El actor se obtiene desde la sesión y se relee activo en users. Se reutilizan roles `alvaro`/`contador`; no hay roles paralelos ni impersonación. No se guardan PIN, contraseñas o hashes de credenciales.

Cada acto conserva versión/hash exactos, propósito, evento `bancos.cargo_gasto.nio`, banco, NIO, entorno, vigencia, actor, referencia, motivo, fecha UTC del servidor y evidencia JSON con SHA256. La evidencia contiene el conjunto completo de campos autoritativos y se coteja con las columnas, no solo con un hash aislado. No se acepta una evidencia vacía aunque alguien recalcule su hash.

La aprobación técnica del piloto verifica las dos asociaciones, banco/base NIO y la igualdad entre `fondos.banco` y el vínculo contable actual del banco. No elige una cuenta real, no aplica el fallback bancario y no reasigna saldos. Habilitar vuelve a comprobar la política y sus fotografías. Retirar agrega una fila: no modifica ni borra el acto original. Retirar la aprobación invalida también la elegibilidad de sus habilitaciones.

No se eligieron fechas empresariales: las vigencias de ensayo se toman de la política sintética correspondiente. No hay una aprobación tácita por publicar, crear un fixture o tener `kind=enterprise`. Las tres filas persistidas de autorización son inequívocamente `simulation/testing`.

Inmutabilidad significa aquí ausencia de API de edición/borrado y preservación de actos mediante append-only, hashes y restricciones referenciales. No se añadieron triggers contra todo SQL privilegiado. Un actor capaz de reescribir datos y hashes desde fuera de los servicios sigue fuera de esa garantía; no se presenta la evidencia como firma externa inviolable.

## 5. Identidad, contenido e idempotencia preparada

`AccountingEventIdentityService::prepareSimulation()` exige UUID, clasificación explícita, importe decimal como texto, fecha contable, referencia y descripción. No admite campos extra como actor, cuenta, política o hash autoritativos.

Normalización anterior a PDO:

- UUID en minúsculas, con formato v4 válido.
- Importe positivo, máximo trece dígitos enteros y dos decimales. Rechaza negativos, vacío, floats, notación científica y exceso de precisión; no redondea silenciosamente.
- Formatos equivalentes `00050.0` y `50.00` producen `50.00`.
- Fecha real `YYYY-MM-DD` dentro del rango DATE admitido; no convierte una fecha inexistente.
- Referencia/descripcion UTF8 no vacías y acotadas. Clasificación obligatoria: mantenimiento o comisión, sin inferirla del texto/código.

El hash se calcula sobre contenido ordenado determinísticamente, con contrato de evento, propósito/entorno, versión/hash, habilitación, recurso, moneda y ejecutor resueltos por el servidor. UUID identifica la solicitud y queda fuera del contenido económico canónico.

Mismo UUID/contenido devuelve el mismo documento preparado. Mismo UUID/contenido diferente, columnas contradictorias, JSON manipulado o estado no interpretable se rechazan. UNIQUE protege la identidad persistente; una competencia SQL/timeout/deadlock se convierte en rechazo controlado, sin reintentar automáticamente.

`confirmedResult(uuid)` autoriza la consulta mediante usuario activo releído —creador o rol contable— y exige resultado confirmado, dos correspondencias con los papeles esperados y contenido íntegro. Rechaza un preparado como resultado financiero y no da permiso para contabilizar.

**No hay método que confirme operaciones.** En los fixtures, los preparados se revierten con su transacción; al cerrar no hay operaciones ni correspondencias persistidas. El éxito positivo de consulta contra un cargo configurable confirmado y la idempotencia financiera completa siguen pendientes del escritor y pruebas de AC-04B2. No se fabricaron asientos con asociaciones para fingir esa cobertura.

## 6. Papeles y compatibilidad de publicación

Se añadió únicamente `gasto.cargo_bancario`: tipo gasto, naturaleza deudora y ámbito `bank_account`. `fondos.banco` se reutiliza. No hay códigos de cuentas empresariales en esos papeles.

Los seis CORE originales permanecen intactos. El contrato 1 de publicación/resolución sigue exigiéndolos, además de las asociaciones declaradas que se añadan en un borrador nuevo. Las versiones publicadas no se editaron; sus fotografías y hashes previos siguen válidos.

Los fixtures nuevos contienen seis papeles globales y los dos bancarios. El uso de cuentas actuales en esos fixtures es una hipótesis explícita de ensayo, no configuración empresarial. No hay seeders de aprobaciones reales.

Se recomienda diseñar un contrato posterior de cobertura por evento si se quiere eliminar la dependencia administrativa de CORE para este piloto. No se debilitó el manifiesto, añadió precedencia global/específica o reinterpretó contrato 1 para lograrlo. Esa evolución requiere aprobación antes de implementarse.

## 7. Concurrencia y orden de bloqueos

Los actos administrativos usan el mutex administrativo AC-02 y toman versión X antes de autorización, banco y cuentas. Publicación AC-03A participa en el mismo mutex. No se agrega GET_LOCK a BancoController o a movimientos financieros.

La inspección documental requiere transacción, fija versión S y relee habilitación/aprobación/revocaciones bajo lecturas actuales. La retirada toma versión X y espera a esa lectura. Tras confirmar la retirada, una nueva inspección rechaza la elegibilidad.

El preparador documental no toma banco X ni ejecuta cargos. No crea ahora la secuencia incorrecta banco X → política S. AC-04B2 deberá integrar expresamente la fijación de versión → bancos ordenados → cuentas con períodos/documentos y los locks de AC-04B0, conservando la transacción hasta el commit financiero. Un resultado previo de inspección no será suficiente.

Carreras realizadas, con cuatro procesos/conexiones MySQL independientes:

| Carrera | Resultado persistido |
| --- | --- |
| Dos aprobaciones incompatibles para versión/recurso | Una aceptada; segunda rechazada. Queda una aprobación, no dos. |
| Lectura fijada frente a retirada de habilitación | La retirada espera; la lectura mantiene evidencia estable; luego confirma la revocación y la siguiente inspección se rechaza. |

Se comprobaron PID/CONNECTION_ID diferentes, barreras, códigos de salida y filas persistidas. El manifiesto privado `ac04b1-concurrency-*.json` conserva IDs y resultados. Los workers solo pueden insertar autorizaciones; rechazan SQL financiero/DDL y desconectan/cierran procesos en finally. No se repitieron carreras sobre intervalos previos: se detecta evidencia existente y se detiene.

## 8. Pruebas y alcance verificado

| Grupo final | Pruebas aprobadas | Aserciones |
| --- | ---: | ---: |
| Instalación aditiva, rollback vacío y reinstalación | 1 | 20 |
| Contrato documental, hash, restricciones y entorno | 19 | 77 |
| Dos carreras de autorización/retirada | 1 | 29 |
| Rollback con evidencia: rechazo previo al DDL | 1 | 5 |
| Regresiones focalizadas AC-02, AC-03A, AC-03B y AC-04B0 | 74 | 382 |
| **Total de casos distintos** | **96** | **513** |

Se probaron UNIQUE, CHECK, FK y RESTRICT; cruces con un recurso existente incorrecto; rechazo de adopción business de una habilitación sintética; UUID duplicado; contenido equivalente/conflictivo; precisión; evidencia manipulada; columnas contradictorias; falta de cobertura; fallo inyectado de habilitación sin fila parcial y conservación de los actos anteriores.

La trazabilidad por línea se verificó mediante sus claves compuestas reales y rechazo de referencias inválidas. Un resultado no puede vincularse a un asiento legacy sin atribución de política. No se crearon asientos nuevos mediante asociaciones para ejecutar una prueba positiva de contabilización; esa prueba y la reconciliación operativa de líneas corresponden a AC-04B2.

Los funcionales usan DatabaseTransactions, nunca RefreshDatabase. La instalación empleó `migrate --path` y rollback de un paso sobre la migración exacta vacía; no una recreación. El guardia final rechazó down con evidencia antes de cualquier DROP/ALTER. El flag permaneció cero incluso durante DDL acotado: no se desactivaron los guardias de RefreshDatabase.

Las regresiones son AccountIntegrityTest, AccountHierarchyConcurrencyTest, AccountingPolicyTest, AccountingAccountResolverTest y BankBalanceIntegrityTest. Se adaptó exclusivamente la expectativa de AC-03B sobre la nueva referencia nullable: ahora comprueba existencia y ausencia de atribución histórica. No se repitieron instalación AC-03A, carreras financieras AC-04B0 o pruebas con evidencia durable ocupada.

Los últimos endurecimientos de validación documental y rechazo de entorno local se verificaron repitiendo el grupo documental. No cambian el protocolo de las carreras ya realizadas. Lint de los catorce archivos PHP y diff-check aprobados. No hubo suite general, Composer test o build frontend.

## 9. Integridad de las tres bases y guardias

Línea base independiente: `storage/app/private/ac04b1-isolation-before.json`. Las bases ajenas se compararon exactamente. En test se proyectaron las columnas originales para comprobar filas anteriores y se verificó que las referencias nuevas de todos los asientos siguen NULL.

| Base | Antes: tablas / filas / migraciones | Después | Resultado |
| --- | --- | --- | --- |
| `salon_erp` | 78 / 225 / 74 | 78 / 225 / 74 | Sin cambios de contenido o estructura. |
| `salon_erp_test` | 84 / 660 / 80 | 87 / 676 / 81 | Filas anteriores conservadas; esquema aditivo instalado. |
| `salon_erp_bootstrap_check` | 78 / 202 / 74 | 78 / 202 / 74 | Sin cambios de contenido o estructura. |

Dieciséis filas adicionales: una versión sintética, ocho asociaciones, tres auditorías técnicas, tres actos documentales de ensayo y una fila de migración. Operaciones preparadas persistidas: cero; correspondencias por línea: cero. Los asientos y movimientos existentes no cambiaron de cuenta, importe o identidad.

Se admiten en la comparación los índices compuestos/columnas aditivas revisados y el avance de AUTO_INCREMENT propio de fixtures revertidos. No se interpretó la nueva columna NULL como una modificación contable ni se atribuyó una versión a documentos históricos.

`SALON_TEST_DB_RESET_ALLOWED=0` se mantuvo y se restableció/comprobó en finally. No se cambiaron `.env`, credenciales, APP_KEY, AGENTS.md, dependencias, permisos SQL o configuración de desarrollo.

El runner nuevo `tests/Support/run-ac04b1-tests.php` comprueba PHP, raíz, cuenta/privilegios/destino, las migraciones exactas y el **nuevo** respaldo/sidecar antes de cada grupo. Los modos preflight/install esperan 80 al iniciar; core/concurrency/guard/regressions esperan 81. Los runners de fases anteriores con expectativas de 80 fallan cerrados; no deben forzarse ni usarse para repetir instalaciones históricas.

Comandos ejecutados con PHP 8.3.30 y `-d zend.exception_ignore_args=1`:

```text
tests/Support/run-ac03a-tests.php preflight-current       (antes de añadir el esquema)
tests/Support/ac04b1-backup.php                          (exportación administrativa autorizada)
tests/Support/ac04b1-isolation.php before
tests/Support/run-ac04b1-tests.php preflight
tests/Support/run-ac04b1-tests.php install
tests/Support/run-ac04b1-tests.php core
tests/Support/run-ac04b1-tests.php concurrency
tests/Support/run-ac04b1-tests.php guard
tests/Support/run-ac04b1-tests.php regressions
tests/Support/ac04b1-isolation.php after
```

## 10. Archivos de esta fase y diff

Nuevos:

- Migración `2026_11_16_000002_add_accounting_event_documentation.php`.
- `app/Services/AccountingEventAuthorizationService.php` y `AccountingEventIdentityService.php`.
- `tests/Feature/AccountingEventInstallationTest.php`, `AccountingEventDocumentationTest.php`, `AccountingAuthorizationConcurrencyTest.php`, `AccountingEventRollbackGuardTest.php`.
- `tests/Support/AccountingEventFixtures.php`, `ac04b1-auth-worker.php`, `ac04b1-backup.php`, `ac04b1-isolation.php`, `run-ac04b1-tests.php`.
- Este informe.

Modificados: `app/Support/AccountingPolicyRoles.php` y `tests/Feature/AccountingAccountResolverTest.php`.

Los cambios de AC-04B0 y sus documentos ya estaban sin commit al iniciar; permanecen ajenos al patch de esta fase. No se modificaron nuevamente sus controladores, locks o fórmulas. No cambiaron ContabilidadService, AccountIntegrityService, AccountingPolicyService, AccountingAccountResolver, FondosService, DivisaService, rutas o servicios de inventario/anulación.

Patch específico final: `storage/app/private/ac04b1-review-final.diff`, **14 archivos**, **73.051 bytes**.

SHA256:

```text
45d26f1d5f7e409ebafc4f8fcacdef7854131aa54b17a407bd064a3080c9b95c
```

Comprobado con `git apply --reverse --check`; no se aplicó. Excluye este informe y el trabajo previo AC-04A/B0. Se conserva un patch intermedio anterior para trazabilidad, pero el indicado arriba es el que corresponde a la versión final.

## 11. Riesgos y condiciones para AC-04B2

1. No existe emisión empresarial real habilitada ni aprobación tácita por datos sintéticos. Debe autorizarse e implementarse su procedimiento y verificación documental antes de activar consumidores.
2. No hay confirmador financiero ni ruta operativa nueva. Los locks, estados, UUID y hashes documentales no demuestran todavía idempotencia completa de cargos ni una confirmación histórica correcta por línea.
3. Se requiere definir el instante definitivo de reconocimiento/vigencia y su integración con períodos, revisión y documentos; la fecha del preparado solo se valida documentalmente. No se seleccionó una nueva política contable retroactiva.
4. Las reversas futuras deberán usar líneas, cuenta y asociaciones originales. No se creó una reversión bancaria genérica ni se levantó el bloqueo manual AC-02 de automáticos. La retirada de una habilitación no anula documentos ni altera conciliaciones.
5. La cobertura por evento posterior requiere un contrato explícito; no debilitar CORE ni modificar versiones publicadas para activar este piloto.
6. El esquema y servicio conservan evidencia, pero no son inmunes a un administrador SQL con privilegios para reescribirla. No se cambiaron privilegios ni se añadieron triggers.
7. Antes de desplegar en desarrollo será necesario otro plan autorizado de migración y respaldo. Esta fase no aplica su migración en desarrollo/bootstrap ni habilita V2.

## 12. Reversión segura

Se probó down con las tres tablas vacías y referencias de cabecera NULL, seguido de reinstalación. En el estado final existen actos de ensayo y el rollback está correctamente bloqueado **antes** del DDL. No borrar esos actos para conseguir un rollback.

El DDL de MySQL no es transaccional como un conjunto: ante instalación parcial se debe detener y diagnosticar, nunca repetir o limpiar automáticamente. No hubo fallo de DDL en esta entrega.

Una reversión de código debe revisarse con el patch final específico y conservar el esquema/evidencia en uso. No usar un reset global sobre AC-04B0 o trabajo ajeno. Restaurar el respaldo nuevo reemplazaría también evidencia posterior y requiere autorización y destino explícitos; no se ejecutó ni se recomienda como limpieza automática.

AC-04B1 termina aquí. No se inicia AC-04B2, AC-05, AC-06 o H01-B2B y no se realizan despliegues ni commits.
