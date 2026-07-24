<?php
/**
 * Siembra de datos de demostración (CLI).
 * Usa los SERVICIOS del dominio para que todo quede consistente:
 * torneos jugados de verdad, brackets que avanzan, tablas recalculadas,
 * campeones definidos e historiales/estadísticas reales para los perfiles.
 *
 * Uso:  php database/seed_demo.php
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH',  BASE_PATH . '/app');
define('CORE_PATH', BASE_PATH . '/core');

foreach (file(BASE_PATH . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    $l = trim($l); if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) continue;
    [$k, $v] = explode('=', $l, 2); $_ENV[trim($k)] = trim($v, " \t\"'");
}
spl_autoload_register(function ($c) {
    foreach ([CORE_PATH, APP_PATH . '/models', APP_PATH . '/services'] as $d) {
        $f = "$d/$c.php"; if (file_exists($f)) { require_once $f; return; }
    }
});
require BASE_PATH . '/config/app.php';

mt_srand(20260608); // reproducible
$db = Database::getInstance();

$torneoService = new TorneoService();
$inscService   = new InscripcionService();
$resService    = new ResultadoService();
$ligaService   = new LigaService();
$elimService   = new EliminacionDirectaService();
$suizoService  = new SistemaSuizoService();
$tipoModel     = new TipoTorneoModel();
$partModel     = new ParticipanteModel();
$equipoModel   = new EquipoModel();

function line(string $s): void { echo $s . "\n"; }

// ── 1) RESET de datos dinámicos (se conservan roles, usuarios, tipos, módulos, permisos) ──
line('Reseteando datos dinámicos…');
$db->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ([
    'solicitudes_correccion','tabla_posiciones','resultados','enfrentamientos','rondas',
    'inscripciones','torneo_organizadores','configuraciones_torneo','torneos',
    'equipo_participantes','equipos','participantes','auditoria',
] as $t) {
    $db->exec("TRUNCATE TABLE $t");
}
$db->exec('SET FOREIGN_KEY_CHECKS = 1');

// ── 2) PARTICIPANTES ──
// Los primeros 3 vinculados a las cuentas demo (pueden iniciar sesión y ver su perfil).
$linked = [
    [4, 'Matías López',   'MatiasL', 'matias@example.com'],
    [5, 'Valentina García','ValeG',  'vale@example.com'],
    [6, 'Nicolás Pérez',  'NicoP',   'nico@example.com'],
];
$standalone = [
    ['Camila Torres','CamiT'], ['Lucas Romero','LucasR'], ['Sofía Méndez','SofiaM'],
    ['Diego Álvarez','DiegoA'], ['Ana Fernández','AnaF'], ['Martín Suárez','MartinS'],
    ['Julia Herrera','JuliaH'], ['Pablo Castro','PabloC'], ['Florencia Reyes','FloR'],
    ['Sebastián Mora','SebaM'], ['Gabriela Silva','GabiS'], ['Rodrigo Jiménez','RodrJ'],
    ['Natalia Ruiz','NataR'], ['Franco Núñez','FranN'], ['Cecilia Blanco','CeciB'],
    ['Tomás González','TomasG'], ['Silvana Martínez','SilM'], ['Agustín Vargas','AgusV'],
    ['Mariana Sosa','MariS'], ['Eduardo Flores','EduF'], ['Patricia Campos','PatriC'],
    ['Joaquín Ramos','JoacoR'], ['Lucía Benítez','LuBe'], ['Hernán Ortiz','HernO'],
    ['Renata Aguirre','RenA'], ['Bruno Medina','BruM'],
];
$pids = [];
foreach ($linked as [$uid, $nombre, $nick, $email]) {
    $pids[] = $partModel->insert(['usuario_id'=>$uid,'nombre'=>$nombre,'nick'=>$nick,'email'=>$email,'estado'=>'activo']);
}
foreach ($standalone as [$nombre, $nick]) {
    $pids[] = $partModel->insert(['nombre'=>$nombre,'nick'=>$nick,'estado'=>'activo']);
}
line('Participantes: ' . count($pids));

// ── 3) EQUIPOS (con descripción) + integrantes ──
$equiposDef = [
    ['Aqua Academy','A','Fútbol','Club universitario con fuerte cantera de jóvenes promesas.'],
    ['Team Atlas','A','Fútbol','Equipo veterano, disciplina táctica y juego colectivo.'],
    ['Nexo Gaming','B','Esports','Organización de esports enfocada en shooters competitivos.'],
    ['Río Negro Club','A','Fútbol','Histórico del litoral, hinchada apasionada.'],
    ['Storm Raiders','B','Esports','Roster joven con mentalidad agresiva.'],
    ['Iron Phoenix','A','Ajedrez','Círculo de ajedrez con maestros titulados.'],
    ['Silver Wolves','B','Esports','Equipo mixto, especialistas en estrategia.'],
    ['Delta Force','A','Baloncesto','Potencia física y transición rápida.'],
    ['Costa Brava FC','A','Fútbol','Estilo ofensivo, vocación de ataque.'],
    ['Quantum Five','B','Esports','Analítica de datos aplicada al juego.'],
];
$eids = [];
$pi = 0;
foreach ($equiposDef as [$nombre, $cat, $disc, $desc]) {
    $eid = $equipoModel->insert(['nombre'=>$nombre,'categoria'=>$cat,'disciplina'=>$disc,'descripcion'=>$desc,'estado'=>'activo']);
    $eids[] = $eid;
    // 4 integrantes rotando sobre el pool de participantes
    for ($k = 0; $k < 4; $k++) {
        $equipoModel->agregarParticipante($eid, $pids[$pi % count($pids)], $k === 0 ? 'capitan' : 'jugador');
        $pi++;
    }
}
line('Equipos: ' . count($eids));

// ── Helpers de simulación ──
$randScore = function (bool $noDraw): array {
    $a = mt_rand(0, 5); $b = mt_rand(0, 5);
    if ($noDraw && $a === $b) { $a += 1; }
    return [(float)$a, (float)$b];
};

/** Carga resultados de todos los partidos con ambos lados definidos (liga + eliminación). */
$cargarPendientes = function (int $torneoId, bool $noDraw) use ($db, $resService, $randScore): void {
    do {
        $rows = $db->query(
            "SELECT id, participante_a_id, participante_b_id, equipo_a_id, equipo_b_id
             FROM enfrentamientos
             WHERE torneo_id = $torneoId AND estado IN ('pendiente','en_curso') AND es_bye = 0"
        )->fetchAll(PDO::FETCH_ASSOC);
        $cargados = 0;
        foreach ($rows as $r) {
            $aSet = $r['participante_a_id'] || $r['equipo_a_id'];
            $bSet = $r['participante_b_id'] || $r['equipo_b_id'];
            if (!$aSet || !$bSet) continue; // espera a que se complete el feeder del bracket
            [$pa, $pb] = $randScore($noDraw);
            $resService->cargar((int)$r['id'], $pa, $pb, 1);
            $cargados++;
        }
    } while ($cargados > 0);
};

