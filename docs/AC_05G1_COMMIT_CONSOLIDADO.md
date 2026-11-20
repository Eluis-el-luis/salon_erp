# AC-05G1 — Commit local consolidado de AC-05

Fecha: 20 de noviembre de 2026. Resultado: **un único commit local creado y verificado**. Este informe se escribió después del commit y queda sin seguimiento ni staging, fuera de sus 29 paths. No autoriza ninguna fase posterior ni despliegue.

## 1. Rama, HEAD e índice inicial

| Comprobación | Resultado |
| --- | --- |
| Rama inicial y final | `feature/panel` |
| HEAD anterior | `9f34037befe0e7bb9730955b3c1b547ad032fb29` |
| Asunto anterior | `AC 4BAC, y A5` |
| Índice inicial | Vacío, sin cambios staged de terceros |
| SHA256 inicial del índice | `a13318f8fbd0bb0a6125e559e4944d92088af0b882da6be538343667566e41f4` |
| Pendientes iniciales | 4 rastreados modificados y 25 nuevos; exactamente la whitelist G0 |
| HEAD final | `330ebd00b8ca2f873e04eed9e20f8e41207c5d31` |
| Padre del commit | Exactamente el HEAD anterior |

Se volvió a comprobar el estado real; no se asumió que siguiera igual al informe G0. No se detectaron paths adicionales, candidatos ausentes, cambios staged ajenos o discrepancias de contenido. No se reconstruyeron estados intermedios de las fases.

## 2. Whitelist y hashes

Fuente exclusiva de autorización: sección 7 de `docs/AC_05G0_PREPARACION_GIT.md`. Se extrajeron sus 29 rutas explícitas y se verificó su unicidad, presencia y correspondencia con todo el estado pendiente.

- Los **28 SHA256 de bytes** de la sección 8 de G0 coinciden antes de staging y después del commit. Ese manifiesto conserva el detalle por archivo, sin sobrescribirlo.
- G0 no contiene su propio hash por autorreferencia. Su SHA256 calculado en G1 es **`792d5e04e49f2172a89feacc4b75495c2f64a5f6ef3beace6d91e1b2701b85bb`**, igual antes y después de la integración.
- Los **29 blobs staged** coincidieron con `git hash-object --path=<path> -- <path>` de los archivos revisados. No se usó `-w` para esa comprobación.
- Se distinguieron hashes de bytes y blobs normalizados por Git. Los avisos CRLF→LF de los tres auxiliares existentes son compatibles con `.gitattributes`; no se editaron los archivos para normalizarlos.

Se verificaron nuevamente rama, HEAD, whitelist y coincidencia de blobs inmediatamente antes de confirmar. No hubo restauraciones automáticas para lograr coincidencia.

## 3. Revisión del contenido y del índice

Se inspeccionaron los archivos nuevos completos y los diffs de los cuatro rastreados, incluyendo PHP, Blade, JavaScript, pruebas, auxiliares y documentación. La revisión del diff staged completo abarcó 29 archivos y 3.678 líneas de diff, además de comprobar la identidad de sus blobs con el contenido revisado.

| Área | Conclusión de revisión estática |
| --- | --- |
| Cierre fiscal AC-05C0 | Corrección prospectiva por centavos y lado no negativo, límites y captura de Throwable. Conserva selección 4–7, destino 3.2, período y transacción; sin cambio de balanceGeneral. |
| Clasificador | Asignación sintética explícita por ID, signos y centavos, rechazo de cobertura incompleta y contribución única por detalle. |
| Extractor/comparador | Snapshot lector, modalidad de fixture declarada, límites de volumen y diagnóstico por grupo/cuenta/detalle. No invocan escritores financieros. |
| Demo | Router independiente 8778, permisos contables activos, sesión propia, CSRF y SQL READ ONLY. Sin rutas ordinarias nuevas ni autoridad de contabilización. |
| Pruebas/auxiliares | Corresponden a las fases documentadas; worker de snapshot rollback-only, fixtures transaccionales y arnés de consulta. No se ejecutaron durante G1. |
| Documentos | Conservan procedencia, diferencias entre simulación y aprobación económica y límites de sus resultados previos. |

