# H05-V1 — Validación SQL integrada: parada en preflight

Fecha de cierre: 21 de noviembre de 2026.

**Estado: BLOQUEADA antes de crear recursos. No se ejecutó ningún escenario SQL.**
No se demostró una restricción efectiva de proceso/red que permitiera únicamente
127.0.0.1:3307 e impidiera conexiones a 3306 y otros destinos. Se aplicó la
condición expresa de parada de H05-V1 §2: no inicializar ni escribir mientras
esa separación no esté demostrada. La autorización de instancia descartable
no se utilizó para flexibilizar este requisito.

Este informe registra una intervención incompleta por precondición de seguridad;
no declara validación integrada aprobada ni confirma defectos mediante SQL.

Referencias revisadas: AGENTS.md, docs/H05_R_VALIDACION_CARRITO.md,
docs/H05_V0_PLAN_SQL_AISLADO.md, tests/Support/H05DisposableSafety.php y
tests/Unit/H05DisposableSafetyTest.php. Se contrastaron también las referencias
PDO directas identificadas en V0.

Código al inicio: `4773e87137bf09dd36486c47733a6dae853f4777`. Árbol limpio.

## 1. Identidad de instancia y datadir

| Elemento | Estado comprobado |
| --- | --- |
| Binario existente | E:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqld.exe |
| Versión | `--no-defaults --version` confirmó MySQL Community Server 8.4.3, Win64 x86_64; salida y código 0 |
| SHA256 del binario | `ee6b6ba65d99bcea50b6605bc0650588c4d5b91b11aefcf4d9e5bd2c12e07d8e` |
| Raíz prevista | E:/salon-erp-h05-disposable/run-h05v1: no existe |
| Padre previsto | E:/salon-erp-h05-disposable: no existe |
| Ancestro existente | E:/ inspeccionado: sin atributo ReparsePoint, LinkType nulo |
| Datadir previsto | E:/salon-erp-h05-disposable/run-h05v1/mysql/data: no creado |
| Puerto 3307 | Sin listener en consulta completa de Get-NetTCPConnection, ejecutada correctamente con permiso de solo lectura |
| UUID nuevo | No generado; no se inicializó servidor |
| Esquema/cuenta runtime | No creados |
| my.ini/logs/tmp privados | No creados |

No se ejecutó mysqld --initialize ni se arrancó otro servidor. Ejecutar únicamente
la consulta de versión no inicializa un datadir ni arranca el servicio.
La ausencia de listeners es una observación de preflight, no una reserva del puerto.

La raíz nueva no coincide con un directorio de instalación existente. Al no existir
su padre, no se crearon componentes para probarlos. No se certifica una resolución
futura de enlaces: deberá repetirse al reanudar antes y después de crear la ruta.

## 2. Pruebas reales de aislamiento y preflight

### Inspecciones realizadas

Se consultaron metadatos de archivos, hashes, estado/diff Git, comandos/módulos
disponibles, procesos MySQL y listeners. No se hicieron conexiones SQL ni pruebas
de conexión TCP al servidor histórico.

El sandbox denegó inicialmente Get-CimInstance y Get-NetTCPConnection. Se solicitó
y obtuvo autorización **exclusivamente de lectura** para repetir la inspección
fuera del sandbox. Esa autorización no concede permiso para modificar firewall,
políticas de red, cuentas Windows, permisos globales o procesos existentes.

Resultado de esa inspección:

- Dos procesos mysqld.exe con PIDs 4496 y 11664, ambos del binario de Laragon.
- Listener histórico `:::3306`, PID 11664. No se detuvo ni modificó.
- Ningún listener en 3307.
- La extracción acotada de argumentos datadir/defaults-file no devolvió valores;
  por sí sola no permite afirmar el datadir efectivo de cada proceso.

La configuración existente
`E:/laragon/bin/mysql/mysql-8.4.3-winx64/my.ini` declara puerto 3306 y
datadir `E:/laragon/data/mysql-8.4`. Solo se extrajeron opciones de ruta/puerto,
sin imprimir el archivo completo ni credenciales. Se verificó existencia y
metadatos, sin leer archivos de tablas, de:

- E:/laragon/data/mysql-8.4/salon_erp
- E:/laragon/data/mysql-8.4/salon_erp_test
- E:/laragon/data/mysql-8.4/salon_erp_bootstrap_check

Se excluyen ese datadir completo, las tres carpetas y la instalación Laragon.
Es evidencia de configuración/filesystem, no un SELECT del datadir efectivo.
No se accedió al contenido de tablas o archivos de datos.

### Control obligatorio no demostrado

