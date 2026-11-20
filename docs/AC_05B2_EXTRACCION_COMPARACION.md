# AC-05B2 — Extracción y comparación paralela contable

Fecha: 19 de noviembre de 2026. Estado: implementado y verificado como ensayo interno de lectura en `salon_erp_test`. No sustituye resultados, balance, dashboard ni cierre fiscal. No crea clasificación persistente o autorización económica empresarial.

## 1. Preflight y respaldo

Antes de las pruebas SQL se verificaron PHP8.3.30, MySQL8.4.3, cuenta `salon_erp_tester@localhost`, puerto3306, roles NONE y privilegios restringidos existentes. Los intentos de SELECT en desarrollo/bootstrap fueron denegados con1142. Se cotejaron82 migraciones y89 tablas, InnoDB en tablas pertinentes y DECIMAL(15,2) en debe/haber. No se cambiaron privilegios ni configuración del proyecto.

Respaldo AC-05B1 comprobado nuevamente:

| Elemento | Evidencia |
| --- | --- |
| Archivo | `E:/salon-erp-backups/salon_erp_test_AC05B1_20261119_225424_43e6c3abf4dc.sql` |
| Tamaño | 1.536.280 bytes |
| SHA256 | `85d77381db1a51387e020482fab4a752c116c5c39616278991f452c56fe430b6` |
| Verificación | Tamaño, manifiesto, hash y sidecar válidos; no se restauró. |

La línea base nueva es `storage/app/private/ac05b2-isolation-before.json`. El mecanismo administrativo existente consulta las tres bases para comparar hashes; no es la identidad de las pruebas ni una conexión alternativa del extractor. El documento131 sigue prepared, banco221 y sin resultados. Reset0 comprobado y restablecido por finally.

## 2. Arquitectura y contrato público

Se añadieron dos servicios pequeños:

- `AccountingReportExtractor::extract($classification, $parameters)`: extrae y clasifica en un snapshot.
- `AccountingReportExtractor::compare($classification, $parameters)`: añade la lectura heredada y la comparación dentro del mismo snapshot.
- `AccountingPresentationComparison::read(...)`: compara en memoria; delega todos los signos y agregaciones a `SyntheticAccountingPresentation`, también para el adaptador de lectura heredada.

No hay rutas, vistas, comandos empresariales, cambios en el clasificador AC-05B1 ni un motor nuevo de asientos. Los servicios no invocan contabilización, resolverPeriodo, cierreFiscal, escritores bancarios ni publicación de políticas. La única integración con controladores existentes es invocar sus métodos lectores y obtener los datos de sus vistas, sin renderizarlas.

La extracción exige simultáneamente entorno testing, bootstrap de pruebas existente, reset deshabilitado, conexión por defecto mysql, base exacta test, usuario configurado restringido y comprobación SQL de base/identidad/puerto. El actor autenticado debe estar activo y tener rol alvaro o contador. No se amplían permisos; la instalación ordinaria carece de esta frontera y no tiene un endpoint nuevo.

## 3. Parámetros explícitos de extracción

```php
[
    'report' => 'result', // o balance
    'from' => '2035-08-01', // NULL para balance
    'until' => '2035-08-31',
    'states' => ['borrador', 'contabilizado', 'anulado'],
    'null_origin' => 'include', // o exclude
    'account_scope' => [/* IDs explícitos del fixture */], // o all
    'center_scope' => 'all', // unassigned o lista de IDs
    // 'period_id' => ID existente, opcional únicamente en resultados
]
```

Se rechazan claves adicionales, scopes vacíos/duplicados, IDs inválidos/inexistentes, estados ambiguos, fechas inválidas y un balance acotado por inicio o período. Las cuentas inactivas y centros inactivos siguen siendo referencias válidas de lectura histórica. Una lista de cuentas es un subconjunto declarado; no se interpreta como autorización empresarial ni se añade al filtro de los reportes visibles.

`extract` admite rangos de resultados arbitrarios. `compare` exige un mes calendario completo para resultados, porque el método heredado solo ofrece mes o todo el histórico. No se etiqueta un rango parcial como una comparación mensual equivalente. Balance compara acumulado hasta fecha. No se añadió una comparación heredada `mes=todo` en esta fase.

La clasificación es el contrato sintético versionado de AC-05B1, con asignaciones explícitas por ID y purpose simulation. Se valida incluso si no se extraen líneas. No se aceptan asignaciones duplicadas ni se resuelven cuentas desde códigos, nombres o bindings para construir la propuesta.

