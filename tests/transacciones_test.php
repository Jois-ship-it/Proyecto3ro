<?php
declare(strict_types=1);

/**
 * Test de transacciones: las operaciones que escriben varias filas son «todo o nada».
 *
 * Qué se prueba. Que si una operación se corta a la mitad —el servidor se cae, se llena el
 * disco, falla una restricción— no queda nada a medias: ni un fixture con rondas sin partidos,
 * ni una cuenta sin su perfil, ni un torneo sin organizador, ni una matriz de permisos
 * cambiada hasta la mitad (un control de acceso inconsistente).
 *
 * Cómo se provoca el corte. Se instala un trigger BEFORE INSERT / BEFORE UPDATE que lanza un
 * error cuando el servicio llega a cierto punto (por ejemplo, al crear la ronda 2). Es la
 * forma de reproducir «falló justo a la mitad» sin tocar el código bajo prueba. Los tests
 * corren como root, que es quien puede crear triggers; los de este archivo llevan el prefijo
 * `trg_test_falla_` y se borran solos antes y después de cada caso.
 *
 * Qué se mira después del corte. El estado completo: lo que debía deshacerse (rondas,
 * partidos, filas del historial de la base) y lo que debía conservarse (la tabla anterior, el
 * resultado original, el organizador). Y que se pueda reintentar: reparada la falla, la misma
 * operación termina bien.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/transacciones_test.php
 */

require __DIR__ . '/bootstrap.php';

class TransaccionesTest extends TestCase
{
    private const PREFIJO = 'trg_test_falla_';

    /** @var string[] triggers de falla instalados por el caso en curso */
    private array $instalados = [];

    /** Contenido de `permisos` al empezar el caso, para devolverlo si un caso lo ensucia. */
    private array $permisosIniciales = [];

