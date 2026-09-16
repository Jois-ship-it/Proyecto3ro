<?php
declare(strict_types=1);

/**
 * Test de la autorización por rol y módulo (tabla `permisos`).
 *
 * Hasta el punto 10 la tabla existía, tenía FK e índice único y ninguna consulta
 * la leía: el control era solo por rol. Ahora `PermisoService` la lee y las
 * guardas de los controladores la consultan.
 *
 * Lo que se verifica:
 *   1. Las tres reglas del servicio: bypass del administrador, la fila manda,
 *      y sin fila se niega.
 *   2. Que la matriz sembrada diga lo que dice la letra del proyecto (§5). Este
 *      es el test que importa para la defensa: si alguien toca el seed y le da
 *      al organizador permisos que la letra le da al administrador, avisa.
 *   3. Que la pantalla de administración guarde de verdad, audite el cambio y
 *      no permita dejar al administrador sin acceso.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/permisos_test.php
 */

require __DIR__ . '/bootstrap.php';

final class PermisosTest extends TestCase
{
    private const ROL_ADMIN        = 1;
    private const ROL_ORGANIZADOR  = 2;
    private const ROL_PARTICIPANTE = 3;

    private PermisoService $servicio;
    private PermisoModel   $modelo;

    /** Copia de la matriz al empezar, para devolver la base como estaba. */
    private static ?array $matrizOriginal = null;

