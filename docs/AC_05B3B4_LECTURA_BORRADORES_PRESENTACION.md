# AC-05B3B4 — Lectura verificada de borradores y presentación sintética

Implementado un recorrido interno CLI desde un borrador existente hasta una presentación en memoria. **Sin DDL, DML, nuevos borradores, publicación, aprobación empresarial, edición emitida ni cambios de reportes.** Ninguna ruta HTTP nueva. Las tres bases conservan exactamente sus filas y DDL, incluidos AUTO_INCREMENT.

## 1. Inspección y referencia Git

Rama `feature/panel`; HEAD inicial/final `6c0adb662537fb3b88c55beaa9ec46c69e80ec44`. Árbol e índice inicialmente limpios. No se ejecutó add, commit, push ni cambio de rama.

Se inspeccionaron el validador, canonicalizador, adaptador B3, clasificador, repositorio B2, extractor y perfiles R. El repositorio lee cabecera y auditorías con `FOR UPDATE` dentro de su transacción: **no es la vía de este lector**. El extractor admite un snapshot propietario o una transacción de fixture; B4 admite exclusivamente el primero y no lo invoca para extraer cuentas reales.

El preflight existente `ac05b3b2r.php preflight` confirmó perfil91, 82 migraciones y concordancia exacta con la evidencia B2 aprobada antes de crear la nueva línea base. No se ejecutaron instaladores, pruebas de persistencia, workers financieros ni fixtures SQL.

## 2. Arquitectura y frontera

```text
bootstrap CLI B4 → lector PDO fijo → snapshot propietario RR / READ ONLY
                                      ├─ perfil91 y privilegios
                                      ├─ actor técnico activo
                                      └─ cabecera + auditorías → verificador puro
                                                ↓ commit de lectura
                                    fotografía readonly, sin PDO
                                                ↓
                           preview puro → adaptador B3 → motor existente
```

`SyntheticFinancialPresentationDraftReader::forHarness()` no recibe conexión, credenciales, actor, base o SQL. Abre una única PDO con DSN fijo `127.0.0.1:3306/salon_erp_test`, usuario `salon_erp_tester` y secreto local del bootstrap de pruebas. No hay conexión alternativa ni fallback administrativo en el lector.

Exige CLI, PHP8.3.30, raíz del proyecto, bootstrap de test, entorno testing, reset deshabilitado y contrato separado `AC05B3B4_READ_ONLY_V1`. `ac05b3b4-bootstrap.php` **no define** el contrato escritor B2. Una prueba verifica que el repositorio rechaza ese proceso por carecer de su contrato propio.

Cada lectura repite la frontera y comprueba configuración de sesión. Dentro de su snapshot valida `AC05_SYNTHETIC_91_V1`: nombres de91 tablas, columnas, índices, FK/CHECK, DDL normalizado y82 nombres de migración contra el manifiesto fijo existente, no contra una estructura aprendida de la base viva. Identidad `salon_erp_tester@localhost`, puerto3306, roles NONE, MySQL8.4.3 y grants exactos. Los SELECT de prueba sobre desarrollo/bootstrap reciben1142. No se modificaron privilegios; esta identidad conserva los privilegios test ya autorizados, **no es un nuevo usuario SQL SELECT-only**.

El actor se obtiene desde SQL como el primer usuario activo alvaro/contador, siguiendo la procedencia técnica B2; se exige coincidencia con cabecera, autor del contenido y auditorías. No es una autenticación empresarial ni una elección del operador.

## 3. Snapshot e integridad documental

El lector rechaza transacciones ajenas y sesiones READ WRITE o de aislamiento diferente. Ejecuta `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY`, con sesión REPEATABLE READ y UTC. Cabecera, auditorías y actor se leen dentro de ese snapshot; no usa `FOR UPDATE`, actualizaciones de timestamps, bloqueos financieros ni GET_LOCK. Excepción o Throwable provoca rollback de lectura; termina con reset0.

La fotografía es `SyntheticPresentationDraftSnapshot`, clase `final readonly` sin PDO. Conserva filas originales, auditorías completas, validación y contexto del snapshot. Una modificación de sus arrays anidados es rechazada por PHP. Puede quedar obsoleta después de su lectura: no confiere permiso para revisar o publicar un documento.

`SyntheticPresentationDraftIntegrity` concentra una verificación pura compartida:

