# Plan de Testing — FlexArena

Este documento tiene dos partes: el **plan manual** (casos a recorrer a mano en
el navegador) y, al final, el mapa de qué casos ya cubre la **batería automática**
de `tests/`. Ver README → Tests para saber cómo correrla.


## Módulo: Autenticación

| # | Caso | Entrada | Resultado esperado |
|---|------|---------|-------------------|
| 1 | Login correcto admin | admin@flexarena.com / password | Redirige a /admin |
| 2 | Login correcto organizador | org1@flexarena.com / password | Redirige a /organizador |
| 3 | Login correcto participante | matias@example.com / password | Redirige a /participante |
| 4 | Email incorrecto | noexiste@x.com / password | "Email o contraseña incorrectos" |
| 5 | Contraseña incorrecta | admin@flexarena.com / mal | "Email o contraseña incorrectos" |
| 6 | Acceso sin login a /admin | — | Redirige a /login con mensaje |
| 7 | Logout | Clic en "Cerrar sesión" | Redirige a /login |
| 8 | Acceso de participante a /admin | — | Error 403 |

## Módulo: Liga

| # | Caso | Condición | Resultado esperado |
|---|------|-----------|-------------------|
| 9  | Generar fixture par (8) | 8 inscritos | 7 rondas × 4 partidos |
| 10 | Generar fixture impar (7) | 7 inscritos | 6 rondas × 3 partidos (sin vs NULL) |
| 11 | Cargar resultado | Partido pendiente | Estado → finalizado, tabla recalculada |
| 12 | Empate permitido | permite_empates=1 | Puntos de empate sumados |
| 13 | Corregir resultado | Partido finalizado | Nueva tabla, registro corregido |
| 14 | Finalizar torneo | Todos los partidos finalizados | Estado → finalizado, campeón asignado |
| 15 | Tabla de posiciones | Tras varios resultados | Orden correcto por puntos, diferencia |

## Módulo: Eliminación Directa

| # | Caso | Condición | Resultado esperado |
|---|------|-----------|-------------------|
| 16 | Bracket 4 participantes | 4 inscritos | 2 Semifinales + 1 Final |
| 17 | Bracket 8 participantes | 8 inscritos | 4 Cuartos + 2 Semi + 1 Final |
| 18 | Bracket 6 participantes | 6 inscritos | 2 byes en primera ronda |
| 19 | Avance ganador | Cargar resultado Semifinal | Ganador aparece en slot de Final |
| 20 | Bye automático | N no potencia de 2 | "Avanza por bye" visible |
| 21 | Campeón definido | Resultado de Final | Campeón guardado en torneo |
| 22 | Bloqueo de corrección | Ganador ya avanzó | "No se puede corregir" |
| 23 | Empate en elim directa | puntos_a = puntos_b | Error de validación |

## Módulo: Sistema Suizo

| # | Caso | Condición | Resultado esperado |
|---|------|-----------|-------------------|
| 24 | Primera ronda par (6) | 6 inscritos | 3 partidos, sin bye |
| 25 | Primera ronda impar (7) | 7 inscritos | 3 partidos + 1 bye |
| 26 | Generar ronda sin completar | Hay pendientes | "No se puede generar..." |
| 27 | Segunda ronda | Todos completados | Emparejamiento por score |
| 28 | Evitar rivales repetidos | — | No se emparejan quienes ya jugaron |
| 29 | Bye segunda vez | Ya tiene bye | Preferir otro |
| 30 | Bloqueo corrección suizo | Ronda posterior generada | Corrección bloqueada |
| 31 | Finalizar tras N rondas | rondas_jugadas = rondas_suizo | Torneo finalizado, campeón definido |

## Módulo: Seguridad

