# AC-05B1 — Caracterización y clasificación sintética de presentación

Fecha: 19 de noviembre de 2026. Estado: completado dentro del alcance de ensayo. No sustituye reportes, cierre ni asociaciones de contabilización. No autoriza una clasificación empresarial.

## 1. Preflight, respaldo e identidad

Se reutilizaron los auxiliares de respaldo, preflight y aislamiento añadiendo el modo AC05B1. Las pruebas SQL utilizaron exclusivamente `salon_erp_tester@localhost`, base `salon_erp_test`, puerto 3306, roles NONE, PHP 8.3.30 y MySQL 8.4.3. Se comprobaron los privilegios exactos existentes y la denegación de SELECT sobre `salon_erp.users` y `salon_erp_bootstrap_check.users` (1142). No se modificaron permisos SQL.

El preflight cotejó las 82 migraciones aplicadas con los archivos; verificó InnoDB en las tablas de cuentas, asientos, detalles, períodos y bindings, y `DECIMAL(15,2)` en debe/haber. El preparado ID131 conserva estado prepared, banco221 y resultados financieros NULL. `SALON_TEST_DB_RESET_ALLOWED=0`, comprobado y restablecido por los auxiliares mediante finally.

Respaldo nuevo, sin sobrescribir anteriores:

- Archivo: `E:/salon-erp-backups/salon_erp_test_AC05B1_20261119_225424_43e6c3abf4dc.sql`.
- SHA256: `85d77381db1a51387e020482fab4a752c116c5c39616278991f452c56fe430b6`.
- Tamaño: 1.536.280 bytes; 89 tablas; 1.873 filas; 82 migraciones.
- Manifiesto: `storage/app/private/ac05b1-backup.json`; sidecar `.sql.sha256`.
- Dump consistente con single-transaction, sin bloqueos de tablas; salida cero, estructura y marcador final comprobados, fuente sin cambios antes/después, hash y sidecar verificados. No se restauró para probarlo. Se conservó el aviso conocido de mysqldump; no se proporcionó un secreto en argumentos.

El primer intento de respaldo fue denegado por el sandbox al reservar el archivo externo; no se ejecutaron pruebas SQL antes de resolverlo. Se obtuvo autorización para el mismo auxiliar y se generó el respaldo indicado. El auxiliar de respaldo y la línea base usan el mecanismo administrativo local existente para lecturas/exportación; no es una conexión de fallback de las pruebas. Las pruebas y sus escrituras transaccionales usan siempre la cuenta restringida.

## 2. Archivos y frontera implementada

| Archivo | Cambio |
| --- | --- |
| `app/Services/SyntheticAccountingPresentation.php` | Lector puro en memoria; no conexión SQL, persistencia, contenedor de políticas o autoridad financiera. |
| `tests/Feature/AccountingPresentationTest.php` | 26 casos puros, sin bootstrap de Laravel ni SQL. |
| `tests/Feature/AccountingReportCharacterizationTest.php` | 8 casos con DatabaseTransactions, fixtures nuevos, lecturas reales de controladores/servicio y regresiones delimitadas. |
| `tests/Support/ac04b1-backup.php` | Admite fase AC05B1 con 82 migraciones; conserva el mecanismo de respaldo existente. |
| `tests/Support/ac04b1-isolation.php` | Admite fase AC05B1 sin tablas ni filas persistentes adicionales permitidas. |
| `tests/Support/run-ac04b1-tests.php` | Modos reports-preflight/core/regressions/bank; respaldo nuevo, esquema y preparado131. |
| Este documento | Resultados, diferencias y límites. |

No se modificaron ContabilidadService, cierreFiscal, AccountIntegrityService, BancoController, FondosService, vistas, rutas, catálogo persistido, migraciones ni permisos. No hay consumidor operativo del clasificador ni endpoints nuevos. No se generó una política de presentación persistente ni empresarial.

## 3. Contrato público del lector sintético

`SyntheticAccountingPresentation::read(array $classification, iterable $details, array $parameters): array`.

Entrada de clasificación exclusivamente de fixtures:

```php
[
    'contract' => 'synthetic-presentation-v1',
    'purpose' => 'simulation',
    'version' => 'AC05B1-fixture-1',
    'assignments' => [
        ['account_id' => 101, 'group' => 'asset'],
        ['account_id' => 102, 'group' => 'income'],
    ],
]
```

