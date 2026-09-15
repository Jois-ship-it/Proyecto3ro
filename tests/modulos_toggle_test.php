<?php
declare(strict_types=1);

/**
 * Test de integración del toggle de módulos.
 *
 * Verifica, contra las clases reales y una base de prueba, que desactivar un
 * módulo de formato tiene efecto funcional:
 *   1. El servicio del formato se niega a generar estructura (fixture/bracket/ronda 1).
 *   2. TorneoService::crear() rechaza torneos nuevos de ese formato.
 *   3. El tipo desaparece de los que ofrece el formulario de creación.
 *   4. Un torneo YA EN CURSO de ese formato puede seguir y terminarse.
 *   5. El cambio de estado queda registrado en auditoría.
 *   6. Al reactivar, todo vuelve a funcionar.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/modulos_toggle_test.php
 */

require __DIR__ . '/bootstrap.php';

$db = Database::getInstance();

$fallos  = 0;
$pruebas = 0;

function ok(string $desc): void {
    global $pruebas; $pruebas++;
    printf("  OK    %s\n", $desc);
}
function falla(string $desc, string $detalle = ''): void {
    global $pruebas, $fallos; $pruebas++; $fallos++;
    printf("  FALLA %s\n", $desc);
    if ($detalle !== '') printf("        %s\n", $detalle);
}

/** Espera que $fn lance RuntimeException cuyo mensaje contenga $fragmento. */
function assertLanza(callable $fn, string $fragmento, string $desc): void {
    try {
        $fn();
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), $fragmento)) { ok($desc); return; }
        falla($desc, "lanzó, pero el mensaje no menciona «{$fragmento}»: " . $e->getMessage());
        return;
    }
    falla($desc, 'no lanzó ninguna excepción');
}

function assertNoLanza(callable $fn, string $desc): void {
    try { $fn(); ok($desc); }
    catch (Throwable $e) { falla($desc, get_class($e) . ': ' . $e->getMessage()); }
}

function assertIgual($esperado, $real, string $desc): void {
    if ($esperado === $real) { ok($desc); return; }
    falla($desc, 'esperado ' . var_export($esperado, true) . ', obtenido ' . var_export($real, true));
}

/**
 * Renderiza la vista REAL del formulario de torneo y devuelve el HTML del
 * <select name="tipo_torneo_id">. Así la comprobación es sobre lo que ve el
 * usuario, no solo sobre lo que devuelve el modelo.
 */
function renderSelectFormato(array $tipos, ?array $torneo): string
{
    $_SESSION = $_SESSION ?? [];
    $organizadores     = [['id' => 2, 'nombre' => 'Organizador', 'rol_nombre' => 'organizador']];
    $organizadorActual = $torneo['organizador_id'] ?? null;

    ob_start();
    include __DIR__ . '/../app/views/admin/torneo_form.php';
    $html = (string) ob_get_clean();

    $ini = strpos($html, '<select name="tipo_torneo_id"');
    $fin = $ini === false ? false : strpos($html, '</select>', $ini);
    return $ini === false || $fin === false ? '' : substr($html, $ini, $fin - $ini);
}

// ── Preparación ─────────────────────────────────────────────────────────────
testResetDatosDinamicos($db);
testActivarTodosLosModulos($db);

$torneoService = new TorneoService();
$inscService   = new InscripcionService();
$resService    = new ResultadoService();
$tipoModel     = new TipoTorneoModel();
$moduloModel   = new ModuloModel();
$moduloService = new ModuloService();
$partModel     = new ParticipanteModel();

$servicios = [
    'liga'                => [new LigaService(),               'generarFixture'],
    'eliminacion_directa' => [new EliminacionDirectaService(), 'generarBracket'],
    'suizo'               => [new SistemaSuizoService(),       'generarPrimeraRonda'],
];

// 8 participantes de prueba
$pids = [];
foreach (['Ana', 'Bruno', 'Carla', 'Diego', 'Elena', 'Fabio', 'Gina', 'Hugo'] as $n) {
    $pids[] = $partModel->insert(['nombre' => "$n Test", 'nick' => $n, 'estado' => 'activo']);
}

$crearTorneo = function (string $slug, string $nombre) use ($torneoService, $tipoModel): int {
    $tipo = $tipoModel->findBySlug($slug);
    return $torneoService->crear([
        'nombre'          => $nombre,
        'tipo_torneo_id'  => $tipo['id'],
        'modalidad'       => 'individual',
        'estado'          => 'inscripcion',
        'publico'         => '1',
        'creado_por'      => 1,
        'organizador_id'  => 2,
        'permite_empates' => null,
        'puntos_victoria' => 3, 'puntos_empate' => 1, 'puntos_derrota' => 0,
        'rondas_suizo'    => $slug === 'suizo' ? 3 : null,
        'bye_suizo'       => 'victoria',
        'nombre_puntos'   => 'puntos',
        'fecha_inicio'    => date('Y-m-d'),
        'fecha_fin'       => date('Y-m-d', strtotime('+30 days')),
    ]);
};

