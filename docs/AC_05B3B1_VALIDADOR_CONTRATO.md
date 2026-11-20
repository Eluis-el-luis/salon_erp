# AC-05B3B1 — Validador técnico puro de clasificación versionada

Fecha: 20 de noviembre de 2026. Estado: implementado y verificado con fixtures sintéticos en memoria. **No publica clasificaciones, no concede autoridad empresarial y no emite estados financieros.** No hay persistencia, rutas o conexión con los reportes operativos.

## 1. Preflight y alcance

Se verificó `feature/panel`, HEAD `330ebd00b8ca2f873e04eed9e20f8e41207c5d31`, índice vacío y únicamente `docs/AC_05G1_COMMIT_CONSOLIDADO.md` sin seguimiento al inicio. Ese informe se conservó sin modificar ni preparar. No aparecieron deltas posteriores del propietario en el preflight. Se leyó puntualmente `SALON_TEST_DB_RESET_ALLOWED=0`; no se modificaron los archivos de entorno.

Se inspeccionaron el contrato B3B0, las convenciones del repositorio y el bootstrap de pruebas. Para esta fase se evitó phpunit.xml porque selecciona tests/bootstrap.php y configuración de bases: las pruebas usan `--no-configuration --bootstrap vendor/autoload.php`, PHPUnit directamente y clases puras. No se inicia Laravel, TestCase de la aplicación, dotenv de test o conexiones SQL.

## 2. Componentes y contrato público

Se crearon dos componentes sin estado y sin dependencias de Laravel:

- `FinancialPresentationContractValidator`: estructura, referencias internas, perfiles, diagnóstico de cobertura de fixtures y operaciones documentales en memoria.
- `FinancialPresentationCanonicalizer`: codificación determinista de valores ya normalizados semánticamente. Separarlo permite revisar UTF-8, tipos y ordenación sin introducir otro motor de presentación.

API:

```php
validate(array $content, ?array $relevantAccountIds = null): array
revise(array $current, string $expectedHash, array $replacement, string $state): array
withdrawal(array $content, string $expectedHash, string $reference, string $recordedAt): array
withinValidity(array $content, string $instant): bool
```

El validador recibe arrays PHP estructurados, no JSON crudo ni solicitudes HTTP. No autentica usuarios, busca cuentas, calcula contribuciones, consulta políticas AC-03 o agrega movimientos. Las listas de IDs son hipótesis de fixture; su existencia y legitimidad económicas no se certifican aquí.

`validate` devuelve status, errors, diagnostics, canonical_content, canonical_json y hash. Siempre devuelve `business_authority=false`, `publishable=false` e `issued_statement=false`, incluso para VALID. Los métodos de revisión/retirada rechazan precondiciones inválidas mediante DomainException; no realizan efectos externos.

## 3. Campos admitidos y restricciones

Todos los objetos tienen whitelist cerrada y campos obligatorios comprobados con presencia real, no mediante valores por defecto.

| Nivel | Campos y contrato técnico |
| --- | --- |
| Identidad | `contract=financial-presentation-v1`, `canonicalizer=financial-presentation-canonical-v1`, `family_uuid` UUID v4 canónico en minúsculas, `version_number` entero positivo, `label` texto. |
| Procedencia | `purpose=simulation`, `origin=synthetic_fixture`, `installation_scope=TEST-FIXTURE-ONLY`. Business/administrative u otra instalación se rechazan. |
| Preparación | `author` identificador ficticio `SYNTHETIC:<clave>`, `prepared_at` UTC. No acepta IDs de usuarios reales como identidad autenticada. |
| Vigencia | `valid_from` UTC y `valid_until` UTC o NULL **explícito**. La ausencia del segundo campo es inválida. |
| Contenido | Listas `groups`, `assignments`, `profiles`, `pending_decisions`. No incluye asientos, saldos, fórmulas o autorizaciones. |
| Opcionales | `reviewer` NULL o identificador SYNTHETIC; `reference` NULL o texto con prefijo `SYNTHETIC:`. Ausencia y NULL tienen hashes diferentes. |
| Grupo | `key`, `parent` NULL o clave existente, `basis`, `order` entero no negativo, `label`. |
| Asignación | `account_id` entero positivo, `group_key`, `order`, `corrector` booleano; `label` opcional nullable. |

Las claves de grupo son `[a-z][a-z0-9_]{0,63}`. Textos significativos deben contener contenido no vacío, medir como máximo 512 bytes y carecer de controles ASCII; no se recortan ni transforman. Los floats se rechazan en cualquier posición, no solo en IDs. Objetos, recursos, claves ambiguas y estructuras con más de 32 niveles también se rechazan.

