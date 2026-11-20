# AC-05G0 — Preparación segura de integración Git

Fecha: 20 de noviembre de 2026. Resultado: **recomendar un commit consolidado del estado final AC-05B1–B3B0, con procedencia documental y esta revisión G0**. No se preparó ningún archivo en el índice, se creó un commit o se reconstruyó un estado intermedio.

## 1. Alcance y clases de evidencia

- **G — Verificado ahora:** estado/diff/historia alcanzable de Git, existencia y hashes de archivos, exclusiones y comprobaciones estáticas.
- **D — Documentado previamente:** atribución por fase, resultados de pruebas y preservación SQL informados en los documentos AC-05. No se reinterpretan como una nueva ejecución.
- **L — Limitación:** evidencia insuficiente para reconstruir exactamente una versión intermedia o certificar efectos en SQL.

Se realizaron lecturas, hashes, `git diff --check`, lint PHP sin cargar configuración y comprobación sintáctica de JavaScript. No se ejecutaron SQL, Artisan, PHPUnit, migraciones, servidores, capturas, restauraciones o comandos mutantes de Git. Único cambio de esta fase: este documento.

## 2. Preflight Git actual

| Elemento | Resultado verificado |
| --- | --- |
| Rama activa | `feature/panel` |
| HEAD | `9f34037befe0e7bb9730955b3c1b547ad032fb29` |
| Asunto de HEAD | `AC 4BAC, y A5` |
| Rastreados modificados al iniciar | 4 |
| No rastreados al iniciar | 24 |
| Índice | Sin cambios staged; `git diff --cached --name-status` vacío |
| SHA256 inicial del índice | `a13318f8fbd0bb0a6125e559e4944d92088af0b882da6be538343667566e41f4` |
| Diff rastreado contra HEAD | 4 archivos,76 adiciones/23 eliminaciones; no incluye los24 no rastreados |
| Migraciones | 82 archivos rastreados, sin cambios pendientes en esa carpeta |
| Hooks | core.hooksPath no definido; no se encontraron hooks activos sin sufijo .sample en .git/hooks |
| Reset | Lectura puntual confirmó `SALON_TEST_DB_RESET_ALLOWED=0`; sin editarlo |

No se asumió que el árbol coincidiera con el informe anterior: se comprobó HEAD/estado y se recalculó la huella comparable de AC-05B3B0. Sus556 archivos previos, excluyendo aquel documento nuevo, conservan SHA256 agregado `5ad049411bc013f6959fca549f00335a3f0779e7c1cecba7e62545848920649c`. No se detectó modificación posterior de ese conjunto. El documento B3B0 existe ahora y tiene su hash propio en la sección8.

La nueva línea base de G0 contiene557 archivos protegidos de app/resources/routes/tests/config/database/docs y manifiestos/AGENTS.md/entornos: `aeb0fe8e743d78a75fe2de8b8a29b200c338e21da41e9573551574162432080e`. Se calcularon solo hashes de archivos de entorno; no se mostraron secretos. Este documento G0 no pertenece a esa huella porque todavía no existía.

## 3. Inventario por fase y carácter compartido

Las atribuciones de fase siguientes se apoyan en informes y contenido actual (**D**). Git verifica la existencia/estado final (**G**), pero no todas las versiones intermedias.

