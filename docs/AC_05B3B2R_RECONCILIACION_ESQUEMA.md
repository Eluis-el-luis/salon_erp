# AC-05B3B2R — Diagnóstico de reconciliación del esquema

Fecha: 20 de noviembre de 2026. **Estado: diagnóstico estático; reconciliación funcional y validación SQL pendientes.** No se presenta AC-05B3B2R como implementada.

La respuesta posterior autorizó lectura por terminal y también incluyó «Limitarse al diagnóstico disponible». Se solicitó aclarar si se mantiene el alcance de implementación original o solo diagnóstico. Hasta resolver ese límite se realizaron únicamente inspecciones, metadatos de archivos y Git: sin SQL, pruebas, modificaciones de auxiliares o nuevo respaldo. Este documento es el único archivo nuevo de esta intervención.

## 1. Estado inicial verificado en Git

- Rama: `feature/panel`.
- HEAD inicial: `330ebd00b8ca2f873e04eed9e20f8e41207c5d31`.
- Índice vacío;21 paths pendientes esperados: auxiliar de respaldo modificado y20 no rastreados de las fases anteriores.
- Se registraron SHA256 iniciales de esos21 archivos; no se alteraron para preparar una integración.
- Se conservan el informe G1, los cinco archivos B1 y los cambios B2. No se ejecutaron add, commit, push, merge, rebase, reset, clean o stash.
- Lectura puntual de `.env.testing`: `SALON_TEST_DB_RESET_ALLOWED=0`; sin editar configuración.

**Cambio externo observado al finalizar:** HEAD avanzó a `07daff20e8abf55a5c49d1dda865211e27c21633`, asunto `Implementacion de ac5`, con padre330ebd00. Esta intervención no ejecutó ese commit. Los21 paths iniciales conservan exactamente sus SHA256 y quedaron integrados en el nuevo HEAD; el índice final está vacío y el único pendiente es este documento nuevo. No se revierte, atribuye o altera el commit externo. Cualquier continuación debe tomar ese HEAD como nueva referencia, después de revisarlo.

Los estados SQL78/74/225,78/74/202 y91/82/1.963 son **antecedentes aceptados de B2**, no una verificación SQL actual de esta fase.

## 2. Respaldo comprobado y respaldo nuevo pendiente

Se verificó el archivo existente de B2 mediante lectura del manifiesto y hash del dump, sin restaurarlo:

| Dato | Resultado |
| --- | --- |
| Archivo | `E:/salon-erp-backups/salon_erp_test_AC05B3B2_20261120_080735_fb27aadd9527.sql` |
| Tamaño actual | 1.536.297 bytes, coincide con manifiesto |
| SHA256 | `3d33ded994f74b98d9a12bedee6a4e12cebe190b1e9ad4931e7f918a470967ff`, coincide |
| Perfil respaldado | 89 tablas/82 migraciones, **anterior** a las tablas sintéticas |

Ese respaldo no se interpreta como una copia de las91 tablas actuales ni de los42 borradores/48 auditorías. El respaldo nuevo de91 tablas solicitado para la reconciliación **no se generó** bajo el límite de diagnóstico. No se afirma restaurabilidad comprobada de ninguno.

## 3. Evidencia histórica y actual en archivos

Se inspeccionaron las claves de tabla de los baselines privados ya existentes:

| Archivo | Tablas test representadas | Significado |
| --- | ---: | --- |
| `storage/app/private/ac05b3b2-isolation-before.json` | 89 | Antes de instalar el prototipo. |
| `storage/app/private/ac05b3b2-isolation-after.json` | 91 | Después del prototipo y sus pruebas. |

La diferencia por nombres es exactamente:

```text
test_financial_presentation_draft_audits
test_financial_presentation_drafts
```

Esto verifica el contenido de la evidencia local, **no** el esquema vivo. No se reescribió un baseline antiguo para añadirle tablas posteriores.

## 4. Perfiles explícitos propuestos

Propuesta no implementada:

| Perfil | Tablas | Migraciones | Uso |
| --- | --- | ---: | --- |
| `AC05_ORIGINAL_89_V1` | Lista exacta de89 nombres originales y sus estructuras aprobadas | 82 | Referencia histórica y comprobación de precondiciones antiguas. |
| `AC05_SYNTHETIC_91_V1` | La misma lista original más las dos test_ explícitas | 82 | Preflight lector actual y nuevo baseline completo. |

El catálogo del perfil debe conservar por tabla una huella de estructura comprobada: columnas, tipos, nulabilidad, defaults, índices, claves, FK y CHECK. Un hash del SHOW CREATE normalizado solo respecto a AUTO_INCREMENT puede servir para estructura; el comparador de preservación de esta fase debe además exigir igualdad del DDL completo y de filas, incluido el contador, porque no se autorizaron escrituras SQL.

