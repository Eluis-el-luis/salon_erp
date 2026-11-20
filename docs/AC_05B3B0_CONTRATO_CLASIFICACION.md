# AC-05B3B0 — Contrato y gobierno de clasificación financiera

Fecha: 20 de noviembre de 2026. Alcance: diseño e inspección estática. **No constituye aprobación contable, autorización de DDL, implementación o publicación empresarial.** Único archivo creado en esta fase: este documento.

Convenciones: **H** = hecho observado en código/Git; **P** = propuesta técnica no implementada; **D** = decisión económica o de gobierno pendiente de aprobación humana. Las pruebas, cifras y estado SQL de fases anteriores son antecedentes; no se verificaron nuevamente contra bases en esta fase.

## 1. Separación de responsabilidades y situación observada

| Concepto | Responsabilidad | Lo que no autoriza |
| --- | --- | --- |
| A. Catálogo | Identidad de cuenta, código, tipo, naturaleza, padre, imputabilidad y actividad; protegido por AC-02. | Cambiar retrospectivamente cuentas utilizadas para acomodar un reporte. |
| B. Selección para contabilizar | AC-03 y servicios de dominio determinan la cuenta que recibe un efecto operativo; conservan importes y valoración. | Convertir sus bindings en categorías universales de reportes. |
| C. Presentación financiera | Asigna IDs existentes a rubros, signos y posiciones de estados; define su universo documental. | Elegir cuentas nuevas para un asiento, alterar D/H o cambiar el cierre. |
| D. Edición emitida | Conserva una salida concreta, sus fuentes y clasificación utilizada, con identidad e integridad propias. | Atribuir una edición archivada a toda consulta retrospectiva o reemplazar una emisión anterior. |

**H:** `SyntheticAccountingPresentation` solo admite purpose simulation, contrato synthetic-presentation-v1, asignaciones por account_id y siete grupos fijos. Rechaza duplicados y cobertura relevante ausente; calcula en centavos y devuelve issued_statement=false. Sus parámetros de lectura y hash no forman una aprobación económica ni un archivo de emisión.

**H:** `AccountingReportExtractor` obtiene candidatos y consulta el legado en un snapshot; `AccountingPresentationComparison` separa totales globales, subconjunto comparable y diferencias por ID. La demo8778 conserva sus restricciones de test. El límite lector actual es1.000 detalles/500 cuentas, no una capacidad empresarial certificada.

**H:** AC-03 tiene versiones, bindings, fotografías de cuentas y auditoría orientados a efectos de contabilización. Sus seis papeles CORE y el piloto bancario no cubren la clasificación completa del mayor. `auditoria_asientos` exige asiento_id y tiene su semántica propia; no sirve como auditoría de una clasificación que no debe crear un asiento.

**P:** conservar lectores y protecciones existentes; añadir en una fase autorizada un contrato específico de presentación y su gobierno, sin segundo catálogo ni motor financiero. Ninguna versión publicada AC-03 se modifica para ello.

## 2. Contrato mínimo propuesto

Nombre conceptual: `financial-presentation-v1`, distinto del contrato sintético vigente. Su compatibilidad/implementación requerirá autorización; no se cambia el contrato de AC-05B1 con este documento.

Separar **contenido económico canónico** de **evidencia de ciclo de vida**:

| Componente | Campos mínimos propuestos |
| --- | --- |
| Identidad | UUID de familia, número de versión, versión de contrato, etiqueta; ámbito de instalación/empresa verificado por servidor. |
| Procedencia | purpose simulation/business, origen synthetic_fixture/administrative, contrato de creación, creador autenticado y fecha UTC de preparación. Inmutables en su origen. |
| Contenido | Manifiesto de grupos/subgrupos, layouts de resultados/balance, asignaciones explícitas por cuenta ID, fotografías de revisión y perfiles documentales. |
| Orden y cálculo | Claves estables, orden de presentación, grupo económico y base D−H/H−D; subtotales admitidos por contrato, sin expresiones ejecutables. |
| Cobertura | Alcance global/subconjunto, cuentas/criterios de relevancia, perfiles y horizonte revisados, evidencia/hash del diagnóstico de cobertura. |
| Tiempo | Intervalo UTC de vigencia propuesto [desde,hasta), alcance de cortes contables autorizados y regla explícita para elegir una versión. |
| Integridad | Algoritmo y versión de canonicalización, SHA256 de contenido, revisión optimista del borrador, vínculo opcional a versión anterior. |
| Ciclo documental | Estado derivado, autores/revisores/aprobadores reales, eventos de revisión/aprobación/publicación/retirada con referencia, motivo y fecha del servidor. |
| Historia | Condiciones de consulta, advertencias de retirada/vencimiento y referencias de ediciones emitidas. |

**P:** preparar y editar borradores con hash esperado. Una modificación invalida cualquier revisión/aprobación que corresponda a otro hash; no cambia la firma documental anterior. El contenido publicado queda congelado. Retirar una versión añade evidencia, no cambia sus asignaciones, hash, vigencia original o etiquetas aprobadas.

**P:** la cabecera puede conservar draft/published como estado material; revisión, aprobación y retirada se representan como eventos y estado derivado de consulta. Así «publicada y retirada» no exige reescribir el contenido que el contador aprobó. Estos son estados documentales futuros, no estados nuevos de ventas o períodos.

Ejemplo **solo ilustrativo** de parte del contenido:

```json
{
  "contract": "financial-presentation-v1",
  "purpose": "simulation",
  "origin": "synthetic_fixture",
  "version_number": 1,
  "installation_scope": "TEST-FIXTURE-ONLY",
  "groups": [
    {"key": "asset", "basis": "debit_minus_credit", "order": 10},
    {"key": "income", "basis": "credit_minus_debit", "order": 40}
  ],
  "assignments": [
    {"account_id": 900001, "group_key": "asset", "order": 10}
  ]
}
```