Preparación mediante `git add --` con los 29 argumentos explícitos de la whitelist. No se usaron add global, add forzado o commit con inclusión automática de cambios.

Comprobaciones del índice aprobadas:

1. `git diff --cached --name-status`: exactamente 25 altas y 4 modificaciones autorizadas.
2. Igualdad de conjuntos entre índice y whitelist, sin rutas adicionales.
3. `git diff --cached --check`: sin errores.
4. `git diff --cached --stat`: **29 archivos, 3.404 inserciones y 23 eliminaciones**.
5. Blobs staged iguales a los archivos revisados y ausencia de delta posterior en el árbol.
6. Sin cambios en migraciones, rutas ordinarias, configuración, dependencias o AGENTS.md.

La búsqueda preventiva de firmas de secretos/dumps señaló tres sentencias INSERT del worker de snapshot. Se pausó la confirmación para revisarlas: son SQL parametrizado escrito en código, con fixtures sintéticos y rollback obligatorio, no un dump ni datos privados. No se modificó contenido para superar el control. La revisión no encontró secretos reales o evidencia privada incorporados; las constantes de acceso sintético de pruebas no constituyen credenciales empresariales.

## 4. Hooks y permisos de escritura Git

`core.hooksPath` no está definido; `.git/hooks` solo contiene muestras `.sample`, sin hooks activos. No había firma externa configurada mediante `commit.gpgsign=true`. No se omitió ningún hook ni se ejecutaron scripts externos de pruebas, Composer o despliegue.

El primer intento de staging fue denegado por el sandbox al crear `.git/index.lock`; no alteró el índice. Se solicitó permiso de ejecución ampliada y se repitió el mismo preflight antes de preparar exclusivamente la whitelist. La creación del commit también utilizó el permiso necesario para escribir en `.git`. No se cambiaron permisos del sistema, configuración de Git o credenciales.

El mensaje se escribió en un archivo temporal UTF-8 sin BOM y se proporcionó mediante `git commit --file`. No se creó este informe antes del commit ni se agregó el archivo temporal al repositorio.

## 5. Mensaje exacto del commit

```text
AC-05: consolidar lectores de presentación y demo aislada con cierre corregido

Integra el estado final de AC-05B1, AC-05C0, AC-05B2 y AC-05B3A.

Incluye clasificación sintética por ID y centavos, corrección prospectiva de los signos del cierre fiscal, extracción/comparación mediante snapshot y demostración local de reportes de solo lectura.

Conserva la procedencia documental AC-05B3B0 y la auditoría Git AC-05G0.

No reconstruye commits históricos intermedios. No sustituye reportes operativos, no activa clasificaciones empresariales y no introduce nuevas migraciones.

Las pruebas funcionales corresponden a los informes previos. AC-05G0 verificó sintaxis y diferencias, sin ejecutar nuevas pruebas SQL.
```

Hash completo: **`330ebd00b8ca2f873e04eed9e20f8e41207c5d31`**. Se creó un solo commit; no se modificó el historial anterior.

## 6. Archivos incluidos

La lista obtenida del commit mediante `git diff-tree --no-commit-id --name-only -r HEAD` coincide exactamente con estos 29 paths:

```text
app/Http/Controllers/PeriodoContableController.php
app/Http/Controllers/ReportPresentationDemoController.php
app/Services/AccountingPresentationComparison.php
app/Services/AccountingReportExtractor.php
app/Services/ReportPresentationDemo.php
app/Services/SyntheticAccountingPresentation.php
app/Support/ReportDemoBoundary.php
docs/AC_05B1_CARACTERIZACION_CLASIFICACION.md
docs/AC_05B2_EXTRACCION_COMPARACION.md
docs/AC_05B3A_DEMO_REPORTES.md
docs/AC_05B3B0_CONTRATO_CLASIFICACION.md
docs/AC_05C0_CIERRE_SIGNOS.md
docs/AC_05G0_PREPARACION_GIT.md
resources/views/accounting/report-demo.blade.php
tests/Feature/AccountingPresentationTest.php
tests/Feature/AccountingReportCharacterizationTest.php
tests/Feature/AccountingReportExtractionTest.php
tests/Feature/AccountingReportSnapshotTest.php
tests/Feature/FiscalClosingSignsTest.php
tests/Feature/ReportPresentationDemoTest.php
tests/Feature/ReportPresentationHttpTest.php
tests/Support/ac04b1-backup.php
tests/Support/ac04b1-isolation.php
tests/Support/ac05b2-snapshot-writer.php
tests/Support/ac05b3a-browser.mjs
tests/Support/ReportDemoHttpClient.php
tests/Support/report-demo-router.php
tests/Support/ReportPresentationFixtures.php
tests/Support/run-ac04b1-tests.php
```

