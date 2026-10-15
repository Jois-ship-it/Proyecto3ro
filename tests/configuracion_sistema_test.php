<?php
declare(strict_types=1);

/**
 * Test de la configuración general del sistema (G4 de la letra: §5.1 y §5.4).
 *
 * Qué pasaba antes: la tabla `configuracion_sistema` tenía siete ajustes y solo el login leía cuatro.
 * El nombre del sitio, el correo de contacto y el interruptor de perfiles públicos no los leía
 * nadie, y no había dónde cambiar ninguno sin tocar la base a mano.
 *
 * Qué se comprueba:
 *   - Validar: cada ajuste acepta lo que tiene que aceptar y rechaza el resto con un motivo, y un
 *     valor que podría romper algo (saltos de línea en un correo, un texto enorme) no pasa.
 *   - Guardar: solo un administrador; se guarda todo o nada; queda quién lo cambió y UNA entrada en
 *     la auditoría con lo que cambió; sin cambios no se escribe nada.
 *   - Leer: un valor inservible en la base cae al de fábrica.
 *   - Efectos: el nombre del sitio sale escapado en las páginas; con los perfiles cerrados solo los
 *     ve quien inició sesión (y se decide antes de buscar el perfil), y los enlaces a los perfiles
 *     desaparecen para quien no puede entrar.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/configuracion_sistema_test.php
 */

require __DIR__ . '/bootstrap.php';

if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));
if (!defined('APP_PATH'))  define('APP_PATH',  BASE_PATH . '/app');
require_once BASE_PATH . '/config/app.php';