No es un contrato completo publicable: faltan grupos, perfiles y evidencia. El ID no se resolvió contra MySQL y no es una asignación aprobada. El hash deberá calcularse sobre el contenido completo; no se inventa uno ni se agrega una aprobación ficticia al ejemplo.

## 3. Modelo de grupos y asignaciones

**P:** mantener siete raíces económicas de ensayo como vocabulario candidato, con claves opacas respecto al código de cuenta: activo, pasivo, patrimonio, ingresos, costos, gastos operativos y otros gastos. Los nombres y subdivisiones empresariales deben aprobarse. Una necesidad que exceda ese contrato requiere revisión, no se representa mediante fórmulas libres o una cuenta «otros» automática.

Reglas técnicas propuestas:

1. Una cuenta ID tiene **una contribución económica** en una versión; UNIQUE(version_id,account_id). No se distribuye su saldo por porcentajes ni se multiplica según bindings.
2. Una asignación apunta a un grupo/hoja existente del manifiesto; su raíz determina la base de signo. Subgrupos heredan esa base; no pueden invertirla silenciosamente.
3. Los layouts reutilizan la contribución: resultados muestra raíces nominales; balance muestra activo/pasivo/patrimonio y calcula el resultado pendiente con las raíces nominales y cierres incluidos. No se registra otra asignación a una fila virtual de utilidad.
4. Subtotales padre son visuales. Un detalle contribuye una vez a la hoja y se agrega a sus ancestros para presentar; nunca se suman otra vez subtotales junto con hojas al calcular el total.
5. Los grupos de presentación tienen su propio árbol, acíclico y con orden determinista. No se heredan del padre de CuentaContable. Padre e hijo contables con imputaciones mantienen sus contribuciones independientes.
6. Solo bases D−H y H−D y subtotales predeterminados del contrato. No se permiten PHP, SQL, fórmulas arbitrarias, valor absoluto o coeficientes económicos enviados por el navegador.
7. Cuenta correctora es una anotación revisable, no una orden de multiplicar por−1. Con H−D, una correctora de ingreso deudora10 aporta−10; con D−H, un contraactivo acreedor5 aporta−5.
8. Tipo/naturaleza actuales sirven para detectar incoherencias, no para asignar el grupo. Una discrepancia requiere decisión expresa; nunca se corrige el catálogo histórico para hacerla desaparecer.

**D:** si una misma cuenta contiene importes corrientes y no corrientes, este mínimo por cuenta no puede dividirla de manera inequívoca. El contador deberá aprobar una clasificación agregada válida o definir un desglose documental y otro alcance. No duplicar la cuenta ni repartir importes automáticamente para aparentar resolución.

## 4. Matriz de decisiones del contador

| Área | Verificable técnicamente | Decisión económica pendiente | Evidencia necesaria para aprobar |
| --- | --- | --- | --- |
| Activo | Cuenta existente, detalles exactos, base D−H y cobertura sin duplicación. | Rubro de cada ID, presentación de correctoras y posible corriente/no corriente. | Relación explícita de cuentas y fundamento de su ubicación. |
| Pasivo | H−D firmado, referencias y jerarquía de presentación válidas. | Rubros y vencimiento/plazo; cuentas mixtas. | Mapa por ID y criterio de plazo, sin inferirlo del código. |
| Patrimonio | H−D y resultado pendiente separados del traslado ya registrado. | Capital, retiros, resultados acumulados y rótulo del residual. | Definición de rubros y de comparación antes/después del cierre. No es aprobación de una cuenta de apertura. |
| Ingresos | H−D conserva devoluciones y correctoras al debe. | Clases de ingreso y presentación bruta/neta/subgrupos. | Mapa de ingreso y contraingreso, con ejemplos firmados. |
| Costos | D−H conserva el costo realmente contabilizado. | Qué cuentas corresponden a costo y qué subtotales afectan. | Aprobación costo frente a gasto por ID; conservar promedio móvil/COGS originales. |
| Gastos operativos | D−H, sin cambiar D/H original. | Criterio de operación y frontera con costo/otros. | Definición contable, cuentas y excepciones revisadas. |
| Otros gastos | Ausencia de fallback para cuentas no cubiertas. | Conceptos admitidos y presentación de ganancias/saldos acreedores. | Lista expresa; si hace falta otra raíz económica, ampliar contrato por separado. |
| Correctoras | El saldo opuesto puede mostrarse negativo sin modificarlo. | Identificación intencional de la correctora y etiqueta. | Revisión por cuenta, distinguiendo saldo contrario ocasional de cuenta correctora. |
| Corriente/no corriente | Subgrupos y cobertura completa se validan. | Si se requiere y si basta clasificar cuentas enteras. | Criterio y soporte de vencimientos; caso mixto bloqueado si no puede representarse. |
| Inactivas con saldo | No filtrarlas por actividad para consultar historia. | Ubicación/rótulo y exposición de saldos. | Mapa de cuentas históricas; desactivar no elimina cobertura. |
| Códigos alternativos | JOIN por cuenta_id y mismas referencias originales. | Correspondencia económica explícita. | Aprobación por ID; ni código, nombre ni naturaleza prueban equivalencia. |
| Conflictos tipo/grupo | Reportar incoherencia con la fotografía revisada. | Si se permite una excepción de presentación y quién la aprueba. | Justificación específica, sin reclasificar asientos ni atributos protegidos. Sin decisión, publicación bloqueada. |

No se eligió una correspondencia empresarial para ninguna de estas filas. Los ejemplos sintéticos aprobados como ensayo no sustituyen el mapa del contador.

## 5. Universos documentales separados

Cada perfil versionado debe contener estados, tratamiento de NULL, rango/corte, período, centros, scope, moneda/base y regla de cierres. Una lista ausente no significa «todos», y un dato faltante no equivale a una exclusión aprobada.

