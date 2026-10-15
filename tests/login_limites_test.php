<?php
declare(strict_types=1);

/**
 * Test de los límites del inicio de sesión: bloqueo temporal, límite por IP, ajustes y la
 * protección de los administradores.
 *
 * Qué pasaba antes:
 *   - El bloqueo a los 5 intentos no vencía: hasta que un administrador lo levantara. Con un solo
 *     administrador y su email a la vista, cualquiera podía dejar al sistema sin quien lo
 *     administre, sin forma de recuperarlo desde la interfaz.
 *   - Los «5 intentos» estaban escritos en el código; la tabla de ajustes, que traía el valor,
 *     no la leía nadie. Y no había ningún límite por dirección IP.
 *   - Bloquear pisaba cualquier estado: una cuenta pendiente de aprobación o rechazada, con
 *     intentos fallidos, pasaba a «bloqueada»; al desbloquearla quedaba ACTIVA sin que nadie la
 *     hubiera aprobado. Y bloquear/desactivar no protegía al último administrador.
 *
 * Complementa a lockout_estado_test.php, que sigue cubriendo el bloqueo y el desbloqueo básicos.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/login_limites_test.php
 */

require __DIR__ . '/bootstrap.php';

class LoginLimitesTest extends TestCase
{
    private const EMAIL    = 'limites' . Fixtures::DOMINIO_TEST;
    private const PASSWORD = 'Flex-Test2026!';

    private int $usuarioId      = 0;
    private int $participanteId = 0;

    protected function setUp(): void
    {
        $this->restaurarAjustes();
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);
        unset($_SERVER['REMOTE_ADDR']);

