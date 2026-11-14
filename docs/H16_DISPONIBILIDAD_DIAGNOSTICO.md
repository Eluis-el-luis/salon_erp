# H16 — Diagnóstico previo: implementación detenida

Nota histórica: el propietario resolvió las decisiones y autorizó la integración
puntual con ventas. H16 ya se implementó y verificó; el estado actual está en
[H16_DISPONIBILIDAD.md](H16_DISPONIBILIDAD.md). Este archivo conserva el diagnóstico
anterior a esa autorización.

No se implementó disponibilidad, no se ejecutaron pruebas ni consultas SQL,
y no se modificaron datos o archivos funcionales. Se mantiene
SALON_TEST_DB_RESET_ALLOWED=0. La fase 1 ordena detenerse si las reglas chocan
con un comportamiento financiero: se encontró ese caso.

## Bloqueo confirmado por el código

VentaController::facturarCita carga cualquier cita por ID (línea 53) sin
rechazar canceladas. En el camino que factura inmediatamente la actualiza a
completada (línea 140), dentro de su transacción financiera, sin validar
disponibilidad ni bloquear estilistas.

VentaController::store hace lo mismo desde el carrito del POS (líneas 374-376):
si existe appointment_id, no es cotización y no requiere aprobación, escribe
completada sin condición sobre el estado anterior. Es un update masivo de
Eloquent: un observer saving/updating del modelo Cita NO interceptaría esa
escritura. Ambas entradas están autenticadas y autorizadas por las reglas
existentes; H12 no elimina esta incoherencia de negocio.

Escenario permitido por esos caminos:

1. Cita A de un estilista: 10:00-10:45, pendiente. Se abre su POS.
2. Se cancela A: según H16 debe liberar su intervalo.
3. Se reserva B para el mismo estilista y ese intervalo: debe permitirse.
4. Se factura A desde la pestaña anterior del POS o el endpoint directo.
5. Si la operación financiera termina correctamente, A queda completada y B
   pendiente en el mismo intervalo; ambas deberían ocupar disponibilidad.

Condiciones: usuario con acceso al flujo y caja abierta cuando ese flujo la
exige, operación que no quede retenida, y demás condiciones financieras y de
inventario satisfechas. Puede usarse un servicio sin fórmula para independizar
la reproducción del stock. No se ejecutó este escenario ni se creó una venta:
la ausencia del control está confirmada estáticamente, no por reproducción.

Un mutex solo en CitaController no cubre estas escrituras. Tampoco basta ocultar
el botón de una cita cancelada: puede existir un carrito abierto anteriormente.
Para una garantía global hace falta integrar las transiciones de facturación
en el mismo protocolo o impedir explícitamente esa reactivación en su límite.
Modificar facturación está expresamente excluido del alcance actual.

## Protecciones y transiciones que sí existen

- CitaController::store ya agrupa cita y appointment_user en una transacción.
  No bloquea estilistas ni comprueba intervalos.
- CitaController::update permite pendiente, completada, cancelada y no_asistio
  desde cualquier estado; no controla conflictos o historia financiera.
- AprobacionService::aprobarVenta (líneas 179-182) solo completa una cita que
  siga pendiente. Esa condición impide convertir directamente cancelada a
  completada desde la aprobación; no atribuirle el mismo defecto incondicional.
  Puede, sin embargo, aprobar una venta cuya cita se haya cancelado sin completar
  esa cita: es una decisión pendiente sobre coordinación de ambos documentos.
- VentaController::anular (líneas 740-743) devuelve completada a pendiente para
  refacturar. H16 puede conservarlo: ambos estados ocupan el mismo intervalo.
  No es necesario cambiar la reversión contable para conservar disponibilidad.
- No hay un estado facturada en appointments. Facturación utiliza completada
  y el vínculo sales.appointment_id. No se propone añadir estados.

## Duraciones, catálogo y relaciones

- services.duration: integer de minutos, default 30. El catálogo valida min:5.
- service_variants.duration: unsignedInteger nullable; el catálogo valida
  min:5/max:600 cuando se especifica. NULL puede heredar duración del servicio.
- cleanup_minutes es un metadato separado. Servicio::duracionTotal suma
  ejecución y limpieza; NO debe utilizarse para el bloqueo H16 acordado.
- appointments.duration_minutes ya permite congelar la duración por cita;
  es integer no nullable, default 30. No tiene restricción SQL de positividad.
- Actualmente el controlador acepta duración nullable enviada por el cliente
  y usa 45 si falta. JavaScript envía 45: no deriva el valor del servicio.
- La cita no guarda service_variant_id y su formulario no selecciona variantes.
  La variante elegida posteriormente en una venta no debe cambiar la duración
  histórica de una reserva ni inferirse como su variante original.
- appointment_user vincula citas y usuarios con FKs y cascadas; no aporta una
  exclusión de intervalos. Usuario::citas y Cita::estilistas usan este pivot.

