# AC-04B2B1 — Administración documental de políticas y habilitaciones

Fecha: 2026-11-16. Completada y verificada **exclusivamente en salon_erp_test**. Implementación del contrato empresarial mediante actos sintéticos de ensayo, sin atribuir aprobación económica real al contador. No se habilita ningún consumidor financiero.

## 1. Alcance implementado

Se reutilizan AccountingPolicyService, AccountingEventAuthorizationService, AccountingAccountResolver y AccountIntegrityService, con las tablas existentes de versiones, bindings, auditoría y autorizaciones. No hay segundo catálogo, motor o sistema de permisos.

Se añadieron creación con procedencia administrativa, revisión de asociaciones, aprobación documental, habilitación y retirada. No se creó un editor completo de asociaciones: la preparación utiliza el servicio existente y su nuevo punto de creación; la interfaz permite revisar versiones preparadas y registrar los actos. No se eligieron cuentas reales para una empresa. Las asociaciones de los fixtures son hipótesis expresas y no configuran el ERP empresarialmente.

**No hubo DDL ni migraciones nuevas:** siguen las 81 existentes. No se alteraron versiones publicadas, CORE, métodos financieros, banco/saldos, inventario, nómina, períodos o conciliaciones. No se utilizó RefreshDatabase ni migrate:fresh.

## 2. Procedencia desde el nacimiento

`AccountingPolicyService::createAdministrativeDraft(array $data)` construye la procedencia desde servidor, dentro de la misma transacción de creación y su acto `created`, secuencia 1. El método ordinario `createDraft()` conserva su contrato anterior.

El JSON de auditoría ya admitía evidencia versionada. En el acto nuevo se conservan `origin` y su SHA256 canónico, con:

- Contrato de procedencia v1, workflow `accounting_administration`.
- Ejecución **synthetic_test**, `economic_approval=false` y esquema exacto `salon_erp_test`.
- ID de política, creador autenticado, hash inicial y timestamp UTC generados por el servidor.

El hash inicial se contrasta con `after_hash` y el contenido `after` del acto de creación; creador, actor, política y fecha deben corresponder. La fecha se comparte entre procedencia y registro para evitar diferencias al cruzar un segundo. El hash de procedencia queda incluido en la evidencia de cada acto administrativo posterior.

No se deduce procedencia de kind, nombre, fecha o referencia. Las versiones técnicas anteriores sin esta evidencia son **no elegibles para el nuevo contrato**, sin reclasificarlas automáticamente como empresariales o sintéticas. Se mantienen consultables por sus contratos técnicos existentes. No existe un setter para adjuntar origen a una versión vieja; los campos adicionales enviados a creación/revisión se rechazan. ReviseDraft y publicación no cambian el acto de origen.

Esto acredita el flujo administrativo ensayado, no una implantación real. No se implementó una procedencia económica empresarial real ni su autorización de despliegue. Una futura implantación requerirá otro origen administrativo legítimo creado desde el inicio y aprobado expresamente; no se obtiene cambiando `execution`, purpose o un JSON de estos registros.

La inmutabilidad está impuesta por los servicios y las referencias/hash del contrato. No se afirma resistencia criptográfica frente a un administrador SQL que pueda reescribir toda la historia. SHA256 no es una firma de aprobación ni se guardan credenciales para firmar.

## 3. Servicios y contrato documental

Nuevos métodos en AccountingEventAuthorizationService:

| Método | Responsabilidad |
| --- | --- |
| `reviewForAdministration(versionId, bankId)` | Versión publicada enterprise, procedencia de creación, banco/base NIO, cuenta bancaria original, cobertura y fotografías completas, historial documental |
| `approveForAdministration(versionId, bankId, hash, from, until, reference, reason)` | Contrastar hash revisado; registrar aprobación con actor, referencia, motivo, ventana y asociaciones revisadas |
| `enableForAdministration(approvalId, from, until, reference, reason)` | Aprobación íntegra no retirada, cobertura actual compatible y ventana contenida; una habilitación por aprobación |
| `revokeForAdministration(targetId, reference, reason)` | Append de retirada del acto exacto, preservando evidencia y padre; impedir repetición |