## 4. Consistencia de lectura

Sin transacción exterior, el extractor fija las características de la **siguiente** transacción a REPEATABLE READ y READ ONLY, inicia una transacción, consulta los datos y reportes heredados y confirma únicamente la lectura. No cambia permanentemente el aislamiento de sesión ni adquiere locks exclusivos/administrativos. MySQL rechazó con1792 una escritura de control dentro de ese snapshot.

En fixtures DatabaseTransactions se reutiliza el snapshot de su transacción exterior con un nivel anidado; se exige configuración de sesión REPEATABLE-READ. Esa vía permite observar las escrituras propias del fixture, que finalmente se revierten. No es una transacción SQL READ ONLY: la salida lo declara mediante `read_only_transaction=false` y `enclosing_fixture_transaction=true`. Una configuración READ-COMMITTED se rechaza, sin cambiarla silenciosamente.

La vía anidada está destinada a los fixtures controlados de esta fase, iniciados con el aislamiento conocido del arnés. No certifica transacciones arbitrarias cuyo aislamiento efectivo haya sido sobreescrito con SET TRANSACTION antes de iniciarse: la variable de sesión por sí sola no prueba esas características particulares. Una futura interfaz operativa deberá utilizar la vía propietaria de solo lectura o definir un contrato verificable adicional para una transacción externa. No se habilitó tal interfaz.

El snapshot se establece con la primera lectura consistente InnoDB, que puede ser la validación del scope antes de leer los detalles. Las consultas posteriores comparten ese punto de visibilidad. El corte contable es el filtro por `asiento.fecha`; no significa «todo lo que se contabilizará eventualmente con esa fecha». Una operación que confirme después de fijar el snapshot no debe formar parte de esa lectura. Un snapshot tampoco crea una edición emitida o un archivo histórico.

La salida conserva aislamiento declarado, modalidad de transacción, ID de conexión y timestamp UTC de captura. Ese timestamp es informativo, no un identificador exacto de commit/read-view de MySQL. No se publican detalles de conexión o credenciales a una ruta HTTP.

## 5. Fuente y referencias preservadas

Una consulta une detalles con su asiento, cuenta y período originales y hace LEFT JOIN por PK al centro opcional. No hay join contra bindings, ni recorrido de descendientes, ni filtros automáticos de actividad. Cada detalle aparece una sola vez y el clasificador rechaza IDs duplicados.

Se conservan:

- IDs de detalle, asiento, cuenta, período y centro.
- Fecha, origen nullable, estado, número y referencia del asiento.
- Debe/haber como cadenas DECIMAL originales; moneda y referencia de tasa, sin conversiones.
- Descripción, fecha de registro del asiento y tipo_asiento.
- Referencias nullable originales de versión/hash de política y autorización; no se vuelven a resolver ni se rellenan.
- Estado/fechas del período y metadatos actuales de cuenta/centro.

Los nombres, tipo, naturaleza, código, actividad y padre se etiquetan `current_account`; los del centro, `current_center`. Son metadatos visibles en el snapshot, no una afirmación de cuáles eran sus nombres cuando se emitió el documento. La identidad y los importes originales no cambian.

`source_details` es el conjunto candidato por rango/corte y scope de cuentas. Los filtros propuestos de centro, estado, origen y período se aplican después explícitamente. Esto permite comparar contra la consulta heredada sin imponerle silenciosamente los filtros nuevos.

## 6. Universo de resultados

El rango de fecha es inclusivo; cierre_fiscal se excluye por el contrato del clasificador. Estado, origen NULL, centro y período se seleccionan expresamente. Se conservan contrapartidas clasificadas para trazabilidad y sumas de detalles, aunque solo los grupos nominales formen bruto/operativo/resultado.

La selección heredada se obtiene del **método real** `ContabilidadController::resultados` para el mes: sus IDs de detalles determinan la pertenencia heredada. El comparador no cambia su SQL ni los criterios de imputabilidad, prefijos4–7, origenNULL o estados. Sus grupos por código se usan únicamente en el adaptador identificado como legacy, nunca para asignar la propuesta.

El resultado heredado contiene sus líneas nominales, mientras el dataset propuesto puede incluir contrapartidas de activo/patrimonio. Esas diferencias de pertenencia son explicables, no una falta de cuadre del escritor. No se exige que un subconjunto de resultados tenga iguales sumas D/H que el diario completo.

## 7. Universo de balance

