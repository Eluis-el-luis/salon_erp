# AC-05B3B3 — Contrato versionado y presentación sintética

Fecha: 20 de noviembre de 2026. **Estado: completada como integración pura en memoria.** No hay conexión SQL, publicación, emisión, clasificación empresarial o sustitución de reportes. No se creó otra implementación de signos o saldos.

## 1. Inspección y compatibilidad observada

Se inspeccionaron FinancialPresentationContractValidator, FinancialPresentationCanonicalizer, SyntheticAccountingPresentation, AccountingPresentationComparison, AccountingReportExtractor, SyntheticFinancialPresentationDraftRepository, ReportPresentationFixtures y los contratos/documentos B0/B1/B2/R.

Hechos determinantes:

- B1 valida un contrato con siete raíces económicas, subgrupos de base heredada, asignaciones por ID y perfiles diferenciados.
- El motor sintético recibe asignaciones planas a esas mismas siete raíces. Ya calcula centavos, correctoras, subtotal/resultados, balance y selección por fecha/estado/origen/período.
- El motor no filtra por account_scope o center_scope. El extractor sí resuelve esos scopes, pero no se invoca desde esta integración pura.
- El motor no calcula subtotales de subgrupos ni consume su orden/etiquetas. Esa estructura se conserva como evidencia, sin inventar importes jerárquicos.
- El repositorio almacena borradores y auditorías, pero su estado no equivale a una publicación. No necesita modificarse para este ensayo en memoria.
- El comparador y los fixtures visuales anteriores tienen otros consumidores; no se cambiaron ni se conectaron al adaptador automáticamente.

## 2. Arquitectura y API

Nuevo componente: `FinancialPresentationSyntheticAdapter`.

```php
read(array $content, string $report, array $fixture): array
```

Secuencia:

1. Validar contenido mediante FinancialPresentationContractValidator y obtener contenido canónico/hash originales.
2. Exigir purpose simulation, origin synthetic_fixture e instalación TEST-FIXTURE-ONLY; seleccionar expresamente result o balance.
3. Validar identidad y base declarada del fixture, sin consultar cuentas o políticas.
4. Comprobar capacidades del perfil seleccionado; rechazar scopes no soportados.
5. Traducir cada asignación a su raíz y conservar su ruta y anotaciones.
6. Invocar **una vez** SyntheticAccountingPresentation::read, sin replicar sus cálculos ni sus filtros documentales.
7. Obtener cobertura del universo seleccionado por ese motor y completar el diagnóstico B1 con esos IDs.
8. Devolver resultado, configuración derivada y evidencia original, siempre sin autoridad empresarial.

El fixture es un objeto cerrado con `id`, `base_currency`, `amount_basis`, `details`. ID obligatorio `SYNTHETIC:<identificador>`, detalles como lista de arrays, base NIO/original_ledger_base compatible con el perfil. Campos de autorización, cuentas elegidas fuera del contrato, política o estado adicionales se rechazan. No recibe conexión, repositorio, usuario o configuración global.

## 3. Transformación de grupos y asignaciones

La asignación versionada `{account_id, group_key, order, corrector, ...}` se transforma en `{account_id, group}` para el contrato existente synthetic-presentation-v1. El grupo es la raíz explícita de group_key, no el prefijo del código o el padre contable.

El recorrido usa exclusivamente la jerarquía ya validada por B1: sin ciclos, padre existente y base idéntica en cada nivel. No suma subgrupos, reparte importes ni evalúa fórmulas. Cada ID produce exactamente una asignación; padre e hijo contables con movimientos propios siguen siendo cuentas distintas.

Ejemplo del fixture: cuenta704 asignada a contra_income, subgrupo de income. Configuración económica derivada: income. Evidencia conservada: asignación completa, ruta `[income, contra_income]`, base credit_minus_debit, anotación corrector y orden original.

`source.content` conserva todos los grupos, etiquetas, órdenes y perfiles del contrato canónico. `assignment_provenance` conserva la transformación por ID. La salida declara `economic_groups=roots_only` y `subgroup_totals=false`: **no ofrece un estado detallado de subgrupos ni oculta esa limitación**. No se calculan subtotales adicionales sobre los subtotales del motor.

No se deducen cuentas por nombre, código, naturaleza, actividad, bindings AC-03 o primer registro disponible. No se introdujo otra tabla, política o resolutor.

