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
- Criterios de desempate: puntos → diferencia → PF → victorias → resultado directo → ID.
- Campeón: posición 1 cuando todos los partidos están jugados.

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

1. Usuario autorizado selecciona "Corregir" en un partido finalizado.
2. Sistema verifica que la corrección es segura (no rompe rondas posteriores).
3. Usuario ingresa nuevos valores y motivo obligatorio.
4. Sistema guarda valores anteriores y nuevos, recalcula.
5. Registro de auditoría con valores antes/después.
