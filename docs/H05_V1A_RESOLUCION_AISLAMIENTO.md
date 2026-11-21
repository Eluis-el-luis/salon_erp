# H05-V1A — Resolución del requisito de aislamiento

Fecha: 21 de noviembre de 2026. **Decisión: CONDICIONADA.**

Hay una vía nativa compatible con los binarios Windows existentes: filtros WFP
acotados a un ejecutable PHP exclusivo, con supervisor y restricciones de proceso.
Requiere implementación revisada, autorización específica para objetos temporales
del motor BFE y pruebas de enforcement. **No hay aislamiento aplicado ni H05-V1
habilitado al terminar este diagnóstico.** Si no se autoriza esa intervención
acotada sobre BFE, el ensayo nativo permanece bloqueado con los permisos actuales.

Se revisaron AGENTS.md, H05-V0, el informe de parada H05-V1 y
tests/Support/H05DisposableSafety.php. No se repitieron el diagnóstico del carrito,
el diseño de fixtures ni escenarios de facturación.

## 1. Causa exacta de la parada

Configurar DB_PORT=3307, usar credenciales nuevas o verificar un UUID no impide
que PHP abra otro socket. Los guardias H05-V0 comprueban datos de configuración;
no limitan Winsock ni los PDO que se construyan fuera del administrador Laravel.
H05-V1 requería una barrera real que permitiera el endpoint de ensayo y negara
3306 y otros destinos **antes** de iniciar escrituras.

La falta era de enforcement por proceso, no de disponibilidad del binario MySQL
ni de autorización para crear el entorno descartable. No se utilizó la red
restringida genérica del agente como prueba de una lista permitida por puerto.

## 2. Capacidades comprobadas en este equipo

Inspecciones de solo lectura realizadas: Get-CimInstance para sistema/características,
Get-Service, Get-Command, metadatos de archivos y registro de desinstalación;
wsl --list --verbose; VBoxManage --version/list vms/list runningvms/showvminfo.
La inspección de Windows/servicios/WSL fuera del sandbox recibió autorización
de lectura; no habilitó cambios administrativos. No se iniciaron invitados ni
se habilitaron características o servicios mediante comandos de administración.

| Capacidad | Evidencia obtenida | Alcance de la conclusión |
| --- | --- | --- |
| Sistema | Microsoft Windows 10 Home, 10.0.19045, 64 bits | Edición y compilación identificadas |
| Hipervisor | Win32_ComputerSystem.HypervisorPresent=true | Hay hipervisor presente; no demuestra Hyper-V completo ni VM utilizable |
| Campos de CPU | VirtualizationFirmwareEnabled, SLAT y VMMonitorModeExtensions devolvieron false | No concluir que debe activarse BIOS: con hipervisor presente estos campos no acreditan por sí solos capacidad física disponible al invitado |
| Características | Microsoft-Windows-Subsystem-Linux y VirtualMachinePlatform: InstallState=1; HypervisorPlatform=2 | WSL/VMP habilitados; HypervisorPlatform deshabilitado según CIM |
| Hyper-V completo | No se encontró módulo Hyper-V, servicio vmms ni característica Hyper-V en el resultado consultado | No hay infraestructura completa comprobada para gestionar una VM Hyper-V |
| Windows Sandbox | WindowsSandbox.exe no localizado; característica Containers-DisposableClientVM no apareció | No disponible en la instalación observada; edición Home tampoco es compatible oficialmente |
| WSL | wsl.exe existe; --list --verbose informó que no hay distribuciones instaladas | No hay Linux de pruebas listo; no se ejecutó --install ni --list --online |
| Servicios | BFE, MpsSvc, hns, vmcompute y WslService en ejecución; LxssManager detenido en la consulta | Estado observado; ninguno se configuró/habilitó para el ensayo |
| WFP/AppContainer | fwpuclnt.dll, userenv.dll y CheckNetIsolation.exe presentes | Componentes nativos identificados, no filtros/perfiles aplicados |
| Token de la terminal ordinaria | ElevatedAdministratorToken=false | No se acreditaron permisos efectivos para añadir objetos WFP desde esa terminal |
| Podman/Docker | Sin comandos en PATH ni instalación encontrada en claves de desinstalación/rutas usuales inspeccionadas | No disponibles como alternativa comprobada; no es un inventario exhaustivo de todo el disco |
| Otros aisladores | No se encontró Sandboxie en las rutas y registro consultados | No se supone instalación por posibilidades teóricas |
| VirtualBox | Registro, binarios y VBoxManage confirman Oracle VirtualBox 7.2.6r172322 | Instalado realmente; no solo un nombre de ejecutable |
| VMs existentes | Tres registros, todos poweroff: dos Debian (64-bit) y uno FreeBSD (64-bit) | No hay invitado Windows listo entre los registros inspeccionados; no se arrancaron |
| Redes de esas VMs | Una Debian tiene intnet, otra NAT; FreeBSD tiene NAT e intnet | No son automáticamente enclaves sin acceso al host; no se cambiaron sus redes |