Cada entrada repite la frontera y permisos del servidor. Aprobación/habilitación/retirada usan el mutex administrativo existente, versión bloqueada X y actor autenticado. No reciben como autoridad actor, aprobador, purpose, entorno, cuenta o instalación del navegador. IDs de versión/banco/acto son referencias que deben validarse, no permisos.

Los instantes requieren ISO con zona explícita y se normalizan a UTC; intervalos [desde,hasta) no vacíos, no expirados y contenidos en los de la política/aprobación. Se admite revisión documental de una política futura publicada, necesaria para planificar habilitación, sin afirmar que ya esté operativamente vigente. Las políticas expiradas y borradores se rechazan.

Se mantiene el criterio conservador de una aprobación no retirada por versión/banco y la unicidad padre/acción. No se sobrescribe ni reactiva una habilitación retirada. Otro recurso, hash distinto, cuenta inactiva/no imputable o fotografía incompatible producen rechazo; no hay fallback por código/nombre.

### Significado de purpose y evidencia

Los nuevos actos usan `purpose=business` para representar el **contrato documental que se ensaya**, con `environment=testing`. No son aprobaciones reales: todos incluyen una sección `administration` validada con `synthetic=true`, `economic_approval=false`, `financial_authority=false`, contrato de evento 1, instalación de ensayo, origen y bindings revisados. La interfaz y motivos de fixtures señalan su naturaleza sintética.

La evidencia completa mantiene `version=1`, compatible con el CHECK existente; la sección administrativa incorpora su propio `contract_version=1`. Se hashea el documento completo y se comprueba su correspondencia con columnas. En habilitación se contrastan nuevamente los bindings revisados. No basta encontrar purpose=business para conceder autoridad.

## 4. Frontera y contabilización cerrada

La vía nueva exige conjuntamente la frontera de ensayo ya validada: CLI, bootstrap seguro, contrato interno, entorno testing, raíz correcta, reset 0, única conexión permitida, esquema salon_erp_test, CURRENT_USER salon_erp_tester@localhost, puerto 3306, roles SQL NONE y usuario contable activo. APP_ENV o un booleano no bastan.

Se mantienen sin cambios:

- `AccountingAccountResolver::resolveForPosting()`: rechaza.
- `AccountingEventAuthorizationService::assertBusinessAuthority()`: rechaza, incluidos los nuevos actos.
- Consumidor empresarial del cargo: inexistente/cerrado.
- Confirmación financiera empresarial: no implementada.
- Métodos ForSimulation y guardias AC-04B2A: conservados; rechazan estas habilitaciones de purpose business.

No se ha abierto una ruta administrativa accesible en una instalación ordinaria: el controlador devuelve 404 fuera del entorno/bootstrap de ensayo CLI, antes de usar el nuevo servicio. La interfaz se verificó mediante el kernel HTTP de Laravel bajo PHPUnit. **No se afirma disponibilidad por navegador contra Laragon ni revisión visual manual.** Su habilitación en una instalación empresarial necesita una frontera propia y autorización posterior; no consiste en quitar estos guardias.

## 5. Interfaz y permisos

Rutas nuevas bajo el grupo web existente `auth` y `role:alvaro,contador`:

```text
GET  /contabilidad/politicas/ensayo
GET  /contabilidad/politicas/ensayo/{version}?bank=...
POST /contabilidad/politicas/ensayo/{target}/approve
POST /contabilidad/politicas/ensayo/{target}/enable
POST /contabilidad/politicas/ensayo/{target}/revoke
```

Solo se admite el action de la allowlist. Formularios con CSRF muestran versión/estado/hash, todas las asociaciones CORE y bancarias con sus cuentas y recursos, banco/NIO, vigencia, referencia, motivo, actor y timestamp UTC. Retirada directa o de aprobación padre se muestra como tal; no se ofrece volver a habilitar el mismo padre. No existe edición de publicación ni selección de cuentas por el operador de caja.