Las huellas no deben aprenderse de un esquema vivo sin compararlo primero con la evidencia B2 aprobada. Una tabla desconocida o un cambio de columna/restricción debe fallar, aunque el recuento siga siendo91. Mantener también lista exacta de migraciones, identidad SQL, privilegios y denegación de bases ajenas.

Aceptar el perfil actual no concede capacidad de contabilización, publicación o modificación de borradores.

## 5. Hallazgos estáticos reproducibles por inspección

| Archivo / punto | Comportamiento observado | Implicación |
| --- | --- | --- |
| `tests/Support/run-ac04b1-tests.php`, bloque reports | Exige COUNT de tablas=89 para reports/closing/extract/report-demo. | Rechazará el esquema91 informado antes de lanzar esos grupos. Es una guarda conservadora, no un defecto financiero. |
| `tests/Support/ac04b1-isolation.php`, comparación de tablas añadidas | Para los perfiles AC05 anteriores espera lista añadida vacía. | Comparar su baseline89 con91 falla. No debe cambiarse la evidencia antigua para evitarlo. |
| `tests/Support/SyntheticPresentationHarness.php::preflight` | Comprueba identidad, privilegios,82 migraciones, COUNT recibido, preparado131 e InnoDB de seis tablas. | Admite el91 solicitado por el bootstrap, pero el COUNT y esas seis comprobaciones no certifican todas las columnas/restricciones de las91 tablas. |
| `tests/Support/ac05b3b2-bootstrap.php` | Invoca el preflight B2 con91. | Frontera propia válida como antecedente, que puede complementarse desde un wrapper de reconciliación sin modificar sus bytes. |
| `tests/Support/ac05b3b2-preflight.php` | Exige89 y reserva su manifiesto inicial con creación exclusiva. | Es preflight histórico de instalación; no reutilizarlo como preflight actual ni sobrescribir su manifiesto. |
| `tests/Support/SyntheticPresentationDraftSchema.php::install` | Exige89 antes de ejecutar sus CREATE TABLE y coteja respaldo/baseline históricos. | Debe seguir rechazando91; no ejecutar el instalador para probarlo en esta fase. |
| `tests/Support/ac05b3b2-isolation.php` | Compara originales exactos y permite las dos tablas nuevas como transición89→91; archivos before/after exclusivos. | Evidencia de la instalación B2, no un nuevo comparador91→91 de preservación total. |
| `tests/Support/ac05b3b2-final.php` | Relee todas las cadenas pero crea el manifiesto final con `xb`. | No repetirlo sobre el manifiesto existente. Reutilizar su lógica lectora mediante una prueba nueva sin sobrescritura. |
| `tests/Support/ac04b1-backup.php` | Admite fase B2, verifica82 migraciones y exporta todas las tablas presentes. El manifiesto de fase usa creación exclusiva. | No repetir la misma fase B2: preservaría el dump anterior, pero no podría reservar su manifiesto otra vez. Hace falta identidad nueva de respaldo o wrapper específico revisado. |

No se reprodujeron estos rechazos ejecutando PHP/SQL en R; son condiciones explícitas observadas en código.

## 6. Clasificación de auxiliares y cambio mínimo recomendado

### A. Lectores compatibles con el esquema ampliado

Las consultas del extractor y demo no necesitan reinterpretar las tablas test_. El runner debe permitir seleccionar **expresamente** el perfil91 para modos lectores revisados, comprobando su lista/estructura completa. No cambiar indiscriminadamente todos los modos ni sustituir89 por91 como único control.

Para AC-04, revisar únicamente preflights de consulta vigentes con82 migraciones. Mantener cerrados los modos financieros, carreras e instalaciones anteriores; algunos esperan80/81 migraciones y deben conservar ese significado.

### B. Comprobaciones históricas

Conservar intactos los baselines89 y el comparador que documentó89→91. Crear un baseline R nuevo, identificado con perfil91, sin admitir altas persistentes adicionales. Las lecturas de B1 no requieren SQL; sus pruebas puras no necesitan adaptación de esquema.

### C. Instaladores

Conservar sus precondiciones de instalación y exclusividad de manifiestos. Verificar su rechazo mediante simulaciones puras de perfiles incorrectos y lectura de la guarda; no invocar sus métodos DDL sobre91 tablas.

## 7. Línea base y aislamiento pendientes

La implementación posterior debería crear una línea base R propia de las tres bases, después del preflight de identidad/privilegios/esquema. Comparar al terminar:

1. Conjunto de nombres de tablas exacto, sin extras u omisiones.
2. DDL completo de todas las tablas, sin cambios de restricciones o columnas.
3. Conjunto de hashes de todas las filas exacto; no solo recuentos o inclusión de filas antiguas.
4. Preparado131 idéntico y sin resultado financiero.
5. Los42 borradores y48 auditorías idénticos por contenido y referencias; verificación lectora de todas sus cadenas.
6. Cero documentos financieros nuevos y desarrollo/bootstrap idénticos.

No se creó ese baseline ni se certificó el estado actual SQL bajo el alcance estático.