| # | Caso | Entrada | Resultado esperado |
|---|------|---------|-------------------|
| 32 | SQL Injection en login | `' OR 1=1 --` en email | No autentica, error genérico |
| 33 | XSS en nombre de participante | `<script>alert(1)</script>` | Texto escapado visible como texto |
| 34 | CSRF inválido | POST sin token | Error 403 |
| 35 | Acceso no autorizado | Participante en /admin/torneos | Error 403 |
| 36 | Edición de resultado por participante | POST a /admin/resultados/cargar | Error 403 |

## Casos límite obligatorios

| # | Caso | Resultado esperado |
|---|------|-------------------|
| 37 | Torneo sin participantes → generar | Error: "Se necesitan al menos 2 inscritos" |
| 38 | Torneo con 1 participante → generar | Error: "Se necesitan al menos 2 inscritos" |
| 39 | Torneo con 2 participantes (elim) | 1 partido = Final directa |
| 40 | Resultados negativos | Error de validación |
| 41 | Resultado duplicado | "Este partido ya tiene resultado" |
| 42 | Torneo finalizado → generar | Error de estado |
| 43 | Bye en liga (N impar) | No se crea partido con null, rotación funciona |

---

## Cobertura automática

La batería de `tests/` es de integración: instancia los servicios, modelos y
controladores reales contra una base descartable. Correrla:

```bash
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_password php tests/run.php            # con la base propia, sin instalar nada
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_password php tests/run.php --phpunit  # los mismos archivos sobre PHPUnit 11 (modo estricto)
```

Además hay tres capas más (ver README → Tests): los scripts del navegador, que se **ejecutan** con un DOM simulado
(`node tests/js/*.js`); el recorrido de punta a punta con **Cypress** (`bash scripts/e2e.sh probar`) sobre un entorno aislado; y
las mutaciones, con las que se comprobó que cada test falla cuando lo que cuida se rompe.

### Casos del plan que ya están automatizados

| # del plan | Dónde |
|---|---|
| 11, 15 | `tabla_posiciones_test.php` — cifras exactas, orden, recálculo |
| 13, 22, 30, 40 | `correccion_resultados_test.php` — solicitar/aprobar/rechazar y bloqueos por formato |
| 14 | `tiebreak_test.php` (liga) y `bracket_integridad_test.php` (eliminación) |
| 16, 17, 18, 19, 20, 21, 23 | `bracket_integridad_test.php` |
| 24, 25, 26, 28, 29, 31, 37, 38 | `suizo_sin_revanchas_test.php` |
| 35, 36 (parcial) | `torneo_ownership_test.php` — quién puede crear, editar y reasignar torneos |

### Cobertura automática que el plan manual no listaba