Las identidades SYNTHETIC son etiquetas de hipótesis: **no prueban procedencia administrativa o identidad autenticada**. No pueden utilizarse como mecanismo de autorización real. El componente tampoco recibe PIN, tokens, contraseñas o hashes de credenciales; tales campos desconocidos son inválidos.

## 4. Grupos, jerarquía y signos

El contrato de ensayo exige las siete raíces ya propuestas en B3B0; no inventa una categoría para completar cuentas faltantes:

| Raíz | Base permitida |
| --- | --- |
| asset | debit_minus_credit |
| liability | credit_minus_debit |
| equity | credit_minus_debit |
| income | credit_minus_debit |
| cost | debit_minus_credit |
| operating_expense | debit_minus_credit |
| other_expense | debit_minus_credit |

Se permiten subgrupos declarados con padre existente. Un recorrido verifica ausencia de ciclos, incluidos autorreferencia y ciclos desconectados; cada hijo debe conservar exactamente la base de su padre. Las raíces no pueden cambiar de base ni duplicarse. La jerarquía es exclusivamente de presentación; no recibe padres del catálogo ni bindings.

Una cuenta tiene una única asignación en el contenido, incluso si dos asignaciones repetidas fueran iguales. El grupo debe existir. Corrector es una anotación booleana: no existe multiplicador ni base alternativa por cuenta. El validador no calcula saldos ni convierte contribuciones negativas en positivas. La prueba de subgrupo corrector conserva la base H−D y rechaza cambiarla a D−H.

`order` conserva el orden visual explícito. Los empates se pueden desambiguar determinísticamente por clave de grupo o ID de cuenta; no dependen del orden accidental de la lista. No hay campos de fórmula, PHP, SQL, coeficientes o expresiones evaluables. Un texto descriptivo nunca se evalúa como código.

## 5. Canonicalización y SHA256

Regla independiente y versionada: `financial-presentation-canonical-v1`.

1. Validar recursivamente UTF-8 y tipos antes de interpretar el contrato. Rechazar floats/objetos/recursos y profundidad excesiva.
2. Rechazar duplicados y referencias inválidas **antes** de ordenar.
3. Normalizar únicamente timestamps UTC admitidos: segundos exactos en `YYYY-MM-DDTHH:MM:SSZ` o `+00:00`; ambas representaciones producen Z. Otros offsets, segundos fraccionarios, leap seconds y fechas inválidas se rechazan.
4. Ordenar grupos por key, asignaciones por account_id, perfiles por report y conjuntos de estados, IDs y decisiones por su valor estable. El campo order sigue siendo contenido significativo.
5. Ordenar recursivamente claves de objetos mediante orden de cadenas; conservar listas. Codificar con JSON_THROW_ON_ERROR, JSON_UNESCAPED_UNICODE y JSON_UNESCAPED_SLASHES.
6. Calcular SHA256 del JSON resultante. Devolver también el array canónico con ese mismo orden de claves.

No se incluye el propio hash: `hash`, `content_hash` y otras claves no previstas se rechazan como entrada. El hash no depende del reloj, la base, un usuario conectado o una configuración global. No es una firma ni aprobación del contador.

Se preservan NULL frente a ausencia, mayúsculas, espacios significativos y composición Unicode. `é` y `e` con acento combinante permanecen distintos. No se convierten strings numéricos en enteros: account_id="900001" es inválido.

Este contrato **no contiene cantidades ni importes monetarios**. No se agregó un umbral de materialidad o monto para justificar una política nueva. El canonicalizador preserva strings decimales exactos como "0.10" y "9999999999999.99" sin float; sus pruebas comprueban ese comportamiento. Esto no constituye una validación de importes financieros para un escritor: introducir un campo decimal económico requerirá definir su escala/formato y autorizar esa extensión. No se redondea ni se normaliza un importe inexistente en el contrato.

Limitación de entrada: los arrays PHP ya no conservan claves JSON duplicadas eliminadas por un parser. No se afirma detectar ese problema en JSON crudo. Una futura frontera de parsing deberá rechazar duplicados antes de producir estos arrays.

## 6. Perfiles documentales diferenciados

Se exige exactamente un perfil result y uno balance. Cada perfil declara:

`report`, `from`, `until`, `states`, `null_origin`, `closing`, `period_id`, `center_scope`, `account_scope`, `scope_label`, `base_currency`, `amount_basis`, `inactive_history`.