| Dimensión | Resultados | Balance | Pendiente/controle propuesto |
| --- | --- | --- | --- |
| Fecha | Rango inclusivo por fecha del asiento. Comparación con legado: mes completo según capacidad actual. | Acumulado hasta corte, sin inicio. | Aprobar calendario, zona contable y relación con período; no deducirlos de la zona del usuario. |
| Período ID | Filtro opcional explícito para un perfil que lo autorice. | No aplicar un período aislado como si fuese acumulado histórico. | Conservar diagnóstico de fecha/período divergentes; no corregir referencias antiguas. |
| Estado de asiento | Lista explícita aprobada. | Lista explícita aprobada, que no tiene por qué ser idéntica. | El legado incluye estados que el ensayo distinguió. No imponer contabilizado como único estado sin revisar productores/reversas. |
| Origen NULL | Inclusión/exclusión expresa y advertida. | Igual exigencia de decisión explícita. | No inventar origen; la comparación actual muestra diferencias. |
| Manual/reversa | Movimientos originales y reversas efectivos por sus fechas, cuentas y referencias. | Ambos afectan sus saldos acumulados. | No eliminar el original por existir una reversa ni identificarla solo por descripción/número. |
| Automáticos/devoluciones | Efectos efectivamente contabilizados, con signo original. | Restituciones y costos retenidos según documentos originales. | Una devolución bancaria no es ingreso por ser entrada; una venta anulada puede conservar CST/CMV. |
| Cierre fiscal | Excluir cierre_fiscal para el desempeño del rango, preservando contrato lector actual. | Incluir su efecto para no duplicar utilidad trasladada. | Sin filtro universal ni modificación del escritor o destino3.2. |
| Período abierto/cerrado | Lectura permitida de ambos; no crear/reabrir períodos. | Igual. | Emisión, reconstrucción y reexpresión requieren identificación distinta. |
| Centros | Todos, sin asignar o lista explícita, incluyendo referencias históricas inactivas. | Si se permite filtrar, declarar que es un subconjunto. | No repartir detalles sin centro ni forzar cuadre de un segmento. |
| Cuenta inactiva | Mantener su historia. | Mantener saldo e historia. | Su estado no es una regla de exclusión económica. |
| Moneda | Debe/haber originales de contabilidad base; conservar moneda/tasa documental. | Igual, sin sumar saldo bancario nativo a importe NIO. | Los lectores actuales no convierten por moneda_id. Verificar la fuente; nunca aplicar tasa actual para reconstruir. |
| Corrección posterior | Se selecciona por fecha contable y visibilidad del snapshot. | Igual a su corte y punto de lectura. | Un asiento confirmado después de emitir no cambia la edición anterior, aunque tenga fecha anterior al corte. |

**P:** expresar la versión completa del perfil y el conjunto seleccionado en toda comparación/emisión. Una selección parcial nunca lleva rótulo global. Mantener explícita la diferencia entre A global heredado y B subset comparable de AC-05B3A.

## 6. Revisión, aprobación y permisos

Flujo propuesto, no implementado:

1. **Borrador:** actor autenticado prepara contenido y procedencia. No hay aprobación por defecto; no se copian autoridades de fixtures.
2. **Cobertura:** obtener evidencia del universo revisado, cuentas relevantes, incoherencias y fuentes. Cualquier cuenta relevante sin clasificación bloquea esa salida completa.
3. **Diferencias:** comparar con legado y, cuando exista, con la versión de presentación anterior; explicar deltas por grupo/cuenta/detalle y cambios de universo por separado.
4. **Revisión identificable:** evento vinculado al hash exacto, diagnóstico de cobertura y diferencias. Una revisión no equivale a aprobación económica.
5. **Aprobación:** responsable designado confirma versión/hash, perfiles, vigencia, mapa y referencias de soporte; el servidor resuelve actor/rol y timestamp. Se conserva la procedencia empresarial verificable.
6. **Publicación:** validar de nuevo contenido, referencias, aprobación vigente y ausencia de solapamiento/ambigüedad; congelar contenido y registrar evento durable en la misma operación documental.
7. **Consulta/retirada:** publicado se conserva. Cambios exigen otra versión; retirada es otro evento, con motivo y efecto prospectivo. No elimina ediciones ni transforma simulation en business.

**P:** reutilizar controles contables alvaro/contador y auditoría por servicio, sin bypass de admin ni cambios de roles. Preparar, aprobar, publicar y emitir son actos diferentes aunque una persona pudiera estar autorizada a varios.

**D:** designar quién tiene competencia económica real para aprobar y si se exige otra persona como revisor/aprobador. No se impone separación obligatoria no aprobada, pero debe registrarse la coincidencia de autor/revisor/aprobador y no fingir independencia. La mera pertenencia al rol contable no acredita una designación empresarial pendiente.

Una aprobación simulation conserva esa procedencia, entorno y marca. No se promueve un registro cambiando purpose, referencia, fecha o JSON. Una versión business necesita creación administrativa nueva y aprobación propia comprobable; sin ese contrato autorizado, permanece cerrada la publicación empresarial.

Auditoría mínima: actor real, rol/contexto usado, versión/hash, evento, referencia, motivo, fecha UTC del servidor y evidencia revisada. No guardar PIN, contraseña, tokens, cookies o hashes de credenciales. Un hash de contenido no es firma del contador ni prueba de aprobación por sí mismo.

## 7. Versiones, canonicalización, vigencia y concurrencia

**P:** canonicalización especificada por versión: UTF-8, claves de objetos ordenadas, listas normalizadas por identificadores estables cuando su orden no tenga significado, orden visual en campos explícitos; IDs enteros y decimales normalizados sin float, NULL distinto de ausencia, fechas contables YYYY-MM-DD y timestamps UTC con Z. Rechazar duplicados antes de ordenar. Cualquier normalización de texto debe estar definida; no aplicar trim/case/Unicode silenciosos a referencias significativas.

