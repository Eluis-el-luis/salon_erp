# AC-05B4B — Paquete de revisión contable del salón

**Documento de trabajo para el contador. Versión de revisión 1. Sin aprobación económica ni autorización de implantación.**

## 1. Propósito y forma de revisión

Salon ERP integra servicios y productos, inventario, compras, cobros, bancos, nómina y contabilidad. Sus documentos conservan cuentas e importes históricos. La clasificación que aquí se prepara determinaría cómo presentar esos movimientos en resultados y balance; no cambiaría qué cuentas recibieron los asientos.

Se solicita al contador confirmar el catálogo de referencia y las reglas de presentación necesarias para el salón. Las sugerencias son un punto de partida documentado, **no una aprobación ni una importación**. Las cuentas de pruebas no se convierten en cuentas empresariales por aparecer en la lista.

Material de revisión:

- [Matriz completa de 68 cuentas](AC_05B4B_MATRIZ_REVISION.csv): catálogo de referencia, procedencia, dependencias, sugerencias y decisiones por cuenta. Puede abrirse en Excel; los datos originales de AC-05B4A permanecen intactos.
- [Anexo técnico de fuentes y límites](AC_05B4B_ANEXO_TECNICO.md): trazabilidad, diferencias de contratos y comprobaciones documentales.
- [Inventario técnico anterior](AC_05B4A_INVENTARIO_CATALOGO.md): actividad y agregados de prueba, sin atribuirlos a operaciones reales.

Para responder, conservar esta matriz como referencia y completar una copia con la decisión por cuenta, motivo, soporte y observaciones. Devolver también las decisiones generales de la sección 6. La copia revisada no modifica el ERP automáticamente. Una aprobación futura deberá identificar expresamente el contenido revisado, su alcance y su responsable.

Al importar el CSV en Excel, elegir UTF-8 y tratar las columnas de código y código padre como **texto**, para conservar puntos y caracteres sin convertirlos en fechas o números. Los identificadores de test sirven solo para localizar evidencia; no son identificadores empresariales definitivos.

## 2. Separar cuatro decisiones

| Elemento | Qué determina | Situación |
| --- | --- | --- |
| Catálogo | Identidad, jerarquía, tipo, naturaleza y posibilidad de imputar a una cuenta | Existe un catálogo de desarrollo; falta ratificar el referente empresarial. |
| Política de contabilización | Cuenta utilizada al registrar una operación | Mantiene sus controles actuales; no se cambia con esta revisión. |
| Clasificación de presentación | Rubro, subgrupo, signo y subtotal de resultados/balance | Se prepara para revisión; no hay asignaciones empresariales aprobadas por este paquete. |
| Estado financiero emitido | Documento presentado con corte, cifras, reglas y responsable determinados | No se emite ni se archiva una edición formal en esta fase. |

Cambiar la presentación futura no autoriza renumerar cuentas, trasladar saldos, corregir asientos, modificar cierres o reconstruir estados emitidos anteriormente.

## 3. Procedencia del catálogo de referencia

El último inventario aprobado es de la base **de pruebas**, no de libros empresariales certificados. Contiene 68 cuentas. La revisión estática permite separar:

| Categoría | Cuentas | Fundamento y alcance |
| --- | ---: | --- |
| A. Estructura base del sistema | 50 | Definiciones del catálogo base cotejadas con código, nombre, tipo y naturaleza del inventario. Son candidatas de referencia, no cuentas empresariales ya ratificadas. |
| B. Complementarias de módulos | 13 | Definiciones documentadas para moneda, custodia, nómina, inventario y bancos. También requieren confirmar su necesidad y presentación. |
| C. Ensayo técnico explícito | 5 | Rotulación de ensayo corroborada por sus definiciones de prueba y antecedentes. Se conservan fuera de la propuesta económica empresarial. |
| D. Sin definición de procedencia identificable | 0 en esta comparación | No quedaron cuentas sin una definición o antecedente cotejable. Esto **no prueba el acto individual de creación de cada fila**. |

La fecha de creación, el número de cuenta o su etiqueta por sí solos no prueban procedencia. Que el sistema proteja una cuenta tampoco equivale a aprobación del contador. La matriz distingue una definición conocida de una creación individual no reconstruida.

### Catálogo base y complementos revisables

La matriz incluye el detalle de cada cuenta y su jerarquía. Esta vista resume las finalidades documentadas, no una selección empresarial definitiva:

| Familia de referencia | Cuentas o familias incluidas | Finalidad documentada y revisión necesaria |
| --- | --- | --- |
| Fondos y derechos | Caja, bancos, cuentas por cobrar, adelantos | Separar recursos y monedas; confirmar cuentas efectivamente necesarias. |
| Inventarios | Productos e insumos | Distinguir bienes para reventa de consumo interno; conservar valoración y documentos originales. |
| Bienes de uso | Mobiliario, equipo de belleza y cómputo | Confirmar composición, presentación y correcciones necesarias; este paquete no propone depreciaciones nuevas. |
| Obligaciones | Proveedores, préstamos, impuestos, salarios, retenciones y propinas | Confirmar obligaciones y vencimientos; evitar duplicar obligaciones con sus pagos. |
| Patrimonio | Capital, utilidades retenidas y retiros | Separar aportes, resultados y disminuciones patrimoniales de gastos del salón. |
| Ingresos | Servicios, productos, descuentos y rendimientos | Confirmar desgloses de actividad y tratamiento de descuentos. |
| Costos | Productos, insumos, comisiones y pago a modelos | Confirmar relación con el servicio/producto y margen que se desea presentar. |
| Gastos | Local, servicios básicos, promoción, mantenimiento, limpieza, licencias, salarios, mermas y cargos bancarios | Confirmar frontera operativa/financiera/otros y evitar agrupaciones genéricas sin soporte. |
| Complementos especiales | Fondos en distintas monedas, propinas en custodia, retenciones y diferencias cambiarias | Confirmar presentación sin cambiar el funcionamiento de bancos, cambio o nómina. |

La cuenta histórica `7.2` permanece inactiva/no imputable; el sistema documenta retiros en patrimonio mediante `3.3`. No se propone reactivar la primera ni tratar un retiro como gasto. La presentación de ambas debe quedar documentada en la revisión.

### Cuentas y movimientos de ensayo

Se identifican como C: `6.98425030`, `6.HTTPb908a3cf`, `1.1.2.HTTPb908a3cf`, `6.HTTPde12dcea` y `1.1.2.HTTPde12dcea`. No se borran ni se modifican. Su finalidad es técnica y no justifica asignaciones empresariales.

Los movimientos de prueba también utilizan cuentas del catálogo base. Por ello **no basta excluir esas cinco cuentas para obtener libros empresariales**: un asiento puede compartir una cuenta de ensayo y una base. La preparación empresarial necesitará definir el origen y alcance documental legítimo de la instalación; este trabajo no separa ni convierte movimientos de prueba en operaciones reales.

## 4. Referencia contable original disponible

Está disponible el archivo original **CONTABILIDAD ALVARO RUGAMA.xlsx**, con hojas «catalogo de cuenta» y «Libro Diario». Se verificó que es el mismo documento identificado en el diagnóstico inicial. Se leyó como referencia, sin ejecutarlo, recalcularlo ni importarlo.

La hoja del catálogo explica activos, pasivos, patrimonio, ingresos, costos y gastos operativos; respalda esas familias como **candidatas para discusión**. No incorpora todos los complementos del ERP. Incluye anotaciones y una descripción final incompleta.

El diario de ejemplo no es una correspondencia fiable para importar: el diagnóstico previo documentó códigos con significados distintos entre hojas, referencias rotas y problemas de precisión. Se solicita al contador una versión tabular confirmada del catálogo y aclaración de esas divergencias. Una coincidencia de nombre o número no se trata como equivalencia aprobada.

## 5. Grupos y signos candidatos

Estas sugerencias se apoyan en las definiciones del catálogo, la referencia original y funciones documentadas de los módulos. **Todas están sujetas a confirmación económica.**

| Grupo candidato | Base propuesta | Qué debe confirmar el contador |
| --- | --- | --- |
| Activo | DEBE menos HABER | Alcance, cuentas correctoras y separación corriente/no corriente. |
| Pasivo | HABER menos DEBE | Obligaciones, vencimientos y compensaciones permitidas. |
| Patrimonio | HABER menos DEBE | Aportes, retiros, resultados acumulados y resultado pendiente. |
| Ingresos | HABER menos DEBE | Actividades, descuentos, devoluciones y rendimientos. |
| Costos | DEBE menos HABER | Costo de productos/servicios y tratamiento de comisiones/modelos. |
| Gastos operativos | DEBE menos HABER | Alcance administrativo y operativo, y subtotales útiles. |
| Otros gastos | DEBE menos HABER | Hechos admitidos, gastos financieros u otras separaciones necesarias. |

