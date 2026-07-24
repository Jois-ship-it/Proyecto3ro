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
| Crear/configurar torneos | ✓ | ✓* | — | — |
| Inscribir participantes | ✓ | ✓* | — | — |
| Generar competencia | ✓ | ✓* | — | — |
| Cargar resultados | ✓ | ✓* | — | — |
| Corregir resultados | ✓ | ✓** | — | — |
| Ver torneos públicos | ✓ | ✓ | ✓ | ✓ |
| Ver resultados | ✓ | ✓ | ✓ | ✓ |
| Editar perfil | ✓ | — | ✓ | — |

`*` solo para torneos asignados   `**` si tiene autorización

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