## 4. Perfiles y restricciones explícitas

| Capacidad del perfil | Tratamiento |
| --- | --- |
| Resultados | from/until explícitos, estados y origen NULL declarados; cierre_fiscal excluido por el motor. |
| Balance | from=NULL, corte acumulado, sin período aislado; cierre_fiscal incluido por el motor. |
| period_id opcional de resultados | Se transmite si no es NULL; no se inventa un período ni se crea en SQL. |
| Estados y NULL | Se transmiten sin defaults nuevos. No se convierten borrador/anulado/NULL en otra cosa. |
| account_scope distinto de all | Rechazo `adapter.unsupported_account_scope`. |
| center_scope lista o unassigned | Rechazo `adapter.unsupported_center_scope`. |
| Base NIO y original_ledger_base | El fixture debe declararlas de manera compatible. No se convierte moneda ni se deduce valor nativo desde currency_id. |
| Inactivas con historia | B1 exige inclusión y el motor no las filtra por actividad. |
| Subgrupos con base incompatible | B1 rechaza antes del cálculo; no se adapta cambiando el signo. |
| Perfil desconocido | Rechazo `adapter.unknown_profile`, sin seleccionar otro automáticamente. |

La compatibilidad se evalúa para **el perfil solicitado**. El otro perfil permanece íntegro en el contenido fuente; no se afirma que una lectura exitosa de balance habilite un perfil de resultados con scopes no soportados. No se prefiltra el fixture para aparentar soporte de esas capacidades.

Las fechas contables y la vigencia del contenido se conservan como dimensiones diferentes. No se utiliza reloj actual, política vigente, publicación o fecha de almacenamiento para activar una versión o reconstruir historia.

## 5. Signos, correctoras e importes

Toda aritmética pertenece al motor existente. El adaptador no convierte DECIMAL a centavos, no suma debe/haber y no calcula resultado o diferencia de balance. Conserva los detalles que devuelve el motor sin reescribirlos.

Corrector es una anotación; no se multiplica por−1. El ingreso deudor contribuye negativamente por H−D y el contraactivo acreedor por D−H. Costo, gasto operativo y otros gastos siguen siendo grupos separados.

El canonicalizador se reutiliza para la huella del fixture y rechaza floats/UTF-8 inválido. Los importes originales se validan mediante el motor: cadenas DECIMAL exactas, sin redondear una precisión inválida. No se crea ninguna línea contable o movimiento financiero.

## 6. Cobertura y universo

La cobertura se comprueba después de la selección **del motor**, sin copiar su predicado de fecha/estado/origen/período. Una línea seleccionada con debe o haber no nulos exige asignación, aunque otras líneas compensen su saldo. También se exige clasificación de las contrapartidas seleccionadas de activo/patrimonio: no se declara cobertura examinando solo resultados nominales.

Si falta una asignación relevante, se rechaza toda la lectura con `adapter.incomplete_coverage`, conservando la excepción original como causa. No se devuelve un estado parcial ni se asigna un grupo automático.

La salida identifica cuentas y detalles incluidos. El contrato del motor permite omitir una línea no clasificada con ambos importes0,00: no se presenta como incidencia económica ni se cambia ese comportamiento. La regla queda explícita como `engine_selected_nonzero_details_require_assignment`; las líneas cero clasificadas conservan su comportamiento habitual.

Un movimiento no clasificado anterior al rango de resultados no invalida ese rango, pero sí provoca rechazo en balance si pertenece a su corte acumulado. Esto se comprueba usando el propio motor, no otro selector.

`scope=declared_fixture_not_SQL_global`: cobertura completa significa completa para el fixture y perfil declarados. No certifica un mayor SQL, la empresa o una edición emitida.

## 7. Procedencia, hashes y autoridad

Cada salida contiene:

- `source`: financial-presentation-v1, UUID de familia, número de versión, content_hash, canonical_json y contenido canónico íntegro.
- `fixture`: identidad sintética, huella propia, base monetaria y procedencia synthetic_memory_only.
- `profile` y `universe`: perfil exacto y parámetros utilizados por el motor.
- `coverage`: estado complete y conjuntos seleccionados por ID.
- `assignment_provenance` y `engine_configuration`: correspondencia exacta hacia raíces.
- `presentation`: resultado del motor con su classification_hash derivado, **distinto del hash fuente versionado**.
- `validation_status` y `diagnostics`: decisiones pendientes B1 conservadas.
- `business_authority=false`, `publishable=false`, `issued_statement=false`.

