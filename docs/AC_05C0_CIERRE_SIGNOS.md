# AC-05C0 — Representación no negativa del cierre fiscal

Fecha: 19 de noviembre de 2026. Estado: completado y verificado exclusivamente en `salon_erp_test`. Corrección prospectiva del escritor de cierre; no rectifica documentos existentes ni sustituye clasificación, prefijos, reportes o políticas empresariales.

## 1. Preflight y respaldo

Antes de ejecutar pruebas se verificaron PHP8.3.30, MySQL8.4.3, destino exacto `salon_erp_test`, puerto3306, identidad `salon_erp_tester@localhost`, roles NONE y privilegios restringidos existentes. Los intentos de lectura hacia desarrollo/bootstrap fueron denegados con1142. Se cotejaron las82 migraciones con sus archivos, las89 tablas, InnoDB en las tablas pertinentes y DECIMAL(15,2) de debe/haber. No se modificaron privilegios ni configuración.

Se verificó nuevamente el respaldo AC-05B1 solicitado, sin crear otro ni restaurarlo:

| Dato | Evidencia |
| --- | --- |
| Archivo | `E:/salon-erp-backups/salon_erp_test_AC05B1_20261119_225424_43e6c3abf4dc.sql` |
| Tamaño | 1.536.280 bytes |
| SHA256 | `85d77381db1a51387e020482fab4a752c116c5c39616278991f452c56fe430b6` |
| Manifiesto | `storage/app/private/ac05b1-backup.json` |
| Comprobación | Tamaño, hash, sidecar SHA256 y manifiesto consistente verificados por preflight; respaldo no restaurado. |

Nueva línea base independiente: `storage/app/private/ac05c0-isolation-before.json`. La inspección de las tres bases usa el auxiliar administrativo de solo lectura existente; ninguna prueba financiera utiliza esa identidad ni una conexión alternativa. `SALON_TEST_DB_RESET_ALLOWED=0` se comprobó y se restablece mediante finally. El documento prepared131 conserva banco221 y resultados NULL.

Pruebas seleccionadas: DatabaseTransactions y fixtures nuevos, sin RefreshDatabase, migrate:fresh, db:wipe, seeders globales, limpieza histórica o carreras persistentes de AC-04.

## 2. Reproducción antes de corregir

Se ejecutó `closing-reproduce` con el escritor anterior y la caracterización AC-05B1 intacta: **8 pruebas, 62 aserciones, aprobadas**. Su caso `test_actual_closing_writer_emits_negative_debit_on_isolated_contra_income` comprobó una línea `debe='-10.00'`, `haber='0.00'` dentro de un período nuevo y revertible. No se ejecutó sobre ningún período preexistente.

SHA256 del controlador antes de modificarlo: `f9e3da7c4f8fce663a0ed2afd424730e4f5c08ef310cbb8aac5b08f63386d193`.

Fixture original:

| Cuenta | Debe | Haber |
| --- | ---: | ---: |
| Activo | 90,00 | 0,00 |
| Correctora de ingreso | 10,00 | 0,00 |
| Ingreso | 0,00 | 100,00 |

El cierre anterior debitaba ingreso100 y correctora−10, y acreditaba patrimonio90. Sumaba90 en ambos lados, pero una de sus líneas era negativa. La reproducción y el diagnóstico previo permanecen documentados en AC-05B1; no se reescribió ese informe.

## 3. Causa exacta y caminos simétricos

En `PeriodoContableController::cierreFiscal`, la rama4 calculaba H−D y lo colocaba siempre en debe. Un saldo deudor producía debe negativo. La rama5/6/7 calculaba D−H y lo colocaba siempre en haber: un saldo acreedor producía haber negativo. El defecto no dependía de que la naturaleza se llamara deudora/acreedora; dependía del saldo efectivo contrario al esperado por la rama.

La contrapartida3.2 ya seleccionaba el lado por signo y no necesitaba cambiar su significado. La validación de cuadre aceptaba sumas firmadas compensadas; `AccountIntegrityService::posting` comprueba referencias, cuenta y período, pero no convierte esas líneas en una representación correcta.

El cierre llama a su propio `validarCuadre`, que invoca AccountIntegrityService y compara centavos de los totales. No se modificaron ContabilidadService ni su validador general; no se añadió una política de signos para los demás escritores.

## 4. Algoritmo corregido

Se conserva exactamente la selección de cuentas imputables `4.%`, `5.%`, `6.%`, `7.%`, detalles por `periodo_id` y exclusión `modulo_origen != cierre_fiscal`. Origen NULL, estados del asiento y discrepancias fecha/período conservan el contrato anterior.