    protected function setUp(): void
    {
        $this->servicio = new PermisoService();
        $this->modelo   = new PermisoModel();

        if (self::$matrizOriginal === null) {
            self::$matrizOriginal = $this->db
                ->query("SELECT * FROM permisos ORDER BY rol_id, modulo_slug")
                ->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    protected function tearDown(): void
    {
        // Los casos que escriben dejan la matriz como la encontraron: el resto
        // de la suite (y el desarrollador) esperan el seed.
        $this->db->exec("DELETE FROM permisos");
        $sent = $this->db->prepare(
            "INSERT INTO permisos (rol_id, modulo_slug, puede_ver, puede_crear, puede_editar, puede_eliminar)
             VALUES (:r, :m, :v, :c, :e, :d)"
        );
        foreach (self::$matrizOriginal as $fila) {
            $sent->execute([
                ':r' => $fila['rol_id'],
                ':m' => $fila['modulo_slug'],
                ':v' => $fila['puede_ver'],
                ':c' => $fila['puede_crear'],
                ':e' => $fila['puede_editar'],
                ':d' => $fila['puede_eliminar'],
            ]);
        }
        Fixtures::cerrarSesion();
    }

    // ─── Las tres reglas ────────────────────────────────────────────────────

    public function test_el_administrador_pasa_sin_consultar_la_tabla(): void
    {
        // La letra §5.1 le da "control completo sobre el sistema".
        $sinFilas = (int) $this->db->query(
            "SELECT COUNT(*) FROM permisos WHERE rol_id = " . self::ROL_ADMIN
        )->fetchColumn();
        $this->assertSame(0, $sinFilas, 'el administrador no debería tener filas en permisos');

        foreach (['torneos', 'resultados', 'participantes', 'equipos', 'auditoria'] as $modulo) {
            foreach (PermisoService::ACCIONES as $accion) {
                $this->assertTrue(
                    $this->servicio->puede(self::ROL_ADMIN, $modulo, $accion),
                    "el administrador debería poder {$accion} en «{$modulo}» sin fila que lo habilite"
                );
            }
        }
    }

    public function test_sin_fila_se_niega(): void
    {
        // «auditoria» no tiene fila para ningún rol salvo el admin (que no pasa
        // por la tabla): consultar registros de auditoría es del admin (§5.1).
        foreach (PermisoService::ACCIONES as $accion) {
            $this->assertFalse(
                $this->servicio->puede(self::ROL_ORGANIZADOR, 'auditoria', $accion),
                "sin fila, el organizador no debería poder {$accion} en «auditoria»"
            );
        }

        // Un módulo que no existe tampoco abre la puerta.
        $this->assertFalse($this->servicio->puede(self::ROL_ORGANIZADOR, 'modulo_inventado', 'ver'));

        // Y un visitante sin sesión no puede nada.
        $this->assertFalse($this->servicio->puede(null, 'torneos', 'ver'));
    }

    public function test_cuando_hay_fila_manda_la_fila(): void
    {
        $this->modelo->guardar(self::ROL_ORGANIZADOR, 'equipos', true, false, true, false);
        $servicio = new PermisoService(); // caché limpia

        $this->assertTrue($servicio->puede(self::ROL_ORGANIZADOR, 'equipos', 'ver'));
        $this->assertTrue($servicio->puede(self::ROL_ORGANIZADOR, 'equipos', 'editar'));
        $this->assertFalse($servicio->puede(self::ROL_ORGANIZADOR, 'equipos', 'crear'));
        $this->assertFalse($servicio->puede(self::ROL_ORGANIZADOR, 'equipos', 'eliminar'));
    }

    public function test_una_accion_que_no_existe_rompe_en_vez_de_negar(): void
    {
        // Un typo en una guarda tiene que avisar, no negar en silencio: negar en
        // silencio deja una pantalla inaccesible sin explicación.
        $this->assertThrows(
            fn() => $this->servicio->puede(self::ROL_ORGANIZADOR, 'torneos', 'borrar'),
            'desconocida'
        );
    }

    public function test_el_permiso_sigue_al_usuario_logueado(): void
    {
        // Se reutiliza el organizador del seed en vez de crear uno: este test no
        // resetea la base, y crear el mismo usuario dos veces choca con el
        // UNIQUE de email en la segunda corrida.
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $this->assertSame(self::ROL_ORGANIZADOR, (int) Auth::user()['rol_id'],
            'Fixtures::ORGANIZADOR tiene que ser una cuenta con rol organizador');

        $servicio = new PermisoService();
        $this->assertTrue($servicio->puedeUsuarioActual('torneos', 'editar'));
        $this->assertFalse($servicio->puedeUsuarioActual('torneos', 'crear'));

        Fixtures::loguearComo(Fixtures::ADMIN);
        $servicio = new PermisoService();
        $this->assertTrue($servicio->puedeUsuarioActual('torneos', 'crear'), 'el admin pasa siempre');

        Fixtures::cerrarSesion();
        $servicio = new PermisoService();
        $this->assertFalse($servicio->puedeUsuarioActual('torneos', 'ver'), 'sin sesión no se puede nada');
    }

    // ─── La matriz contra la letra del proyecto ─────────────────────────────

    /**
     * Cada expectativa cita el punto de la letra que la justifica. Si el seed
     * cambia y deja de coincidir, este test dice exactamente qué se rompió.
     *
     * @return array<string, array{int, string, string, bool, string}>
     */
    private function expectativasDeLaLetra(): array
    {
        $o = self::ROL_ORGANIZADOR;
        $p = self::ROL_PARTICIPANTE;

        return [
            // §5.2: "configurar torneos asignados", "publicar o cerrar rondas".
            'organizador configura sus torneos'        => [$o, 'torneos', 'editar', true,
                '§5.2 «configurar torneos asignados»'],
            // §5.1: "crear y configurar torneos" es del administrador.
            'organizador NO crea torneos'              => [$o, 'torneos', 'crear', false,
                '§5.1 le da la creación de torneos al administrador'],
            'organizador NO elimina torneos'           => [$o, 'torneos', 'eliminar', false,
                '§5.1 la eliminación es del administrador'],

            // §5.2: "cargar resultados".
            'organizador carga resultados'             => [$o, 'resultados', 'crear', true,
                '§5.2 «cargar resultados»'],
            // §5.2: "corregir resultados si cuenta con autorización".
            'organizador corrige si tiene autorizacion'=> [$o, 'resultados', 'editar', true,
                '§5.2 «corregir resultados si cuenta con autorización»'],

            // §5.2: "generar rondas o llaves", en los tres formatos.
            'organizador genera liga'                  => [$o, 'liga', 'crear', true, '§5.2 «generar rondas o llaves»'],
            'organizador genera eliminacion'           => [$o, 'eliminacion_directa', 'crear', true, '§5.2 «generar rondas o llaves»'],
            'organizador genera suizo'                 => [$o, 'suizo', 'crear', true, '§5.2 «generar rondas o llaves»'],

            // §5.1: "gestionar participantes y equipos" es del ADMINISTRADOR.
            // §5.2 solo le da al organizador "inscribir participantes", que es
            // una acción sobre el torneo, no sobre el padrón.
            'organizador ve el padron de participantes'=> [$o, 'participantes', 'ver', true,
                '§5.2 «inscribir participantes» requiere poder verlos'],
            'organizador NO crea participantes'        => [$o, 'participantes', 'crear', false,
                '§5.1 «gestionar participantes y equipos» es del administrador'],
            'organizador NO edita participantes'       => [$o, 'participantes', 'editar', false,
                '§5.1 «gestionar participantes y equipos» es del administrador'],
            'organizador NO crea equipos'              => [$o, 'equipos', 'crear', false,
                '§5.1 «gestionar participantes y equipos» es del administrador'],

            // §5.3: el participante consulta y nada más.
            'participante consulta'                    => [$p, 'consulta_publica', 'ver', true,
                '§5.3 «consultar los torneos en los que participa»'],
            'participante ve resultados'               => [$p, 'resultados', 'ver', true,
                '§5.3 «consultar resultados»'],
            'participante NO modifica resultados'      => [$p, 'resultados', 'editar', false,
                '§5.3 «no podrá modificar resultados»'],
            'participante NO crea torneos'             => [$p, 'torneos', 'crear', false,
                '§5.3 «no podrá [...] crear torneos»'],
            'participante NO configura competencias'   => [$p, 'torneos', 'editar', false,
                '§5.3 «ni alterar configuraciones de competencia»'],
        ];
    }

    public function test_la_matriz_sembrada_coincide_con_la_letra_del_proyecto(): void
    {
        $desvios = [];
        foreach ($this->expectativasDeLaLetra() as $etiqueta => [$rol, $modulo, $accion, $esperado, $cita]) {
            $real = $this->servicio->puede($rol, $modulo, $accion);
            if ($real !== $esperado) {
                $desvios[] = sprintf('%s: rol %d %s/%s es %s y debería ser %s (%s)',
                    $etiqueta, $rol, $modulo, $accion,
                    $real ? 'SÍ' : 'no', $esperado ? 'SÍ' : 'no', $cita);
            }
        }

        $this->assertCount(0, $desvios, "\n        - " . implode("\n        - ", $desvios));
    }

    // ─── La pantalla de administración ──────────────────────────────────────

    public function test_guardar_la_matriz_persiste_lo_marcado_y_borra_lo_demas(): void
    {
        $marcados = [
            self::ROL_ORGANIZADOR => [
                'torneos'    => ['ver' => '1', 'editar' => '1'],
                'resultados' => ['ver' => '1'],
            ],
        ];

        $this->servicio->guardarMatriz($marcados);
        $servicio = new PermisoService();

        $this->assertTrue($servicio->puede(self::ROL_ORGANIZADOR, 'torneos', 'editar'));
        $this->assertFalse($servicio->puede(self::ROL_ORGANIZADOR, 'torneos', 'crear'));
        $this->assertTrue($servicio->puede(self::ROL_ORGANIZADOR, 'resultados', 'ver'));
        $this->assertFalse($servicio->puede(self::ROL_ORGANIZADOR, 'resultados', 'crear'),
            'lo que no viene marcado se niega');

        // Los módulos que no aparecen en el formulario quedan sin fila, no en cero.
        $this->assertSame(null, $this->modelo->find(self::ROL_ORGANIZADOR, 'liga'),
            'un módulo sin ninguna casilla marcada no deja fila en cero');

        // Y los roles que no vinieron en el formulario también se procesan: el
        // participante queda sin permisos porque no se marcó nada suyo.
        $this->assertFalse($servicio->puede(self::ROL_PARTICIPANTE, 'consulta_publica', 'ver'));
    }

    public function test_la_pantalla_no_puede_dejar_al_administrador_sin_acceso(): void
    {
        // Aunque el formulario venga con el rol admin sin ninguna casilla (o
        // manipulado a mano), no se le escribe ni se le borra nada.
        $this->servicio->guardarMatriz([
            self::ROL_ADMIN => ['torneos' => []],   // "quitarle todo"
        ]);

        $servicio = new PermisoService();
        $this->assertTrue($servicio->puede(self::ROL_ADMIN, 'torneos', 'eliminar'),
            'el administrador no puede quedar fuera por editar la matriz');

        $filasAdmin = (int) $this->db->query(
            "SELECT COUNT(*) FROM permisos WHERE rol_id = " . self::ROL_ADMIN
        )->fetchColumn();
        $this->assertSame(0, $filasAdmin, 'tampoco se le crean filas');
    }

    public function test_guardar_la_matriz_queda_auditado(): void
    {
        $antes = (int) $this->db->query(
            "SELECT COUNT(*) FROM auditoria WHERE accion = 'actualizar_permisos'"
        )->fetchColumn();

        Fixtures::loguearComo(Fixtures::ADMIN);
        $this->servicio->guardarMatriz([
            self::ROL_ORGANIZADOR => ['torneos' => ['ver' => '1']],
        ]);

        $despues = (int) $this->db->query(
            "SELECT COUNT(*) FROM auditoria WHERE accion = 'actualizar_permisos'"
        )->fetchColumn();
        $this->assertSame($antes + 1, $despues, 'cambiar permisos es una acción sensible: tiene que auditarse');

        $registro = $this->db->query(
            "SELECT * FROM auditoria WHERE accion = 'actualizar_permisos' ORDER BY id DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        // valor_anterior/valor_nuevo son columnas JSON: tienen que traer la
        // matriz aplanada, no una descripción suelta.
        $anterior = json_decode((string) $registro['valor_anterior'], true);
        $nuevo    = json_decode((string) $registro['valor_nuevo'], true);
        $this->assertTrue(is_array($anterior), 'valor_anterior debería ser JSON válido');
        $this->assertTrue(is_array($nuevo), 'valor_nuevo debería ser JSON válido');
        $this->assertStringContainsString('torneos', implode(' ', array_keys($nuevo)));
        $this->assertTrue(count($anterior) > count($nuevo), 'el cambio recortó permisos y debería notarse');
    }

    public function test_guardar_sin_cambiar_nada_no_ensucia_la_auditoria(): void
    {
        // Abrir la pantalla y darle a guardar sin tocar nada no es un cambio: la
        // bandeja de auditoría tiene que seguir mostrando solo lo que sí cambió.
        $marcados = [];
        foreach ($this->db->query("SELECT * FROM permisos")->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            foreach (PermisoService::ACCIONES as $accion) {
                if ((int) $fila['puede_' . $accion] === 1) {
                    $marcados[(int) $fila['rol_id']][(string) $fila['modulo_slug']][$accion] = '1';
                }
            }
        }

        $antes = (int) $this->db->query(
            "SELECT COUNT(*) FROM auditoria WHERE accion = 'actualizar_permisos'"
        )->fetchColumn();

        $this->servicio->guardarMatriz($marcados);

        $despues = (int) $this->db->query(
            "SELECT COUNT(*) FROM auditoria WHERE accion = 'actualizar_permisos'"
        )->fetchColumn();

        $this->assertSame($antes, $despues, 'guardar la misma matriz no debería dejar registro de auditoría');

        // Y la matriz tiene que haber quedado igual, no vaciada.
        $servicio = new PermisoService();
        $this->assertTrue($servicio->puede(self::ROL_ORGANIZADOR, 'torneos', 'editar'));
        $this->assertTrue($servicio->puede(self::ROL_PARTICIPANTE, 'consulta_publica', 'ver'));
    }

    // ─── Las guardas, puestas donde tienen que estar ──────────────────

    /**
     * Que el servicio decida bien no sirve de nada si ningún controlador lo
     * llama: eso era exactamente el problema original, una tabla que nadie
     * consultaba. Acá se lee el código fuente de cada acción y se comprueba que
     * la guarda esté puesta.
     *
     * No se despachan las acciones de verdad porque las guardas terminan en
     * redirect() o en el 403, y las dos llaman a exit(): matarían el proceso del
     * test. La decisión en sí ya está cubierta por los casos de arriba.
     */
    public function test_los_controladores_piden_el_permiso_donde_corresponde(): void
    {
        $esperadas = [
            // Letra §5.2, organizador
            'OrganizadorController::dashboard'            => "requirePermiso('torneos', 'ver')",
            'OrganizadorController::misTorneos'           => "requirePermiso('torneos', 'ver')",
            'OrganizadorController::gestion'              => "requirePermiso('torneos', 'ver')",
            'OrganizadorController::inscribir'            => "requirePermiso('torneos', 'editar'",
            'OrganizadorController::desinscribir'         => "requirePermiso('torneos', 'editar'",
            'OrganizadorController::siguienteRondaSuizo'  => "requirePermiso('suizo', 'crear'",
            // El de generarCompetencia depende del formato: se arma en runtime.
            'OrganizadorController::generarCompetencia'   => 'requirePermiso((string)$tipo[',
            'TorneoController::generarCompetencia'        => 'requirePermiso((string)$tipo[',
            'TorneoController::listadoAdmin'              => "requirePermiso('torneos', 'ver')",
            'TorneoController::gestion'                   => "requirePermiso('torneos', 'ver')",
            'TorneoController::formulario'                => "requirePermiso('torneos', 'editar'",
            'TorneoController::guardar'                   => "requirePermiso('torneos', 'editar'",
            'TorneoController::accionSobreRonda'          => "requirePermiso('torneos', 'editar'",
            'TorneoController::inscribir'                 => "requirePermiso('torneos', 'editar'",
            'TorneoController::desinscribir'              => "requirePermiso('torneos', 'editar'",
            'ResultadoController::cargar'                 => "requirePermiso('resultados', 'crear'",
            'ResultadoController::programar'              => "requirePermiso('resultados', 'editar'",
            'ResultadoController::corregir'               => "requirePermiso('resultados', 'editar'",
            'CorreccionController::solicitar'             => "requirePermiso('resultados', 'editar'",
            // Letra §5.3, participante
            'ParticipanteController::dashboard'           => "requirePermiso('consulta_publica', 'ver')",
            'ParticipanteController::misTorneos'          => "requirePermiso('resultados', 'ver')",
        ];

        $faltantes = [];
        foreach ($esperadas as $donde => $fragmento) {
            [$clase, $metodo] = explode('::', $donde);
            if (!str_contains($this->fuenteDelMetodo($clase, $metodo), $fragmento)) {
                $faltantes[] = "{$donde} debería llamar a {$fragmento}";
            }
        }

        $this->assertCount(0, $faltantes, "
        - " . implode("
        - ", $faltantes));
    }

    /** Código fuente de un método, para inspeccionar las guardas sin ejecutarlas. */
    private function fuenteDelMetodo(string $clase, string $metodo): string
    {
        $r = new ReflectionMethod($clase, $metodo);
        $lineas = file((string) $r->getFileName());
        if ($lineas === false) return '';

        return implode('', array_slice(
            $lineas,
            $r->getStartLine() - 1,
            $r->getEndLine() - $r->getStartLine() + 1
        ));
    }

    // ─── Composición con las otras dos compuertas ───────────────────────────

    public function test_el_permiso_no_reemplaza_al_estado_del_modulo(): void
    {
        // Son compuertas independientes: `permisos` dice qué puede el rol,
        // `modulos.estado` dice si el módulo está habilitado para todo el
        // sistema. Tener el permiso no revive un módulo apagado.
        $moduloModel = new ModuloModel();
        $liga        = $moduloModel->findBySlug('liga');
        $this->assertNotNull($liga);

        $moduloModel->update((int) $liga['id'], ['estado' => 'inactivo']);
        try {
            $this->assertTrue($this->servicio->puede(self::ROL_ORGANIZADOR, 'liga', 'crear'),
                'el permiso del rol sigue siendo el mismo');
            $this->assertFalse($moduloModel->estaActivo('liga'),
                'pero el módulo está apagado y la otra compuerta lo frena');
        } finally {
            $moduloModel->update((int) $liga['id'], ['estado' => 'activo']);
        }
    }
}

exit(TestCase::ejecutar(PermisosTest::class));