| Archivo o conjunto | Procedencia documentada | Exclusividad del estado actual |
| --- | --- | --- |
| SyntheticAccountingPresentation.php | B1: motor sintético por ID/centavos; B3A: contribución por detalle | Compartido B1/B3A. No separar por nombre de archivo. |
| AccountingPresentationTest.php | B1 | Exclusivo B1 según informe; no hay snapshot Git intermedio del archivo. |
| AccountingReportCharacterizationTest.php | B1: caracterización; C0: expectativa y nombre del caso de cierre corregidos | Compartido B1/C0. El total bruto100 reemplaza90 por la representación autorizada, no cambia su neto90. |
| ac04b1-backup.php | B1: fase AC05B1 y82 migraciones | Cambio pendiente exclusivo B1; diff exacto contra HEAD disponible. |
| PeriodoContableController.php | C0: centavos/signos, límites y catch Throwable | Cambio pendiente exclusivo C0, delimitado exactamente por diff contra HEAD. No cambia balanceGeneral. |
| FiscalClosingSignsTest.php | C0 | Exclusivo C0 según informe. |
| AccountingReportExtractor.php | B2: extracción/snapshot/adaptación; B3A: techo de volumen | Compartido B2/B3A. |
| AccountingPresentationComparison.php | B2: comparación; B3A: contribuciones por detalle | Compartido B2/B3A. |
| AccountingReportExtractionTest.php, AccountingReportSnapshotTest.php, ac05b2-snapshot-writer.php | B2 | Exclusivos B2 según informe. El worker solo sirve a pruebas rollback-only. |
| ReportDemoBoundary.php, ReportPresentationDemoController.php, ReportPresentationDemo.php, report-demo-router.php, report-demo.blade.php | B3A | Exclusivos de la demo B3A; no agregan rutas ordinarias. |
| ReportPresentationFixtures.php, ReportDemoHttpClient.php, ReportPresentationDemoTest.php, ReportPresentationHttpTest.php, ac05b3a-browser.mjs | B3A | Fixtures, pruebas y capturas B3A; el navegador no es un consumidor financiero. |
| ac04b1-isolation.php | B1/C0/B2/B3A: fases de comparación | Compartido; varias fases en una línea y condiciones comunes. |
| run-ac04b1-tests.php | B1/C0/B2/B3A: grupos y preflight común | Compartido; incluye conteos/estructura, respaldo y preparado131. |
| Cinco documentos AC-05B1/B2/C0/B3A/B3B0 | Evidencia y diseño respectivos | Se conservan originales, sin modificar resultados ni límites retrospectivamente. |

Los nombres se resuelven a sus paths completos y únicos en la lista autorizable de la sección7. No se incluye AC-05A como cambio pendiente: ya está en la historia rastreada.

## 4. Proveniencia comprobable y límites de reconstrucción

### Evidencia encontrada

1. **G:** HEAD proporciona el antes exacto de los cuatro archivos rastreados modificados. El diff del controlador contiene únicamente representación del cierre/helpers y Throwable; el del respaldo añade AC05B1. Estos dos cambios pueden atribuirse con precisión al contenido final documentado.
2. **G:** `git log --all` para el clasificador, extractor, comparador y caracterización no mostró commits alcanzables de esos paths. Actualmente son archivos no rastreados, no una secuencia Git de fases.
3. **G:** búsqueda de patches/diffs/snapshots en docs, tests y storage/app/private encontró diffs de AC-02/03/04 y antecedentes H, pero no un patch completo AC-05B1/B2/C0/B3A que preserve todos sus estados de código. Los directorios .codex/.agents no existen en esta raíz. No se hizo una búsqueda forense de todo el equipo u objetos Git inalcanzables.
4. **G:** los baselines privados AC05 tienen esquema/filas resumidos por hashes. Se inspeccionaron las claves, no se volcaron datos: bases → tablas → columns/rows/ddl. Son evidencia de aislamiento de bases, **no copias de PHP/Blade/JS ni del índice**.
5. **G/D:** hay manifiesto de respaldo, dos evidencias de snapshot, logs y capturas privadas. Prueban aspectos documentados de ejecución, no reconstruyen los bytes de cada fase. Un dump SQL tampoco almacena el estado de los servicios PHP.
6. **D:** C0 conserva el hash del controlador anterior y la reproducción8/62; B3B0 conserva la huella de archivos previos. Un hash permite cotejar un candidato, pero no recuperar sus bytes ni demostrar un intermedio completo sin esa fuente.

### Consecuencia

**L:** no se dispone de instantáneas completas inequívocas para reconstruir B1 antes de C0, o B2 antes de B3A. Quitar a mano campos/límites, renombrar tests o restablecer expectativas negativas basándose solo en narrativa fabricaría una historia aproximada. No se hizo.

**P:** conservar los documentos como procedencia y limitaciones y proponer el estado final completo. Esta decisión actualiza prospectivamente la opción de cinco commits propuesta en B3B0; no cambia aquel documento ni afirma que sus estados sugeridos hayan existido como commits verificables.

## 5. Dependencias y elección de integración

| Consumidor/fase | Requiere | Riesgo de separación artificial |
| --- | --- | --- |
| B1 | Catálogo/mayor actuales, bootstrap seguro | Un clasificador fragmentado puede faltar en las pruebas nuevas. |
| C0 | Caracterización B1, validaciones de cuenta/período existentes | Combinar escritor corregido con expectativa negativa antigua produce un estado inconsistente. |
| B2 | Clasificador B1 y contrato de cierre C0 ya caracterizado | Extraer servicios sin el lector/base de pruebas omite dependencias. |
| B3A | B1/B2/C0, metadata por detalle, límite de volumen y fixtures | Preparar solo la vista/router omite servicios, guardas o estructura de resultado que consume. |
| B3B0 | Diseño y evidencia de las fases anteriores | No implementa tablas ni autoriza publicación; mantenerlo separado semánticamente de funciones empresariales. |
| G0 | Revisión del estado final anterior | Es preparación documental, no ejecución de integración. |