El hash fuente lo calcula B1; no se sustituye por el hash del motor ni se acepta un content_hash arbitrario dentro del contrato. La versión derivada del motor incluye familia, número de versión y hash fuente, conservando identidad incluso si dos hipótesis producen iguales resultados netos.

El hash de fixture identifica la representación en memoria, incluidos sus detalles/metadatos. No es firma, prueba de recepción bancaria o evidencia de una fuente SQL. Sus declaraciones NIO/synthetic son declaraciones de un fixture, no autenticación económica.

Un contrato estructuralmente válido con pending_decisions puede calcular una **hipótesis**, pero mantiene REQUIRES_ACCOUNTING_APPROVAL y sus diagnósticos. Cobertura completa no borra esa condición. No existe publicación ni acto de aprobación.

No se consume el estado de un documento persistido como autoridad. Inyectar status=frozen_simulation o campos de aprobación en el contenido provoca rechazo de B1 por campos desconocidos. Extraer en el futuro el contenido de un borrador frozen no convertiría ese contenido en política aprobada; esta API no tiene tal capacidad.

## 8. Fixtures nuevos y expectativas independientes

`VersionedPresentationFixtures` reutiliza únicamente la estructura pura de contrato B1 y define nuevas asignaciones explícitas701–711. No son IDs de cuentas consultadas en MySQL. Los códigos ALTERNATIVE-* no determinan sus grupos.

Fixture general: capital1.000, ingreso120, correctora10, costo20, gasto30, depreciación5 contra contraactivo, gastos propios de padre/hijo2+3, manual/reversa6/6, cargo/devolución50/50, otros4, saldo acreedor de gasto7 y pasivo10 con entrada al activo.

| Magnitud | Esperado literal |
| --- | ---: |
| Ingreso neto |110,00 |
| Costo |20,00 |
| Gasto operativo |33,00 |
| Otros gastos |4,00 |
| Bruto / operativo / resultado |90,00 /57,00 /53,00 |
| Activo neto / pasivo / patrimonio |1.063,00 /10,00 /1.000,00 |
| Resultado pendiente de balance |53,00 |
| Diferencia de balance |0,00 |
| Debe / haber originales |1.323,00 /1.323,00 |
| Detalles |29, conservados una sola vez |

La cuenta inactiva conserva su historia; padre/hijo aportan2 y3 sin subtotal duplicado. Un detalle con varios bindings ficticios sigue aportando una sola vez. Cargo y devolución conservan sus signos+50/−50 en gasto y referencias documentales originales; la entrada de devolución no se clasifica como ingreso.

Fixture de cierre separado: activo90, ingreso100 y correctora10; espejo corregido al debe ingreso100, haber correctora10 y patrimonio90. Resultados conserva90 y excluye tres detalles de cierre; balance incluye los seis, patrimonio90 y resultado pendiente0. **Es una proyección nueva en memoria, no una ejecución de cierreFiscal o devolución bancaria real.**

Las cifras esperadas se escribieron como centavos literales derivados de esos documentos. No se reconstruyeron mediante otra copia del algoritmo probado. El caso alternativo costo→gasto es una modificación explícita de hipótesis: cambia margen bruto90→110 y hash, mantiene resultado53 y todos los detalles originales. No reclasifica ninguna cuenta o asiento persistido.

## 9. Pruebas definitivas

| Grupo focalizado | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| FinancialPresentationSyntheticAdapterTest |34|116|Aprobado |
| FinancialPresentationContractTest |71|270|Aprobado |
| AccountingPresentationTest, motor puro existente |26|61|Aprobado |
| **Total conjunto** | **131** | **447** | **Aprobado** |

No se suman reejecuciones. Cobertura: contrato válido/inválido, business/origen manipulado, grupo inexistente, subgrupo válido e incompatible, duplicados, fórmulas, scopes no soportados, cobertura incompleta, contrapartidas, correctoras, contraactivo, costo/gasto, inactivas, jerarquía/bindings, códigos alternativos, reversas, cargo/devolución, cierre corregido, fechas/períodos/NULL/estados, base monetaria, floats, precisión, repetición de detalle, identidad/hash, orden equivalente, decisiones pendientes y falta de autoridad.