// Para cada formato: un torneo "en curso" (arrancado con el módulo activo) y otro
// todavía en inscripción, que es el que se intentará arrancar con el módulo apagado.
$enCurso     = [];
$sinArrancar = [];
foreach ($servicios as $slug => [$servicio, $metodo]) {
    $tEnCurso = $crearTorneo($slug, "En curso ($slug)");
    foreach (array_slice($pids, 0, 4) as $pid) $inscService->inscribirParticipante($tEnCurso, $pid);
    $servicio->$metodo($tEnCurso);
    $enCurso[$slug] = $tEnCurso;

    $tNuevo = $crearTorneo($slug, "Sin arrancar ($slug)");
    foreach (array_slice($pids, 0, 4) as $pid) $inscService->inscribirParticipante($tNuevo, $pid);
    $sinArrancar[$slug] = $tNuevo;
}
echo "Preparados 6 torneos (3 en curso, 3 en inscripción) con los módulos activos.\n\n";

// ── 1) Con el módulo ACTIVO todo funciona (control) ─────────────────────────
echo "Con todos los módulos activos:\n";
foreach ($servicios as $slug => [$servicio, $metodo]) {
    $tipos = array_column($tipoModel->findDisponibles(), 'slug');
    if (in_array($slug, $tipos, true)) ok("«{$slug}» se ofrece en el formulario de creación");
    else falla("«{$slug}» se ofrece en el formulario de creación", 'no aparece: ' . implode(', ', $tipos));
}

// ── 2) Apagar cada módulo y comprobar el bloqueo ────────────────────────────
foreach ($servicios as $slug => [$servicio, $metodo]) {
    $modulo = $moduloModel->findBySlug($slug);
    echo "\nMódulo «{$modulo['nombre']}» desactivado:\n";

    $r = $moduloService->toggle((int)$modulo['id']);
    assertIgual('inactivo', $r['nuevo'], "toggle() deja el módulo en 'inactivo'");

    // 2a. No se puede generar la estructura de un torneo sin arrancar.
    assertLanza(
        fn() => $servicio->$metodo($sinArrancar[$slug]),
        'Un administrador debe reactivarlo',
        "$metodo() se niega a arrancar un torneo nuevo"
    );

    // 2b. No se pueden crear torneos de ese formato.
    assertLanza(
        fn() => $crearTorneo($slug, "No debería existir ($slug)"),
        'Un administrador debe reactivarlo',
        'TorneoService::crear() rechaza el formato deshabilitado'
    );

    // 2c. El tipo desaparece del formulario de creación.
    $ofrecidos = array_column($tipoModel->findDisponibles(), 'slug');
    if (!in_array($slug, $ofrecidos, true)) ok('el tipo ya no se ofrece en el formulario de creación');
    else falla('el tipo ya no se ofrece en el formulario de creación', 'sigue apareciendo');

    // Y lo mismo sobre el HTML que realmente renderiza la vista.
    $selectNuevo = renderSelectFormato($tipoModel->findDisponibles(), null);
    if ($selectNuevo === '') {
        falla('el <select> del formulario no contiene el formato deshabilitado', 'no se pudo renderizar la vista');
    } elseif (!str_contains($selectNuevo, 'data-slug="' . $slug . '"')) {
        ok('el <select> del formulario ya no contiene ese formato');
    } else {
        falla('el <select> del formulario ya no contiene ese formato', $selectNuevo);
    }

    // 2d. Editando un torneo existente de ese formato, el select conserva su tipo.
    $tipoId     = (int) $tipoModel->findBySlug($slug)['id'];
    $alEditar   = $tipoModel->findDisponibles($tipoId);
    $conservado = array_values(array_filter($alEditar, fn($t) => (int)$t['id'] === $tipoId));
    if ($conservado && (int)$conservado[0]['modulo_activo'] === 0) {
        ok('al editar un torneo de ese formato, el select lo conserva y lo marca deshabilitado');
    } else {
        falla('al editar un torneo de ese formato, el select lo conserva y lo marca deshabilitado');
    }

    $torneoEnEdicion = (new TorneoModel())->findById($sinArrancar[$slug]);
    $selectEdicion   = renderSelectFormato($alEditar, $torneoEnEdicion);
    if (str_contains($selectEdicion, 'data-slug="' . $slug . '"')
        && str_contains($selectEdicion, 'módulo deshabilitado')) {
        ok('el <select> de edición conserva el formato y lo rotula «módulo deshabilitado»');
    } else {
        falla('el <select> de edición conserva el formato y lo rotula «módulo deshabilitado»', $selectEdicion);
    }

    // 2e. El toggle quedó auditado.
    $auditado = (int) $db->query(
        "SELECT COUNT(*) FROM auditoria
         WHERE accion = 'toggle_modulo' AND tabla_afectada = 'modulos'
           AND registro_id = " . (int)$modulo['id'] . "
           AND JSON_UNQUOTE(JSON_EXTRACT(valor_nuevo, '$.estado')) = 'inactivo'
           AND JSON_UNQUOTE(JSON_EXTRACT(valor_anterior, '$.estado')) = 'activo'"
    )->fetchColumn();
    assertIgual(1, $auditado, 'el cambio de estado quedó registrado en auditoría');
}

