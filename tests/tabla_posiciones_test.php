<?php
declare(strict_types=1);

/**
 * Test de integración del recálculo de la tabla de posiciones.
 *
 * Ejercita TablaPosicionesService::recalcular() de verdad (vía ResultadoService,
 * que es quien la dispara) contra una base de prueba, con un cuadro de resultados
 * fijo del que se conocen de antemano todas las cifras.
 *
 * Cubre:
 *   - Acumulación correcta de PJ/PG/PE/PP/PF/PC/diferencia/puntos.
 *   - Orden descendente por puntos.
 *   - Desempate por diferencia cuando dos comparten puntos.
 *   - Puntuación configurable por torneo (puntos_victoria/empate/derrota).
 *   - Recálculo tras corregir un resultado ya cargado.
 *   - byes_recibidos en Sistema Suizo.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/tabla_posiciones_test.php
 */

require __DIR__ . '/bootstrap.php';

final class TablaPosicionesTest extends TestCase
{
    /** @var int[] ids de los 4 participantes, en orden A, B, C, D */
    private array $pids = [];
    private int $torneoId = 0;

    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);
        $this->pids = [];
        $this->torneoId = 0;
    }

    /**
     * Arma una liga de 4 y carga este cuadro (el ganador marca, el perdedor 0):
     *
     *   A-B → gana B 1-0      A-C → gana A 5-0      A-D → gana A 5-0
     *   B-C → gana B 1-0      B-D → gana D 1-0      C-D → gana C 3-0
     *
     * Tabla esperada:
     *   1º A  6 pts  PF 10  PC 1  dif  +9
     *   2º B  6 pts  PF  2  PC 1  dif  +1
     *   3º C  3 pts  PF  3  PC 6  dif  -3
     *   4º D  3 pts  PF  1  PC 8  dif  -7
     */
    private function armarLigaConocida(array $opcionesTorneo = []): void
    {
        $this->pids     = Fixtures::participantes(4, 'Jug');
        $this->torneoId = Fixtures::torneo('liga', array_merge(['nombre' => 'Liga de prueba'], $opcionesTorneo));
        Fixtures::inscribir($this->torneoId, $this->pids);
        (new LigaService())->generarFixture($this->torneoId);

        // Marcadores por par de índices (0=A, 1=B, 2=C, 3=D): [puntos del menor, del mayor]
        $reglas = [
            '0-1' => [0, 1],
            '0-2' => [5, 0],
            '0-3' => [5, 0],
            '1-2' => [1, 0],
            '1-3' => [0, 1],
            '2-3' => [3, 0],
        ];

        $indice = array_flip($this->pids);
        $svc    = new ResultadoService();

        $partidos = $this->db->query(
            "SELECT id, participante_a_id, participante_b_id
             FROM enfrentamientos WHERE torneo_id = {$this->torneoId} AND es_bye = 0
             ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($partidos as $p) {
            $ia = $indice[(int)$p['participante_a_id']];
            $ib = $indice[(int)$p['participante_b_id']];
            $clave = min($ia, $ib) . '-' . max($ia, $ib);
            [$menor, $mayor] = $reglas[$clave];
            $puntosA = $ia < $ib ? $menor : $mayor;
            $puntosB = $ia < $ib ? $mayor : $menor;
            $svc->cargar((int)$p['id'], (float)$puntosA, (float)$puntosB, Fixtures::ADMIN);
        }
    }

    /** Fila de la tabla del participante con índice $i (0=A…3=D). */
    private function fila(int $i): array
    {
        $pid = $this->pids[$i];
        foreach ((new TablaPosicionesService())->getByTorneo($this->torneoId) as $row) {
            if ((int)$row['participante_id'] === $pid) return $row;
        }
        $this->fallar("No hay fila de tabla para el participante índice $i (id $pid).");
    }

    public function test_acumula_partidos_puntos_y_diferencia_con_las_cifras_exactas(): void
    {
        $this->armarLigaConocida();

        $esperado = [
            // i => [pj, pg, pe, pp, pf, pc, diferencia, puntos]
            0 => [3, 2, 0, 1, 10, 1,  9, 6],
            1 => [3, 2, 0, 1,  2, 1,  1, 6],
            2 => [3, 1, 0, 2,  3, 6, -3, 3],
            3 => [3, 1, 0, 2,  1, 8, -7, 3],
        ];

        foreach ($esperado as $i => [$pj, $pg, $pe, $pp, $pf, $pc, $dif, $pts]) {
            $f = $this->fila($i);
            $letra = ['A', 'B', 'C', 'D'][$i];
            $this->assertSame($pj,  (int)$f['pj'],         "$letra PJ");
            $this->assertSame($pg,  (int)$f['pg'],         "$letra PG");
            $this->assertSame($pe,  (int)$f['pe'],         "$letra PE");
            $this->assertSame($pp,  (int)$f['pp'],         "$letra PP");
            $this->assertSame($pf,  (int)$f['pf'],         "$letra PF");
            $this->assertSame($pc,  (int)$f['pc'],         "$letra PC");
            $this->assertSame($dif, (int)$f['diferencia'], "$letra diferencia");
            $this->assertSame($pts, (int)$f['puntos'],     "$letra puntos");
        }
    }

    public function test_ordena_de_mayor_a_menor_puntaje(): void
    {
        $this->armarLigaConocida();
        $tabla = (new TablaPosicionesService())->getByTorneo($this->torneoId);

        $this->assertCount(4, $tabla, 'la tabla debe tener una fila por inscrito');

        $puntos = array_map(fn($r) => (int)$r['puntos'], $tabla);
        $this->assertSame([6, 6, 3, 3], $puntos, 'puntos en orden de posición');

        $posiciones = array_map(fn($r) => (int)$r['posicion'], $tabla);
        $this->assertSame([1, 2, 3, 4], $posiciones, 'posiciones consecutivas desde 1');

        // El puntero tiene que ser el de más puntos, no el de menos.
        $this->assertSame($this->pids[0], (int)$tabla[0]['participante_id'], 'el 1.º debe ser A');
        $this->assertSame($this->pids[3], (int)$tabla[3]['participante_id'], 'el último debe ser D');
    }

    public function test_desempata_por_diferencia_cuando_hay_igualdad_de_puntos(): void
    {
        $this->armarLigaConocida();
        $tabla = (new TablaPosicionesService())->getByTorneo($this->torneoId);

        // A y B empatan en 6 puntos; A tiene +9 de diferencia y B +1.
        $this->assertSame(6, (int)$tabla[0]['puntos']);
        $this->assertSame(6, (int)$tabla[1]['puntos']);
        $this->assertGreaterThan((int)$tabla[1]['diferencia'], (int)$tabla[0]['diferencia'],
            'con puntos iguales, primero va el de mejor diferencia');
        $this->assertSame($this->pids[0], (int)$tabla[0]['participante_id'], 'A (+9) por encima de B (+1)');

        // Lo mismo abajo: C (-3) por encima de D (-7).
        $this->assertSame($this->pids[2], (int)$tabla[2]['participante_id'], 'C (-3) por encima de D (-7)');
    }

    public function test_respeta_la_puntuacion_configurada_del_torneo(): void
    {
        // Mismo cuadro, pero la victoria vale 2 en lugar de 3.
        $this->armarLigaConocida(['puntos_victoria' => 2, 'puntos_empate' => 1, 'puntos_derrota' => 0]);

        $this->assertSame(4, (int)$this->fila(0)['puntos'], 'A: 2 victorias x 2 puntos');
        $this->assertSame(4, (int)$this->fila(1)['puntos'], 'B: 2 victorias x 2 puntos');
        $this->assertSame(2, (int)$this->fila(2)['puntos'], 'C: 1 victoria x 2 puntos');
        $this->assertSame(2, (int)$this->fila(3)['puntos'], 'D: 1 victoria x 2 puntos');
    }

    public function test_recalcula_la_tabla_al_corregir_un_resultado(): void
    {
        $this->armarLigaConocida();

        // Antes: A primero con 6 puntos y +9.
        $this->assertSame(1, (int)$this->fila(0)['posicion'], 'A arranca 1.º');
        $this->assertSame(6, (int)$this->fila(1)['puntos'],  'B arranca con 6');

        // Se corrige A vs C: ya no fue 5-0 para A sino 0-1 para C.
        $partido = $this->db->query(
            "SELECT id, participante_a_id FROM enfrentamientos
             WHERE torneo_id = {$this->torneoId} AND es_bye = 0
               AND (participante_a_id, participante_b_id) IN (({$this->pids[0]}, {$this->pids[2]}), ({$this->pids[2]}, {$this->pids[0]}))
             LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $aEsA = (int)$partido['participante_a_id'] === $this->pids[0];

        (new ResultadoService())->corregir(
            (int)$partido['id'],
            $aEsA ? 0.0 : 1.0,
            $aEsA ? 1.0 : 0.0,
            'Acta mal transcripta en la planilla',
            Fixtures::ADMIN
        );

        // Después: A pierde esa victoria (6 → 3) y C la gana (3 → 6).
        $this->assertSame(3, (int)$this->fila(0)['puntos'], 'A queda con 3 puntos');
        $this->assertSame(6, (int)$this->fila(2)['puntos'], 'C pasa a 6 puntos');
        $this->assertSame(5, (int)$this->fila(0)['pf'],     'A pierde los 5 PF de ese partido');
        $this->assertSame(4, (int)$this->fila(2)['pf'],     'C suma 1 PF (3 + 1)');

        // Y el orden se rehace: B (6, +1) y C (6, …) arriba, A ya no es puntero.
        $tabla = (new TablaPosicionesService())->getByTorneo($this->torneoId);
        $this->assertNotSame($this->pids[0], (int)$tabla[0]['participante_id'],
            'A ya no puede seguir siendo 1.º tras perder el partido');
    }

    public function test_cuenta_los_byes_recibidos_en_sistema_suizo(): void
    {
        // 5 inscritos → cantidad impar → una ronda con bye.
        [$torneoId, $pids] = Fixtures::torneoArrancado('suizo', 5, ['rondas_suizo' => 3]);

        $conBye = (int) $this->db->query(
            "SELECT COUNT(*) FROM enfrentamientos WHERE torneo_id = $torneoId AND es_bye = 1"
        )->fetchColumn();
        $this->assertSame(1, $conBye, 'la ronda 1 de un suizo impar deja exactamente un bye');

        $byes = (int) $this->db->query(
            "SELECT SUM(byes_recibidos) FROM tabla_posiciones WHERE torneo_id = $torneoId"
        )->fetchColumn();
        $this->assertSame(1, $byes, 'la tabla registra ese bye');

        $filas = (int) $this->db->query(
            "SELECT COUNT(*) FROM tabla_posiciones WHERE torneo_id = $torneoId"
        )->fetchColumn();
        $this->assertSame(5, $filas, 'hay una fila por inscrito');
    }
}

exit(TestCase::ejecutar(TablaPosicionesTest::class));