        $this->usuarioId = Fixtures::usuario('Persona Limites', self::EMAIL, self::PASSWORD, 3);
        $this->participanteId = (new ParticipanteModel())->insert([
            'usuario_id' => $this->usuarioId, 'nombre' => 'Persona Limites', 'nick' => 'limites',
            'email' => self::EMAIL, 'estado' => 'activo',
        ]);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        $this->restaurarAjustes();
        // Varios casos bloquean de verdad cuentas del seed (hasta al administrador). Los tests que
        // corren después comparten la base y algunos (permisos_test) no la reinician: tiene que
        // quedar como se la encontró, con todas las cuentas activas y sin bloqueos.
        Fixtures::reset($this->db);
        Fixtures::cerrarSesion();
    }

    // ─── Apoyo ──────────────────────────────────────────────────────────────

    /** Devuelve los ajustes de acceso a su valor de fábrica (y descarta lo que el servicio tenía leído). */
    private function restaurarAjustes(): void
    {
        foreach (['login_max_intentos', 'login_minutos_bloqueo', 'login_max_intentos_ip', 'login_ventana_minutos'] as $clave) {
            $st = $this->db->prepare(
                'INSERT INTO configuracion_sistema (clave, valor, descripcion) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
            );
            $st->execute([$clave, ConfiguracionSistemaService::POR_DEFECTO[$clave], 'restaurado por el test']);
        }
        ConfiguracionSistemaService::olvidar();
    }

    private function ajustar(string $clave, string $valor): void
    {
        $this->db->prepare('UPDATE configuracion_sistema SET valor = ? WHERE clave = ?')->execute([$valor, $clave]);
        ConfiguracionSistemaService::olvidar();
    }

    private function valor(string $sql): mixed
    {
        return $this->db->query($sql)->fetchColumn();
    }

    private function estado(): string
    {
        return (string) $this->valor("SELECT estado FROM usuarios WHERE id = {$this->usuarioId}");
    }

    private function estadoPerfil(): string
    {
        return (string) $this->valor("SELECT estado FROM participantes WHERE id = {$this->participanteId}");
    }

    private function intentosFallidos(): int
    {
        return (int) $this->valor("SELECT failed_attempts FROM usuarios WHERE id = {$this->usuarioId}");
    }

    /** Intenta entrar $n veces con la clave equivocada y devuelve el último mensaje. */
    private function fallarLogin(int $n, string $email = self::EMAIL): string
    {
        $mensaje = '';
        for ($i = 0; $i < $n; $i++) {
            try { (new AuthService())->login($email, 'Clave-Equivocada-1!'); }
            catch (ErrorNegocio $e) { $mensaje = $e->getMessage(); }
        }
        return $mensaje;
    }

    private function entrar(): array
    {
        $usuario = (new AuthService())->login(self::EMAIL, self::PASSWORD);
        Fixtures::loguearComo(Fixtures::ADMIN);   // el login dejó la sesión de la cuenta de prueba
        return $usuario;
    }

    /** Segundos que faltan para que venza el bloqueo, según la propia base. */
    private function segundosDeBloqueo(): ?int
    {
        $s = $this->valor("SELECT TIMESTAMPDIFF(SECOND, NOW(), bloqueada_hasta) FROM usuarios WHERE id = {$this->usuarioId}");
        return $s === null ? null : (int) $s;
    }

    private function venceElBloqueo(): void
    {
        $this->db->exec("UPDATE usuarios SET bloqueada_hasta = NOW() - INTERVAL 1 MINUTE WHERE id = {$this->usuarioId}");
    }

    // ─── Bloqueo temporal ───────────────────────────────────────────────────

    public function test_al_llegar_al_maximo_la_cuenta_se_bloquea_por_el_tiempo_configurado(): void
    {
        $this->fallarLogin(5);

        $this->assertSame('bloqueada', $this->estado());
        $this->assertSame('suspendido', $this->estadoPerfil());
        $segundos = $this->segundosDeBloqueo();
        $this->assertNotNull($segundos, 'el bloqueo tiene fecha de fin (antes duraba hasta que un administrador lo levantara)');
        $this->assertGreaterThan(14 * 60, $segundos, 'login_minutos_bloqueo = 15');
        $this->assertTrue($segundos <= 15 * 60, 'y no más que eso');
        $this->assertSame(1, (int) $this->valor("SELECT COUNT(*) FROM auditoria WHERE accion = 'cuenta_bloqueada' AND descripcion LIKE '%15 minutos%'"));
    }

    public function test_mientras_dura_el_bloqueo_no_entra_ni_con_la_clave_correcta_y_se_le_dice_hasta_cuando(): void
    {
        $this->fallarLogin(5);

        $e = $this->assertThrows(fn() => (new AuthService())->login(self::EMAIL, self::PASSWORD), 'bloqueada');
        $this->assertTrue(preg_match('/a las \d\d:\d\d/', $e->getMessage()) === 1, 'le dice a qué hora puede volver a intentar');
        $this->assertStringContainsString('administrador', $e->getMessage(), 'o a quién recurrir si no quiere esperar');
    }

    public function test_vencido_el_bloqueo_la_cuenta_entra_sola(): void
    {
        $this->fallarLogin(5);
        $this->venceElBloqueo();

        $usuario = $this->entrar();

        $this->assertSame($this->usuarioId, (int) $usuario['id']);
        $this->assertSame('activo', $this->estado());
        $this->assertSame('activo', $this->estadoPerfil(), 'el perfil vuelve junto con la cuenta');
        $this->assertSame(0, $this->intentosFallidos());
        $this->assertSame(null, $this->segundosDeBloqueo(), 'ya no tiene fecha de bloqueo');
        $this->assertSame(1, (int) $this->valor("SELECT COUNT(*) FROM auditoria WHERE accion = 'cuenta_desbloqueada_auto'"));
    }

    public function test_vencido_el_bloqueo_el_conteo_de_intentos_empieza_de_cero(): void
    {
        $this->fallarLogin(5);
        $this->venceElBloqueo();

        $this->fallarLogin(1);

        $this->assertSame('activo', $this->estado(), 'un solo error después de vencido el bloqueo no la vuelve a bloquear');
        $this->assertSame(1, $this->intentosFallidos(), 'cuenta desde cero, no 6');
    }

    public function test_con_cero_minutos_el_bloqueo_dura_hasta_que_un_administrador_lo_levante(): void
    {
        $this->ajustar('login_minutos_bloqueo', '0');
        $this->fallarLogin(5);

        $this->assertSame('bloqueada', $this->estado());
        $this->assertSame(null, $this->segundosDeBloqueo(), 'sin fecha de fin');

        $e = $this->assertThrows(fn() => (new AuthService())->login(self::EMAIL, self::PASSWORD), 'bloqueada');
        $this->assertStringContainsString('administrador', $e->getMessage());
        $this->assertTrue(preg_match('/\d\d:\d\d/', $e->getMessage()) === 0, 'no promete una hora que no existe');

        (new UsuarioService())->desbloquear($this->usuarioId);
        $this->assertSame('activo', $this->estado());
    }

    public function test_el_bloqueo_manual_no_vence(): void
    {
        (new UsuarioService())->bloquear($this->usuarioId);

        $this->assertSame('bloqueada', $this->estado());
        $this->assertSame(null, $this->segundosDeBloqueo());
    }

    public function test_desbloquear_borra_la_fecha_del_bloqueo(): void
    {
        $this->fallarLogin(5);
        (new UsuarioService())->desbloquear($this->usuarioId);

        $this->assertSame('activo', $this->estado());
        $this->assertSame(null, $this->segundosDeBloqueo());
    }

    // ─── Ajustes ────────────────────────────────────────────────────────────

    public function test_los_limites_salen_de_los_ajustes_del_sistema(): void
    {
        $this->ajustar('login_max_intentos', '3');
        $this->ajustar('login_minutos_bloqueo', '2');

        $this->fallarLogin(2);
        $this->assertSame('activo', $this->estado(), 'con 3 como máximo, dos errores no bloquean');

        $this->fallarLogin(1);
        $this->assertSame('bloqueada', $this->estado(), 'el tercero sí');
        $this->assertTrue($this->segundosDeBloqueo() <= 120, 'y dura los 2 minutos configurados');
    }

    public function test_un_ajuste_que_no_sirve_no_deja_el_login_sin_limites(): void
    {
        foreach (['abc' => '5', '0' => '5', '-3' => '5', '' => '5', '2.5' => '5'] as $valor => $esperado) {
            $this->ajustar('login_max_intentos', (string) $valor);
            $this->assertSame((int) $esperado, (new ConfiguracionSistemaService())->getInt('login_max_intentos', 1),
                "«{$valor}» no es un máximo válido: se usa el de fábrica");
        }

        $this->ajustar('login_max_intentos', 'abc');
        $this->fallarLogin(4);
        $this->assertSame('activo', $this->estado());
        $this->fallarLogin(1);
        $this->assertSame('bloqueada', $this->estado(), 'con el ajuste roto rige el máximo de fábrica (5), no «sin límite»');
    }

    public function test_un_ajuste_ausente_usa_el_valor_de_fabrica(): void
    {
        $fila = $this->db->query("SELECT * FROM configuracion_sistema WHERE clave = 'login_max_intentos_ip'")->fetch(PDO::FETCH_ASSOC);
        $this->db->exec("DELETE FROM configuracion_sistema WHERE clave = 'login_max_intentos_ip'");
        ConfiguracionSistemaService::olvidar();
        try {
            $this->assertSame(20, (new ConfiguracionSistemaService())->getInt('login_max_intentos_ip', 1));
        } finally {
            $this->db->prepare('INSERT INTO configuracion_sistema (clave, valor, descripcion) VALUES (?, ?, ?)')
                     ->execute([$fila['clave'], $fila['valor'], $fila['descripcion']]);
            ConfiguracionSistemaService::olvidar();
        }
    }

    // ─── Tiempo de respuesta ────────────────────────────────────────────────

    public function test_un_email_inexistente_verifica_contra_un_hash_valido_del_mismo_costo(): void
    {
        // Si el email no existe y se respondiera sin verificar nada, el login tardaría una fracción de
        // lo que tarda con una cuenta real, y esa diferencia delataría qué emails tienen cuenta. Para
        // que no pase, se verifica contra un hash falso: tiene que ser un bcrypt VÁLIDO y del mismo
        // costo que los reales (con un hash mal escrito password_verify falla al instante).
        $falso = (new ReflectionClassConstant(AuthService::class, 'CLAVE_FALSA'))->getValue();
        $info  = password_get_info($falso);
        $real  = password_get_info((string) $this->valor('SELECT password_hash FROM usuarios WHERE id = ' . $this->usuarioId));

        $this->assertSame('bcrypt', $info['algoName'], 'un bcrypt válido');
        $this->assertSame($real['options']['cost'], $info['options']['cost'], 'del mismo costo que los hashes reales');
        $this->assertFalse(password_verify('cualquier cosa', $falso), 'y que nadie conoce la clave');

        $fuente = (string) file_get_contents(dirname(__DIR__) . '/app/services/AuthService.php');
        $this->assertStringContainsString('password_verify($password, self::CLAVE_FALSA)', $fuente,
            'y que la rama «el email no existe» lo use');
    }

    // ─── Límite por IP ──────────────────────────────────────────────────────

    public function test_una_ip_que_agota_sus_intentos_queda_afuera_aunque_use_la_clave_correcta(): void
    {
        $this->ajustar('login_max_intentos_ip', '3');
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        // Tres intentos contra cuentas que ni existen: no bloquean a nadie, pero gastan los de la IP.
        $this->fallarLogin(1, 'a@test.local');
        $this->fallarLogin(1, 'b@test.local');
        $this->fallarLogin(1, 'c@test.local');
        $this->assertSame(3, (int) $this->valor("SELECT COUNT(*) FROM intentos_login WHERE ip = '10.0.0.1'"));

        $e = $this->assertThrows(fn() => (new AuthService())->login(self::EMAIL, self::PASSWORD), 'demasiados intentos');
        $this->assertTrue($e instanceof ErrorNegocio);

        $this->assertSame('activo', $this->estado(), 'no se tocó la cuenta ni se miró su clave');
        $this->assertSame(0, $this->intentosFallidos());
        $this->assertSame(3, (int) $this->valor("SELECT COUNT(*) FROM intentos_login WHERE ip = '10.0.0.1'"),
            'y mientras está bloqueada no se siguen anotando intentos (el bloqueo no se prolonga solo)');
    }

    public function test_otra_ip_no_se_ve_afectada(): void
    {
        $this->ajustar('login_max_intentos_ip', '3');
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $this->fallarLogin(3, 'a@test.local');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
        $this->assertDoesNotThrow(fn() => $this->entrar());
    }

    public function test_pasada_la_ventana_la_ip_vuelve_a_poder_intentar(): void
    {
        $this->ajustar('login_max_intentos_ip', '3');
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $this->fallarLogin(3, 'a@test.local');
        $this->assertThrows(fn() => (new AuthService())->login(self::EMAIL, self::PASSWORD), 'demasiados intentos');

        $this->db->exec("UPDATE intentos_login SET created_at = NOW() - INTERVAL 20 MINUTE");   // ventana: 15

        $this->assertDoesNotThrow(fn() => $this->entrar());
    }

    public function test_los_intentos_contra_una_cuenta_bloqueada_tambien_gastan_los_de_la_ip(): void
    {
        $this->fallarLogin(5);                                   // bloquea la cuenta (5 intentos de esta IP)
        $antes = (int) $this->valor('SELECT COUNT(*) FROM intentos_login');
        $this->fallarLogin(2);                                   // contra la cuenta ya bloqueada

        $this->assertSame($antes + 2, (int) $this->valor('SELECT COUNT(*) FROM intentos_login'));
    }

    public function test_un_email_enorme_no_rompe_el_registro_de_intentos(): void
    {
        $largo = str_repeat('a', 500) . '@test.local';

        $e = $this->assertThrows(fn() => (new AuthService())->login($largo, 'Clave-Equivocada-1!'), 'incorrectos');
        $this->assertTrue($e instanceof ErrorNegocio, 'el aviso de siempre, no un error de la base');
        $this->assertSame(150, (int) $this->valor('SELECT CHAR_LENGTH(email) FROM intentos_login ORDER BY id DESC LIMIT 1'),
            'el email se guarda cortado al largo de la columna');
    }

    public function test_se_purga_lo_viejo(): void
    {
        $this->db->exec("INSERT INTO intentos_login (ip, email, created_at) VALUES ('9.9.9.9', 'x', NOW() - INTERVAL 3 DAY), ('9.9.9.9', 'y', NOW())");

        $this->assertSame(1, (new IntentoLoginModel())->purgarAnteriores(24));
        $this->assertSame(1, (int) $this->valor("SELECT COUNT(*) FROM intentos_login WHERE ip = '9.9.9.9'"));
    }

    // ─── Bloquear y desbloquear solo cuando corresponde ─────────────────────

    public function test_una_cuenta_pendiente_o_rechazada_no_pasa_a_bloqueada_por_fallar_la_clave(): void
    {
        foreach (['pendiente', 'rechazado'] as $estado) {
            $this->db->exec("UPDATE usuarios SET estado = '{$estado}', failed_attempts = 0 WHERE id = {$this->usuarioId}");
            $this->db->exec('DELETE FROM intentos_login');

            $this->fallarLogin(6);

            $this->assertSame($estado, $this->estado(), "una cuenta «{$estado}» se queda como está (antes pasaba a bloqueada)");
        }
    }

    public function test_desbloquear_no_activa_una_cuenta_pendiente_ni_rechazada(): void
    {
        foreach (['pendiente', 'rechazado', 'inactivo'] as $estado) {
            $this->db->exec("UPDATE usuarios SET estado = '{$estado}' WHERE id = {$this->usuarioId}");

            $this->assertThrows(fn() => (new UsuarioService())->desbloquear($this->usuarioId), 'no está bloqueada');
            $this->assertSame($estado, $this->estado(), "sigue «{$estado}»: desbloquear ya no la activa");
        }
    }

    public function test_desbloquear_una_cuenta_activa_avisa_que_no_hay_nada_que_hacer(): void
    {
        $this->assertThrows(fn() => (new UsuarioService())->desbloquear($this->usuarioId), 'no está bloqueada');
        $this->assertSame(0, (int) $this->valor("SELECT COUNT(*) FROM auditoria WHERE accion = 'desbloquear_cuenta'"),
            'y no queda registrado como si se hubiera desbloqueado algo');
    }

    public function test_solo_se_bloquean_cuentas_activas(): void
    {
        $this->db->exec("UPDATE usuarios SET estado = 'pendiente' WHERE id = {$this->usuarioId}");
        $this->assertThrows(fn() => (new UsuarioService())->bloquear($this->usuarioId), 'Solo se puede bloquear una cuenta activa');
        $this->assertSame('pendiente', $this->estado());
    }

    public function test_nadie_puede_bloquear_su_propia_cuenta(): void
    {
        Fixtures::loguearComo($this->usuarioId);
        $this->assertThrows(fn() => (new UsuarioService())->bloquear($this->usuarioId), 'tu propia cuenta');
        $this->assertSame('activo', $this->estado());
    }

    // ─── Los administradores ────────────────────────────────────────────────

    /** Crea un segundo administrador activo y devuelve su id. */
    private function segundoAdministrador(): int
    {
        return Fixtures::usuario('Segundo Admin', 'admin2' . Fixtures::DOMINIO_TEST, 'Clave-Segura-9876!', 1);
    }

    public function test_el_unico_administrador_activo_no_se_puede_bloquear_ni_desactivar_ni_degradar(): void
    {
        $this->assertSame(1, (new UsuarioModel())->contarAdministradoresActivos(), 'control: el seed trae un solo administrador');
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);        // quien hace el pedido no es el administrador mismo
        $svc = new UsuarioService();

        $this->assertThrows(fn() => $svc->bloquear(Fixtures::ADMIN), 'único administrador activo');
        $this->assertThrows(fn() => $svc->toggleActivo(Fixtures::ADMIN), 'único administrador activo');
        $datos = ['nombre' => 'Administrador', 'email' => 'admin@flexarena.com', 'rol_id' => 2, 'estado' => 'activo'];
        $this->assertThrows(fn() => $svc->editar(Fixtures::ADMIN, $datos), 'único administrador activo');
        $this->assertThrows(fn() => $svc->editar(Fixtures::ADMIN, ['rol_id' => 1, 'estado' => 'inactivo'] + $datos), 'único administrador activo');

        $this->assertSame('activo', $this->valor('SELECT estado FROM usuarios WHERE id = ' . Fixtures::ADMIN));
        $this->assertSame(1, (int) $this->valor('SELECT rol_id FROM usuarios WHERE id = ' . Fixtures::ADMIN));
    }

    public function test_con_otro_administrador_activo_si_se_puede_pero_el_ultimo_queda_protegido(): void
    {
        $otro = $this->segundoAdministrador();
        $svc  = new UsuarioService();
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);

        $svc->bloquear(Fixtures::ADMIN);                      // hay otro: se permite
        $this->assertSame('bloqueada', $this->valor('SELECT estado FROM usuarios WHERE id = ' . Fixtures::ADMIN));

        // Ahora el segundo es el único activo.
        $this->assertThrows(fn() => $svc->bloquear($otro), 'único administrador activo');
        $this->assertThrows(fn() => $svc->toggleActivo($otro), 'único administrador activo');

        $svc->desbloquear(Fixtures::ADMIN);
        $svc->bloquear($otro);                                // el primero volvió: ahora se puede
        $this->assertSame('bloqueada', $this->valor("SELECT estado FROM usuarios WHERE id = {$otro}"));
    }

    public function test_un_administrador_no_puede_quitarse_el_rol_ni_desactivarse_a_si_mismo(): void
    {
        $this->segundoAdministrador();                        // hay otro, así que no es la regla del último
        $datos = ['nombre' => 'Administrador', 'email' => 'admin@flexarena.com'];

        $this->assertThrows(fn() => (new UsuarioService())->editar(Fixtures::ADMIN, $datos + ['rol_id' => 2, 'estado' => 'activo']),
            'No podés quitarte el rol de administrador');
        $this->assertThrows(fn() => (new UsuarioService())->editar(Fixtures::ADMIN, $datos + ['rol_id' => 1, 'estado' => 'inactivo']),
            'No podés quitarte el rol de administrador');
        $this->assertSame(1, (int) $this->valor('SELECT rol_id FROM usuarios WHERE id = ' . Fixtures::ADMIN));
    }

    public function test_el_bloqueo_automatico_del_unico_administrador_nunca_es_permanente(): void
    {
        $this->ajustar('login_minutos_bloqueo', '0');         // «hasta que un administrador la desbloquee»

        $this->fallarLogin(5, 'admin@flexarena.com');

        $this->assertSame('bloqueada', $this->valor('SELECT estado FROM usuarios WHERE id = ' . Fixtures::ADMIN));
        $segundos = $this->valor('SELECT TIMESTAMPDIFF(SECOND, NOW(), bloqueada_hasta) FROM usuarios WHERE id = ' . Fixtures::ADMIN);
        $this->assertNotNull($segundos, 'si fuera permanente, cualquiera que conozca el email dejaría al sistema sin administrador');
        $this->assertTrue((int) $segundos > 14 * 60 && (int) $segundos <= 15 * 60);
    }

    public function test_con_otro_administrador_el_ajuste_de_cero_minutos_se_respeta(): void
    {
        $this->segundoAdministrador();
        $this->ajustar('login_minutos_bloqueo', '0');

        $this->fallarLogin(5, 'admin@flexarena.com');

        $this->assertSame(null, $this->valor('SELECT bloqueada_hasta FROM usuarios WHERE id = ' . Fixtures::ADMIN),
            'hay otro administrador que lo puede desbloquear: el ajuste manda');
    }

    public function test_no_se_puede_crear_un_usuario_con_un_rol_que_no_existe(): void
    {
        $this->assertThrows(fn() => (new UsuarioService())->crear([
            'nombre' => 'Sin Rol', 'email' => 'sinrol@test.local', 'rol_id' => 999, 'password' => 'Clave-Segura-9876!',
        ]), 'El rol elegido no existe');
        $this->assertSame(0, (int) $this->valor("SELECT COUNT(*) FROM usuarios WHERE email = 'sinrol@test.local'"));
    }
}

TestCase::correrSiEsScript(LoginLimitesTest::class);
