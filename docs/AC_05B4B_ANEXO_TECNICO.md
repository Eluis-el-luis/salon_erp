# AC-05B4B — Anexo técnico de fuentes y límites

Este anexo respalda el [paquete para el contador](AC_05B4B_PAQUETE_REVISION_CONTABLE.md). No contiene asignaciones empresariales aprobadas.

## 1. Referencia y método

Rama feature/panel; HEAD de inspección `82f39d583d000d14486e36464d47f2d1a0ea9249`. Árbol e índice inicialmente limpios. Solo lectura de archivos mediante PowerShell/rg, incluida lectura ZIP/XML del Excel con .NET; sin ejecutar PHP, SQL, PHPUnit, Artisan, macros o fórmulas. No se consultaron siquiera las bases test/desarrollo/bootstrap en esta fase.

La fuente de datos es **el snapshot documentado en AC-05B4A**, no un inventario SQL renovado. Se conservan sin cambios su CSV, JSON y documento. Sus68 cuentas/298 detalles, sus propiedades y dependencias no se reinterpretan como registros empresariales. No se afirma que las bases no hayan cambiado desde aquel snapshot; solamente que B4B no las consultó ni modificó.

La matriz B4B se elaboró en memoria a partir del JSON B4A y las definiciones estáticas. Se cotejaron63 códigos, nombres, tipos y naturalezas completos contra ambos seeders, no solo prefijos. Los padres se expresan por código de referencia a partir de los IDs del mismo anexo; el ID test conserva una columna explícitamente técnica. El nivel/imputabilidad/estado son los observados en B4A, no atributos empresariales autorizados.

No se demuestra la ejecución individual del seeder para cada fila: los métodos son idempotentes y pueden reutilizar cuentas existentes. No se conoce el acto de creación exacto de todas las cuentas. No se atribuye un creador ni una aprobación a partir de marcas temporales.

## 2. Fuentes verificables

| Clave | Archivo o sección inspeccionada | Aporte y límite |
| --- | --- | --- |
| INV | docs/AC_05B4A_CATALOGO_ANEXO.json; matriz y documento B4A |68 cuentas completas, metadatos y agregados; datos test, no catálogo empresarial ratificado. |
| BAS | database/seeders/ContabilidadBaseSeeder.php, run/cuenta/cuentas |50 definiciones base. Declara familias, tipos, naturalezas y jerarquías; no prueba ejecución original ni aprobación económica. |
| COMP | database/seeders/CuentasComplementariasSeeder.php, run/asegurar |13 definiciones complementarias y finalidad de los módulos; el alta depende de que exista el padre. |
| INI | database/seeders/DatabaseSeeder.php; RrhhBaseSeeder.php |Orden de inicialización esencial. RRHH crea estructuras con cuenta_contable_id NULL; no es una aprobación de asignaciones salariales. Ninguno se ejecutó. |
| RET | database/migrations/2026_09_17_000000_add_retiros_propietario.php; BAS |Documenta cuenta patrimonial3.3 y desactivación7.2. No autoriza reinterpretar movimientos históricos. |
| SYS | database/migrations/2026_09_22_000001_add_is_system_account_to_cuentas_contables.php |La migración marcaba todas las filas existentes como sistema. Por tanto is_system_account no certifica por sí solo la procedencia de una cuenta. |
| ENS-1 | tests/Feature/AccountingAdministrationConcurrencyTest.php:19 |Alta explícita de cuenta sintética con código6.98 más sufijo aleatorio, nombre/tipo/naturaleza/padre cotejables; no reconstruye la sesión que creó ID2564. |
| ENS-2 | tests/Feature/PilotDemoHttpExecutionTest.php:30–35 |Define gasto/fondos sintéticos6.HTTP/1.1.2.HTTP con sufijo común y metadatos de ensayo. No se ejecutó el test. |
| ENS-3 | docs/AC_04B2B4B_PILOTO_HTTP_TEST.md, sección7 |Identifica expresamente cuentas2662/2663 y efectos del piloto, incluida una contrapartida base6.9. |
| EXL | docs/CONTABILIDAD ALVARO RUGAMA.xlsx, catalogo de cuenta!B3:B40 |Referencia conceptual original; no autorización/importación. |
| EXL-D | docs/AC_01_CONFIGURABILIDAD_CONTABLE.md, sección7 |Análisis previo de divergencias, referencias y precisión del diario. Se cita como antecedente; no se recalcularon sus cifras en B4B. |
| CAT | app/Models/CuentaContable.php; app/Services/AccountIntegrityService.php |Catálogo único, dependencias, protección de cuentas/ramas con uso y reversas documentadas. No clasifican estados financieros. |
| REP | ContabilidadController::resultados; PeriodoContableController::balanceGeneral/cierreFiscal; DashboardService::consultaResultados |Universos y agrupaciones vigentes; no se cambiaron ni ejecutaron. |
| GOV | docs/AC_05B3B0_CONTRATO_CLASIFICACION.md |Separa catálogo, contabilización, presentación y edición emitida; modelo de gobierno aún por aprobar. |