// ── 3) Los torneos en curso siguen funcionando con los módulos apagados ─────
echo "\nCon los tres módulos apagados, los torneos ya en curso:\n";

// Suizo: cargar la ronda 1 y generar la ronda 2.
$tSuizo = $enCurso['suizo'];
$idsSuizo = $db->query(
    "SELECT e.id FROM enfrentamientos e JOIN rondas r ON r.id = e.ronda_id
     WHERE e.torneo_id = $tSuizo AND r.numero = 1 AND e.es_bye = 0"
)->fetchAll(PDO::FETCH_COLUMN);
assertNoLanza(function () use ($idsSuizo, $resService) {
    foreach ($idsSuizo as $eid) $resService->cargar((int)$eid, 3.0, 1.0, 1);
}, 'suizo en curso: se pueden cargar resultados');
assertNoLanza(
    fn() => (new SistemaSuizoService())->generarSiguienteRonda($tSuizo),
    'suizo en curso: se puede generar la ronda siguiente'
);

// Eliminación directa: cargar la primera ronda y comprobar que el bracket avanza.
$tElim = $enCurso['eliminacion_directa'];
$idsElim = $db->query(
    "SELECT e.id FROM enfrentamientos e JOIN rondas r ON r.id = e.ronda_id
     WHERE e.torneo_id = $tElim AND r.numero = 1 AND e.es_bye = 0"
)->fetchAll(PDO::FETCH_COLUMN);
assertNoLanza(function () use ($idsElim, $resService) {
    foreach ($idsElim as $eid) $resService->cargar((int)$eid, 2.0, 1.0, 1);
}, 'bracket en curso: se pueden cargar resultados y avanzar ganadores');
$avanzados = (int) $db->query(
    "SELECT COUNT(*) FROM enfrentamientos e JOIN rondas r ON r.id = e.ronda_id
     WHERE e.torneo_id = $tElim AND r.numero = 2
       AND e.participante_a_id IS NOT NULL AND e.participante_b_id IS NOT NULL"
)->fetchColumn();
assertIgual(1, $avanzados, 'bracket en curso: la final quedó armada con los dos ganadores');

// Liga: el fixture ya está generado, se cargan resultados igual.
$tLiga = $enCurso['liga'];
$idsLiga = $db->query(
    "SELECT id FROM enfrentamientos WHERE torneo_id = $tLiga AND es_bye = 0 LIMIT 3"
)->fetchAll(PDO::FETCH_COLUMN);
assertNoLanza(function () use ($idsLiga, $resService) {
    foreach ($idsLiga as $eid) $resService->cargar((int)$eid, 2.0, 0.0, 1);
}, 'liga en curso: se pueden cargar resultados');

// ── 4) Reactivar devuelve todo a la normalidad ──────────────────────────────
echo "\nAl reactivar los módulos:\n";
foreach ($servicios as $slug => [$servicio, $metodo]) {
    $modulo = $moduloModel->findBySlug($slug);
    $r = $moduloService->toggle((int)$modulo['id']);
    assertIgual('activo', $r['nuevo'], "«{$modulo['nombre']}» vuelve a 'activo'");
    assertNoLanza(
        fn() => $servicio->$metodo($sinArrancar[$slug]),
        "$metodo() vuelve a generar la estructura"
    );
}
$ofrecidos = array_column($tipoModel->findDisponibles(), 'slug');
assertIgual(3, count($ofrecidos), 'el formulario vuelve a ofrecer los tres formatos');

// ── Resumen ─────────────────────────────────────────────────────────────────
echo "\n";
if ($fallos > 0) {
    echo "RESULTADO: FALLÓ — {$fallos} de {$pruebas} comprobaciones.\n";
    exit(1);
}
echo "RESULTADO: OK — {$pruebas} comprobaciones pasaron.\n";
exit(0);