| Dimensión | Validación |
| --- | --- |
| Resultados | from/until válidos, rango no invertido; closing=exclude. period_id NULL o entero positivo. No se obliga a mes completo porque no se conecta al comparador legado. |
| Balance | from=NULL, period_id=NULL, corte until válido y closing=include. |
| Estados | Lista explícita, no vacía, sin duplicados; borrador/contabilizado/anulado son vocabulario técnico, no una selección empresarial aprobada. |
| Origen NULL | include/exclude explícito. No se inventa un origen. |
| Centros | all, unassigned o lista no vacía de IDs únicos positivos. |
| Cuentas | all o lista no vacía de IDs únicos positivos. |
| Alcance | global solo si ambos scopes son all; cualquier filtro parcial exige scope_label=partial. |
| Moneda/base | NIO y original_ledger_base: no moneda nativa bancaria ni conversión actual. Es contrato del prototipo de lectura existente. |
| Inactivas | inactive_history=include. No excluir historia por desactivación. |

Los perfiles no crean ni validan existencia SQL de períodos/centros/cuentas. No reconstruyen reversas o devoluciones: esa selección pertenece a futuros lectores. Los contratos de resultados y balance conservan la diferencia sobre cierres; no se introduce un filtro universal.

Las fechas contables del perfil y la vigencia de selección son ejes distintos. El validador no exige que un reporte histórico esté dentro de la vigencia, ni selecciona automáticamente una versión actual para ese corte. Esa decisión permanece pendiente.

## 7. Vigencia, revisión y retirada simuladas

La vigencia es UTC **[desde,hasta)**. Se rechaza hasta menor o igual que desde; NULL significa intervalo abierto explícito. `withinValidity` solo comprueba pertenencia temporal técnica, sin resolver una política empresarial ni atribuir autorización.

El contenido canónico está separado del estado documental. `revise` exige state=draft y hash esperado igual al contenido actual. Una sustitución inválida, hash obsoleto o cambio de identidad/procedencia del mismo borrador se rechaza. Si cambia contenido significativo, devuelve otro hash y `previous_review_valid=false`; esa bandera indica exclusivamente coincidencia de contenido en memoria, **no una aprobación previa real**. Si el contenido no cambia, mantiene el hash.

Todo estado distinto de draft impide editar, incluyendo el escenario conceptual published_simulation probado. No existe método de publicación, firma o creación de aprobaciones. La inmutabilidad es una precondición del helper en memoria, no una garantía persistente contra un administrador o un cliente que falsifique estado; una futura persistencia deberá obtener estado/hash desde almacenamiento confiable.

`withdrawal` produce un **evento separado de simulación** ligado al hash esperado, referencia SYNTHETIC y timestamp UTC explícito. No modifica vigencia o contenido, no genera IDs de aprobación ni recalifica una versión. Su timestamp es un dato del fixture; no se presenta como fecha auténtica de un acto registrado por servidor. La consulta histórica se demuestra revalidando el contenido original sin cambios después de construir el evento.

## 8. Tres clases de resultado

| Estado | Significado y límites |
| --- | --- |
| VALID | Estructura técnicamente válida dentro de la hipótesis; sin incidencias del universo de fixture proporcionado y sin decisiones marcadas como pendientes. No prueba legitimidad económica, cuenta existente o aprobación real. |
| INVALID | Violación técnica reproducible; código de error estable, sin hash o contenido canónico utilizables. Se informa el primer error en orden de validación, sin SQL/trazas. |
| REQUIRES_ACCOUNTING_APPROVAL | Estructura válida, pero cobertura no verificada, cuentas relevantes no cubiertas o decisiones explícitamente pendientes. Conserva hash y diagnóstico; no habilita publicación. |

`pending_decisions` admite únicamente taxonomy, universe, governance, temporal_selection y currency_basis, sin duplicados. La lista vacía es una declaración de un fixture, no prueba de aprobación económica. El validador no decide si una cuenta es costo o gasto ni si sus metadatos reales contradicen el grupo.

El universo opcional `relevantAccountIds` permite comprobar cobertura en memoria. NULL genera `coverage.not_verified`; una lista explícita de IDs faltantes genera `coverage.missing_accounts`, ordenada determinísticamente. No se completa mediante prefijos, nombres, naturaleza o primer binding. Un universo proporcionado por el llamador no demuestra que incluya toda la historia de MySQL; esa garantía no existe en esta fase.

## 9. Pruebas puras definitivas

Comandos focalizados ejecutados con PHP 8.3.30, sin configuración PHPUnit de la aplicación, sin Laravel y con PDO/mysqli deshabilitados en el proceso:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d disable_classes=PDO,mysqli -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result --no-progress tests/Unit/FinancialPresentationContractTest.php

& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d disable_classes=PDO,mysqli -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result --no-progress tests/Unit/FinancialPresentationContractTest.php tests/Feature/AccountingPresentationTest.php
```

| Grupo | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| FinancialPresentationContractTest | 71 | 270 | Aprobado |
| AccountingPresentationTest, regresión pura existente | 26 | 61 | Aprobado |
| **Ejecución conjunta definitiva** | **97** | **331** | **Aprobado** |

No se suman reejecuciones. La primera ejecución detectó una diferencia en el orden de claves del array devuelto, aunque JSON y hash ya eran iguales. Se corrigió la salida canónica; se conservó la expectativa de igualdad estricta. Después se ampliaron entradas malformadas y revisión documental; la batería final pasa sin cambiar expectativas económicas históricas.

Cobertura: contrato desconocido, campos adicionales, propósito/procedencia incorrectos, duplicados, grupo/padre inexistentes, ciclos y autociclo, herencia de signo, correctora sin multiplicador, parámetros de fórmulas, IDs ambiguos, orden equivalente, cambios significativos, ausencia frente a NULL, fechas/UTC/vigencia, floats, strings decimales exactos, UTF-8, referencias, revisión obsoleta, identidad/freeze, retirada separada, perfiles incompatibles, cierres diferenciados, scopes parciales, incompletitud y ausencia de autoridad. También se prueban arrays anidados donde se exige escalar y ausencia de normalización Unicode/textual.

Lint `php -n -l` de los cuatro PHP nuevos: aprobado. `git diff --check`: aprobado. No se ejecutaron suites generales, SQL, Artisan, migraciones, Composer test, seeders o carreras persistentes. Ninguna prueba hereda Tests\TestCase o utiliza DatabaseTransactions/RefreshDatabase. No se creó configuración PHPUnit nueva.

## 10. Compatibilidad y ausencia de efectos

No se modificaron SyntheticAccountingPresentation, AccountingReportExtractor, AccountingPresentationComparison, demo Blade/router, cierre fiscal, ContabilidadService, AC-03, catálogo, bancos, inventario, nómina, permisos o migraciones. No existe consumidor de aplicación del validador nuevo; solo los fixtures/tests lo invocan.

El contrato financial-presentation-v1 sigue separado de synthetic-presentation-v1. No se pasan grupos o identidades nuevos a los reportes existentes; el componente no calcula estados financieros. La regresión pura comprueba que AC-05B1 conserva sus signos y referencias. La compatibilidad B2/B3A es por ausencia de modificaciones y de integración, **no una nueva ejecución SQL/HTTP de esos módulos**.

Las tres bases no fueron accedidas en esta fase. No se vuelve a certificar su esquema 82 o sus hashes de filas: las verificaciones SQL previas pertenecen a los antecedentes. No hubo DDL/DML, respaldo nuevo, restauración, aprobación, clasificación persistente o estado emitido. El preparado131 y la evidencia AC-04 no se utilizaron como fixtures. SALON_TEST_DB_RESET_ALLOWED permanece en cero, sin editar configuración.

## 11. Archivos y revisión Git

Únicos archivos propios nuevos:

```text
app/Services/FinancialPresentationContractValidator.php
app/Support/FinancialPresentationCanonicalizer.php
tests/Support/FinancialPresentationContractFixtures.php
tests/Unit/FinancialPresentationContractTest.php
docs/AC_05B3B1_VALIDADOR_CONTRATO.md
```

Se conserva como pendiente previo `docs/AC_05G1_COMMIT_CONSOLIDADO.md`, fuera del índice. HEAD sigue en el commit consolidado, sin cambios de rama, commits nuevos, staging o push. Los archivos rastreados previos no tienen diff. Al ser archivos nuevos, `git diff` ordinario no representa su contenido; revisar íntegramente estos cinco paths antes de cualquier integración futura.

## 12. Riesgos, reversión y siguiente alcance

Pendientes: definición empresarial de grupos/subgrupos y asignaciones por cuenta, universos, gobierno/revisores reales, vigencia para cortes históricos, moneda/base y archivo de ediciones. La aprobación de un ensayo no resuelve esas decisiones. No se certifica capacidad de grandes catálogos o seguridad de un parser HTTP; no existe tal entrada.

La codificación declara límites/tipos y whitelist, pero no reemplaza autenticación, integridad referencial, procedencia durable, concurrencia o permisos de una futura persistencia. No se simulan esos controles mediante IDs ficticios.

Reversión de esta fase, si se autoriza: retirar únicamente sus cinco archivos nuevos tras revisar dependencias posteriores; conservar el informe G1, código consolidado y evidencia privada. No hay datos, migración, política o documento financiero que revertir. No ejecutar reset/clean, restauraciones o compensaciones.

Recomendación para una **futura autorización separada**: persistencia exclusivamente sintética de borradores con hash esperado, procedencia no promocionable y auditoría; revisar esquema, FK, congelación y retirada antes de DDL. Una posterior publicación empresarial y sustitución de reportes seguirán requiriendo autorización y decisiones económicas propias. No se implementa esa persistencia aquí.

AC-05B3B1 termina con contrato técnico puro. No continúa a migraciones, publicación, sustitución de reportes, AC-06, H01-B2B o despliegues.
