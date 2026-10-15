<?php
declare(strict_types=1);

/**
 * Test de Validator: contraseñas, email y el largo máximo de los textos.
 *
 * El caso que motivó los largos: un nick de 200 caracteres (la columna mide 80) llegaba a la
 * base, que lo rechazaba con «Data too long for column 'nick'». La persona veía ese texto —y
 * antes de que existiera ErrorNegocio, literalmente— sin saber qué corregir. Ahora cada
 * servicio avisa el motivo ANTES de tocar la base, y esos avisos tienen que coincidir con los
 * tamaños reales de las columnas: si alguien agranda o achica una columna, este test lo marca.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/validator_test.php
 */

require __DIR__ . '/bootstrap.php';

class ValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);
    }

    protected function tearDown(): void
    {
        Fixtures::cerrarSesion();
    }

    private function largoDeColumna(string $tabla, string $columna): int
    {
        $st = $this->db->prepare(
            'SELECT character_maximum_length FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $st->execute([$tabla, $columna]);
        return (int) $st->fetchColumn();
    }

    // ─── Validator por sí solo ──────────────────────────────────────────────

    public function test_un_texto_dentro_del_maximo_pasa_y_uno_mas_largo_no(): void
    {
        $this->assertDoesNotThrow(fn() => Validator::assertLargos(['El nick' => [str_repeat('n', 80), 80]]));
        $e = $this->assertThrows(fn() => Validator::assertLargos(['El nick' => [str_repeat('n', 81), 80]]),
            'El nick no puede superar los 80 caracteres.');
        $this->assertTrue($e instanceof ErrorNegocio, 'el mensaje es para la persona');
    }

    public function test_el_largo_se_cuenta_en_caracteres_y_sin_los_espacios_de_los_extremos(): void
    {
        // «ñ» ocupa 2 bytes pero es 1 carácter, igual que para VARCHAR(n) en utf8mb4.
        $this->assertDoesNotThrow(fn() => Validator::assertLargos(['El nick' => [str_repeat('ñ', 80), 80]]));
        $this->assertDoesNotThrow(fn() => Validator::assertLargos(['El nick' => ['  ' . str_repeat('n', 80) . '  ', 80]]),
            'los espacios de los extremos se recortan antes de guardar');
        $this->assertDoesNotThrow(fn() => Validator::assertLargos(['El nick' => [null, 80]]), 'un campo ausente');
    }

    public function test_el_aviso_nombra_el_campo_que_hay_que_corregir(): void
    {
        $this->assertThrows(fn() => Validator::assertLargos([
            'El nombre' => ['Ana', 100],
            'El teléfono' => [str_repeat('9', 31), 30],
        ]), 'El teléfono no puede superar los 30 caracteres.');
    }

    public function test_la_politica_de_contrasenas(): void
    {
        $this->assertSame([], Validator::passwordErrors('Clave-Segura-123!'));
        $this->assertCount(1, Validator::passwordErrors('ClaveSegura123'), 'sin símbolo');
        $this->assertCount(1, Validator::passwordErrors('clave-segura-123!'), 'sin mayúscula');
        $this->assertCount(1, Validator::passwordErrors('CLAVE-SEGURA-123!'), 'sin minúscula');
        $this->assertCount(1, Validator::passwordErrors('Clave-Segura-abc!'), 'sin número');
        $this->assertGreaterThan(0, count(Validator::passwordErrors('Ab1!')), 'muy corta');

        $e = $this->assertThrows(fn() => Validator::assertPassword('Clave-Segura-123!', 'otra-distinta'), 'no coinciden');
        $this->assertTrue($e instanceof ErrorNegocio);
    }

    public function test_el_email(): void
    {
        $this->assertTrue(Validator::email('ana@ejemplo.com'));
        $this->assertFalse(Validator::email('ana@'));
        $this->assertFalse(Validator::email('sin arroba'));
    }

    // ─── Los servicios avisan antes de tocar la base ────────────────────────

    public function test_el_registro_rechaza_cada_texto_demasiado_largo_sin_crear_nada(): void
    {
        $valido = [
            'nombre' => 'Persona Larga', 'email' => 'larga@test.local', 'nick' => 'larga', 'telefono' => '099123456',
            'documento' => '6123456', 'password' => 'Clave-Segura-123!', 'password_confirm' => 'Clave-Segura-123!',
        ];
        $svc = new RegistroService();

        foreach ([
            'nombre'    => ['El nombre',    $this->largoDeColumna('participantes', 'nombre')],
            'nick'      => ['El nick',      $this->largoDeColumna('participantes', 'nick')],
            'telefono'  => ['El teléfono',  $this->largoDeColumna('participantes', 'telefono')],
            'documento' => ['El documento', $this->largoDeColumna('participantes', 'documento')],
        ] as $campo => [$etiqueta, $maximo]) {
            $this->assertGreaterThan(0, $maximo, "control: existe la columna de {$campo}");
            $e = $this->assertThrows(fn() => $svc->registrar([$campo => str_repeat('x', $maximo + 1)] + $valido),
                "{$etiqueta} no puede superar los {$maximo} caracteres.");
            $this->assertTrue($e instanceof ErrorNegocio);
        }

        $this->assertThrows(fn() => $svc->registrar(
            ['email' => str_repeat('a', $this->largoDeColumna('usuarios', 'email')) . '@test.local'] + $valido
        ), 'El email no puede superar');

        $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) FROM usuarios WHERE email LIKE '%@test.local'")->fetchColumn(),
            'ningún intento dejó una cuenta');
        $this->assertGreaterThan(0, $svc->registrar($valido), 'con los largos justos, el registro anda');
    }

    public function test_los_formularios_de_participante_equipo_usuario_y_torneo_tambien_validan_el_largo(): void
    {
        $uid = Fixtures::usuario('Persona Larga', 'larga2@test.local', 'Clave-Segura-123!', 3);
        $pid = (new ParticipanteModel())->insert(['usuario_id' => $uid, 'estado' => 'activo', 'nombre' => 'Persona Larga']);

        $largo = fn(string $tabla, string $col) => str_repeat('x', $this->largoDeColumna($tabla, $col) + 1);

        $this->assertThrows(fn() => (new ParticipanteService())->actualizarPerfil($pid, ['nombre' => 'Ana', 'nick' => $largo('participantes', 'nick')]),
            'El nick no puede superar');
        $this->assertThrows(fn() => (new ParticipanteService())->editar($pid, ['nombre' => 'Ana', 'telefono' => $largo('participantes', 'telefono')]),
            'El teléfono no puede superar');
        $this->assertThrows(fn() => (new EquipoService())->crear(['nombre' => $largo('equipos', 'nombre')]),
            'El nombre del equipo no puede superar');
        $this->assertThrows(fn() => (new EquipoService())->crear(['nombre' => 'Equipo corto', 'disciplina' => $largo('equipos', 'disciplina')]),
            'La disciplina no puede superar');
        $this->assertThrows(fn() => (new UsuarioService())->crear([
            'nombre' => $largo('usuarios', 'nombre'), 'email' => 'x@test.local', 'rol_id' => 2, 'password' => 'Clave-Segura-123!',
        ]), 'El nombre no puede superar');
        $this->assertThrows(fn() => Fixtures::torneo('liga', ['nombre' => $largo('torneos', 'nombre')]),
            'El nombre del torneo no puede superar');

        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM torneos')->fetchColumn());
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM equipos')->fetchColumn());
        $this->assertSame('Persona Larga', $this->db->query("SELECT nombre FROM participantes WHERE id = $pid")->fetchColumn(),
            'el perfil sigue igual');
    }
}

TestCase::correrSiEsScript(ValidatorTest::class);