Se acumulan candidatos hasta la fecha inclusive, sin inicio ni filtro por período. Cierre_fiscal permanece incluido. Los estados y orígenes propuestos son explícitos; la consulta heredada no aplica esos filtros.

Se invoca el método real `balanceGeneral` para conservar sus totales publicados por la vista. Para la trazabilidad de sus detalles, el adaptador selecciona del conjunto candidato todas las cuentas imputables, incluido el nominal: el controlador no devuelve los detalles nominales que utiliza para calcular su fila virtual de resultado. Los grupos reproducen su primer carácter1/2/3/4/5/6 y fallback restante a otros. Esto se etiqueta como adaptación de la lectura vigente, no como clasificación recomendada.

El balance sintético conserva patrimonio explícito y resultado pendiente sin persistir una cuenta3.99. Un cierre corregido de utilidad90 deja activo90, patrimonio trasladado90 y resultado pendiente0; resultados conserva90 al excluirlo.

## 8. Comparación por grupos, cuenta y detalle

La salida separa:

| Campo | Significado |
| --- | --- |
| `synthetic` | Clasificación propuesta de ensayo, con grupos, cuentas y detalles originales. |
| `legacy` | Adaptación en centavos del conjunto heredado dentro del scope declarado. |
| `legacy_reported_global` | Totales reales devueltos por los controladores, sin restringirlos al scope de cuentas del ensayo. Conserva el formato numérico heredado. |
| `differences.groups_cents` | Diferencia sintético−heredado por grupo. |
| `differences.accounts` | Ambos grupos y aportaciones por cuenta ID, con deltas de D/H/contribución. |
| `differences.details` | Ambas referencias completas por detalle ID, grupos, deltas exactos D/H y motivo tipado de diferencia. |
| `parameters`, versión/hash | Universo declarado e identidad de la clasificación hipotética. |
| `snapshot`, `source_details` | Procedencia técnica de la extracción y candidatos originales. |

Con scope parcial, no se resta un subtotal del ensayo de un total global como si fueran universos iguales: se advierte `comparison_is_explicit_account_subset`. Los grupos exactos se calculan con SyntheticAccountingPresentation para ambas partes. Los floats que devuelve la vista heredada permanecen separados; no se presentan como un nuevo cálculo exacto del motor.

Los motivos distinguen código no cubierto por la consulta heredada, cuenta no imputable, NULL excluido por resultados, filtros explícitos de estado/período/centro y cambio de grupo sintético frente al código legado. Una diferencia de pertenencia no explicada genera advertencia, no se oculta. Detalles presentes en ambas partes deben conservar exactamente sus campos; una modificación inesperada provoca error. Los deltas rechazan desbordamiento de centavos enteros.

Si falta clasificación de un detalle relevante, la propuesta queda `rejected_missing_classification`, con IDs de cuentas faltantes, `synthetic=null` y sin diferencias parciales presentadas como éxito. La lectura heredada puede consultarse con la advertencia. No se completa automáticamente la cobertura. Una asignación duplicada/contradictoria se rechaza antes de extraer.

## 9. Diferencias verificadas

| Fixture nuevo | Lectura propuesta y contraste |
| --- | --- |
| IngresoINC-, activoACT-, gastoEXP-, costoCST- | Por ID tienen su grupo explícito. El balance heredado envía códigos alternativos a otros; resultados omite las cuentas fuera de sus prefijos. No se cambian sus códigos. |
| Ingreso100/costo20/gasto10 alternativos | Propuesta: bruto80, operativo70, resultado70; el universo nominal heredado de esas cuentas queda vacío. Se explican también las contrapartidas. |
| Correctora de ingreso10 | Contribuye−10 dentro de ingreso, sin cambiar el debe original. |
| Contraactivo10 y gasto acreedor5 | Contribuciones−10/−5 en sus grupos, conservando cuentas y haber. |
| Cuenta inactiva con historia | Se extrae y compara sin exigir actividad para su lectura. |
| Padre no imputable sin movimientos e hijo40 | Aporta40, sin subtotal duplicado. |
| Padre/hijo con movimientos y reversa | Se conservan sus seis detalles; neto costo0 y D/H60 por lado en el conjunto completo. |
| Dos bindings reales hacia una cuenta del fixture | No multiplican el detalle: el extractor no los une. La política es un draft sintético nuevo dentro de la transacción. |
| IngresoNULL10, borrador20, anulado30 y confirmado40 | Propuesta con todos/NULLinclude:100; heredado:90. Filtro confirmado/período/centro del fixture:40, sin reducir la lectura heredada90 a la fuerza. Se comparan IDs exactos. |
| Fecha de agosto/período de julio | Se conserva la discrepancia original del fixture para distinguir fecha y periodo_id; no se regulariza. |
| Centro histórico inactivo y sin asignar | Se admite su ID y se conserva su referencia; filtros explícitos separan ambos conjuntos. |
| Cargo50 y devolución50 | Proyección contable nueva de ensayo: neto de gasto0, referencias originales conservadas; no se reconoce ingreso por una devolución. No se invoca el escritor bancario. |
| Cierre corregido AC-05C0 | Se representa con un asiento nuevo de fixture, con correctora al haber10, no debe−10. Resultados excluye sus tres líneas; balance las incluye y conserva patrimonio90/residual0. No se llama al cierre desde el extractor. |

