<?php
declare(strict_types=1);

/**
 * Test de las dos banderas de puntuación que estaban muertas.
 *
 * `torneos.usa_puntos_favor` y `torneos.requiere_desempate_final` se guardaban y
 * ninguna consulta las leía. Se resolvieron distinto porque no tenían el mismo
 * problema:
 *
 *   - `usa_puntos_favor` se quedó y ahora decide el orden de la tabla. De paso
 *     se arregló un error de dato: el formulario nunca mandaba el campo y el
 *     servicio hacía `isset(...) ? 1 : 0`, con lo cual guardar un torneo desde
 *     la app la apagaba en silencio, pisando el DEFAULT 1 del esquema.
 *   - `requiere_desempate_final` se eliminó: su apagado no tiene ninguna
 *     implementación razonable (ver la migración 2026_09_banderas_puntuacion).
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/puntos_favor_test.php
 */

require __DIR__ . '/bootstrap.php';

final class PuntosFavorTest extends TestCase
{
    private TorneoService  $torneos;
    private TorneoModel    $torneoModel;

    protected function setUp(): void
    {
        $this->torneos     = new TorneoService();
        $this->torneoModel = new TorneoModel();
        Fixtures::reset($this->db);
    }

    /**
     * Liga de 3 jugadores donde los tres terminan con los mismos puntos y los
     * mismos partidos ganados, y SOLO se distinguen por la diferencia.
     *
     * Los marcadores se eligen para que el orden por diferencia sea el inverso
     * del orden por id: así, si el criterio se aplica, el resultado no puede
     * confundirse con el orden de inserción.
     *
     *   C gana a A 10-0    A: dif -9   (id más chico)
     *   A gana a B  1-0    B: dif  0
     *   B gana a C  1-0    C: dif +9   (id más grande)
     *
     * @return array{torneo:int, ids:int[]}
     */
    private function ligaConDiferenciasDistintas(bool $usaPuntosFavor): array
    {
        $ids      = Fixtures::participantes(3, 'Jugador');
        [$a, $b, $c] = $ids;

        $torneoId = Fixtures::torneo('liga', [
            'usa_puntos_favor' => $usaPuntosFavor ? '1' : '0',
            'permite_empates'  => null,
        ]);
        Fixtures::inscribir($torneoId, $ids);

        (new LigaService())->generarFixture($torneoId);

        // Se cargan los marcadores buscando cada cruce por sus dos rivales.
        $marcadores = [
            [$c, $a, 10, 0],
            [$a, $b,  1, 0],
            [$b, $c,  1, 0],
        ];
        // partidosPendientes() devuelve ids; el cruce se busca por separado.
        $resultados = new ResultadoService();
        $enfModel   = new EnfrentamientoModel();

        foreach (Fixtures::partidosPendientes($torneoId) as $enfId) {
            $enf = $enfModel->findById($enfId);
            $pa  = (int) $enf['participante_a_id'];
            $pb  = (int) $enf['participante_b_id'];

            foreach ($marcadores as [$gana, $pierde, $pg, $pp]) {
                if ($pa === $gana && $pb === $pierde) {
                    $resultados->cargar($enfId, (float)$pg, (float)$pp, Fixtures::ADMIN);
                    continue 2;
                }
                if ($pa === $pierde && $pb === $gana) {
                    $resultados->cargar($enfId, (float)$pp, (float)$pg, Fixtures::ADMIN);
                    continue 2;
                }
            }
            $this->fallar("cruce inesperado entre {$pa} y {$pb}");
        }

        return ['torneo' => $torneoId, 'ids' => $ids];
    }

    /** @return int[] ids de participante en el orden en que quedó la tabla */
    private function ordenDeLaTabla(int $torneoId): array
    {
        return array_map(
            fn(array $f) => (int) $f['participante_id'],
            (new TablaPosicionesService())->getByTorneo($torneoId)
        );
    }