Los helpers V0 validan configuración e identidad mediante predicados puros.
No aplican restricciones de sockets. Un puerto configurado y credenciales nuevas
no impiden que PHP intente otro PDO o acceda a otro destino. Los lectores
SyntheticFinancialPresentationDraftReader, AccountingCatalogueInventoryReader y
PilotDemoReadOnly conservan DSN directos a 3306/salon_erp_test; no se invocaron.

En la sesión no se identificó una herramienta que proporcione una lista permitida
de red **por proceso** configurable para este ensayo. La configuración general
de red restringida del sandbox tampoco constituye prueba de permitir 3307 y
denegar simultáneamente 3306. La inspección encontró NetSecurity, pero disponer
de cmdlets no demuestra una política aplicada. No se ejecutó New-NetFirewallRule
ni se modificó el firewall compartido. Se encontró wsl.exe, pero eso no demuestra
una distribución o aislamiento apto para ejecutar este PHP/MySQL Windows.

No se aprovisionaron AppContainer, filtros WFP, VM, cuentas Windows ni recursos
de red externos a la raíz. No se afirma que todas esas alternativas sean
imposibles: **ninguna quedó implementada, autorizada cuando correspondiera y
verificada con pruebas positivas/negativas de tráfico** en este preflight.

Prueba requerida para reanudar: bajo el mismo aislamiento e identidad del futuro
runner, demostrar permiso hacia el endpoint descartable autorizado y denegación
de 3306 y demás destinos, con evidencia de enforcement del sistema; confirmar
también que no hereda acceso a secretos históricos. No utilizar credenciales SQL
históricas para probar esa denegación. No aceptar banderas booleanas como prueba.

## 3. Estructura instalada y huella

No se generó ni aplicó DDL. No existe estructura instalada ni huella de esquema
real que pueda informarse.

Sí se verificó el archivo fuente permitido:

`tests/Support/fixtures/ac05-schema-v1.json`

SHA256: `0c139a1fc6627958f3827c9062e76bf6a3685b67eb731e379c78b872202a4dfc`,
coincidente con V0. No se modificaron el manifiesto ni los perfiles AC-05 89/91.
Hash de origen no equivale a revisión/aplicación de DDL ni a equivalencia con el
esquema vivo. La resolución de dependencias FK, selección final de tablas,
validación de sentencias y comparación contra MySQL quedan pendientes.

## 4. Fixtures creados

Ninguno. No se crearon usuarios SQL/ERP, roles, cajas, clientes, catálogos,
variantes, inventario, lotes, recetas, monedas, períodos o citas sintéticos.
No se generaron contraseñas ni PIN. No se copiaron datos empresariales,
credenciales, saldos, borradores o evidencias AC-04/AC-05.

## 5. Escenarios ejecutados

**0 de 18.** Solo se ejecutó preflight de lectura y consulta de versión del binario.
No se inició Laravel ni el kernel, no hubo PDO del runner y no se construyó un
bootstrap de emisión. No se ejecutaron pruebas históricas ni suites generales.

## 6. Escenarios aprobados

Ningún escenario SQL aprobado. Las 53 pruebas PHP y 12 de frontend de H05-R, y
las 33 pruebas/55 aserciones de V0, son resultados previos; **no se reejecutaron
ni se contabilizan como validación SQL H05-V1**.

## 7. Escenarios fallidos

Ningún escenario funcional ejecutado y fallido. El incumplimiento observado es
una precondición de aislamiento no demostrada; no se atribuye al código de
facturación ni se presenta como un rollback fallido.

## 8. Escenarios bloqueados o no ejecutados

Los 18 escenarios quedan bloqueados por la misma precondición, antes de DDL/fixtures:

| Nº | Escenario H05-V0 | Estado |
| --- | --- | --- |
| 1 | Producto válido | No ejecutado |
| 2 | Servicio válido | No ejecutado |
| 3 | Venta mixta | No ejecutado |
| 4 | Variante | No ejecutado |
| 5 | Descuento admitido | No ejecutado |
| 6 | Precio manipulado | No ejecutado |
| 7 | Cantidad inválida | No ejecutado |
| 8 | Venta a crédito | No ejecutado |
| 9 | Borrador | No ejecutado |
| 10 | Retenida y aprobada | No ejecutado |
| 11 | Consumo real explícito cero | No ejecutado |
| 12 | Pago mixto | No ejecutado |
| 13 | Fallo posterior a inventario | No ejecutado |
| 14 | Fallo posterior a pagos | No ejecutado |
| 15 | Fallo posterior a asiento | No ejecutado |
| 16 | Rollback completo | No ejecutado |
| 17 | Preservación de cita ajena | No ejecutado |
| 18 | Totales, pagos y asientos | No ejecutado |