Los DECIMAL originales se leen como cadenas exactas de dos decimales y se acumulan en centavos enteros. No se usa float para decidir el saldo, su lado o el resultado del cierre. Al ser columnas de dos decimales, saldo distinto de cero equivale al umbral heredado de magnitud mayor que0,005; no se añade una nueva política de redondeo.

| Saldo firmado | Debe del cierre | Haber del cierre |
| --- | ---: | ---: |
| Ingreso: S=H−D | max(S,0) | max(−S,0) |
| Costo/gasto/otros: S=D−H | max(−S,0) | max(S,0) |
| Resultado neto U hacia3.2 | max(−U,0) | max(U,0) |

Un saldo nominal cero no emite línea. Para cada cuenta no nula, el nuevo D−H es exactamente el contrario de su D−H original. Solo un lado es positivo; el otro es cero. No se modifica la línea original ni se introduce un asiento compensatorio adicional.

Helpers privados asociados únicamente al cierre:

- `centavosCierre`: exige representación original DECIMAL(15,2), incluidos signos históricos; rechaza formatos o precisiones no representables, sin normalizarlos.
- `sumarCentavosCierre`: acumula enteros y rechaza desbordamiento.
- `importeCierre`: emite cadena decimal exacta no negativa y exige el límite de una línea DECIMAL(15,2).

Si el saldo acumulado por cuenta o la contrapartida superan9.999.999.999.999,99, se rechaza el cierre y se revierte todo. No se divide una línea ni se crea otra cuenta para eludir ese límite. Los importes originales negativos, si existieran, se leen como datos firmados sin reescribirlos; no se corrige su historia.

## 5. Efecto neto y contrapartida

Se mantiene U=ingresos−costos−gastos−otros, con sus signos originales. U positivo acredita3.2; U negativo la debita por su magnitud; U cero no genera contrapartida. No se cambia la cuenta literal, fecha, concepto, origen o referencia del cierre.

Para el fixture reproducido, el nuevo cierre es:

| Cuenta | Debe | Haber | D−H, igual al efecto anterior |
| --- | ---: | ---: | ---: |
| Ingreso | 100,00 | 0,00 | +100,00 |
| Correctora | 0,00 | 10,00 | −10,00 |
| Patrimonio3.2 | 0,00 | 90,00 | −90,00 |
| Total | 100,00 | 100,00 | 0,00 |

El volumen bruto de debe/haber pasa de90/90 a100/100 porque se elimina una cantidad negativa compensada. Ese cambio de totales brutos es consecuencia necesaria de representar correctamente los lados; **el efecto neto de cada cuenta, utilidad90, saldo del mayor y balance no cambian**. Por ello se adaptó la expectativa bruta de la prueba anterior, conservando su expectativa económica y sus originales.

## 6. Períodos, bloqueo y atomicidad

Se conserva la validación previa y la relectura `lockForUpdate` del período dentro de la transacción. Una petición repetida sobre período cerrado se rechaza; también se probó una instancia obsoleta que aparenta abierto, para comprobar el rechazo después de bloquear/releer. No se introdujo un índice, otro estado ni otra política temporal.

La transacción del cierre sigue incluyendo cabecera, líneas, validación de cuentas/cuadre y cambio a cerrado, sin commits intermedios. Se amplió únicamente el catch del cierre de Exception a Throwable: un Error también debe revertir los efectos iniciados antes de propagar un resultado controlado. No se modificó el manejo de errores de otros escritores.

Las pruebas comprueban que el nivel de transacción exterior se conserva: el commit anidado no confirma el fixture de DatabaseTransactions. No se afirma una nueva prueba de concurrencia multiproceso; se conservó el protocolo de exclusión del período y se verificaron repetición y modelo obsoleto secuencialmente.

## 7. Matriz de pruebas del cierre

`FiscalClosingSignsTest` utiliza únicamente períodos y cuentas recién creados en su transacción. Sus expectativas derivan del D−H original de cada cuenta, no de los resultados del algoritmo corregido.