- JSON canónico exacto y SHA256 calculados nuevamente mediante B1 para cada versión.
- simulation / synthetic_fixture / TEST-FIXTURE-ONLY y procedencia B2 exacta.
- Familia, versión y autor coherentes con cabecera y todas las revisiones.
- Estado draft o frozen_simulation, sin interpretación de aprobación.
- Creación1 seguida de revisiones consecutivas; expected_revision, evento, actor y draft_id correctos.
- Unicidad de solicitudes dentro de la cadena y UUID de creación igual al documento.
- before_content/before_hash iguales al after anterior; número de revisiones y último after iguales a cabecera.

Para evitar una tercera copia del algoritmo se extrajo la lógica del repositorio y del auxiliar R. El repositorio conserva validación de cabecera **antes del mismo lock de auditorías**, las mismas consultas `FOR UPDATE`, transacciones y métodos escritores. Se agregan al helper comprobaciones explícitas de pertenencia draft_id y solicitud repetida. No se ejecutaron sus escritores como regresión, porque B4 prohíbe fixtures INSERT aun revertibles. El auxiliar administrativo conserva además recuentos42/48, estado draft esperado, ausencia de huérfanos y preparado131.

Un snapshot InnoDB garantiza una vista de filas confirmadas al fijarlo; no sustituye una edición emitida ni certifica carreras con DDL externo. Esta fase no ejecuta escritores concurrentes.

## 4. Integración, hashes y procedencia

Uso interno:

```php
$photo = SyntheticFinancialPresentationDraftReader::forHarness()->read($documentUuid);
$preview = (new SyntheticFinancialPresentationDraftPreview)->present($photo, 'result', $memoryFixture);
```

El preview vuelve a verificar la fotografía, llama la API existente `FinancialPresentationSyntheticAdapter::read()` y exige igualdad entre hash almacenado, hash revalidado, validación adjunta y hash fuente usado por el adaptador. Construir manualmente un DTO no evita esa verificación.

Salida: UUID, revisión, estado, hashes almacenado/revalidado, identidad/familia/versión y contenido fuente; perfil, universo y cobertura; ID/hash del fixture; clasificación derivada y su hash; diagnósticos, contenido con decisiones pendientes y contexto de lectura. Siempre `business_authority=false`, `publishable=false`, `issued_statement=false`, también para frozen_simulation.

`derived_presentation_hash` es SHA256 del JSON exacto del sobre `synthetic-draft-preview-v1` con hash fuente, hash fixture y presentación del motor. Se usan JSON_THROW_ON_ERROR, JSON_UNESCAPED_UNICODE y JSON_UNESCAPED_SLASHES; el motor ordena IDs de cuentas/detalles. Es distinto del hash canónico B1 y del classification_hash del motor. No es una firma, archivo emitido ni autoridad económica. No se incluyen en él IDs de conexión o tiempos de observación.

## 5. Compatibilidad real de los fixtures

**Hecho verificado en las42 cabeceras:** todas contienen una única asignación al ID ficticio900001. No se buscaron cuentas reales del catálogo para darle otro significado.

El fixture compatible `SYNTHETIC:AC05B3B4-COMPATIBLE-V1` utiliza exclusivamente ese ID ya asignado a ingreso: crédito20.00 y su reversa deudora20.00, fecha2030-10-15, conservando vínculo y cuenta hipotética inactiva. Resultado0; contribución de la reversa−2000 centavos; balance neto0. Las líneas individuales son proyecciones hipotéticas y **no se presentan como asientos completos extraídos de MySQL**. El universo cubierto es únicamente ese fixture; no acredita un estado financiero global del ERP.

Recorrido almacenado comprobado:

| Evidencia | Valor |
| --- | --- |
| Document UUID | `2ebf70b7-731a-432a-8151-9440de644e8a` |
| Revisión / estado | 1 / draft |
| Hash almacenado y revalidado | `f98aa0b111f9e9224fee9d0bc88ebfa01c29a0c11fb39a047d1ca3bd037d7190` |
| Hash del fixture | `c9066a64abfcd3e14cbd429d974c9946e14f747ac5273cc23067998888cd65e5` |
| Hash clasificación derivada | `c10241532ee1f97850ff3ac4086f0778c978b10062b3b7e6b85d143514920516` |
| Hash presentación derivada | `9b555bf1700028777797e6a484b7a226e0b36dbf588053e41351e7c5edb73f27` |

