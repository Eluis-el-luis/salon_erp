# AC-05B4A — Inventario contable para revisión del contador

Se inventariaron **68 cuentas, 146 asientos y 298 detalles** existentes en salon_erp_test mediante lecturas restringidas. **15 cuentas tienen movimientos y53 no tienen detalles.** Todas las asignaciones económicas futuras permanecen PENDIENTES. No se aprobó ninguna clasificación ni se modificaron cuentas, documentos o reportes.

## 1. Fuentes, alcance y anexos completos

Separación utilizada:

- **Observación SQL:** identidad, atributos actuales, relaciones, detalles, fechas, estados e importes registrados en test.
- **Hecho de código:** filtros y convenciones actuales de resultados, balance y dashboard.
- **Diagnóstico técnico:** cobertura, saldo contrario, dependencia o diferencia de universo. No determina legitimidad económica.
- **Decisión contable:** grupo, subgrupo, correctora, corriente/no corriente, universo y aprobación. Sigue pendiente.

Los anexos contienen las68 cuentas, sin muestreo ni exclusión de inactivas:

1. [Matriz completa para el contador, CSV](AC_05B4A_MATRIZ_CONTADOR.csv): identidad, código/nombre, tipo/naturaleza, padre/nivel, estado/imputabilidad, fechas de catálogo, dependencias, actividad, agrupaciones heredadas y columnas pendientes de revisión.
2. [Catálogo y agregados completos, JSON](AC_05B4A_CATALOGO_ANEXO.json): además desglosa por mes, período, moneda, centro de costo, estado, origen y tipo de asiento; conserva los universos heredados por cuenta. Importes en **centavos enteros del DECIMAL registrado**, sin conversión ni cambio de signo original.

El JSON mantiene etiquetas exactas y NULL. El CSV protege celdas de texto que pudieran interpretarse como fórmulas al abrirlas en una hoja de cálculo; no altera los metadatos fuente del JSON. Ninguno de los anexos contiene clientes, conceptos, números de asiento, IDs de detalles/asientos o referencias bancarias individuales. La extracción identificable permanece en evidencia privada.

**Todo procede de test. No acredita operaciones empresariales reales del salón.** La historia incluye ensayos AC-04/05 y fechas futuras de fixtures; no existe separación económica empresarial certificada de esos registros. Los borradores con account_id900001 no se utilizaron para clasificar el catálogo.

## 2. Arquitectura de extracción lectora

Se inspeccionaron CuentaContable, AccountIntegrityService, modelos de asientos/detalles, resultados, balance, dashboard, extractor, comparador, validador B1 y documentos AC-05A/B3B0. No se modificó ninguno.

`AccountingCatalogueInventoryReader::read()` utiliza la frontera CLI lectora B4 existente, no una autorización escritora. Abre una PDO de destino fijo127.0.0.1:3306/salon_erp_test con salon_erp_tester, sin recibir conexiones, credenciales, esquema, actor o SQL desde parámetros. No tiene fallback administrativo. Requiere bootstrap controlado, CLI/PHP8.3.30/raíz, entorno testing, contrato interno lector y reset0; comprueba actor técnico activo alvaro/contador desde SQL.

Antes de extraer, el perfil fijo `AC05_SYNTHETIC_91_V1` verifica identidad salon_erp_tester@localhost, roles NONE, MySQL8.4.3, puerto3306, grants exactos, las91 tablas/estructuras y82 migraciones. Los SELECT sobre desarrollo/bootstrap son denegados con1142. La cuenta conserva sus permisos test previos; **no se afirma que sea una cuenta SQL exclusivamente SELECT**.

El servicio fija UTC, REPEATABLE READ y READ ONLY. La inspección de metadatos posee su propia transacción lectora; después, catálogo, detalles, períodos, monedas, dependencias y controles se extraen en **un único snapshot propietario** mediante `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY`. No usa locks financieros, FOR UPDATE, GET_LOCK ni servicios de contabilización. Al terminar confirma solo la lectura; ante fallo revierte su snapshot y conserva reset0. No inicia Laravel/Eloquent ni dispara eventos de modelos.

