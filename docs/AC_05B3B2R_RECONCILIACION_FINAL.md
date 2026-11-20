# AC-05B3B2R — Reconciliación final de preflights y esquema

Fecha: 20 de noviembre de 2026. **Estado: completada como reconciliación de pruebas y lecturas en salon_erp_test.** No autoriza publicación, emisión, clasificación empresarial o cambios financieros. Sin DDL, INSERT, UPDATE o DELETE; sin commits ni despliegue.

## 1. HEAD, árbol y diagnóstico preservado

Rama inicial/final: `feature/panel`. HEAD inicial/final: **`07daff20e8abf55a5c49d1dda865211e27c21633`**, asunto `Implementacion de ac5`, padre `330ebd00b8ca2f873e04eed9e20f8e41207c5d31`.

Se revisó el commit externo:21 paths,1.858 inserciones y2 eliminaciones. Integra el validador/canonicalizador B1, fixtures/pruebas B1, repositorio y esquema test-only B2, auxiliares y pruebas de persistencia/concurrencia, ampliación B2 del respaldo y documentos B1/B2/G1. No se repitió, revirtió ni reescribió ese commit. Sus servicios mantienen los contratos esperados y no tienen diff en esta continuación.

El índice estaba vacío; único pendiente inicial: `docs/AC_05B3B2R_RECONCILIACION_ESQUEMA.md`. Se conserva íntegramente, sin reescribir su diagnóstico o atribuirle retrospectivamente pruebas SQL:

SHA256 del diagnóstico: `8014488d3d3e6888f4d1f2c81b429ee0527036c43c2b9d0f9fd4b6471fcd767f`.

No hubo nuevos avances de HEAD durante esta continuación. Los únicos archivos rastreados modificados son los dos auxiliares de respaldo/runner descritos abajo. No se preparó el índice.

## 2. Diferencia respecto al diagnóstico

El diagnóstico había identificado los rechazos89/91 mediante inspección, sin SQL ni implementación. La aclaración posterior permitió completar ahora:

1. Verificación SQL restringida y comparación con evidencia B2 antes de crear otro baseline.
2. Respaldo nuevo de las91 tablas.
3. Perfiles cerrados de estructura histórica y actual.
4. Selección explícita del perfil actual en modos lectores delimitados.
5. Baseline nuevo R y comparación de preservación91→91.
6. Pruebas puras de rechazo y prueba HTTP lectora existente.

No se cambiaron los baselines89, el comparador de transición B2 ni el diagnóstico original.

## 3. Identidad SQL y estado vivo inicial

Antes de generar perfiles/baseline se verificó mediante los auxiliares controlados:

- PHP8.3.30; MySQL8.4.3; puerto3306; raíz del proyecto y bootstrap de test.
- Base exacta `salon_erp_test`, cuenta efectiva `salon_erp_tester@localhost`, roles SQL NONE.
- Grants exactos existentes: USAGE global y SELECT/INSERT/UPDATE/DELETE/CREATE/DROP/ALTER/INDEX/REFERENCES solo sobre test. No se cambiaron privilegios.
- SELECT a `salon_erp.users` y `salon_erp_bootstrap_check.users` rechazados con1142.
- SALON_TEST_DB_RESET_ALLOWED=0, conservado y restablecido mediante finally.
-82 migraciones por nombres exactos, cotejadas con los archivos existentes;91 tablas por nombre.
- DDL completo de todas las tablas coincide con la evidencia B2 aceptada, incluidas columnas, índices, FK, CHECK, motores y contadores. No se basó la autorización solo en COUNT.
-42 cabeceras y48 auditorías existentes; cadenas verificadas mediante lecturas READ ONLY y validador B1, sin invocar revisiones del repositorio.
- Preparado131: `prepared`, banco221, resultado de movimiento/asiento NULL.

La inspección administrativa independiente usa READ ONLY para comparar las tres bases. Es el mecanismo local de lectura previamente autorizado, **no una conexión alternativa del extractor o de un escritor**. El acceso de prueba restringido sigue sin poder leer desarrollo/bootstrap.