**Opción A — commits por fase:** aceptable únicamente con fuentes completas de cada estado, dependencias consistentes y revisión/validación segura. La evidencia disponible no alcanza para toda la secuencia sin reconstrucción manual aproximada. No se recomienda ahora.

**Opción B — commit consolidado:** **recomendada**. Conservar exactamente los bytes finales verificados y documentar la evolución por fase en el mensaje/archivos. Permite revisar el escritor C0 aparte dentro del mismo diff sin modificarlo por estética. No cambia commits AC-04 ni reescribe historial.

Se puede hacer una revisión por bloques funcionales antes de autorizar el único commit, sin fingir que son versiones históricas independientes: cierre, lectores, demo, pruebas/auxiliares y documentos. No hace falta crear ramas temporales o cambiar el checkout para esta propuesta.

## 6. Revisión estática del estado final

### Comprobaciones ejecutadas en G0

- **21 archivos PHP** de AC-05: `php8.3.30 -n -l path`, todos sin errores. El modo -n y lint no ejecuta scripts, Composer/bootstrap, PDO, helpers de respaldo o tests.
- `node --check tests/Support/ac05b3a-browser.mjs`: aprobado, sin ejecutar el arnés.
- Nombres de clases/path/namespace y consumidores inspeccionados: los servicios nuevos se corresponden con sus clases, la vista usa report-demo y el router referencia su controlador/frontera. No se observó una discrepancia en los componentes revisados; esto no equivale a resolución funcional completa por pruebas.
- Blade inspeccionado estáticamente: bloques @php multilínea, escape de expresiones, CSRF en formularios y procedencia A/B/C/D. No se compiló la vista ni se ejecutó navegador en esta fase.
- `git diff --check`: sin errores de espacios; avisos CRLF/LF en los tres auxiliares existentes. No se normalizaron archivos.

### Funcionalidad preservada por inspección

- **C0 presente:** cuenta nominal se neutraliza con lados no negativos, centavos exactos, rechazo de límites y catch Throwable. Prefijos4–7/destino3.2 y balanceGeneral no se sustituyen.
- **Lectores presentes:** clasificador sintético, extractor con snapshot propietario o modalidad declarada de fixture, comparador por grupo/cuenta/detalle y mapa de contribuciones. Conservan rechazo de cobertura, sin inferir desde bindings una clasificación propuesta.
- **Demo presente:** router8778 independiente, sesión propia, frontera de raíz/testing/identidad/puerto, SQL READ ONLY y formularios de consulta. Sus POST son login/logout/compare; no confirman cargos o asientos.
- **Rutas ordinarias:** no hay diff en routes. Las rutas de demo se registran solo desde el router de test y su whitelist no sirve archivos del repositorio. No se observó un consumidor financiero nuevo fuera del ensayo.
- **Fuera de alcance sin diff:** ContabilidadService, AccountIntegrityService, AccountingPolicyService, BankBalanceService, BancoController, FondosService, InventarioService, NominaService, migraciones, config, AGENTS.md y manifiestos de dependencias. Esto es comparación contra HEAD, no una certificación general de todos sus comportamientos.
- **Pruebas/documentos presentes:** se inspeccionaron traits y worker. Caracterización/extracción/cierre/demo usan fixtures DatabaseTransactions; presentación pura no requiere SQL; snapshot tiene hijo de rollback; HTTP lee historia y sesiones propias. No se halló RefreshDatabase o seed global en esos tests seleccionados. No ejecutar por ello automáticamente todos los modos del runner, que conserva grupos antiguos fuera del alcance.

### Validación funcional: qué no se repitió

No fue necesario SQL/PHPUnit para esta revisión de Git sin cambios funcionales y con huella idéntica a los antecedentes. **No se ejecutaron regresiones en G0.** Las100 pruebas/931 aserciones y ocho capturas son resultados aceptados de B3A, no un nuevo resultado de esta fase. El lint actual no prueba atomicidad, concurrencia o seguridad HTTP; se conserva esa distinción.

## 7. Lista exacta que se podría preparar, solo con autorización posterior