| Tema | Dónde |
|---|---|
| Cadena de partidos de desempate hasta que haya campeón | `tiebreak_test.php` |
| Programación de partidos dentro del rango de fechas del torneo | `match_schedule_test.php` |
| Bloqueo de cuenta a los 5 intentos fallidos y desbloqueo | `lockout_estado_test.php` |
| Efecto funcional del toggle de módulos | `modulos_toggle_test.php` |
| Ausencia de revanchas evitables en Sistema Suizo | `suizo_sin_revanchas_test.php` |
| Restricciones del esquema que rechaza el motor (únicos, `CHECK`) y mensaje claro del servicio | `esquema_integridad_test.php` |
| Mínimo privilegio del usuario de la base, conectándose como ese usuario | `privilegios_test.php` |
| Contraseñas iniciales: el seed no publica ninguna y el arranque las asigna sin pisar las existentes | `cuentas_iniciales_test.php` |
| Actualizar una base de septiembre, vacía y con datos | `migraciones_consolidadas_test.php`, `migracion_2026_10_test.php` |
| Las vistas SQL devuelven lo mismo que las consultas que reemplazaron, y la pública no expone datos personales | `vistas_test.php` |
| Historial de la base: cada cambio queda con su antes y después y su autor, sin contraseñas ni datos personales | `historial_bd_test.php` |
| Operaciones de varias filas «todo o nada»: se las corta a la mitad y no queda nada a medias | `transacciones_test.php` |
| Qué puertos publica el compose: la base ninguno, phpMyAdmin solo en `127.0.0.1` | `exposicion_test.php` |
| Manejo central de errores: página con código de referencia, JSON para la API, registro sin argumentos ni query string, el mensaje de la base no llega a la persona, `_method` con lista blanca | `manejador_errores_test.php` |
| Largo máximo de los textos contra el tamaño real de las columnas, política de contraseñas y email | `validator_test.php` |
| Vida de la sesión (vencimiento por inactividad, regeneración) y revalidación de la cuenta contra la base en cada pedido | `sesion_test.php` |
| Bloqueo temporal que vence solo, límite por IP, ajustes del sistema, bloquear/desbloquear solo cuando corresponde y protección de los administradores | `login_limites_test.php` |
| Autorización por rol sobre todas las rutas, token CSRF en todo POST, y correcciones: aplicar es solo del administrador, solicitar solo en los torneos propios | `autorizacion_test.php` |
| Gestión de cuentas de organizador y de administrador: cada apartado solo toca su rol, y no se pierde el último administrador activo | `administradores_test.php` |
| La cuenta de las páginas de una lista: página inválida o fuera de rango, cobertura sin repetidos, números a dibujar | `paginador_test.php` |
| Lo que se acepta de la dirección en un listado: texto acotado, opciones permitidas, fechas reales, comodines escapados | `filtros_test.php` |
| Exportación a CSV: formato para Excel en español y que ningún nombre de usuario llegue a la planilla como fórmula | `csv_test.php` |
| Listas con búsqueda y paginación en SQL: filtros, comodines y SQL en el texto, total = filas, páginas que cubren todo, regla del organizador en la consulta | `listados_test.php` |
| Reportes: cifras contra la tabla de posiciones, casos de borde (empate, bye, sin generar), CSV, quién entra y exportaciones auditadas | `reportes_test.php` |
| Imágenes subidas: reducción a 512 px, proporción, transparencia, orientación EXIF, código pegado que no sobrevive, rechazos y tope de megapíxeles | `imagen_test.php` |
| Frontend sin código ni estilos en línea, política de seguridad con nonce por pedido, clases dinámicas que solo aceptan números, el nombre malicioso que ya no llega a un manejador, hojas Mobile First, calendario público (grilla que no se ensancha, puntos/lista/tarjetas según el ancho) y accesibilidad (etiquetas, tablas, diálogos, landmarks, foco, avisos, contraste AA del botón primario) | `frontend_estatico_test.php` |
| Los scripts de la interfaz con un DOM simulado: confirmar, imprimir, diálogos (foco, Tab, Escape), menú del bracket, reglas de contraseña, rechazar, próximos partidos, calendario (meses, días, torneos en el día de inicio, lista ordenada, datos rotos) | `tests/js/interfaz_test.js` (Node) |
| `formato.js` (fechas, rangos y estados iguales a los de PHP) y la búsqueda en vivo de `/torneos`: cuándo pide, pausa al tipear, qué dibuja (mismo marcado que el servidor), respuesta tardía que no pisa a la nueva, cancelación, falla de red y de la API, nombres con HTML | `tests/js/formato_test.js`, `tests/js/torneos_en_vivo_test.js` (Node) |
| Política de Privacidad y Términos al registrarse: sin la casilla no se crea ni la cuenta ni el perfil (también enviando el formulario a mano), la auditoría guarda la versión aceptada, las páginas se leen sin sesión y dicen quién ve qué, y el login no pide nada | `politicas_test.php` |
| Las vistas no crean modelos ni servicios ni consultan la base; el resultado de cada partido viaja con el partido; el menú lateral | `vistas_sin_modelos_test.php` |
| Panel del participante: próximos partidos, resultados desde su lado, posición en liga y llave, equipos, bye y tope | `panel_participante_test.php` |
| Configuración: validación por campo, solo administrador, todo o nada, auditoría, perfiles públicos, nombre del sitio escapado | `configuracion_sistema_test.php` |
| Pantallas de Configuración y «Mi panel»: acceso, CSRF, errores con lo escrito, envíos manipulados, pie con contacto, estados vacíos y escape | `configuracion_pantalla_test.php` |
| Un empate sin ganador ni perdedor, la regla de empates al corregir, el campeón que se revisa al corregir un torneo finalizado y la migración de los empates ya guardados | `campeon_empates_test.php` |
| API pública de solo lectura: formato, privacidad, filtros, paginación, bordes, 404/405 y que no escriba | `api_test.php` |
| Resultados por fetch: JSON, token en cabecera, compuertas que responden 401/403 sin redirigir y el formulario común intacto | `fetch_resultados_test.php` |
| Los scripts del navegador (`api.js`, `resultados.js`) ejecutados con un fetch simulado | `tests/js/resultados_cliente_test.js` (Node) |
| El respaldo se hace como root y con los triggers, sin claves del host | `backup_restore_test.sh` |