Los fixtures de cargo/devolución son proyecciones contables con cuentas y vínculos de origen nuevos, no una ejecución de autorización, saldo bancario o devolución AC-04. No se atribuye una política empresarial a esos documentos. Las referencias de política existentes se preservan cuando están presentes, y NULL permanece NULL.

## 10. Ensayo concurrente de lectura y límites de la evidencia

`AccountingReportSnapshotTest` ejecutó un lector propietario READ ONLY y un escritor de fixtures en otro proceso PHP/PDO con la misma identidad restringida. El hijo creó un período, asiento y dos detalles nuevos de un centavo, permaneció dentro de su transacción y recibió orden de rollback. Referenció cuentas/actor existentes sin modificarlos; no reutilizó un documento AC-04 mutable ni una carrera financiera persistente.

Evidencia final: `storage/app/private/ac05b2-snapshot-c1a40dd1b0.json`:

- Conexión lectora462, escritora463, distintas.
- ID de asiento de fixture2481, nunca confirmado y ausente al terminar.
- READ ONLY rechazó con1792 el control `UPDATE ... WHERE id=0`, sin modificar fila alguna.
- El dataset de lectura y los totales heredados permanecieron iguales durante la escritura pendiente.
- El hijo confirmó su rollback, terminó con exit0 y se cerraron sus pipes/proceso.
- Conjuntos originales de detalles intactos; transacciones del padre cerradas.

**Límite explícito:** se probó concurrencia real con escritura no confirmada y rollback. No se ensayó un commit financiero concurrente, para no dejar nuevos documentos persistentes ni borrar evidencia para limpiar el test. REPEATABLE READ proporciona el contrato de visibilidad de commits posteriores, pero este ensayo no se presenta como una prueba empírica de ese escenario ni como seguridad concurrente de un escritor financiero. Tampoco prueba una publicación/revocación empresarial, que no participa en el lector.

## 11. Pruebas definitivas

| Grupo | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| AccountingReportExtractionTest | 27 | 143 | Aprobado |
| AccountingReportSnapshotTest | 1 | 20 | Aprobado |
| AccountingPresentationTest + AccountingReportCharacterizationTest | 34 | 123 | Aprobado |
| FiscalClosingSignsTest | 29 | 556 | Aprobado |
| **Total definitivo** | **91** | **842** | **Aprobado** |