`ac05b3b2r.php inspect` comparó todas las filas y DDL vivos con `ac05b3b2-isolation-after.json`; la igualdad fue exacta. Solo después guardó `storage/app/private/ac05b3b2r-verified-source.json`. Una diferencia habría impedido compilar perfiles o crear un baseline que la legitimara.

## 4. Respaldo nuevo91

El respaldo B2 anterior también se verificó por tamaño y SHA256 y se conservó. Se añadió una fase nueva AC05B3B2R al auxiliar existente, con controles adicionales para este perfil:91 nombres exactos, DDL igual a la evidencia recién verificada,1.963 filas,42 cabeceras y48 auditorías.

| Elemento | Resultado |
| --- | --- |
| Archivo nuevo | `E:/salon-erp-backups/salon_erp_test_AC05B3B2R_20261120_092818_0b7b605d017b.sql` |
| SHA256 | **`15fc93be213a2e54450cd0c8034ab2e86191a1bfdf713ad013b04ac640df7eef`** |
| Tamaño | **1.777.958 bytes** |
| Contenido |91 tablas,82 migraciones,1.963 filas |
| Manifiesto privado | `storage/app/private/ac05b3b2r-backup.json` |
| Sidecar | `.sql.sha256` junto al dump |

Código de salida0; exportación single-transaction; número de CREATE TABLE y marcador final verificados; sin CREATE DATABASE/USE; fuente idéntica antes/después de exportar. Tamaño, SHA256 y sidecar comprobados nuevamente al habilitar el perfil actual. Se conserva el aviso conocido de mysqldump con skip-password; no se suministró una credencial en argumentos.

El sandbox denegó inicialmente reservar el archivo externo. Se solicitó permiso y el mismo auxiliar completó el respaldo con nombre único. No se realizaron pruebas dependientes del nuevo baseline hasta obtenerlo. No se sobrescribió ningún respaldo/manifiesto ni se restauró una base; **restaurabilidad no ensayada**.

## 5. Perfiles implementados y fundamento de huellas

`AccountingTestSchemaProfiles` y el manifiesto de estructuras `tests/Support/fixtures/ac05-schema-v1.json` definen:

| Perfil | Tablas permitidas | Migraciones |
| --- | --- | ---: |
| `AC05_ORIGINAL_89_V1` | Lista explícita de las89 originales |82 exactas por nombre |
| `AC05_SYNTHETIC_91_V1` | Las mismas89 más las dos test_ autorizadas |La misma lista82 |

Tablas adicionales únicas:

```text
test_financial_presentation_draft_audits
test_financial_presentation_drafts
```

El manifiesto incluye nombres, definiciones SHOW CREATE aprobadas, huella completa, huella de columnas, huella de índices/FK/CHECK, migraciones, identidad y grants. No contiene datos de filas, credenciales o un catálogo financiero nuevo. Es evidencia de esquema de ensayo.

SHA256 fijo del manifiesto: **`0c139a1fc6627958f3827c9062e76bf6a3685b67eb731e379c78b872202a4dfc`**. La clase comprueba ese hash antes de usarlo. No aprende automáticamente un esquema vivo ni acepta reemplazar una tabla manteniendo el total91.

La compilación fue explícita y única: tomó metadatos cuya concordancia con el baseline B2 aprobado ya se había demostrado, separó originales según el before89 previo, cotejó los DDL con el after91 aprobado y exigió respaldo R válido. El compilador no consulta una base arbitraria, reserva el archivo fuente con `xb` y no sobrescribe una definición existente. Las huellas quedan fijadas en código/fixture revisables.

Solo se normaliza el contador AUTO_INCREMENT de la opción final de tabla InnoDB para comprobar **estructura**. La expresión está anclada a esa opción: no elimina texto similar dentro de defaults o comentarios de columnas. Una prueba específica preserva ese texto significativo. El comparador de **preservación** no aplica esta normalización: exige DDL completo idéntico, incluidos los contadores.

## 6. Auxiliares adaptados y selección explícita

