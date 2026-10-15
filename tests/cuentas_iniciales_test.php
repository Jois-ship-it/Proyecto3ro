<?php
declare(strict_types=1);

/**
 * Test de las contraseñas iniciales (database/inicializar_cuentas.php) y de que el
 * repositorio ya no publique ninguna.
 *
 * Dos mitades:
 *   - El seed y la migración: ni un hash ni una contraseña a la vista, y ninguna
 *     cuenta que se pueda abrir tal como se instala.
 *   - El script: qué clave recibe cada cuenta, qué pasa si falta o es débil, y
 *     que nunca toque una cuenta que ya tiene la suya.
 *
 * Todo corre dentro de una transacción que se deshace al final, así la base de
 * pruebas queda como estaba. bcrypt va con costo 4: lo que se prueba es a quién se
 * le asigna cada clave, no cuánto tarda en calcularse.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/cuentas_iniciales_test.php
 */

require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/database/inicializar_cuentas.php';

class CuentasInicialesTest extends TestCase
{
    private const COSTO = 4;

    private const ADMIN = 'admin@flexarena.com';
    private const ORG   = 'org1@flexarena.com';
    private const PART  = 'matias@example.com';

    /** Una contraseña que cumple la política (8+, mayúscula, minúscula, número y símbolo). */
    private const CLAVE_ADMIN = 'Admin-Segura-1!';
    private const CLAVE_DEMO  = 'Demo-Segura-1!';