Los números del ejemplo son ilustrativos; cada prueba obtiene IDs de su propio fixture. No son una selección del catálogo empresarial. La cuenta se asigna una sola vez; se rechazan asignaciones repetidas, grupos desconocidos y propósito distinto de simulation. No se utiliza código, nombre, naturaleza actual, jerarquía, política vigente ni bindings para deducir un grupo.

Grupos del contrato de ensayo: asset, liability, equity, income, cost, operating_expense y other_expense. Asset/cost/gastos contribuyen D−H; liability/equity/income H−D. Son reglas explícitas de esta hipótesis de presentación, no una política empresarial aprobada. Correctoras permanecen negativas dentro de su grupo cuando corresponde.

Cada detalle contiene IDs originales de detalle/asiento/cuenta/período, fecha exacta, origen explícito (incluido NULL), estado y cadenas DECIMAL de debe/haber. Puede conservar campos de evidencia adicionales; se devuelven sin modificarlos. No se reciben importes float. Se admiten importes firmados heredados para observar líneas patológicas sin normalizarlas; se rechazan exceso de precisión, formato ambiguo, fecha inválida y acumulados que desborden centavos enteros.

No se repite un detail_id, incluso si llega por dos relaciones. Un detalle relevante sin clasificación provoca rechazo, aunque su saldo pueda compensarse con otro detalle. Una línea sin importe no clasificada no aporta. Un padre sin movimientos no necesita contribuir; padre e hijo con movimientos propios contribuyen cada uno exactamente una vez. El servicio no consulta ni une tablas de bindings.

La salida incluye grupos y cuentas en centavos, detalles originales por ID, D/H totales, subtotales derivados, diferencia de balance, versión/hash de clasificación y parámetros de lectura. El hash es estable ante reordenación de asignaciones y cambia al cambiar un grupo significativo. Los subtotales visuales no se vuelven a sumar como movimientos.

`issued_statement=false`: es una lectura del conjunto recibido, no un estado emitido, certificado ni archivado. El hash identifica clasificación, no demuestra integridad de una fuente externa ni sustituye el archivo de una edición. El llamador de ensayo conserva la responsabilidad de extraer el universo completo autorizado; en los fixtures SQL se acota por IDs de asientos recién creados. No se publica un extractor empresarial.

## 4. Universos de lectura separados

| Universo | Contrato sintético |
| --- | --- |
| Resultado | Desde/hasta inclusivos; excluye siempre `cierre_fiscal`. Opcionalmente restringe período por ID para observar diferencias. |
| Balance | Acumulado hasta fecha; prohíbe inicio o filtro por período; incluye cierres y calcula resultado pendiente residual. |
| Ambos | Exigen política explícita include/exclude de origen NULL y lista de estados admitidos. No hay fallback silencioso. |

Los grupos nominales producen bruto, operativo y resultado. En balance, el patrimonio explícito más el resultado pendiente se compara con activo menos pasivo. No se crea una cuenta `3.99` ni un asiento para forzar diferencia cero.

La clasificación puede conciliar un universo que no esté cuadrado; informa su diferencia, sin corregirla. El fixture debe aportar contrapartidas y justificar su selección. En resultado se conservan también los detalles de contrapartida seleccionados para poder comprobar D/H y referencias, aunque solo los grupos nominales formen el resultado.

## 5. Caracterización comprobada de las lecturas vigentes

Se invocaron los métodos reales de ContabilidadController, PeriodoContableController y DashboardService con fixtures transaccionales. No se cambiaron sus filtros. Las comprobaciones comparan deltas respecto al estado previo cuando el reporte incorpora historia ajena al fixture.