El runner `run-ac04b1-tests.php` admite un segundo argumento de perfil. Sin argumento conserva la referencia histórica89; no acepta91 silenciosamente.

Ejemplo lector autorizado:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-ac04b1-tests.php reports-preflight AC05_SYNTHETIC_91_V1
```

El perfil91 solo se permite para:

```text
reports-preflight
closing-preflight
extract-preflight
report-demo-preflight
report-demo-http
```

Para esos modos se comprueban estructura íntegra y respaldo R nuevo. Se conservan los controles anteriores de identidad, grants, denegación de otras bases, migraciones, InnoDB, DECIMAL(15,2) y preparado131. La conexión de metadatos debe ser restringida y READ ONLY; el lector es dueño de su snapshot y rechaza una transacción exterior.

| Área anterior | Tratamiento en R |
| --- | --- |
| AC-05B1 | Preflight lector acepta91 explícito; pruebas puras no requieren SQL. No se habilita reports-core o reports-bank mediante el perfil actual. |
| AC-05C0 | closing-preflight puede verificar91; no se ejecuta closing-core ni se cambia el cierre. |
| AC-05B2 | extract-preflight acepta91; lectura del extractor real comprobada desde el test HTTP existente. No se ejecutan extract-core ni el worker extract-race. |
| AC-05B3A | Preflight y prueba HTTP solo lectura admiten91 explícito. Sus vistas/router/servicios no cambian. |
| AC-05B3B1 | Contrato puro sin adaptación SQL; regresiones repetidas sin PDO. |
| AC-05B3B2 | Bootstrap, preflight, repositorio e instalador anteriores intactos; el wrapper R añade el control estricto de estructura y lectura de cadenas. |
| AC-04 | Modos/versiones anteriores conservados, sin habilitación genérica del perfil91. No se repiten carreras ni instalaciones. |

Los modos con fixtures o escritores no obtienen permiso por seleccionar otro perfil. La selección actual se rechaza para install, refund-install, pilot-core, reports-bank, reports-core, extract-race y closing-core, entre otros. Los modos históricos no necesarios pueden seguir rechazando la instalación ampliada; no se alteran para que sus pruebas pasen.

## 7. Controles históricos que no se cambiaron

- El perfil89 mantiene89 como universo exacto; la ejecución real sin selección explícita sobre91 falla de forma esperada.
- `ac04b1-isolation.php` sigue interpretando sus baselines históricos. No se le añade retrospectivamente la evidencia B2.
- `ac05b3b2-isolation.php` sigue representando la transición89→91 original, con sus archivos exclusivos intactos.
- El instalador B2 conserva preflight89 antes de DDL; no se ejecutó sobre91. Su precondición y el rechazo de perfiles incompatibles se revisaron/ensayaron sin invocar DDL.
- `ac05b3b2-final.php` no se repitió; su manifiesto original se conserva. La nueva verificación R es lectora y usa nombres de evidencia propios.
- No se modificaron las82 migraciones ni sus registros.

## 8. Comparación nueva91→91 y cadenas

`ac05b3b2r.php before/after` crea evidencias nuevas, identificadas con `AC05_SYNTHETIC_91_V1`, sin sobrescribir las anteriores. `assertPreserved` exige perfil congruente, nombres de tabla correctos, evidencia de filas/DDL válida,42/48 y cadenas íntegras; después compara el estado completo sin normalización.

Baselines privados:

```text
storage/app/private/ac05b3b2r-isolation-before.json
storage/app/private/ac05b3b2r-isolation-after.json
```

Ambos tienen el **mismo SHA256**:

`204e933b35c93c4fa3826e00f92f8de930ae43ce42b31bf28498e43a46521158`.

La igualdad es byte a byte, además de la comparación de arrays: mismas filas serializadas por hash y mismo DDL completo para las tres bases. No se permitieron altas persistentes o transitorias ni se restauraron secuencias.

Las42 cabeceras y48 auditorías se leen en un snapshot READ ONLY. Se reutiliza el validador B1 para verificar canonical_json/hash, identidad, simulation, procedencia del arnés, autor técnico, familia/versión, revisión consecutiva, before/after, evento de creación, UUID y última auditoría. Se detectan huérfanos o saltos. No se usa el getter con locks del repositorio ni se modifica su contrato transaccional.

Preparado131 permanece prepared, banco221 y resultados NULL. Huella del registro leído en sesión UTC, igual antes/después:

`3167cc4bbdb3ccaa58fbc454634f1431ca20fc3281e228d20c4f5ce791f87a52`.

## 9. Pruebas puras y lectoras definitivas

| Grupo | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| AccountingTestSchemaProfilesTest |29|60|Aprobado |
| ReportPresentationHttpTest, lector existente B3A |1|57|Aprobado con perfil91 |
| Validador B1 + frontera pura de borradores + clasificador previo |98|333|Aprobado |
| **Total definitivo** | **128** | **450** | **Aprobado** |

No se suman ejecuciones intermedias. La ampliación final del normalizador se probó con su caso adicional y se volvió a verificar el perfil contra metadatos vivos. El refuerzo final de identidad/READ ONLY del lector de metadatos también se comprobó mediante preflight real, sin cambiar el resultado del lector financiero.

Las pruebas de rechazo mutan **solo arrays en memoria**: tabla desconocida, sustitución con91 nombres, columna, tipo, índice, FK, CHECK, motor, migración, base/usuario/puerto/roles/grants, tabla sintética extra, baseline/perfil incongruente, hashes de filas, tabla original, borrador, auditoría, documentos nuevos y DDL/AUTO_INCREMENT alterado. Se verifica que cada fixture modifique realmente sus metadatos antes de esperar el rechazo. No hubo ALTER, UPDATE de prueba o fixtures SQL.

Las puras se ejecutaron con vendor/autoload.php, `--no-configuration` y PDO/mysqli deshabilitados. El test HTTP fue inspeccionado antes: consulta historia y usa sesiones/cache en archivos; no crea documentos, actualiza usuarios o inicia escritores financieros. Verifica la comparación MySQL READ ONLY, fixtures offline, sesión/CSRF, límites de rutas y preservación de sus tablas leídas. Los baselines completos verifican el resto.

Los cuatro preflights lectores con selección91 aprobaron. El preflight histórico por defecto rechazó91 con exit1, **resultado negativo esperado**, no bloqueo de implementación. No se ejecutó el instalador para comprobar ese rechazo.

No se ejecutaron suites generales, Composer test, seeders, DatabaseTransactions, RefreshDatabase, migrate:fresh, db:wipe, workers de snapshot, carreras antiguas o nuevos INSERT, ni siquiera revertibles. Lint de los siete PHP nuevos/modificados: sin errores. Diff check sin errores; se conservaron avisos CRLF/LF de los dos auxiliares Windows.

## 10. Comandos de validación reproducibles

Estas comprobaciones no generan fixtures SQL:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d disable_classes=PDO,mysqli -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result --no-progress tests/Unit/AccountingTestSchemaProfilesTest.php

& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d disable_classes=PDO,mysqli -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result --no-progress tests/Unit/FinancialPresentationContractTest.php tests/Unit/SyntheticPresentationDraftBoundaryTest.php tests/Feature/AccountingPresentationTest.php

& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-ac04b1-tests.php report-demo-http AC05_SYNTHETIC_91_V1

& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/ac05b3b2r.php preflight
```

