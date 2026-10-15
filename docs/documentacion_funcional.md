# Documentación Funcional — FlexArena

## Problema

Las organizaciones deportivas y educativas carecen de herramientas accesibles para gestionar torneos con múltiples formatos de competencia. La mayoría usa planillas Excel manuales, lo que genera errores y dificulta la consulta pública de resultados.

## Objetivos

1. Proveer una plataforma web modular para gestionar torneos deportivos, mentales y electrónicos.
2. Soportar tres formatos principales: Liga, Eliminación Directa y Sistema Suizo.
3. Permitir consulta pública de torneos, resultados y clasificaciones sin autenticación.
4. Mantener trazabilidad de todas las acciones mediante auditoría.
5. Desplegar el sistema con Docker de manera reproducible.

## Alcance

**Incluye:**
- Gestión de usuarios, roles, participantes y equipos
- Torneos individuales y por equipos
- Los tres formatos de torneo
- Carga y corrección controlada de resultados
- Vista pública de torneos y resultados
- Panel de administrador, organizador y participante
- Auditoría de acciones críticas

**No incluye:**
- Pagos, apuestas, redes sociales
- Arbitraje automático, IA embebida
- Venta de entradas, control de acceso físico

## Roles y permisos

| Acción | Admin | Organizador | Participante | Público |
|--------|-------|-------------|--------------|---------|
| Crear/editar usuarios | ✓ | — | — | — |
| Crear torneos | ✓ | — | — | — |
| Configurar torneos | ✓ | ✓* | — | — |
| Inscribir participantes | ✓ | ✓* | — | — |
| Generar competencia | ✓ | ✓* | — | — |
| Cargar resultados | ✓ | ✓* | — | — |
| Corregir resultados | ✓ | ✓** | — | — |
| Ver torneos públicos | ✓ | ✓ | ✓ | ✓ |
| Ver resultados | ✓ | ✓ | ✓ | ✓ |
| Editar perfil | ✓ | — | ✓ | — |
| Consultar reportes y exportarlos a CSV | ✓ | ✓* | — | — |

`*` solo para torneos asignados   `**` si tiene autorización

La columna del organizador no está clavada en el código: sale de la tabla
`permisos`, que el administrador edita desde **Sistema → Permisos**. Cada casilla
de esta matriz es una fila rol × módulo con cuatro acciones (ver, crear, editar,
eliminar). La autorización del `**` es exactamente la casilla
`resultados / editar` del rol organizador.

El administrador no aparece en la tabla a propósito: tiene control completo y
pasa sin consultarla, de modo que no hay forma de dejarlo sin acceso editando la
matriz. Ver `docs/documentacion_seguridad.md` → «Roles y permisos».

## Datos del evento y cierre de inscripción

Además de su configuración de competencia (formato, puntuación, fechas), cada
torneo puede llevar cuatro datos opcionales que se cargan en el mismo formulario
y se muestran en su ficha pública:

| Dato | Para qué |
|---|---|
| **Sede** | Dónde se juega. |
| **Contacto** | Correo de consulta para los participantes. |
| **Cierre de inscripción** | Último día para anotarse. |
| **Observaciones** | Reglamento, material a llevar, condiciones especiales. |

**El cierre de inscripción no es solo informativo.** Pasada esa fecha el sistema
rechaza nuevas inscripciones, tanto de participantes como de equipos, con un
mensaje que dice cuándo cerró. Cuatro aclaraciones:

- El último día cuenta: un torneo que cierra el 20 acepta inscripciones durante
  todo el 20.
- Dejarlo vacío significa «sin límite».
- En estado *borrador* no se aplica: el torneo todavía se está armando.
- Cerrar el plazo impide **anotarse**, no **retirarse**: alguien que ya no va a
  competir puede salir de la lista igual.

Si hace falta correr la fecha, un administrador la edita desde el formulario del
torneo. No puede quedar después de la fecha de inicio.

## Datos de prueba

`database/seed_demo.php` genera el juego de datos de demostración **usando los
servicios del dominio**, no INSERT sueltos: los torneos se juegan de verdad, los
brackets avanzan, las tablas se recalculan y los campeones se definen solos. Eso
hace que los datos de prueba sean consistentes con las reglas de negocio.

Volumen que deja (la letra exige un mínimo de 50 registros por componente):

| Componente | Registros |
|---|---|
| Usuarios | 60 |
| Participantes | 60 (52 con cuenta, 8 sin cuenta) |
| Equipos | 52 (208 integrantes) |
| Torneos | 52 (18 finalizados, 10 en curso, 16 en inscripción, 5 en borrador, 3 cancelados) |
| Inscripciones | 288 |
| Rondas | 106 |
| Enfrentamientos | 318 |
| Resultados | 264 |
| Tabla de posiciones | 118 |
| Configuraciones de torneo | 174 |
| Solicitudes de corrección | 55 (aprobadas, rechazadas y pendientes) |
| Auditoría | 879 |