Duración propuesta: variante válida del servicio seleccionado cuando se elija
explícitamente; si su duración es NULL, services.duration. Exigir entero >0,
guardarlo una vez en duration_minutes y no confiar en un valor arbitrario
enviado por el navegador. Una duración de catálogo inválida se rechaza, no se
sustituye silenciosamente por 45. Sin sumar limpieza, sin recalcular historia.

## Decisiones necesarias antes de implementar

1. Autorizar una integración puntual en las transiciones de citas desde
   VentaController, sin cambiar precios, cobros, inventario, comisiones o
   asientos. Recomendación: no completar/facturar una cita cancelada; su
   reactivación debe pasar primero por agenda y volver a validar disponibilidad.
2. Definir disponibilidad de no_asistio, estado ya existente y no contemplado
   en las decisiones recibidas: conservar ocupación histórica o liberarla.
3. Definir si se permite cancelar citas con una venta vigente o retenida y qué
   debe suceder al aprobar esa venta. No cambiar ni anular documentos financieros
   automáticamente como consecuencia de un cambio de estado de agenda.

Las reglas pendiente/completada bloqueantes y cancelada liberada se mantienen
como decisión del propietario. El conflicto no requiere añadir sillas u otros
recursos: afecta al propio estilista.

## Diseño mínimo condicionado a esas decisiones — NO implementado

Un servicio pequeño de agenda compartido por creación y cambios de estado,
sin nueva arquitectura ni dependencias. Intervalos semiabiertos [inicio, fin):
hay conflicto si inicio_existente < fin_nuevo y fin_existente > inicio_nuevo.
No filtrar solamente por día: una cita puede cruzar medianoche.

Protocolo: transacción en la misma conexión; normalizar IDs de estilistas y
bloquear filas users por ID ascendente, incluso si no existen citas. Después
releer conflictos mediante lectura actual dentro de la transacción (no una
instantánea anterior de REPEATABLE READ). Validar todos los estilistas, crear
cita y pivot, y confirmar juntos. Cancelación/reactivación deben compartir
protocolo. La facturación conserva su transacción exterior, sin commits
prematuros añadidos por agenda.

Para una futura reprogramación, bloquear la unión de estilistas anteriores y
nuevos con el mismo orden, releer cita y asignaciones y excluir su propio ID
de los conflictos; si las asignaciones cambiaron durante la adquisición, no
continuar con un conjunto de locks incompleto. Esto es contrato para H15,
no autorización para implementar su interfaz ahora.

Mensaje de conflicto comprensible en el formulario, conservando H12 y las
confirmaciones/navegación H14-A. No resolverlo solo con JavaScript, observers
o locks sobre citas que pueden no existir todavía.

## Pruebas preparadas como plan — NO ejecutadas

- Reservas separadas, consecutivas, contención y solapamientos parciales en
  ambos extremos; diferentes estilistas y varios estilistas por cita.
- Distintos días y cruces de medianoche, igualdad exacta de extremos.
- Duración base/variante/herencia, rechazo de cero/negativo/NULL sin duración
  resoluble, duración histórica inmutable al cambiar el catálogo y sin limpieza.
- Pendiente y completada ocupan; cancelación libera; reactivación revalida;
  no_asistio según decisión. Reversión de asociación fallida sin cita parcial.
- Caso bloqueante de facturación: cancelar A, reservar B y tratar de facturar A
  debe rechazarse sin venta, asiento, pago o cambio parcial de estado.
- Carrera real con dos procesos y conexiones MySQL independientes, identidad
  tester verificada en cada uno, barreras para forzar contención sobre el mismo
  estilista y resultado de una reserva y un rechazo. Fixtures visibles mediante
  commit exclusivo en salon_erp_test; no esconderlos en la transacción del padre.
  Finalización/limpieza de procesos en finally y sin ejecución paralela de suite.
- Regresiones inspeccionadas H12, H13 (incluida facturación) y H14-A; middleware
  CSRF efectivamente activo y navegación/confirmaciones conservadas.

Antes de ejecutar: respaldo disponible, destino/privilegios tester, efectos de
migraciones/seeders y líneas base de bases ajenas; si se autoriza RefreshDatabase,
indicador temporal con finally a cero. No se hicieron estas pruebas o conexiones
en esta fase por la condición de parada.

## Archivos y reversión

Único archivo añadido en H16: este diagnóstico. No hay implementación funcional,
migración ni cambio de representación para revertir. Retirar este documento
revierte el cambio de archivos de la fase; no hay recuperación SQL necesaria.

Plan de archivos, sujeto a resolver el alcance: CitaController, un servicio de
agenda, Cita si requiere helpers, vista de agenda y pruebas focalizadas;
VentaController para la transición que hoy elude el protocolo. La aprobación
solo se tocaría si la decisión sobre documentos retenidos lo exige. No cambiar
cálculos financieros, migraciones históricas, inventario ni H15.

Las bases no fueron consultadas ni alteradas aquí; no se afirma una nueva
comparación de hashes. Su último estado validado es el cierre de H13-D.
