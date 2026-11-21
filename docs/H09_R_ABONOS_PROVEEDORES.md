# H09-R — Integridad de abonos a proveedores

Fecha: 21 de noviembre de 2026. Alcance exclusivo H09. Corrección implementada
y comprobada sin conexiones SQL; serialización y rollback reales InnoDB pendientes.
No se reanudó H05-V1 ni se trabajó en WFP.

## 1. Estado actual de H09

El defecto descrito en AUDITORIA_MANTENIBILIDAD.md seguía presente:

- CuentaPorPagarController::store abría una transacción manual, leía la CxP sin
  lock y restaba el importe del saldo cargado en memoria.
- Solo comprobaba que el importe no superara ese saldo. No verificaba estado
  pagable ni coherencia entre deuda original, recibos y saldo.
- El rechazo por sobrepago retornaba sin commit/rollback.
- CompraService::anularFactura bloqueaba factura, pero cargaba su CxP como
  relación ordinaria y comprobaba pagos mediante exists sin lectura bloqueante.

Se revisaron los modelos, consumidores, migraciones y servicios actuales,
incluidas compras/anulación, FondosService y las integraciones contables de
pagos. No se identificó otra implementación vigente que hubiera corregido H09.
Los documentos H02/H03/H04 posteriores describen cambios de inventario y
reversibilidad; no sustituyen el bloqueo de esta obligación. No se encontró
un informe previo específico de corrección H09 en docs.

Se confirmó además por código una colisión de referencias contables: PXP-time()
puede producir el mismo numero_asiento para distintos abonos del mismo segundo,
y ese campo tiene índice UNIQUE. No se provocó una colisión sobre una base real.

## 2. Rutas, métodos y consumidores vigentes

| Ruta | Método/caso de uso | Acceso vigente |
| --- | --- | --- |
| GET `/cuentas-por-pagar` | CuentaPorPagarController::index | Web + auth + role:admin,contador; bypass alvaro |
| POST `/cuentas-por-pagar/abonar` | CuentaPorPagarController::store → PagoProveedorService::registrar | Mismo grupo |
| POST `/compras/{id}/anular` | FacturaCompraController::anular → CompraService::anularFactura | Grupo role:admin; bypass alvaro |

Consumidores internos identificados:

- resources/views/payables/index.blade.php: formulario POST, CSRF, ID de CxP,
  monto y forma_pago; máximo mostrado desde la página.
- resources/views/providers/show.blade.php: modal de abono rápido con el mismo
  contrato, importe min 0.01/step 0.01 y límite mostrado.
- CadenaSuministroApTest: abono seguido de intento de anulación, con SQL/seeders
  históricos. Se inspeccionó; **no se ejecutó**.

No se modificaron rutas, permisos, formularios ni campos solicitados. El éxito
sigue redirigiendo al origen con el mensaje existente; los rechazos de dominio
se muestran mediante el error de sesión. La validación HTTP conserva el manejo
normal de Laravel. Los tests nuevos del controlador no arrancan ese kernel ni
certifican el renderizado HTTP completo.

## 3. Autoridad del saldo e invariantes

La autoridad de coordinación es **cuentas_por_pagar.id**, no el saldo mostrado
en el navegador ni el perfil del proveedor. El nuevo caso de uso obtiene esa fila
mediante lockForUpdate dentro de su transacción y vuelve a comprobarla entonces.

| Invariante | Tratamiento actual |
| --- | --- |
| Obligación existente | exists HTTP es ayuda temprana; findOrFail bloqueante del servicio es la decisión definitiva |
| Estado pagable | Solo pendiente/parcial, comprobados después de adquirir el lock |
| Importe positivo representable | Decimal ordinario, hasta 2 decimales, mayor que cero; máximo DECIMAL(15,2): C$9,999,999,999,999.99 |
| No sobrepago | Centavos solicitados ≤ saldo actual bloqueado |
| Pagos acumulados/saldo | original − suma de recibos = saldo antes de registrar; después se conserva esa igualdad |
| Rechazo sin efectos parciales | Excepción de dominio antes de crear recibo; errores posteriores revierten mediante transacción |
| Pago/anulación compatibles | Comparten mutex CxP y lecturas actuales de pagos; no pueden confirmar ambos una anulación incompatible y su abono |
| No actualización perdida | Saldo se calcula con la fila obtenida después del lock, no con una lectura previa |
| Reenvío | **Sin garantía de idempotencia**, detallado en §8; no hay identificador de solicitud |
| Contrato económico | Mismo importe NIO en recibo y contabilidad; efectivo/banco y cuentas existentes conservados |

