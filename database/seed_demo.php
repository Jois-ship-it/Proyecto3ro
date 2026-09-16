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

// El .env puede no existir: en Docker la configuracion llega por variables de
// entorno del contenedor. Las variables reales tienen prioridad sobre el archivo.
$archivoEnv = BASE_PATH . '/.env';
if (is_readable($archivoEnv)) {
    foreach (file($archivoEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $l = trim($l); if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) continue;
        [$k, $v] = explode('=', $l, 2); $_ENV[trim($k)] = trim($v, " \t\"'");
    }
}
foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_ENV'] as $clave) {
    $valor = getenv($clave);
    if ($valor !== false && $valor !== '') $_ENV[$clave] = $valor;
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
// Uno por cada cuenta con rol 'participante' —así todas las cuentas de prueba
// tienen perfil, pueden iniciar sesión y aparecen en el sitio— más un puñado de
// jugadores sin cuenta, que es un caso real: el organizador los anota a mano.
$cuentas = $db->query(
    "SELECT u.id, u.nombre, u.email FROM usuarios u
     JOIN roles r ON r.id = u.rol_id
     WHERE r.nombre = 'participante'
     ORDER BY u.id"
)->fetchAll(PDO::FETCH_ASSOC);

$nickDesdeEmail = fn(string $email): string => ucfirst(explode('@', $email)[0]);

$pids = [];
foreach ($cuentas as $c) {
    $pids[] = $partModel->insert([
        'usuario_id' => (int)$c['id'],
        'nombre'     => $c['nombre'],
        'nick'       => $nickDesdeEmail($c['email']),
        'email'      => $c['email'],
        'documento'  => str_pad((string)(4000000 + (int)$c['id'] * 137), 8, '0', STR_PAD_LEFT),
        'estado'     => 'activo',
    ]);
}

$sinCuenta = [
    ['Marcelo Da Rosa', 'MarceDR'], ['Elena Zubillaga', 'EleZ'],
    ['Wilson Acosta',   'WilsonA'], ['Norma Cristiani', 'NormaC'],
    ['Óscar Buzó',      'OscarB'],  ['Teresa Lavagna',  'TereL'],
    ['Aníbal Gadea',    'AniG'],    ['Estela Montaño',  'EsteM'],
];
foreach ($sinCuenta as $k => [$nombre, $nick]) {
    $pids[] = $partModel->insert([
        'nombre'    => $nombre,
        'nick'      => $nick,
        'documento' => str_pad((string)(5000000 + $k * 211), 8, '0', STR_PAD_LEFT),
        'estado'    => 'activo',
    ]);
}
line('Participantes: ' . count($pids) . ' (' . count($cuentas) . ' con cuenta, ' . count($sinCuenta) . ' sin cuenta)');

// ── 3) EQUIPOS (con descripción) + integrantes ──
// Los diez primeros son los de siempre (los que aparecen en la documentación);
// el resto completa el mínimo de 50 registros que pide la letra, repartidos
// entre las mismas disciplinas para que el listado público sea creible.
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

// Generación del resto: nombre = prefijo + núcleo, con disciplina y categoría
// rotando, de modo que queden repartidos y no todos iguales.
$prefijos = ['Club', 'Unión', 'Academia', 'Asociación', 'Centro', 'Liga', 'Escuela'];
$nucleos  = ['Progreso', 'La Blanqueada', 'Cerro Largo', 'Punta Carretas', 'Las Piedras',
             'Maldonado', 'Salto Grande', 'Paysandú', 'Tacuarembó', 'Rivera',
             'Florida', 'Durazno', 'Treinta y Tres', 'Colonia'];
$disciplinas = ['Fútbol', 'Baloncesto', 'Ajedrez', 'Esports', 'Vóleibol', 'Handball'];

