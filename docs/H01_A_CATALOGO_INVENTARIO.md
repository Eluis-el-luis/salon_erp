# H01-A — Separación del catálogo y las existencias físicas

## Alcance

El CRUD ordinario (`POST /inventario`, `PUT /inventario/{id}`) aceptaba `$request->all()` y podía cambiar `existencia_actual` y `current_volume` sin lotes, kárdex ni documento. Ahora utiliza exclusivamente datos validados. Ambos campos físicos deben estar **ausentes**: incluso enviarlos con cero o null produce 422. La creación fija ambos en cero, incluida la de artículos fraccionables con capacidad positiva.

Los campos comerciales admitidos son nombre (`producto`), código, ubicación, categoría, marca, precios, proveedor y mínimo de alerta. `stock_min` no es stock físico. El formulario muestra cantidades de consulta separadas del objeto enviado; cambiar capacidad ya no rellena volumen abierto. Los errores de validación se muestran al usuario. El formato JSON se conserva para solicitudes que lo esperan bajo inventario; las respuestas web ordinarias mantienen su formato.

## Interpretación del inventario

`type`, `unit_measure`, `is_fractionable` y `total_volume` solo pueden cambiar si no hay cantidades físicas ni referencias en lotes, kárdex, líneas de compra (incluidas retenidas), detalles/consumos de venta, fórmulas o mermas. Se inspeccionan también registros agotados y eliminados lógicamente. Tener stock cero no equivale a no tener historial.

La actualización bloquea el artículo dentro de una transacción, compara los valores efectivos y consulta referencias mediante lecturas actuales con bloqueo antes de persistir. Enviar de nuevo una capacidad decimal equivalente o un booleano equivalente no constituye un cambio. Una edición comercial no intenta corregir capacidades históricas inválidas: su saneamiento necesita análisis separado. Los artículos nuevos y los cambios estructurales hacia fraccionamiento requieren capacidad positiva.

No se modifican los campos fillable de Articulo: compras y otros servicios siguen necesitándolos. No se cambia InventarioService, CompraService, FIFO, valoración, costos, volumen, contabilidad o nómina. ArticuloController (`/api/items`) contiene métodos vacíos, sin otra escritura de catálogo implementada; no se amplía esa API.

## H01-B pendiente: apertura física documentada

Crear un artículo sin stock queda disponible. La carga inicial de productos que el salón ya posee queda limitada hasta H01-B: no debe realizarse mediante CRUD ni simulando compras o CxP.

H01-B deberá definir, antes de implementarse: cantidades/unidades y volumen compatibles con la representación actual, costo verificable, fecha, usuario, motivo, documento de apertura, lotes, kárdex y contrapartida contable aprobada. Todo debe ser atómico, con bloqueos, prevención de duplicación y reversión trazable. No se incorpora ningún endpoint, cálculo o documento nuevo en H01-A.

El endpoint existente `/inventario/kardex/apertura` fotografía cantidades heredadas **ya presentes** cuando aún no existe kárdex. No es una operación para ingresar stock físico nuevo y no sustituye H01-B. Se conserva su comportamiento.

## Verificación y aislamiento

Se usa PHP 8.3.30 de Laragon, phpunit.xml y el bootstrap seguro existentes. El launcher `tests/Support/run-h16-tests.php` incorpora únicamente selecciones explícitas `h01a` y `h01a-regressions`; mantiene validación de identidad, permisos exactos, rechazo de las otras bases y SHA256 del respaldo. Restablece el indicador a cero en finally. No se utiliza Composer test ni la suite completa.