/** Juega un suizo: carga la ronda actual y genera la siguiente, hasta completar. */
$jugarSuizo = function (int $torneoId, int $rondas, int $hasta, bool $noDraw) use ($db, $resService, $suizoService, $randScore): void {
    for ($round = 1; $round <= $hasta; $round++) {
        $ids = $db->query(
            "SELECT e.id FROM enfrentamientos e JOIN rondas r ON r.id = e.ronda_id
             WHERE e.torneo_id = $torneoId AND e.estado IN ('pendiente','en_curso') AND e.es_bye = 0 AND r.numero = $round"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $eid) { [$pa, $pb] = $randScore($noDraw); $resService->cargar((int)$eid, $pa, $pb, 1); }
        if ($round < $rondas) { $suizoService->generarSiguienteRonda($torneoId); }
    }
};

/** Crea un torneo (estado inscripción), devuelve id. */
$crearTorneo = function (array $c) use ($torneoService, $tipoModel): int {
    $tipo = $tipoModel->findBySlug($c['tipo']);
    return $torneoService->crear([
        'nombre'          => $c['nombre'],
        'tipo_torneo_id'  => $tipo['id'],
        'modalidad'       => $c['modalidad'],
        'estado'          => 'inscripcion',
        'publico'         => '1',
        'creado_por'      => 1,
        'organizador_id'  => $c['org'],
        'permite_empates' => !empty($c['empates']) ? '1' : null,
        'puntos_victoria' => 3, 'puntos_empate' => 1, 'puntos_derrota' => 0,
        'rondas_suizo'    => $c['rondas'] ?? null,
        'bye_suizo'       => 'victoria',
        'nombre_puntos'   => $c['puntos'] ?? 'puntos',
        'min_integrantes_equipo' => $c['modalidad'] === 'equipos' ? 3 : null,
        'fecha_inicio'    => date('Y-m-d'),
        'fecha_fin'       => date('Y-m-d', strtotime('+30 days')),
    ]);
};

$inscribir = function (int $torneoId, string $modalidad, array $ids) use ($inscService): void {
    foreach ($ids as $id) {
        if ($modalidad === 'equipos') $inscService->inscribirEquipo($torneoId, $id);
        else                          $inscService->inscribirParticipante($torneoId, $id);
    }
};

// Pools de selección (subconjuntos rotados para variar participación)
$sub = fn(array $arr, int $start, int $n): array => array_slice(array_merge($arr, $arr), $start, $n);

// ── 4) TORNEOS FINALIZADOS ──
line('Generando torneos…');

// Liga individual A (8)
$t = $crearTorneo(['nombre'=>'Liga Apertura 2026','tipo'=>'liga','modalidad'=>'individual','org'=>2,'empates'=>true,'puntos'=>'puntos']);
$inscribir($t,'individual', $sub($pids,0,8));   $ligaService->generarFixture($t); $cargarPendientes($t,false);
line("  ✓ Liga Apertura 2026 (8 jug.) finalizada");

// Liga individual B (6)
$t = $crearTorneo(['nombre'=>'Liga Clausura 2026','tipo'=>'liga','modalidad'=>'individual','org'=>3,'empates'=>true]);
$inscribir($t,'individual', $sub($pids,3,6));   $ligaService->generarFixture($t); $cargarPendientes($t,false);
line("  ✓ Liga Clausura 2026 (6 jug.) finalizada");

// Eliminación individual (8)
$t = $crearTorneo(['nombre'=>'Copa Relámpago','tipo'=>'eliminacion_directa','modalidad'=>'individual','org'=>2]);
$inscribir($t,'individual', $sub($pids,0,8));   $elimService->generarBracket($t); $cargarPendientes($t,true);
line("  ✓ Copa Relámpago (8 jug., bracket) finalizada");

// Eliminación individual (12 → bracket con byes)
$t = $crearTorneo(['nombre'=>'Copa Maestros','tipo'=>'eliminacion_directa','modalidad'=>'individual','org'=>3]);
$inscribir($t,'individual', $sub($pids,5,12));  $elimService->generarBracket($t); $cargarPendientes($t,true);
line("  ✓ Copa Maestros (12 jug., con byes) finalizada");

// Suizo individual (8, 4 rondas)
$t = $crearTorneo(['nombre'=>'Suizo Ajedrez Otoño','tipo'=>'suizo','modalidad'=>'individual','org'=>2,'rondas'=>4,'empates'=>true]);
$inscribir($t,'individual', $sub($pids,2,8));   $suizoService->generarPrimeraRonda($t); $jugarSuizo($t,4,4,false);
line("  ✓ Suizo Ajedrez Otoño (8 jug., 4 rondas) finalizado");

// Suizo individual (11, 5 rondas, impar → byes)
$t = $crearTorneo(['nombre'=>'Suizo Estrategia Open','tipo'=>'suizo','modalidad'=>'individual','org'=>3,'rondas'=>5,'empates'=>true]);
$inscribir($t,'individual', $sub($pids,6,11));  $suizoService->generarPrimeraRonda($t); $jugarSuizo($t,5,5,false);
line("  ✓ Suizo Estrategia Open (11 jug., 5 rondas) finalizado");

// Liga por equipos (6)
$t = $crearTorneo(['nombre'=>'Liga de Clubes','tipo'=>'liga','modalidad'=>'equipos','org'=>2,'empates'=>true,'puntos'=>'goles']);
$inscribir($t,'equipos', $sub($eids,0,6));      $ligaService->generarFixture($t); $cargarPendientes($t,false);
line("  ✓ Liga de Clubes (6 equipos) finalizada");

// Eliminación por equipos (8)
$t = $crearTorneo(['nombre'=>'Champions Cup','tipo'=>'eliminacion_directa','modalidad'=>'equipos','org'=>3,'puntos'=>'mapas']);
$inscribir($t,'equipos', $sub($eids,0,8));      $elimService->generarBracket($t); $cargarPendientes($t,true);
line("  ✓ Champions Cup (8 equipos, bracket) finalizada");

// ── 5) TORNEOS EN CURSO (parciales) ──

// Liga individual en curso (8) — solo se juegan las primeras fechas
$t = $crearTorneo(['nombre'=>'Liga Verano (en juego)','tipo'=>'liga','modalidad'=>'individual','org'=>2,'empates'=>true]);
$inscribir($t,'individual', $sub($pids,1,8));   $ligaService->generarFixture($t);
// cargar solo las rondas 1 a 3
foreach ([1,2,3] as $rn) {
    $ids = $db->query("SELECT e.id FROM enfrentamientos e JOIN rondas r ON r.id=e.ronda_id WHERE e.torneo_id=$t AND r.numero=$rn AND e.estado IN ('pendiente','en_curso') AND e.es_bye=0")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $eid) { [$pa,$pb]=$randScore(false); $resService->cargar((int)$eid,$pa,$pb,1); }
}
line("  ◔ Liga Verano (8 jug.) EN CURSO (3 fechas jugadas)");