`AccountingCatalogueInventory` es una proyección pura de metadatos y actividad. No calcula una clasificación propuesta ni un segundo estado financiero. Usa centavos enteros, rechaza precisión no representable y overflow, cuentas/detalles duplicados, huérfanos, ciclos o padres inexistentes. Los descendientes no se suman al padre. Las dependencias se cuentan separadamente por FK entrante y por las asociaciones conocidas de AccountIntegrityService; no se unen bindings al mayor.

El snapshot conserva la versión visible al iniciarlo, no representa un archivo emitido ni una garantía de ausencia de DDL externo concurrente. No se ejecutaron carreras o cambios de esquema en esta fase. La comparación independiente final verificó que no hubo tales modificaciones.

## 3. Catálogo observado

| Atributo actual | Recuento |
| --- | ---: |
| Cuentas totales | 68 |
| Tipo activo / pasivo / patrimonio | 22 / 10 / 4 |
| Tipo ingreso / costo / gasto | 8 / 5 / 19 |
| Imputables actualmente / no imputables | 55 / 13 |
| Activas / inactivas | 67 / 1 |
| Con movimientos / sin movimientos | 15 / 53 |
| No imputables con detalles propios | 0 |
| Inactivas con detalles | 0 |
| Cuentas con historia y neto cero | 0 |
| Padre con movimientos propios | 1: ID2272 |

Son atributos **actuales**, no fotografías de los atributos al registrar cada asiento. CuentaContable no utiliza SoftDeletes: el estado observado es activa. El modelo contiene is_system_account, created_at y updated_at, pero no una categoría de presentación aprobada ni una dimensión corriente/no corriente. Esos campos y los NULL históricos se conservan en los anexos.

La cuenta inactiva2315 (`7.2`, etiqueta actual «Gastos Personales / Retiros del Dueño») es también no imputable y no tiene detalles. Su etiqueta no autoriza registrarla como gasto ni reactivarla. ID2318 (`3.3`) mantiene tipo patrimonio y naturaleza deudora; no se modifica ni se deduce una clasificación futura por similitud de nombres.

Las otras12 cuentas no imputables son2269,2270,2271,2279,2283,2284,2289,2291,2294,2300,2305,2313. No tienen movimientos directos. El árbol se recorrió por IDs, sin ciclos/padres faltantes detectados; no se renumeró ni reparó ningún nivel.

## 4. Actividad completa por cuenta

Todos los298 detalles observados tienen moneda_id73, catálogo NIO/base. No se convirtió moneda ni se dedujo moneda por el nombre de la cuenta: por ejemplo2320 contiene «USD» en su etiqueta pero sus3 detalles están registrados con moneda_id73. La moneda documental de los documentos operativos no se reconstruyó. El JSON mantiene desglose por moneda para que la lectura no mezcle silenciosamente futuras monedas distintas.

En esta tabla D/H y saldo se expresan en unidades monetarias registradas, dos decimales; el anexo conserva centavos exactos. Saldo = D−H para naturaleza deudora, H−D para acreedora **actual**. No implica un rubro aprobado.

| ID | Código | Detalles | DEBE | HABER | Saldo según naturaleza | Primera / última fecha |
| --- | --- | ---: | ---: | ---: | ---: | --- |
| 2272 | 1.1.1 | 24 | 1400.00 | 1000.00 | 400.00 | 2026-11-14 / 2032-08-20 |
| 2273 | 1.1.2.1 | 6 | 400.00 | 70.00 | 330.00 | 2031-05-15 / 2032-08-20 |
| 2274 | 1.1.2.2 | 86 | 21670.00 | 2935.00 | 18735.00 | 2031-01-15 / 2037-05-15 |
| 2276 | 1.1.4 | 8 | 100.00 | 80.00 | 20.00 | 2026-11-14 / 2026-11-14 |
| 2277 | 1.1.5 | 23 | 124.00 | 73.00 | 51.00 | 2026-11-14 / 2026-11-14 |
| 2285 | 2.1.1 | 12 | 0.00 | 180.00 | 180.00 | 2026-11-14 / 2026-11-14 |
| 2295 | 4.1 | 11 | 400.00 | 700.00 | 300.00 | 2026-11-14 / 2026-11-14 |
| 2298 | 4.4 | 10 | 300.00 | 700.00 | 400.00 | 2026-11-14 / 2026-11-14 |
| 2301 | 5.1 | 10 | 110.00 | 40.00 | 70.00 | 2026-11-14 / 2026-11-14 |
| 2302 | 5.2 | 9 | 43.00 | 4.00 | 39.00 | 2026-11-14 / 2026-11-14 |
| 2320 | 1.1.1.2 | 3 | 300.00 | 0.00 | 300.00 | 2031-05-15 / 2032-08-20 |
| 2328 | 4.7 | 23 | 0.00 | 21100.00 | 21100.00 | 2032-08-18 / 2034-09-10 |
| 2330 | 6.9 | 52 | 2285.00 | 200.00 | 2085.00 | 2026-11-16 / 2037-05-15 |
| 2662 | 6.HTTPde12dcea | 10 | 500.00 | 0.00 | 500.00 | 2026-11-16 / 2026-11-16 |
| 2663 | 1.1.2.HTTPde12dcea | 11 | 0.00 | 550.00 | **−550.00** | 2026-11-16 / 2026-11-16 |
| **Control** | **15 cuentas** | **298** | **27632.00** | **27632.00** | No sumar naturalezas distintas | 2026-11-14 / 2037-05-15 |

