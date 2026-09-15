<?php
declare(strict_types=1);

/**
 * Test de integración del Sistema Suizo: ningún par puede repetirse.
 *
 * Corre database/seed_demo.php COMPLETO contra la base de pruebas (o sea,
 * ejercita SistemaSuizoService de verdad, no una reimplementación) y después
 * audita la base buscando revanchas.
 *
 * Regla verificada:
 *   - En un torneo suizo, dos participantes no se enfrentan dos veces mientras
 *     exista algún emparejamiento perfecto alternativo sin revanchas.
 *   - Las rondas "Desempate N" quedan fuera: ahí la revancha es intencional.
 *   - El bye no se reparte dos veces a nadie mientras haya alguien sin bye.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/suizo_sin_revanchas_test.php
 */

require __DIR__ . '/bootstrap.php';

$db = Database::getInstance();

// ── Sembrar: corre el seed real sobre la base de pruebas ────────────────────
echo "Sembrando datos de demostración (seed_demo.php)…\n";
ob_start();
require __DIR__ . '/../database/seed_demo.php';
$salidaSeed = ob_get_clean();
if (!str_contains($salidaSeed, 'Datos de demostración regenerados')) {
    fwrite(STDERR, "El seed no terminó correctamente:\n$salidaSeed\n");
    exit(2);
}
echo "Seed completo.\n\n";

// ── Auditoría ───────────────────────────────────────────────────────────────
$torneos = $db->query(
    "SELECT t.id, t.nombre, t.modalidad
     FROM torneos t
     JOIN tipos_torneo tt ON tt.id = t.tipo_torneo_id
     WHERE tt.slug = 'suizo'
     ORDER BY t.id"
)->fetchAll(PDO::FETCH_ASSOC);

if (count($torneos) === 0) {
    fwrite(STDERR, "No hay torneos suizos en la base sembrada: el test no prueba nada.\n");
    exit(2);
}

$fallos = 0;

foreach ($torneos as $t) {
    $tid      = (int) $t['id'];
    $esEquipo = $t['modalidad'] === 'equipos';
    $colA     = $esEquipo ? 'equipo_a_id' : 'participante_a_id';
    $colB     = $esEquipo ? 'equipo_b_id' : 'participante_b_id';

    // Enfrentamientos reales del suizo (sin byes y sin rondas de desempate).
    $enfs = $db->query(
        "SELECT r.numero AS ronda, e.$colA AS a, e.$colB AS b
         FROM enfrentamientos e
         JOIN rondas r ON r.id = e.ronda_id
         WHERE e.torneo_id = $tid
           AND e.es_bye = 0
           AND r.nombre NOT LIKE 'Desempate%'
           AND e.$colA IS NOT NULL AND e.$colB IS NOT NULL
         ORDER BY r.numero, e.orden"
    )->fetchAll(PDO::FETCH_ASSOC);

    $vistos    = [];   // "min-max" => [rondas]
    $revanchas = [];

    foreach ($enfs as $e) {
        $a = (int) $e['a'];
        $b = (int) $e['b'];
        $clave = min($a, $b) . '-' . max($a, $b);
        if (isset($vistos[$clave])) {
            $revanchas[$clave] = array_merge($vistos[$clave], [(int) $e['ronda']]);
        }
        $vistos[$clave][] = (int) $e['ronda'];
    }

    // Byes: nadie debería recibir dos mientras alguien tenga cero.
    $byes = $db->query(
        "SELECT e.$colA AS id, COUNT(*) AS n
         FROM enfrentamientos e
         WHERE e.torneo_id = $tid AND e.es_bye = 1 AND e.$colA IS NOT NULL
         GROUP BY e.$colA"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $inscritos = (int) $db->query("SELECT COUNT(*) FROM inscripciones WHERE torneo_id = $tid")->fetchColumn();
    $byesDobles = array_filter($byes, fn($n) => (int) $n > 1);
    $byeInjusto = $byesDobles !== [] && count($byes) < $inscritos;

    $etiqueta = "Torneo #{$tid} «{$t['nombre']}»";

    if ($revanchas === [] && !$byeInjusto) {
        printf("  OK    %-45s %2d partidos, sin revanchas\n", $etiqueta, count($enfs));
        continue;
    }

    $fallos++;
    printf("  FALLA %-45s\n", $etiqueta);
    foreach ($revanchas as $clave => $rondas) {
        [$x, $y] = explode('-', $clave);
        printf("        revancha: %s vs %s en rondas %s\n", $x, $y, implode(', ', $rondas));
    }
    foreach ($byesDobles as $id => $n) {
        printf("        bye repetido: %s recibió %d byes (hay inscritos sin bye)\n", $id, $n);
    }
}

echo "\n";
if ($fallos > 0) {
    echo "RESULTADO: FALLÓ — {$fallos} torneo(s) suizo(s) con emparejamientos repetidos.\n";
    exit(1);
}
echo "RESULTADO: OK — ningún torneo suizo repite emparejamientos.\n";
exit(0);