Se revisaron además AccountingPolicyService, AccountingPolicyRoles, AccountingAccountResolver y AccountingEventAuthorizationService; antecedentes AC-03A/B, AC-04B2B1 y funciones relevantes de ContabilidadService/FondosService.

## 3. Procedencia de las68 referencias

- **A:50**, definiciones BAS, observadas en IDs2269–2318. Su función estructural puede identificarse por código completo y atributos cotejados; no se afirma trazabilidad de una inserción específica.
- **B:13**, definiciones COMP, IDs2319–2331. Son también cuentas protegidas del sistema según INV; B describe el origen funcional complementario, no contradice ese atributo.
- **C:5**, IDs2564/2660/2661/2662/2663; códigos y etiquetas del anexo cotejados con ENS-1/2 y antecedente ENS-3. Las dos últimas cuentan con referencia histórica explícita de IDs; para las otras se reconoce el fixture y rotulación, sin reconstruir el acto individual ni inventar una auditoría de creación.
- **D:0** cuentas sin definición/antecedente cotejable en este paquete. La incertidumbre sobre acto individual se conserva en la columna de confirmación de procedencia; no se oculta tras ese cero.

La categoría A/B no autoriza tratar los movimientos de esas cuentas como reales. En B4A,21 detalles utilizan cuentas C (10 en2662 y11 en2663); los277 restantes **no quedan certificados como empresariales**. Excluir filas por cuenta puede romper asientos compartidos. Hace falta una delimitación documental legítima; no se propone borrado, backfill, renumeración o promoción de fixtures.

## 4. Excel original y límites de correspondencia

Archivo21243 bytes; SHA256 `03005277d86e89a2124e2cb5f511b91d97f6754e31b00de532fe78840fcad58d`, coincidente con AC-01. Se verificaron las hojas Libro Diario y catalogo de cuenta, y se leyeron las38 celdas descriptivas B3:B40 de esta última, sin extracción a disco ni ejecución de fórmulas. No se inspeccionó el .xlsm general para deducir otro catálogo: no es la referencia contable inequívoca solicitada.

Soporte conceptual concreto: B3/B14/B22/B25/B30/B34 declaran activo, pasivo, patrimonio, ingresos, costos y gastos operativos. B5–B9 fondos/CxC/inventarios; B11–B13 bienes de uso; B16–B21 obligaciones; B26–B29 actividades; B31–B33 costos. B40 está incompleta y existen notas entre corchetes. No se trataron como códigos importables.

AC-01 documenta que el diario utiliza, por ejemplo,1.2 para bancos donde el catálogo lo describe como activo no corriente;7.5/7.6 con varios significados, y celdas con #REF!/precisión interna no monetaria. Son discrepancias pendientes, no correcciones autorizadas. Las semejanzas entre Excel y BAS respaldan discutir familias; no crean una homologación uno a uno.

