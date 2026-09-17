<?php
declare(strict_types=1);

/**
 * Test de que el alta de participantes tiene un solo camino.
 *
 * Un participante nace de su propio registro público: RegistroService crea la
 * cuenta y el perfil juntos, y el administrador aprueba la solicitud. El alta
 * manual desde el panel no existe.
 *
 * Pero el código se había quedado a mitad de camino. Las rutas
 * `admin/participantes/crear` seguían registradas, `participanteForm()` y
 * `participanteGuardar()` empezaban con un `if (!$id) { … redirect(); }`, y
 * debajo de ese corte quedaba la rama de alta llamando a
 * `ParticipanteService::crear()`. Como `redirect()` termina en `exit()`, esa
 * rama no se ejecutaba nunca: era la única llamada al método, y el método era
 * código muerto.
 *
 * El síntoma se vio en los datos: `seed_demo.php` sembraba ocho participantes
 * sin cuenta, un estado que la aplicación no sabe producir.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/alta_participantes_test.php
 */

require __DIR__ . '/bootstrap.php';

final class AltaParticipantesTest extends TestCase
{
    /** @return string[] "GET admin/... → Controlador::accion" de cada ruta registrada */
    private function rutasRegistradas(): array
    {
        $router = new Router();
        require dirname(__DIR__) . '/config/routes.php';

        $prop = (new ReflectionObject($router))->getProperty('routes');
        $prop->setAccessible(true);

        return array_map(
            fn(array $r) => "{$r['method']} {$r['path']} → {$r['controller']}::{$r['action']}",
            (array) $prop->getValue($router)
        );
    }

    public function test_no_queda_ninguna_ruta_de_alta_manual(): void
    {
        $crear = array_values(array_filter(
            $this->rutasRegistradas(),
            fn(string $r) => str_contains($r, 'participantes/crear')
        ));

        $this->assertCount(0, $crear,
            'rutas de alta manual todavía registradas: ' . implode(', ', $crear));
    }

    public function test_el_servicio_ya_no_ofrece_un_alta_sin_cuenta(): void
    {
        // Si el método vuelve, vuelve el estado inalcanzable: un participante
        // creado sin tocar la tabla usuarios.
        $this->assertFalse(method_exists(ParticipanteService::class, 'crear'),
            'ParticipanteService::crear() crea un participante sin cuenta y nadie puede llamarlo');
    }

    public function test_ningun_controlador_llama_a_un_metodo_que_ya_no_existe(): void
    {
        // El error clásico al recortar: se saca el servicio y queda la llamada.
        $sobrantes = [];
        foreach (glob(dirname(__DIR__) . '/app/controllers/*.php') ?: [] as $archivo) {
            $fuente = (string) file_get_contents($archivo);
            if (preg_match('/participanteService\s*->\s*crear\s*\(/', $fuente) === 1) {
                $sobrantes[] = basename($archivo);
            }
        }

        $this->assertCount(0, $sobrantes, 'siguen llamando a crear(): ' . implode(', ', $sobrantes));
    }

    public function test_no_queda_codigo_despues_de_un_redirect(): void
    {
        // redirect() termina en exit(): todo lo que venga después de un
        // `if (...) { ...redirect(); }` que siempre se cumple es inalcanzable.
        // Acá se comprueba lo concreto que había: los dos cortes por $id vacío.
        $fuente = (string) file_get_contents(dirname(__DIR__) . '/app/controllers/AdminController.php');

        $this->assertStringNotContainsString('El alta manual de participantes está deshabilitada', $fuente,
            'quedó el corte que hacía inalcanzable la rama de alta');
        $this->assertStringNotContainsString('Nuevo participante', $fuente,
            'quedó el título de un alta que no se puede hacer');
    }

    public function test_el_formulario_solo_apunta_a_editar(): void
    {
        $vista = (string) file_get_contents(dirname(__DIR__) . '/app/views/admin/participante_form.php');

        $this->assertStringContainsString('/admin/participantes/editar/', $vista);
        $this->assertStringNotContainsString("'crear'", $vista,
            'el formulario todavía puede postear a la ruta de alta');
    }

    // ─── Y el camino que sí existe sigue funcionando ────────────────────────

    public function test_el_registro_publico_crea_cuenta_y_perfil_juntos(): void
    {
        Fixtures::reset($this->db);

        $email = 'alta.publica' . Fixtures::DOMINIO_TEST;
        (new RegistroService())->registrar([
            'nombre'   => 'Persona Registrada',
            'email'    => $email,
            'password' => 'Flex-Test2026!',
            'password_confirm' => 'Flex-Test2026!',
        ]);

        $fila = $this->db->query(
            "SELECT u.id AS uid, u.estado AS cuenta, p.id AS pid, p.estado AS perfil
               FROM usuarios u
               LEFT JOIN participantes p ON p.usuario_id = u.id
              WHERE u.email = " . $this->db->quote($email)
        )->fetch(PDO::FETCH_ASSOC);

        $this->assertNotNull($fila['uid'] ?? null, 'se creó la cuenta');
        $this->assertNotNull($fila['pid'] ?? null, 'y el perfil, en el mismo acto');
        $this->assertSame($fila['cuenta'], $fila['perfil'],
            'cuenta y perfil nacen en el mismo estado');
    }

    public function test_ningun_participante_puede_quedar_sin_cuenta(): void
    {
        // El seed ya no siembra ninguno; esta es la comprobación de que tampoco
        // aparecen por otro lado durante los tests.
        $sinCuenta = $this->db->query(
            "SELECT nombre FROM participantes WHERE usuario_id IS NULL"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(0, $sinCuenta,
            'participantes sin cuenta: ' . implode(', ', $sinCuenta));
    }
}

exit(TestCase::ejecutar(AltaParticipantesTest::class));
