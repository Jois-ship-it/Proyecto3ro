<?php
declare(strict_types=1);

require_once __DIR__ . '/entorno_cli.php';

/**
 * Mínimo privilegio para el usuario de la aplicación (DCL).
 *
 * La imagen oficial de MySQL, al crear MYSQL_USER, le da ALL PRIVILEGES sobre la
 * base: puede borrar tablas, cambiar su estructura y reescribir la auditoría. La
 * aplicación no necesita nada de eso, y una inyección SQL (o una credencial
 * filtrada) heredaría todo ese poder. Este script deja al usuario con lo que la
 * aplicación usa de verdad, tabla por tabla:
 *
 *   tablas de negocio        SELECT, INSERT, UPDATE, DELETE
 *   roles, tipos_torneo      SELECT                  catálogos: solo se cargan con el seed
 *   modulos                  SELECT, UPDATE          el administrador los enciende y apaga
 *   configuracion_sistema    SELECT, INSERT, UPDATE  se edita, no se borra
 *   auditoria                SELECT, INSERT          evidencia: no se edita ni se borra
 *   historial_bd             SELECT                  la escriben los triggers, no la aplicación
 *   intentos_login           SELECT, INSERT, DELETE  registro operativo que se purga solo
 *   vistas                   SELECT
 *
 * Nunca se concede CREATE, DROP, ALTER, INDEX, REFERENCES, TRIGGER, LOCK TABLES ni
 * GRANT OPTION, ni nada a nivel global.
 *
 * Idempotente: compara lo que el usuario TIENE con lo que DEBE tener y concede o
 * revoca solo la diferencia, así que correrlo de más no cambia nada. Se corre en
 * cada arranque del contenedor `app` (docker/docker-entrypoint.sh), por lo que
 * también alcanza a una base ya existente y a las tablas que agreguen las
 * migraciones futuras.
 *
 * Necesita credenciales de ADMINISTRACIÓN de la base (root): las que la aplicación
 * NO usa. Por eso no corre dentro de la app web.
 *
 * Uso (CLI):
 *   DB_ROOT_PASS=... php database/aplicar_privilegios.php
 * Toma DB_HOST, DB_PORT, DB_NAME y DB_USER del entorno (o del .env); el
 * administrador es DB_ROOT_USER (por omisión root) con DB_ROOT_PASS.
 */
final class PrivilegiosDb
{
    /** Lo que se concede por omisión: las tablas que la aplicación administra. */
    public const NEGOCIO = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

    /**
     * Las excepciones, con el motivo. Todo lo que no figura acá es de negocio.
     *
     * @var array<string, array{0: string[], 1: string}> tabla => [privilegios, por qué]
     */
    public const ESPECIALES = [
        'roles'                 => [['SELECT'],                     'catálogo: solo se carga con el seed'],
        'tipos_torneo'          => [['SELECT'],                     'catálogo: solo se carga con el seed'],
        'modulos'               => [['SELECT', 'UPDATE'],           'el administrador los enciende y apaga; no se crean ni se borran'],
        'configuracion_sistema' => [['SELECT', 'INSERT', 'UPDATE'], 'se edita, no se borra'],
        'auditoria'             => [['SELECT', 'INSERT'],           'evidencia: no se edita ni se borra'],
        'historial_bd'          => [['SELECT'],                     'evidencia que escriben los triggers (con los privilegios de quien los creó): la aplicación solo la lee'],
        'intentos_login'        => [['SELECT', 'INSERT', 'DELETE'], 'registro operativo que se purga solo'],
    ];

    /** Lo único que se le concede sobre una vista. */
    public const VISTA = ['SELECT'];

    /** @return string[] los privilegios que le corresponden a $tabla, en orden canónico */
    public static function privilegiosDe(string $tabla, bool $esVista = false): array
    {
        if ($esVista) return self::VISTA;
        return self::ESPECIALES[$tabla][0] ?? self::NEGOCIO;
    }