| Cobertura | Comprobación |
| --- | --- |
| Ingreso acreedor100 | Debe100, haber0; patrimonio acreedor100. |
| Correctora deudora10 | Debe0, haber10; pérdida debitada a3.2. |
| Gasto deudor20 | Haber20. |
| Gasto acreedor5 | Debe5. |
| Costo acreedor7 | Debe7. |
| Otros acreedores3 | Debe3. |
| Costo normal | Haber por su saldo deudor. |
| Utilidad/pérdida mixta | Varias cuentas y correctoras; resultado y3.2 firmados exactos. |
| Resultado cero | Sin contrapartida3.2; cierre cuadrado. |
| Cuenta nominal cero | Sin línea, tanto con0/0 como con debe10/haber10. |
| Centavos | Se conserva el último centavo y la neutralización cuenta por cuenta. |
| Máximo DECIMAL | Línea9.999.999.999.999,99 y contrapartida exactas, incluidos los99 centavos. |
| Exceso por cuenta | Dos detalles válidos cuyo saldo no cabe en una línea: rechazo y rollback. |
| Exceso de contrapartida | Líneas nominales individuales válidas cuyo resultado no cabe en3.2: rollback de líneas ya iniciadas. |
| Datos no representables | Helper rechaza float, notación científica, tres decimales y exceso de longitud. No se insertan esos datos inválidos. |
| Cuenta inactiva | La guarda de imputación existente rechaza el cierre y revierte sus efectos; no se relaja para pasar la prueba. |
| Período cerrado/repetido | Ninguna segunda cabecera; rechazo también con modelo obsoleto abierto. |
| Excepción/Error | Fallos inyectados tras cabecera, tras primera línea y antes de actualizar el período. Recuento de cabeceras/líneas, originales, período y nivel transaccional intactos. |

En cada escenario completo se verifica que no hay debe/haber negativos, que una sola cara aporta, que cada cuenta seleccionada termina en saldo cero, que el asiento cuadra y que3.2 conserva el efecto firmado. También se comprueban los detalles originales por contenido, fecha y referencia del cierre, diario con original/cierre, mayor neutralizado, resultados sin cierre y balance con traslado patrimonial incluido.

## 8. Resultados definitivos y regresiones

| Grupo definitivo | Pruebas | Aserciones | Resultado |
| --- | ---: | ---: | --- |
| FiscalClosingSignsTest | 29 | 556 | Aprobado |
| AccountingPresentationTest + AccountingReportCharacterizationTest | 34 | 123 | Aprobado |
| **Total posterior a la corrección** | **63** | **679** | **Aprobado** |

La reproducción previa8/62 se registra como evidencia anterior y **no se suma** a los resultados definitivos. Tampoco se suman las ejecuciones intermedias21/534 y27/545 usadas al ampliar cobertura.