El hash de contenido cubre propósito/procedencia, ámbito, versión/contrato, grupos, asignaciones, fotografías revisadas, perfiles y vigencia propuesta. Excluye eventos futuros y campos técnicos como updated_at; cada evento tiene su propio hash y referencia al hash aprobado. No incluir el propio content_hash en su cálculo. SHA256 y canonicalizador deben declararse, no asumirse compatibles con hashes sintéticos anteriores.

Tras publicar, las filas y snapshots del contenido son inmutables. La retirada se vincula al original y no modifica su hash. Una expiración por fecha y una revocación documentada se muestran de forma distinta. Para nuevas selecciones elegibles, aplicar el intervalo UTC [desde,hasta), limitado por retirada efectiva si la hay. No actualizar valid_until para aparentar que siempre tuvo otra vigencia.

**D:** aprobar el eje temporal de selección. Recomendación conservadora: una sola versión fijada por lectura/emisión; selección explícita para cortes históricos y prohibición de aplicar automáticamente la última versión a un corte antiguo. La vigencia para nuevas emisiones y el alcance autorizado de fechas contables son conceptos separados. Reexpresar un comparativo requiere autorización y etiqueta propias.

Dos versiones elegibles para la misma familia/instalación/perfil no tienen precedencia implícita: bloquear publicación/selección ambigua. No se elige primera, más reciente o global/específica sin una regla aprobada. Los límites finitos o una vigencia abierta también deben ser explícitos; no introducir un «para siempre» por ausencia de dato.

Protocolo administrativo futuro propuesto: mutex AC-02 → recurso/familia de presentación → versión(es) en orden estable → cuentas requeridas por ID → evidencia/auditoría. Revalidar snapshots bajo ese protocolo. No bloquear banco, artículo o documento financiero ni añadir GET_LOCK a cada reporte/venta.

La publicación/retirada deben serializarse sobre la misma fila de versión/familia. Los lectores usan contenido publicado inmutable en snapshot. Una futura emisión requiere además una comprobación final actual de elegibilidad frente a una retirada concurrente: no basta una lectura RR antigua. Esa finalización documental debe diseñarse aparte de la transacción SQL READ ONLY del extractor; no convertirla en una escritura financiera ni relajar su frontera.

## 8. Preservación histórica y cobertura

**P:** conservar IDs y D/H originales; no backfill de versiones en asientos ni asignación de una política de presentación a un documento que nunca la utilizó. Una versión consulta historia, no cambia qué cuenta recibió el asiento.

Consulta histórica debe distinguir:

- **Lectura/reconstrucción:** versión explícita, datos visibles ahora para el corte, perfiles y advertencias; no garantiza igualdad con lo que se vio entonces.
- **Edición emitida:** recuperación del manifiesto/archivo inmutable, sin ejecutar otra vez la política vigente.
- **Reexpresión:** edición nueva, identificada y autorizada, vinculada a la anterior, con diferencias explicadas; nunca sobreescribirla.

Retirada, vencimiento o cuenta inactiva no hacen desaparecer la evidencia de una edición. Una versión retirada puede consultarse analíticamente con advertencia; no queda habilitada para nuevas emisiones formales por esa consulta. Autorizar una excepción de emisión sería otro acto empresarial, no un fallback técnico.

La cobertura se define para perfiles/horizonte revisados e incluye cuentas con detalles relevantes aunque el neto sea cero, inactivas con historia y códigos alternativos. Deben reconciliarse las contrapartidas usadas para trazabilidad, no solo las filas nominales visibles. Una cuenta agrupadora sin movimientos no añade contribución; una agrupadora con historia no se excluye silenciosamente.

El informe de cobertura se fija con hash y fecha, pero no prueba cobertura perpetua. Una cuenta nueva o antes dormida puede recibir movimientos después. Cada lectura/emisión debe volver a comprobar cobertura del conjunto seleccionado y rechazar incompletitud, sin cambiar el catálogo ni inventar un grupo.

**P/D:** para etiquetas de una edición, preferir la fotografía aprobada y conservar también la etiqueta actual como metadato comparativo si se muestra. Si se elige usar etiquetas del catálogo al emitir, aprobar ese modo y archivarlas exactamente como se utilizaron. El nombre actual no reconstruye el nombre histórico; el modo de etiqueta forma parte del contrato.

## 9. Edición de estado financiero emitido

Una edición futura debe ser autocontenida y verificable. Contenido mínimo:

| Bloque | Evidencia a conservar |
| --- | --- |
| Identidad | UUID de edición, tipo de estado, purpose/origen/instalación, referencia de reemplazo si existe. |
| Clasificación | ID/versión/contrato y hash exactos; aprobación/publicación aplicables, no solo su estado actual. |
| Universo | Corte/rango, período explícito o ausencia, perfiles de estados/NULL/cierres, centros y scope global/parcial, moneda/base y zona contable declarada. |
| Fuente | IDs y copias o evidencia verificable de detalle/asiento/cuenta/moneda/centro; D/H originales, fechas, origen, estado y referencias. Hash del conjunto ordenado por detalle ID. |
| Presentación | Grupos, asignaciones y etiquetas efectivamente utilizadas, contribuciones firmadas, totales/subtotales, residual y versión de formato/lector. |
| Emisión | Actor autenticado, fecha UTC del servidor, fecha de reconocimiento declarada y resultado de cobertura/cuadre del universo. |
| Integridad | Manifiesto canónico, hash del payload, del conjunto fuente y del archivo renderizado; ubicación privada y tamaño. |

Conservar solo IDs y volver a consultar después no basta: podrían cambiar etiquetas o aparecer documentos retroactivos. Un agregado o checksum sin poder reconstruir sus importes tampoco equivale a evidencia completa. Propuesta mínima: manifiesto autocontenido con originales necesarios y archivo de representación, sin duplicar un segundo libro contable operativo.