Se reutiliza InventoryDecimal::scaled/text para convertir a centavos enteros y
volver a string decimal, con límite explícito de las columnas DECIMAL(15,2).
No se suman/restan floats como autoridad nueva. Los casts existentes del modelo
se conservan para compatibilidad; el caso de uso lee getRawOriginal de las filas
cargadas para sus cálculos.

Se rechazan cero, negativos, NaN/Infinity, exponentes, booleanos, arrays, texto,
precisión excesiva y valores fuera de rango. No se redondea un importe inválido
para volverlo aceptable. La misma comprobación esencial se usa desde HTTP y
desde el servicio, sin depender del formulario.

Una obligación histórica cuyo saldo no concuerde con sus recibos queda bloqueada
para conciliación. No se reescriben saldos ni se fabrican pagos para repararla.
La anulación tiene una semántica distinta: deja estado anulada y saldo cero;
esa fila no pasa por el cálculo de obligación pagable.

## 4. Transacciones y bloqueos

PagoProveedorService::registrar reemplaza el caso de uso que antes estaba en el
controlador; no es un segundo circuito de pagos. Secuencia:

1. Validar representación/positividad del importe y forma de pago.
2. DB::transaction en la conexión predeterminada.
3. Bloquear CxP por PK; verificar estado y sus importes originales.
4. Leer pagos con orderBy(id), lockForUpdate y get; verificar su suma en centavos.
5. Rechazar exceso; crear PagoProveedor con campos explícitos.
6. Guardar saldo/estado y ejecutar el método contable existente con el mismo importe.
7. Commit exterior; ante Throwable, rollback al nivel del llamador.

La ruta web ordinaria comienza sin transacción y debe terminar en nivel cero.
Si el servicio se llama dentro de una transacción exterior legítima, no confirma
esa transacción del llamador. No hay retornos HTTP dentro de una transacción
manual ni commits nuevos en el controlador.

ContabilidadService::contabilizarPagoProveedor mantiene su bloque interno y
validarCuadre antes de commit. Su catch ahora cubre Throwable: un Error dentro
de ese bloque debe cerrar el nivel anidado y propagarse al exterior. Esto evita
dejar un nivel adicional abierto cuando el servicio exterior maneja el fallo.
La creación eventual del período ocurre bajo la transacción exterior del abono.

Las lecturas de pagos también son bloqueantes para no decidir desde un snapshot
anterior después de esperar el mutex. MySQL documenta que FOR UPDATE obtiene
datos actuales y mantiene sus locks hasta commit/rollback.
[Manual MySQL 8.4: locking reads](https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html).

En el recorrido revisado no hay PDO/conexiones independientes: modelos, DB y
motor contable usan la predeterminada. No se añadieron otras conexiones. Las
transacciones anidadas de Laravel usan la misma conexión; el ensayo real de
esta propiedad sigue pendiente, sin equiparar un mock con una sesión InnoDB.

## 5. Interacción con anulación de compras

Orden de adquisición de locks relevante:

| Operación | Orden |
| --- | --- |
| Abonar | CxP → pagos existentes por id → integración contable |
| Anular compra | Factura → CxP → pagos cuando procede comprobarlos → usuarios/artículos/lotes y reversión ya existentes |

El abono **no pide después el lock de factura**. Esto evita un ciclo introducido
por ordenar factura/CxP de manera inversa entre las dos operaciones. No se
modificó el orden del inventario o el algoritmo físico/contable de anulación.

La anulación deja de usar la CxP eager-loaded sin lock: obtiene
cuentaPorPagar()->lockForUpdate()->first(). Antes de mutar, rechaza un estado
distinto de pendiente, pagos existentes mediante lectura actual bloqueante o
saldo distinto del original. Así también bloquea una deuda parcialmente pagada
con historial incompleto, sin atribuirle silenciosamente ausencia de abonos.

Interleavings previstos por el protocolo, **no medidos en SQL aquí**:

- Si el abono posee el mutex primero y confirma, la anulación espera y debe
  observar estado parcial/pagada o recibos actuales: rechaza antes de revertir.
- Si la anulación posee el mutex primero y confirma, el abono espera y observa
  anulada: rechaza antes de crear recibo.