El signo de presentación se define por el grupo aprobado. No se invierte automáticamente porque una cuenta tenga naturaleza contraria o saldo negativo.

**Correctoras:** el descuento `4.5` está documentado en el sistema como reducción de ingreso; se sugiere conservarlo con contribución negativa en ingresos, sujeto a confirmación de presentación. Los retiros `3.3` están documentados como disminución patrimonial. No se propone volverlos gastos ni sumar sus importes como positivos. Un saldo contrario en otra cuenta no basta para declararla correctora.

La matriz deja PENDIENTE el grupo cuando falta aclarar el hecho económico, especialmente comisiones, pago a modelos, cargos bancarios, reembolsos y otros gastos genéricos. No transforma la agrupación heredada por prefijos en una aprobación.

## 6. Decisiones documentales por aprobar

| Tema | Propuesta para revisar | Decisión requerida |
| --- | --- | --- |
| Resultados | Medir movimientos del rango elegido y excluir el asiento de cierre de su desempeño | Confirmar fechas, estados y documentos participantes; aprobar ingresos netos, costos y subtotales. |
| Balance | Acumular hasta el corte e incluir los efectos de cierres | Confirmar alcance acumulado, patrimonio y tratamiento de correcciones posteriores. |
| Resultado pendiente | Mostrar solo el resultado aún no trasladado a patrimonio | Definir rótulo/ubicación y evitar sumar nuevamente utilidad ya trasladada. |
| Correctoras | Conservar contribuciones negativas y su relación con el grupo | Confirmar cuentas, base y presentación, sin valor absoluto automático. |
| Reversas, devoluciones y anulaciones | Conservar el original y el efecto inverso documentado | Confirmar estados/documentos incluidos sin omitir el original y contar solo su reversa, o viceversa. |
| Cierre fiscal | Mantener separado el reporte del procedimiento que registra el cierre | Este paquete no cambia cuentas nominales seleccionadas, destino patrimonial, fecha ni regla de períodos. |
| Origen sin identificar | Mostrar advertencia en vez de decidir por omisión | Aprobar inclusión/exclusión y cómo documentar la falta de origen. |
| Estados del asiento | Diferenciar borrador, contabilizado y anulado | Determinar qué estados participan por reporte y cómo intervienen sus reversas; no adoptar silenciosamente el filtro actual. |
| Fecha y período | Elegir explícitamente fecha de reconocimiento y/o pertenencia al período | Resolver diferencias y criterio para comparativos, sin corregir períodos cerrados. |
| Centros de costo | Identificar total del salón y cualquier subconjunto | Confirmar centros, tratamiento de no asignados y que un informe parcial no se rotule global. |
| Moneda | Separar importe contable y moneda del documento | Confirmar moneda base y presentación. No convertir importes por el nombre de la cuenta ni aplicar tasas nuevas. |
| Inactivas con historia | Conservar movimientos históricos | Definir presentación y ceros relevantes; la inactividad no elimina saldos. |
| Padres con movimientos directos | Contar cada detalle una vez; subtotal visual separado | Confirmar agrupaciones sin duplicar padre e hijos. |
| Costos, gastos y otros | Describir qué hechos pertenecen a cada rubro | Resolver comisiones, modelos, cargos bancarios, reembolsos y conceptos genéricos antes de implementar. |

El funcionamiento actual no resuelve automáticamente estas decisiones: resultados, balance y cierre utilizan universos distintos. Los detalles de las diferencias están en el anexo técnico.

## 7. Orden de revisión y preguntas al contador

**Nivel 1 — estructura económica y bloqueantes:** confirmar catálogo de referencia, grupos, signos, reglas documentales y cuentas con actividad representativa. Revisar primero fondos, inventarios, proveedores, ingresos de servicios/productos, costos, cargos bancarios y patrimonio; resolver también conceptos de comisiones, modelos y reembolsos aunque carezcan de actividad. La matriz marca28 filas de primera revisión, incluyendo estructuras agrupadoras y casos prioritarios de ensayo que deben segregarse.

**Nivel 2 — cobertura y refinamiento:** revisar las40 filas restantes, subgrupos, cuentas sin actividad, correctoras específicas, cuentas inactivas y desgloses especiales. El orden no autoriza excluirlas de la cobertura empresarial. Un concepto pendiente solo puede aplazarse con un alcance explícito que no lo presente como cubierto.