Roles alvaro/contador admitidos; admin, recepcion y estilista rechazados tanto por ruta como por servicios. El enum real de users no tiene un valor cajera/cajero: no se creó un rol ni se amplió permiso para simularlo. También se verificó sesión ausente y 404 en entorno local. No se impuso separación obligatoria de personas.

Las entradas autoritativas adicionales se rechazan. No se almacenan PIN, contraseñas, tokens, cookies o hashes de credenciales en los documentos. El token CSRF permanece en el mecanismo web existente. No se añadió navegación global, framework JS, CSS transversal ni corrección UI-01.

## 6. Concurrencia administrativa

No se añadieron locks administrativos a operaciones financieras. Los actos mutantes siguen mutex AC-02 → versión X → autorización/origen → recursos/cuentas en el orden de AC-03A/B1. La revisión mantiene versión S y lecturas actuales de sus documentos; una retirada espera al final de esa revisión. No se creó un nuevo protocolo de banco ni se modificó BankBalanceService.

Se ejecutó una sola pasada de siete carreras, con ventanas hipotéticas nuevas enero–julio 2045. No se repitieron ventanas ocupadas. Evidencia durable:

`storage/app/private/ac04b2b1-concurrency-adea2edc479738a4.json`, más siete `ac04b2b1-pair-*.json`.

| Carrera | CONNECTION_ID | Resultado persistido |
| --- | --- | --- |
| Dos aprobaciones | 1292 / 1293 | Una confirma, otra rechaza; un acto |
| Aprobación / desactivar cuenta | 1294 / 1295 | Aprobación confirma; modificación incompatible rechazada por AC-02; cuenta conserva activa |
| Dos habilitaciones | 1296 / 1297 | Una habilitación; duplicada rechazada |
| Dos retiradas | 1298 / 1299 | Una retirada; repetición rechazada |
| Consulta / retirada | 1300 / 1301 | Consulta estable bajo S; retirada espera y confirma después |
| Publicación / aprobación | 1302 / 1303 | Publicación confirma; aprobación con hash de borrador queda obsoleta y se rechaza |
| Retirada de aprobación / habilitación | 1304 / 1305 | Retirada confirma; habilitación no puede eludirla |

Se comprobaron barreras de bloqueo, PID/CONNECTION_ID distintos, códigos de salida, registros finales y cierre de procesos/conexiones. La prueba de modificación usa una cuenta de gasto nueva **de fixture**, sin saldos/asientos, para no cambiar cuentas históricas. Queda conservada como evidencia sintética.

Los fallos inyectados tras insertar un acto y al insertar la auditoría de creación comprobaron rollback de documento/procedencia y nivel transaccional. No se dejó una política sin auditoría ni una autorización parcial.

## 7. Pruebas definitivas y build

| Grupo | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| admin-core | 15 | 77 | Aprobado |
| admin-race | 1, siete carreras | 123 | Aprobado |
| admin-regressions | 114 | 677 | Aprobado |
| Total | **130** | **877** | **Sin fallos** |

Regresiones focalizadas: AccountIntegrityTest, AccountHierarchyConcurrencyTest, AccountingPolicyTest, AccountingAccountResolverTest, BankBalanceIntegrityTest, AccountingEventDocumentationTest, AccountingEventRollbackGuardTest y AccountingBankChargeSimulationTest. Este último crea efectos solo dentro de pruebas transaccionales revertidas; no se repitieron las carreras financieras persistentes B2A. No se ejecutó suite general ni Composer test.

Se cubrieron procedencia ausente/manipulada, hash incorrecto, referencia/motivo vacíos, ventana incompatible, borrador, banco inexistente/incompatible, cuenta inactiva y política expirada. Se verificó origen no aceptado desde payload, permisos del servidor en cada acto, revisión de bindings, duplicados, retirada, conservación histórica y bloqueos financieros.