- Que la anulación haya empezado y tenga lock de factura no decide por sí solo
  cuál gana. El punto común de serialización es CxP. La garantía es que no
  confirman ambos efectos incompatibles, no que la primera petición HTTP siempre gane.

Los demás escritores identificados son CompraService::ejecutarIngresoMercaderia
(creación inicial de obligación) y la anulación. ProveedorController/Proveedor
agregan/leen saldos; no son escritores de esa fila. No se encontró otra creación
de PagoProveedor en app. Escritores SQL externos o futuros que ignoren el mutex
no quedan cubiertos por esta disciplina de aplicación.

## 6. Fallos encontrados y evidencia

| Hallazgo | Evidencia previa / nueva |
| --- | --- |
| Sobrepago y actualización perdida | Dos lecturas 100 y abonos 80 pueden escribir ambas 20 con recibos 160 en el algoritmo previo; caracterización aritmética pura, sin afirmar persistencia histórica |
| Transacción abierta al rechazar | Return por sobrepago entre beginTransaction y commit/rollback, observado en código previo; retirado |
| Estado no pagable | El controlador previo no comprobaba estado; ahora servicio rechaza anulada/pagada y otros estados |
| Precisión | numeric/min aceptaba montos que luego podían redondearse a distinta escala; ahora validación exacta compartida |
| Carrera con anulación | Relación ordinaria y exists no bloqueante; ahora mutex común y lecturas bloqueantes explícitas |
| Colisión de asientos | PXP-time() bajo UNIQUE; ahora referencia PXP-PAGO-id_recibo, ensayada con dos recibos en tiempo congelado |
| Error no Exception en asiento | Catch interior solo Exception; ahora Throwable, comprobado con Error centinela en doble sin SQL |
| Historial incoherente | Se detecta y bloquea; no se modifica evidencia para permitir el pago |

Las pruebas prueban rechazos, cálculos, contratos y llamadas/SQL generados.
No se sostiene que dos conexiones SQL concurrentes hayan reproducido o resuelto
la carrera en este entorno.

## 7. Correcciones implementadas y contrato de desembolso

- Nuevo PagoProveedorService, dueño del caso de uso/transacción, con lecturas
  actuales bloqueantes, invariantes de estado/saldo/recibos y cálculo exacto.
- CuentaPorPagarController delega, conserva contratos web y muestra errores de
  dominio; no acepta usuario_id/origen_pago_id/estado extra como autoridad.
- CompraService coordina CxP/pagos bloqueados antes de decidir su reversión.
- ContabilidadService modifica solo la referencia de asientos de pagos nuevos
  y la captura Throwable del método relacionado.

Se conservan recibo, usuario autenticado, fecha, modulo_origen=pagos_proveedor,
referencia_id=id del recibo, moneda base y dos líneas contables:

| Método | Debe | Haber |
| --- | --- | --- |
| efectivo | 2.1.1 Proveedores | 1.1.1 Caja |
| banco | 2.1.1 Proveedores | 1.1.2.1 Banco LAFISE |

Monto idéntico a ambos lados, con validarCuadre existente antes del commit.
No se cambió la elección de banco, disponibilidad de fondos, bóveda, registro
de MovimientoBancario ni divisas. Este flujo previo no llama a FondosService,
no expone selección de cuenta bancaria/moneda y no crea movimiento bancario
desde contabilizarPagoProveedor: no se inventó uno para esta corrección.

Los asientos antiguos PXP-timestamp no se renumeran. PXP-PAGO-id evita compartir
namespace con los sufijos numéricos legacy y no depende de la resolución del
reloj. El UNIQUE del asiento sigue siendo una restricción SQL; su eficacia real
no se ensayó contra las bases protegidas.

## 8. Contrato de idempotencia y solución preparada

No hay identificador de solicitud en los dos formularios ni clave persistida
para deduplicar POST. El ID del recibo se obtiene **después** de crear el pago;
no identifica el reenvío HTTP. origen_pago_id es opcional, no único y no forma
parte del contrato enviado; no debe reutilizarse como clave idempotente.

Por tanto, con saldo suficiente, repetir dos veces el mismo cuerpo registra
dos abonos: por ejemplo 20 + 20 sobre 100 deja saldo 60, dos recibos y dos
asientos. La prueba documenta este límite y no lo considera un caso idempotente
aprobado. Si el primer pago agota el saldo, el segundo se rechaza por estado/saldo;
eso tampoco equivale a deduplicación del request.

La nueva referencia contable distingue recibos distintos y evita dos asientos
con la misma referencia de un recibo bajo UNIQUE, pero **no** deduplica solicitudes.