Se conserva ID2663 con saldo contrario; no se transforma en positivo, no se llama automáticamente contraactivo ni se corrige su saldo. Es una cuenta etiquetada sintética del ensayo HTTP, no una posición bancaria empresarial certificada.

ID2272 tiene hijos2319/2320/2321 y24 detalles propios. ID2320 tiene3 detalles independientes; sus300.00 no se agregaron a los400.00 del padre. No hay actualmente una agrupadora no imputable con movimientos: esa variante se cubrió mediante arrays puros, sin crearla en SQL.

## 5. Cuentas sin actividad y dependencias

Los53 IDs sin detalles son:

2269,2270,2271,2275,2278,2279,2280,2281,2282,2283,2284,2286,2287,2288,2289,2290,2291,2292,2293,2294,2296,2297,2299,2300,2303,2304,2305,2306,2307,2308,2309,2310,2311,2312,2313,2314,2315,2316,2317,2318,2319,2321,2322,2323,2324,2325,2326,2327,2329,2331,2564,2660,2661.

**Sin detalles no significa prescindible.** Además de relaciones padre/hijo,2564 y2660 tienen un binding cada una;2661 tiene un binding y una cuenta bancaria asociada. Los anexos conservan esas dependencias. No se propone borrado ni exclusión de cobertura futura.

Entre las cuentas con actividad,2274 tiene41 bindings y50 asociaciones bancarias;2273 tiene9 asociaciones bancarias;2663 tiene3 bindings y1 asociación bancaria. Son recuentos de test, no50 bancos reales distintos ni50 cuentas económicas nuevas. Cada una de sus líneas del mayor sigue contándose una sola vez. Los FK detectados y asociaciones conocidas se consultan con GROUP BY separado, nunca mediante join multiplicador con detalles.

## 6. Universos documentales y limitaciones observadas

Inventario completo: todas las fechas, estados y orígenes, sin filtro por período. Los agregados mensuales y por periodo_id se conservan por separado; el inventario no elige una regla económica entre ellos.

Hechos de la lectura:

- 146 asientos, todos con al menos un detalle;298 detalles únicos.
- Todos los detalles relacionados están en asientos contabilizados y tipo_asiento diario. **«diario» no prueba que el asiento sea manual.**
- Orígenes por detalle: cargos_bancarios156, ventas52, inventario28, transferencias24, compras24, bank_charge_refunds8 y mesa_cambio6.
- 298 detalles pertenecen a asientos con referencia_id no NULL; se publica solo el recuento.
- 24 detalles tienen número de asiento con marcador REV-. Es una convención observada, no una verificación universal del vínculo de reversa. Las devoluciones bancarias se identifican adicionalmente por su origen documentado; no se vuelven a contabilizar.
- Origen NULL, cierre_fiscal, detalle negativo y fecha fuera del período asociado:0 observados. Las variantes se prueban en memoria; no se afirma haberlas encontrado en la base viva.
- 32 períodos de catálogo:31 abiertos y1 cerrado. No se ejecutó un cierre ni se alteró ninguno.

La amplitud temporal hasta2037 evidencia el uso de fixtures. Estas sumas no representan un período empresarial, una conciliación de efectivo ni un estado financiero emitido. Una eventual edición real deberá elegir corte, período, estados, origen NULL, moneda y tratamiento de correcciones con aprobación identificable.