Los resultados intermedios no se suman. Se ejecutó de nuevo el grupo extractor y el ensayo de snapshot tras las últimas modificaciones de campos y diagnósticos. No se modificaron expectativas anteriores.

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 tests/Support/run-ac04b1-tests.php extract-preflight
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 tests/Support/run-ac04b1-tests.php extract-core
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 tests/Support/run-ac04b1-tests.php extract-race
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 tests/Support/run-ac04b1-tests.php closing-regressions
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 tests/Support/run-ac04b1-tests.php closing-core
```

Los fixtures secuenciales usan DatabaseTransactions, sin seeders. Las lecturas se ejecutan además bajo un observador que rechaza DML; el ensayo propietario verifica READ ONLY en el motor. Se prueban scopes inexistentes, permisos de operador ordinario, aislamiento incompatible, clasificación faltante/duplicada, rangos no comparables y preservación de importes/referencias.

Las regresiones AC-05B1 incluyen guardas relevantes AC-02 y cardinalidad de bindings AC-03. FiscalClosingSignsTest ejecuta sus cierres únicamente sobre períodos nuevos revertibles como regresión de AC-05C0; **el extractor no los invoca**. No se ejecutaron suites/carreras AC-03/04 adicionales: no cambian sus contratos y se conservó la evidencia previa. No se ejecutó AccountIntegrityTest completo por sus seeders globales.

## 12. Bases, guardias y preservación

La comparación final de hashes de filas y esquema arrojó `OTHER_DATABASES_EXACT=True` y `OLD_ROWS_PRESERVED=True`. El modo AC05B2 no permite filas persistentes adicionales en test; compara conjuntos de hashes, no solo recuentos.

| Base | Tablas antes/después | Filas antes/después | Migraciones antes/después |
| --- | ---: | ---: | ---: |
| salon_erp | 78/78 | 225/225 | 74/74 |
| salon_erp_test | 89/89 | 1.873/1.873 | 82/82 |
| salon_erp_bootstrap_check | 78/78 | 202/202 | 74/74 |

Solo pueden avanzar AUTO_INCREMENT por fixtures revertidos; el hash de esquema normaliza ese contador, sin cambiar columnas o restricciones. No se corrigieron secuencias ni se borraron documentos para lograr igualdad. Preparado131, autorizaciones, extractos y demás historia conservados. No hubo DDL, restauración, despliegue, nueva ruta o activación empresarial. Reset0 se mantuvo.

## 13. Archivos y diff propio

| Archivo | Cambio AC-05B2 |
| --- | --- |
| `app/Services/AccountingReportExtractor.php` | Nuevo lector interno, snapshot y adaptación de consultas heredadas. |
| `app/Services/AccountingPresentationComparison.php` | Nuevo comparador puro por IDs/centavos que reutiliza el clasificador. |
| `tests/Feature/AccountingReportExtractionTest.php` | Nuevos fixtures/pruebas de extracción y diferencias. |
| `tests/Feature/AccountingReportSnapshotTest.php` | Ensayo con procesos independientes, READ ONLY y rollback. |
| `tests/Support/ac05b2-snapshot-writer.php` | Hijo CLI de fixtures nuevos, obligatoriamente revertidos. |
| `tests/Support/run-ac04b1-tests.php` | Modos extract-* reutilizando preflight/respaldo existentes. |
| `tests/Support/ac04b1-isolation.php` | Fase AC05B2 con comparación sin persistencias nuevas permitidas. |
| Este documento | Contrato, resultados y límites. |

El árbol ya contenía cambios AC-05B1/AC-05C0 sin commit. Se preservaron: SyntheticAccountingPresentation, controlador de cierre, pruebas y documentos previos y auxiliar de respaldo no se editaron en esta fase. Los nuevos servicios no están conectados a rutas ni reportes productivos. Diff check sin errores de espacios; las advertencias CRLF/LF de auxiliares Windows no indican cambios económicos.

Revisar archivos nuevos completos y `git diff -- tests/Support/run-ac04b1-tests.php tests/Support/ac04b1-isolation.php`; ese diff también contiene hunks de fases anteriores, que deben distinguirse. No se hicieron commits.

## 14. Reversión segura y riesgos pendientes

Para retirar únicamente AC-05B2, eliminar sus cinco archivos nuevos de código/pruebas/arnés y retirar modos extract-* y fase AC05B2 de los dos auxiliares, conservando hunks anteriores. Mantener documentación/evidencia privada como auditoría. No ejecutar reset general, restauración, migraciones down o limpieza de cuentas/asientos.

Limitaciones: los reportes se materializan en memoria y las consultas heredadas pueden leer muchas cuentas; no se certificó rendimiento empresarial ni paginación histórica. No hay archivo de edición emitida, clasificación persistente, aprobación empresarial o sustitución de pantallas. La comparación de un scope parcial no certifica los estados globales. Metadatos actuales no reconstruyen etiquetas históricas. Los hallazgos separados de AC-05A (medios de pago, libro300, inventario comercial, comisiones y vistas históricas negativas) siguen intactos.

## 15. Recomendación de siguiente fase

Solicitar autorización nueva antes de persistir o presentar una clasificación empresarial. El contador deberá definir grupos, correctoras, costo/gasto/otros, universo de estados/NULL, fechas/períodos y política de edición/reexpresión histórica. Conservar como lectura paralela hasta aprobar esas decisiones y resolver consistencia de cualquier transacción externa no controlada.

Si se requiere demostrar visibilidad ante commits concurrentes, acordar primero un arnés y una política de evidencia nueva que no use historia previa como fixture desechable. No convertir la prueba rollback-only en autorización de escrituras persistentes.

AC-05B2 termina aquí, sin sustitución de reportes, AC-06, H01-B2B, clasificación persistente, habilitación empresarial o despliegue.