Propuesta compatible para una intervención posterior, sin implementar aquí:

1. Token aleatorio de operación emitido al abrir el formulario, conservado en
   reintentos/doble envío y renovado al iniciar un abono diferente.
2. Contrato que vincule token con actor, CxP, monto y forma de pago normalizados;
   mismo token/datos devuelve el recibo existente, mismo token/datos distintos
   se rechaza. Definir también comportamiento de errores y retención del token.
3. Registro durable y unicidad SQL adquiridos en la misma transacción que el
   recibo; coordinación del orden de locks con CxP. Una variable de sesión o
   deshabilitar el botón no resuelve varios procesos/conexiones.
4. Eso necesita esquema/índice o tabla de operaciones y una política de
   compatibilidad para consumidores antiguos. **No está autorizado ni se creó**.
   Debe revisarse y aprobarse aparte antes de prometer la invariante de reenvío.

No se infiere que dos pagos legítimos de igual importe/fecha son duplicados;
rechazarlos por heurística cambiaría la operación económica existente.

## 9. Pruebas puras ejecutadas

Ejecución exclusiva con PHP 8.3.30, vendor/autoload; sin phpunit.xml,
tests/bootstrap.php, .env.testing, bootstrap Laravel de producción o SQL:

```powershell
& 'E:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe' -d memory_limit=128M -d max_execution_time=40 -d zend.exception_ignore_args=1 vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result --no-progress tests/Unit/PagoProveedorIntegrityTest.php tests/Unit/PagoProveedorHttpTest.php tests/Unit/PagoProveedorAccountingTest.php
```

Resultado: **25 pruebas aprobadas, 113 aserciones**, código 0.

- IntegrityTest: parcial/liquidación, importes inválidos, sobrepago, ausencia,
  anulada/pagada, incoherencia histórica, error posterior a efectos, reenvío,
  interleavings seriales de saldo insuficiente/suficiente y anulación primero.
  El rollback de este grupo restaura un doble de estado en memoria.
- Dos casos compilan consultas **Eloquent reales** usando una conexión espía
  cuyo select devuelve filas sintéticas y cuyo proveedor PDO lanza una excepción.
  Comprueban FOR UPDATE en CxP/pagos y el orden factura → CxP → pagos de la
  anulación real, que se detiene antes del inventario. No son sesiones InnoDB.
- HttpTest: Request/validación/controlador reales con dependencias simuladas;
  formulario válido, campos protegidos, importes/ID/método inválidos y error de
  dominio mantienen redirect/mensaje/error sin indicar éxito falso. No es un
  ensayo de navegador, middleware o kernel completo.
- AccountingTest: método real contabilizarPagoProveedor con modelos/DB simulados;
  verifica cuentas, montos y referencias de dos recibos en el mismo segundo.
  validarCuadre se sustituye solo en esta prueba para observar su llamada e
  inyectar un Error. Se comprueban las líneas simétricas y cierre del nivel del
  doble; no se ejecuta AccountIntegrityService ni cuadre agregado de SQL real.

La primera ejecución ampliada detectó bindings faltantes de Auth en los arneses
con Container mínimo; se corrigieron exclusivamente esos tests. El resultado
arriba corresponde a los arneses corregidos. No se cambiaron expectativas de
pruebas históricas. Lint de los siete PHP modificados/nuevos y diff --check pasan.

## 10. Pruebas SQL pendientes

No se creó laboratorio. Plan focalizado para una instancia independiente,
pendiente de autorización específica y sin reutilizar salon_erp_test:

1. Verificar identidad/UUID/datadir/grants, InnoDB, esquema y conexiones de
   escritura únicas; fixtures sintéticos de proveedor, CxP, usuarios, moneda,
   período abierto y cuentas vigentes. Sin migraciones/seeders históricos ni
   transacción exterior que oculte commits del caso de uso.
2. Dos conexiones A/B sobre la misma CxP de 100: detener A tras lock; iniciar B
   por el servicio real; demostrar espera; A paga 80 y confirma. B debe leer
   saldo 20/recibo 80 actuales y rechazar 80 sin crear recibo/asiento nuevo.
3. Saldo 200 y pagos 80/80: ambos deben confirmar secuencialmente, saldo 40,
   recibos/asientos distintos incluso en el mismo segundo, suma abonada 160.
4. Pago primero/anulación después: demostrar espera sobre CxP y rechazo de la
   anulación tras el commit del pago, sin reversión parcial de compra.