    /**
     * Deja a $usuario con el mínimo privilegio sobre $base.
     *
     * @param  PDO    $admin   conexión con permiso para GRANT/REVOKE (root)
     * @return array{usuario:string, hosts:string[], tablas:int, otorgados:int, revocados:int}
     */
    public static function aplicar(PDO $admin, string $base, string $usuario): array
    {
        foreach (['base' => $base, 'usuario' => $usuario] as $que => $valor) {
            if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $valor)) {
                throw new InvalidArgumentException("El nombre de {$que} «{$valor}» tiene caracteres no permitidos.");
            }
        }
        if (strtolower($usuario) === 'root') {
            throw new InvalidArgumentException('DB_USER no puede ser root: la aplicación no debe conectarse como administrador.');
        }

        $q = fn(string $v): string => $admin->quote($v);
        $id = fn(string $v): string => '`' . str_replace('`', '``', $v) . '`';

        $hosts = $admin->query('SELECT host FROM mysql.user WHERE user = ' . $q($usuario))->fetchAll(PDO::FETCH_COLUMN);
        if ($hosts === []) {
            throw new RuntimeException("El usuario «{$usuario}» no existe en el servidor de base de datos.");
        }

        // Todas las tablas y vistas de la base, con su tipo.
        $stmt = $admin->prepare('SELECT table_name AS t, table_type AS tipo FROM information_schema.tables WHERE table_schema = ?');
        $stmt->execute([$base]);
        $objetos = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $objetos[$f['t']] = $f['tipo'] === 'VIEW';
        }

        $otorgados = 0;
        $revocados = 0;

        foreach ($hosts as $host) {
            $cuenta  = $q($usuario) . '@' . $q($host);
            $grantee = "'{$usuario}'@'{$host}'";

            // 1) Nada a nivel de base de datos: lo que se concede es por tabla.
            //    (En MySQL un privilegio de base no se puede recortar por tabla.)
            $porBase = $admin->prepare(
                'SELECT privilege_type, is_grantable FROM information_schema.schema_privileges WHERE grantee = ? AND table_schema = ?');
            $porBase->execute([$grantee, $base]);
            $deBase = $porBase->fetchAll(PDO::FETCH_NUM);
            if ($deBase !== []) {
                $admin->exec('REVOKE ALL PRIVILEGES ON ' . $id($base) . '.* FROM ' . $cuenta);
                $revocados += count($deBase);
                if (in_array('YES', array_column($deBase, 1), true)) {
                    $admin->exec('REVOKE GRANT OPTION ON ' . $id($base) . '.* FROM ' . $cuenta);
                }
            }

            // 2) Lo que ya tiene, por tabla (y si puede pasárselo a otros).
            $tiene = [];
            $reparte = [];
            $porTabla = $admin->prepare(
                'SELECT table_name, privilege_type, is_grantable FROM information_schema.table_privileges WHERE grantee = ? AND table_schema = ?');
            $porTabla->execute([$grantee, $base]);
            foreach ($porTabla->fetchAll(PDO::FETCH_NUM) as [$t, $p, $puedeDar]) {
                $tiene[$t][] = $p;
                if ($puedeDar === 'YES') $reparte[$t] = true;
            }

            // 3) Lo que le falta y lo que le sobra, tabla por tabla.
            foreach ($objetos as $tabla => $esVista) {
                $debe = self::privilegiosDe($tabla, $esVista);
                $hoy  = $tiene[$tabla] ?? [];

                $faltan = array_values(array_diff($debe, $hoy));
                $sobran = array_values(array_diff($hoy, $debe));

                if ($sobran !== []) {
                    $admin->exec('REVOKE ' . implode(', ', $sobran) . ' ON ' . $id($base) . '.' . $id($tabla) . ' FROM ' . $cuenta);
                    $revocados += count($sobran);
                }
                if ($faltan !== []) {
                    $admin->exec('GRANT ' . implode(', ', $faltan) . ' ON ' . $id($base) . '.' . $id($tabla) . ' TO ' . $cuenta);
                    $otorgados += count($faltan);
                }
                if (isset($reparte[$tabla])) {
                    $admin->exec('REVOKE GRANT OPTION ON ' . $id($base) . '.' . $id($tabla) . ' FROM ' . $cuenta);
                    $revocados++;
                }
            }

            // 4) Permisos sobre tablas que ya no existen (una tabla borrada deja su
            //    fila en mysql.tables_priv): no sirven y confunden en un SHOW GRANTS.
            foreach (array_diff(array_keys($tiene), array_keys($objetos)) as $fantasma) {
                $admin->exec('REVOKE ALL PRIVILEGES ON ' . $id($base) . '.' . $id($fantasma) . ' FROM ' . $cuenta);
                $revocados += count($tiene[$fantasma]);
            }
        }

        return [
            'usuario'   => $usuario,
            'hosts'     => array_values($hosts),
            'tablas'    => count($objetos),
            'otorgados' => $otorgados,
            'revocados' => $revocados,
        ];
    }

    /** Punto de entrada de la línea de comandos. Devuelve el código de salida. */
    public static function ejecutarCli(): int
    {
        $e = entornoCli(dirname(__DIR__),
            ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_ROOT_USER', 'DB_ROOT_PASS']);

        if (($e['DB_ROOT_PASS'] ?? '') === '') {
            fwrite(STDERR, "Falta DB_ROOT_PASS: aplicar privilegios exige las credenciales de administración de la base.\n");
            return 2;
        }

        try {
            $admin = new PDO(
                sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $e['DB_HOST'] ?? 'db', $e['DB_PORT'] ?? '3306'),
                $e['DB_ROOT_USER'] ?? 'root',
                $e['DB_ROOT_PASS'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $r = self::aplicar($admin, $e['DB_NAME'] ?? 'flexarena', $e['DB_USER'] ?? 'flexarena_user');
        } catch (Throwable $ex) {
            fwrite(STDERR, 'No se pudo aplicar el mínimo privilegio: ' . $ex->getMessage() . "\n");
            return 1;
        }

        if ($r['otorgados'] === 0 && $r['revocados'] === 0) {
            echo "Privilegios de «{$r['usuario']}»: ya estaban al mínimo ({$r['tablas']} tablas), sin cambios.\n";
        } else {
            echo "Privilegios de «{$r['usuario']}» ajustados al mínimo: {$r['tablas']} tablas, "
               . "{$r['otorgados']} concedidos, {$r['revocados']} revocados.\n";
        }
        return 0;
    }
}

// Solo corre cuando se invoca como script, no cuando un test lo incluye.
if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(PrivilegiosDb::ejecutarCli());
}