**Excepción documentada: las tablas de catálogo.** `roles` (3), `tipos_torneo`
(3), `modulos` (9) y `permisos` (9) no llegan a 50 y no deberían: tienen tantas
filas como conceptos existen en el sistema —tres roles, tres formatos de torneo,
nueve módulos— y rellenarlas con entradas inventadas sería ruido, no datos de
prueba. `tests/datos_minimos_test.php` verifica que tengan exactamente la
cantidad esperada, así la excepción no se convierte en un agujero: si alguien
agrega un formato o un módulo, el test avisa.

## Formatos de torneo

### Liga (Round-robin)
- Todos contra todos.
- Fixture generado automáticamente.
- Tabla de posiciones: PJ, PG, PE, PP, PF, PC, Dif, Pts.
- Puntuación configurable: victoria/empate/derrota.
- Criterios de desempate: puntos → **diferencia → PF** → victorias → buchholz → ID.
  Los dos en negrita solo cuentan si el torneo tiene activado **«Desempatar por
  puntos a favor»**, una casilla del formulario. Conviene apagarla cuando el
  marcador no mide rendimiento (ajedrez: 1, ½ o 0), porque ahí «puntos a favor»
  repite lo que ya dice la columna de puntos. Apagada, la diferencia se sigue
  mostrando en la tabla; lo único que cambia es que no ordena.
- El ID del final no es un criterio deportivo: está para que el orden sea estable
  cuando ya no queda nada que comparar. Por eso, si los dos primeros empatan en
  todo lo demás, el sistema **no corona a nadie**: genera una ronda «Desempate»
  para que lo definan jugando.
- Campeón: posición 1 cuando todos los partidos están jugados y hay un líder
  claro.

### Eliminación Directa (Bracket)
- Potencia de 2 más cercana; byes automáticos para cantidades no potencia de 2.
- Ganadores avanzan automáticamente a la siguiente ronda.
- Rondas nombradas: Final, Semifinales, Cuartos, etc.
- Corrección bloqueada si el ganador ya avanzó a ronda posterior.
- Campeón: ganador de la final.

### Sistema Suizo
- Cantidad de rondas configurable.
- Emparejamiento por score (sin repetición de rivales).
- Bye para cantidad impar: preferir participante de menor score sin bye previo.
- Puntos de bye configurables: 0, equivalente a victoria, o personalizado.
- Ranking: puntos → victorias → diferencia → PF → buchholz → ID.
- Corrección bloqueada en las rondas anteriores a la última generada: el
  emparejamiento de la ronda siguiente se armó con esos puntajes.
- Campeón: posición 1 del ranking al completar todas las rondas.

## Flujo de carga de resultado

1. Organizador selecciona un partido pendiente y presiona "Cargar resultado".
2. Ingresa los puntos de cada participante.
3. Sistema valida (no negativos, empates según config).
4. Sistema guarda resultado y actualiza estado del enfrentamiento.
5. Sistema recalcula tabla/ranking según formato.
6. Sistema registra en auditoría.
7. Mensaje de confirmación.

## Flujo de corrección de resultado

1. Usuario autorizado selecciona "Corregir" en un partido finalizado. El
   administrador corrige directamente; el organizador ve "Solicitar corrección"
   (solo en los partidos de sus torneos): pide, no aplica, y un administrador
   aprueba o rechaza la solicitud.
2. Sistema verifica que la corrección es segura (no rompe rondas posteriores).
   La misma verificación corre al pedirla y al aplicarla, así una solicitud que
   no se va a poder aprobar no llega a registrarse.
3. Usuario ingresa nuevos valores y motivo obligatorio.
4. Sistema guarda valores anteriores y nuevos, recalcula.
5. Registro de auditoría con valores antes/después.

6. Si el torneo ya estaba **finalizado**, el sistema vuelve a decidir quién lo ganó con la
   misma regla de siempre. Si el líder cambió, el campeón pasa a ser el nuevo (queda en la
   auditoría con el anterior y quien corrige recibe un aviso aparte). Si la corrección deja a los
   dos primeros empatados en todo, el torneo vuelve a «En curso», sin campeón, con un partido
   de desempate. En Eliminación Directa solo la final puede cambiar al campeón (un partido anterior
   no se corrige una vez que su ganador avanzó).
7. Un **empate** no tiene ganador ni perdedor. Corregir (y pedir una corrección) respeta la misma
   regla que cargar: Eliminación Directa no admite empates, y Liga y Suizo solo si el torneo los permite.