CSRF se probó con PreventRequestForgery real, sustituyendo únicamente su bypass runningUnitTests: POST sin token devolvió 419; con token se ejercitaron aprobación, habilitación y retirada. No se utilizó exclusivamente postJson como demostración. Los formularios se renderizaron por HTTP, con valores escapados.

Incidencia inicial: un fixture intentó guardar el rol inexistente cajera en el enum y falló; se corrigió la prueba para usar los roles reales. No se cambió esquema ni reglas RBAC. La pasada final completa indicada arriba usa la versión definitiva.

`npm run build` inicialmente quedó bloqueado por npm.ps1 y la política de ejecución de PowerShell; `npm.cmd run build` terminó con código 0, Vite 8.1.5 y 12 módulos. No se cambió ExecutionPolicy ni se instalaron dependencias. Advertencia no bloqueante: optimización opcional de fuentes requiere fontaine. No se cambió su configuración. Salida generada de Vite ignorada por Git, sin edición manual.

Sintaxis PHP y `git diff --check` correctos. No se atribuyen estas pruebas a aprobación económica real ni se afirma seguridad de un consumidor empresarial aún inexistente.

## 8. Preflight, respaldo y tres bases

Cada grupo verificó PHP 8.3.30, MySQL 8.4.3, conexión TCP 127.0.0.1:3306, identidad restringida, roles NONE, nueve privilegios de esquema previstos más USAGE y rechazo de acceso a las otras bases. Se contrastaron nombres y estado de las 81 migraciones, sin ejecutarlas.

Se verificó el respaldo existente AC-04B2A, tamaño y SHA256/sidecar:

```text
E:/salon-erp-backups/salon_erp_test_AC04B2A_20261116_132030_f5b65ea23b33.sql
296534 bytes
cd85eb0497ef63bb3a4a1a42373166c0ccc442e36951bb3c28fcebff335a7a93
```

No se creó otro respaldo ni se sobrescribió ninguno. Este archivo corresponde al estado previo a los cargos sintéticos B2A (676 filas), no contiene toda la evidencia posterior; no debe presentarse como copia del estado final actual. Restauración no ensayada. Antes de futuro DDL/despliegue debe generarse un respaldo actualizado autorizado.

Nueva línea base: `storage/app/private/ac04b2b1-isolation-before.json`. Comparación final completa por hashes de filas, columnas y DDL, normalizando contadores AUTO_INCREMENT. El inspector administrativo solo hizo consultas de lectura; las pruebas usaron tester.

| Base | Tablas | Migraciones | Filas antes/después | Resultado |
| --- | ---: | ---: | ---: | --- |
| salon_erp | 78 | 74 | 225 / 225 | Estructura y contenido conservados |
| salon_erp_bootstrap_check | 78 | 74 | 202 / 202 | Estructura y contenido conservados |
| salon_erp_test | 87 | 81 | 1267 / 1364 | Historia previa conservada; 97 filas nuevas de ensayo |

Las adiciones persistentes son siete políticas administrativas, 56 bindings, 21 actos de auditoría, 12 autorizaciones documentales sintéticas y una cuenta fixture. La comparación solo permite append en esas cinco tablas para esta fase. **Cuentas bancarias, movimientos, asientos, detalles, operaciones y correspondencias financieras quedaron exactamente iguales a la línea base**, no solo con el mismo conteo. Se conserva el preparado ID131, banco221, sin intervención, así como todas las carreras anteriores.

`SALON_TEST_DB_RESET_ALLOWED=0` comprobado en archivo y restablecido mediante finally por runner/workers. Nunca se habilitó. Sin cambios de credenciales, APP_KEY, AGENTS.md, dependencias o permisos SQL. V2 no desplegado en desarrollo.

## 9. Archivos y diff

Modificados:

- `app/Services/AccountingPolicyService.php`: creación administrativa y origen atómico en auditoría.
- `app/Services/AccountingEventAuthorizationService.php`: revisión, aprobaciones, habilitación y retirada del contrato documental de ensayo.
- `routes/web.php`: rutas administrativas focalizadas con roles actuales, además de la frontera del controlador.
- `tests/Support/run-ac04b1-tests.php`: grupos allowlisted y preflight para B2B1.
- `tests/Support/ac04b1-isolation.php`: nueva línea base y comparación estricta sin cambios financieros.

Nuevos:

- `app/Http/Controllers/AccountingAdministrationController.php`.
- `resources/views/accounting/policy-administration.blade.php`.
- `tests/Feature/AccountingAdministrationTest.php` y `AccountingAdministrationConcurrencyTest.php`.
- `tests/Support/AccountingAdministrationFixtures.php` y `ac04b2b1-administration-worker.php`.
- Este informe.

El documento B2B0 ya estaba sin seguimiento al iniciar: se conserva y no se incluye en el patch de código de esta fase. No se modificaron resolutor, ContabilidadService, BankBalanceService, escritores bancarios, migraciones ni configuración.

Patch final específico, **11 archivos de código/pruebas**, excluyendo documentación:

```text
storage/app/private/ac04b2b1-review-final.diff
51287 bytes
SHA256 d02c0f08ed69892f752aa6c61f497d664174a499582bdf0f7e832ecdb80aef27
```

Comprobado mediante `git apply --reverse --check`, sin aplicar la reversión. No hubo commits.

## 10. Comandos de verificación

Desde la raíz y con PHP absoluto `E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe`, opciones `-d display_errors=0 -d zend.exception_ignore_args=1`:

```text
tests/Support/run-ac04b1-tests.php admin-preflight
tests/Support/ac04b1-isolation.php before AC04B2B1
tests/Support/run-ac04b1-tests.php admin-core
tests/Support/run-ac04b1-tests.php admin-race
tests/Support/run-ac04b1-tests.php admin-regressions
tests/Support/ac04b1-isolation.php after AC04B2B1
```

El grupo de carreras rechaza una ventana ya ocupada. No repetir ciegamente admin-race ni reemplazar su evidencia para hacerlo pasar. Sintaxis con php -l; compilación mediante npm.cmd run build. Sin Artisan, DDL, RefreshDatabase, suite general o Composer test.

## 11. Riesgos pendientes y reversión

1. La procedencia empresarial real, aprobación económica, entorno autorizado y habilitación operativa continúan pendientes. Los métodos actuales solo prueban el contrato con evidencia sintética y no deben promoverse quitando guardias.
2. Se conservan CORE v1 y las validaciones de todas sus fotografías; pueden impedir aprobar una política si cambia una cuenta CORE. No se implementó cobertura v2 ni precedencias.
3. No hay editor completo de políticas/asociaciones por UI ni interfaz empresarial operativa en navegador; se verificaron los servicios y el renderizado/actos por kernel HTTP aislado. Cualquier ampliación necesita alcance explícito.
4. Siguen pendientes reversión bancaria de dominio, conciliados, fechas/períodos, preparados empresariales, abandono/reemplazo y habilitación financiera. No se creó ninguna de esas políticas por inferencia.
5. Revisión e inmutabilidad dependen del protocolo de servicios; SQL privilegiado puede eludirlo. No se añadieron triggers ni cambios de permisos.
6. No se cambió segregación obligatoria de personas. Los actos registran identidades, pero la regla empresarial debe aprobarse antes de exigir actores distintos.

Reversión segura: revisar el patch específico y comprobar nuevamente su aplicación inversa antes de retirarlo, sin reset global ni eliminación de trabajo anterior. Conservar el esquema y documentos sintéticos existentes. Retirar el código no elimina sus auditorías; no borrar filas ni restaurar automáticamente el dump antiguo. Cualquier restauración o limpieza requiere autorización y destino explícitos. No hay migración nueva que revertir.

AC-04B2B1 termina aquí. No se inicia reversión bancaria, cargo empresarial, AC-05, AC-06, H01-B2B ni despliegue.