El archivo debe quedar privado, inmutable y accesible a roles autorizados; conservar respaldo y verificar su hash al recuperar. No incluir estos artefactos financieros en Git ni en una URL pública. Retención, responsables y necesidad de firma externa requieren decisión del propietario/contador; no se fijan plazos normativos aquí.

Lectura y emisión son etapas separadas: materializar snapshot READ ONLY, validar payload completo, preparar archivo privado único y finalizar un documento de emisión con comprobación actual de versión/aprobación/retirada. No escribir una edición desde la transacción READ ONLY. Si el proceso falla, no presentar una edición parcial como emitida; conservar/diagnosticar archivos huérfanos sin borrar evidencia anterior. Idempotencia documental por UUID+hash, sin generar un UUID nuevo ante una respuesta incierta.

**H:** los lectores actuales devuelven issued_statement=false; no se localizó un archivo general de ediciones financieras emitidas. Las capturas del piloto y PDFs operativos no prueban ese archivo. No atribuirles aprobaciones o ediciones retroactivamente.

## 10. Esquema futuro mínimo, solo propuesto

Los nombres son de diseño. No se crearon archivos de migración, modelos, tablas o datos.

### 10.1 financial_presentation_versions

- **Propósito:** contenido revisable y versión publicada de presentación, separada de accounting_policy_versions.
- **Columnas:** id, family_uuid, version_number, contract_version, installation_scope, purpose, origin_kind/origin_evidence, label/reason, created_by, prepared_at_utc, draft_revision, document_state draft/published, valid_from/until_utc, reporting_scope_manifest, group_layout_manifest, read_profiles, content_hash/canonicalizer_version, optional predecessor_version_id y published_at.
- **FK:** created_by → users; predecessor → esta tabla. RESTRICT en borrado/actualización de referencias. No FK a una tabla empresarial/instalación inexistente: la fuente confiable de installation_scope debe definirse antes de implementar.
- **Unicidad:** family_uuid+version_number; identidad estable de familia por instalación/purpose. CHECK de enums/formatos/orden temporal cuando sea viable. La exclusión de vigencias requiere servicio serializado, no afirmar que un UNIQUE común evita todo solapamiento.
- **Inmutabilidad/retirada:** contenido publicado congelado; retirada por evento. No editar propósito/origen para promover fixtures. Aprobador/revisor pueden derivarse de eventos, evitando campos duplicados que discrepen.
- **Dependencias/impacto:** patrones AC-03, integridad AC-02 y lectores AC-05; sin ALTER/backfill sobre asientos ni activación automática.

### 10.2 financial_presentation_assignments

- **Propósito:** una asignación económica explícita por cuenta ID, con orden y fotografía de revisión.
- **Columnas:** id, version_id, account_id, group_key, order_index, optional approved_label, corrector_annotation, account_review_snapshot, review_reason/exception_reference y timestamps de preparación.
- **FK:** version_id → versiones; account_id → cuentas_contables; RESTRICT. UNIQUE(version_id,account_id); índice de orden, sin exigir que todos los renglones tengan un número de orden global distinto si el manifiesto lo desambigua por clave.
- **Grupo/subgrupo:** definidos en JSON canónico de la versión para mantener un esquema pequeño. **No hay FK SQL hacia una clave JSON**; el servicio valida pertenencia, árbol y bases antes de publicar y al verificar integridad. Si se exige FK relacional de cada nodo, proponer una tabla adicional de grupos con clave compuesta en otra autorización, no fingir que el JSON ya la ofrece.
- **Inmutabilidad:** no editar/borrar filas de versión publicada; borradores cambian con revisión/hash y auditoría. No cascadas destructivas de contenido publicado.
- **Compatibilidad histórica:** se puede clasificar una cuenta inactiva con historia; no exigir que sea utilizable para una imputación nueva. No modificar su código/tipo/naturaleza ni activar una cuenta para poder mostrarla.

### 10.3 financial_presentation_audits

- **Propósito:** preparación, revisión, aprobación, publicación y retirada durables, incluidos sus soportes.
- **Columnas:** id, version_id, event_sequence, event_type, actor_id, recorded_at_utc, effective_at_utc cuando corresponda, reason/reference, subject_content_hash, before_hash/after_hash, evidence_version, evidence, evidence_hash y optional referenced_event_id.
- **FK:** versión, usuario y evento original; RESTRICT. UNIQUE(version_id,event_sequence), y clave compuesta version_id+id para vincular aprobaciones sin cruce de versiones. Vincular la aprobación/retirada al hash y acto originales mediante validación y referencias coherentes.
- **Inmutabilidad:** append-only; revocación no borra o modifica aprobación. Los intentos fallidos de seguridad no sustituyen la evidencia transaccional del acto confirmado. No almacenar credenciales.
- **Dependencias:** roles/identidades actuales y patrón AC-03; no mezclar sus auditorías con una versión sintética bancaria o reutilizar auditoria_asientos para un documento sin asiento.

### 10.4 financial_statement_editions + manifiesto/archivo privado

- **Propósito:** índice durable de emisiones efectivamente finalizadas y sus artefactos; previews no se registran como emitidas.
- **Columnas:** id, edition_uuid, version_id, classification_hash, approval_event_id, report_kind, parameters/universe_manifest, source_manifest_hash, totals_manifest, format_version, payload_hash, artifact_path/hash/bytes, issued_by, issued_at_utc y optional replaces_edition_id.
- **FK:** versión, aprobación de la misma versión mediante clave compuesta, usuario y edición anterior; RESTRICT. UNIQUE(edition_uuid), comprobación de UUID+payload ante reintentos; no deduplicar ediciones solo por corte si podrían existir reexpresiones autorizadas.
- **Inmutabilidad/retirada:** no sobrescribir un emitido; corrección/reemplazo genera otra edición con vínculo. Retirar la clasificación no borra el archivo ni cambia el original.
- **Fuente:** los IDs/campos fuente se conservan en manifiesto autocontenido. No existe FK SQL hacia IDs dentro de un archivo/JSON. Reutilizar las prohibiciones de borrado financiero y verificar fuente al emitir; si se exige integridad referencial por cada detalle en SQL, hará falta una estructura edition_sources adicional, a justificar y autorizar por separado.
- **Impacto:** no cambia asientos ni lectores operativos. Manifiesto/archivo no son filas de un nuevo mayor ni se usan para recalcular un asiento.