Whitelist de **29 paths**:28 existentes pendientes y este documento nuevo. No usar `git add .`, `-A` o `-f`; no se proporciona ni se ejecuta una orden de staging en esta fase.

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

La inclusión de G0 en el commit único mantiene junto al estado integrado su justificación. También podría conservarse como revisión documental posterior si se solicita expresamente; no obliga a separar artificialmente las fases funcionales. Cualquier cambio posterior de HEAD/hash/staged exige revisar otra vez la propuesta, no ejecutar esta whitelist a ciegas.

## 8. Manifiesto SHA256 del estado candidato

Hashes de bytes del árbol de trabajo, no hashes del blob normalizado de Git. G0 no incluye su propio hash para evitar autorreferencia; se verificará aparte al preparar una integración futura. Las conversiones EOL de Git pueden cambiar bytes del blob; deberán revisarse sin normalizar todo el proyecto.

| Path | SHA256 |
| --- | --- |
| app/Http/Controllers/PeriodoContableController.php | `1069f99ec9ed8079a809ae966797148d3e1c8ab0a862bbab7d6ee9e2e461f215` |
| app/Http/Controllers/ReportPresentationDemoController.php | `8b8cbb043fa20898c19144565c7397e8c337169701763c8bfff6ff8f5a8d1165` |
| app/Services/AccountingPresentationComparison.php | `d584ccea9b5bce210c8dcd626e307690cbca97a6e0cc213015d7aa267a2019b2` |
| app/Services/AccountingReportExtractor.php | `b8bbf53d7653d9f930cfbe7373763524cc535fcb32d7af3b9c3afc7663ed6d34` |
| app/Services/ReportPresentationDemo.php | `5f9d9e330488aa57108a07a21f6ad8860a46684229a9c7325ed82cfe28e493fc` |
| app/Services/SyntheticAccountingPresentation.php | `29ef59d3137aaba8b0d71dd8e6b5baf8621a0f3098ca0ea0cd3b12aaea2803bf` |
| app/Support/ReportDemoBoundary.php | `4bd93660a3a081bdb3d0ff693a502e6ba62cb6c39cae434c6162d7158d6729bc` |
| docs/AC_05B1_CARACTERIZACION_CLASIFICACION.md | `d24007dffa7f8e48f3f05b5224b1b6b25e83d35dd221154dba8b74876e0ed65f` |
| docs/AC_05B2_EXTRACCION_COMPARACION.md | `f9faba70dc29d7dcc0857739ec7c6d04cb9320bab4086f76c6fab0757ad514fe` |
| docs/AC_05B3A_DEMO_REPORTES.md | `1751788ae979e27980a6a03445fb5d56ce98ecd71ed058e366d5bb8ae688b0c4` |
| docs/AC_05B3B0_CONTRATO_CLASIFICACION.md | `4c9b8897f751ee17e24af5a447eb9d4186d6444810fa17f1a15f1e9cd7a2a3b9` |
| docs/AC_05C0_CIERRE_SIGNOS.md | `448a838f23a641617fe8ce80c9ab420c53f409382d9429d791d13380efdbd205` |
| resources/views/accounting/report-demo.blade.php | `c8d708e3fcd2f7f6e27b811e80167227d06ed02969618547f12f8ee5cfe96f0f` |
| tests/Feature/AccountingPresentationTest.php | `6a8574bdf6cd0da5651e53f0ee75a913abda71da5c704dc3f00c4b44853494d9` |
| tests/Feature/AccountingReportCharacterizationTest.php | `5f8297491ae2cf8787489c9dd4f4ff1fde21467e1fb925b8b0519f6d55f2d9aa` |
| tests/Feature/AccountingReportExtractionTest.php | `6d76778218c21124fae39b3b6703c81caca74e65fb3b26e17d987126d14bcb76` |
| tests/Feature/AccountingReportSnapshotTest.php | `69537bcd39fb773905cbbff1611c2b3b3ac7949c97e5e1e04e9502b21693b49d` |
| tests/Feature/FiscalClosingSignsTest.php | `b5101f48cd6c5d938de8013151c231a03c1e76157eee6e8c0d2a0bbf1f758699` |
| tests/Feature/ReportPresentationDemoTest.php | `fffd84db323ec5f9f88824525f660b85bc683b63e6226f74fb63c1fa8e29449f` |
| tests/Feature/ReportPresentationHttpTest.php | `69b77aaebbe2f916d92ca154d35896cfcfd70fc4331e59ddd82b871abb92ee32` |
| tests/Support/ac04b1-backup.php | `8e1b722794d93cf7d55f3f38de6b67e42fe2e7fa9ab84211f90ded6ae72d4255` |
| tests/Support/ac04b1-isolation.php | `1318323df680cfd4203b9274203670a5e1bcc7d096d75e855fa0fddcc069bfd2` |
| tests/Support/ac05b2-snapshot-writer.php | `1371b7df3fc55f88e2a97f4b79fc25cab4256cacfb4fa084a0d6c2ac0ab02343` |
| tests/Support/ac05b3a-browser.mjs | `c437aca8ebe90e6ab4303757ad399f5d4991488457049eb9dae0719a8d5f8f2a` |
| tests/Support/ReportDemoHttpClient.php | `0a2c1cf079dbc1e85db302f47774f67f51e1e67be6f8b8fc8b05cea94ea06538` |
| tests/Support/report-demo-router.php | `5cade83081c25b69bf09f03227b5c570014e985e4d18ddaca07ed3d6d3a78c47` |
| tests/Support/ReportPresentationFixtures.php | `0b8dcf8db13264a3c7b133f75ef93b773da53c8279cd97fdc48600ce6f592e3d` |
| tests/Support/run-ac04b1-tests.php | `76dcd489a826ee52c7631bb49def2095a5a3afacae02509da62080d3c5ea5dd4` |

