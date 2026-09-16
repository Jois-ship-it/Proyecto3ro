<?php
declare(strict_types=1);

/**
 * Test de integración del toggle de módulos.
 *
 * Verifica, contra las clases reales y una base de prueba, que desactivar un
 * módulo de formato tiene efecto funcional:
 *   1. El servicio del formato se niega a generar estructura (fixture/bracket/ronda 1).
 *   2. TorneoService::crear() rechaza torneos nuevos de ese formato.
 *   3. El tipo desaparece del <select> que renderiza el formulario de creación.
 *   4. Al editar, el formato del torneo se conserva rotulado como deshabilitado.
 *   5. Un torneo YA EN CURSO de ese formato puede seguir y terminarse.
 *   6. El cambio de estado queda registrado en auditoría.
 *   7. Al reactivar, todo vuelve a funcionar.
 *
 * Los torneos se arman dentro de cada caso y no en setUp(): crear la estructura
 * de los tres formatos para los diez casos multiplicaba por seis el tiempo de la
 * corrida sin agregar cobertura.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/modulos_toggle_test.php
 */

require __DIR__ . '/bootstrap.php';

final class ModulosToggleTest extends TestCase
{
    /** slug del módulo => [clase del servicio, método que genera la estructura] */
    private const FORMATOS = [
        'liga'                => ['LigaService',               'generarFixture'],
        'eliminacion_directa' => ['EliminacionDirectaService', 'generarBracket'],
        'suizo'               => ['SistemaSuizoService',       'generarPrimeraRonda'],
    ];

    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);
    }

    private function servicio(string $slug): object
    {
        $clase = self::FORMATOS[$slug][0];
        return new $clase();
    }

    private function metodo(string $slug): string
    {
        return self::FORMATOS[$slug][1];
    }

    /** Torneo creado e inscrito, pero SIN estructura generada todavía. */
    private function torneoSinArrancar(string $slug): int
    {
        $torneoId = Fixtures::torneo($slug, ['nombre' => "Sin arrancar ($slug)"]);
        Fixtures::inscribir($torneoId, Fixtures::participantes(4));
        return $torneoId;
    }

    private function desactivar(string $slug): array
    {
        $modulo = (new ModuloModel())->findBySlug($slug);
        $r = (new ModuloService())->toggle((int)$modulo['id']);
        $this->assertSame('inactivo', $r['nuevo'], "el módulo «{$slug}» quedó inactivo");
        return $modulo;
    }

    private function reactivar(string $slug): void
    {
        $modulo = (new ModuloModel())->findBySlug($slug);
        $r = (new ModuloService())->toggle((int)$modulo['id']);
        $this->assertSame('activo', $r['nuevo'], "el módulo «{$slug}» volvió a estar activo");
    }

    /**
     * Renderiza la vista REAL del formulario de torneo y devuelve el HTML del
     * <select name="tipo_torneo_id">, para comprobar lo que ve el usuario y no
     * solo lo que devuelve el modelo.
     */
    private function selectDeFormatos(array $tipos, ?array $torneo): string
    {
        $organizadores     = [['id' => 2, 'nombre' => 'Organizador', 'rol_nombre' => 'organizador']];
        $organizadorActual = $torneo['organizador_id'] ?? null;

        ob_start();
        include __DIR__ . '/../app/views/admin/torneo_form.php';
        $html = (string) ob_get_clean();

        $ini = strpos($html, '<select name="tipo_torneo_id"');
        $fin = $ini === false ? false : strpos($html, '</select>', $ini);
        if ($ini === false || $fin === false) $this->fallar('no se pudo renderizar el formulario de torneo');

        return substr($html, $ini, $fin - $ini);
    }

    // ─── Control: con los módulos activos todo funciona ─────────────────────

    public function test_con_los_modulos_activos_se_ofrecen_los_tres_formatos(): void
    {
        $ofrecidos = array_column((new TipoTorneoModel())->findDisponibles(), 'slug');
        sort($ofrecidos);
        $this->assertSame(['eliminacion_directa', 'liga', 'suizo'], $ofrecidos);

        $select = $this->selectDeFormatos((new TipoTorneoModel())->findDisponibles(), null);
        foreach (array_keys(self::FORMATOS) as $slug) {
            $this->assertStringContainsString('data-slug="' . $slug . '"', $select);
        }
    }

    // ─── Con el módulo apagado ──────────────────────────────────────────────

    public function test_un_modulo_apagado_impide_generar_la_estructura(): void
    {
        foreach (array_keys(self::FORMATOS) as $slug) {
            $torneoId = $this->torneoSinArrancar($slug);
            $this->desactivar($slug);

            $metodo = $this->metodo($slug);
            $this->assertThrows(
                fn() => $this->servicio($slug)->$metodo($torneoId),
                'Un administrador debe reactivarlo',
                "$metodo() debe negarse con el módulo «{$slug}» apagado"
            );
            $this->assertSame(0, (int) $this->db->query(
                "SELECT COUNT(*) FROM rondas WHERE torneo_id = $torneoId"
            )->fetchColumn(), 'no se generó ninguna ronda');
        }
    }

    public function test_un_modulo_apagado_impide_crear_torneos_de_ese_formato(): void
    {
        foreach (array_keys(self::FORMATOS) as $slug) {
            $this->desactivar($slug);
            $this->assertThrows(
                fn() => Fixtures::torneo($slug, ['nombre' => "No debería existir ($slug)"]),
                'Un administrador debe reactivarlo',
                "crear() debe rechazar el formato «{$slug}»"
            );
        }
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM torneos')->fetchColumn(),
            'no se creó ningún torneo');
    }

    public function test_un_modulo_apagado_saca_el_formato_del_formulario_de_alta(): void
    {
        foreach (array_keys(self::FORMATOS) as $slug) {
            $this->desactivar($slug);

            $ofrecidos = array_column((new TipoTorneoModel())->findDisponibles(), 'slug');
            $this->assertFalse(in_array($slug, $ofrecidos, true), "«{$slug}» ya no debería ofrecerse");

            $select = $this->selectDeFormatos((new TipoTorneoModel())->findDisponibles(), null);
            $this->assertStringNotContainsString('data-slug="' . $slug . '"', $select,
                "el <select> no debería traer «{$slug}»");
        }
    }

    public function test_al_editar_se_conserva_el_formato_rotulado_como_deshabilitado(): void
    {
        foreach (array_keys(self::FORMATOS) as $slug) {
            $torneoId = Fixtures::torneo($slug, ['nombre' => "Creado antes de apagar ($slug)"]);
            $this->desactivar($slug);

            $tipoId = (int) (new TipoTorneoModel())->findBySlug($slug)['id'];
            $tipos  = (new TipoTorneoModel())->findDisponibles($tipoId);

            $conservado = array_values(array_filter($tipos, fn($t) => (int)$t['id'] === $tipoId));
            $this->assertCount(1, $conservado, "el formato «{$slug}» sigue disponible al editar");
            $this->assertSame(0, (int)$conservado[0]['modulo_activo'], 'pero marcado como inactivo');

            $select = $this->selectDeFormatos($tipos, (new TorneoModel())->findById($torneoId));
            $this->assertStringContainsString('data-slug="' . $slug . '"', $select);
            $this->assertStringContainsString('módulo deshabilitado', $select);
        }
    }

    public function test_el_cambio_de_estado_queda_auditado(): void
    {
        foreach (array_keys(self::FORMATOS) as $slug) {
            $modulo = $this->desactivar($slug);

            $filas = (int) $this->db->query(
                "SELECT COUNT(*) FROM auditoria
                 WHERE accion = 'toggle_modulo' AND tabla_afectada = 'modulos'
                   AND registro_id = " . (int)$modulo['id'] . "
                   AND JSON_UNQUOTE(JSON_EXTRACT(valor_anterior, '$.estado')) = 'activo'
                   AND JSON_UNQUOTE(JSON_EXTRACT(valor_nuevo,    '$.estado')) = 'inactivo'"
            )->fetchColumn();

            $this->assertSame(1, $filas, "el apagado de «{$slug}» quedó en auditoría");
        }
    }

    // ─── Los torneos en curso no se interrumpen ─────────────────────────────

    public function test_un_suizo_en_curso_puede_seguir_con_el_modulo_apagado(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('suizo', 4, ['rondas_suizo' => 3]);
        $this->desactivar('suizo');

        $this->assertDoesNotThrow(function () use ($torneoId) {
            foreach (Fixtures::partidosPendientes($torneoId, 1) as $eid) {
                (new ResultadoService())->cargar($eid, 3.0, 1.0, Fixtures::ADMIN);
            }
        }, 'se pueden cargar resultados');

        $this->assertDoesNotThrow(
            fn() => (new SistemaSuizoService())->generarSiguienteRonda($torneoId),
            'se puede generar la ronda siguiente'
        );
    }

    public function test_un_bracket_en_curso_sigue_avanzando_con_el_modulo_apagado(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('eliminacion_directa', 4);
        $this->desactivar('eliminacion_directa');

        $this->assertDoesNotThrow(fn() => Fixtures::jugarTodo($torneoId), 'el bracket se puede terminar');

        $torneo = (new TorneoModel())->findById($torneoId);
        $this->assertSame('finalizado', $torneo['estado'], 'llega a finalizar');
        $this->assertNotNull($torneo['campeon_participante_id'], 'y corona campeón');
    }

    public function test_una_liga_en_curso_sigue_aceptando_resultados(): void
    {
        [$torneoId] = Fixtures::torneoArrancado('liga', 4);
        $this->desactivar('liga');

        $this->assertDoesNotThrow(fn() => Fixtures::jugarTodo($torneoId), 'la liga se puede terminar');
        $this->assertSame('finalizado', (new TorneoModel())->findById($torneoId)['estado']);
    }

    // ─── Reactivar ──────────────────────────────────────────────────────────

    public function test_al_reactivar_vuelve_a_funcionar_todo(): void
    {
        foreach (array_keys(self::FORMATOS) as $slug) {
            $torneoId = $this->torneoSinArrancar($slug);

            $this->desactivar($slug);
            $this->reactivar($slug);

            $metodo = $this->metodo($slug);
            $this->assertDoesNotThrow(
                fn() => $this->servicio($slug)->$metodo($torneoId),
                "$metodo() vuelve a generar la estructura"
            );
        }

        $this->assertCount(3, (new TipoTorneoModel())->findDisponibles(),
            'el formulario vuelve a ofrecer los tres formatos');
    }
}

exit(TestCase::ejecutar(ModulosToggleTest::class));