Los modos inspect/before/after, el compilador y el respaldo R reservan sus archivos con creación exclusiva. **No repetirlos ciegamente sobre evidencias ocupadas** ni borrar archivos para conseguir una nueva ejecución. Preflight es la vía reutilizable de consulta; nuevos baselines requieren otra identidad documental autorizada.

## 11. Estado final de las tres bases

| Base | Tablas antes/después | Migraciones antes/después | Filas antes/después |
| --- | ---: | ---: | ---: |
| salon_erp |78/78|74/74|225/225 |
| salon_erp_test |91/91|82/82|1.963/1.963 |
| salon_erp_bootstrap_check |78/78|74/74|202/202 |

Coinciden íntegramente todas las filas y DDL de las tres bases, incluidos los AUTO_INCREMENT, las89 tablas originales y las dos test_. Los42 borradores y48 auditorías conservan exactamente sus registros; no hay cabeceras, auditorías o documentos financieros nuevos. El estado también sigue coincidiendo con el cierre B2 aprobado, comprobado nuevamente después de las verificaciones finales.

No se modificaron catálogo, asientos, períodos, políticas, autorizaciones, bancos, conciliaciones, inventario o nómina. No hubo DDL ni migración. SALON_TEST_DB_RESET_ALLOWED sigue en0 y no se cambiaron archivos de credenciales/configuración, APP_KEY, permisos o dependencias.