class ConfiguracionSistemaTest extends TestCase
{
    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        $this->restaurar();
        Fixtures::cerrarSesion();
    }

    protected function tearDown(): void
    {
        $this->restaurar();
        Fixtures::cerrarSesion();
    }

    // ── apoyo ──────────────────────────────────────────────────────────

    /** Deja los ajustes como los trae el seed y descarta lo que el servicio tenía leído. */
    private function restaurar(): void
    {
        $st = $this->db->prepare(
            'INSERT INTO configuracion_sistema (clave, valor, descripcion) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), updated_by = NULL'
        );
        foreach (ConfiguracionSistemaService::POR_DEFECTO as $clave => $valor) {
            $st->execute([$clave, $valor, ConfiguracionSistemaService::CAMPOS[$clave]['descripcion']]);
        }
        ConfiguracionSistemaService::olvidar();
    }

    private function servicio(): ConfiguracionSistemaService
    {
        return new ConfiguracionSistemaService();
    }

    /** Pone un valor directo en la base (como si alguien lo hubiera escrito a mano) y descarta el caché. */
    private function enBase(string $clave, string $valor): void
    {
        $this->db->prepare('UPDATE configuracion_sistema SET valor = ? WHERE clave = ?')->execute([$valor, $clave]);
        ConfiguracionSistemaService::olvidar();
    }

    private function valorEnBase(string $clave): ?string
    {
        $st = $this->db->prepare('SELECT valor FROM configuracion_sistema WHERE clave = ?');
        $st->execute([$clave]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    private function auditorias(): array
    {
        return $this->db->query("SELECT * FROM auditoria WHERE accion = 'actualizar_configuracion' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── el catálogo ────────────────────────────────────────────────────

    public function test_el_catalogo_y_los_valores_de_fabrica_hablan_de_los_mismos_ajustes(): void
    {
        $claves = array_keys(ConfiguracionSistemaService::CAMPOS);
        $this->assertSame(array_keys(ConfiguracionSistemaService::POR_DEFECTO), $claves, 'mismos ajustes y mismo orden');
        foreach (ConfiguracionSistemaService::CAMPOS as $clave => $campo) {
            foreach (['grupo', 'tipo', 'etiqueta', 'ayuda', 'descripcion'] as $atributo) {
                $this->assertTrue(!empty($campo[$atributo]), "{$clave} define «{$atributo}»");
            }
            $this->assertTrue(in_array($campo['grupo'], ['sitio', 'acceso'], true), "{$clave}: grupo conocido");
        }
    }

    public function test_cada_valor_de_fabrica_es_un_valor_valido(): void
    {
        $this->assertSame(
            ConfiguracionSistemaService::POR_DEFECTO,
            $this->servicio()->validar(ConfiguracionSistemaService::POR_DEFECTO),
            'si lo de fábrica no pasara la validación, restaurar no se podría guardar'
        );
    }

    public function test_las_descripciones_son_las_del_seed(): void
    {
        $filas = $this->db->query('SELECT clave, descripcion FROM configuracion_sistema')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (ConfiguracionSistemaService::CAMPOS as $clave => $campo) {
            $this->assertSame($filas[$clave] ?? null, $campo['descripcion'], "{$clave}: la descripción del catálogo es la de seed.sql");
        }
    }

    public function test_los_minimos_de_los_enteros_son_los_que_usa_el_login(): void
    {
        // AuthService lee con getInt(clave, 1|0|1|1): si el formulario aceptara menos, getInt ignoraría el valor.
        $this->assertSame(1, ConfiguracionSistemaService::CAMPOS['login_max_intentos']['min']);
        $this->assertSame(0, ConfiguracionSistemaService::CAMPOS['login_minutos_bloqueo']['min']);
        $this->assertSame(1, ConfiguracionSistemaService::CAMPOS['login_max_intentos_ip']['min']);
        $this->assertSame(1, ConfiguracionSistemaService::CAMPOS['login_ventana_minutos']['min']);
    }

    // ── validar ────────────────────────────────────────────────────────

    public function test_validar_deja_cada_valor_en_su_forma_normal(): void
    {
        $r = $this->servicio()->validar([
            'sitio_nombre'          => '  Mi Liga Escolar  ',
            'sitio_contacto'        => ' contacto@ejemplo.com ',
            'perfiles_publicos'     => '0',
            'login_max_intentos'    => '007',
            'login_minutos_bloqueo' => ' 30 ',
        ]);

        $this->assertSame([
            'sitio_nombre'          => 'Mi Liga Escolar',
            'sitio_contacto'        => 'contacto@ejemplo.com',
            'perfiles_publicos'     => '0',
            'login_max_intentos'    => '7',
            'login_minutos_bloqueo' => '30',
        ], $r);
    }

    public function test_validar_solo_mira_lo_que_viene(): void
    {
        $this->assertSame([], $this->servicio()->validar([]), 'sin nada, nada');
        $this->assertSame(['login_max_intentos' => '3'], $this->servicio()->validar(['login_max_intentos' => '3', 'inventado' => 'x', 'csrf_token' => 'abc']),
            'lo que no es un ajuste se ignora');
    }

    public function test_el_interruptor_acepta_solo_cero_o_uno(): void
    {
        $s = $this->servicio();
        $this->assertSame(['perfiles_publicos' => '1'], $s->validar(['perfiles_publicos' => '1']));
        $this->assertSame(['perfiles_publicos' => '1'], $s->validar(['perfiles_publicos' => true]));
        $this->assertSame(['perfiles_publicos' => '1'], $s->validar(['perfiles_publicos' => 1]));
        $this->assertSame(['perfiles_publicos' => '0'], $s->validar(['perfiles_publicos' => false]));
        foreach (['2', 'on', 'true', 'si', '', '-1'] as $malo) {
            $this->assertThrows(fn() => $s->validar(['perfiles_publicos' => $malo]), 'Perfiles públicos', "«{$malo}» no es un valor del interruptor");
        }
    }

    public function test_el_nombre_del_sitio(): void
    {
        $s = $this->servicio();
        $this->assertSame(['sitio_nombre' => 'Ñandú Fútbol Club'], $s->validar(['sitio_nombre' => 'Ñandú Fútbol Club']));
        $this->assertSame(['sitio_nombre' => str_repeat('a', 60)], $s->validar(['sitio_nombre' => str_repeat('a', 60)]), '60 caracteres entran');
        $this->assertSame(['sitio_nombre' => str_repeat('ñ', 60)], $s->validar(['sitio_nombre' => str_repeat('ñ', 60)]), 'se cuentan caracteres, no bytes');

        $this->assertThrows(fn() => $s->validar(['sitio_nombre' => '']), 'no puede quedar vacío');
        $this->assertThrows(fn() => $s->validar(['sitio_nombre' => '   ']), 'no puede quedar vacío');
        $this->assertThrows(fn() => $s->validar(['sitio_nombre' => str_repeat('a', 61)]), 'hasta 60 caracteres');
        $this->assertThrows(fn() => $s->validar(['sitio_nombre' => "Liga\nFalsa"]), 'caracteres no permitidos');
        $this->assertThrows(fn() => $s->validar(['sitio_nombre' => "Li\x00ga"]), 'caracteres no permitidos');
        $this->assertSame(['sitio_nombre' => 'Liga'], $s->validar(['sitio_nombre' => "Liga\x00\n"]), 'lo que sobra en los extremos se recorta, no se rechaza');
        $this->assertThrows(fn() => $s->validar(['sitio_nombre' => "Liga\xFF\xFE"]), 'caracteres no permitidos');
        $this->assertThrows(fn() => $s->validar(['sitio_nombre' => ['a']]), 'no es válido');
        $this->assertThrows(fn() => $s->validar(['sitio_nombre' => null]), 'no es válido');
    }

    public function test_el_correo_de_contacto(): void
    {
        $s = $this->servicio();
        $this->assertSame(['sitio_contacto' => ''], $s->validar(['sitio_contacto' => '']), 'vacío es válido: no se muestra');
        $this->assertSame(['sitio_contacto' => 'liga@club.org.ar'], $s->validar(['sitio_contacto' => 'liga@club.org.ar']));

        foreach (['sin-arroba', 'a@', '@b.com', 'a b@c.com', "a@b.com\nBcc: otro@x.com", "a@b.com\r\nBcc: c@d.com", 'a@b.com, c@d.com', '<a@b.com>'] as $malo) {
            $this->assertThrows(fn() => $s->validar(['sitio_contacto' => $malo]), 'correo', 'se rechaza: ' . json_encode($malo));
        }
        $this->assertThrows(fn() => $s->validar(['sitio_contacto' => str_repeat('a', 145) . '@b.com']), 'correo', 'más de 150 caracteres');
    }

    public function test_los_enteros_respetan_su_rango(): void
    {
        $s = $this->servicio();
        // [clave, mínimo, máximo]
        foreach ([['login_max_intentos', 1, 20], ['login_minutos_bloqueo', 0, 1440], ['login_max_intentos_ip', 1, 1000], ['login_ventana_minutos', 1, 1440]] as [$clave, $min, $max]) {
            $this->assertSame([$clave => (string) $min], $s->validar([$clave => (string) $min]), "{$clave}: el mínimo entra");
            $this->assertSame([$clave => (string) $max], $s->validar([$clave => (string) $max]), "{$clave}: el máximo entra");
            $this->assertThrows(fn() => $s->validar([$clave => (string) ($max + 1)]), "entre {$min} y {$max}", "{$clave}: el máximo + 1 no");
            if ($min > 0) {
                $this->assertThrows(fn() => $s->validar([$clave => (string) ($min - 1)]), "entre {$min} y {$max}", "{$clave}: el mínimo - 1 no");
            }
            foreach (['', 'abc', '-1', '1.5', '1,5', '1e2', '0x10', '１２', '12abc', ' ', '99999999999'] as $malo) {
                $this->assertThrows(fn() => $s->validar([$clave => $malo]), 'número entero', "{$clave}: se rechaza " . json_encode($malo));
            }
        }
    }

    public function test_un_numero_se_acepta_como_texto_o_como_entero(): void
    {
        $this->assertSame(['login_max_intentos' => '8'], $this->servicio()->validar(['login_max_intentos' => 8]));
    }

    // ── guardar ────────────────────────────────────────────────────────

    public function test_solo_un_administrador_puede_guardar(): void
    {
        foreach ([Fixtures::ORGANIZADOR, 4, 999999] as $actor) {
            $e = $this->assertThrows(fn() => $this->servicio()->guardar(['sitio_nombre' => 'Intruso'], $actor), 'Solo un administrador');
            $this->assertTrue($e instanceof ErrorNegocio);
        }
        $this->assertSame('FlexArena', $this->valorEnBase('sitio_nombre'), 'no se guardó nada');
        $this->assertSame([], $this->auditorias());
    }

    public function test_guardar_cambia_solo_lo_que_cambio(): void
    {
        $cambios = $this->servicio()->guardar([
            'sitio_nombre'       => 'Liga del Sur',
            'sitio_contacto'     => '',                        // igual al que hay
            'login_max_intentos' => '8',
            'login_minutos_bloqueo' => '15',                   // igual al que hay
        ], Fixtures::ADMIN);

        $this->assertSame([
            'sitio_nombre'       => ['antes' => 'FlexArena', 'despues' => 'Liga del Sur'],
            'login_max_intentos' => ['antes' => '5',         'despues' => '8'],
        ], $cambios);
        $this->assertSame('Liga del Sur', $this->valorEnBase('sitio_nombre'));
        $this->assertSame('8', $this->valorEnBase('login_max_intentos'));
        $this->assertSame('15', $this->valorEnBase('login_minutos_bloqueo'), 'lo que no cambió, igual');
    }

    public function test_queda_quien_lo_cambio(): void
    {
        $this->servicio()->guardar(['sitio_nombre' => 'Liga del Sur', 'login_max_intentos' => '8'], Fixtures::ADMIN);

        $filas = $this->db->query("SELECT clave, updated_by FROM configuracion_sistema ORDER BY clave")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(Fixtures::ADMIN, (int) $filas['sitio_nombre']);
        $this->assertSame(Fixtures::ADMIN, (int) $filas['login_max_intentos']);
        $this->assertSame(null, $filas['login_minutos_bloqueo'], 'quien no cambió no figura');
    }

    public function test_la_auditoria_tiene_una_sola_entrada_con_lo_de_antes_y_lo_de_despues(): void
    {
        $this->servicio()->guardar(['sitio_nombre' => 'Liga del Sur', 'perfiles_publicos' => '0', 'login_max_intentos' => '5'], Fixtures::ADMIN);

        $filas = $this->auditorias();
        $this->assertCount(1, $filas, 'una entrada por guardado, no una por ajuste');
        $f = $filas[0];
        $this->assertSame('configuracion_sistema', $f['tabla_afectada']);
        $this->assertSame(Fixtures::ADMIN, (int) $f['usuario_id']);
        $this->assertSame(['sitio_nombre' => 'FlexArena', 'perfiles_publicos' => '1'], json_decode((string) $f['valor_anterior'], true));
        $this->assertSame(['sitio_nombre' => 'Liga del Sur', 'perfiles_publicos' => '0'], json_decode((string) $f['valor_nuevo'], true));
        $this->assertStringContainsString('nombre del sitio', (string) $f['descripcion']);
        $this->assertStringContainsString('perfiles públicos', (string) $f['descripcion']);
    }

    public function test_sin_cambios_no_se_escribe_nada(): void
    {
        $cambios = $this->servicio()->guardar(ConfiguracionSistemaService::POR_DEFECTO, Fixtures::ADMIN);

        $this->assertSame([], $cambios);
        $this->assertSame([], $this->auditorias(), 'ni se audita');
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM configuracion_sistema WHERE updated_by IS NOT NULL')->fetchColumn());
        $this->assertSame([], $this->servicio()->guardar([], Fixtures::ADMIN), 'un envío vacío tampoco');
    }

    public function test_si_un_valor_no_sirve_no_se_guarda_ninguno(): void
    {
        $e = $this->assertThrows(fn() => $this->servicio()->guardar([
            'sitio_nombre'       => 'Liga del Sur',            // válido
            'login_max_intentos' => '50',                      // fuera de rango
        ], Fixtures::ADMIN), 'entre 1 y 20');

        $this->assertTrue($e instanceof ErrorNegocio);
        $this->assertSame('FlexArena', $this->valorEnBase('sitio_nombre'), 'el válido tampoco se guardó');
        $this->assertSame('5', $this->valorEnBase('login_max_intentos'));
        $this->assertSame([], $this->auditorias());
    }

    public function test_si_falla_a_mitad_se_deshace_todo(): void
    {
        // Un trigger que falla al tocar «login_max_intentos»: el ajuste anterior (nombre) tampoco puede quedar.
        $this->db->exec('DROP TRIGGER IF EXISTS trg_test_falla_config');
        $this->db->exec("CREATE TRIGGER trg_test_falla_config BEFORE UPDATE ON configuracion_sistema FOR EACH ROW
                         BEGIN IF NEW.clave = 'login_max_intentos' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'falla inducida'; END IF; END");
        try {
            $this->assertThrows(fn() => $this->servicio()->guardar(['sitio_nombre' => 'Liga del Sur', 'login_max_intentos' => '8'], Fixtures::ADMIN));
        } finally {
            $this->db->exec('DROP TRIGGER IF EXISTS trg_test_falla_config');
        }

        $this->assertSame('FlexArena', $this->valorEnBase('sitio_nombre'), 'no queda a medias');
        $this->assertSame([], $this->auditorias());
    }

    public function test_lo_guardado_se_lee_enseguida_en_el_mismo_pedido(): void
    {
        $s = $this->servicio();
        $this->assertSame('FlexArena', $s->get('sitio_nombre'));          // lo deja leído

        $s->guardar(['sitio_nombre' => 'Liga del Sur', 'login_max_intentos' => '9'], Fixtures::ADMIN);

        $this->assertSame('Liga del Sur', $s->get('sitio_nombre'));
        $this->assertSame('Liga del Sur', $this->servicio()->get('sitio_nombre'));
        $this->assertSame(9, $this->servicio()->getInt('login_max_intentos', 1), 'y el login lo usa');
    }

    public function test_si_la_fila_no_estaba_se_crea_con_su_descripcion(): void
    {
        $this->db->exec("DELETE FROM configuracion_sistema WHERE clave = 'sitio_contacto'");
        ConfiguracionSistemaService::olvidar();

        $this->assertSame([], $this->servicio()->guardar(['sitio_contacto' => ''], Fixtures::ADMIN), 'igual al de fábrica: nada que escribir');
        $this->assertSame(null, $this->valorEnBase('sitio_contacto'), 'y no crea la fila porque sí');

        $this->servicio()->guardar(['sitio_contacto' => 'liga@club.org'], Fixtures::ADMIN);

        $fila = $this->db->query("SELECT * FROM configuracion_sistema WHERE clave = 'sitio_contacto'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('liga@club.org', $fila['valor']);
        $this->assertSame(ConfiguracionSistemaService::CAMPOS['sitio_contacto']['descripcion'], $fila['descripcion']);
        $this->assertSame(Fixtures::ADMIN, (int) $fila['updated_by']);
    }

    public function test_los_valores_para_la_pantalla_completan_lo_que_falta(): void
    {
        $this->db->exec("DELETE FROM configuracion_sistema WHERE clave = 'login_max_intentos'");
        $this->enBase('sitio_nombre', 'Liga del Sur');

        $v = $this->servicio()->valores();

        $this->assertSame(array_keys(ConfiguracionSistemaService::CAMPOS), array_keys($v));
        $this->assertSame('Liga del Sur', $v['sitio_nombre']);
        $this->assertSame('5', $v['login_max_intentos'], 'sin fila: el de fábrica');
    }

    // ── leer ───────────────────────────────────────────────────────────

    public function test_el_sitio_por_defecto(): void
    {
        $this->assertSame(['nombre' => 'FlexArena', 'contacto' => '', 'perfiles_publicos' => true], $this->servicio()->sitio());
    }

    public function test_el_sitio_toma_lo_guardado(): void
    {
        $this->servicio()->guardar(['sitio_nombre' => 'Liga del Sur', 'sitio_contacto' => 'liga@club.org', 'perfiles_publicos' => '0'], Fixtures::ADMIN);

        $this->assertSame(['nombre' => 'Liga del Sur', 'contacto' => 'liga@club.org', 'perfiles_publicos' => false], $this->servicio()->sitio());
    }

    public function test_un_valor_inservible_en_la_base_cae_al_de_fabrica(): void
    {
        $this->enBase('sitio_nombre', '   ');
        $this->enBase('sitio_contacto', 'no-es-un-correo');
        $this->assertSame('FlexArena', $this->servicio()->sitio()['nombre'], 'un nombre en blanco no deja el sitio sin nombre');
        $this->assertSame('', $this->servicio()->sitio()['contacto'], 'un correo mal escrito no se muestra');

        foreach (['', 'x', 'abierto', '2'] as $raro) {
            $this->enBase('perfiles_publicos', $raro);
            $this->assertTrue($this->servicio()->perfilesPublicos(), "«{$raro}» no cierra los perfiles: solo un 0");
        }
        $this->enBase('perfiles_publicos', '0');
        $this->assertFalse($this->servicio()->perfilesPublicos());
    }

    // ── el nombre del sitio en las páginas ─────────────────────────────

    /** Lo que dibuja una acción del controlador público. */
    private function pagina(callable $accion): string
    {
        ob_start();
        try {
            $accion(new PublicoController());
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    public function test_el_nombre_del_sitio_sale_en_el_titulo_el_menu_y_el_pie(): void
    {
        $this->servicio()->guardar(['sitio_nombre' => 'Liga del Sur'], Fixtures::ADMIN);

        $html = $this->pagina(fn($c) => $c->home());

        $this->assertStringContainsString('<title>Inicio · Liga del Sur</title>', $html);
        $this->assertStringContainsString('<span>Liga del Sur</span>', $html, 'el menú');
        $this->assertStringContainsString('Liga del Sur · Sistema de Gestión Deportiva Modular', $html, 'el pie');
        $this->assertStringNotContainsString('FlexArena ·', $html, 'ya no queda el nombre viejo en el pie');
    }

    public function test_el_nombre_del_sitio_se_escapa(): void
    {
        $this->servicio()->guardar(['sitio_nombre' => 'Liga <script>alert(1)</script> & "Co"'], Fixtures::ADMIN);

        $html = $this->pagina(fn($c) => $c->home());

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'un nombre con HTML no inyecta nada');
        $this->assertStringContainsString('Liga &lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;Co&quot;', $html);
    }

    public function test_una_pagina_sin_controlador_usa_el_nombre_del_entorno(): void
    {
        $this->servicio()->guardar(['sitio_nombre' => 'Liga del Sur'], Fixtures::ADMIN);

        ob_start();
        View::render('shared/404', [], 'public');         // lo que dibuja el router para una ruta inexistente
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('<title>' . APP_NAME . '</title>', $html);
        $this->assertStringNotContainsString('Liga del Sur', $html, 'una página de error no depende de la base');
    }

    // ── perfiles públicos ──────────────────────────────────────────────

    private function perfilDeUnJugador(): int
    {
        return Fixtures::participantes(1, 'Visible')[0];
    }

    private function perfilDeUnEquipo(): int
    {
        $pids = Fixtures::participantes(3, 'Integrante');
        return Fixtures::equipos(1, $pids)[0];
    }

    public function test_con_los_perfiles_abiertos_cualquiera_los_ve(): void
    {
        $jugador = $this->perfilDeUnJugador();
        $equipo  = $this->perfilDeUnEquipo();

        $this->assertStringContainsString('Visible 1', $this->pagina(fn($c) => $c->jugador((string) $jugador)));
        $this->assertStringContainsString('Equipo 1', $this->pagina(fn($c) => $c->equipo((string) $equipo)));
    }

    public function test_con_los_perfiles_cerrados_un_visitante_va_al_login(): void
    {
        $jugador = $this->perfilDeUnJugador();
        $equipo  = $this->perfilDeUnEquipo();
        $this->servicio()->guardar(['perfiles_publicos' => '0'], Fixtures::ADMIN);

        $e = $this->assertThrows(fn() => $this->pagina(fn($c) => $c->jugador((string) $jugador)));
        $this->assertTrue($e instanceof SesionRequerida, 'el visitante tiene que iniciar sesión');
        $e = $this->assertThrows(fn() => $this->pagina(fn($c) => $c->equipo((string) $equipo)));
        $this->assertTrue($e instanceof SesionRequerida);
        $this->assertStringContainsString('sesión iniciada', (string) Session::getFlash('warning'), 'y se le explica por qué');
    }

    public function test_se_decide_antes_de_buscar_el_perfil(): void
    {
        $this->servicio()->guardar(['perfiles_publicos' => '0'], Fixtures::ADMIN);

        // Un id que no existe: un visitante no tiene que poder distinguirlo de uno que sí.
        $e = $this->assertThrows(fn() => $this->pagina(fn($c) => $c->jugador('987654')));
        $this->assertTrue($e instanceof SesionRequerida, 'ni siquiera un 404: no se averigua qué ids existen');
        $e = $this->assertThrows(fn() => $this->pagina(fn($c) => $c->equipo('987654')));
        $this->assertTrue($e instanceof SesionRequerida);
    }

    public function test_con_los_perfiles_cerrados_quien_inicio_sesion_los_ve(): void
    {
        $jugador = $this->perfilDeUnJugador();
        $equipo  = $this->perfilDeUnEquipo();
        $this->servicio()->guardar(['perfiles_publicos' => '0'], Fixtures::ADMIN);

        foreach ([Fixtures::ADMIN, Fixtures::ORGANIZADOR, 4] as $usuario) {
            Fixtures::loguearComo($usuario);
            $this->assertStringContainsString('Visible 1', $this->pagina(fn($c) => $c->jugador((string) $jugador)), "usuario {$usuario}");
            $this->assertStringContainsString('Equipo 1', $this->pagina(fn($c) => $c->equipo((string) $equipo)), "usuario {$usuario}");
        }
    }

    public function test_los_enlaces_a_perfiles_desaparecen_para_quien_no_puede_entrar(): void
    {
        [$tid] = Fixtures::torneoArrancado('liga', 4);
        $nombres = $this->db->query("SELECT nombre FROM participantes ORDER BY id DESC LIMIT 1")->fetchColumn();

        $abierto = $this->pagina(fn($c) => $c->detalle((string) $tid));
        $this->assertTrue((bool) preg_match('~href="/jugador/\d+"~', $abierto), 'con los perfiles abiertos hay enlaces');

        $this->servicio()->guardar(['perfiles_publicos' => '0'], Fixtures::ADMIN);
        $cerrado = $this->pagina(fn($c) => $c->detalle((string) $tid));
        $this->assertFalse((bool) preg_match('~href="/jugador/\d+"~', $cerrado), 'cerrados, un visitante no ve enlaces a perfiles');
        $this->assertStringContainsString((string) $nombres, $cerrado, 'pero los nombres siguen en la lista de inscritos');

        Fixtures::loguearComo(4);
        $conSesion = $this->pagina(fn($c) => $c->detalle((string) $tid));
        $this->assertTrue((bool) preg_match('~href="/jugador/\d+"~', $conSesion), 'quien inició sesión sí ve los enlaces');
    }

    public function test_la_api_no_depende_del_interruptor_de_perfiles(): void
    {
        // La API pública no tiene perfiles: nombres y posiciones, igual que las páginas de torneos.
        [$tid] = Fixtures::torneoArrancado('liga', 4);
        $this->servicio()->guardar(['perfiles_publicos' => '0'], Fixtures::ADMIN);

        $datos = (new ApiService())->posiciones($tid);

        $this->assertNotNull($datos, 'sigue respondiendo');
    }
}

TestCase::correrSiEsScript(ConfiguracionSistemaTest::class);