Los nombres de registro de esas VMs son kali-linux-2025.4-virtualbox-amd64,
PFSensexd y eldebianxd(pc1). El tipo de SO es metadata de VirtualBox; no se
inspeccionó el SO invitado ni su software. No se reutilizarán laboratorios
existentes ni sus credenciales para pruebas empresariales.

Un intento de lectura complementaria del registro XML de VirtualBox mediante
GetFolderPath(UserProfile) no pudo resolver esa ruta en el entorno del agente.
No se usa esa lectura fallida como evidencia; el inventario anterior procede
de VBoxManage, que sí devolvió estados y configuración. Sus consultas pueden
activar el proceso auxiliar de gestión de VirtualBox; no equivalen a iniciar
una VM ni a habilitar un servicio persistente.

## 3. Alternativas técnicamente viables, con sus condiciones

| Alternativa | Defensa frente a 3306 / acceso de ensayo | Privilegios e impacto | PHP/MySQL/Laravel | Preparación, directorios y reversión |
| --- | --- | --- | --- | --- |
| **WFP por ejecutable exclusivo** | Filtros de denegación del complemento de TCP IPv4 127.0.0.1:3307, incluyendo IPv6; debe probarse loopback | Broker elevado o derechos WFP específicos. Añade objetos a BFE compartido; no es permiso ya otorgado. No exige editar reglas/perfiles persistentes existentes | Conserva PHP 8.3 y MySQL 8.4.3 Windows y kernel Laravel en proceso | Implementación nativa y revisión de ciclo de vida; copia PHP propia, token no elevado, entorno sin secretos; retirar solo IDs de objetos propios después de terminar procesos |
| **Nueva VM Windows VirtualBox sin NIC** | El localhost invitado no es el host; sin NIC no hay ruta al MySQL histórico. No acredita por sí sola una lista de puertos dentro del invitado | Crear VM nueva y disponer de medio/licencia Windows; no modificar VM/red existentes ni firewall host | Puede usar los mismos binarios Windows, previa comprobación de runtimes y extensiones | Mayor costo: disco/guest nuevo, importación offline sin carpetas host amplias. Borrado de VM/disco solo con autorización separada |
| WSL2 / VM Linux aislada | Un namespace Linux sin salida y PHP/MySQL dentro puede aislarlos; NAT ordinario no basta | Falta distribución o entorno preparado; instalar paquetes y autorizar recursos nuevos | PHP/Laravel posibles; **no** reutiliza directamente mysqld.exe de Laragon como motor Linux. Ejecutar binarios Windows por interoperabilidad no los encierra en el namespace Linux | Requiere cambiar el supuesto de plataforma y aislar montajes/interop/credenciales; no es la vía inmediata de H05-V1 |
| Podman con PHP/MySQL en namespace sin salida | Conceptualmente válido con ambos procesos dentro y sin red host; no usar host networking | Falta instalación y máquina backend comprobada | Linux compatible con versiones fijadas, pero no con reutilización directa del motor Win64 exigido | Descargas, imágenes y entorno adicionales; mantener fuera por ahora |