## 7. Comparación con los reportes heredados

Hechos inspeccionados en código, sin cambiar ni invocar controladores escritores:

| Consumidor | Selección vigente | Efecto y límites |
| --- | --- | --- |
| Resultados | Imputable; LIKE4.%/5.%/6.%/7.%; fecha del asiento por mes o mes=todo; origen != cierre_fiscal | NULL también queda excluido por SQL. Sin filtro de estado o activa.4 usa H−D;5/6/7 D−H. No depende de tipo/naturaleza para agrupar. |
| Dashboard KPI/serie | Misma selección de cuentas/origen, rango de fechas explícito | No confundir con cobros por método ni indicadores comerciales. No se afirma igualdad de rangos temporales predeterminados. |
| Balance | Todas las imputables; fecha<=corte; cualquier estado/origen, incluido cierre | Primer carácter1/2/3 presenta activo/pasivo/patrimonio;4/5/6 resultado; **cualquier otro código cae en resultado:otros**. Saldos cero patrimoniales pueden no mostrarse. |
| Diario/mayor | Documentos y detalles originales; filtros propios | No son una clasificación económica nueva ni un rollup automático de hijos. |

La matriz muestra elegibilidad y agrupación por cuenta, incluso si no tiene actividad. El JSON compara universos sobre **todo el histórico observado**, equivalente a mes=todo para resultados y corte2037-05-15 para balance; no reproduce la pantalla mensual de hoy ni crea una edición.

Control aritmético del universo heredado histórico, sin atribuir aprobación:

| Rubro heredado | Cuentas elegibles | Detalles | DEBE | HABER | Contribución por regla heredada |
| --- | ---: | ---: | ---: | ---: | ---: |
| Ingresos4.% | 7 | 44 | 700.00 | 22500.00 | 21800.00 |
| Costos5.% | 4 | 19 | 153.00 | 44.00 | 109.00 |
| Gastos6.% | 12 | 62 | 2785.00 | 200.00 | 2585.00 |
| Otros7.% | 4 | 0 | 0.00 | 0.00 | 0.00 |

125 detalles elegibles para resultados;173 no son cuentas nominales seleccionadas. Con estas reglas la diferencia histórica sería19106.00; el balance heredado acumularía activo19286.00, pasivo180.00 y patrimonio directo0, con resultado residual19106.00. Son **caracterizaciones aritméticas en centavos**, no ejecuciones de la vista ni certificación de precisión de sus sumas float.

No hay cuenta con actividad fuera de los prefijos reconocidos por los reportes en este snapshot. Los códigos alfanuméricos2660–2663 sí existen, pero conservan prefijos6. /1.; no deben confundirse con un fallo reproducido de exclusión por código. La futura clasificación por ID deberá tratar explícitamente los códigos alternativos: las pruebas puras incluyen9-ALT, seleccionado como otros por el balance y omitido por resultados, pese a tipo activo.

Dos atributos merecen revisión sin asumir error:2299 (`4.5`) es ingreso/deudora y2318 (`3.3`) patrimonio/deudora. Ambas carecen de detalles. La naturaleza opuesta a una convención puede ser legítima; **no se impone cambiarla ni se confirma una correctora por su nombre**.

## 8. Matriz de clasificación pendiente y decisiones concretas

Las68 filas del CSV contienen grupo=PENDIENTE, subgrupo=PENDIENTE, correctora=PENDIENTE DE CONFIRMACIÓN, corriente/no corriente=PENDIENTE con aplicabilidad por confirmar y estado=NO REVISADA. Los valores tipo/naturaleza actuales y las agrupaciones heredadas se mantienen en columnas diferentes. No se deduce ninguna asignación AC-03 ni financiera desde nombres.

Agenda propuesta de revisión humana, **sin decisiones económicas adoptadas**:

