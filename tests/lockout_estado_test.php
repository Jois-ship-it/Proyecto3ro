<?php
declare(strict_types=1);

/**
 * Test de integración del bloqueo/desbloqueo de cuentas.
 *
 * Reemplaza a la versión que reproducía las reglas con funciones propias: ahora
 * se llama a AuthService, UsuarioService y ParticipanteService de verdad contra
 * una base de prueba, y se comprueba lo que quedó en `usuarios` y `participantes`.
 *
 * Regla de negocio: una cuenta nunca puede quedar "activa" y "bloqueada" a la
 * vez, y ninguna acción salvo desbloquear() puede revertir un bloqueo.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/lockout_estado_test.php
 */

require __DIR__ . '/bootstrap.php';

final class LockoutEstadoTest extends TestCase
{
    private const EMAIL    = 'bloqueo' . Fixtures::DOMINIO_TEST;
    private const PASSWORD = 'Flex-Test2026!';

    private int $usuarioId       = 0;
    private int $participanteId  = 0;

    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        // El admin es quien ejecuta las acciones de gestión (toggleActivo se niega
        // a operar sobre la cuenta del propio usuario logueado).
        Fixtures::loguearComo(Fixtures::ADMIN);

        $this->usuarioId = Fixtures::usuario('Usuario de bloqueo', self::EMAIL, self::PASSWORD, 3);
        $this->participanteId = (new ParticipanteModel())->insert([
            'usuario_id' => $this->usuarioId,
            'nombre'     => 'Usuario de bloqueo',
            'nick'       => 'bloqueo',
            'email'      => self::EMAIL,
            'estado'     => 'activo',
        ]);
    }

    private function estadoUsuario(): string
    {
        return (string) $this->db->query(
            "SELECT estado FROM usuarios WHERE id = {$this->usuarioId}"
        )->fetchColumn();
    }

    private function intentosFallidos(): int
    {
        return (int) $this->db->query(
            "SELECT failed_attempts FROM usuarios WHERE id = {$this->usuarioId}"
        )->fetchColumn();
    }

    private function estadoParticipante(): string
    {
        return (string) $this->db->query(
            "SELECT estado FROM participantes WHERE id = {$this->participanteId}"
        )->fetchColumn();
    }

    /** Intenta iniciar sesión con contraseña incorrecta $n veces. */
    private function fallarLogin(int $n): void
    {
        $auth = new AuthService();
        for ($i = 0; $i < $n; $i++) {
            try { $auth->login(self::EMAIL, 'ContraseñaIncorrecta1!'); }
            catch (RuntimeException $e) { /* esperado */ }
        }
    }

    // ─── Auto-bloqueo por intentos fallidos ─────────────────────────────────

    public function test_con_cuatro_intentos_fallidos_la_cuenta_sigue_activa(): void
    {
        $this->fallarLogin(4);

        $this->assertSame(4, $this->intentosFallidos(), 'se contaron los 4 intentos');
        $this->assertSame('activo', $this->estadoUsuario(), 'todavía no se llegó al umbral de 5');
        $this->assertSame('activo', $this->estadoParticipante());
    }

    public function test_al_quinto_intento_fallido_la_cuenta_queda_bloqueada(): void
    {
        $this->fallarLogin(5);

        $this->assertSame(5, $this->intentosFallidos());
        $this->assertSame('bloqueada', $this->estadoUsuario(), 'MAX_FAILED_ATTEMPTS = 5');
        $this->assertSame('suspendido', $this->estadoParticipante(),
            'el bloqueo cascadea al perfil de participante');
    }

    public function test_una_cuenta_bloqueada_no_entra_ni_con_la_contrasena_correcta(): void
    {
        $this->fallarLogin(5);

        $e = $this->assertThrows(
            fn() => (new AuthService())->login(self::EMAIL, self::PASSWORD),
            'bloqueada'
        );
        $this->assertStringContainsString('administrador', $e->getMessage(),
            'el mensaje le dice al usuario a quién recurrir');
    }

    public function test_el_login_correcto_resetea_el_contador_de_intentos(): void
    {
        $this->fallarLogin(3);
        $this->assertSame(3, $this->intentosFallidos());

        (new AuthService())->login(self::EMAIL, self::PASSWORD);

        $this->assertSame(0, $this->intentosFallidos(), 'un login válido borra los intentos previos');
        $this->assertSame('activo', $this->estadoUsuario());

        // La sesión quedó con el usuario logueado; se restaura al admin para el resto.
        Fixtures::loguearComo(Fixtures::ADMIN);
    }

    // ─── Ninguna otra acción revierte el bloqueo ────────────────────────────

    public function test_toggle_activo_no_puede_reactivar_una_cuenta_bloqueada(): void
    {
        $this->fallarLogin(5);

        $this->assertThrows(
            fn() => (new UsuarioService())->toggleActivo($this->usuarioId),
            'Desbloqueala primero'
        );
        $this->assertSame('bloqueada', $this->estadoUsuario(), 'el estado no cambió');
    }

    public function test_toggle_activo_tampoco_reactiva_al_participante_suspendido(): void
    {
        $this->fallarLogin(5);

        $this->assertThrows(
            fn() => (new ParticipanteService())->toggleActivo($this->participanteId),
            'Desbloqueala primero'
        );
        $this->assertSame('suspendido', $this->estadoParticipante());
    }

    public function test_editar_usuario_no_desbloquea_aunque_el_post_traiga_activo(): void
    {
        $this->fallarLogin(5);

        (new UsuarioService())->editar($this->usuarioId, [
            'nombre' => 'Usuario de bloqueo',
            'email'  => self::EMAIL,
            'rol_id' => 3,
            'estado' => 'activo',   // POST manipulado
        ]);

        $this->assertSame('bloqueada', $this->estadoUsuario(),
            'editar() ignora el estado posteado si la cuenta está bloqueada');
    }

    public function test_editar_participante_no_levanta_la_suspension(): void
    {
        $this->fallarLogin(5);

        (new ParticipanteService())->editar($this->participanteId, [
            'nombre' => 'Usuario de bloqueo',
            'email'  => self::EMAIL,
            'nick'   => 'bloqueo',
            'estado' => 'activo',   // POST manipulado
        ]);

        $this->assertSame('suspendido', $this->estadoParticipante(),
            'editar() ignora el estado posteado si el participante está suspendido');
    }

    // ─── Desbloqueo ─────────────────────────────────────────────────────────

    public function test_desbloquear_devuelve_la_cuenta_y_el_perfil_a_activo(): void
    {
        $this->fallarLogin(5);
        (new UsuarioService())->desbloquear($this->usuarioId);

        $this->assertSame('activo', $this->estadoUsuario());
        $this->assertSame('activo', $this->estadoParticipante(), 'el participante se restaura también');
        $this->assertSame(0, $this->intentosFallidos(), 'el contador vuelve a cero');
    }

    public function test_tras_desbloquear_el_toggle_y_el_login_vuelven_a_funcionar(): void
    {
        $this->fallarLogin(5);
        (new UsuarioService())->desbloquear($this->usuarioId);

        $this->assertDoesNotThrow(
            fn() => (new AuthService())->login(self::EMAIL, self::PASSWORD),
            'con la cuenta desbloqueada se puede iniciar sesión'
        );
        Fixtures::loguearComo(Fixtures::ADMIN);

        $this->assertSame('inactivo', (new UsuarioService())->toggleActivo($this->usuarioId),
            'toggleActivo vuelve a operar normalmente (activo → inactivo)');
        $this->assertSame('activo', (new UsuarioService())->toggleActivo($this->usuarioId));
    }

    public function test_bloquear_manualmente_tiene_el_mismo_efecto_que_el_automatico(): void
    {
        (new UsuarioService())->bloquear($this->usuarioId);

        $this->assertSame('bloqueada', $this->estadoUsuario());
        $this->assertSame('suspendido', $this->estadoParticipante());
        $this->assertThrows(
            fn() => (new UsuarioService())->toggleActivo($this->usuarioId),
            'Desbloqueala primero'
        );
    }

    public function test_nadie_puede_cambiar_el_estado_de_su_propia_cuenta(): void
    {
        Fixtures::loguearComo($this->usuarioId);

        $this->assertThrows(
            fn() => (new UsuarioService())->toggleActivo($this->usuarioId),
            'tu propia cuenta'
        );

        Fixtures::loguearComo(Fixtures::ADMIN);
    }

    // ─── Presentación del estado (View::estadoChip, clase real) ─────────────

    public function test_el_chip_de_estado_cubre_todos_los_valores_del_enum(): void
    {
        // usuarios:      pendiente, activo, inactivo, suspendido, rechazado, bloqueada
        // participantes: pendiente, activo, inactivo, suspendido, rechazado
        $esperado = [
            'pendiente'  => 'chip warning',
            'activo'     => 'chip success',
            'inactivo'   => 'chip danger',
            'suspendido' => 'chip danger',
            'rechazado'  => 'chip danger',
            'bloqueada'  => 'chip danger',
        ];

        foreach ($esperado as $valor => $clase) {
            $this->assertSame(
                '<span class="' . $clase . '">' . ucfirst($valor) . '</span>',
                View::estadoChip($valor),
                "estadoChip(\"$valor\")"
            );
        }
    }
}

exit(TestCase::ejecutar(LockoutEstadoTest::class));
