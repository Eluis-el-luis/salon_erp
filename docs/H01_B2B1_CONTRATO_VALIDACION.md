# H01-B2B-1 — Contrato de validación sin escritores

Fecha: 2026-11-16. Estado: completado en el alcance de validación.
**No permite confirmar aperturas ni emitir efectos físicos o financieros.**

## 1. Archivos y responsabilidades

| Archivo nuevo | Responsabilidad |
| --- | --- |
| app/Services/OpeningDocumentContract.php | Validación pura, normalización decimal, política requerida, canonicalización y SHA256; ninguna conexión o persistencia |
| app/Services/OpeningInventoryEligibility.php | Inspección reutilizable por SELECT parametrizados y evaluación conservadora de fotografía de artículo/historia |
| app/Services/OpeningDocumentIdentity.php | Clasificación interpretativa de identidad y reintentos; no concede éxito financiero ni emite confirmed |
| app/Exceptions/OpeningDocumentValidationException.php | Errores estructurados por campo sin arrancar Laravel; traducibles a validación HTTP en una fase futura |
| tests/Support/OpeningContractHypotheses.php | Catálogo y políticas hipotéticas solo en memoria, expresamente ajenos a configuración empresarial |
| tests/Support/run-opening-contract-tests.php | Selección fija de cuatro pruebas unitarias; exige PHP 8.3.30, PDO deshabilitado e indicador cero; finally conserva cero |
| tests/Unit/OpeningDocumentContractTest.php | Cabecera, decimales, posiciones, políticas, hash y ausencia de mutación |
| tests/Unit/OpeningEligibilityTest.php | Historia, representación, archivados y adaptador SELECT con conexión simulada |
| tests/Unit/OpeningIdentityTest.php | Misma clave, conflictos, preparados y confirmados hipotéticos solo en memoria |
| tests/Unit/OpeningCatalogueGuardTest.php | Ejecuta validación real de InventarioController::store/update, rechazando campos físicos antes de persistir |

Este informe es el único archivo documental creado en esta fase. El documento
H01_B2B0_POLITICAS_APERTURA.md ya estaba sin seguimiento y permanece sin cambios.
No se modificaron archivos existentes, rutas, controladores, modelos, configuración,
migraciones, catálogo contable, credenciales o dependencias. No hubo commits.

## 2. API interna y separación de confianza

`OpeningDocumentContract::prepare(input, items, policy, actor)` recibe:

- input: exclusivamente campos documentales admitidos y posiciones del conteo.
- items: mapa por ID con fotografías de catálogo suministradas por servidor.
  Debe usar booleano estricto para is_fractionable, versión integer/null y decimales
  de catálogo como cadenas exactas; no reutilizar el cuerpo HTTP como catálogo.
- policy: contexto explícito del servidor con aprobaciones P1–P9, referencia,
  alternativa y metadatos contables, corte/zona, fuentes, costo cero, lote/vencimiento,
  clasificación individual y responsables. No lee ni instala configuración activa.
- actor: identidad autenticada del servidor. No acepta executed_by del navegador.

Sin política, con aprobaciones incompletas o decisiones contradictorias lanza
OpeningDocumentValidationException con errors por campo/política. No inventa código,
fuente, fecha, excepción ni lista de clasificación. Valida que la contrapartida
declarada en contexto sea activa, de movimiento, patrimonial acreedora y consistente
con el código; rechaza utilidades retenidas/retiros y sus subcuentas. Esto **no**
consulta ni certifica el catálogo real o una autorización humana: ambos siguen
pendientes del futuro origen confiable de políticas.

El resultado contiene operation_uuid, content, canonical_json, content_hash y
**confirmation_allowed=false**. No es un documento persistido ni comprobante de
elegibilidad, PIN, cuenta o período abierto. assertConfirmationUnavailable siempre
lanza rechazo, incluso con todas las hipótesis de aprobación presentes.

No se crearon requests conectados a rutas, formularios ni endpoints. La integración
HTTP posterior deberá convertir errors a 422 y resolver contexto exclusivamente
en servidor; todavía no puede guardar ni confirmar estos resultados.

## 3. Validación documental y física

- Lista cerrada de campos; rechaza status, content_hash, policy, supervisor, ejecutor,
  resultados de lote/movimiento y marcadores físicos suministrados por cliente.
- UUID de formato canónico normalizado a minúsculas. Referencias/descripciones UTF-8
  no vacías y acotadas; evidencia versión integer 1 con reference/description.
  Campos adicionales de responsables en evidencia también se rechazan.