Comando definitivo, sin configuración/bootstrap de Laravel y con clases PDO/mysqli deshabilitadas:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d disable_classes=PDO,mysqli -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result --no-progress tests/Unit/FinancialPresentationSyntheticAdapterTest.php tests/Unit/FinancialPresentationContractTest.php tests/Feature/AccountingPresentationTest.php
```

Lint PHP `-n -l` de los tres archivos PHP nuevos: aprobado. No se ejecutaron SQL, Artisan, migraciones, suites generales, seeders o pruebas con fixtures persistentes/revertibles. No se cargó tests/bootstrap.php, dotenv de test o una aplicación Laravel. No se creó un endpoint.

## 10. Compatibilidad y preservación del entorno

No se modificaron el validador, canonicalizador, clasificador, comparador, extractor, repositorio, fixtures visuales previos, perfiles de esquema R, cierre, reportes o autorizaciones. El nuevo adaptador no tiene consumidores operativos: solo las pruebas puras nuevas lo invocan.

No se usa AccountingPresentationComparison para calcular una segunda propuesta, ni se cambia el contrato de entrada del extractor. Una futura conexión de lectura requerirá seleccionar explícitamente un perfil compatible y conservar los dos hashes; no debe pasar content financiero como si ya fuera synthetic-presentation-v1.

No se leyó ni editó un borrador persistido: no fue necesario para demostrar la integración pura. Por tanto no se expuso el getter con locks del repositorio, no se crearon auditorías y no se repitieron helpers que reservan manifiestos anteriores.

Las91 tablas/1.963 filas de test y la preservación de desarrollo/bootstrap son antecedentes aceptados de R; **no se vuelven a certificar mediante SQL en B3**. Esta fase no abrió conexiones, por lo que no realizó operaciones que pudieran alterarlas. Reset0 se comprobó por lectura puntual; no se modificaron credenciales, APP_KEY, configuración o dependencias.

## 11. Archivos modificados

Únicos archivos nuevos de esta fase:

```text
app/Services/FinancialPresentationSyntheticAdapter.php
tests/Support/VersionedPresentationFixtures.php
tests/Unit/FinancialPresentationSyntheticAdapterTest.php
docs/AC_05B3B3_INTEGRACION_CONTRATO_PRESENTACION.md
```

Árbol inicialmente limpio, HEAD `932e37958e88f29645c6bb8ffbb4b46668f65361`. No hubo modificaciones de archivos rastreados, staging, commits o push. El propietario conserva la gestión de Git. Revisar los cuatro archivos nuevos completos: git diff ordinario no muestra su contenido mientras sigan sin seguimiento.

## 12. Riesgos y reversión segura

- Los scopes de cuentas/centros y subtotales de subgrupos siguen fuera de la capacidad calculadora del motor; no se añadió otra aritmética para simularlos.
- La cobertura de un fixture no prueba completitud SQL. Su identidad/hash no autentican que los datos procedan de documentos económicos reales.
- No hay archivo de edición emitida, aprobación, publicación o selección de política empresarial vigente.
- La compatibilidad temporal es la del perfil explícito; vigencia, retirada, versiones históricas y autorización de emisión no se deciden por el reloj.
- Los errores del motor siguen siendo rechazos; solo la cobertura incompleta se identifica con un código del adaptador. No se introducen fallbacks.

Si se autoriza retirar esta fase, revisar y retirar únicamente sus cuatro archivos nuevos, preservando toda la evidencia y código previo. No hay SQL, migración, documento o auditoría que revertir; no se requieren restauraciones o compensaciones.

## 13. Recomendación de continuación

Una futura fase autorizada podría ensayar la lectura de contenido verificado desde un borrador sintético, conservar su hash/revisión y aplicar este adaptador sin mutaciones. Si se requieren scopes o presentación de subgrupos, definir primero si habrá selección lectora previa o una evolución explícita del contrato del motor; no ignorar filtros ni duplicar cálculos.

AC-05B3B3 termina aquí. No continúa automáticamente a aprobación/publicación empresarial, emisión, rutas HTTP, migraciones, sustitución de reportes, AC-06, H01-B2B o despliegues.
