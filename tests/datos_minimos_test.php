<?php
declare(strict_types=1);

/**
 * Test del mínimo de datos de prueba que exige la letra del proyecto.
 *
 * Corre database/seed_demo.php completo y cuenta las filas de cada tabla.
 *
 * La letra pide un mínimo de 50 registros por componente. Los CATÁLOGOS quedan
 * fuera a propósito y el test lo deja explícito en vez de callarlo: `roles`,
 * `tipos_torneo`, `modulos` y `permisos` tienen exactamente tantas filas como
 * conceptos existen en el sistema (3 roles, 3 formatos de torneo, 9 módulos).
 * Inventarles 50 entradas sería ruido, no datos de prueba. Para que la excepción
 * no se convierta en un agujero, se comprueba que esas tablas tengan la cantidad
 * esperada: si alguien agrega un formato o un módulo, este test avisa.
 *
 * Además de contar, verifica que los datos sean coherentes: torneos en todos los
 * estados, solicitudes de corrección en los tres estados, configuración para
 * cada torneo y perfiles vinculados a sus cuentas.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/datos_minimos_test.php
 */

require __DIR__ . '/bootstrap.php';

final class DatosMinimosTest extends TestCase
{
    private const MINIMO = 50;

    /** Tablas de catálogo: tantas filas como conceptos, no 50. */
    private const CATALOGOS = [
        'roles'        => 3,   // administrador, organizador, participante
        'tipos_torneo' => 3,   // liga, eliminación directa, suizo
        'modulos'      => 9,
        'permisos'     => 9,
    ];

    private static bool $sembrado = false;

    protected function setUp(): void
    {
        // El seed tarda un par de minutos: se corre una sola vez para todo el archivo.
        if (self::$sembrado) return;

        ob_start();
        require __DIR__ . '/../database/seed_demo.php';
        $salida = (string) ob_get_clean();

        if (!str_contains($salida, 'Datos de demostración regenerados')) {
            $this->fallar("seed_demo.php no terminó bien:\n" . mb_substr($salida, -2000));
        }
        self::$sembrado = true;
    }