Evidencia privada nueva, sin sobrescribir: `storage/app/private/ac05b3b4-read-f583741e75b002f5.json`.

El mismo borrador con el fixture rico B3 (IDs701–711) se rechaza con `adapter.incomplete_coverage`. Activos, contraactivos, costos/gastos, padre/hijo, cargos/devoluciones y cierre se verifican mediante contratos **nuevos exclusivamente en memoria**, identificados como variantes hipotéticas; sus resultados no se atribuyen al borrador almacenado900001 ni a documentos financieros AC-04. No se modifica ninguno de los42/48 registros para conseguir cobertura.

## 6. Cobertura y perfiles

Se reutilizan íntegramente los filtros y signos de B3; no hay un segundo algoritmo de saldos. Resultados usa el rango explícito y excluye cierre; balance acumula hasta el corte e incluye cierre. No se infieren asignaciones desde códigos, nombres, jerarquía contable ni bindings.

Las regresiones puras incluyen correctora de ingreso−1000 centavos, contraactivo−500, padre/hijo con contribuciones independientes200/300, cuenta inactiva, costo y gasto diferenciados, manual/reversa, cargo/devolución sintéticos, cierre corregido, estados, origen NULL y fecha/período. Se preservan detalles y referencias originales. Filtros no soportados por B3 siguen rechazados. El cierre hipotético deja resultado9000 fuera de cierres y patrimonio9000 al incluir traslado, sin modificar el escritor fiscal.

## 7. Pruebas ejecutadas

| Grupo | Pruebas | Aserciones |
| --- | ---: | ---: |
| B4 puro: integridad, snapshot inmutable y preview | 27 | 47 |
| Regresiones puras B1/B3/motor | 131 | 447 |
| Perfiles e aislamiento puros R | 29 | 60 |
| B4 SQL exclusivamente lector | 10 | 244 |
| **Total final focalizado** | **197** | **798** |

Las187 pruebas puras se ejecutaron con PDO/mysqli deshabilitados, sin configuración PHPUnit de la aplicación. Las10 SQL usan bootstrap B4, sin Laravel TestCase, DatabaseTransactions ni traits destructivos. Verifican dos lecturas de cada una de42 cadenas, fotografías idénticas, hash, autor/asignaciones, timestamps, sesión RR/RO observada dentro de la lectura, transacción propietaria, rechazo de sesión READ WRITE, fallo tras cabecera con rollback, identidad inválida/no encontrada, entorno alterado y ausencia de autoridad escritora B2.