### Dependencia específica AC-02

**H:** AccountIntegrityService::dependencies todavía no conoce estas estructuras. **P:** en una implementación futura sus referencias deben impedir borrado/cambio estructural destructivo, pero tratarse como dependencias históricas de lectura, no como asociaciones que exijan cuenta activa. Agregar el nombre de la tabla sin más al tratamiento actual podría bloquear desactivaciones que sí deben seguir permitidas. Definir esa distinción mínima y probarla antes de integrar; no se modifica el servicio ahora ni se debilitan bancos/gastos/nómina que requieren actividad.

Las FK RESTRICT preservan relaciones; no prueban por sí solas aprobación, hash o inmutabilidad frente a un administrador SQL. La garantía de aplicación requiere servicio, controles de acceso, auditoría y verificación de integridad. No se propone introducir triggers o permisos nuevos en esta fase.

## 11. Integridad, diferencias inexplicables e integración futura

Controles de publicación propuestos:

1. Validar estructura/contrato, claves únicas, árboles y orden, bases de signo y ausencia de fórmula ejecutable.
2. Verificar referencias reales y fotografías revisadas; señalar conflictos económicos sin resolverlos desde metadatos.
3. Extraer en snapshot los universos acordados, comparar IDs de detalles y centavos, incluir inactivas y detectar cobertura incompleta aunque el neto se compense.
4. Explicar por separado cambios de grupo y de universo. Un total igual no descarta error: una reclasificación puede ocultar un activo mediante resultado residual y aun cuadrar.
5. Bloquear publicación con diferencias de pertenencia sin motivo verificable o detalles presentes en ambas lecturas con campos originales distintos. Registrar el diagnóstico y su hash; un motivo libre no convierte un cambio inexplicable en correcto.
6. Revisar cambios frente a versión anterior y legado. Una diferencia económica legítima exige fundamento y aprobación; no se corrigen datos ni expectativas para obtener igualdad artificial.
7. Verificar origen de moneda/base y alcance global/parcial, confirmar aprobación exacta y revalidar bajo el protocolo administrativo antes de publicar.

Integración mínima futura, sujeta a autorización:

| Componente | Adaptación posterior propuesta | Límite |
| --- | --- | --- |
| AccountingReportExtractor | Recibir contenido aprobado y perfil fijado por servidor; conservar snapshot propietario y límites declarados. | No crear períodos, asientos ni adquirir locks financieros. No aceptar un ID del navegador como autoridad. |
| SyntheticAccountingPresentation | Mantener el prototipo sintético aislado; extraer/reutilizar la agregación pura mediante un contrato autorizado cuando proceda. | No cambiar purpose a business ni quitar su guardia para aparentar activación. Validar equivalencia antes de compartir implementación. |
| AccountingPresentationComparison | Comparar legado, versión anterior y candidata por cuenta/detalle/centavos y fuente. | No usar bindings como categorías ni mezclar versiones en un estado. |
| Resultados / balance | Lectura paralela primero; sustituir controlador/vista solo con autorización expresa y equivalencia revisada. | Conservar perfiles distintos de cierre y resultado residual. |
| Dashboard | Consumir la misma clasificación/perfil aprobado para KPIs compatibles. | Métodos de cobro y rankings son contratos distintos; no absorberlos en grupos económicos genéricos. |
| Cierre fiscal | Ninguna adaptación en este plan. | Su selección4–7 y destino3.2 permanecen independientes; una presentación configurable no hace configurable el escritor. |

Riesgos de migración: clasificar historia con una versión posterior sin indicarlo; promover fixtures; exigir actividad a cuentas históricas; deduplicar por nombre; joins 1:N a bindings/grupos; doble suma de padres; mezcla de moneda nativa/NIO; emitir desde una lectura parcial; sobrescribir artefactos; resolver con un snapshot antiguo una retirada concurrente. Todos requieren pruebas/controles explícitos; no un backfill automático.

## 12. Plan de pruebas para una fase futura autorizada

Ninguna prueba se ejecutó en AC-05B3B0. Preparar fixtures nuevos y transaccionales compatibles con la evidencia existente; no reutilizar carreras persistentes ni activar una política de prueba.