$k = 0;
while (count($equiposDef) < 52) {
    $nombre     = $prefijos[$k % count($prefijos)] . ' ' . $nucleos[intdiv($k, count($prefijos)) % count($nucleos)];
    $disciplina = $disciplinas[$k % count($disciplinas)];
    $equiposDef[] = [
        $nombre,
        $k % 2 === 0 ? 'A' : 'B',
        $disciplina,
        "Equipo de {$disciplina} con plantel estable y participación regular en el circuito.",
    ];
    $k++;
}

$eids = [];
$pi = 0;
foreach ($equiposDef as [$nombre, $cat, $disc, $desc]) {
    $eid = $equipoModel->insert(['nombre'=>$nombre,'categoria'=>$cat,'disciplina'=>$disc,'descripcion'=>$desc,'estado'=>'activo']);
    $eids[] = $eid;
    // 4 integrantes rotando sobre el pool de participantes
    for ($m = 0; $m < 4; $m++) {
        $equipoModel->agregarParticipante($eid, $pids[$pi % count($pids)], $m === 0 ? 'capitan' : 'jugador');
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
        'estado'          => $c['estado'] ?? 'inscripcion',
        'publico'         => '1',
        'creado_por'      => 1,
        'organizador_id'  => $c['org'],
        'permite_empates' => !empty($c['empates']) ? '1' : null,
        'puntos_victoria' => 3, 'puntos_empate' => 1, 'puntos_derrota' => 0,
        'rondas_suizo'    => $c['rondas'] ?? null,
        'bye_suizo'       => 'victoria',
        'nombre_puntos'   => $c['puntos'] ?? 'puntos',
        'min_integrantes_equipo' => $c['modalidad'] === 'equipos' ? 3 : null,
        'fecha_inicio'    => $c['inicio'] ?? date('Y-m-d'),
        'fecha_fin'       => $c['fin']    ?? date('Y-m-d', strtotime('+30 days')),
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

// ── 7) RESTO DE TORNEOS hasta el mínimo que pide la letra ──
// La letra exige un mínimo de 50 registros por componente. En vez de repetir 40
// veces el mismo torneo, la tanda imita la mezcla de una plataforma real: unos
// pocos con historia completa, otros a mitad de camino, y bastantes todavía en
// inscripción o en borrador (que es el estado más común de todos).
//
// Los que se juegan acá son chicos (4 a 8 inscritos) a propósito: simular 40
// torneos grandes multiplicaría por varios minutos lo que tarda este script,
// que además corre en el arranque del contenedor.
line('');
line('Completando el mínimo de 50 registros por componente…');

/** Juega un torneo entero, o solo su arranque si $completo es false. */
$simular = function (int $torneoId, string $slug, int $rondasSuizo, bool $completo)
    use ($db, $resService, $ligaService, $elimService, $suizoService, $cargarPendientes, $jugarSuizo, $randScore): void
{
    if ($slug === 'suizo') {
        $suizoService->generarPrimeraRonda($torneoId);
        $hasta = $completo ? $rondasSuizo : max(1, intdiv($rondasSuizo, 2));
        $jugarSuizo($torneoId, $rondasSuizo, $hasta, false);
        // Un empate exacto en la cima genera una ronda de desempate: si queda
        // pendiente, el torneo nunca llega a 'finalizado'. Se resuelve sin empate.
        if ($completo) $cargarPendientes($torneoId, true);
        return;
    }

    if ($slug === 'liga') {
        $ligaService->generarFixture($torneoId);
    } else {
        $elimService->generarBracket($torneoId);
    }

    if ($completo) {
        $cargarPendientes($torneoId, $slug === 'eliminacion_directa');
        $cargarPendientes($torneoId, true);   // cierra un eventual desempate
        return;
    }

    // Parcial: solo la primera fecha/ronda, para que quede "en curso" de verdad.
    $ids = $db->query(
        "SELECT e.id FROM enfrentamientos e JOIN rondas r ON r.id = e.ronda_id
         WHERE e.torneo_id = {$torneoId} AND r.numero = 1
           AND e.estado IN ('pendiente','en_curso') AND e.es_bye = 0"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $eid) {
        [$pa, $pb] = $randScore($slug === 'eliminacion_directa');
        $resService->cargar((int)$eid, $pa, $pb, 1);
    }
};

// [nombre, tipo, modalidad, organizador, inscritos, destino]
// destino: finalizado | en_curso | inscripcion | borrador | cancelado
$masTorneos = [
    // — Con historia completa —
    ['Copa Otoño de Ajedrez',        'suizo',               'individual',  2, 6, 'finalizado'],
    ['Liga Barrial de Fútbol 5',     'liga',                'individual',  3, 5, 'finalizado'],
    ['Torneo Relámpago Nocturno',    'eliminacion_directa', 'individual', 30, 8, 'finalizado'],
    ['Copa Interclubes de Vóleibol', 'liga',                'equipos',    31, 4, 'finalizado'],
    ['Circuito Esports Verano',      'eliminacion_directa', 'equipos',    32, 4, 'finalizado'],
    ['Abierto de Handball',          'liga',                'equipos',    33, 4, 'finalizado'],
    ['Copa Juvenil de Ajedrez',      'suizo',               'individual',  2, 5, 'finalizado'],
    ['Torneo de Egresados',          'eliminacion_directa', 'individual',  3, 4, 'finalizado'],
    ['Liga Docente 2026',            'liga',                'individual', 30, 5, 'finalizado'],
    ['Copa Aniversario del Club',    'suizo',               'individual', 31, 6, 'finalizado'],

    // — En curso —
    ['Liga Metropolitana (en juego)',  'liga',                'individual',  2, 6, 'en_curso'],
    ['Copa Federal (en juego)',        'eliminacion_directa', 'individual',  3, 8, 'en_curso'],
    ['Suizo de Invierno (en juego)',   'suizo',               'individual', 30, 6, 'en_curso'],
    ['Liga de Clubes B (en juego)',    'liga',                'equipos',    31, 4, 'en_curso'],
    ['Copa Esports Otoño (en juego)',  'eliminacion_directa', 'equipos',    32, 4, 'en_curso'],
    ['Torneo Escolar (en juego)',      'liga',                'individual', 33, 5, 'en_curso'],
    ['Suizo Universitario (en juego)', 'suizo',               'individual',  2, 7, 'en_curso'],
    ['Copa Litoral (en juego)',        'eliminacion_directa', 'individual',  3, 4, 'en_curso'],

    // — Inscripción abierta —
    ['Copa Verano 2027',             'liga',                'individual', 30,  6, 'inscripcion'],
    ['Abierto de Ajedrez 2027',      'suizo',               'individual', 31,  8, 'inscripcion'],
    ['Torneo de Baloncesto Juvenil', 'eliminacion_directa', 'equipos',    32,  6, 'inscripcion'],
    ['Liga Femenina de Vóleibol',    'liga',                'equipos',    33,  5, 'inscripcion'],
    ['Copa Esports Invierno 2027',   'eliminacion_directa', 'equipos',     2,  8, 'inscripcion'],
    ['Circuito Nacional de Ajedrez', 'suizo',               'individual',  3, 10, 'inscripcion'],
    ['Liga Interbarrial 2027',       'liga',                'individual', 30,  7, 'inscripcion'],
    ['Copa de la Costa',             'eliminacion_directa', 'individual', 31,  6, 'inscripcion'],
    ['Torneo Mixto de Handball',     'liga',                'equipos',    32,  4, 'inscripcion'],
    ['Abierto Senior de Ajedrez',    'suizo',               'individual', 33,  6, 'inscripcion'],
    ['Copa Primavera de Clubes',     'liga',                'equipos',     2,  6, 'inscripcion'],
    ['Torneo Express de Esports',    'eliminacion_directa', 'equipos',     3,  4, 'inscripcion'],
    ['Liga Amateur 2027',            'liga',                'individual', 30,  8, 'inscripcion'],
    ['Copa Integración',             'eliminacion_directa', 'individual', 31,  5, 'inscripcion'],

    // — Borrador (todavía sin publicar) —
    ['Copa Anual 2028 (borrador)',         'liga',                'individual',  2, 0, 'borrador'],
    ['Torneo de Verano 2028 (borrador)',   'eliminacion_directa', 'individual',  3, 0, 'borrador'],
    ['Liga de Clubes 2028 (borrador)',     'liga',                'equipos',    30, 0, 'borrador'],
    ['Abierto de Ajedrez 2028 (borrador)', 'suizo',               'individual', 31, 0, 'borrador'],
    ['Copa Esports 2028 (borrador)',       'eliminacion_directa', 'equipos',    32, 0, 'borrador'],

    // — Cancelados —
    ['Copa Otoño 2026 (cancelada)',  'liga',                'individual', 33, 4, 'cancelado'],
    ['Torneo Regional (cancelado)',  'eliminacion_directa', 'individual',  2, 4, 'cancelado'],
    ['Liga de Invierno (cancelada)', 'liga',                'equipos',     3, 4, 'cancelado'],
];

$torneoModelSeed   = new TorneoModel();
$porEstado         = ['finalizado' => 0, 'en_curso' => 0, 'inscripcion' => 0, 'borrador' => 0, 'cancelado' => 0];
$desplazamiento    = 0;

foreach ($masTorneos as $idx => [$nombre, $tipo, $modalidad, $org, $cantidad, $destino]) {
    // Fechas coherentes con el estado: lo terminado quedó atrás, lo abierto
    // todavía no empezó. Así el calendario público tiene sentido.
    $diasInicio = match ($destino) {
        'finalizado' => -150 + $idx * 3,
        'cancelado'  => -90  + $idx,
        'en_curso'   => -15  + ($idx % 10),
        default      =>  20  + $idx * 3,
    };
    $inicio = date('Y-m-d', strtotime("{$diasInicio} days"));
    $fin    = date('Y-m-d', strtotime(($diasInicio + 45) . ' days'));

    $rondasSuizo = $tipo === 'suizo' ? max(3, min(5, (int)ceil(log(max($cantidad, 2), 2)) + 1)) : null;

    $torneoId = $crearTorneo([
        'nombre'    => $nombre,
        'tipo'      => $tipo,
        'modalidad' => $modalidad,
        'org'       => $org,
        'estado'    => $destino === 'borrador' ? 'borrador' : 'inscripcion',
        'empates'   => $tipo !== 'eliminacion_directa',
        'rondas'    => $rondasSuizo,
        'inicio'    => $inicio,
        'fin'       => $fin,
    ]);

    if ($cantidad > 0) {
        // Se rota el punto de partida del pool para que no compitan siempre los mismos.
        $pool = $modalidad === 'equipos' ? $eids : $pids;
        $inscribir($torneoId, $modalidad, $sub($pool, $desplazamiento % count($pool), $cantidad));
        $desplazamiento += $cantidad;
    }

    match ($destino) {
        'finalizado' => $simular($torneoId, $tipo, (int)$rondasSuizo, true),
        'en_curso'   => $simular($torneoId, $tipo, (int)$rondasSuizo, false),
        'cancelado'  => $torneoModelSeed->updateEstado($torneoId, 'cancelado'),
        default      => null,   // inscripción y borrador quedan como están
    };

    $porEstado[$destino]++;
}

foreach ($porEstado as $estado => $cuantos) {
    line("  · {$cuantos} torneo(s) agregados en estado {$estado}");
}

// ── 8) CONFIGURACIONES POR TORNEO ──
// configuraciones_torneo es la tabla clave/valor para los datos del evento que
// no tienen columna propia en `torneos`: sede, contacto, cierre de inscripcion
// y observaciones.
//
// Se escribe por ConfiguracionTorneoService y no con un INSERT suelto, como el
// resto del seed usa los servicios del dominio: asi los datos de demostracion
// pasan por las mismas validaciones que los que carga un administrador, y si
// alguna combinacion no fuera valida el seed lo dice en vez de dejarla entrar
// por la puerta de atras.
$configService = new ConfiguracionTorneoService();

$sedes = [
    'Gimnasio Municipal de Montevideo', 'Club Social y Deportivo Progreso',
    'Centro Cultural de Las Piedras',   'Complejo Deportivo UTU',
    'Sala de Ajedrez del Ateneo',       'Arena Esports Pocitos',
    'Polideportivo de Maldonado',       'Club Náutico de Colonia',
];
$contactos = ['torneos@flexarena.uy', 'organizacion@flexarena.uy', 'info@flexarena.uy'];

// Un torneo ABIERTO a inscripcion no puede arrancar hoy ni tener el plazo
// vencido: desde que InscripcionService aplica `cierre_inscripcion`, esos datos
// dejarian la demo con todos los torneos cerrados. Se les corren las fechas
// hacia adelante antes de calcular el cierre.
$stmtFechas = $db->prepare(
    "UPDATE torneos SET fecha_inicio = :ini, fecha_fin = :fin WHERE id = :id"
);
foreach ($db->query("SELECT id FROM torneos WHERE estado = 'inscripcion'")->fetchAll(PDO::FETCH_COLUMN) as $i => $tid) {
    // Escalonadas, para que no arranquen todos el mismo dia.
    $inicio = date('Y-m-d', strtotime('+' . (21 + ($i % 30)) . ' days'));
    $stmtFechas->execute([
        ':ini' => $inicio,
        ':fin' => date('Y-m-d', strtotime($inicio . ' +30 days')),
        ':id'  => (int) $tid,
    ]);
}

$todosLosTorneos = $db->query("SELECT id, fecha_inicio FROM torneos ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$configs = 0;
foreach ($todosLosTorneos as $n => $t) {
    $inicio = $t['fecha_inicio'] ?: date('Y-m-d');
    // El cierre cae tres dias antes del arranque. En los torneos abiertos eso
    // da una fecha futura (las fechas se corrieron arriba); en los que ya se
    // jugaron, una pasada, que es lo que corresponde.
    $cierre = date('Y-m-d', strtotime($inicio . ' -3 days'));

    $claves = [
        'sede'               => $sedes[$n % count($sedes)],
        'contacto'           => $contactos[$n % count($contactos)],
        'cierre_inscripcion' => $cierre,
    ];
    // Una cuarta clave solo en algunos, para que no sean todos idénticos.
    if ($n % 3 === 0) {
        $claves['observaciones'] = 'Se juega con el reglamento de la federación que corresponda al formato.';
    }
    $configs += $configService->guardar((int)$t['id'], $claves, $t);
}
line("Configuraciones de torneo: {$configs}");

// ── 9) SOLICITUDES DE CORRECCIÓN ──
// Los tres estados posibles. Las aprobadas ajustan el marcador SIN cambiar quién
// ganó (le suman un punto al que ya ganaba): así el campeón de cada torneo sigue
// siendo coherente con su historia.
$correccionService = new CorreccionService();

$candidatos = $db->query(
    "SELECT e.id, r.puntos_a, r.puntos_b
     FROM enfrentamientos e
     JOIN resultados r    ON r.enfrentamiento_id = e.id
     JOIN torneos t       ON t.id = e.torneo_id
     JOIN tipos_torneo tt ON tt.id = t.tipo_torneo_id
     WHERE e.estado = 'finalizado' AND e.es_bye = 0
       AND tt.slug = 'liga'          -- la liga no bloquea correcciones por ronda posterior
       AND r.puntos_a <> r.puntos_b  -- con un ganador claro, para no tocar empates
     ORDER BY e.id"
)->fetchAll(PDO::FETCH_ASSOC);

$motivos = [
    'El planillero anotó mal el marcador final, se adjunta el acta firmada.',
    'La mesa de control cargó los puntos invertidos respecto de la planilla.',
    'Se contabilizó un punto de más por un error de suma en el acta.',
    'El acta del partido difiere del resultado cargado en el sistema.',
    'Revisión del video: el último punto no correspondía y hay que descontarlo.',
];
$motivosRechazo = [
    'El acta original coincide con lo cargado; no corresponde la corrección.',
    'La solicitud llegó fuera del plazo reglamentario de 48 horas.',
    'No se adjuntó documentación que respalde el cambio pedido.',
];

$solicitudes = ['pendiente' => 0, 'aprobada' => 0, 'rechazada' => 0];
$objetivoSolicitudes = 55;

foreach ($candidatos as $n => $c) {
    if (array_sum($solicitudes) >= $objetivoSolicitudes) break;

    $pa = (float)$c['puntos_a'];
    $pb = (float)$c['puntos_b'];
    $nuevoA = $pa > $pb ? $pa + 1 : $pa;
    $nuevoB = $pb > $pa ? $pb + 1 : $pb;

    $correccionService->solicitar((int)$c['id'], $nuevoA, $nuevoB, $motivos[$n % count($motivos)], 4 + ($n % 3));

    $solicitudId = (int) $db->query(
        "SELECT id FROM solicitudes_correccion
         WHERE enfrentamiento_id = {$c['id']} AND estado = 'pendiente'
         ORDER BY id DESC LIMIT 1"
    )->fetchColumn();

    // Un tercio se aprueba, un tercio se rechaza y un tercio queda pendiente.
    switch ($n % 3) {
        case 0:
            $correccionService->aprobar($solicitudId, 1);
            $solicitudes['aprobada']++;
            break;
        case 1:
            $correccionService->rechazar($solicitudId, 1, $motivosRechazo[$n % count($motivosRechazo)]);
            $solicitudes['rechazada']++;
            break;
        default:
            $solicitudes['pendiente']++;
    }
}
line('Solicitudes de corrección: ' . array_sum($solicitudes)
   . " (aprobadas {$solicitudes['aprobada']}, rechazadas {$solicitudes['rechazada']}, pendientes {$solicitudes['pendiente']})");

// ── Resumen ──
line('');
line('=== RESUMEN ===');

// La letra del proyecto pide un mínimo de 50 registros por componente. Los
// catálogos quedan fuera a propósito: roles, tipos de torneo, módulos y
// permisos tienen tantas filas como conceptos existen en el sistema, y
// rellenarlos con entradas inventadas sería ruido, no datos de prueba.
$catalogos = ['roles', 'tipos_torneo', 'modulos', 'permisos'];
$minimo    = 50;

$tablas = $db->query(
    "SELECT table_name FROM information_schema.tables
     WHERE table_schema = DATABASE() ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

$porDebajo = [];
foreach ($tablas as $tabla) {
    $n = (int) $db->query("SELECT COUNT(*) FROM `{$tabla}`")->fetchColumn();
    $esCatalogo = in_array($tabla, $catalogos, true);
    $marca = $esCatalogo ? '(catálogo)' : ($n >= $minimo ? 'OK' : '← POR DEBAJO DE ' . $minimo);
    if (!$esCatalogo && $n < $minimo) $porDebajo[] = "{$tabla} ({$n})";
    line(sprintf('  %-24s %6d  %s', $tabla, $n, $marca));
}

line('');
$camp = (int)$db->query("SELECT COUNT(*) FROM torneos WHERE campeon_participante_id IS NOT NULL OR campeon_equipo_id IS NOT NULL")->fetchColumn();
line("Torneos con campeón: {$camp}");

if ($porDebajo !== []) {
    line('');
    line('ATENCIÓN: estas tablas no llegan al mínimo de ' . $minimo . ': ' . implode(', ', $porDebajo));
}

line('Listo. Datos de demostración regenerados.');
