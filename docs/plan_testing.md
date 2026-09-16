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
DB_HOST=127.0.0.1 DB_USER=root DB_PASS=tu_password php tests/run.php
```

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

### Casos que siguen siendo manuales

- **1, 2, 3, 6, 7, 8** — login por rol, logout y redirecciones: dependen de sesión
  HTTP y de la respuesta del navegador.
- **9, 10, 12, 27, 39, 41, 42, 43** — todavía sin caso automatizado.
- **32, 33, 34** — inyección SQL, XSS y CSRF: se verifican sobre la aplicación
  levantada, no desde CLI.

Mantener esta tabla al día cuando se agreguen tests: es la que dice qué hay que
recorrer a mano antes de una entrega.