## 12. Bloqueos resueltos y límites

El único impedimento de ejecución fue la escritura del respaldo externo denegada por sandbox; se resolvió mediante permiso, sin cambiar la ubicación ni eludir guardias. No hubo discrepancia del esquema vivo que exigiera reparación.

Los perfiles son específicos de la estructura aprobada y MySQL8.4.3. Cambios legítimos futuros requieren revisar y versionar sus huellas; no añadir un fallback o regenerarlas automáticamente. La normalización estructural de AUTO_INCREMENT no autoriza INSERT ni relaja la comparación de filas/DDL completo de R.

Los helpers no son un nuevo sistema de autorización económica. Tampoco convierten el usuario técnico de ensayo en aprobador real ni prueban restaurabilidad del respaldo. La lectura consistente no equivale a una edición financiera emitida o a una prueba concurrente nueva de escritores.

## 13. Archivos propios de esta continuación

Nuevos:

```text
tests/Support/AccountingSchemaInspection.php
tests/Support/AccountingTestSchemaProfiles.php
tests/Support/ac05b3b2r.php
tests/Support/ac05b3b2r-build-profile.php
tests/Support/fixtures/ac05-schema-v1.json
tests/Unit/AccountingTestSchemaProfilesTest.php
docs/AC_05B3B2R_RECONCILIACION_FINAL.md
```

Modificados mínimamente:

```text
tests/Support/ac04b1-backup.php
tests/Support/run-ac04b1-tests.php
```

El diagnóstico original continúa como pendiente previo, sin modificaciones. Los componentes B1/B2 y los servicios/reportes prohibidos no tienen diff respecto a07daff20. Tampoco se modificaron ac04b1-isolation, los baselines históricos o los instaladores B2. Datos de filas, baselines y respaldos siguen privados/ignorados; el JSON nuevo de esquema contiene definiciones, no datos financieros.

Revisar los dos diffs rastreados y los siete archivos nuevos completos. No se creó un commit o parche que mezcle el diagnóstico previo con esta continuación. Índice vacío, mismo HEAD.

## 14. Reversión segura

Si se autoriza retirar esta reconciliación, revisar únicamente sus siete archivos nuevos y las adiciones R de los dos auxiliares; conservar el commit externo, el diagnóstico original y todos los archivos B1/B2. Retirar el selector actual devuelve el rechazo conservador de los preflights89 sobre91; no cambia los datos.

No hay tabla o migración R que revertir. No ejecutar DROP, restauración, borrado de borradores, reset, clean, stash o modificación de contadores. Conservar dump, sidecar, manifiestos y baselines R como evidencia, aunque se retire código. No sobrescribir los perfiles fijados para aparentar compatibilidad con un esquema distinto.

## 15. Cierre y recomendación

AC-05B3B2R queda completada: perfil histórico preservado, perfil91 explícito y estricto para lecturas, respaldo actualizado y preservación completa91→91 demostrada. La próxima intervención deberá tener su propio alcance y evidencia; para simples comprobaciones puede reutilizar el preflight lector, sin habilitar escritores o repetir instalaciones.

No se inicia publicación o retirada empresarial, emisión, sustitución de reportes, AC-06, H01-B2B o despliegues.
