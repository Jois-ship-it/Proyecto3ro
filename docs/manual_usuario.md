# Manual de Usuario — FlexArena

## Iniciar sesión

1. Ir a `http://localhost:8080/login`
2. Ingresar email y contraseña
3. Hacer clic en **Ingresar**

Las credenciales de prueba están en el README principal.

## Vista pública (sin login)

- **Inicio** (`/`): Presentación del sistema y torneo destacado.
- **Torneos** (`/torneos`): Lista de torneos públicos con filtros por formato y estado.
- **Detalle de torneo** (`/torneo/{id}`): Tabla de posiciones, bracket o ranking según formato.

## Panel del participante

1. Iniciar sesión con rol participante
2. Ver torneos en los que estás inscrito
3. Consultar resultados y posición actual
4. Editar datos básicos de perfil en `/participante/perfil`

## Crear un torneo (Admin/Organizador)

1. Ir a **Torneos → Nuevo torneo**
2. Completar nombre, formato y modalidad
3. Configurar puntuación (liga/suizo) o rondas (suizo)
4. El formulario muestra/oculta campos según el formato
5. Guardar → el torneo queda en estado **Borrador**

## Inscribir participantes

1. Ir a **Torneos → [nombre del torneo] → Gestionar**
2. En la sección "Inscripciones" seleccionar un participante o equipo
3. Clic en **Inscribir**
4. Repetir para todos los participantes/equipos

## Generar competencia

1. Con al menos 2 inscritos y torneo en estado **Borrador** o **Inscripción**:
2. Clic en **Generar competencia**
3. Confirmar en el diálogo
4. El sistema genera el fixture/bracket/primera ronda automáticamente

## Cargar resultados

1. En la gestión del torneo, buscar un partido **Pendiente**
2. Clic en **Cargar resultado**
3. Ingresar los puntos de cada participante/equipo
4. Confirmar
5. La tabla se actualiza automáticamente

## Corregir un resultado

1. Buscar el partido con estado **Finalizado**
2. Clic en **Corregir**
3. Modificar los valores
4. Ingresar el **motivo obligatorio** (mínimo 10 caracteres)
5. Confirmar — el sistema valida si la corrección es segura

## Generar siguiente ronda (Suizo)

Aparece el botón **"Generar ronda N"** automáticamente cuando:
- Todos los partidos de la ronda anterior están finalizados
- El torneo no completó todas las rondas configuradas

## Consultar auditoría

Solo administradores: **Sistema → Auditoría** muestra el historial completo de acciones.