5. Anulación primero/pago después: demostrar espera y rechazo por anulada tras
   commit, sin recibo, asiento ni saldo resucitado. Repetir con rollback de la
   primera operación para comprobar que el segundo puede continuar correctamente.
6. Error centinela después de recibo/saldo y dentro/después de contabilidad:
   constatar efectos dentro de la transacción, luego nivel cero y lectura desde
   otro observador del mismo laboratorio sin recibos/asientos/períodos huérfanos.
7. Verificar líneas/debe/haber, saldo=original−pagos, método/origen preservados,
   historial de otra obligación intacto y reenvíos según la limitación actual.

Registrar esperas y barreras reales, no usar sleep como única prueba de contención.
Comparar filas y contadores por separado: AUTO_INCREMENT puede avanzar aunque
reviertan filas; ese efecto solo es admisible en laboratorio descartable.
No se reabre infraestructura H05, WFP ni otro despliegue para ejecutarlo ahora.

## 11. Preservación de bases y evidencia

No se abrió ninguna conexión SQL, tampoco de lectura. No se usaron
RefreshDatabase, migrate:fresh, db:wipe, seeders, restauraciones o suites generales.
No se leyó/modificó .env ni .env.testing ni se cambió el indicador de reset.

salon_erp, salon_erp_bootstrap_check, salon_erp_test y toda evidencia AC-04/AC-05
quedaron fuera de ejecución. No se generaron snapshots ni hashes SQL nuevos;
no se afirma igualdad histórica medida hoy. La preservación de esta intervención
se basa en no acceder a ellas ni modificar sus archivos/evidencia.

Los informes H05-V1 y H05-V1A ya estaban sin seguimiento al comenzar y se
conservaron. No se crearon recursos de aislamiento ni se trabajó en sus helpers.

## 12. Archivos modificados

- app/Http/Controllers/CuentaPorPagarController.php — validación exacta y delegación.
- app/Services/PagoProveedorService.php — nuevo caso de uso único de abonos.
- app/Services/CompraService.php — mutex/guardia de CxP en anulación, sin cambiar inventario.
- app/Services/ContabilidadService.php — solo referencia y Throwable del pago proveedor.
- tests/Unit/PagoProveedorIntegrityTest.php — dominio, interleavings simulados y SQL generado.
- tests/Unit/PagoProveedorHttpTest.php — contrato HTTP aislado.
- tests/Unit/PagoProveedorAccountingTest.php — integración contable con dobles.
- docs/H09_R_ABONOS_PROVEEDORES.md — este informe.

No se modificaron modelos, rutas, vistas, esquema, migraciones, fondos/divisas,
clasificación, cierre, nómina ni datos históricos. Sin operaciones Git de escritura.

## 13. Riesgos residuales

- La concurrencia y atomicidad real de MySQL siguen sin ejecutar: los locks
  pedidos y el orden están sustentados por código/SQL generado, no por carreras
  simultáneas medidas. No declarar H09 certificado en producción por estos tests.
- El reenvío parcial sigue pudiendo crear otro abono cuando queda saldo; falta
  contrato durable de idempotencia. Se documenta sin ocultarlo con el mutex.
- Obligaciones históricas con saldo/recibos incoherentes quedan bloqueadas para
  revisión; no se probó si existen en las bases actuales ni se repararon.
- La disciplina exige que futuros escritores de la obligación respeten el mismo
  mutex. No se garantiza ausencia de todos los deadlocks con otros módulos;
  errores de base deben abortar la operación, no confirmarla parcialmente.
- El desembolso conserva las limitaciones previas de efectivo/banco genérico;
  disponibilidad, conciliación y multimoneda no se rediseñan en H09.
- La anulación sigue usando el vínculo factura.cuenta_por_pagar_id actual.
  Relaciones históricas incompletas requieren revisión, no asociación inferida
  o migración automática en esta intervención.

## 14. Recomendación funcional siguiente

Revisar y autorizar aparte el contrato de idempotencia durable antes de introducir
claves/índices o cambiar formularios. La validación de locks/rollback real requiere
el laboratorio independiente descrito, sin convertir las bases históricas en
zona de fixtures. No aplicar nuevas políticas de banco, divisas o contabilidad
para conseguir éxito de pruebas.

H09-R se detiene con la corrección y evidencia pura descritas. No se inicia H07,
H06, H05-V1, migración ni despliegue automáticamente.
