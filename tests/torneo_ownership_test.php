<?php
declare(strict_types=1);

/**
 * Test de las reglas de ownership de torneos en TorneoController (sin base de datos).
 * Reproduce fielmente las reglas implementadas en:
 *   - TorneoController::listadoAdmin() (filtro por organizador_id cuando no es admin)
 *   - TorneoController::formulario()/guardar() (editar: solo el dueño o el admin;
 *     crear: solo el admin — "crear y configurar torneos" es función del administrador
 *     según la letra del proyecto; el organizador únicamente "configura torneos asignados")
 *   - TorneoController::guardar() (organizador_id del POST solo se aplica si es admin;
 *     si no, se conserva el organizador_id que ya tenía el torneo)
 *
 * Ejecutar:  php sgdm/tests/torneo_ownership_test.php
 */

// ── Reproducción del gate de acceso a formulario()/guardar() ──
function puedeAccederFormulario(bool $esAdmin, ?int $torneoId, ?int $organizadorIdDelTorneo, int $authId): bool
{
    if ($torneoId !== null) {
        // Editar: admin siempre puede; el organizador solo si es el dueño actual.
        return $esAdmin || $organizadorIdDelTorneo === $authId;
    }
    // Crear: solo admin.
    return $esAdmin;
}

// ── Reproducción de la resolución de 'organizador_id' en guardar() ──
function resolverOrganizadorId(bool $esAdmin, int $organizadorIdPosteado, ?int $organizadorIdActualDelTorneo): int
{
    return $esAdmin ? $organizadorIdPosteado : (int)($organizadorIdActualDelTorneo ?? 0);
}

// ── Reproducción del filtro de listadoAdmin() (idéntico a OrganizadorController::misTorneos()) ──
function listadoVisible(bool $esAdmin, array $todosLosTorneos, int $authId): array
{
    if ($esAdmin) return $todosLosTorneos;
    return array_values(array_filter($todosLosTorneos, fn($t) => $t['organizador_id'] === $authId));
}

$fallos = 0;
function check(bool $cond, string $desc): void
{
    global $fallos;
    printf("[%s] %s\n", $cond ? 'OK' : 'FALLO', $desc);
    if (!$cond) $fallos++;
}

// Escenario: torneo #1 pertenece al organizador id=2 (Carlos). id=3 es otro organizador (Laura). id=1 es admin.
$ADMIN = 1; $CARLOS = 2; $LAURA = 3;

// 1) Editar: admin siempre puede, sea o no el dueño.
check(puedeAccederFormulario(true, 1, $CARLOS, $ADMIN) === true, 'admin puede editar cualquier torneo (es dueño o no)');

// 2) Editar: el organizador dueño puede.
check(puedeAccederFormulario(false, 1, $CARLOS, $CARLOS) === true, 'organizador dueño puede editar su propio torneo');

// 3) Editar: el organizador NO dueño no puede.
check(puedeAccederFormulario(false, 1, $CARLOS, $LAURA) === false, 'organizador no-dueño no puede editar un torneo ajeno');

// 4) Crear: un organizador no-admin no puede crear torneos nuevos.
check(puedeAccederFormulario(false, null, null, $CARLOS) === false, 'organizador no-admin no puede crear un torneo nuevo');

// 5) Crear: el admin sí puede.
check(puedeAccederFormulario(true, null, null, $ADMIN) === true, 'admin puede crear un torneo nuevo');

// 6) organizador_id: el admin puede reasignarlo libremente (incluso a otro organizador).
check(resolverOrganizadorId(true, $LAURA, $CARLOS) === $LAURA, 'admin reasigna organizador_id al valor posteado, aunque difiera del actual');

// 7) organizador_id: el organizador no-admin NO puede reasignarlo, aunque lo postee (POST manipulado).
check(resolverOrganizadorId(false, $LAURA, $CARLOS) === $CARLOS, 'organizador no-admin no puede reasignar organizador_id: se conserva el actual (Carlos), no el posteado (Laura)');
check(resolverOrganizadorId(false, $CARLOS, $CARLOS) === $CARLOS, 'organizador no-admin editando su propio torneo conserva su propio id (sin cambios)');

// 8) listadoAdmin(): el admin ve todos los torneos.
$todos = [
    ['id' => 1, 'nombre' => 'Torneo A', 'organizador_id' => $CARLOS],
    ['id' => 2, 'nombre' => 'Torneo B', 'organizador_id' => $LAURA],
    ['id' => 3, 'nombre' => 'Torneo C', 'organizador_id' => $CARLOS],
];
check(count(listadoVisible(true, $todos, $ADMIN)) === 3, 'admin ve todos los torneos en el listado (3 de 3)');

// 9) listadoAdmin(): el organizador ve solo los suyos.
$visiblesCarlos = listadoVisible(false, $todos, $CARLOS);
check(count($visiblesCarlos) === 2, 'organizador Carlos ve solo sus 2 torneos, no los 3');
check(
    array_column($visiblesCarlos, 'id') === [1, 3],
    'organizador Carlos ve exactamente los torneos 1 y 3 (los suyos), no el 2 (de Laura)'
);

echo "\n";
if ($fallos > 0) {
    echo "TOTAL: {$fallos} fallo(s)\n";
    exit(1);
}
echo "TOTAL: todos los casos OK\n";