| Área | Criterio verificable |
| --- | --- |
| Contrato/hash | Mismo contenido y orden equivalente → mismo hash; cambio significativo → otro; NULL/ausente, referencias y normalizaciones no ambiguos. |
| Procedencia | Synthetic nunca se promueve por campos/IDs; creación administrativa y aprobación reales son actos diferentes. |
| Permisos | Autor/revisor/aprobador resueltos desde sesión/servicio; sin admin bypass; autoaprobación según decisión empresarial, nunca oculta. |
| Cobertura | Cuenta relevante nueva/inactiva/alternativa o neto cero con detalles no nulos exige cobertura; incompletitud bloquea una salida completa. |
| Correctoras | Ingreso deudor10→−10; contraactivo acreedor5→−5; gasto acreedor conserva signo; sin abs/renumeración. |
| Costo/gasto/otros | Mapa aprobado explica cambios de margen bruto/operativo; diferencia no se oculta por totales netos iguales. |
| Jerarquías/bindings | Padre10/hijo20 suma30, no50; cuenta con dos bindings y detalle50 aporta50; ciclos y asignación duplicada rechazados. |
| Universos | Fecha frente a período, NULL, borrador/anulado, centros e inactivas: pertenencia exacta por ID, no solo totales. |
| Cierres | Fixture ingreso100/gasto20: resultado80 antes/después; balance posterior retiene80 y residual0. Sin cambiar cierreFiscal ni historia. |
| Reversas/devoluciones | Cuentas/importes históricos y costos retenidos preservados, no reconstruidos con otra política. |
| Vigencia | [desde,hasta), conflicto de versiones y retirada; consulta histórica permitida con advertencia, emisión nueva bloqueada cuando corresponda. |
| Concurrencia | Dos publicaciones, cambio de cuenta durante revisión, retirada frente a emisión; versión/hash fijados y comprobación final actual. No confundir propuesta documental con evidencia de carreras. |
| Edición | Manifest/archivo coherentes, fuente recuperable y hash verificado; cuenta renombrada o corrección retroactiva posterior no cambia el emitido. |
| Fallos/reintentos | Error de auditoría, archivo incompleto o UUID con otro contenido no produce una edición final parcial; mismo UUID/hash devuelve la edición autorizada original. |
| Regresiones | AC-02/03 y AC-05 lectores conservados, reporte paralelo/visible y demo aislados; ausencia de cambios en inventario/nómina/bancos/cierre. |
| Rendimiento/seguridad | Umbrales sin recorte silencioso, artefactos privados, acceso por actor/contexto y ausencia de secretos en auditoría. |

Una prueba de aislamiento ante escritor que hace rollback no demuestra visibilidad ante commit concurrente: AC-05B2 documentó esa limitación. Si se necesita ese ensayo, autorizar primero fixtures/evidencia y preservación, sin borrar documentos antiguos para repetirlo.

## 13. Auditoría Git y plan de commits revisables

### 13.1 Estado observado

**H:** HEAD `9f34037befe0e7bb9730955b3c1b547ad032fb29` (`AC 4BAC, y A5`). No había cambios en el índice (`git diff --cached --name-only` vacío). Antes de este documento: cuatro archivos rastreados modificados y23 archivos no rastreados de AC-05. El nuevo documento se añade a ese inventario, sin staging.

Rastreados modificados:

- `app/Http/Controllers/PeriodoContableController.php`: AC-05C0, algoritmo de signos/centavos y Throwable.
- `tests/Support/ac04b1-backup.php`: AC-05B1, fase/respaldo nuevo.
- `tests/Support/ac04b1-isolation.php`: fases B1/C0/B2/B3A acumuladas en una misma línea.
- `tests/Support/run-ac04b1-tests.php`: grupos, conteos/preflight y respaldo compartidos de esas fases.

Archivos nuevos por procedencia y solapamiento:

| Fase | Archivos no rastreados observados |
| --- | --- |
| AC-05B1 | SyntheticAccountingPresentation.php, AccountingPresentationTest.php, AccountingReportCharacterizationTest.php y docs/AC_05B1_CARACTERIZACION_CLASIFICACION.md. El servicio ya contiene metadatos añadidos por B3A y la caracterización contiene la expectativa corregida por C0. |
| AC-05C0 | FiscalClosingSignsTest.php y docs/AC_05C0_CIERRE_SIGNOS.md; también modifica el controlador rastreado y el caso previo de caracterización. |
| AC-05B2 | AccountingReportExtractor.php, AccountingPresentationComparison.php, AccountingReportExtractionTest.php, AccountingReportSnapshotTest.php, ac05b2-snapshot-writer.php y docs/AC_05B2_EXTRACCION_COMPARACION.md. Los dos servicios incluyen después límite/metadatos B3A. |
| AC-05B3A | ReportPresentationDemoController.php, ReportPresentationDemo.php, ReportDemoBoundary.php, report-demo.blade.php, ReportPresentationDemoTest.php, ReportPresentationHttpTest.php, ReportDemoHttpClient.php, ReportPresentationFixtures.php, ac05b3a-browser.mjs, report-demo-router.php y docs/AC_05B3A_DEMO_REPORTES.md. |
| AC-05B3B0 | Este documento solamente. |

Las rutas se resuelven bajo app/Http/Controllers, app/Services, app/Support, resources/views/accounting, tests/Feature y tests/Support según su nombre. No atribuir todo un archivo no rastreado a su primera fase: los cambios aditivos posteriores deben separarse.

`git diff --stat` inicial solo cuenta los cuatro rastreados:76 adiciones/23 eliminaciones. **No representa el alcance total** porque omite los nuevos. Se observaron advertencias CRLF/LF en auxiliares; no normalizar finales de línea de todo el repositorio para simplificar revisión.

### 13.2 Migraciones de fases anteriores

Hay82 archivos de migración en el árbol, todos rastreados; ningún cambio pendiente bajo database/migrations. Esto verifica archivos Git, **no** migraciones aplicadas en SQL. Las ocho incorporaciones posteriores a las74 de desarrollo, ya rastreadas, son:

| Archivo | Origen |
| --- | --- |
| 2026_11_14_000001_add_fractional_inventory_representation.php | H03-B1 |
| 2026_11_14_000002_create_stock_ledger_lot_allocations.php | H03-B1 |
| 2026_11_14_000003_add_consumption_composition_evidence.php | H03-B2 |
| 2026_11_14_000004_add_sale_annulment_evidence.php | H04-B |
| 2026_11_14_000005_create_inventory_opening_documents.php | H01-B2A |
| 2026_11_16_000001_create_versioned_accounting_policies.php | AC-03A |
| 2026_11_16_000002_add_accounting_event_documentation.php | AC-04B1 |
| 2026_11_16_000003_create_bank_charge_refunds.php | AC-04B2B3A |

No se reescriben, vuelven a agregar como nuevas o despliegan por cerrar commits de reportes. Cualquier instalación ordinaria con74 requiere su plan de despliegue independiente, todavía no autorizado.

### 13.3 Privados/generados