    protected function setUp(): void
    {
        $this->repararFallas();          // por si un caso anterior se cortó con un trigger puesto
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);
        $this->permisosIniciales = $this->volcado('permisos');
    }

    protected function tearDown(): void
    {
        $this->repararFallas();
        if ($this->volcado('permisos') !== $this->permisosIniciales) {
            $this->db->exec('DELETE FROM permisos');
            foreach ($this->permisosIniciales as $fila) {
                $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($fila)));
                $this->db->prepare("INSERT INTO permisos ($cols) VALUES (" . implode(', ', array_fill(0, count($fila), '?')) . ')')
                         ->execute(array_values($fila));
            }
        }
        $this->db->exec('DELETE FROM historial_bd');
        Fixtures::cerrarSesion();
    }

    // ─── Apoyo ──────────────────────────────────────────────────────────────

    /**
     * Hace que el motor rechace el INSERT (o UPDATE) sobre $tabla cuando $cuando —una condición
     * sobre la fila nueva, NEW— es verdadera. Va como root y fuera de toda transacción: crear un
     * trigger confirma lo pendiente.
     */
    private function fallarAl(string $operacion, string $tabla, string $cuando = '1 = 1'): void
    {
        $nombre = self::PREFIJO . count($this->instalados);
        $this->db->exec("DROP TRIGGER IF EXISTS `$nombre`");
        $this->db->exec(
            "CREATE TRIGGER `$nombre` BEFORE $operacion ON `$tabla` FOR EACH ROW
             IF $cuando THEN
                 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Falla inducida por el test';
             END IF"
        );
        $this->instalados[] = $nombre;
    }

    /** Quita todas las fallas inducidas (las de este caso y las que haya dejado uno cortado). */
    private function repararFallas(): void
    {
        $triggers = $this->db->query(
            "SELECT trigger_name FROM information_schema.triggers
              WHERE trigger_schema = DATABASE() AND trigger_name LIKE 'trg\\_test\\_falla\\_%'"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($triggers as $t) {
            $this->db->exec("DROP TRIGGER IF EXISTS `$t`");
        }
        $this->instalados = [];
    }

    private function contar(string $tabla, string $donde = '1 = 1', array $params = []): int
    {
        $st = $this->db->prepare("SELECT COUNT(*) FROM `$tabla` WHERE $donde");
        $st->execute($params);
        return (int) $st->fetchColumn();
    }

    private function valor(string $sql, array $params = []): mixed
    {
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    /** @return array<int, array<string, mixed>> todas las filas de la tabla, ordenadas por id */
    private function volcado(string $tabla): array
    {
        return $this->db->query("SELECT * FROM `$tabla` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** La tabla de posiciones de un torneo, en una forma que se pueda comparar. */
    private function tabla(int $torneoId): array
    {
        $st = $this->db->prepare(
            'SELECT participante_id, equipo_id, pj, pg, pe, pp, pf, pc, puntos, posicion
               FROM tabla_posiciones WHERE torneo_id = ? ORDER BY posicion, participante_id, equipo_id'
        );
        $st->execute([$torneoId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Otra conexión a la misma base: ve solo lo que ya se confirmó (COMMIT). */
    private function otraConexion(): PDO
    {
        return new PDO(
            "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
            $_ENV['DB_USER'], $_ENV['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function equiposVistosDesdeAfuera(string $nombre): int
    {
        $st = $this->otraConexion()->prepare('SELECT COUNT(*) FROM equipos WHERE nombre = ?');
        $st->execute([$nombre]);
        return (int) $st->fetchColumn();
    }

    /** Datos válidos de un auto-registro. */
    private function datosRegistro(array $cambios = []): array
    {
        return array_merge([
            'nombre'           => 'Persona Nueva',
            'email'            => 'nueva.tx@test.local',
            'nick'             => 'nuevatx',
            'telefono'         => '099123456',
            'documento'        => '5123456',
            'password'         => 'Clave-Segura-123!',
            'password_confirm' => 'Clave-Segura-123!',
            'acepto_politicas' => '1',
        ], $cambios);
    }

    // ─── Database::transaccion() ────────────────────────────────────────────

    public function test_transaccion_confirma_lo_que_hizo_y_devuelve_el_resultado(): void
    {
        $devuelto = Database::transaccion(function () {
            $this->db->exec("INSERT INTO equipos (nombre) VALUES ('TX confirmado')");
            return 'listo';
        });

        $this->assertSame('listo', $devuelto, 'devuelve lo que devuelve la función');
        $this->assertFalse($this->db->inTransaction(), 'no deja una transacción abierta');
        $this->assertSame(1, $this->equiposVistosDesdeAfuera('TX confirmado'),
            'otra conexión lo ve: se hizo COMMIT de verdad');
    }

    public function test_transaccion_deshace_todo_y_deja_pasar_el_error(): void
    {
        $e = $this->assertThrows(function () {
            Database::transaccion(function () {
                $this->db->exec("INSERT INTO equipos (nombre) VALUES ('TX uno')");
                $this->db->exec("INSERT INTO equipos (nombre) VALUES ('TX dos')");
                throw new RuntimeException('se cortó a la mitad');
            });
        }, 'se cortó a la mitad');

        $this->assertSame(RuntimeException::class, get_class($e), 'la excepción llega tal cual, para que el controlador la muestre');
        $this->assertFalse($this->db->inTransaction(), 'no deja una transacción abierta');
        $this->assertSame(0, $this->contar('equipos', "nombre LIKE 'TX %'"), 'no queda ni la mitad');
    }

    public function test_transaccion_anidada_se_une_a_la_de_afuera(): void
    {
        // Lo que escribe la interna no se confirma solo: si la de afuera falla después,
        // se va con ella. Es lo que pasa cuando cargar() llama a recalcular().
        $this->assertThrows(function () {
            Database::transaccion(function () {
                $this->db->exec("INSERT INTO equipos (nombre) VALUES ('TX externa')");
                Database::transaccion(function () {
                    $this->db->exec("INSERT INTO equipos (nombre) VALUES ('TX interna')");
                });
                $this->assertTrue($this->db->inTransaction(), 'la interna no cerró la transacción de afuera');
                $this->assertSame(0, $this->equiposVistosDesdeAfuera('TX interna'), 'y tampoco la confirmó');
                throw new RuntimeException('falla después de la interna');
            });
        }, 'falla después de la interna');

        $this->assertSame(0, $this->contar('equipos', "nombre LIKE 'TX %'"), 'no queda ni lo de adentro ni lo de afuera');
    }

    public function test_el_error_de_una_interna_deshace_tambien_lo_de_la_externa(): void
    {
        $this->assertThrows(function () {
            Database::transaccion(function () {
                $this->db->exec("INSERT INTO equipos (nombre) VALUES ('TX externa')");
                Database::transaccion(function () {
                    $this->db->exec("INSERT INTO equipos (nombre) VALUES ('TX interna')");
                    throw new RuntimeException('falla la interna');
                });
            });
        }, 'falla la interna');

        $this->assertSame(0, $this->contar('equipos', "nombre LIKE 'TX %'"));
        $this->assertFalse($this->db->inTransaction());
    }

    public function test_se_une_a_una_transaccion_abierta_por_quien_llama_y_no_la_cierra(): void
    {
        $this->db->beginTransaction();
        Database::transaccion(fn() => $this->db->exec("INSERT INTO equipos (nombre) VALUES ('TX prestado')"));

        $this->assertTrue($this->db->inTransaction(), 'la transacción de quien llamó sigue abierta');
        $this->assertSame(0, $this->equiposVistosDesdeAfuera('TX prestado'), 'y nada se confirmó por su cuenta');

        $this->db->rollBack();
        $this->assertSame(0, $this->contar('equipos', "nombre = 'TX prestado'"), 'quien la abrió la deshizo, y se fue todo');
    }

    public function test_despues_de_un_error_la_siguiente_transaccion_empieza_limpia(): void
    {
        try {
            Database::transaccion(function () { throw new RuntimeException('primera falla'); });
        } catch (RuntimeException) {
            // esperado
        }

        // Si el contador de anidamiento hubiera quedado «sucio», esta se uniría a una
        // transacción que no existe y no confirmaría nada.
        Database::transaccion(function () {
            $this->assertTrue($this->db->inTransaction(), 'abre una transacción propia');
            $this->db->exec("INSERT INTO equipos (nombre) VALUES ('TX despues')");
        });

        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(1, $this->equiposVistosDesdeAfuera('TX despues'), 'y la confirma');
    }

    // ─── Cuentas ────────────────────────────────────────────────────────────

    public function test_registrar_no_deja_una_cuenta_sin_perfil(): void
    {
        $this->fallarAl('INSERT', 'participantes');

        $this->assertThrows(fn() => (new RegistroService())->registrar($this->datosRegistro()), 'Falla inducida');

        $this->assertSame(0, $this->contar('usuarios', 'email = ?', ['nueva.tx@test.local']),
            'la cuenta no queda sola (antes: quedaba, y el email se volvía «ya registrado»)');
        $this->assertSame(0, $this->contar('participantes', 'email = ?', ['nueva.tx@test.local']));
        $this->assertSame(0, $this->contar('auditoria', "accion = 'registro_participante'"));

        $this->repararFallas();
        $this->assertGreaterThan(0, (new RegistroService())->registrar($this->datosRegistro()),
            'y la persona puede volver a intentarlo con el mismo email');
    }

    public function test_un_nick_demasiado_largo_no_deja_una_cuenta_sin_perfil(): void
    {
        // El caso real, sin trigger: el nick mide 80 en la base y nada lo recortaba antes de
        // insertar. El perfil fallaba DESPUÉS de crear la cuenta. Que el servicio llegue a
        // validarlo antes o que lo rechace la base da igual: lo que importa es el estado final.
        $this->assertThrows(fn() => (new RegistroService())->registrar(
            $this->datosRegistro(['nick' => str_repeat('n', 200)])
        ));

        $this->assertSame(0, $this->contar('usuarios', 'email = ?', ['nueva.tx@test.local']));
        $this->assertSame(0, $this->contar('participantes', 'email = ?', ['nueva.tx@test.local']));
    }

    public function test_aprobar_y_rechazar_un_registro_cambian_cuenta_y_perfil_juntos(): void
    {
        $svc = new RegistroService();
        $aprobado  = $svc->registrar($this->datosRegistro());
        $rechazado = $svc->registrar($this->datosRegistro([
            'email' => 'otra.tx@test.local', 'documento' => '5999888', 'nick' => 'otratx',
        ]));

        $this->fallarAl('UPDATE', 'participantes', "NEW.estado IN ('activo', 'rechazado')");

        $this->assertThrows(fn() => $svc->aprobar($aprobado, Fixtures::ADMIN), 'Falla inducida');
        $this->assertThrows(fn() => $svc->rechazar($rechazado, Fixtures::ADMIN), 'Falla inducida');

        foreach ([$aprobado, $rechazado] as $uid) {
            $this->assertSame('pendiente', $this->valor('SELECT estado FROM usuarios WHERE id = ?', [$uid]),
                'la cuenta sigue pendiente (antes: ya figuraba resuelta mientras el perfil seguía pendiente)');
            $this->assertSame('pendiente', $this->valor('SELECT estado FROM participantes WHERE usuario_id = ?', [$uid]));
        }
        $this->assertSame(0, $this->contar('auditoria', "accion IN ('aprobar_registro', 'rechazar_registro')"));

        $this->repararFallas();
        $svc->aprobar($aprobado, Fixtures::ADMIN);
        $this->assertSame('activo', $this->valor('SELECT estado FROM usuarios WHERE id = ?', [$aprobado]));
    }

    public function test_editar_el_perfil_no_deja_la_cuenta_y_el_perfil_desincronizados(): void
    {
        $uid = Fixtures::usuario('Persona Perfil', 'perfil.tx@test.local', 'Clave-Segura-9876!', 3);
        $pid = (new ParticipanteModel())->insert([
            'usuario_id' => $uid, 'estado' => 'activo', 'nombre' => 'Persona Perfil',
            'email' => 'perfil.tx@test.local', 'nick' => 'perfil_tx',
        ]);
        $cambios = ['nombre' => 'Nombre Nuevo', 'email' => 'otro.perfil.tx@test.local', 'nick' => 'nuevo_nick', 'telefono' => ''];

        // La cuenta se sincroniza primero y el perfil después: si el perfil falla, la cuenta no puede
        // haberse quedado con el nombre y el email nuevos (el perfil y el login mostrarían datos distintos).
        $this->fallarAl('UPDATE', 'participantes');
        $this->assertThrows(fn() => (new ParticipanteService())->actualizarPerfil($pid, $cambios), 'Falla inducida');
        $this->assertThrows(fn() => (new ParticipanteService())->editar($pid, $cambios + ['estado' => 'activo']), 'Falla inducida');

        $this->assertSame(['nombre' => 'Persona Perfil', 'email' => 'perfil.tx@test.local'],
            $this->db->query("SELECT nombre, email FROM usuarios WHERE id = $uid")->fetch(PDO::FETCH_ASSOC),
            'la cuenta conserva su nombre y su email');
        $this->assertSame('Persona Perfil', $this->valor('SELECT nombre FROM participantes WHERE id = ?', [$pid]));
        $this->assertSame(0, $this->contar('auditoria', "accion IN ('editar_perfil', 'editar_participante')"));

        $this->repararFallas();
        (new ParticipanteService())->actualizarPerfil($pid, $cambios);
        $this->assertSame('Nombre Nuevo', $this->valor('SELECT nombre FROM usuarios WHERE id = ?', [$uid]),
            'reparada la falla, los dos se actualizan');
        $this->assertSame('Nombre Nuevo', $this->valor('SELECT nombre FROM participantes WHERE id = ?', [$pid]));
    }

    public function test_bloquear_y_desbloquear_una_cuenta_cambia_cuenta_y_perfil_juntos(): void
    {
        $uid = Fixtures::usuario('Persona Bloqueable', 'bloqueo.tx@test.local', 'Clave-Segura-9876!', 3);
        $pid = (new ParticipanteModel())->insert([
            'usuario_id' => $uid, 'estado' => 'activo', 'nombre' => 'Persona Bloqueable', 'email' => 'bloqueo.tx@test.local',
        ]);
        $svc = new UsuarioService();
        $estados = fn() => [
            $this->valor('SELECT estado FROM usuarios WHERE id = ?', [$uid]),
            $this->valor('SELECT estado FROM participantes WHERE id = ?', [$pid]),
        ];

        // Bloquear = marcar la cuenta + suspender el perfil. Si lo segundo falla, la cuenta no queda
        // bloqueada con el perfil todavía activo (el administrador vería dos estados que no coinciden).
        $this->fallarAl('UPDATE', 'participantes', "NEW.estado = 'suspendido'");
        $this->assertThrows(fn() => $svc->bloquear($uid), 'Falla inducida');
        $this->assertSame(['activo', 'activo'], $estados());
        $this->assertSame(0, $this->contar('auditoria', "accion = 'bloquear_cuenta'"));

        $this->repararFallas();
        $svc->bloquear($uid);
        $this->assertSame(['bloqueada', 'suspendido'], $estados(), 'reparada la falla, se bloquean los dos');

        // Desbloquear: lo mismo en sentido contrario, y el contador de intentos no se reinicia a medias.
        $this->db->exec("UPDATE usuarios SET failed_attempts = 5 WHERE id = $uid");
        $this->fallarAl('UPDATE', 'participantes', "NEW.estado = 'activo'");
        $this->assertThrows(fn() => $svc->desbloquear($uid), 'Falla inducida');
        $this->assertSame(['bloqueada', 'suspendido'], $estados(), 'la cuenta sigue bloqueada');
        $this->assertSame(5, (int) $this->valor('SELECT failed_attempts FROM usuarios WHERE id = ?', [$uid]));

        $this->repararFallas();
        $svc->desbloquear($uid);
        $this->assertSame(['activo', 'activo'], $estados(), 'reparada la falla, se desbloquean los dos');
        $this->assertSame(0, (int) $this->valor('SELECT failed_attempts FROM usuarios WHERE id = ?', [$uid]));
    }

    public function test_anonimizar_es_todo_o_nada_y_la_foto_se_borra_al_final(): void
    {
        // Upload ubica la carpeta de fotos con BASE_PATH.
        if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));

        $uid = Fixtures::usuario('Persona Transaccional', 'persona.tx@test.local', 'Clave-Segura-9876!', 3);
        $foto = 'zz_test_tx_' . bin2hex(random_bytes(4)) . '.png';
        $ruta = BASE_PATH . '/public/assets/uploads/avatars/' . $foto;
        file_put_contents($ruta, 'x');

        try {
            $pid = (new ParticipanteModel())->insert([
                'usuario_id' => $uid, 'estado' => 'activo', 'nombre' => 'Persona Transaccional',
                'documento' => '4999111', 'nick' => 'persona_tx', 'email' => 'persona.tx@test.local',
                'telefono' => '099111222', 'foto' => $foto,
            ]);

            // Falla al anonimizar la CUENTA, o sea después de haber anonimizado el perfil.
            $this->fallarAl('UPDATE', 'usuarios');
            $this->assertThrows(fn() => (new ParticipanteService())->anonimizarDatos($pid, $uid), 'Falla inducida');

            $p = (new ParticipanteModel())->findById($pid);
            $this->assertSame('Persona Transaccional', $p['nombre'], 'el perfil no quedó anonimizado a medias');
            $this->assertSame('4999111', $p['documento']);
            $this->assertSame($foto, $p['foto']);
            $this->assertSame($uid, (int) $p['usuario_id'], 'sigue vinculado a su cuenta');
            $this->assertSame('persona.tx@test.local', $this->valor('SELECT email FROM usuarios WHERE id = ?', [$uid]));
            $this->assertTrue(is_file($ruta), 'y la foto sigue ahí: borrar un archivo no se puede deshacer');

            $this->repararFallas();
            (new ParticipanteService())->anonimizarDatos($pid, $uid);

            $this->assertSame('Participante eliminado', (new ParticipanteModel())->findById($pid)['nombre']);
            $this->assertFalse(is_file($ruta), 'con la base ya en orden, ahora sí se borra la foto');
        } finally {
            @unlink($ruta);
            $this->db->exec('DELETE FROM usuarios WHERE id = ' . (int) $uid);   // el email anonimizado ya no cae en el reset
        }
    }

    // ─── Torneos ────────────────────────────────────────────────────────────

    public function test_crear_un_torneo_no_deja_un_torneo_sin_organizador(): void
    {
        $this->fallarAl('INSERT', 'torneo_organizadores');

        $this->assertThrows(fn() => Fixtures::torneo('liga'), 'Falla inducida');

        $this->assertSame(0, $this->contar('torneos'), 'el torneo no queda huérfano de organizador');
        $this->assertSame(0, $this->contar('auditoria', "accion = 'crear_torneo'"));
    }

    public function test_editar_un_torneo_no_lo_deja_sin_organizador(): void
    {
        $tid  = Fixtures::torneo('liga');
        $tipo = (new TipoTorneoModel())->findBySlug('liga');

        // Cambiar de organizador es «borrar el anterior, poner el nuevo». Si lo segundo falla
        // y lo primero ya se hizo, el torneo queda sin nadie a cargo.
        $this->fallarAl('INSERT', 'torneo_organizadores');
        $this->assertThrows(fn() => (new TorneoService())->editar($tid, [
            'nombre'         => 'Nombre nuevo',
            'tipo_torneo_id' => (int) $tipo['id'],
            'modalidad'      => 'individual',
            'estado'         => 'inscripcion',
            'fecha_inicio'   => date('Y-m-d'),
            'fecha_fin'      => date('Y-m-d', strtotime('+30 days')),
            'organizador_id' => Fixtures::ORGANIZADOR2,
        ]), 'Falla inducida');

        $this->assertSame('Torneo de prueba (liga)', $this->valor('SELECT nombre FROM torneos WHERE id = ?', [$tid]),
            'el nombre nuevo tampoco se guardó');
        $this->assertSame([Fixtures::ORGANIZADOR],
            array_map('intval', $this->db->query("SELECT usuario_id FROM torneo_organizadores WHERE torneo_id = $tid")->fetchAll(PDO::FETCH_COLUMN)),
            'el organizador de antes sigue siéndolo');
        $this->assertSame(0, $this->contar('auditoria', "accion = 'editar_torneo'"));
    }

    public function test_cambiar_el_organizador_no_deja_al_torneo_sin_organizador(): void
    {
        $tid = Fixtures::torneo('liga');
        $auditoriaAntes = $this->contar('auditoria', "accion = 'asignar_organizador'");

        // Cambiar de organizador es «borrar el anterior, poner el nuevo»; llamado solo (no desde
        // editar()), si lo segundo falla el torneo se quedaría sin nadie a cargo.
        $this->fallarAl('INSERT', 'torneo_organizadores');
        $this->assertThrows(fn() => (new TorneoService())->setOrganizador($tid, Fixtures::ORGANIZADOR2), 'Falla inducida');

        $this->assertSame([Fixtures::ORGANIZADOR],
            array_map('intval', $this->db->query("SELECT usuario_id FROM torneo_organizadores WHERE torneo_id = $tid")->fetchAll(PDO::FETCH_COLUMN)),
            'el organizador de antes sigue siéndolo');
        $this->assertSame($auditoriaAntes, $this->contar('auditoria', "accion = 'asignar_organizador'"));

        $this->repararFallas();
        (new TorneoService())->setOrganizador($tid, Fixtures::ORGANIZADOR2);
        $this->assertSame([Fixtures::ORGANIZADOR2],
            array_map('intval', $this->db->query("SELECT usuario_id FROM torneo_organizadores WHERE torneo_id = $tid")->fetchAll(PDO::FETCH_COLUMN)),
            'reparada la falla, el cambio se hace');
    }

    public function test_guardar_los_datos_del_evento_es_todo_o_nada(): void
    {
        $tid = Fixtures::torneo('liga');
        $svc = new ConfiguracionTorneoService();
        $svc->guardar($tid, ['sede' => 'Sede vieja']);
        $antes = $svc->getPorTorneo($tid);

        // Las claves se guardan en el orden del catálogo: «observaciones» es la última.
        $this->fallarAl('INSERT', 'configuraciones_torneo', "NEW.clave = 'observaciones'");
        $this->assertThrows(fn() => $svc->guardar($tid, [
            'sede'          => 'Sede nueva',
            'contacto'      => 'info@test.local',
            'observaciones' => 'Traer documento',
        ]), 'Falla inducida');

        $this->assertSame($antes, $svc->getPorTorneo($tid),
            'no se guardó la mitad del formulario: ni la sede nueva ni el contacto');
    }

    // ─── Generadores de estructura ──────────────────────────────────────────

    /** Lo que un generador que se corta NO debe dejar atrás. */
    private function assertSinEstructura(int $torneoId, string $donde): void
    {
        $this->assertSame(0, $this->contar('rondas', 'torneo_id = ?', [$torneoId]), "rondas ($donde)");
        $this->assertSame(0, $this->contar('enfrentamientos', 'torneo_id = ?', [$torneoId]), "partidos ($donde)");
        $this->assertSame(0, $this->contar('resultados'), "resultados de byes ($donde)");
        $this->assertSame(0, $this->contar('tabla_posiciones', 'torneo_id = ?', [$torneoId]), "tabla ($donde)");
        $this->assertSame('inscripcion', $this->valor('SELECT estado FROM torneos WHERE id = ?', [$torneoId]),
            "el torneo no arrancó ($donde)");
        $this->assertSame(0, $this->contar('auditoria', "accion IN ('generar_fixture', 'generar_bracket', 'generar_ronda_suiza', 'asignar_bye')"),
            "sin auditoría de algo que no pasó ($donde)");
        $this->assertSame(0, $this->contar('historial_bd', "tabla IN ('rondas', 'enfrentamientos', 'resultados', 'tabla_posiciones')"),
            "el historial de la base tampoco registra lo que se deshizo ($donde)");
    }

    public function test_generar_el_fixture_de_liga_es_todo_o_nada(): void
    {
        $tid = Fixtures::torneo('liga');
        Fixtures::inscribir($tid, Fixtures::participantes(4));

        foreach ([
            'al crear la fecha 2'       => ['INSERT', 'rondas', 'NEW.numero = 2'],
            'al crear el 2.º partido'   => ['INSERT', 'enfrentamientos', 'NEW.orden = 2'],
            'al armar la tabla'         => ['INSERT', 'tabla_posiciones', '1 = 1'],
            'al auditar, lo último'     => ['INSERT', 'auditoria', "NEW.accion = 'generar_fixture'"],
        ] as $donde => [$operacion, $tabla, $cuando]) {
            $this->fallarAl($operacion, $tabla, $cuando);
            $this->assertThrows(fn() => (new LigaService())->generarFixture($tid), 'Falla inducida');
            $this->assertSinEstructura($tid, $donde);
            $this->repararFallas();
        }

        (new LigaService())->generarFixture($tid);
        $this->assertSame(3, $this->contar('rondas', 'torneo_id = ?', [$tid]), 'reparada la falla, se puede reintentar');
        $this->assertSame('en_curso', $this->valor('SELECT estado FROM torneos WHERE id = ?', [$tid]));
    }

    public function test_generar_el_bracket_es_todo_o_nada(): void
    {
        // 5 inscritos: el cuadro es de 8, hay 3 byes. Sus ganadores avanzan en una SEGUNDA pasada,
        // cuando la ronda 1, los byes y el estado del torneo ya están escritos.
        $tid = Fixtures::torneo('eliminacion_directa');
        Fixtures::inscribir($tid, Fixtures::participantes(5));

        foreach ([
            'al registrar el primer bye'      => ['INSERT', 'resultados', '1 = 1'],
            'al crear la ronda 2 (avance)'    => ['INSERT', 'rondas', 'NEW.numero = 2'],
            'al auditar, lo último'           => ['INSERT', 'auditoria', "NEW.accion = 'generar_bracket'"],
        ] as $donde => [$operacion, $tabla, $cuando]) {
            $this->fallarAl($operacion, $tabla, $cuando);
            $this->assertThrows(fn() => (new EliminacionDirectaService())->generarBracket($tid), 'Falla inducida');
            $this->assertSinEstructura($tid, $donde);
            $this->repararFallas();
        }

        (new EliminacionDirectaService())->generarBracket($tid);
        $this->assertSame('en_curso', $this->valor('SELECT estado FROM torneos WHERE id = ?', [$tid]),
            'reparada la falla, se puede reintentar');
    }

    public function test_generar_la_primera_ronda_suiza_es_todo_o_nada(): void
    {
        // 5 inscritos: uno recibe bye en la ronda 1.
        $tid = Fixtures::torneo('suizo');
        Fixtures::inscribir($tid, Fixtures::participantes(5));

        foreach ([
            'al crear el 2.º partido'  => ['INSERT', 'enfrentamientos', 'NEW.orden = 2'],
            'al armar la tabla'        => ['INSERT', 'tabla_posiciones', '1 = 1'],
            'al auditar, lo último'    => ['INSERT', 'auditoria', "NEW.accion = 'generar_ronda_suiza'"],
        ] as $donde => [$operacion, $tabla, $cuando]) {
            $this->fallarAl($operacion, $tabla, $cuando);
            $this->assertThrows(fn() => (new SistemaSuizoService())->generarPrimeraRonda($tid), 'Falla inducida');
            $this->assertSinEstructura($tid, $donde);
            $this->repararFallas();
        }

        (new SistemaSuizoService())->generarPrimeraRonda($tid);
        $this->assertSame(1, $this->contar('rondas', 'torneo_id = ?', [$tid]), 'reparada la falla, se puede reintentar');
    }

    // ─── Resultados y tabla ─────────────────────────────────────────────────

    public function test_cargar_un_resultado_es_todo_o_nada(): void
    {
        [$tid] = Fixtures::torneoArrancado('liga', 4);
        $eid    = Fixtures::partidosPendientes($tid)[0];
        $estado = $this->valor('SELECT estado FROM enfrentamientos WHERE id = ?', [$eid]);
        $tabla  = $this->tabla($tid);

        // recalcular() borra la tabla y la vuelve a escribir: si se corta en el medio, la
        // transacción tiene que devolver la tabla vieja, no dejar una a medio escribir.
        $this->fallarAl('INSERT', 'tabla_posiciones');
        $this->assertThrows(fn() => (new ResultadoService())->cargar($eid, 2.0, 1.0, Fixtures::ADMIN), 'Falla inducida');

        $this->assertSame(0, $this->contar('resultados', 'enfrentamiento_id = ?', [$eid]), 'el resultado no quedó cargado');
        $this->assertSame($estado, $this->valor('SELECT estado FROM enfrentamientos WHERE id = ?', [$eid]),
            'el partido sigue como estaba');
        $this->assertSame($tabla, $this->tabla($tid), 'y la tabla es la de antes, entera');
        $this->assertSame(0, $this->contar('auditoria', "accion = 'cargar_resultado'"));

        $this->repararFallas();
        (new ResultadoService())->cargar($eid, 2.0, 1.0, Fixtures::ADMIN);
        $this->assertSame('finalizado', $this->valor('SELECT estado FROM enfrentamientos WHERE id = ?', [$eid]),
            'reparada la falla, se puede volver a cargar');
    }

    public function test_el_resultado_y_su_auditoria_se_guardan_juntos(): void
    {
        [$tid] = Fixtures::torneoArrancado('liga', 4);
        $eid = Fixtures::partidosPendientes($tid)[0];

        // Antes la auditoría se escribía DESPUÉS de confirmar: si fallaba, el resultado quedaba
        // guardado sin rastro (y el usuario veía un error de algo que sí se había hecho).
        $this->fallarAl('INSERT', 'auditoria');
        $this->assertThrows(fn() => (new ResultadoService())->cargar($eid, 3.0, 0.0, Fixtures::ADMIN), 'Falla inducida');

        $this->assertSame(0, $this->contar('resultados', 'enfrentamiento_id = ?', [$eid]));
        $this->assertNotSame('finalizado', $this->valor('SELECT estado FROM enfrentamientos WHERE id = ?', [$eid]));
    }

    public function test_cargar_en_eliminacion_directa_no_deja_la_ronda_siguiente_a_medias(): void
    {
        // Cargar una semifinal crea la ronda siguiente y ubica ahí al ganador, todo dentro de la
        // misma operación. Si se corta después de crear la ronda, la ronda no puede quedar.
        [$tid] = Fixtures::torneoArrancado('eliminacion_directa', 4);
        $eid = Fixtures::partidosPendientes($tid)[0];

        foreach ([
            'al crear la ronda 2'          => ['INSERT', 'rondas', 'NEW.numero = 2'],
            'al ubicar al ganador en ella' => ['INSERT', 'enfrentamientos', 'NEW.participante_a_id IS NOT NULL AND NEW.participante_b_id IS NULL AND NEW.es_bye = 0'],
        ] as $donde => [$operacion, $tabla, $cuando]) {
            $this->fallarAl($operacion, $tabla, $cuando);
            $this->assertThrows(fn() => (new ResultadoService())->cargar($eid, 2.0, 1.0, Fixtures::ADMIN), 'Falla inducida');

            $this->assertSame(0, $this->contar('resultados', 'enfrentamiento_id = ?', [$eid]), "el resultado no quedó ($donde)");
            $this->assertNotSame('finalizado', $this->valor('SELECT estado FROM enfrentamientos WHERE id = ?', [$eid]), "el partido sigue abierto ($donde)");
            $this->assertSame(1, $this->contar('rondas', 'torneo_id = ?', [$tid]), "no quedó una ronda 2 vacía ($donde)");
            $this->assertSame(2, $this->contar('enfrentamientos', 'torneo_id = ?', [$tid]), "ni un cruce de la ronda 2 ($donde)");
            $this->repararFallas();
        }

        (new ResultadoService())->cargar($eid, 2.0, 1.0, Fixtures::ADMIN);
        $this->assertSame(2, $this->contar('rondas', 'torneo_id = ?', [$tid]), 'reparada la falla, la ronda 2 se crea');
    }

    public function test_cargar_el_ultimo_partido_de_una_liga_no_la_deja_a_medio_finalizar(): void
    {
        // Con 2 inscritos hay un solo partido: cargarlo proclama al campeón y cierra el torneo.
        [$tid] = Fixtures::torneoArrancado('liga', 2);
        $eid   = Fixtures::partidosPendientes($tid)[0];
        $tabla = $this->tabla($tid);

        $this->fallarAl('UPDATE', 'torneos', "NEW.estado = 'finalizado'");
        $this->assertThrows(fn() => (new ResultadoService())->cargar($eid, 2.0, 1.0, Fixtures::ADMIN), 'Falla inducida');

        $this->assertSame(0, $this->contar('resultados'), 'no hay un campeón sin resultado que lo justifique');
        $this->assertSame('en_curso', $this->valor('SELECT estado FROM torneos WHERE id = ?', [$tid]));
        $this->assertSame(null, $this->valor('SELECT campeon_participante_id FROM torneos WHERE id = ?', [$tid]) ?: null);
        $this->assertSame($tabla, $this->tabla($tid));

        $this->repararFallas();
        (new ResultadoService())->cargar($eid, 2.0, 1.0, Fixtures::ADMIN);
        $this->assertSame('finalizado', $this->valor('SELECT estado FROM torneos WHERE id = ?', [$tid]),
            'reparada la falla, el torneo se cierra');
    }

    public function test_generar_la_siguiente_ronda_suiza_es_todo_o_nada(): void
    {
        // La ronda 2 la pide el organizador cuando termina la 1. Se juega la ronda 1 entera.
        [$tid] = Fixtures::torneoArrancado('suizo', 4);
        Fixtures::jugarTodo($tid);
        $this->assertSame(1, $this->contar('rondas', 'torneo_id = ?', [$tid]), 'control: solo existe la ronda 1');
        $tabla = $this->tabla($tid);

        foreach ([
            'al crear el 2.º cruce' => ['INSERT', 'enfrentamientos', 'NEW.orden = 2'],
            'al auditar, lo último' => ['INSERT', 'auditoria', "NEW.accion = 'generar_ronda_suiza'"],
        ] as $donde => [$operacion, $tabla_, $cuando]) {
            $this->fallarAl($operacion, $tabla_, $cuando);
            $this->assertThrows(fn() => (new SistemaSuizoService())->generarSiguienteRonda($tid), 'Falla inducida');

            $this->assertSame(1, $this->contar('rondas', 'torneo_id = ?', [$tid]), "no hay una ronda 2 a medio emparejar ($donde)");
            $this->assertSame(2, $this->contar('enfrentamientos', 'torneo_id = ?', [$tid]), "ni cruces sueltos ($donde)");
            $this->assertSame($tabla, $this->tabla($tid), "la tabla no se tocó ($donde)");
            $this->repararFallas();
        }

        (new SistemaSuizoService())->generarSiguienteRonda($tid);
        $this->assertSame(2, $this->contar('rondas', 'torneo_id = ?', [$tid]), 'reparada la falla, la ronda 2 se genera');
    }

    public function test_corregir_un_resultado_es_todo_o_nada(): void
    {
        [$tid] = Fixtures::torneoArrancado('liga', 4);
        $eid = Fixtures::partidosPendientes($tid)[0];
        (new ResultadoService())->cargar($eid, 2.0, 1.0, Fixtures::ADMIN);

        $resultado = $this->db->query("SELECT * FROM resultados WHERE enfrentamiento_id = $eid")->fetch(PDO::FETCH_ASSOC);
        $partido   = $this->db->query("SELECT * FROM enfrentamientos WHERE id = $eid")->fetch(PDO::FETCH_ASSOC);
        $tabla     = $this->tabla($tid);

        $this->fallarAl('INSERT', 'auditoria', "NEW.accion = 'corregir_resultado'");   // lo último que hace corregir()
        $this->assertThrows(fn() => (new ResultadoService())->corregir($eid, 0.0, 3.0, 'Se cargó al revés', Fixtures::ADMIN), 'Falla inducida');

        $this->assertSame($resultado, $this->db->query("SELECT * FROM resultados WHERE enfrentamiento_id = $eid")->fetch(PDO::FETCH_ASSOC),
            'el resultado sigue siendo el original (2-1), sin marcas de corrección');
        $this->assertSame($partido, $this->db->query("SELECT * FROM enfrentamientos WHERE id = $eid")->fetch(PDO::FETCH_ASSOC),
            'el ganador del partido no cambió');
        $this->assertSame($tabla, $this->tabla($tid), 'la tabla tampoco');

        $this->repararFallas();
        (new ResultadoService())->corregir($eid, 0.0, 3.0, 'Se cargó al revés', Fixtures::ADMIN);
        $this->assertSame('corregido', $this->valor('SELECT estado FROM resultados WHERE enfrentamiento_id = ?', [$eid]),
            'reparada la falla, se puede corregir');
    }

    public function test_aprobar_una_correccion_es_todo_o_nada(): void
    {
        [$tid] = Fixtures::torneoArrancado('liga', 4);
        $eid = Fixtures::partidosPendientes($tid)[0];
        (new ResultadoService())->cargar($eid, 2.0, 1.0, Fixtures::ADMIN);

        $svc = new CorreccionService();
        $svc->solicitar($eid, 0.0, 3.0, 'Se cargó al revés, ganó el otro', Fixtures::ORGANIZADOR);
        $sid = (int) $this->valor('SELECT id FROM solicitudes_correccion WHERE enfrentamiento_id = ?', [$eid]);
        $resultado = $this->db->query("SELECT * FROM resultados WHERE enfrentamiento_id = $eid")->fetch(PDO::FETCH_ASSOC);

        // Aprobar = aplicar la corrección Y marcar la solicitud como resuelta. Si lo segundo
        // falla, no puede quedar el resultado corregido con la solicitud todavía pendiente: otro
        // administrador la aprobaría de nuevo.
        $this->fallarAl('UPDATE', 'solicitudes_correccion');
        $this->assertThrows(fn() => $svc->aprobar($sid, Fixtures::ADMIN), 'Falla inducida');

        $this->assertSame($resultado, $this->db->query("SELECT * FROM resultados WHERE enfrentamiento_id = $eid")->fetch(PDO::FETCH_ASSOC),
            'el resultado no se corrigió');
        $this->assertSame('pendiente', $this->valor('SELECT estado FROM solicitudes_correccion WHERE id = ?', [$sid]));
        $this->assertSame(0, $this->contar('auditoria', "accion IN ('corregir_resultado', 'aprobar_correccion')"));

        $this->repararFallas();
        $svc->aprobar($sid, Fixtures::ADMIN);
        $this->assertSame('aprobada', $this->valor('SELECT estado FROM solicitudes_correccion WHERE id = ?', [$sid]));
        $this->assertSame('corregido', $this->valor('SELECT estado FROM resultados WHERE enfrentamiento_id = ?', [$eid]));
    }

    public function test_recalcular_la_tabla_es_todo_o_nada(): void
    {
        [$tid] = Fixtures::torneoArrancado('liga', 4);
        (new ResultadoService())->cargar(Fixtures::partidosPendientes($tid)[0], 2.0, 1.0, Fixtures::ADMIN);
        $tabla = $this->tabla($tid);
        $this->assertCount(4, $tabla, 'control: la tabla tiene a los 4');

        // Se corta al escribir el 3.er puesto: ya se había borrado todo y escrito el 1.º y el 2.º.
        $this->fallarAl('INSERT', 'tabla_posiciones', 'NEW.posicion = 3');
        $this->assertThrows(fn() => (new TablaPosicionesService())->recalcular($tid), 'Falla inducida');

        $this->assertSame($tabla, $this->tabla($tid), 'la tabla sigue entera, no con 2 de 4');
    }

    // ─── Permisos ───────────────────────────────────────────────────────────

    public function test_guardar_la_matriz_de_permisos_es_todo_o_nada(): void
    {
        $roles   = (new RolModel())->findAll();
        $modulos = (new ModuloModel())->findAll();
        $admin   = (int) $this->valor("SELECT id FROM roles WHERE nombre = 'administrador'");

        // Se marca TODO para todos los roles que no son el administrador…
        $marcados = [];
        $ultimo   = null;
        foreach ($roles as $rol) {
            if ((int) $rol['id'] === $admin) continue;
            foreach ($modulos as $modulo) {
                foreach (PermisoService::ACCIONES as $accion) {
                    $marcados[(int) $rol['id']][(string) $modulo['slug']][$accion] = '1';
                }
                $ultimo = [(int) $rol['id'], (string) $modulo['slug']];
            }
        }
        $this->assertNotNull($ultimo, 'control: hay roles y módulos');

        // …control: sin el último par, el resto SÍ cambiaría algo (si no, no se vería el medio).
        $cambiarian = $this->contar('permisos',
            'rol_id <> ? AND NOT (rol_id = ? AND modulo_slug = ?) AND NOT (puede_ver = 1 AND puede_crear = 1 AND puede_editar = 1 AND puede_eliminar = 1)',
            [$admin, $ultimo[0], $ultimo[1]]);
        $this->assertGreaterThan(0, $cambiarian, 'control: la matriz de fábrica no es ya «todo marcado»');

        // …y se corta en el último par, cuando casi toda la matriz ya se había reescrito.
        $this->fallarAl('INSERT', 'permisos', "NEW.rol_id = {$ultimo[0]} AND NEW.modulo_slug = '{$ultimo[1]}'");
        $this->assertThrows(fn() => (new PermisoService())->guardarMatriz($marcados), 'Falla inducida');

        $this->assertSame($this->permisosIniciales, $this->volcado('permisos'),
            'la matriz es exactamente la de antes: ni un permiso cambió');
        $this->assertSame(0, $this->contar('auditoria', "accion = 'actualizar_permisos'"));
    }
}

TestCase::correrSiEsScript(TransaccionesTest::class);