Si el torneo avanza mientras una solicitud espera, la aprobación falla con el
motivo del bloqueo y la solicitud queda pendiente: el administrador la rechaza
indicando por qué. El sistema no la resuelve por su cuenta.


## Búsqueda y paginación de las listas

Las listas largas se pueden buscar, filtrar y recorrer por páginas, sin traer todos los datos de una vez.

| Lista | Busca por | Filtra por | Por página |
|---|---|---|---|
| Torneos públicos (`/torneos`) | nombre | formato, estado (inscripción, en curso, finalizado) | 12 |
| Torneos del panel | nombre | formato, estado | 15 |
| Participantes | nombre, apodo, documento, correo | estado | 15 |
| Equipos | nombre, categoría, disciplina | estado | 15 |
| Organizadores y Administradores | nombre, correo | estado | 15 |
| Auditoría | usuario, descripción, tabla | acción, desde, hasta | 50 |

La búsqueda ignora mayúsculas y tildes, y busca «contiene». Los caracteres `%` y `_` se buscan tal cual. La lista
pública nunca muestra borradores, torneos privados ni cancelados, ni siquiera filtrando por ellos. Un
organizador solo ve sus torneos aunque busque el nombre de uno ajeno.

La lista pública además se actualiza **mientras se escribe** (o se cambia el formato o el estado), sin recargar la
página y sin apretar «Buscar». Es una mejora: con JavaScript apagado el formulario funciona igual, y la dirección
(`/torneos?q=liga&tipo=liga`) se puede copiar y abrir de nuevo con el mismo resultado.

## Panel del participante

«Mi panel» muestra, además del perfil y los torneos, lo que el participante necesita saber hoy:

- **Próximos partidos**: contra quién juega, en qué torneo y ronda, y cuándo si ya tiene fecha.
- **Últimos resultados**: victoria, empate o derrota con el marcador, del más reciente al más viejo.
- **Mi posición**: su lugar en la tabla de cada liga o suizo en que compite («2.º de 4») y, en una llave, si sigue en
  carrera, si es campeón o en qué ronda quedó afuera.

Si compite con un equipo, el panel lo dice. Cada fila lleva a la ficha del torneo.

## Configuración del sistema

El administrador cambia desde **Sistema → Configuración** el nombre de la plataforma, el correo de contacto del
pie, si los perfiles de participantes y equipos se ven sin iniciar sesión, y los límites del inicio de sesión
(intentos antes de bloquear, duración del bloqueo, intentos por dirección IP y su ventana). Si un valor no sirve no
se guarda nada y la pantalla muestra el motivo sin perder lo escrito. Cada cambio queda en la auditoría.

## Fotos de perfil y logos

Al subir una foto o un logo, el sistema la reduce y la vuelve a guardar: no hace falta achicarla antes. Se aceptan
JPG, PNG, WEBP y GIF de hasta 2 MB y 16 megapíxeles.

## API pública y carga por fetch

- **API de solo lectura** para consultar los torneos desde otra aplicación o un script, sin iniciar sesión:
  la lista (con búsqueda y filtros), la tabla de posiciones y el calendario de cada torneo. Muestra lo mismo que
  las páginas públicas. Ver `docs/api.md`.
- **Cargar resultados sin recargar la página**: al cargar, corregir, pedir una corrección o programar un partido,
  si el servidor rechaza lo pedido (marcador inválido, ronda cerrada…) el motivo aparece dentro del diálogo, que
  sigue abierto con lo escrito; si sale bien, la página se actualiza con el aviso de siempre.

## Reportes

Letra §5.1 «acceder a reportes» (administrador) y §5.2 «consultar reportes del torneo» (organizador).

1. **Reporte general** (solo administrador, *Sistema → Reportes*): totales del sistema, torneos por estado y
   formato, correcciones por estado, cuentas, y la tabla de todos los torneos con sus partidos jugados y su campeón.
2. **Reporte de un torneo** (administrador en cualquiera; organizador en los suyos): resumen, avance,
   tabla de posiciones (liga y suizo) y partidos.
3. **Salida**: se puede **imprimir o guardar en PDF** (el navegador arma la hoja) y **descargar en CSV**
   (torneos, posiciones, partidos, inscritos). Es la funcionalidad opcional de la letra §14.

Criterios de las cifras: un **partido** es un enfrentamiento que se juega; no cuenta un *bye* (un lado sin rival,
que pasa de ronda sin jugar) ni uno cancelado. Un **empate** se reconoce por los puntos. Las cifras del reporte
general son la suma de las de cada torneo, y los puntos anotados de una liga coinciden con los puntos a favor
de su tabla (`tests/reportes_test.php` lo comprueba).