- Instantes con zona explícita, calendario real y normalización UTC. Corte y conteo
  deben coincidir con el corte suministrado por la política hipotética. No se ofrece
  conciliación retrospectiva de un conteo anterior ni se simula una fecha aprobada.
- Decimal de entrada solo string/int; float, vacío, negativo, notación exponencial,
  NaN/INF y exceso de escala rechazados. InventoryDecimal realiza escalado entero
  antes de cualquier posible PDO; no hay redondeo silencioso.
- Unidades/cerrados enteros positivos; remanente positivo <=capacidad; abierto
  completo continúa open y nunca se convierte en cerrado. Capacidad/unidad contrastan
  con fotografía de catálogo; ml/gr/oz no se convierten.
- Varias posiciones por artículo conservan cantidades y valores independientes;
  posiciones numéricas duplicadas, dos abiertos y duplicación de una misma constatación
  física (artículo/clase/lote/referencia) son rechazados. Conteos independientes deben
  tener referencias de posición que permitan diferenciarlos.
- Comprueba límites por posición, agregado por artículo, contador entero y total
  monetario. Importes se suman en centavos y deben coincidir con total declarado.
  No calcula valor desde precio comercial o costo informativo redondeado.
- Fabricante desconocido solo se representa si el contexto hipotético lo permite;
  conserva manufacturer_lot=NULL y lot_origin=internal, sin fabricar un lote real.
- Aplicabilidad de vencimiento procede de política por artículo, no solo del cliente;
  conocido posterior al corte o no aplicable expresamente aprobado. Desconocido,
  vencido o contradictorio se rechazan. Ninguna excepción queda instalada.
- Fuente de valoración debe pertenecer a las admitidas en contexto y tener evidencia.
  Cero solo es representable bajo la hipótesis explícita documented; no es sustituto
  de ausencia, política real ni autorización de contabilización.

La salida prepara el significado de las posiciones compatible con B2A; no representa
un array listo para asignación masiva. Una futura persistencia deberá mapear campos
explícitos a cabecera/líneas y JSON; nunca pasar content directamente a create().

## 4. Canonicalización, hash e interpretación de identidad

Claves de objetos ordenadas recursivamente; posiciones ordenadas por position;
cantidades/valores con escalas fijas, IDs enteros, instantes UTC y UUID en minúsculas.
Listas de clasificación y fuentes de política se ordenan y eliminan duplicados.
Contenido incluye actor, política, acta, fechas, cantidades, evidencias y valores.
UUID queda fuera del hash, al igual que PIN, CSRF y resultados/timestamps generados
(estos últimos ni se admiten en la entrada). Cambiar contenido significativo cambia
el SHA256; cambiar solo formato equivalente u orden de posiciones no lo cambia.

OpeningDocumentIdentity clasifica:

| Situación | Resultado interpretativo |
| --- | --- |
| Clave nueva sin historia informada | new_key_requires_eligibility |
| Clave distinta/nueva con historia | new_key_history_conflict |
| Misma clave y hash diferente | same_key_content_conflict |
| Misma clave/hash, prepared | prepared_not_success |
| confirmed sin fotografía/hash/vínculos coherentes en memoria | confirmed_incomplete |
| confirmed con fotografía y estructura de resultados completas en memoria | same_key_confirmed_candidate_requires_locked_verification |

El último caso no afirma integridad real: no se consultan filas/asientos, permisos
o conciliación bajo bloqueo. Los fixtures confirmed existen solo como arrays PHP,
no fueron emitidos ni persistidos. No hay garantía de idempotencia de efectos.

## 5. Elegibilidad y límites de la inspección

inspect(connection, itemId) recibe una conexión explícita; únicamente invoca select
con bindings y nombres de tabla de una lista interna. No crea conexiones por defecto,
transacciones, locks, clasificaciones o registros. assess(item, history) permite probar
la decisión sin SQL. Los motivos se devuelven junto con requires_locked_recheck=true
y confirmation_allowed=false; un resultado provisional limpio no concede autorización.

Detecta inexistencia/archivo/tipo servicio, saldos físicos o costo residual no cero,
datos desconocidos y marcador incompatible. Comprueba lotes incluso agotados,
kárdex, asignaciones, líneas de compra/venta, consumos, mermas y líneas de apertura
previas. No presume ausencia de efectos por un estado comercial determinado.
Los preparados con líneas también son una dependencia conservadora que requiere
revisión; no se eliminan ni se toman como una apertura confirmada.

