<?php
declare(strict_types=1);

/**
 * Datos de prueba reutilizables por los tests de integración.
 *
 * Todo se crea con los SERVICIOS reales (no con INSERT a mano) para que los
 * tests ejerciten el mismo camino que la aplicación. Los catálogos que vienen
 * de database/seed.sql —roles, usuarios base, tipos_torneo, modulos— se dan por
 * existentes; lo demás se genera y se limpia en cada corrida.
 *
 * Ids de usuario que aporta seed.sql y que acá se usan como dueños:
 *   1 = administrador, 2 y 3 = organizadores.
 */
class Fixtures
{
    public const ADMIN        = 1;
    public const ORGANIZADOR  = 2;
    public const ORGANIZADOR2 = 3;

    /** Sufijo de email de los usuarios que crean los tests (se borran en reset()). */
    public const DOMINIO_TEST = '@test.local';

    /**
     * Estado de partida conocido: sin datos dinámicos, sin usuarios de test y
     * con todos los módulos habilitados.
     *
     * Se trunca en vez de envolver todo en una transacción porque varios
     * servicios (ResultadoService::corregir, por ejemplo) abren su propia
     * transacción, y PDO/MySQL no anidan: un BEGIN externo se cerraría solo.
     */
    public static function reset(PDO $db): void
    {
        // Se usa DELETE y no TRUNCATE a proposito: TRUNCATE es DDL y en InnoDB
        // cuesta del orden de un segundo por tabla, lo que con un setUp() por
        // caso dominaba el tiempo de toda la bateria. Con DELETE no se reinicia
        // el AUTO_INCREMENT, cosa de la que ningun test depende.
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'solicitudes_correccion', 'tabla_posiciones', 'resultados', 'enfrentamientos', 'rondas',
            'inscripciones', 'torneo_organizadores', 'configuraciones_torneo', 'torneos',
            'equipo_participantes', 'equipos', 'participantes', 'auditoria',
        ] as $tabla) {
            $db->exec("DELETE FROM $tabla");
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');

        // Usuarios creados por tests anteriores.
        $db->exec("DELETE FROM usuarios WHERE email LIKE '%" . self::DOMINIO_TEST . "'");

        // Los tests del toggle de módulos pueden dejar alguno apagado.
        $db->exec("UPDATE modulos SET estado = 'activo'");

        // Los tests de bloqueo tocan los usuarios del seed: devolverlos a su estado.
        $db->exec("UPDATE usuarios SET failed_attempts = 0 WHERE id IN (1,2,3,4,5,6)");
        $db->exec("UPDATE usuarios SET estado = 'activo' WHERE id IN (1,2,3,4,5,6)");
    }

    /** @return int[] ids de los participantes creados */
    public static function participantes(int $cantidad, string $prefijo = 'Jugador'): array
    {
        $model = new ParticipanteModel();
        $ids = [];
        for ($i = 1; $i <= $cantidad; $i++) {
            $ids[] = $model->insert([
                'nombre' => "$prefijo $i",
                'nick'   => strtolower($prefijo) . $i,
                'estado' => 'activo',
            ]);
        }
        return $ids;
    }

    /** @return int[] ids de los equipos creados, con 3 integrantes cada uno */
    public static function equipos(int $cantidad, array $participanteIds): array
    {
        $model = new EquipoModel();
        $ids = [];
        $i = 0;
        for ($e = 1; $e <= $cantidad; $e++) {
            $eid = $model->insert([
                'nombre'     => "Equipo $e",
                'categoria'  => 'A',
                'disciplina' => 'Prueba',
                'estado'     => 'activo',
            ]);
            for ($k = 0; $k < 3; $k++) {
                $model->agregarParticipante($eid, $participanteIds[$i % count($participanteIds)], $k === 0 ? 'capitan' : 'jugador');
                $i++;
            }
            $ids[] = $eid;
        }
        return $ids;
    }

    /** Crea un usuario de prueba (email con DOMINIO_TEST) y devuelve su id. */
    public static function usuario(string $nombre, string $email, string $password, int $rolId = 2): int
    {
        if (!str_ends_with($email, self::DOMINIO_TEST)) {
            throw new InvalidArgumentException('Los usuarios de test deben usar ' . self::DOMINIO_TEST);
        }
        return (new UsuarioService())->crear([
            'nombre'   => $nombre,
            'email'    => $email,
            'password' => $password,
            'rol_id'   => $rolId,
            'estado'   => 'activo',
        ]);
    }

    /**
     * Crea un torneo en estado inscripción con los valores por defecto de los
     * tests. $opciones sobrescribe cualquier clave.
     */
    public static function torneo(string $slug, array $opciones = []): int
    {
        $tipo = (new TipoTorneoModel())->findBySlug($slug);
        if (!$tipo) throw new RuntimeException("No existe el tipo de torneo «{$slug}».");

        return (new TorneoService())->crear(array_merge([
            'nombre'          => 'Torneo de prueba (' . $slug . ')',
            'tipo_torneo_id'  => (int)$tipo['id'],
            'modalidad'       => 'individual',
            'estado'          => 'inscripcion',
            'publico'         => '1',
            'creado_por'      => self::ADMIN,
            'organizador_id'  => self::ORGANIZADOR,
            'permite_empates' => null,
            'puntos_victoria' => 3,
            'puntos_empate'   => 1,
            'puntos_derrota'  => 0,
            'rondas_suizo'    => $slug === 'suizo' ? 3 : null,
            'bye_suizo'       => 'sin_puntos',
            'nombre_puntos'   => 'puntos',
            'fecha_inicio'    => date('Y-m-d'),
            'fecha_fin'       => date('Y-m-d', strtotime('+30 days')),
        ], $opciones));
    }

    public static function inscribir(int $torneoId, array $ids, string $modalidad = 'individual'): void
    {
        $svc = new InscripcionService();
        foreach ($ids as $id) {
            if ($modalidad === 'equipos') $svc->inscribirEquipo($torneoId, $id);
            else                          $svc->inscribirParticipante($torneoId, $id);
        }
    }

    /**
     * Torneo listo para jugar: creado, con inscritos y con la estructura generada
     * por el servicio del formato que corresponda.
     *
     * @return array{0:int,1:int[]} [torneoId, ids de los inscritos]
     */
    public static function torneoArrancado(string $slug, int $participantes, array $opciones = []): array
    {
        $pids     = self::participantes($participantes);
        $torneoId = self::torneo($slug, $opciones);
        self::inscribir($torneoId, $pids);

        match ($slug) {
            'liga'                => (new LigaService())->generarFixture($torneoId),
            'eliminacion_directa' => (new EliminacionDirectaService())->generarBracket($torneoId),
            'suizo'               => (new SistemaSuizoService())->generarPrimeraRonda($torneoId),
            default               => throw new RuntimeException("Formato desconocido: $slug"),
        };

        return [$torneoId, $pids];
    }

    /**
     * Ids de los enfrentamientos jugables (con ambos lados definidos y sin resultado).
     * Si se pasa $numeroRonda, solo los de esa ronda.
     *
     * @return int[]
     */
    public static function partidosPendientes(int $torneoId, ?int $numeroRonda = null): array
    {
        $db  = Database::getInstance();
        $sql = "SELECT e.id
                FROM enfrentamientos e
                JOIN rondas r ON r.id = e.ronda_id
                WHERE e.torneo_id = :tid
                  AND e.estado IN ('pendiente','en_curso')
                  AND e.es_bye = 0
                  AND (e.participante_a_id IS NOT NULL OR e.equipo_a_id IS NOT NULL)
                  AND (e.participante_b_id IS NOT NULL OR e.equipo_b_id IS NOT NULL)";
        $params = [':tid' => $torneoId];
        if ($numeroRonda !== null) {
            $sql .= " AND r.numero = :n";
            $params[':n'] = $numeroRonda;
        }
        $sql .= " ORDER BY r.numero, e.orden";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Carga resultados con el marcador que devuelva $marcador (por defecto 2-1,
     * o sea siempre gana el lado A). Repite hasta que no queden partidos jugables,
     * de modo que los brackets avancen ronda a ronda.
     *
     * @param callable|null $marcador fn(int $enfrentamientoId, int $i): array{0:float,1:float}
     */
    public static function jugarTodo(int $torneoId, ?callable $marcador = null, int $cargadoPor = self::ADMIN): void
    {
        $svc = new ResultadoService();
        $i = 0;
        do {
            $ids = self::partidosPendientes($torneoId);
            foreach ($ids as $eid) {
                [$a, $b] = $marcador ? $marcador($eid, $i) : [2.0, 1.0];
                $svc->cargar($eid, (float)$a, (float)$b, $cargadoPor);
                $i++;
            }
        } while ($ids !== []);
    }

    /** Deja la sesión como si $usuarioId estuviera logueado, sin pasar por AuthService. */
    public static function loguearComo(int $usuarioId): void
    {
        $u = (new UsuarioModel())->findByIdConRol($usuarioId) ?? (new UsuarioModel())->findById($usuarioId);
        if (!$u) throw new RuntimeException("Usuario $usuarioId inexistente.");
        $_SESSION['user_id']     = (int)$u['id'];
        $_SESSION['user_nombre'] = $u['nombre'];
        $_SESSION['user_email']  = $u['email'];
        $_SESSION['user_rol']    = $u['rol_nombre'] ?? null;
        $_SESSION['user_rol_id'] = (int)$u['rol_id'];
    }

    public static function cerrarSesion(): void
    {
        $_SESSION = [];
    }
}