    private function contar(string $tabla): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM `{$tabla}`")->fetchColumn();
    }

    /** @return string[] */
    private function tablas(): array
    {
        return $this->db->query(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE() ORDER BY table_name"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    // ─── El conteo ──────────────────────────────────────────────────────────

    public function test_ninguna_tabla_de_datos_queda_por_debajo_de_50_registros(): void
    {
        $bajas = [];
        foreach ($this->tablas() as $tabla) {
            if (isset(self::CATALOGOS[$tabla])) continue;
            $n = $this->contar($tabla);
            if ($n < self::MINIMO) $bajas[] = "{$tabla} ({$n})";
        }

        $this->assertCount(0, $bajas, 'por debajo de ' . self::MINIMO . ': ' . implode(', ', $bajas));
    }

    public function test_los_catalogos_tienen_la_cantidad_de_filas_que_les_corresponde(): void
    {
        foreach (self::CATALOGOS as $tabla => $esperadas) {
            $this->assertSame($esperadas, $this->contar($tabla),
                "«{$tabla}» es un catálogo: si cambió su cantidad de filas hay que revisar "
                . 'la excepción documentada en este test');
        }
    }

    public function test_las_tablas_centrales_del_dominio_tienen_volumen_suficiente(): void
    {
        // Explícito y legible, para que quede claro en la defensa qué se cubre.
        foreach ([
            'usuarios', 'participantes', 'equipos', 'equipo_participantes',
            'torneos', 'torneo_organizadores', 'inscripciones', 'rondas',
            'enfrentamientos', 'resultados', 'tabla_posiciones',
            'configuraciones_torneo', 'solicitudes_correccion', 'auditoria',
        ] as $tabla) {
            $n = $this->contar($tabla);
            $this->assertTrue($n >= self::MINIMO, "{$tabla} tiene {$n}, se esperaban al menos " . self::MINIMO);
        }
    }

    // ─── La coherencia ──────────────────────────────────────────────────────

    public function test_hay_torneos_en_todos_los_estados(): void
    {
        $porEstado = $this->db->query(
            "SELECT estado, COUNT(*) FROM torneos GROUP BY estado"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach (['borrador', 'inscripcion', 'en_curso', 'finalizado', 'cancelado'] as $estado) {
            $this->assertTrue(
                isset($porEstado[$estado]) && (int)$porEstado[$estado] > 0,
                "no hay ningún torneo en estado «{$estado}»"
            );
        }
    }

    public function test_los_torneos_finalizados_tienen_campeon(): void
    {
        $sinCampeon = (int) $this->db->query(
            "SELECT COUNT(*) FROM torneos
             WHERE estado = 'finalizado'
               AND campeon_participante_id IS NULL AND campeon_equipo_id IS NULL"
        )->fetchColumn();

        $this->assertSame(0, $sinCampeon, 'un torneo finalizado sin campeón no es historia coherente');
    }

    public function test_hay_solicitudes_de_correccion_en_los_tres_estados(): void
    {
        $porEstado = $this->db->query(
            "SELECT estado, COUNT(*) FROM solicitudes_correccion GROUP BY estado"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach (['pendiente', 'aprobada', 'rechazada'] as $estado) {
            $this->assertTrue(
                isset($porEstado[$estado]) && (int)$porEstado[$estado] > 0,
                "no hay ninguna solicitud de corrección «{$estado}»"
            );
        }

        // Las aprobadas tienen que haberse aplicado de verdad.
        $aplicadas = (int) $this->db->query(
            "SELECT COUNT(*) FROM solicitudes_correccion s
             JOIN resultados r ON r.enfrentamiento_id = s.enfrentamiento_id
             WHERE s.estado = 'aprobada' AND r.estado = 'corregido'"
        )->fetchColumn();
        $this->assertGreaterThan(0, $aplicadas, 'aprobar una corrección debe dejar el resultado corregido');

        // Y las rechazadas tienen que traer el motivo del rechazo.
        $sinMotivo = (int) $this->db->query(
            "SELECT COUNT(*) FROM solicitudes_correccion
             WHERE estado = 'rechazada' AND (motivo_rechazo IS NULL OR motivo_rechazo = '')"
        )->fetchColumn();
        $this->assertSame(0, $sinMotivo, 'una solicitud rechazada sin motivo no sirve de ejemplo');
    }

    public function test_cada_torneo_tiene_su_configuracion(): void
    {
        $sinConfig = (int) $this->db->query(
            "SELECT COUNT(*) FROM torneos t
             WHERE NOT EXISTS (SELECT 1 FROM configuraciones_torneo c WHERE c.torneo_id = t.id)"
        )->fetchColumn();

        $this->assertSame(0, $sinConfig, 'configuraciones_torneo no puede quedar huérfana de torneos');
    }

    public function test_cada_torneo_tiene_organizador_asignado(): void
    {
        $sinOrganizador = (int) $this->db->query(
            "SELECT COUNT(*) FROM torneos t
             WHERE NOT EXISTS (SELECT 1 FROM torneo_organizadores o WHERE o.torneo_id = t.id)"
        )->fetchColumn();

        $this->assertSame(0, $sinOrganizador);
    }

    public function test_las_cuentas_de_participante_tienen_perfil_vinculado(): void
    {
        $sinPerfil = (int) $this->db->query(
            "SELECT COUNT(*) FROM usuarios u
             JOIN roles r ON r.id = u.rol_id
             WHERE r.nombre = 'participante'
               AND NOT EXISTS (SELECT 1 FROM participantes p WHERE p.usuario_id = u.id)"
        )->fetchColumn();

        $this->assertSame(0, $sinPerfil, 'toda cuenta de participante debería poder ver su perfil');
    }

    public function test_el_perfil_y_su_cuenta_no_se_contradicen(): void
    {
        // El seed forzaba 'activo' en todo perfil con cuenta, sin mirar el estado
        // de la cuenta. Quedaba una persona con la cuenta pendiente de aprobación
        // y el perfil ya aprobado, que es el estado que el flujo de registro
        // justamente no puede producir.
        $contradicciones = $this->db->query(
            "SELECT p.nombre, p.estado AS perfil, u.estado AS cuenta
               FROM participantes p
               JOIN usuarios u ON u.id = p.usuario_id
              WHERE p.estado <> u.estado
                -- Excepción real: al bloquear una cuenta por intentos fallidos,
                -- el perfil pasa a 'suspendido' porque 'bloqueada' no es un
                -- estado de participante. Ver UsuarioModel::bloquear().
                AND NOT (u.estado = 'bloqueada' AND p.estado = 'suspendido')"
        )->fetchAll(PDO::FETCH_ASSOC);

        $detalle = array_map(
            fn(array $f) => "{$f['nombre']}: perfil «{$f['perfil']}» / cuenta «{$f['cuenta']}»",
            $contradicciones
        );

        $this->assertCount(0, $contradicciones,
            "hay perfiles que no coinciden con su cuenta:\n        - " . implode("\n        - ", $detalle));
    }

    public function test_ningun_participante_queda_sin_cuenta(): void
    {
        // El seed sembraba ocho jugadores «anotados a mano por el organizador».
        // Ese estado la aplicación no lo sabe producir: el alta manual no existe
        // y todos se registran por sí mismos. Ver tests/alta_participantes_test.php.
        $sinCuenta = $this->db->query(
            "SELECT nombre FROM participantes WHERE usuario_id IS NULL"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(0, $sinCuenta,
            'participantes sin cuenta, que el sistema no puede crear: ' . implode(', ', $sinCuenta));
    }

    public function test_los_perfiles_tienen_sus_datos_de_contacto(): void
    {
        $incompletos = $this->db->query(
            "SELECT nombre FROM participantes
              WHERE documento IS NULL OR documento = ''
                 OR nick      IS NULL OR nick      = ''
                 OR email     IS NULL OR email     = ''"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(0, $incompletos,
            'perfiles a medio cargar: ' . implode(', ', $incompletos));
    }

    public function test_los_partidos_jugados_tienen_fecha_real(): void
    {
        // match_list.php muestra la hora de inicio y de fin de cada partido
        // terminado. Si el seed no las carga, esa parte de la pantalla aparece
        // vacía en la demo y parece que la función no está hecha.
        $sinFecha = (int) $this->db->query(
            "SELECT COUNT(*) FROM enfrentamientos
              WHERE estado = 'finalizado'
                AND (fecha_inicio_real IS NULL OR fecha_fin_real IS NULL)"
        )->fetchColumn();

        $this->assertSame(0, $sinFecha, 'los partidos ya jugados deberían tener su horario real');

        // El reverso: un bye no se juega, así que no puede tener horario. La
        // migración 2026_06_partidos_fechas.sql sí se los ponía; el sistema no.
        $byeConFecha = (int) $this->db->query(
            "SELECT COUNT(*) FROM enfrentamientos
              WHERE es_bye = 1 AND (fecha_inicio_real IS NOT NULL OR fecha_fin_real IS NOT NULL)"
        )->fetchColumn();

        $this->assertSame(0, $byeConFecha, 'un bye no tiene horario porque nadie lo disputó');
    }

    public function test_los_torneos_cubren_los_tres_formatos_y_las_dos_modalidades(): void
    {
        $porFormato = $this->db->query(
            "SELECT tt.slug, COUNT(*) FROM torneos t
             JOIN tipos_torneo tt ON tt.id = t.tipo_torneo_id GROUP BY tt.slug"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach (['liga', 'eliminacion_directa', 'suizo'] as $slug) {
            $this->assertTrue(
                isset($porFormato[$slug]) && (int)$porFormato[$slug] > 0,
                "no hay torneos del formato «{$slug}»"
            );
        }

        $porModalidad = $this->db->query(
            "SELECT modalidad, COUNT(*) FROM torneos GROUP BY modalidad"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach (['individual', 'equipos'] as $modalidad) {
            $this->assertTrue(
                isset($porModalidad[$modalidad]) && (int)$porModalidad[$modalidad] > 0,
                "no hay torneos de modalidad «{$modalidad}»"
            );
        }
    }
}

exit(TestCase::ejecutar(DatosMinimosTest::class));