## 5. Contratos actuales que deben permanecer separados

1. **Resultados/dashboard:** cuentas imputables LIKE4.%–7.%, fecha del asiento, origen distinto de cierre_fiscal. La desigualdad SQL también excluye NULL. Sin filtro de estado/activa.4 usa H−D;5/6/7 D−H.
2. **Balance:** imputables, fecha hasta corte, todos los orígenes y estados; primer carácter1–6 determina ramas, resto cae en otros. Incluye cierre y calcula resultado residual virtual;3.99 de presentación no es una nueva cuenta persistida por este paquete.
3. **Cierre escritor:** selección nominal4.%–7.% por periodo_id, no por el mismo rango de fecha de resultados; excluye cierre y NULL mediante !=, contrapartida3.2. Conserva corrección AC-05C0 de importes no negativos y efecto firmado. Clasificar una cuenta de modo distinto en un informe no cambia este escritor; cualquier divergencia exige evaluación separada antes de implantación.
4. **AC-03:** seis papeles CORE para ventas/inventario, más fondos.banco/gasto.cargo_bancario y apertura reservada. Los bindings determinan efectos de contabilización, no todas las categorías de presentación. Publicación técnica no implica aprobación empresarial. resolveForPosting y assertBusinessAuthority mantienen rechazo expreso; los actos de ensayo existentes no se promueven.
5. **Contratos AC-05:** validador/adaptador existentes aceptan simulation y procedencia sintética; una futura vía empresarial no se obtiene sustituyendo un campo por business. No se diseña ni implementa esa ampliación en B4B.

## 6. Controles de la matriz

68 filas únicas por ID técnico y código, con padre y atributos conservados. A/B suman63 referencias de definición; C suma5 referencias segregadas. Todos los estados de revisión indican NO REVISADA; ninguna pertenencia empresarial está confirmada. Grupos sugeridos llevan el rótulo NO APROBADA; donde el hecho no está suficientemente definido se usa PENDIENTE. El tipo/naturaleza observados no se presentan como aprobación de un rubro.

La base propuesta para4.5 es H−D, y para3.3 H−D dentro de patrimonio: sus naturalezas deudoras no se usan como multiplicador positivo. Para reembolso7.1 y legado7.2 se deja la base pendiente porque no se debe decidir su hecho económico por el rótulo.

Nivel1 tiene28 filas y nivel2 tiene40. Los niveles organizan revisión, no cobertura. Se priorizan estructuras y cuentas representativas, conceptos controvertidos5.3/5.4/7.1 y los dos casos de ensayo con actividad; la cuenta inactiva7.2 queda en revisión histórica. Toda configuración global futura necesitará resolver la cobertura completa de su universo, incluidos ceros relevantes e inactivas con historia.

## 7. Entrega y límites de verificación

Archivos nuevos: `AC_05B4B_PAQUETE_REVISION_CONTABLE.md`, `AC_05B4B_MATRIZ_REVISION.csv` y este anexo. No se modificaron fuentes de aplicación, pruebas, seeders, migraciones, reportes, configuración ni antecedentes. No se ejecutaron SQL, DDL/DML, pruebas o comandos del ERP. La comprobación documental y Git no equivale a un nuevo preflight SQL ni confirma recuentos vivos actuales.

No se invocó ningún escritor, no se generaron aprobaciones, firmas, asientos, asignaciones o ediciones emitidas. No se modificaron credenciales, permisos, APP_KEY, AGENTS.md o dependencias. El indicador de reset se conserva en su archivo; no se cambió configuración. El propietario mantiene el control de commits; no se preparó el índice.

Una futura implementación requiere primero las decisiones económicas y de alcance del paquete, después autorización técnica explícita. Su diseño debería reutilizar catálogo único, lectores y gobierno existentes, mantener los contenidos sintéticos separados, detectar falta de cobertura sin inferencias y comparar reportes antes de sustituirlos. No se autoriza una migración, una nueva aprobación ni una activación por esta recomendación.
