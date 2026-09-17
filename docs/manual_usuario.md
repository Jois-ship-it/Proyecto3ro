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
3. Configurar puntuación (liga/suizo) o rondas (suizo). La casilla
   **Desempatar por puntos a favor** decide si dos participantes con los mismos
   puntos se ordenan por diferencia; conviene destildarla en ajedrez y formatos
   donde el marcador no mide rendimiento
4. El formulario muestra/oculta campos según el formato
5. Al final, en **Datos del evento**, se pueden cargar sede, contacto, cierre de
   inscripción y observaciones. Son opcionales y aparecen en la ficha pública
   del torneo
6. Guardar → el torneo queda en estado **Borrador**

## Inscribir participantes

1. Ir a **Torneos → [nombre del torneo] → Gestionar**
2. En la sección "Inscripciones" seleccionar un participante o equipo
3. Clic en **Inscribir**
4. Repetir para todos los participantes/equipos

> **Si el torneo tiene cierre de inscripción**, pasada esa fecha el sistema no
> deja anotar a nadie más y avisa cuándo cerró. El último día cuenta: un torneo
> que cierra el 20 acepta inscripciones durante todo el 20. Para reabrirlo, un
> administrador corre la fecha desde **Datos del evento** en el formulario del
> torneo. Retirar una inscripción ya cargada sigue siendo posible.

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
2. Clic en **Corregir**. Si sos organizador dice **Solicitar corrección**: la
   aplica un administrador después de revisarla.
3. Modificar los valores
4. Ingresar el **motivo obligatorio** (mínimo 10 caracteres)
5. Confirmar — el sistema valida si la corrección es segura

Si el partido ya no se puede corregir, el mensaje aparece al confirmar y explica
por qué: en Eliminación Directa cuando el ganador ya avanzó, y en Suizo cuando
la ronda del partido no es la última generada. En esos casos la solicitud no se
manda, así que no queda esperando una respuesta que no va a llegar.

## Generar siguiente ronda (Suizo)

Aparece el botón **"Generar ronda N"** automáticamente cuando:
- Todos los partidos de la ronda anterior están finalizados
- El torneo no completó todas las rondas configuradas

## Consultar auditoría

Solo administradores: **Sistema → Auditoría** muestra el historial completo de acciones.