Comandos focalizados, con PHP explícito y bootstrap seguro:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 tests/Support/run-ac04b1-tests.php closing-preflight
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 tests/Support/run-ac04b1-tests.php closing-core
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 tests/Support/run-ac04b1-tests.php closing-regressions
```

El modo `closing-reproduce` se ejecutó **antes** del cambio con la caracterización antigua. Con el árbol definitivo esa clase comprueba ya el lado corregido, no vuelve a producir la representación defectuosa. Para reproducir historia se conserva la evidencia de las secciones2/5 y el diff; no se deja un escritor antiguo activo.

Las regresiones AC-05B1 siguen verificando prefijos, códigos alternativos, estados, origenNULL, fecha/período, inactivas, jerarquías, bindings y reversas. Sus guardas AC-02 prueban clasificación histórica protegida, nuevas imputaciones a inactivas rechazadas y reversión manual de automático bloqueada. No se ejecutó AccountIntegrityTest completo por sus seeders globales, ni se repitieron las suites/carreras AC-03/04: sus servicios y contratos no se modificaron. No se cambió una aserción para ocultar una diferencia económica; la única adaptación es la representación autorizada de la correctora y su total bruto explicado en sección5.

Lint del controlador y nueva prueba: sin errores. `git diff --check`: sin errores de espacios; advertencias CRLF/LF de auxiliares existentes en Windows.

## 9. Integridad de las tres bases

Se compararon antes/después hashes de todas las filas y esquema con el auxiliar AC05C0. Resultado final:

| Base | Tablas antes/después | Filas antes/después | Migraciones antes/después |
| --- | ---: | ---: | ---: |
| salon_erp | 78/78 | 225/225 | 74/74 |
| salon_erp_test | 89/89 | 1.873/1.873 | 82/82 |
| salon_erp_bootstrap_check | 78/78 | 202/202 | 74/74 |

`OTHER_DATABASES_EXACT=True`, `OLD_ROWS_PRESERVED=True`. El modo AC05C0 no admite filas persistentes adicionales en test; los conjuntos de filas coinciden, no solo sus recuentos. Se permite únicamente avance normal de AUTO_INCREMENT por INSERT revertidos, excluido del hash normalizado del esquema. No se restauraron secuencias ni respaldos.

Se preservaron documentos, períodos, cuentas, extractos, asientos y evidencias AC-04, incluido prepared131. No se aplicó DDL, cierre histórico ni reclasificación. La etiqueta genérica `LEGACY_ENTRIES_UNATTRIBUTED=True` del auxiliar no reinterpreta asientos de piloto existentes: la evidencia aplicable es conservación exacta de las filas previas.

## 10. Vistas y defecto histórico separado

Inspección: `resources/views/accounting/diario.blade.php:107,110` muestra importes solo si son positivos; `resources/views/accounting/asientos/show.blade.php:98,99` sustituye negativos por raya. Por ello una línea negativa anterior puede no verse fielmente, aunque siga participando en sumas.

No se modificaron esas vistas ni sus datos. Las nuevas líneas bien formadas se mostrarán por las condiciones actuales. Propuesta separada: autorizar una corrección exclusivamente visual que muestre el valor firmado original en su columna real, acompañado de una advertencia documental si procede. No convertir negativos en positivos visualmente ni aparentar que un cierre histórico ya fue corregido. No se implementó ni se realizó revisión visual/manual del navegador en esta fase.

## 11. Archivos modificados y estado de Git

Delta exclusivo de AC-05C0 respecto al inicio de esta intervención:

| Archivo | Cambio propio |
| --- | --- |
| `app/Http/Controllers/PeriodoContableController.php` | Representación del cierre en centavos, lado correcto no negativo, límites y catch Throwable. Solo cierre/helper privados. |
| `tests/Feature/FiscalClosingSignsTest.php` | Nueva batería transaccional del cierre. |
| `tests/Feature/AccountingReportCharacterizationTest.php` | Adaptación explícita del caso reproducido a0/10 y total bruto100, manteniendo neto/originales y demás cobertura. |
| `tests/Support/run-ac04b1-tests.php` | Modos closing-* y comprobación de89 tablas en el preflight de reportes/cierre. Reutiliza respaldo AC05B1. |
| `tests/Support/ac04b1-isolation.php` | Fase AC05C0 con comparación estricta sin persistencias nuevas. |
| Este documento | Evidencia, resultados, límites y reversión. |

Había cambios AC-05B1 sin commit al comenzar: servicio y pruebas de clasificación, su documento y tres auxiliares. Se conservaron. En particular, `ac04b1-backup.php` ya estaba modificado por AC-05B1 y **no se editó en AC-05C0**. El diff del controlador es52 adiciones/19 eliminaciones; no se cambió balanceGeneral, resultados, DashboardService, AccountIntegrityService o ContabilidadService. Sin commits automáticos.

## 12. Diff y reversión segura

Revisión funcional:

```powershell
git diff -- app/Http/Controllers/PeriodoContableController.php
git diff -- tests/Support/run-ac04b1-tests.php tests/Support/ac04b1-isolation.php
git status --short
```

La nueva prueba y el documento son archivos nuevos; la caracterización todavía pertenece al conjunto no rastreado de AC-05B1. Revisar su caso renombrado y las tres expectativas anotadas, además del diff de archivos ya rastreados. Los auxiliares contienen también cambios previos AC-05B1: no descartarlos en bloque.

Reversión de código: deshacer únicamente los hunks AC-05C0 del controlador, retirar la prueba nueva y los modos/fase closing-*/AC05C0, y restaurar el caso de caracterización anterior si se vuelve al escritor antiguo. Esa reversión reintroduce el defecto conocido y requiere revisión antes de usar el cierre. Conservar el diagnóstico y evidencia; no restaurar bases, borrar documentos, hacer reset general ni aplicar compensaciones. No hay una migración que revertir ni un documento persistido que cancelar.

## 13. Límites y riesgos pendientes

- No cambian prefijos4–7, destino3.2, universo de origenNULL/estados, política de fechas/períodos o resolución de cuentas.
- No se reparan líneas negativas antiguas ni se certifica su existencia en datos reales; la reproducción fue un fixture nuevo.
- No se modifica clasificación de reportes, inventario, nómina, bancos, divisas, autorizaciones, anulaciones o devoluciones.
- La validación general de signos de otros escritores no forma parte de esta entrega. El defecto simétrico se corrigió dentro del escritor de cierre únicamente.
- La representación exacta no sustituye una aprobación empresarial de catálogo o cierre; tampoco vuelve configurable la selección del cierre.
- Se conservaron bloqueos existentes; esta fase no aporta nuevas carreras MySQL ni evidencia sobre otros contratos concurrentes.

## 14. Preparación de AC-05B2

La lectura de resultados/balance puede seguir caracterizándose sin líneas nuevas de cierre negativas. AC-05B2 aún necesita autorización y decisiones contables sobre grupos, correctoras, origenNULL, estados, cortes, clasificación por ID y preservación de ediciones emitidas. Esta corrección no aprueba sustituir sus prefijos ni cambiar el cierre para nuevas cuentas fuera de las clases actuales.

AC-05C0 termina aquí. No se inicia AC-05B2, AC-06, H01-B2B, una corrección de vistas históricas o habilitación empresarial; no hay despliegue en desarrollo.