    // ─── El criterio ordena, o no, según la bandera ─────────────────────────

    public function test_con_puntos_a_favor_la_diferencia_define_el_orden(): void
    {
        ['torneo' => $torneoId, 'ids' => [$a, $b, $c]] = $this->ligaConDiferenciasDistintas(true);

        $tabla = (new TablaPosicionesService())->getByTorneo($torneoId);

        // Control del escenario: los tres empatan en puntos y en ganados.
        foreach ($tabla as $fila) {
            $this->assertSame(3, (int)$fila['puntos'], 'los tres deberían tener 3 puntos');
            $this->assertSame(1, (int)$fila['pg'], 'los tres deberían tener 1 partido ganado');
        }

        $this->assertSame([$c, $b, $a], $this->ordenDeLaTabla($torneoId),
            'con el criterio activo manda la diferencia: +9, 0, -9');
    }

    public function test_sin_puntos_a_favor_la_diferencia_se_ignora(): void
    {
        ['torneo' => $torneoId, 'ids' => [$a, $b, $c]] = $this->ligaConDiferenciasDistintas(false);

        $tabla = (new TablaPosicionesService())->getByTorneo($torneoId);
        // El escenario es el mismo: las diferencias siguen calculándose y
        // mostrándose, lo que cambia es que no ordenan.
        $porId = [];
        foreach ($tabla as $fila) $porId[(int)$fila['participante_id']] = (int)$fila['diferencia'];
        $this->assertSame(-9, $porId[$a]);
        $this->assertSame(9,  $porId[$c]);

        $this->assertSame([$a, $b, $c], $this->ordenDeLaTabla($torneoId),
            'sin el criterio quedan empatados en todo y ordena el id, que es el desempate técnico');
    }

    // ─── El bug de coerción silenciosa ──────────────────────────────────────

    public function test_guardar_un_torneo_no_apaga_los_puntos_a_favor(): void
    {
        // El formulario manda un hidden en 0 y la casilla lo pisa con 1. Antes
        // no mandaba nada y el valor llegaba null, que `isset()` lee como
        // ausente: el torneo se guardaba con 0 pisando el DEFAULT 1 del esquema.
        $torneoId = Fixtures::torneo('liga', ['usa_puntos_favor' => '1']);
        $this->assertSame(1, (int)$this->torneoModel->findById($torneoId)['usa_puntos_favor']);

        // Edición que no toca el criterio: tiene que conservarlo.
        $this->torneos->editar($torneoId, [
            'nombre'         => 'Liga renombrada',
            'tipo_torneo_id' => (int)$this->torneoModel->findById($torneoId)['tipo_torneo_id'],
            'modalidad'      => 'individual',
            'estado'         => 'inscripcion',
            'organizador_id' => Fixtures::ORGANIZADOR,
            'creado_por'     => Fixtures::ADMIN,   // en CLI no hay sesion: Auth::id() es null
            'usa_puntos_favor' => '1',
            'fecha_inicio'   => date('Y-m-d'),
            'fecha_fin'      => date('Y-m-d', strtotime('+30 days')),
        ]);

        $this->assertSame(1, (int)$this->torneoModel->findById($torneoId)['usa_puntos_favor'],
            'editar un torneo no puede apagarle el criterio de desempate');
    }

    public function test_apagar_el_criterio_desde_el_formulario_funciona(): void
    {
        // El hidden manda '0' cuando la casilla está destildada: eso SÍ tiene que
        // apagarlo. Si no, la casilla no serviría de nada.
        $torneoId = Fixtures::torneo('liga', ['usa_puntos_favor' => '0']);
        $this->assertSame(0, (int)$this->torneoModel->findById($torneoId)['usa_puntos_favor']);
    }