Comandos focalizados:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d disable_classes=PDO,mysqli -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result --no-progress tests/Unit/SyntheticPresentationDraftIntegrityTest.php tests/Unit/FinancialPresentationSyntheticAdapterTest.php tests/Unit/FinancialPresentationContractTest.php tests/Feature/AccountingPresentationTest.php tests/Unit/AccountingTestSchemaProfilesTest.php
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap tests/Support/ac05b3b4-bootstrap.php --do-not-cache-result --no-progress tests/Feature/SyntheticPresentationDraftReadOnlyTest.php
```

**Límite deliberado de la prueba DML:** no se envió INSERT/UPDATE/DELETE al servidor para obtener1792, respetando la prohibición de introducir escrituras para provocar errores. Se verificó READ ONLY real, rechazo de una sesión READ WRITE y rechazo de una cadena DML como identidad antes de ejecutar SQL. La API no expone SQL/PDO. Esto no se presenta como una prueba empírica del error1792 de MySQL. Las alteraciones de hashes, secuencias, procedencia, familia, cuenta y esquema se hicieron exclusivamente en arrays.

Lint de los11 archivos PHP propios/modificados: aprobado. `git diff --check`: aprobado. No suites generales, carreras financieras ni instalación SQL. La extracción pura del verificador no altera los métodos financieros ni su orden de locks; una regresión escritora del repositorio no se ejecutó bajo esta autorización lectora.

## 8. Respaldo, baseline y estado de bases

Se verificó de nuevo el respaldo R aprobado y su sidecar/manifiesto, sin generar ni restaurar otro:

- Archivo: `E:/salon-erp-backups/salon_erp_test_AC05B3B2R_20261120_092818_0b7b605d017b.sql`.
- Tamaño:1777958 bytes;91 tablas,82 migraciones,1963 filas.
- SHA256: `15fc93be213a2e54450cd0c8034ab2e86191a1bfdf713ad013b04ac640df7eef`.
- No se afirma restaurabilidad ensayada.

Nueva identidad de baseline `d9e27b4068a1f35c`:

- `storage/app/private/ac05b3b4-d9e27b4068a1f35c-before.json`.
- `storage/app/private/ac05b3b4-d9e27b4068a1f35c-after.json`.
- Ambos SHA256: `204e933b35c93c4fa3826e00f92f8de930ae43ce42b31bf28498e43a46521158`.

El comparador exige igualdad con la evidencia R aprobada y con el nuevo baseline propio. Compara hashes de todas las filas y DDL completo, **sin normalizar AUTO_INCREMENT**. Inspección administrativa independiente READ ONLY para tres bases, en proceso separado; no es una alternativa del lector restringido. Los archivos privados quedan ignorados por Git.

| Base | Tablas antes/después | Migraciones | Filas antes/después | Resultado |
| --- | ---: | ---: | ---: | --- |
| salon_erp | 78 / 78 | 74 | 225 / 225 | Idéntica |
| salon_erp_bootstrap_check | 78 / 78 | 74 | 202 / 202 | Idéntica |
| salon_erp_test | 91 / 91 | 82 | 1963 / 1963 | Idéntica |

42 cabeceras y48 auditorías exactas, todas las cadenas válidas. Preparado131: prepared, banco221, resultados NULL; hash de fila UTC `3167cc4bbdb3ccaa58fbc454634f1431ca20fc3281e228d20c4f5ce791f87a52`. No filas nuevas/transitorias, cambios de timestamps, asientos, autorizaciones ni saldos. Reset0 conservado y restablecido en finally. No se modificaron credenciales, configuración, APP_KEY, permisos, dependencias o AGENTS.md.

## 9. Archivos y reversión revisable

Nuevos servicios:

- `app/Services/SyntheticFinancialPresentationDraftReader.php`.
- `app/Services/SyntheticPresentationDraftSnapshot.php`.
- `app/Services/SyntheticPresentationDraftIntegrity.php`.
- `app/Services/SyntheticFinancialPresentationDraftPreview.php`.

Nuevos auxiliares/pruebas:

- `tests/Support/ac05b3b4-bootstrap.php`.
- `tests/Support/ac05b3b4-isolation.php`.
- `tests/Support/SyntheticDraftReadFixtures.php`.
- `tests/Unit/SyntheticPresentationDraftIntegrityTest.php`.
- `tests/Feature/SyntheticPresentationDraftReadOnlyTest.php`.

Modificados únicamente para compartir verificación pura:

- `app/Services/SyntheticFinancialPresentationDraftRepository.php`.
- `tests/Support/AccountingSchemaInspection.php`.

Más este documento. Validador, canonicalizador, adaptador B3, motor, extractor, comparador, reportes, vistas, routers, migraciones y escritores financieros permanecen sin cambios.

Revisión del diff: comparar los dos archivos rastreados contra el HEAD indicado y leer íntegramente los nueve PHP nuevos; Git no incluye automáticamente los archivos no rastreados en `git diff`. Reversión futura: retirar solo la delegación al helper de esos dos archivos, recuperar sus verificaciones anteriores y retirar los nuevos consumidores tras revisar dependencias. No ejecutar reset/clean, no tocar tablas y conservar evidencia privada y este informe. No se aplicó ninguna reversión ni se preparó el índice.

## 10. Límites y continuación recomendada

La presentación positiva almacenada tiene cobertura económica estrecha, neto cero, y no es un estado global del catálogo. Los hashes son pruebas técnicas de coherencia, no firmas frente a quien pueda editar SQL directamente. Las fotografías son lecturas del instante fijado, no estados emitidos ni autoridades para escribir. El actor técnico depende de la selección controlada B2: si cambia o se desactiva, el lector puede rechazar la consulta antes que atribuir otra identidad.

Próxima fase recomendada, sujeta a autorización independiente: ampliar fixtures exclusivamente en memoria y comparar diferencias explícitas de cobertura entre borradores; decidir separadamente si se necesitan nuevos borradores sintéticos completos. No promover los existentes, inferir cuentas reales, publicar, emitir estados ni sustituir reportes. Esta fase se detiene aquí.