Relaciona fórmulas con detalles de servicios documentados y consulta ingredientes
fotografiados mediante JSON_CONTAINS, no solo la receta actual. Una fórmula de
catálogo sola no es historia física. Si hay servicios históricos sin fotografía
de versión/modo/ingredientes verificables, bloquea conservadoramente por composición
no resuelta, incluso si la receta vigente no menciona el artículo. Esto puede exigir
conciliación amplia; no reconstruye consumos ni reclasifica historia.

Las consultas reales de este adaptador no se ejecutaron en MySQL en esta fase;
se comprobó su contrato mediante mock de ConnectionInterface que solo permite
SELECT. La validación del SQL en el esquema real, planes y tiempos queda pendiente
de un preflight/ensayo de lectura autorizado antes de su integración operativa.
Tampoco se prueban ausencia de documentos externos ni concurrencia mediante mocks.

## 6. Pruebas y verificaciones

Comando final ejecutado:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d disable_classes=PDO,mysqli -d zend.exception_ignore_args=1 tests/Support/run-opening-contract-tests.php
```

Resultado final: **108 pruebas, 184 aserciones, 0 errores/fallos**, exit 0, PHP 8.3.30.
PHPUnit cargó phpunit.xml/tests/bootstrap.php; no arrancó Laravel. El runner ejecuta
solo las cuatro clases nuevas, exige indicador cero y restaura variables de proceso
en finally. PDO y clase mysqli estaban deshabilitados durante los ensayos.

Cobertura: cabecera, evidencia, políticas ausentes/contradictorias, cantidades,
capacidad/unidad, duplicados, límites, valores independientes por lote, hash,
identidad, historia y ausencia de mutación. Doce casos ejercitan store/update reales
de H01-A usando Request con la fábrica de validación de Laravel sin aplicación;
incluso cero/NULL en existencia_actual/current_volume falla antes de modelos/SQL.
No equivale a una nueva regresión HTTP/CSRF o documental financiera completa.

Durante la revisión se añadió el límite real de código contable de 20 caracteres;
el código ficticio del fixture lo excedía y provocó 11 errores en una ejecución.
Se acortó exclusivamente el código hipotético y se repitieron los grupos con la
versión definitiva: todos pasaron. No se relajó la validación para aceptar el fixture.

Los diez PHP nuevos pasaron lint. Diff inverso comprobado sin aplicarlo. No se
repitieron pruebas de instalación/rollback de B2A ni RefreshDatabase. Las 79
migraciones y sus restricciones permanecen sin cambios; compatibilidad B2A aquí
es estática/contractual, no una nueva prueba DDL.

## 7. Bases, guardias, políticas y continuación pendiente

No se ejecutó SQL contra salon_erp_test, salon_erp o salon_erp_bootstrap_check.
No se crearon/actualizaron documentos prepared/confirmed en ninguna base. No hubo
lotes, ledger, asignaciones, clasificaciones NULL→2, cantidades, asientos, compras,
pagos, pasivos o ingresos emitidos. No se modificó ni deshabilitó la ruta heredada.
El indicador local fue comprobado nuevamente: SALON_TEST_DB_RESET_ALLOWED=0.

Al no existir pruebas SQL no se abrió un preflight SQL ni se afirmó una nueva
identidad/permisos/SHA256 o comparación de contenido. El último estado documentado
es test79/desarrollo74/bootstrap74; no es una medición nueva de este ensayo.

P1–P9 siguen pendientes de autorización humana según H01_B2B0. Las aprobaciones
del trait de pruebas son hipótesis inequívocas solo en memoria, sin cuenta SQL ni
configuración empresarial activa. El futuro servicio debe resolver políticas,
catálogo, supervisor y evidencia verificada desde servidor; una aprobación booleana
o un hash suministrados por cliente no los acreditan.

Antes de confirmar en otra entrega hacen falta guardas de corte/pendientes,
autorización PIN, lecturas actuales bajo el protocolo de bloqueos, persistencia de
idempotencia, conciliación física/monetaria, prueba real de consultas y carreras
MySQL. No hay activación V2/despliegue en desarrollo ni autorización de saldos.

## 8. Diff y reversión

Diff de los diez archivos PHP nuevos, excluyendo informes:
`storage/app/private/h01b2b1-review.diff` (48.230 bytes).
SHA256: `a5b93d0edf41a21394ebaab2e677a65c1fb083cde28d1861d3dfb6d5e8999449`.
git apply --reverse --check pasó sin aplicar cambios.

Para retirar esta entrega, revisar ese parche y retirar solo esos archivos nuevos
y este informe. Preservar el diseño B2B0 preexistente. No hay cambios SQL que revertir:
no ejecutar rollback, borrar evidencia B2A ni modificar las tablas documentales.
No se realizó commit ni se avanzó a escritores físicos/contables o formularios.