Comandos focalizados:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/isolation-snapshot.php before H01A
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-h16-tests.php h01a
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/run-h16-tests.php h01a-regressions
node --test tests/Frontend/inventory.test.cjs
npm.cmd run build
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d display_errors=0 -d zend.exception_ignore_args=1 tests/Support/isolation-snapshot.php after H01A
```

El snapshot before es exclusivo y no debe sobrescribirse al repetir verificaciones. El auxiliar solo lee con SELECT/SHOW; la ejecución de PHPUnit usa exclusivamente salon_erp_tester@localhost sobre salon_erp_test. La regresión CadenaSuministroApTest usa RefreshDatabase, autorizado únicamente allí. Las nuevas pruebas del catálogo usan DatabaseTransactions y revierten sus fixtures; sus contadores autoincrementales pueden avanzar incluso con rollback.

La primera regresión ejecutó tres casos (dos aprobados) y se detuvo al no encontrar CMV en una venta retenida. El fixture usaba `admin`, mientras que la configuración vigente exime del umbral únicamente a `alvaro`. Se corrigió solo la identidad del actor del test, con rol y role_id coherentes; no se alteró configuración ni lógica financiera. Se conserva el resultado inicial en `storage/app/private/h01a-regressions-initial.log`. La segunda ejecución focalizada requiere una nueva recreación de salon_erp_test porque el test existente utiliza RefreshDatabase; no afecta otros esquemas.

También se detectó y corrigió el formato HTML de errores de validación en solicitudes JSON de inventario; los primeros intentos detectaron además una comparación incorrecta del fixture antes de releer sus decimales desde MySQL. Se corrigieron las aserciones para comparar registros persistidos. Los 21 casos del catálogo pasaron con 115 aserciones. Las tres pruebas JavaScript y Vite pasaron (advertencia opcional de fontaine; no se instaló nada).

Resultado final: 21 pruebas del catálogo / 115 aserciones y 9 regresiones documentales / 118 aserciones, ambas ejecuciones con salida 0. Total: **30 pruebas PHP / 233 aserciones**, más **3 pruebas JavaScript** aprobadas. Las regresiones comprueban compras, lotes, kárdex, consumo de venta/CMV, mermas, bloqueos y reversión de compras, apertura heredada y vistas. No se ejecutó la suite completa. El indicador terminó en **SALON_TEST_DB_RESET_ALLOWED=0**, comprobado después del finally.

| Base | Antes: tablas / filas / migraciones | Después | Comparación |
| --- | --- | --- | --- |
| salon_erp | 78 / 215 / 74 | 78 / 215 / 74 | Las 77 tablas distintas de sessions y todas las estructuras coinciden exactamente. sessions mantiene dos filas, pero su hash de contenido cambió. |
| salon_erp_test | 78 / 107 / 74 | 78 / 107 / 74 | Esquema recreado exclusivamente para las regresiones autorizadas; fixtures revertidos. No se afirma identidad histórica de sus datos o autoincrementales. |
| salon_erp_bootstrap_check | 78 / 202 / 74 | 78 / 202 / 74 | Igualdad exacta de conteos, contenido y estructura. |

**Limitación de integridad:** el comparador completo devolvió salida 1 por sessions y se conserva esa diferencia, sin ignorarla ni normalizarla para presentar un éxito. La última actividad de sesión consultada fue 2026-11-13 20:20:02, durante la ventana de trabajo. No se determina qué petición la produjo; la actividad HTTP ordinaria del ERP no fue detenida en esta fase. No se hicieron solicitudes de prueba al ERP de desarrollo. La cuenta de PHPUnit carece de acceso a esa base y las herramientas administrativas utilizadas solo emitieron SELECT/SHOW. No hay diferencias detectadas en inventario, documentos comerciales, contabilidad u otras tablas de negocio. Para exigir igualdad absoluta de sesiones en futuras verificaciones debe excluirse también la actividad ordinaria del ERP durante la ventana autorizada.

## Riesgos y reversión

Archivos de la entrega:

| Archivo | Cambio |
| --- | --- |
| `app/Http/Controllers/InventarioController.php` | Contrato validado del catálogo, cantidades cero y guardia de interpretación dentro de transacción. |
| `bootstrap/app.php` | Respuestas JSON de errores para solicitudes de inventario que esperan JSON. |
| `resources/views/inventory/index.blade.php` | Cantidades de consulta, payload sin stock y mensajes de validación. |
| `tests/Feature/CatalogInventoryProtectionTest.php` | Altas, rechazos, historia, edición, roles y CSRF real. |
| `tests/Feature/CadenaSuministroApTest.php` | Actor propietario coherente con la venta inmediata esperada por las regresiones existentes. |
| `tests/Frontend/inventory.test.cjs` | Payload, reinicio y visualización de errores del formulario real mediante VM. |
| `tests/Support/run-h16-tests.php` | Selecciones explícitas H01-A y sus logs, sin ampliar destinos permitidos. |
| `tests/Support/isolation-snapshot.php` | Línea base H01A, comparación de los dos esquemas ajenos a las pruebas y diagnóstico de tablas discrepantes sin exponer registros. Sigue devolviendo error ante diferencias. |
| `docs/H01_A_CATALOGO_INVENTARIO.md` | Decisión, resultados, límites, H01-B y reversión. |

- Protección en el límite HTTP del catálogo; no impide escrituras SQL manuales ni código interno que eluda deliberadamente ese contrato.
- La guarda es conservadora: un artículo agotado con historia no puede cambiar su interpretación. Requiere un nuevo artículo o un procedimiento posterior autorizado.
- No se certifica concurrencia de todos los flujos de inventario heredados; no se cambia su protocolo. El motor puede abortar transacciones con deadlock y no debe considerarse una autorización para reintentos destructivos.
- No se corrigen H02/H03, ni se implementa H01-B; no se altera UI-01 ni CSS de tablas.
- Las comprobaciones JavaScript usan el script real en una VM, y la vista se renderiza mediante Laravel en pruebas. La comprobación manual del modal en un navegador real sigue pendiente.
- Sin migraciones nuevas ni cambios de datos de negocio en desarrollo, la reversión es de código: revertir solamente el diff H01-A y sus pruebas/documentación. No restaurar bases ni ejecutar migraciones para revertir esta entrega. Revertir reabre el defecto original y requiere decisión expresa.

El diff completo, incluidos los archivos nuevos, queda en `storage/app/private/h01a-review.diff` (ignorado por Git). Se puede revisar con `git diff` para archivos seguidos y leyendo los archivos nuevos indicados arriba. Para una reversión posterior autorizada, comprobar primero `git apply --reverse --check storage/app/private/h01a-review.diff`; solo si sigue siendo aplicable, ejecutar la misma orden sin `--check`. No se ejecutó la reversión ni se hicieron commits.