## 8. Pruebas focalizadas propuestas, no ejecutadas

- Perfil histórico acepta exactamente su universo89 y rechaza91 como si fuera el mismo perfil.
- Perfil actual acepta las89 originales más las dos test_ exactas.
- Sustituir una tabla por otra manteniendo91 entradas provoca rechazo.
- Simular columna, FK, CHECK, índice, tipo o migración alterados provoca rechazo sin DDL.
- Identidad, rol SQL, grants o base incorrectos fallan antes de utilizar el perfil.
- Baseline identificado con otro perfil no puede compararse como si fuera actual.
- Comparador puro detecta fila, hash, DDL o tabla cambiados, incluido borrador/auditoría nuevos.
- Validación lectora READ ONLY de contratos y cadenas existentes, sin revisar ni guardar borradores.
- Comparación de reportes solo lectura bajo snapshot propietario, sin crear períodos/asientos.
- Regresiones puras B1 y clasificador, sin bootstrap de bases.

Se inspeccionó `ReportPresentationHttpTest`: consulta documentos y usa sesiones/CSRF en archivos, sin crear fixtures financieros; sería candidato lector tras revisión completa. `AccountingReportSnapshotTest` inicia un worker de escritura rollback-only: no es necesario para reconciliar perfiles y se recomienda no ejecutarlo aquí. Tampoco se necesitan DatabaseTransactions, carreras financieras o fixtures SQL nuevos.

## 9. Restricciones preservadas

No se modificaron repositorio sintético, validador, canonicalizador, extractor, clasificador, comparador, cierre, contabilidad, reportes, bancos, inventario, nómina, cuentas o autorizaciones. No se ejecutaron SQL, PHP/Artisan/PHPUnit, DDL, DML, instaladores, seeders o servidores. No hay rutas, migraciones o perfiles activos nuevos.

Los helpers B2 conservan sus guardias CLI y de procedencia simulation; una propuesta de perfil no se convierte en autoridad económica. Las cuentas, asientos y documentos reales no se utilizaron como fixtures desechables.

## 10. Estado de las bases y de los documentos

| Elemento | Antecedente B2 aceptado | Verificación nueva en R |
| --- | --- | --- |
| salon_erp | 78 tablas/74 migraciones/225 filas | No consultada mediante SQL. |
| salon_erp_bootstrap_check | 78/74/202 | No consultada mediante SQL. |
| salon_erp_test | 91/82/1.963 | No consultada mediante SQL. |
| Borradores/auditorías | 42/48, cadenas íntegras | No se releen ni modifican mediante SQL en esta inspección. |
| Preparado131 | Conservado por B2 | No se verifica nuevamente contra SQL. |

No hay operaciones de esta fase que hayan podido modificar esos datos. Esa ausencia de operaciones no se presenta como una nueva comparación SQL de integridad.

## 11. Riesgos y límites

El principal riesgo sería debilitar un preflight para «aceptar91»: un COUNT correcto no prueba estructura y añadir tablas a un baseline histórico cambia su significado. Tampoco basta verificar los nombres de las dos tablas nuevas si una de las89 originales cambió.

Otro riesgo es repetir auxiliares históricos que reservan archivos o crean fixtures durables. Deben utilizarse evidencias nuevas y lectores específicos, sin sobrescritura ni carreras anteriores. El respaldo verificado de89 tablas no sustituye la copia actual91 solicitada.

La clasificación del riesgo es estática. No se afirma que el esquema vivo haya sufrido alteraciones o que un actor haya eludido las guardias.

## 12. Archivos, diff y preservación

Único archivo propio de esta inspección: `docs/AC_05B3B2R_RECONCILIACION_ESQUEMA.md`.

No se modificaron auxiliares, perfiles, pruebas, migraciones o archivos anteriores. El índice permanece sin preparar. La revisión Git debe distinguir esta adición documental de los21 paths previos, cuyos bytes se conservaron y que fueron integrados por el commit externo descrito en sección1.

## 13. Reversión segura

Si se descarta este diagnóstico, retirar únicamente este documento tras revisión. No hay código, perfil instalado, dato o DDL R que revertir. Conservar todos los respaldos, baselines, manifiestos, filas y cambios anteriores; no ejecutar reset/clean/stash/checkout o restauración de bases.

## 14. Continuación requerida

Para completar la reconciliación originalmente solicitada falta confirmar si la respuesta que incluyó también «Limitarse al diagnóstico disponible» permite modificar auxiliares y realizar las verificaciones SQL lectoras. De confirmarse el alcance completo, seguir el orden: preflight restringido exacto → respaldo nuevo91 → baseline R → perfiles/compatibilidad explícitos → pruebas puras y lectoras → comparación exacta final.

Hasta esa aclaración, esta entrega queda **limitada al diagnóstico estático**. No declara completada la implementación AC-05B3B2R ni inicia publicación, retirada empresarial, emisión, sustitución de reportes, AC-06, H01-B2B o despliegues.