    public function test_sin_el_dato_vale_el_default_del_esquema(): void
    {
        // Un torneo creado por un script (el seed, un test) que no menciona la
        // clave tiene que quedar en 1, como dice el DEFAULT de la columna, y no
        // en 0 por un `isset()` sobre una clave ausente.
        $tipo = (new TipoTorneoModel())->findBySlug('liga');
        $torneoId = $this->torneos->crear([
            'nombre'         => 'Torneo sin la clave',
            'tipo_torneo_id' => (int)$tipo['id'],
            'modalidad'      => 'individual',
            'estado'         => 'inscripcion',
            'creado_por'     => Fixtures::ADMIN,
            'organizador_id' => Fixtures::ORGANIZADOR,
            'fecha_inicio'   => date('Y-m-d'),
            'fecha_fin'      => date('Y-m-d', strtotime('+30 days')),
        ]);

        $this->assertSame(1, (int)$this->torneoModel->findById($torneoId)['usa_puntos_favor']);
    }

    // ─── El campeón se decide con el mismo criterio que la tabla ────────────

    public function test_el_empate_en_cima_mira_lo_mismo_que_el_orden(): void
    {
        // Con el criterio APAGADO, los tres quedan empatados en todo lo que el
        // torneo mira, así que el sistema no puede coronar campeón: crea una
        // ronda de desempate. Con el criterio ACTIVO hay líder y no la crea.
        // Que el orden y la decisión del campeón usaran criterios distintos
        // dejaría una tabla con un puntero que el sistema no reconoce.
        ['torneo' => $sinCriterio] = $this->ligaConDiferenciasDistintas(false);
        ['torneo' => $conCriterio] = $this->ligaConDiferenciasDistintas(true);

        $desempates = fn(int $id) => (int) $this->db->query(
            "SELECT COUNT(*) FROM rondas WHERE torneo_id = {$id} AND nombre LIKE 'Desempate%'"
        )->fetchColumn();

        $this->assertGreaterThan(0, $desempates($sinCriterio),
            'sin el criterio los dos primeros empatan en todo: hay que desempatar jugando');
        $this->assertSame(0, $desempates($conCriterio),
            'con el criterio hay un líder por diferencia: no corresponde desempate');

        // Y el torneo con líder claro sí tiene campeón.
        $torneo = $this->torneoModel->findById($conCriterio);
        $this->assertNotNull($torneo['campeon_participante_id']);
    }

    // ─── La columna eliminada ───────────────────────────────────────────────

    public function test_la_columna_requiere_desempate_final_ya_no_existe(): void
    {
        $existe = (int) $this->db->query(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'torneos'
                AND column_name = 'requiere_desempate_final'"
        )->fetchColumn();

        $this->assertSame(0, $existe,
            'la bandera se retiró: su apagado no tenía ninguna implementación defendible');
    }

    public function test_el_codigo_ya_no_menciona_la_bandera_eliminada(): void
    {
        // Una columna que se va del esquema pero sigue nombrada en el código es
        // un error en diferido: el INSERT rompe recién cuando alguien lo corre.
        $sobrantes = [];
        foreach (['app/controllers', 'app/services', 'app/models', 'app/views', 'core', 'database'] as $dir) {
            $ruta = dirname(__DIR__) . '/' . $dir;
            $it   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ruta));
            foreach ($it as $archivo) {
                if (!$archivo->isFile()) continue;
                if (!in_array($archivo->getExtension(), ['php', 'sql'], true)) continue;
                // La migración la nombra a propósito: es la que la da de baja.
                if (str_contains($archivo->getFilename(), 'banderas_puntuacion')) continue;

                if (str_contains((string) file_get_contents($archivo->getPathname()), 'requiere_desempate_final')) {
                    $sobrantes[] = $dir . '/' . $archivo->getFilename();
                }
            }
        }

        $this->assertCount(0, $sobrantes, 'todavía la nombran: ' . implode(', ', $sobrantes));
    }
}

exit(TestCase::ejecutar(PuntosFavorTest::class));