Preguntas concretas para resolver por escrito:

1. ¿Cuál es el catálogo empresarial de referencia: una versión corregida del Excel, el catálogo base revisado u otra lista? ¿Qué cuentas se conservan y con qué fundamento?
2. ¿Cómo se separarán los documentos legítimos de los ensayos antes de usar un informe empresarial? ¿Qué información falta sobre saldos iniciales y su soporte?
3. ¿Se desea separar ingresos/costos de productos y servicios? ¿Qué subtotales permiten evaluar la operación del salón?
4. ¿Las comisiones son costo del servicio o gasto? ¿Qué hechos representa pago a modelos: prestación del servicio, promoción u otra finalidad?
5. ¿Dónde se presentan mantenimiento y comisiones bancarias, rendimientos y diferencias cambiarias? ¿Se requiere un subtotal financiero?
6. ¿Qué documentos representa reembolso a clientes y cuándo reduce ingresos, constituye una devolución de fondos o corresponde a otro hecho?
7. ¿Cómo se mostrarán descuentos, retiros y otras correctoras, conservando sus efectos firmados?
8. ¿Qué criterios de vencimiento o uso separan corriente y no corriente? ¿Una cuenta puede necesitar más de un desglose?
9. ¿Qué estados y orígenes se incluyen en cada informe? ¿Qué se hace con origen ausente, reversas y anulaciones?
10. ¿Qué fecha/período y centros se utilizarán? ¿Cómo se distinguirán un resultado provisional, una lectura histórica y un estado emitido?
11. ¿Quién revisa y aprueba, con qué documento, alcance y fecha de vigencia? ¿Se exige revisión por una segunda persona? Esa separación aún no se impone como regla nueva.

## 8. Bloqueantes y decisiones aplazables

**Bloquean una clasificación empresarial completa:** catálogo y alcance documental ratificados; separación de ensayos; grupos/signos y pertenencia explícita por cuenta; resolución de hechos económicos ambiguos relevantes; universos de resultados/balance; moneda base; reglas del resultado pendiente; cobertura y aprobación identificable. Las correspondencias dudosas del Excel no se resuelven por aproximación.

**Pueden organizarse después, sin omitir cobertura:** orden visual, etiquetas abreviadas, profundidad de subgrupos, formato de impresión y comparativos adicionales. Corriente/no corriente no es aplazable si el estado requerido necesita esa distinción. Tampoco se aplaza una cuenta activa relevante bajo la apariencia de un informe global completo.

## 9. Contrato de revisión y aprobación futura

El documento que eventualmente se apruebe deberá contener:

- Identidad y versión de la clasificación; catálogo de referencia confirmado y correspondencias explícitas con las cuentas de la instalación.
- Grupos/subgrupos, orden, base de signo, subtotales y asignaciones por cuenta, con tratamiento de correctoras.
- Perfiles diferenciados de resultados/balance: fechas, períodos, estados, orígenes, cierres, centros, moneda y alcance.
- Cobertura completa del universo declarado y diferencias explicadas frente a reportes actuales; los pendientes relevantes bloquean ese alcance.
- Referencia documental, motivo, responsable de preparación/revisión/aprobación, fecha, ámbito y vigencia. En este paquete no se registra ningún acto de aprobación.
- Regla prospectiva para cambios, consulta de versiones anteriores y retirada sin borrar historia. Una modificación posterior exige una nueva revisión del contenido afectado.

Revisar una matriz, aprobarla, publicarla y habilitar un reporte son actos diferentes. Ninguno se presume por el hecho de que el sistema conozca la cuenta o porque exista una política técnica de contabilización.

## 10. Próximo paso condicionado y cierre

El siguiente paso útil es recibir la matriz y las reglas generales revisadas por el contador, identificar desacuerdos y dejar constancia de su resolución. Después, con autorización técnica independiente, podrá diseñarse la adaptación mínima de lectura a esas decisiones, validar cobertura y comparar estados antes de sustituir cualquier reporte. La persistencia, aprobación/publicación en el ERP y emisión formal requerirán alcance propio.

Se crearon únicamente este paquete, la matriz CSV y el anexo técnico. No se ejecutaron consultas SQL, pruebas de aplicación, seeders, migraciones o escritores. No se modificaron datos ni código. La fase termina sin iniciar clasificación empresarial, publicación, emisión o despliegue.