| Tema | Evidencia para revisar | Decisión solicitada al contador |
| --- | --- | --- |
| Activo, pasivo y patrimonio | IDs, tipos/naturalezas y saldos del anexo | Confirmar grupo por ID y excepciones justificadas; no aprobar por prefijo. |
| Ingresos | Cuentas tipo ingreso, incluidos2299/2328 | Definir categorías y correctoras con soporte económico; confirmar qué historia es exclusivamente de ensayo. |
| Costos de venta y servicio |2301–2304 y demás cuentas de costo | Confirmar distinción producto/servicio y tratamiento de comisiones/modelos; no trasladar a gasto por etiqueta. |
| Gastos operativos/otros | Cuentas tipo gasto, incluidas2330/2331 | Aprobar frontera entre categorías y subtotales, sin asumir que6/7 ya la ratifican. |
| Correctoras |2299/2318, saldo contrario2663 y cuentas sin actividad | Confirmar significado y presentación; saldo contrario o naturaleza opuesta no bastan. |
| Padre e hijo |2272 con detalles y2319–2321 | Elegir subtotales visuales manteniendo una contribución por detalle; no consolidar dos veces. |
| Corriente/no corriente | Jerarquía actual y vencimientos no examinados | Aprobar criterios y posibles divisiones por vencimiento; el nombre del padre no basta para clasificar. |
| Inactivas y sin actividad |2315 y53 cuentas sin detalles | Definir presentación/cobertura futura sin borrar historia ni excluir por saldo cero. |
| Catálogo de ensayo |2564,2660–2663 y antecedentes AC-04 | Determinar alcance empresarial futuro con documentación; no promover ni borrar evidencia de test. |
| Estados y origen NULL | Contrato SQL actual; casos ausentes hoy | Aprobar estados incluidos y tratamiento explícito de NULL y reversas. |
| Cierres y temporalidad | Fecha frente a periodo_id; cierres ausentes en lectura | Definir universos distintos para resultados/balance y correcciones posteriores al corte; no reabrir períodos. |
| Moneda | Detalles en73 NIO, catálogo también74 USD | Ratificar base contable, moneda documental y criterio de presentación; no convertir por el nombre de cuenta. |
| Gobierno | Matriz completa y diferencias | Aprobar contenido exacto, hash, ámbito, referencia, vigencia y actor mediante un futuro flujo legítimo autorizado. Esta matriz no es esa aprobación. |

No se genera firma ni aprobación simulada de Álvaro o del contador. La revisión puede dejar casos pendientes; no se rellenan automáticamente para lograr cobertura.

## 9. Controles y pruebas focalizadas

| Grupo | Pruebas | Aserciones |
| --- | ---: | ---: |
| Proyección pura de inventario | 12 | 37 |
| Regresión pura de perfiles/esquema R | 29 | 60 |
| Lectura SQL del inventario existente | 1 | 149 |
| **Total** | **42** | **246** |

Las41 pruebas puras se ejecutaron con PDO y mysqli deshabilitados. Incluyen cuenta agrupadora con detalles, padre/hijo, inactiva con historia, neto cero con movimientos, cuenta vacía, código alternativo, estados/origen NULL, cierre identificado, fecha/período discordante, precisión/monedas y rechazo de IDs duplicados/huérfanos/ciclos. Las dependencias múltiples no multiplican las líneas. Los perfiles se alteraron solo en arrays para probar rechazo de columna, índice, FK/CHECK, tabla, migración, identidad o privilegio inesperados.

La prueba SQL realiza dos lecturas independientes, sin fixtures ni DatabaseTransactions: compara los inventarios completos y sus controles, verifica68 IDs/298 detalles únicos, SUM SQL de D/H, dependencia detalle_asientos por cada cuenta y ausencia de clasificación económica automática. Son dos conexiones distintas, no una prueba de concurrencia de escritores.