| Caso | Consulta vigente comprobada | Lectura sintética / diferencia esperada en el ensayo |
| --- | --- | --- |
| Códigos alternativos | ACT-/ING-/GAS- quedan fuera de sus grupos esperados: resultados no incorpora ING-/GAS-; balance no añade ACT- a activos. | Con ID explícito: activo190, patrimonio100 y resultado pendiente90; diferencia0. Fixture: capital100, ingreso120, gasto30. |
| Diario | Conserva los tres documentos de ese fixture sin clasificar económicamente por prefijo. | Se conserva exactamente el conjunto de detalles y sus importes; D/H250 cada lado. |
| Mayor | Cuenta ACT- tiene neto190 por sus movimientos propios, aunque balance no la presente como activo. | Grupo asset190, misma cuenta y detalles. El prototipo no corrige el saldo inicial del mayor. |
| Prefijos 4/5/6/7 | Fixture ingreso100, correctora10, costo20, gasto10, otros5: dashboard refleja deltas90/20/10/5. | Resultado55; ingreso neto90 por las cuentas explícitas. No se sustituyó el origen de valoración del costo. |
| Dashboard panel/serie | panel devuelve los mismos KPIs que kpisRango; suma de serie mensual coincide con KPI de ingresos/costos. | Comparación con grupos del fixture; ranking, métodos y meta conservan su implementación. |
| Origen y estados | Ingreso NULL10, borrador20, anulado30, cierre40: resultados incluye20+30=50; excluye NULL y cierre. | NULL include produce60, exclude produce50, con igualdad de IDs de detalle para el universo equivalente. Balance incluye los100. |
| Fecha frente a período | Los anteriores documentos tienen fecha de abril y referencia a período de marzo cerrado, construida solo para caracterizar la lectura. Resultados de abril los selecciona por fecha. | Filtro explícito por período de abril selecciona cero. No se regularizó el documento ni se abrió un período real. |
| Jerarquía / inactiva | Padre imputable D10 e hijo imputable inactivo D20 aparecen una vez cada uno en resultados. | Gasto30, tres detalles con contrapartida; no se descarta historia ni se suma de nuevo un subtotal padre. |
| Reversas | Diario conserva cargos/refund y manual/reversa como documentos separados. | Cuentas y signos originales; gasto neto0 en misma ventana, sin tratar entrada bancaria como ingreso. |

Los fixtures de estado y período son deliberadamente anómalos para revelar la selección de la lectura; no demuestran que un escritor legítimo permita producirlos ni que existan así en datos empresariales.

## 6. Signos, correctoras, inactivas y jerarquías

Pruebas puras verifican activo100, ingreso120, gasto30, costo20, ingreso corrector−10, contraactivo−10, patrimonio contrario−10, devolución de gasto−50 y último centavo0,01. No se infiere el signo desde naturaleza o desde que el movimiento bancario sea un abono.

Padre agrupador sin detalles e hijo40 aporta40; padre directo10 e hijo20 aporta30. Bruto80 y operativo70 en fixture ingreso100/costo20/gasto10 son subtotales separados, sin duplicar el activo ni los grupos económicos.

Se registraron dos bindings reales de una política draft sintética nueva hacia una misma cuenta de costo del fixture. Un detalle D50 conservó aporte50, no100. No se editó una versión publicada ni se utilizó su política para clasificar el reporte.

La regresión AC-02 verificó que una cuenta inactiva con historia puede leerse en contexto histórico, pero no utilizarse para imputación nueva; también se rechazó cambiar el tipo de una cuenta del fixture ya utilizada. Se efectuó una reversa manual legítima del fixture mediante su ruta existente y se comprobó neto0 y preservación de los detalles originales. La reversa manual de un automático fue rechazada.

## 7. Cierre: defecto reproducido sin corrección de su escritor

Prueba: `AccountingReportCharacterizationTest::test_actual_closing_writer_emits_negative_debit_on_isolated_contra_income`.

Se creó un período nuevo y cuentas nuevas exclusivamente dentro de DatabaseTransactions. En él se registró un único asiento de fixture:

| Cuenta sintética | Debe | Haber |
| --- | ---: | ---: |
| Activo con prefijo1 | 90,00 | 0,00 |
| Correctora de ingreso con prefijo4 y naturaleza deudora | 10,00 | 0,00 |
| Ingreso con prefijo4 | 0,00 | 100,00 |

Se invocó el cierre existente **solo sobre ese período nuevo**. La transacción exterior conserva el control del rollback; el commit anidado del controlador no confirma los fixtures en la base persistente. Ningún período histórico se cerró ni se modificó. La comparación final de todas las filas verifica su desaparición.

Resultado observado: línea de cierre de la correctora `debe='-10.00'`, `haber='0.00'`. El asiento global suma debe90 y haber90 y el período del fixture queda cerrado dentro de la transacción. La validación de cuadre no detecta esta representación porque los importes firmados se compensan. Los detalles originales quedan intactos.