## 7. Privados, evidencias y bases

Antes y después se comprobó que `.env`, `.env.testing`, manifiesto privado de respaldo AC05B1, resultado privado de capturas B3A, sesiones report-demo, log Laravel, manifest de build, vendor y node_modules continúan ignorados y fuera de los archivos rastreados. No se usó add forzado. Dumps/sidecars externos tampoco forman parte de la whitelist o del commit.

Se verificó por lectura la presencia de los baselines AC05B1/C0/B2/B3A y del manifiesto/resultado de capturas previo. Los hashes de los 29 candidatos originales permanecen iguales. No se borraron ni sobrescribieron pruebas, informes, evidencia privada o respaldos.

Respaldo anterior comprobado directamente, sin generar otro ni restaurarlo:

- Archivo: `E:/salon-erp-backups/salon_erp_test_AC05B1_20261119_225424_43e6c3abf4dc.sql`.
- Tamaño: **1.536.280 bytes**.
- SHA256: **`85d77381db1a51387e020482fab4a752c116c5c39616278991f452c56fe430b6`**.

`SALON_TEST_DB_RESET_ALLOWED=0` se confirmó mediante lectura puntual antes y después; no se editó configuración. Se conservaron los 82 archivos rastreados de migración sin cambios entre padre y commit.

**No hubo SQL, conexiones a las bases, migraciones, restauraciones ni cambios financieros en G1.** No se vuelve a certificar mediante SQL la instalación 74/82/74 o el preparado131: su preservación comprobada pertenece a los informes anteriores. Esta fase no invocó ningún escritor o prueba que pudiera alterarlos. Tampoco hubo push, consultas remotas, merge, rebase, despliegue o activación de políticas empresariales.

## 8. Estado final y límites de validación

Inmediatamente después del commit, el índice y el árbol de trabajo estaban limpios. Después de redactar este informe, el único pendiente esperado es:

```text
?? docs/AC_05G1_COMMIT_CONSOLIDADO.md
```

El informe no se prepara automáticamente y no pertenece al commit consolidado. El índice debe continuar vacío y HEAD en el hash documentado.

No se ejecutaron PHPUnit, Artisan, carreras financieras, lint adicional o suite general en G1. La sintaxis se verificó en G0 y las pruebas funcionales corresponden a sus informes previos, incluido B3A con 100 pruebas/931 aserciones y ocho capturas. El commit no constituye una repetición de esas pruebas ni aprobación empresarial de sus fixtures.

No quedan discrepancias de hashes, contenido ajeno staged, secretos detectados o hooks pendientes que bloqueen esta integración local. Persisten los límites funcionales y decisiones contables de B3B0; no se implementaron para facilitar el commit.

## 9. Reversión segura y cierre

No se ejecuta reversión ahora. Si posteriormente se autoriza retirar la integración, revisar un commit inverso trazable sobre `330ebd00b8ca2f873e04eed9e20f8e41207c5d31`, con sus dependencias posteriores y evidencia conservadas. No usar reset/clean/stash/checkout ni restaurar bases para deshacer un commit de código. Retirar AC-05C0 reintroduciría el defecto conocido de líneas negativas y exige evaluación explícita.

AC-05G1 concluye aquí: un commit local consolidado, informe posterior sin staging y ninguna continuación automática a AC-05B3B1, AC-06, H01-B2B, clasificación empresarial o despliegues.