// Suizo en curso (8, 5 rondas) — solo 2 rondas
$t = $crearTorneo(['nombre'=>'Suizo Nocturno (en juego)','tipo'=>'suizo','modalidad'=>'individual','org'=>3,'rondas'=>5,'empates'=>true]);
$inscribir($t,'individual', $sub($pids,4,8));   $suizoService->generarPrimeraRonda($t); $jugarSuizo($t,5,2,false);
line("  ◔ Suizo Nocturno (8 jug.) EN CURSO (2/5 rondas)");

// ── 6) TORNEOS ABIERTOS (inscripción/borrador) ──
$t = $crearTorneo(['nombre'=>'Copa Primavera (inscripción abierta)','tipo'=>'eliminacion_directa','modalidad'=>'individual','org'=>2]);
$inscribir($t,'individual', $sub($pids,0,5)); // inscritos pero sin generar
line("  ○ Copa Primavera EN INSCRIPCIÓN (5 anotados)");

$t = $crearTorneo(['nombre'=>'Torneo de Equipos 2027','tipo'=>'liga','modalidad'=>'equipos','org'=>3]);
$inscribir($t,'equipos', $sub($eids,2,4));
line("  ○ Torneo de Equipos 2027 EN INSCRIPCIÓN (4 equipos)");

// ── Resumen ──
line('');
$c = fn(string $t) => (int)$db->query("SELECT COUNT(*) FROM $t")->fetchColumn();
line('=== RESUMEN ===');
line("Participantes: {$c('participantes')} | Equipos: {$c('equipos')} | Torneos: {$c('torneos')}");
line("Inscripciones: {$c('inscripciones')} | Enfrentamientos: {$c('enfrentamientos')} | Resultados: {$c('resultados')}");
line("Rondas: {$c('rondas')} | Tabla posiciones: {$c('tabla_posiciones')}");
$camp = (int)$db->query("SELECT COUNT(*) FROM torneos WHERE campeon_participante_id IS NOT NULL OR campeon_equipo_id IS NOT NULL")->fetchColumn();
line("Torneos con campeón: $camp");
line('Listo. Datos de demostración regenerados.');