La opción VirtualBox no se descarta por edición Home: está instalada. Se
condiciona por falta de invitado Windows aprobado y porque no se puede arrancar,
clonar o cambiar las máquinas existentes sin autorización. Una VM sin NIC
protege el host, pero no demuestra que un proceso solo pueda abrir **un puerto
local invitado**: si se exige también esa condición literal, necesita control
interno adicional. No sustituir silenciosamente el endpoint host por localhost
invitado: cambia identidad/ruta/UUID esperados y necesita autorización explícita.

Oracle documenta los modos de adaptador y distingue redes internas de host-only;
esta última conecta al host y no sirve como aislamiento del MySQL histórico.
[Manual Oracle VirtualBox 7.2](https://download.virtualbox.org/virtualbox/7.2.0/UserManual.pdf).

WSL NAT permite llegar a servicios Windows mediante la IP del host: no se debe
presentar una distribución recién instalada como barrera suficiente.
[Microsoft: red de WSL](https://learn.microsoft.com/en-us/windows/wsl/networking).

## 4. Alternativas descartadas para aplicación inmediata

- **Windows Sandbox:** no disponible; Microsoft excluye Home. Su parámetro
  Networking deshabilita el adaptador externo, no proporciona una lista de
  puertos dentro del invitado. Mapear el ERP o el perfil completo expondría datos;
  cerrar Sandbox también descarta su estado. No habilitarlo para explorar.
  [Microsoft: disponibilidad de Sandbox](https://learn.microsoft.com/en-us/windows/security/application-security/application-isolation/windows-sandbox/windows-sandbox-overview).
- **Hyper-V completo:** Home no admite instalar ese rol. VMP/WslService no
  prueban que esté disponible el gestor Hyper-V.
  [Microsoft: requisitos de Hyper-V](https://learn.microsoft.com/en-us/windows-server/virtualization/hyper-v/get-started/Install-Hyper-V).
- **AppContainer sin más controles:** aísla por capacidades, pero su permiso
  de red/loopback no es una autorización nativa limitada a 3307. Una excepción
  LoopbackExempt no sirve como whitelist de ese puerto. Requiere perfil, ACL y
  compatibilidad Win32 comprobados; no se ha creado ninguno. Las guías modernas
  de Win32 isolation no se suponen aplicables íntegramente a esta compilación.
  [Microsoft: AppContainer](https://learn.microsoft.com/en-us/windows/win32/secauthz/appcontainer-isolation),
  [Microsoft: loopback UWP](https://learn.microsoft.com/en-us/windows/security/operating-system-security/network-security/windows-firewall/troubleshooting-uwp-firewall).
- **Job Object, usuario no administrador o token restringido por sí solos:**
  no constituyen firewall por endpoint. Son controles complementarios de proceso.
- **Regla ordinaria de Windows Firewall sobre php.exe de Laragon:** modifica
  infraestructura compartida y puede afectar al ERP u otros proyectos. No se
  aplica ni se propone como permiso implícito. Tampoco se presume que una regla
  común cubra correctamente loopback sin verificarlo.
- **Variables Laravel, bloqueo en PDO, php.ini o mocks:** no restringen todas
  las vías de sockets del proceso. Se conservan como capas adicionales.
- **VM con NAT/bridge/host-only o red intnet existente:** no se acepta sin revisar
  todas las rutas/adaptadores, appliances y montajes; no tocar laboratorios ajenos.

## 5. Mejor mecanismo recomendado: WFP acotado y supervisor

### Qué ofrece y qué exige

WFP está soportado por el Windows observado, BFE está ejecutándose y permite
filtrar en ALE por identidad de aplicación. Añadir filtros requiere derechos
del motor, normalmente elevación administrativa. La presencia de DLL/servicio
es evidencia de capacidad, **no una prueba de autorización efectiva o de bloqueo**.
[Microsoft: arquitectura y seguridad WFP](https://learn.microsoft.com/en-us/windows/win32/fwp/about-windows-filtering-platform),
[Microsoft: control de acceso WFP](https://learn.microsoft.com/en-us/windows/win32/fwp/access-control).

El helper futuro debe ser pequeño, revisable y exclusivo del ensayo. Dos roles:

1. Broker privilegiado: crea únicamente sus objetos WFP identificados y supervisa
   al trabajador; no carga Laravel ni credenciales MySQL.
2. PHP trabajador no elevado: copia propia bajo una raíz aprobada nueva, entorno
   explícito y sin secretos/handles administrativos heredados. Ejecuta primero
   las sondas sin SQL; el kernel de H05 se usará solo después en su fase autorizada.

**No se filtra el php.exe compartido de Laragon.** La identidad WFP se obtiene
de la ruta normalizada de la copia exclusiva mediante la API apropiada, no
de una comparación informal del nombre del proceso. Todos los procesos que
usen esa ruta quedan dentro del alcance: un AppID de ruta no equivale a un PID.
Fijar su hash, rechazar enlaces/reemplazos y no usar esa copia para otras tareas.

### Política mínima a revisar, todavía no aplicada

Para AppID=P (copia PHP exclusiva), denegar en ALE_AUTH_CONNECT:

```text
IPv4: P y protocolo distinto de TCP               -> BLOCK
IPv4: P y dirección remota distinta de 127.0.0.1   -> BLOCK
IPv4: P y puerto remoto distinto de 3307           -> BLOCK
IPv6: P, cualquier destino/protocolo/puerto         -> BLOCK
```

Son condiciones de bloqueo del **complemento** del endpoint permitido. No
usar «bloquear todo y añadir un allow» suponiendo que prevalecerá el allow.
No añadir permisos duros para saltarse decisiones de otros proveedores. El tuple
autorizado solo queda libre de estos bloqueos propios; si otra política lo
deniega, falla la prueba positiva y se detiene, sin cambiar reglas compartidas.
[Microsoft: arbitraje de filtros](https://learn.microsoft.com/en-us/windows/win32/fwp/filter-arbitration).

Se debe revisar y probar la disponibilidad/tipo/endianness de cada condición
en ambas capas, incluidas rutas dual-stack. Se niega IPv6 completo para evitar
::1 o direcciones IPv4-mapped como salida alternativa; el cliente autorizado
debe usar IPv4 explícito. No excluir tráfico marcado loopback de estos filtros.
Denegar escucha/aceptación no solicitada por PHP si el helper incluye esos
controles: el kernel Laravel en proceso no requiere un servidor HTTP de red.
[Microsoft: capas ALE](https://learn.microsoft.com/en-us/windows/win32/fwp/ale-layers),
[Microsoft: condiciones WFP](https://learn.microsoft.com/en-us/windows/win32/fwp/filtering-condition-identifiers-).

### Vida útil y fallo del supervisor

No se recomienda como primera implementación que la barrera principal desaparezca
automáticamente al morir el broker: los filtros de una sesión dinámica se borran
al cerrar esa sesión. Un PHP sobreviviente podría quedar sin restricciones.

Para el prototipo se propone **objetos estáticos volátiles, no persistentes**,
acotados a P, conservados hasta una retirada explícita. No es una política
permanente ni un objeto con flag PERSISTENT. Si el broker falla, sus filtros
permanecen mientras BFE siga activo; al salir normalmente se eliminan solo tras
confirmar la terminación del trabajador. Esto requiere permiso específico para
limpiar esos objetos y aceptar que pueden quedar residuos propios tras un fallo.
[Microsoft: vida útil de objetos WFP](https://learn.microsoft.com/en-us/windows/win32/fwp/object-management).

Crear trabajador suspendido, asignarlo a Job Object sin breakaway y con
KILL_ON_JOB_CLOSE, instalar/verificar todos los filtros en transacción y solo
entonces reanudarlo. Los handles de supervisión no deben heredarse. El job es
complementario; no reemplaza los filtros. Se debe comprobar compatibilidad con
cualquier job exterior de la herramienta antes de aplicar.
[Microsoft: Job Objects](https://learn.microsoft.com/en-us/windows/win32/procthread/job-objects).

Restringir creación de hijos para impedir que PHP ejecute otro binario sin
AppID filtrado. La política nativa de hijos y los derechos sobre otros procesos
requieren verificación; no bastan disable_functions de PHP ni solo un Job Object.
Microsoft advierte que handles privilegiados pueden permitir eludir restricciones
de hijos. Si no se acredita esa contención, no declarar acceso exclusivo del
proceso/árbol y detener el prototipo.
[Microsoft: atributos de creación de procesos](https://learn.microsoft.com/en-us/windows/win32/api/processthreadsapi/nf-processthreadsapi-updateprocthreadattribute).

Una parada/reinicio de BFE puede retirar objetos volátiles; no se certifica
continuidad ante un administrador que modifique el motor ni caída de BFE. No
reiniciar servicios para ensayarlo. La estabilidad y supervisión del motor son
precondiciones; si el requisito exige garantías incluso durante esa avería,
esta vía necesita un diseño adicional y no se acepta todavía como APTA.

## 6. Evidencia disponible: A / B / C

| Nivel | Resultado de H05-V1A |
| --- | --- |
| **A. Inspección** | Edición/build, características, servicios, DLL, token ordinario, inventario WSL/VirtualBox y redes configuradas comprobados; documentación primaria de APIs revisada |
| **B. Restricción aplicada por SO** | **Ninguna nueva**: no hay filtros WFP, AppContainer propio, VM aislada ni whitelist demostrada. No se lanzaron sockets de prueba |
| **C. Simulación pura** | Los helpers V0 siguen siendo predicados sin I/O de red; no se convirtieron en enforcement. No se añadieron/ejecutaron nuevos mocks en V1A |

H05DisposableSafety.php conserva su hash
`3d1979361407d96bc52f02d9fd2c60a699dd5f347c95e0abd03c948b6d690f13` y coincide
con HEAD, comprobado mediante git diff --quiet con código 0. El informe previo
H05-V1 ya estaba sin seguimiento al iniciar; no se modificó.

No se concluye que las APIs funcionarán con este PHP solo porque Windows las
documente. Es precisamente el motivo de la decisión CONDICIONADA y del ensayo
de aceptación sin SQL que debe preceder a la emisión.

## 7. Cambios y permisos necesarios

Ninguno aplicado ahora. Para un ensayo de aislamiento posterior, separado de
H05-V1, se propone raíz nueva:

`E:/salon-erp-h05-isolation/run-h05v1a`

Es una propuesta, no un recurso creado o ya autorizado por el permiso anterior
del datadir. No ocupa `E:/salon-erp-h05-disposable/run-h05v1`, que debe seguir
ausente hasta su preflight de creación. Cambios concretos a autorizar:

- Crear esa raíz exclusiva con helper fuente/binario, manifiesto, copia PHP/DLL,
  scripts de sonda y logs mínimos propios. Rechazar raíces previas o enlaces.
- Crear token/job del trabajador y ACL necesarias **solo en recursos nuevos**.
  Nada de usuarios Windows nuevos, grupos, políticas o ACL del ERP/Laragon.
- Autorizar broker elevado para añadir provider/sub-layer/filtros WFP propios,
  GUID y AppID exactos, sin editar objetos de otros proveedores. Registro de IDs
  y retirada individual de esos mismos objetos tras terminar el trabajador.
- Autorizar un listener TCP sintético en 127.0.0.1:3307 y un puerto negativo
  controlado que se encuentre libre, por ejemplo 3308. No son MySQL ni HTTP:
  solo intercambian un nonce de sonda. No conectarse a 3306.
- Revisar aislamiento de archivos/credenciales: copiar una fuente sin .env,
  limpiar entorno, no heredar handles y demostrar que PHP no puede abrir un
  archivo canario fuera de los directorios permitidos. Una copia limpia y
  variables vacías no demuestran que todo archivo histórico sea inaccesible.
  Si hacen falta permisos fuera de recursos nuevos, parar y pedirlos.

El estado WFP pertenece al motor compartido de Windows. Aunque su efecto esté
acotado y no se editen reglas persistentes de Defender Firewall, **sí es un
cambio de filtrado del sistema**. No se interpreta la autorización V1 como
permiso para realizarlo. Si el propietario mantiene prohibición absoluta de
añadir cualquier objeto a BFE, no aplicar esta opción.

## 8. Riesgos residuales

- Broker incorrecto, scope de ruta amplio o filtros incompletos pueden afectar
  otro PHP o dejar salidas sin filtrar. Requiere revisión antes de elevarlo.
- Validar IPv4, IPv6, puertos, protocolos, loopback, identidad del ejecutable,
  proceso hijo y fallos; una sola conexión denegada no demuestra la política.
- Un error de conexión por falta de listener o por credenciales no prueba WFP.
  Se necesitan correlación de denegación con filtros propios y controles positivos.
- Job/token son controles complementarios. No se ha demostrado contención de
  hijos, handles privilegiados ni lectura de secretos; no exagerar el alcance.
- Los objetos volátiles propios podrían quedar tras caída del broker. Su retiro
  necesita el manifiesto y autorización limitada; no hacer limpieza global.
- No se garantiza aislamiento frente a administrador/servicio de seguridad que
  altere BFE. El proceso trabajador nunca debe recibir esos privilegios.
- Una VM sin NIC protege el host, pero usar carpetas compartidas amplias,
  clipboard o montajes con secretos puede destruir el aislamiento de datos.
- WSL/Podman requieren plataforma/datos nuevos; interop con Windows o NAT puede
  devolver acceso al host. No usarlo como atajo para reutilizar mysqld.exe.

## 9. Verificación positiva/negativa propuesta, sin SQL

**No ejecutada en V1A.** Requiere la autorización específica siguiente.

1. Comprobar hashes/raíces, puertos libres y políticas objeto propias antes de
   crear procesos. Revisar el helper completo; no elevar código generado sin
   revisión. Confirmar que solo PHP copiado tiene scope de filtros.
2. Usar listener de nonce en 127.0.0.1:3307. Establecer un control no restringido
   hacia ese listener. Repetir desde PHP trabajador con filtros: debe conectar
   y recibir el nonce correcto. No iniciar MySQL.
3. Usar listener de nonce en 127.0.0.1:3308, solo si está libre. Control no
   restringido debe alcanzarlo; PHP restringido debe recibir denegación del SO,
   sin nonce ni accept del listener. Registrar filtro propio que decidió BLOCK.
4. **No lanzar conexión contra 127.0.0.1:3306**, ni siquiera sin contraseña.
   Para ese tuple exacto enumerar las condiciones/filtros mediante las APIs de
   consulta WFP, sin presentar esa enumeración como una conexión denegada real.
   No se identificó ni se propone aquí una API de simulación offline como
   sustituto de una decisión observada del kernel. La política por complemento
   cubre todos los puertos salvo 3307;
   demostrarla en puerto sintético no implica una prueba de conexión real a 3306.
   Si se exige evidencia de paquete denegado en 3306, requiere un entorno donde
   3306 sea un listener sintético y no el servidor histórico, con autorización
   separada; no probarlo silenciosamente aquí.
5. Comprobar denegación IPv6 y UDP con listeners sintéticos y controles, sin
   tocar destinos empresariales. Para no-loopback/red remota, inspeccionar las
   reglas efectivas y, si se exige una prueba de tráfico, solicitar un destino
   controlado autorizado después. No sondear IPs remotas arbitrarias.
6. Ensayar que el trabajador no crea hijos fuera del alcance ni hereda sockets,
   handles de broker o credenciales. Comprobar solo con canarios sintéticos el
   acceso denegado a archivos fuera de la lista permitida; no leer .env real.
7. Terminar de forma controlada el broker de ensayo y verificar que no queda
   trabajador desprotegido: job terminado y/o filtros volátiles aún presentes.
   Comprobar ausencia de escape en el intervalo. No detener BFE ni MpsSvc.
8. Parar/esperar todos los procesos de prueba antes de retirar solo los IDs WFP
   propios. Comprobar ausencia de objetos residuales y que el listener control
   conserva su comportamiento. Cerrar solo listeners nuevos. Conservar archivos
   y manifiesto; no borrar VM/datadir ni directorios automáticamente.

La parte no ensayada con tráfico debe registrarse como tal, sin inventar un
simulador que se presente como kernel. La aceptación se decidirá sobre la
evidencia disponible y el requisito exacto,
sin afirmar APTA únicamente por los predicados puros.

## 10. Autorización exacta necesaria para aplicarlo

Propuesta de permiso posterior, **no recibida ni aplicada en V1A**:

> Autorizo desarrollar y revisar un prototipo de aislamiento sin SQL en
> E:/salon-erp-h05-isolation/run-h05v1a, con copia exclusiva de PHP y recursos
> nuevos. Autorizo expresamente un broker elevado para añadir únicamente sus
> objetos WFP volátiles no persistentes, identificados por GUID/manifiesto y
> acotados al AppID de esa copia, permitiendo solo el tuple TCP IPv4
> 127.0.0.1:3307 frente a sus filtros y bloqueando el resto. Reconozco que esto
> añade filtros al motor compartido BFE; no autoriza editar reglas/perfiles
> persistentes existentes, GPO, servicios, políticas globales o ACL históricas.
> Autorizo token/job y ACL solo de recursos nuevos, listeners de nonce en
> 3307/3308 si están libres y pruebas controladas de denegación sin SQL, sin
> conexiones al servidor histórico ni destinos externos. Autorizo retirar solo
> los objetos WFP propios después de confirmar el fin de los procesos de ensayo.
> Ante scope inesperado, acceso externo, hijo no contenido o falta de permisos,
> detenerse sin ampliar privilegios. No autorizo crear MySQL, VM, contenedor,
> usuario Windows, cargar fixtures ni reanudar automáticamente facturación.

Debe existir una implementación concreta revisable antes de pedir su elevación.
Este diagnóstico no adjunta un launcher WFP para ejecutar sin revisión. El
permiso anterior es más específico y distinto del ya recibido para consultar
capacidades del host o crear el datadir descartable de H05-V1.

Si no se acepta ningún cambio BFE, la alternativa exige autorizar una **nueva**
VM Windows sin red, medios/licencia y transferencia offline, con contrato de
endpoint/paths distinto y control interno de puertos si se mantiene esa exigencia.
No cambiar el diseño a esa VM sin nueva decisión del propietario.

## 11. Condiciones para reanudar H05-V1

- Decisión explícita sobre el mecanismo y permisos concretos adicionales.
- Helper revisado, barrera SO aplicada al trabajador y verificación positiva/
  negativa documentada, sin confundir listener ausente o modelo puro con bloqueo.
- Contención de hijos/handles/secretos y ciclo de vida aceptados. Ningún test
  empresarial debe arrancar si la barrera desaparece o su scope no coincide.
- Resolver expresamente el nivel de evidencia para 3306 sin acceder al servidor
  histórico: esa prohibición sigue vigente.
- Repetir preflight de raíz/puerto/identidad y aplicar los guardias H05 existentes.
  La autorización anterior de MySQL no necesita repetirse por sí misma, pero no
  habilita estos cambios de filtrado ni elimina las precondiciones.

V1A termina con diagnóstico **CONDICIONADO**. No hay evidencia nivel B nueva,
no se declara el requisito satisfecho y no se inicia H05-V1.

## 12. Archivos creados o modificados y preservación

Único archivo nuevo de esta intervención:

`docs/H05_V1A_RESOLUCION_AISLAMIENTO.md`.

El informe H05-V1 sin seguimiento ya existía; se conservó intacto. No se
modificaron helpers V0 ni código funcional. No hubo SQL, tráfico de prueba a
3306, DDL/DML, migraciones, seeders, cuentas, filtros, contenedores o invitados
nuevos, cambios de configuración de Laragon o evidencia AC-04/AC-05.

La raíz MySQL descartable sigue ausente. No se crearon recursos del prototipo
WFP ni se instalaron características. No se ejecutaron operaciones Git de
escritura ni commits. La verificación de entrega es documental y de inspección;
no se reportan pruebas de enforcement o facturación aprobadas.