## 9. Evidencia de transacciones y rollback

No hay transacciones SQL, modificaciones visibles dentro de una transacción,
excepciones centinela, lecturas de observador ni rollback real ejecutado.
No se afirma nivel Laravel cero medido en una aplicación: la aplicación no arrancó.
No se consumieron valores AUTO_INCREMENT mediante fixtures o facturación.

## 10. Resultados de pagos e inventario

No evaluados mediante SQL. No se registraron pagos, movimientos de divisas,
consumos, stock, lotes o kárdex. V2 no fue activado. No hay importes o cantidades
reales del ensayo para conciliar.

## 11. Conciliación contable

No ejecutada. No se crearon períodos, asientos, detalles contables, comisiones
ni saldos sintéticos. No se modificaron políticas económicas o cuentas para
acomodar resultados. No se inventó ninguna aprobación AC-05.

## 12. Errores funcionales detectados

Ningún defecto nuevo confirmado mediante SQL en H05-V1. La limitación legacy de
todos los consumos explícitos cero, descrita en H05-R/V0, sigue como antecedente
que debe verificarse en el entorno descartable; no se declara reproducida ahora.
No se corrigieron H06, H04, H07, H09 ni otros módulos.

## 13. Archivos nuevos o modificados

Único archivo nuevo de H05-V1:

`docs/H05_V1_VALIDACION_SQL_INTEGRADA.md`.

Sin cambios de aplicación, helpers, configuración, tests, binarios, perfiles,
migraciones o evidencia. Los helpers coinciden con HEAD (`git diff --quiet`,
código 0) y tienen estas huellas para la revisión:

| Archivo | SHA256 |
| --- | --- |
| tests/Support/H05DisposableSafety.php | `3d1979361407d96bc52f02d9fd2c60a699dd5f347c95e0abd03c948b6d690f13` |
| tests/Unit/H05DisposableSafetyTest.php | `0a029c1aa3a5bfe367663ecab324757becd6c7bfb0b6ab1c6d54222713e7853d` |

No hubo escrituras Git, commits ni limpieza del árbol. No se crearon recursos
fuera del workspace para el ensayo. La única elevación autorizada se usó en
la consulta de procesos/listeners de solo lectura descrita en §2.

## 14. Estado final del entorno descartable

**No creado.** La raíz y su padre siguen ausentes. No existe servidor de ensayo,
PID propio, UUID, esquema, cuentas, DDL aplicado, fixtures, secretos, logs ni
archivos temporales de runner. No se destruyó ni restauró ningún entorno.
Los procesos MySQL existentes no se iniciaron, detuvieron o configuraron desde
esta intervención.

## 15. Verificación de ausencia de acceso del runner a bases históricas

No hubo runner, bootstrap Laravel, cliente MySQL de consultas ni conexión SQL.
Por tanto no se enviaron consultas ni escrituras desde el runner a salon_erp,
salon_erp_test o salon_erp_bootstrap_check. No se leyó .env/.env.testing ni se
inyectaron credenciales históricas en un proceso de prueba.

La inspección del host sí leyó configuración de puerto/datadir y metadatos de
directorios; eso queda distinguido de acceso SQL y de lectura de datos de tablas.
No se capturaron snapshots SQL ni hashes de filas antes/después: no se afirma
igualdad histórica medida por SQL ni ausencia de actividad de otros usuarios.
La preservación por esta intervención se sustenta en no iniciar operaciones
SQL ni alterar archivos de datos/configuración existentes.

## 16. Siguiente intervención recomendada

Resolver primero el requisito técnico de aislamiento de proceso/red. Presentar
un mecanismo concreto y revisable que limite solo el proceso de ensayo a 3307,
sin cambiar el firewall compartido ni permisos globales del sistema, y demostrar
sus controles de red y separación de secretos. Si ese mecanismo necesita
privilegios o recursos del sistema fuera de la raíz autorizada, solicitar
autorización específica **antes de aplicarlo**. No se solicita autorización para
modificar el firewall compartido: esa prohibición se conserva.

La autorización de crear el entorno ya recibida no necesita repetirse por sí
misma; para reanudar hacen falta la precondición demostrada y la resolución de
cualquier permiso adicional concreto. Repetir después el preflight de raíz,
puerto, enlaces, hashes y datadirs antes de crear recursos, sin fallback de
destino. Solo entonces continuar la secuencia H05-V1 ya autorizada.

No se recomienda todavía una corrección funcional nueva: faltan resultados
integrados para sustentarla. H05-V1 termina en esta parada documentada, sin
comenzar otra fase ni desplegar cambios.