## 9. Privados y generados excluidos

Verificaciones positivas de `git check-ignore`: .env, .env.testing, public/build/manifest.json, ac05b1-backup.json, result.json de capturas, sesiones de report-demo, logs, vendor/autoload.php y node_modules/.package-lock.json. Se conservaron sus ubicaciones; no se usó add forzado.

Excluir de cualquier preparación:

- Secretos, APP_KEY, archivos de entorno, credenciales y archivos personales del editor.
- Dumps SQL, `.sha256` y manifiestos de respaldo privados, incluidos los externos en `E:/salon-erp-backups`.
- Baselines financieros, evidencia de conexión/snapshot, datos de cuentas/documentos extraídos y capturas privadas con potencial información sensible.
- Sesiones, cookies, cache, perfiles Chrome, logs y vistas compiladas de storage.
- public/build, vendor, node_modules, archivos temporales y resultados/cache de tests.

Los documentos Markdown con evidencia acotada sí son candidatos; las credenciales sintéticas de pruebas existentes no son autoridad empresarial ni deben sustituirse por secretos reales. Una revisión futura del diff staged debe buscar exposición accidental sin imprimir ni copiar contenido privado al mensaje de commit.

## 10. Migraciones, entornos y preservación

**G:** no hay cambios pendientes en database/migrations; los82 archivos están rastreados. Las ocho adiciones posteriores al conjunto de74 de desarrollo ya pertenecen a fases anteriores: dos de representación/asignaciones fraccionables, fotografía de consumo, evidencia de anulación, documentos de apertura, políticas versionadas, documentación de evento y reversa bancaria.

Un commit de AC-05 no debe volver a agregarlas, editarlas o ejecutarlas. No se modifican commits AC-04 ni se interpreta la existencia de archivos como instalación SQL. El estado74/82/74 de las bases es antecedente de B3A, **no verificación SQL G0**. No se accedió a las bases, no se generó respaldo nuevo ni se ejecutó una restauración.

La corrección C0 está en el código candidato y puede cambiar nuevos cierres cuando sea desplegada, pero G0 no lo despliega ni vuelve a ejecutar sobre historia. Confirmar Git no autoriza aplicar las migraciones previas o habilitar la demo en una instalación ordinaria.

## 11. Propuesta de mensaje y comprobaciones previas a integrar

Mensaje recomendado, **no ejecutado**:

```text
AC-05: consolidar lectores de presentación y demo aislada con cierre corregido

Integra el estado final de AC-05B1, AC-05C0, AC-05B2 y AC-05B3A:
clasificación sintética por ID/centavos, cierre con lados no negativos,
extracción/comparación por snapshot y demo de lectura exclusiva de test.

Incluye el contrato documental AC-05B3B0 y la revisión Git AC-05G0.
La procedencia por fase está documentada; no se reconstruyen intermedios
no verificables. No sustituye reportes ordinarios ni activa clasificación
o contabilización empresarial. Sin migraciones nuevas ni despliegue.

G0: lint de 21 PHP, node --check y git diff --check aprobados.
Las pruebas funcionales y capturas constan en sus informes previos;
G0 no ejecutó SQL ni PHPUnit.
```

Antes de una autorización posterior para preparar/confirmar:

1. Confirmar que rama/HEAD, hashes y lista pendiente siguen siendo los descritos; si cambió algo, comparar su delta antes de continuar.
2. Revisar bloques completos, incluidos todos los no rastreados; verificar las29 rutas de la whitelist y ausencia de privados.
3. Autorizar expresamente si se desea el commit local consolidado. No incluir push, merge, rebase, despliegue o migraciones de forma implícita.
4. Solo tras esa autorización, quien prepare deberá comparar el diff staged con la whitelist y contenido esperado; el índice ahora está vacío y no hay una integración staged lista para confirmar.
5. Si se exige validación adicional, inspeccionar nuevamente los tests, preflight/guardias y datos antes de SQL. Elegir solo grupos seguros, sin carreras persistentes, seeders o suite general. No convertir la preparación Git en ejecución automática de Composer test o hooks no inspeccionados.
6. Confirmar que el mensaje conserva límites históricos y no atribuye aprobación real al contador o una nueva ejecución de pruebas a G0.

Como alternativa de mensaje únicamente documental, si se pide una separación explícita de esta revisión: `docs(AC-05G0): documentar integración consolidada y manifiesto de revisión`. No sustituye la revisión funcional del commit principal ni obliga a cinco commits históricos.

## 12. Riesgos, bloqueos y reversión sin pérdida

| Riesgo | Tratamiento |
| --- | --- |
| Intermedios AC-05 no reconstruibles de forma completa | Bloquea la opción A integral; no bloquea revisar el estado final consolidado. No fabricar bytes/expectativas antiguas. |
| Helpers y lectores compartidos | Incluir versión final coherente; revisar dependencia de cada grupo, no staging parcial automático. |
| Archivos nuevos omitidos por diff --stat | Usar whitelist y hashes, además de diff de rastreados. |
| Cambios posteriores del propietario u otro proceso | Volver a inspeccionar; no sobrescribir ni usar el manifiesto como permiso para descartar cambios. |
| Confusión commit/despliegue | Commit futuro local no autoriza instalación de82 migraciones en desarrollo, activación de demo o políticas. |
| Futuros tests/CI/hooks con efectos | Revisión y autorización propias; no ejecutar grupos viejos indiscriminadamente. No se inspeccionaron automatizaciones remotas ni se realizó push. |
| EOL | Distinguir hash de bytes y normalización Git; evitar cambios masivos ajenos al alcance. |
| Limitaciones funcionales ya documentadas | Mantenerlas: prototype sintético, límites de volumen, edición no archivada, lector histórico no equivalente a emisión y ensayo concurrente rollback-only. No son aprobación empresarial. |

**Ahora, sin integración ejecutada:** no hay nada que deshacer del índice o código. Si se descarta G0, retirar únicamente este documento con revisión del usuario; conservar código y evidencia acumulados.

**Después de un commit futuro autorizado:** cualquier retirada debe ser una revisión inversa trazable, preferentemente mediante otro commit aprobado, conservando los anteriores y la evidencia. Revisar dependencias que pudieran agregarse después; no aplicar una reversión ciega, reset destructivo o eliminación de archivos privados. No restaurar bases ni ejecutar migraciones down como efecto de una reversión de código. Volver al cierre previo reintroduciría su defecto conocido y requiere evaluación explícita.

## 13. Comprobación de salida y límite de autorización

La comprobación final confirmó las mismas huellas del índice (`a13318f8fbd0bb0a6125e559e4944d92088af0b882da6be538343667566e41f4`) y de los557 archivos previos (`aeb0fe8e743d78a75fe2de8b8a29b200c338e21da41e9573551574162432080e`), excluyendo únicamente este documento nuevo. Resultado:4 rastreados modificados y25 no rastreados (29 candidatos), mismos cambios previos más G0. Rama/HEAD permanecieron iguales, índice sin staged, diff check sin errores y reset0.

No se ejecutaron git add, commit, reset, clean, stash, checkout, rebase o cherry-pick, ni se crearon ramas temporales. No se cambió código funcional, pruebas, vistas, configuración, dependencias o datos financieros. No hubo SQL, PHPUnit, migración, servidor o navegador.

**Propuesta preparada para revisión:** integración local única de los29 paths declarados y sus bytes finales, sujeta a autorización posterior y revalidación si el estado cambia. No está autorizada su ejecución por AC-05G0. Esta fase termina aquí; no inicia AC-05B3B1, clasificación empresarial, AC-06, H01-B2B o despliegues.