Condición: saldo H−D negativo en cuenta nominal clasificada como ingreso por prefijo4. El algoritmo coloca ese saldo en debe sin desplazarlo al lado contrario. Por inspección, un saldo D−H negativo en costo/gasto puede producir el caso simétrico en haber. **Solo el caso de correctora de ingreso se reprodujo ejecutando el escritor en esta fase.**

Severidad: alta para la integridad de representación de un cierre y su lectura; el cuadre por sí solo no la garantiza. La inspección de AC-05A muestra además celdas de diario/detalle condicionadas a importes positivos; no se realizó una revisión visual de esas pantallas en AC-05B1.

Después del cierre del fixture: resultado excluyendo cierres90; balance con activo90, patrimonio trasladado90 y resultado pendiente0. La lectura conserva la línea negativa original para diagnosticarla; no la invierte, reescribe o compensa.

Propuesta **separada y no implementada**: autorizar un cambio específico que represente el espejo de cada saldo en su lado no negativo, con guardas y pruebas de correctoras, devoluciones y saldos contrarios. Revisar también las vistas afectadas. No reescribir cierres existentes ni resolverlo cambiando la clasificación del reporte. Requiere alcance y autorización propios.

## 8. Pruebas ejecutadas y resultados definitivos

Comandos ejecutados mediante PHP 8.3.30 explícito, `phpunit.xml` y bootstrap seguro:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 tests/Support/run-ac04b1-tests.php reports-core
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 tests/Support/run-ac04b1-tests.php reports-regressions
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 tests/Support/run-ac04b1-tests.php reports-bank
```

| Grupo definitivo | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| AccountingPresentationTest + AccountingReportCharacterizationTest | 34 | 123 | Aprobado |
| AccountingPolicyTest + AccountingAccountResolverTest | 44 | 148 | Aprobado |
| AccountingBankChargeSimulationTest | 20 | 153 | Aprobado |
| **Total** | **98** | **424** | **Aprobado** |

Lint PHP de servicio y ambas pruebas nuevas: sin errores. Diff check: sin errores de espacios; advertencias CRLF/LF de auxiliares Windows. Los cambios posteriores al último grupo de pruebas solo ampliaron el preflight (verificado de nuevo) y documentación; no cambiaron servicio ni expectativas.

El primer intento del grupo nuevo aprobó los26 casos puros y falló al insertar el período: reloj2077 excedía TIMESTAMP de MySQL. No creó asientos. Se corrigió el fixture SQL a2037, dentro de rango; las fechas2077 de casos puramente en memoria no usan PDO. El segundo grupo, antes de añadir dos casos de regresión, pasó32 pruebas/109 aserciones. Esos resultados intermedios **no se suman** al total definitivo ni alteraron expectativas contables para ocultar diferencias.

## 9. Alcance y seguridad de las regresiones

- Se inspeccionaron las clases seleccionadas y sus traits antes de ejecutarlas: no RefreshDatabase, truncation, migraciones, seeders globales o procesos de carreras persistentes. Los casos SQL usan DatabaseTransactions.
- No se ejecutó AccountIntegrityTest completo: `InventoryH02Fixtures::actorH02` utiliza seeders globales. Las guardas relevantes se cubrieron con fixtures nuevos sin seeders.
- Las regresiones AC-03 crean drafts/publicaciones hipotéticas dentro de su transacción y comprueban bloqueo de autoridad financiera. No se modifica ninguna aprobación empresarial real.
- AccountingBankChargeSimulationTest conserva sus guardias CLI, crea bancos/operaciones nuevos de fixture y revierte sus efectos; no repite carreras persistentes ni utiliza131 como operación mutable. Su ventana transaccional no deja evidencia nueva.
- El ejemplo de cargo/refund del clasificador es una representación nueva de detalles de ensayo; no afirma haber ejecutado una devolución AC-04B2B3A completa. La regresión del cargo real sintético se ejecutó por separado con sus contratos originales. No se habilitaron rutas HTTP ni se reabrió AC-04.
- No se afirmó seguridad concurrente nueva. Este lector puro no incorpora un protocolo de publicación ni un archivo de edición emitida. La fijación de un universo bajo concurrencia sigue pendiente de un futuro contrato lector autorizado.

## 10. Preservación de las tres bases y evidencia anterior

Línea base: `storage/app/private/ac05b1-isolation-before.json`. Se compararon hashes de todas las filas, columnas y DDL. Las lecturas administrativas de desarrollo/bootstrap fueron exclusivamente para esta verificación independiente; las conexiones de prueba no pueden consultarlas.

| Base | Tablas antes/después | Filas antes/después | Migraciones antes/después |
| --- | ---: | ---: | ---: |
| salon_erp | 78 / 78 | 225 / 225 | 74 / 74 |
| salon_erp_test | 89 / 89 | 1.873 / 1.873 | 82 / 82 |
| salon_erp_bootstrap_check | 78 / 78 | 202 / 202 | 74 / 74 |

Resultado del auxiliar: `OTHER_DATABASES_EXACT=True`, `OLD_ROWS_PRESERVED=True`. En el modo AC05B1 la lista de tablas autorizadas a conservar filas adicionales está vacía: test conserva también exactamente sus conjuntos de filas, no solo sus recuentos. No hubo tablas/columnas añadidas ni DDL ejecutado. Los valores AUTO_INCREMENT de test pueden avanzar por INSERT revertidos; el comparador excluye únicamente ese contador del hash de DDL. No se restauraron secuencias ni se reutilizaron IDs.

Todas las filas de autorizaciones, movimientos, saldos, asientos, extractos y documentos previos quedaron conservadas, incluido131. La etiqueta genérica `LEGACY_ENTRIES_UNATTRIBUTED=True` del auxiliar no significa que no existieran asientos de piloto con procedencia antes de esta fase: la garantía aplicable aquí es igualdad de todas las filas originales. Preflight final volvió a verificar respaldo/hash, identidad, estructura y reset0.

## 11. Diferencias que no se corrigieron

Los reportes operativos siguen clasificando por sus prefijos actuales. No se cambió el filtro SQL de origen NULL, la inclusión de estados, la fecha frente a período, ni los filtros de actividad/imputabilidad de sus consultas. Las discrepancias caracterizadas están documentadas; no se modificaron las pruebas históricas para hacerlas desaparecer.

Siguen separados los hallazgos AC-05A: medios de pago con cuatro códigos, comisiones por documento, inventario a precio comercial, libro bancario de300 movimientos, consulta de costos del panel y presentación de importes negativos. No se realizaron correcciones ni asientos compensatorios. No se cambió conciliación, inventario, nómina o divisas.

## 12. Decisiones pendientes y recomendación para AC-05B2

Antes de sustituir cualquier reporte, el contador debe aprobar grupos/rubros y correctoras, costo frente a gasto operativo/otros, patrimonio y residual, tratamiento de origen NULL/estados, fecha frente a período, jerarquías y saldos históricos. Los resultados del ensayo no equivalen a esa aprobación.

Alcance recomendado de AC-05B2, sujeto a nueva autorización:

1. Cerrar esas decisiones mediante una clasificación explícita de ensayo revisable por ID, preservando referencias originales.
2. Definir un extractor lector completo, autorización de consulta, consistencia de corte y tratamiento de cuentas sin clasificación. El prototipo recibe un dataset; no certifica que un tercero haya extraído todas las líneas relevantes.
3. Preparar comparación en paralelo, sin sustitución inicial de resultados/balance/dashboard, con diagnóstico por ID de detalle y cuenta y contrato de versión de presentación.
4. Diseñar conservación de edición emitida y política prospectiva/reexpresión histórica antes de cualquier persistencia. Solicitar autorización específica si requiere DDL.
5. Mantener la corrección del cierre como tarea separada. Una lectura configurable no habilita cierre con códigos alternativos ni permite rectificar historia.

No se inicia AC-05B2 ni se promueve una configuración sintética a empresarial. AC-06, H01-B2B y despliegues permanecen fuera de alcance.

## 13. Diff y reversión segura

El diff propio se limita a los siete archivos de la sección2. Revisar auxiliares con `git diff -- tests/Support/ac04b1-backup.php tests/Support/ac04b1-isolation.php tests/Support/run-ac04b1-tests.php`; los tres archivos nuevos de código/pruebas y este documento aparecen como nuevos en Git. No se hicieron commits.

Para revertir AC-05B1: retirar el servicio y las dos pruebas nuevas y deshacer únicamente las adiciones AC05B1/reports-* de los tres auxiliares, conservando sus modos anteriores. Retirar este documento solo si se desea descartar el diagnóstico. No ejecutar reset general, migraciones down, restauración de bases ni limpieza de evidencia. Los respaldos y la línea base privados deben conservarse como auditoría. No existe clasificación persistente ni cambio de reporte operativo que desactivar.