Comandos ejecutados:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d disable_classes=PDO,mysqli -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result --no-progress tests/Unit/AccountingCatalogueInventoryTest.php tests/Unit/AccountingTestSchemaProfilesTest.php
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap tests/Support/ac05b3b4-bootstrap.php --do-not-cache-result --no-progress tests/Feature/AccountingCatalogueInventoryReadOnlyTest.php
```

Lint de los6 PHP nuevos: aprobado. No se ejecutaron pruebas antiguas de escritura, suites generales, migraciones, seeders, cierre o procesos financieros. No se enviaron DML de prueba para provocar errores.

## 10. Respaldo, aislamiento y evidencia

Antes de consultar se verificaron el respaldo R y su SHA256, perfil91/82 migraciones,42 cabeceras/48 auditorías y preparado131. No se generó ni restauró un respaldo; la fase es lectora.

- Respaldo: `E:/salon-erp-backups/salon_erp_test_AC05B3B2R_20261120_092818_0b7b605d017b.sql`,1777958 bytes.
- SHA256: `15fc93be213a2e54450cd0c8034ab2e86191a1bfdf713ad013b04ac640df7eef`; manifiesto y sidecar verificados. No se afirma restaurabilidad ensayada.
- Baselines nuevos privados: `ac05b4a-40bc9162e7ad580f-before.json` y `ac05b4a-40bc9162e7ad580f-after.json` en storage/app/private.
- SHA256 idéntico de ambos: `204e933b35c93c4fa3826e00f92f8de930ae43ce42b31bf28498e43a46521158`.

La comparación administrativa independiente utiliza exclusivamente READ ONLY sobre las tres bases; es un proceso separado del servicio restringido. Exige igualdad con la evidencia R aprobada y con el baseline propio: tablas, DDL completo, AUTO_INCREMENT y hashes de todas las filas. No se sobrescribieron baselines previos.

| Base | Tablas inicial/final | Migraciones | Filas inicial/final | Comparación |
| --- | ---: | ---: | ---: | --- |
| salon_erp | 78/78 | 74 | 225/225 | Exacta |
| salon_erp_bootstrap_check | 78/78 | 74 | 202/202 | Exacta |
| salon_erp_test | 91/91 | 82 | 1963/1963 | Exacta |

42 borradores/48 auditorías con cadenas válidas e idénticas. Preparado131 intacto: prepared, banco221, resultados NULL; hash UTC `3167cc4bbdb3ccaa58fbc454634f1431ca20fc3281e228d20c4f5ce791f87a52`. Reset0 preservado y restablecido mediante finally. No filas nuevas ni transitorias, cambios de timestamps, credenciales, permisos, APP_KEY, AGENTS.md o configuración.

Evidencia identificable privada: `storage/app/private/ac05b4a-40bc9162e7ad580f-inventory.json`, SHA256 `1c0b66d0e791d80598b9fc71a9712eecee167e5201b1b24f6f8184c7ef1e7b1a`. Contiene la proyección mínima de detalles necesaria para comprobar cuentas/fechas/estados, sin conceptos o datos de clientes.

Anexo JSON SHA256 `1e7cd115478208c2ed529af5e572af274de985a6aaaab7cc64428acd4999b00b`; matriz CSV SHA256 `1c85f16db3f6ae585a04f08872d7b2768126bd28051f8feed9790f9eb6fdd31f`.

## 11. Archivos, límites y continuación

Todos los archivos propios son nuevos:

- `app/Services/AccountingCatalogueInventory.php`.
- `app/Services/AccountingCatalogueInventoryReader.php`.
- `tests/Unit/AccountingCatalogueInventoryTest.php`.
- `tests/Feature/AccountingCatalogueInventoryReadOnlyTest.php`.
- `tests/Support/ac05b4a-isolation.php`.
- `tests/Support/ac05b4a-inventory.php`.
- Los dos anexos y este informe.

Ningún archivo existente modificado. Rama feature/panel y HEAD `0c362a819ff582e9335c0fbb4327c44e72cf0b25` conservados; índice sin cambios. Evidencia privada ignorada por Git. No add/commit/push ni operaciones de gestión de historial.

La extracción privada y los anexos son evidencia del snapshot, no clasificaciones persistentes ni estados emitidos. Las dependencias detectadas abarcan FK entrantes y asociaciones conocidas de AC-02; no pretenden descubrir toda dependencia semántica en código dinámico o documentación externa. Los reportes legados se caracterizaron estáticamente y con sus reglas sobre los datos, sin alterar sus consultas ni afirmar una reproducción visual.

Recomendación siguiente: entregar las68 filas al contador para que confirme alcance empresarial, grupos/subgrupos por ID, correctoras, subtotales y universos, con referencias documentales. Priorizar15 cuentas con actividad, las2 naturalezas nominalmente opuestas, la jerarquía2272 y la separación de evidencia sintética, conservando revisión de las53 restantes. Solo después de decisiones identificables y una autorización nueva corresponde diseñar su incorporación; no ampliar automáticamente la infraestructura de ensayo ni publicar una clasificación.

Para una eventual retirada del código lector, revisar sus seis consumidores nuevos y conservar los anexos/baselines como evidencia; no hay rollback SQL ni razón para restaurar o borrar datos. Esta fase termina sin iniciar aprobación, publicación, emisión, sustitución de reportes o despliegue.
