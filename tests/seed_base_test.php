<?php
declare(strict_types=1);

/**
 * Test de `database/seed.sql` por su cuenta.
 *
 * Es el seed que carga Docker en la primera inicialización y el que recibe una
 * instalación nueva. Hasta ahora nadie lo miraba solo: `datos_minimos_test.php`
 * corre `seed_demo.php`, que empieza borrando todo y reconstruyendo con los
 * servicios del dominio, así que tapaba cualquier incoherencia de esta base.
 *
 * Y había una: `seed.sql` inserta los enfrentamientos a mano, sin pasar por
 * ResultadoService, así que los partidos terminados quedaban sin
 * `fecha_inicio_real` ni `fecha_fin_real`. `match_list.php` muestra esas horas;
 * sin ellas, esa parte de la pantalla aparece vacía en una instalación recién
 * hecha, que es exactamente la que alguien mira por primera vez.
 *
 * El test se deja la base como la encuentra: borra, aplica seed.sql y comprueba.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/seed_base_test.php
 */

require __DIR__ . '/bootstrap.php';

final class SeedBaseTest extends TestCase
{
    private static bool $cargado = false;

    protected function setUp(): void
    {
        if (self::$cargado) return;

        Fixtures::reset($this->db);
        // reset() deja los usuarios del seed; para partir de cero hay que sacarlos
        // todos, porque seed.sql los inserta con id explícito.
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['usuarios', 'permisos', 'modulos', 'tipos_torneo', 'roles'] as $t) {
            $this->db->exec("DELETE FROM `{$t}`");
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');

        $sql = file_get_contents(dirname(__DIR__) . '/database/seed.sql');
        if ($sql === false) $this->fallar('No se pudo leer database/seed.sql.');
        $this->db->exec($sql);

        self::$cargado = true;
    }

    private function contar(string $sql): int
    {
        return (int) $this->db->query($sql)->fetchColumn();
    }

    // ─── Que haya cargado ───────────────────────────────────────────────────

    public function test_el_seed_deja_el_sistema_utilizable(): void
    {
        // Control del escenario: si el seed no cargó, todo lo de abajo pasaría
        // de vacío.
        $this->assertGreaterThan(0, $this->contar('SELECT COUNT(*) FROM roles'));
        $this->assertGreaterThan(0, $this->contar('SELECT COUNT(*) FROM usuarios'));
        $this->assertGreaterThan(0, $this->contar('SELECT COUNT(*) FROM torneos'));
        $this->assertGreaterThan(0, $this->contar('SELECT COUNT(*) FROM enfrentamientos'));
    }

    // ─── Coherencia de los datos ────────────────────────────────────────────

    public function test_los_partidos_terminados_tienen_su_horario(): void
    {
        $sinFecha = $this->db->query(
            "SELECT id FROM enfrentamientos
              WHERE estado = 'finalizado'
                AND (fecha_inicio_real IS NULL OR fecha_fin_real IS NULL)"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(0, $sinFecha,
            'partidos finalizados sin horario real: ' . implode(', ', $sinFecha));
    }

    public function test_un_bye_no_tiene_horario_porque_no_se_jugo(): void
    {
        // El reverso del anterior: rellenar los byes «por completar» sería
        // inventar un partido que nadie disputó.
        $conFecha = $this->contar(
            "SELECT COUNT(*) FROM enfrentamientos
              WHERE es_bye = 1 AND (fecha_inicio_real IS NOT NULL OR fecha_fin_real IS NOT NULL)"
        );

        $this->assertSame(0, $conFecha, 'un bye no se juega: no puede tener hora de inicio ni de fin');
    }

    public function test_el_horario_de_cada_partido_es_coherente(): void
    {
        $incoherentes = $this->db->query(
            "SELECT id FROM enfrentamientos
              WHERE fecha_inicio_real IS NOT NULL
                AND fecha_fin_real    IS NOT NULL
                AND fecha_fin_real < fecha_inicio_real"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(0, $incoherentes,
            'terminan antes de empezar: ' . implode(', ', $incoherentes));
    }

    public function test_el_horario_cae_dentro_de_las_fechas_del_torneo(): void
    {
        $fuera = $this->db->query(
            "SELECT e.id FROM enfrentamientos e
               JOIN torneos t ON t.id = e.torneo_id
              WHERE e.fecha_inicio_real IS NOT NULL
                AND t.fecha_inicio IS NOT NULL AND t.fecha_fin IS NOT NULL
                AND (DATE(e.fecha_inicio_real) < t.fecha_inicio
                  OR DATE(e.fecha_fin_real)    > t.fecha_fin)"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(0, $fuera,
            'jugados fuera del rango de fechas de su torneo: ' . implode(', ', $fuera));
    }

    public function test_cada_participante_esta_vinculado_a_su_cuenta(): void
    {
        // En seed.sql, a diferencia de seed_demo.php, todos tienen cuenta: son
        // las personas que aparecen en la documentación y en los manuales.
        $sinCuenta = $this->db->query(
            "SELECT nombre FROM participantes WHERE usuario_id IS NULL"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(0, $sinCuenta,
            'participantes sin cuenta en el seed base: ' . implode(', ', $sinCuenta));
    }

    public function test_el_perfil_y_su_cuenta_no_se_contradicen(): void
    {
        $contradicciones = $this->db->query(
            "SELECT p.nombre FROM participantes p
               JOIN usuarios u ON u.id = p.usuario_id
              WHERE p.estado <> u.estado
                AND NOT (u.estado = 'bloqueada' AND p.estado = 'suspendido')"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(0, $contradicciones,
            'perfil y cuenta en estados distintos: ' . implode(', ', $contradicciones));
    }

    public function test_todo_partido_finalizado_tiene_resultado(): void
    {
        $sinResultado = $this->db->query(
            "SELECT e.id FROM enfrentamientos e
              WHERE e.estado = 'finalizado'
                AND NOT EXISTS (SELECT 1 FROM resultados r WHERE r.enfrentamiento_id = e.id)"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(0, $sinResultado,
            'finalizados sin resultado cargado: ' . implode(', ', $sinResultado));
    }

    public function test_cada_torneo_tiene_organizador(): void
    {
        $sinOrganizador = $this->db->query(
            "SELECT t.nombre FROM torneos t
              WHERE NOT EXISTS (SELECT 1 FROM torneo_organizadores o WHERE o.torneo_id = t.id)"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(0, $sinOrganizador,
            'torneos sin organizador: ' . implode(', ', $sinOrganizador));
    }
}

exit(TestCase::ejecutar(SeedBaseTest::class));
