<?php
declare(strict_types=1);

/**
 * Test de integración del desempate por partido extra.
 *
 * Reemplaza a la versión que simulaba la lógica: ahora se juegan torneos reales
 * y se comprueba lo que quedó en la base tras pasar por LigaService /
 * SistemaSuizoService / TablaPosicionesService.
 *
 * Regla bajo prueba (DesempateTrait + intentarFinalizar):
 *   - Si los dos primeros terminan empatados en TODAS las métricas de orden,
 *     se genera una ronda «Desempate N» con un único partido entre ellos.
 *   - Ese partido suma a la tabla como uno más.
 *   - Si hay ganador, rompe el empate y ese queda campeón.
 *   - Si vuelve a empatar, se genera otro desempate, sin límite.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/tiebreak_test.php
 */

require __DIR__ . '/bootstrap.php';

final class DesempateTest extends TestCase
{
    private int $torneoId = 0;
    /** @var int[] */
    private array $pids = [];

    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);
        $this->torneoId = 0;
        $this->pids     = [];
    }

    /**
     * Liga de 2 con empates permitidos: un solo partido, que se carga empatado.
     * Eso deja a los dos punteros idénticos en puntos, diferencia, PF, PG y
     * buchholz, que es exactamente la condición que dispara el desempate.
     */
    private function ligaEmpatadaDeDos(string $formato = 'liga'): void
    {
        $this->pids     = Fixtures::participantes(2, 'Fin');
        $this->torneoId = Fixtures::torneo($formato, [
            'nombre'          => 'Final empatada',
            'permite_empates' => '1',
            'rondas_suizo'    => $formato === 'suizo' ? 2 : null,
        ]);
        Fixtures::inscribir($this->torneoId, $this->pids);

        if ($formato === 'suizo') {
            (new SistemaSuizoService())->generarPrimeraRonda($this->torneoId);
        } else {
            (new LigaService())->generarFixture($this->torneoId);
        }

        $partidos = Fixtures::partidosPendientes($this->torneoId);
        (new ResultadoService())->cargar($partidos[0], 1.0, 1.0, Fixtures::ADMIN);
    }

    /** @return array[] rondas «Desempate N» del torneo, ordenadas por número */
    private function rondasDesempate(): array
    {
        return $this->db->query(
            "SELECT * FROM rondas WHERE torneo_id = {$this->torneoId}
               AND nombre LIKE 'Desempate%' ORDER BY numero"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function torneo(): array
    {
        return (new TorneoModel())->findById($this->torneoId);
    }

    /** Juega el desempate abierto con el marcador dado. */
    private function jugarDesempate(float $a, float $b): void
    {
        $pendientes = Fixtures::partidosPendientes($this->torneoId);
        $this->assertCount(1, $pendientes, 'debería haber exactamente un desempate abierto');
        (new ResultadoService())->cargar($pendientes[0], $a, $b, Fixtures::ADMIN);
    }

    public function test_el_empate_en_la_cima_genera_una_ronda_de_desempate(): void
    {
        $this->ligaEmpatadaDeDos();

        $desempates = $this->rondasDesempate();
        $this->assertCount(1, $desempates, 'se generó la ronda de desempate');
        $this->assertSame('Desempate 1', $desempates[0]['nombre']);

        $partidosDelDesempate = (int) $this->db->query(
            "SELECT COUNT(*) FROM enfrentamientos WHERE ronda_id = " . (int)$desempates[0]['id']
        )->fetchColumn();
        $this->assertSame(1, $partidosDelDesempate, 'el desempate tiene un único partido');

        $torneo = $this->torneo();
        $this->assertSame('en_curso', $torneo['estado'], 'el torneo no se finaliza con el empate sin resolver');
        $this->assertTrue(
            $torneo['campeon_participante_id'] === null,
            'no puede haber campeón mientras el desempate esté pendiente'
        );
    }

    public function test_el_desempate_se_juega_entre_los_dos_empatados(): void
    {
        $this->ligaEmpatadaDeDos();

        $enf = $this->db->query(
            "SELECT e.participante_a_id, e.participante_b_id
             FROM enfrentamientos e JOIN rondas r ON r.id = e.ronda_id
             WHERE e.torneo_id = {$this->torneoId} AND r.nombre = 'Desempate 1'"
        )->fetch(PDO::FETCH_ASSOC);

        $enJuego = [(int)$enf['participante_a_id'], (int)$enf['participante_b_id']];
        sort($enJuego);
        $esperados = $this->pids;
        sort($esperados);
        $this->assertSame($esperados, $enJuego, 'los que juegan el desempate son los dos punteros');
    }

    public function test_un_ganador_en_el_desempate_define_el_campeon(): void
    {
        $this->ligaEmpatadaDeDos();
        $this->jugarDesempate(2.0, 1.0);

        $torneo = $this->torneo();
        $this->assertSame('finalizado', $torneo['estado'], 'el torneo se finaliza');
        $this->assertNotNull($torneo['campeon_participante_id'], 'quedó un campeón definido');

        $tabla = (new TablaPosicionesService())->getByTorneo($this->torneoId);
        $this->assertSame(
            (int)$torneo['campeon_participante_id'],
            (int)$tabla[0]['participante_id'],
            'el campeón es el 1.º de la tabla'
        );
        // El desempate suma como un partido más: 1 (empate) + 3 (victoria) = 4.
        $this->assertSame(4, (int)$tabla[0]['puntos'], 'el desempate suma a la tabla');
        $this->assertSame(1, (int)$tabla[1]['puntos'], 'el perdedor conserva el punto del empate');
        $this->assertCount(1, $this->rondasDesempate(), 'no hizo falta un segundo desempate');
    }

    public function test_un_empate_en_el_desempate_genera_otro_desempate(): void
    {
        $this->ligaEmpatadaDeDos();
        $this->jugarDesempate(1.0, 1.0);

        $desempates = $this->rondasDesempate();
        $this->assertCount(2, $desempates, 'el empate encadena un segundo desempate');
        $this->assertSame('Desempate 2', $desempates[1]['nombre']);
        $this->assertSame('en_curso', $this->torneo()['estado'], 'sigue sin haber campeón');
    }

    public function test_la_cadena_de_desempates_no_tiene_limite(): void
    {
        $this->ligaEmpatadaDeDos();

        // Cuatro empates seguidos y recién ahí un ganador (el caso más largo del
        // test anterior, que hasta ahora solo se simulaba).
        for ($i = 0; $i < 4; $i++) {
            $this->jugarDesempate(1.0, 1.0);
        }
        $this->assertCount(5, $this->rondasDesempate(), 'cuatro empates dejan cinco desempates abiertos en total');
        $this->assertSame('en_curso', $this->torneo()['estado']);

        $this->jugarDesempate(1.0, 2.0);

        $torneo = $this->torneo();
        $this->assertSame('finalizado', $torneo['estado'], 'el quinto desempate define');
        $this->assertCount(5, $this->rondasDesempate(), 'no se genera un sexto desempate');

        // 5 empates x 1 punto + 3 de la victoria final = 8 para el campeón.
        $tabla = (new TablaPosicionesService())->getByTorneo($this->torneoId);
        $this->assertSame(8, (int)$tabla[0]['puntos']);
        $this->assertSame(5, (int)$tabla[1]['puntos']);
        $this->assertSame((int)$torneo['campeon_participante_id'], (int)$tabla[0]['participante_id']);
    }

    public function test_el_suizo_usa_el_mismo_mecanismo_de_desempate(): void
    {
        $this->ligaEmpatadaDeDos('suizo');

        // Con 2 inscritos y 2 rondas configuradas, la ronda 2 es la revancha
        // obligada; se la juega empatada para llegar empatados al final.
        (new SistemaSuizoService())->generarSiguienteRonda($this->torneoId);
        $pendientes = Fixtures::partidosPendientes($this->torneoId);
        (new ResultadoService())->cargar($pendientes[0], 2.0, 2.0, Fixtures::ADMIN);

        $this->assertCount(1, $this->rondasDesempate(), 'el suizo también genera «Desempate 1»');

        $this->jugarDesempate(3.0, 0.0);
        $this->assertSame('finalizado', $this->torneo()['estado'], 'y lo cierra igual que la liga');
        $this->assertNotNull($this->torneo()['campeon_participante_id']);
    }
}

exit(TestCase::ejecutar(DesempateTest::class));