    protected function setUp(): void
    {
        $this->db->beginTransaction();

        // Estado de partida: el de seed.sql, todas las cuentas sin clave (menos las
        // que crean los tests, que traen la suya).
        $this->db->prepare('UPDATE usuarios SET password_hash = ? WHERE email NOT LIKE ?')
                 ->execute([CuentasIniciales::SIN_CLAVE, '%' . Fixtures::DOMINIO_TEST]);
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) $this->db->rollBack();
    }

    private function hashDe(string $email): string
    {
        $st = $this->db->prepare('SELECT password_hash FROM usuarios WHERE email = ?');
        $st->execute([$email]);
        return (string) $st->fetchColumn();
    }

    private function cuentasSinClave(): int
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM usuarios WHERE password_hash = ?');
        $st->execute([CuentasIniciales::SIN_CLAVE]);
        return (int) $st->fetchColumn();
    }

    // ─── El repositorio no publica contraseñas ──────────────────────────────

    public function test_el_seed_no_trae_ni_un_hash_ni_una_contrasena(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__) . '/database/seed.sql');

        $this->assertStringNotContainsString('$2y$', $sql, 'seed.sql no puede contener hashes bcrypt');
        $this->assertStringNotContainsString('admin123', $sql);
        $this->assertStringNotContainsString('Adm!n', $sql);

        // Cada fila de usuarios usa el marcador, sin excepción.
        $this->assertTrue((bool) preg_match('/INSERT INTO usuarios \(.*?\) VALUES(.*?)ON DUPLICATE KEY UPDATE([^;]*);/s', $sql, $m),
            'no se encontró el INSERT de usuarios en seed.sql');
        $filas     = preg_match_all('/^\(\d+,/m', $m[1]);
        $marcadas  = preg_match_all("/^\\(\\d+,.*'" . preg_quote(CuentasIniciales::SIN_CLAVE, '/') . "'/m", $m[1]);
        $this->assertGreaterThan(50, $filas, 'el seed trae menos cuentas de las esperadas');
        $this->assertSame($filas, $marcadas, 'hay cuentas del seed con una contraseña en vez del marcador');

        // Volver a correr el seed sobre una base viva no puede pisar las contraseñas.
        $this->assertStringNotContainsString('password_hash', $m[2],
            'el ON DUPLICATE KEY UPDATE de usuarios pisaría las contraseñas ya asignadas');
    }

    public function test_el_marcador_es_el_mismo_en_el_codigo_el_seed_y_la_migracion(): void
    {
        $literal = "'" . CuentasIniciales::SIN_CLAVE . "'";
        foreach (['database/seed.sql', 'database/migrations/2026_10_cuentas_sin_clave_conocida.sql'] as $archivo) {
            $this->assertStringContainsString($literal, (string) file_get_contents(dirname(__DIR__) . '/' . $archivo),
                "{$archivo} no usa el mismo marcador que CuentasIniciales::SIN_CLAVE");
        }
    }

    public function test_ninguna_clave_abre_una_cuenta_sin_contrasena(): void
    {
        $marcador = CuentasIniciales::SIN_CLAVE;

        foreach (['', 'admin123', 'Admin-Segura-1!', $marcador, 'cualquier cosa'] as $intento) {
            $this->assertFalse(password_verify($intento, $marcador),
                'el marcador no puede validar ninguna clave, ni siquiera él mismo');
        }
        $this->assertSame(null, password_get_info($marcador)['algo'], 'el marcador no es un hash de ningún algoritmo');
    }

    public function test_no_se_puede_iniciar_sesion_con_una_cuenta_sin_contrasena(): void
    {
        $auth = new AuthService();
        foreach (['Admin-Segura-1!', CuentasIniciales::SIN_CLAVE, 'admin123'] as $intento) {
            $this->assertThrows(
                fn() => $auth->login(self::ADMIN, $intento),
                'Email o contraseña incorrectos',
                "no debería entrar con «{$intento}»");
        }
    }

    // ─── El script ──────────────────────────────────────────────────────────

    public function test_con_las_dos_claves_cada_grupo_recibe_la_suya(): void
    {
        $r = CuentasIniciales::inicializar($this->db, self::CLAVE_ADMIN, self::CLAVE_DEMO, self::COSTO);

        $this->assertSame('configurada', $r['admin']);
        $this->assertSame('configurada', $r['demo']);
        $this->assertSame([self::ADMIN], $r['emails_admin']);
        $this->assertSame(1, $r['cuentas_admin']);
        $this->assertGreaterThan(50, $r['cuentas_demo']);

        $this->assertTrue(password_verify(self::CLAVE_ADMIN, $this->hashDe(self::ADMIN)));
        $this->assertTrue(password_verify(self::CLAVE_DEMO,  $this->hashDe(self::ORG)));
        $this->assertTrue(password_verify(self::CLAVE_DEMO,  $this->hashDe(self::PART)));

        // El administrador no comparte la clave de las cuentas de demostración.
        $this->assertFalse(password_verify(self::CLAVE_DEMO,  $this->hashDe(self::ADMIN)));
        $this->assertFalse(password_verify(self::CLAVE_ADMIN, $this->hashDe(self::ORG)));

        $this->assertSame(0, $this->cuentasSinClave(), 'no debería quedar ninguna cuenta sin contraseña');
        $this->assertNotSame(self::CLAVE_ADMIN, $this->hashDe(self::ADMIN), 'se guarda el hash, nunca la clave');
    }

    public function test_el_inicio_de_sesion_funciona_con_la_clave_asignada(): void
    {
        CuentasIniciales::inicializar($this->db, self::CLAVE_ADMIN, self::CLAVE_DEMO, self::COSTO);

        $usuario = (new AuthService())->login(self::ADMIN, self::CLAVE_ADMIN);
        $this->assertSame(self::ADMIN, $usuario['email']);
        Fixtures::cerrarSesion();
    }

    public function test_sin_clave_de_administrador_se_genera_una_valida_y_se_informa_una_sola_vez(): void
    {
        $r = CuentasIniciales::inicializar($this->db, '', self::CLAVE_DEMO, self::COSTO);

        $this->assertSame('generada', $r['admin']);
        $this->assertNotNull($r['clave_generada']);
        $this->assertSame([], Validator::passwordErrors((string) $r['clave_generada']),
            'la clave generada tiene que cumplir la política del sistema');
        $this->assertTrue(password_verify((string) $r['clave_generada'], $this->hashDe(self::ADMIN)),
            'la clave que se informa es la que quedó guardada');

        // Una segunda corrida no vuelve a generar ni a mostrar nada.
        $otra = CuentasIniciales::inicializar($this->db, '', self::CLAVE_DEMO, self::COSTO);
        $this->assertSame('ninguna', $otra['admin']);
        $this->assertSame(null, $otra['clave_generada']);
    }

    public function test_sin_clave_de_demostracion_esas_cuentas_quedan_sin_contrasena(): void
    {
        $antes = $this->cuentasSinClave();
        $r = CuentasIniciales::inicializar($this->db, self::CLAVE_ADMIN, '', self::COSTO);

        $this->assertSame('sin_clave', $r['demo']);
        $this->assertSame($antes - 1, $this->cuentasSinClave(), 'solo el administrador recibió contraseña');
        $this->assertSame(CuentasIniciales::SIN_CLAVE, $this->hashDe(self::ORG));
        $this->assertSame(CuentasIniciales::SIN_CLAVE, $this->hashDe(self::PART));
    }

    public function test_una_clave_que_incumple_la_politica_se_rechaza_y_no_se_elige_otra_en_silencio(): void
    {
        $r = CuentasIniciales::inicializar($this->db, 'admin123', 'corta', self::COSTO);

        $this->assertSame('invalida', $r['admin']);
        $this->assertSame('invalida', $r['demo']);
        $this->assertSame(null, $r['clave_generada'], 'una clave explícita pero débil no se reemplaza por otra sin avisar');
        $this->assertCount(2, $r['errores']);
        $this->assertStringContainsString('ADMIN_PASS', $r['errores'][0]);
        $this->assertStringContainsString('DEMO_PASS',  $r['errores'][1]);

        $this->assertSame(CuentasIniciales::SIN_CLAVE, $this->hashDe(self::ADMIN), 'la cuenta queda sin contraseña');
        $this->assertSame(CuentasIniciales::SIN_CLAVE, $this->hashDe(self::ORG));
    }

    public function test_nunca_toca_una_cuenta_que_ya_tiene_contrasena(): void
    {
        CuentasIniciales::inicializar($this->db, self::CLAVE_ADMIN, self::CLAVE_DEMO, self::COSTO);

        // Alguien cambia su clave desde la aplicación.
        $propio = password_hash('Mi-Clave-Propia1!', PASSWORD_BCRYPT, ['cost' => self::COSTO]);
        $this->db->prepare('UPDATE usuarios SET password_hash = ? WHERE email = ?')->execute([$propio, self::ORG]);
        $adminAntes = $this->hashDe(self::ADMIN);

        // El arranque siguiente trae otras claves en el .env: no tienen que aplicarse.
        $r = CuentasIniciales::inicializar($this->db, 'Otra-Admin-2!', 'Otra-Demo-2!', self::COSTO);

        $this->assertSame('ninguna', $r['admin']);
        $this->assertSame('ninguna', $r['demo']);
        $this->assertSame(0, $r['cuentas_admin'] + $r['cuentas_demo']);
        $this->assertSame($propio, $this->hashDe(self::ORG), 'la clave que eligió su dueño no se pisa');
        $this->assertSame($adminAntes, $this->hashDe(self::ADMIN), 'ni la del administrador');
    }

    public function test_deja_constancia_en_la_auditoria_sin_guardar_ninguna_clave(): void
    {
        $this->db->exec("DELETE FROM auditoria WHERE accion IN ('cuenta_inicializada','cuentas_demo_inicializadas')");

        $r = CuentasIniciales::inicializar($this->db, '', self::CLAVE_DEMO, self::COSTO);

        $filas = $this->db->query(
            "SELECT accion, descripcion FROM auditoria
              WHERE accion IN ('cuenta_inicializada','cuentas_demo_inicializadas') ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $filas, 'una fila por el administrador y una por todo el grupo de demostración');
        $this->assertSame('cuenta_inicializada', $filas[0]['accion']);
        $this->assertStringContainsString(self::ADMIN, $filas[0]['descripcion']);
        $this->assertSame('cuentas_demo_inicializadas', $filas[1]['accion']);

        foreach ($filas as $f) {
            $this->assertStringNotContainsString(self::CLAVE_DEMO, $f['descripcion'], 'la auditoría no guarda contraseñas');
            $this->assertStringNotContainsString((string) $r['clave_generada'], $f['descripcion']);
        }
    }

    // ─── La clave aleatoria ─────────────────────────────────────────────────

    public function test_las_claves_aleatorias_cumplen_la_politica_y_no_se_repiten(): void
    {
        $vistas = [];
        for ($i = 0; $i < 200; $i++) {
            $clave = CuentasIniciales::claveAleatoria();

            $this->assertSame(CuentasIniciales::LARGO_GENERADA, strlen($clave));
            $this->assertSame([], Validator::passwordErrors($clave), "no cumple la política: {$clave}");
            $this->assertFalse((bool) preg_match('/[0OIl1]/', $clave), "tiene caracteres que se confunden: {$clave}");
            $vistas[$clave] = true;
        }
        $this->assertCount(200, array_keys($vistas), 'se repitió una clave en 200 intentos');
    }
}

TestCase::correrSiEsScript(CuentasInicialesTest::class);