### Casos del plan que cubre el recorrido de punta a punta (Cypress)

Los casos que dependen de la sesión HTTP y del navegador se recorrían a mano. Ahora los recorre `tests/e2e/` (ver README →
Pruebas de punta a punta) contra un entorno aislado, y el número del plan va entre corchetes en el nombre de cada caso:

| # del plan | Dónde |
|---|---|
| 1, 2, 3, 6, 7 | `08_casos_del_plan` — cada rol cae en su panel, `/admin` sin sesión lleva a `/login` con su mensaje, «Cerrar sesión» |
| 4, 5, 8 | `02_registro_y_acceso` — la clave equivocada no revela si el correo existe, y cada rol recibe 403 donde no le corresponde |
| 9, 10, 43 | `08_casos_del_plan` — liga de 8 (7 rondas × 4 partidos) y de 5 (5 rondas × 2, nadie contra «nadie») |
| 11, 14 | `03_liga` — se carga cada resultado, la tabla se recalcula y el torneo termina con campeón |
| 12 | `08_casos_del_plan` — con empates permitidos un 1 a 1 suma el punto a los dos |
| 13 | `05_correcciones_y_propiedad` — solicitar, aprobar, rechazar y corregir directo (motivo de al menos 10 caracteres, empates no permitidos) |
| 16, 17, 18, 19, 20, 21, 22, 23, 39 | `08_casos_del_plan` y `04_eliminacion_y_suizo` — llaves de 2, 4, 6 y 8, pases libres, el ganador avanza, el empate se rechaza, no se corrige lo que ya avanzó, campeón |
| 24, 25, 26, 31 | `08_casos_del_plan` y `04_eliminacion_y_suizo` — primera ronda de 6 y de 7 (con descanso), la siguiente ronda no se genera con partidos pendientes, el suizo termina tras sus rondas |
| 32, 33, 34, 35, 36 | `08_casos_del_plan` — inyección SQL en el correo, nombre con HTML escapado en el registro, la aprobación, los listados y la auditoría, POST sin token CSRF, y un participante sin acceso a `/admin` ni a cargar o corregir resultados |
| 37, 38, 42 | `08_casos_del_plan` — con 0 o 1 inscrito y sobre un torneo que ya empezó, el servidor rechaza la generación con su motivo (también por un envío directo) |
| 40, 41 | `08_casos_del_plan` — un resultado negativo o una segunda carga del mismo partido no se aceptan por un envío directo |

### Casos que siguen siendo manuales

- **27** — el emparejamiento por puntaje de la segunda ronda del suizo: el recorrido de punta a punta genera la ronda pero no
  verifica los cruces (eso lo prueban `suizo_sin_revanchas_test.php` y los demás tests de PHP).
- **15** — el orden fino de la tabla de posiciones: lo cubre `tabla_posiciones_test.php`; en la pantalla se mira que exista.

Mantener estas tablas al día cuando se agreguen tests: son las que dicen qué hay que recorrer a mano antes de una entrega.