`git check-ignore` confirmó exclusión de .env.testing, public/build/manifest.json y result.json de capturas. Se observaron también baselines/manifiestos de respaldo, logs HTTP, sesiones/cache, perfiles/capturas de navegador, vistas compiladas y logs de Laravel en storage, ignorados. Conservarlos como evidencia privada; no usar add forzado ni copiarlos a docs sin revisión de datos.

No deben formar parte de commits: secretos/APP_KEY/contraseñas, cookies/sesiones, datos financieros extraídos, dumps y sidecars externos de `E:/salon-erp-backups`, perfiles de Chrome, caches, node_modules/vendor o archivos compilados. Los documentos Markdown de resultados sí son entregables revisables; sus cifras deben identificarse como evidencia de la fase que los produjo, no de esta inspección.

### 13.4 Plan propuesto, no ejecutado

| Orden | Commit conceptual | Separación necesaria y criterio de revisión |
| --- | --- | --- |
| 1 | AC-05B1: lector sintético y caracterización | Incorporar versión del clasificador previa al mapa por detalle B3A, pruebas iniciales y caracterización negativa original; helpers reports-* y respaldo B1. Conservar el hecho reproducido y el informe original. |
| 2 | AC-05C0: cierre con lados no negativos | Controlador, prueba específica, adaptación explícita del caso de caracterización y helpers closing-*/fase C0. Revisar efecto neto y rollback sin mezclar UI o extracción. |
| 3 | AC-05B2: extracción/snapshot/comparador | Servicios antes del límite/metadatos B3A, pruebas de extracción/snapshot, hijo rollback-only, helpers extract-*/fase B2 e informe. No incorporar una clasificación empresarial. |
| 4 | AC-05B3A: demo local de lectura | Interfaz/frontera/router/fixtures/tests/browser, límite del extractor y metadatos por detalle añadidos al clasificador/comparador; helpers report-demo-*/fase B3A. Revisar que los reportes ordinarios y escritores no cambien. |
| 5 | AC-05B3B0: diseño de gobierno | Este documento, sin implementación ni nueva migración. |

Este orden sigue dependencias técnicas; no ordena desplegar. Preparar parches de contenido por fase y revisarlos, en vez de seleccionar archivos completos o todo un hunk mezclado. En archivos nuevos, reconstruir primero la versión previa con los antecedentes y aplicar después el delta autorizado; no inventar una atribución basándose solo en el nombre del commit o en su fecha.

Antes de ejecutar ese plan, verificar las instantáneas históricas disponibles y que cada estado intermedio sea revisable/consistente. Si no se puede reconstruir inequívocamente un delta, detener su preparación y documentar la procedencia, sin borrar evidencia o mezclar fases. La futura validación de estados intermedios tendrá que ser autorizada y segura para test persistente; no ejecutar automáticamente carreras antiguas para «validar un commit».

No se ejecutaron git add, commit, reset, clean, stash o checkout. No se alteró el índice ni se prepararon parches que sobrescriban archivos. El plan necesita una instrucción posterior para cualquier acción de Git.

## 14. Decisiones previas a AC-05B3B1 y verificación de alcance

Autorizaciones/datos humanos indispensables antes de implementar:

1. Aprobar el contrato de presentación y grupos/subgrupos, signos y subtotales; entregar mapa por cuenta ID, criterios costo/gasto/otros y tratamiento de conflictos con tipo/naturaleza.
2. Aprobar universos de estados, NULL, fechas/períodos, centros, moneda/base y corte; conservar resultados y balance separados y decidir corriente/no corriente cuando se requiera.
3. Designar responsable económico y actores de preparación/revisión/aprobación/publicación/emisión; decidir segregación o declarar autoaprobación permitida, sin autoridad por admin genérico.
4. Aprobar procedencia empresarial, instalación y pruebas de autorización documental; no promover políticas/fixtures sintéticos.
5. Elegir vigencia, selección para cortes históricos, retirada y reglas de consulta/reexpresión/emisión nueva. Ninguna versión «actual» se aplica retrospectivamente por defecto.
6. Aprobar formato de edición, modo de etiquetas, evidencia fuente, privacidad, retención y necesidad de FK por detalle frente a manifiesto autocontenido.
7. Autorizar por separado esquema aditivo y futura integración AC-02 de dependencias históricas, con pruebas y preflight. Este documento no autoriza crear las cuatro tablas propuestas.
8. Acordar si AC-05B3B1 será aún contrato/validación sintética o incluirá persistencia de borradores, y qué queda cerrado. Publicación empresarial, emisión formal y sustitución de reportes requieren autorización explícita distinta si no están incluidas.

**Comprobación de esta fase:** solo se leyeron fuentes, documentación y Git y se creó este Markdown. No se ejecutaron PHP, SQL, Artisan, PHPUnit, navegador, servidores o migraciones; no se creó respaldo ni se consultaron las tres bases. Su integridad SQL no se vuelve a certificar aquí. La última evidencia74/82/74 y preservación de filas pertenece a AC-05B3A.

Se leyó únicamente la línea reset0 de .env.testing; no se modificó. La huella agregada inicial y final de556 archivos previos de aplicación, vistas, rutas, pruebas, configuración, migraciones, documentos y manifiestos, más hashes de entornos/AGENTS.md sin mostrar secretos, coincide: `5ad049411bc013f6959fca549f00335a3f0779e7c1cecba7e62545848920649c`. La comparación excluyó únicamente este documento nuevo. El índice siguió vacío; diff check no informó errores de espacios, solo avisos CRLF/LF de los tres auxiliares ya modificados.

Único diff propio: adición de `docs/AC_05B3B0_CONTRATO_CLASIFICACION.md`. Reversión de esta fase: retirar solo ese documento si se descarta el diseño, sin tocar los cambios previos o bases. AC-05B3B0 concluye con contrato y decisiones propuestas; no inicia AC-05B3B1, DDL, sustitución de reportes, AC-06, H01-B2B o habilitación empresarial.
