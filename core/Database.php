<?php
declare(strict_types=1);

class Database
{
    private static ?PDO $pdo = null;

    /** Cuántas llamadas a transaccion() hay abiertas una dentro de otra. */
    private static int $nivelTransaccion = 0;

    private function __construct() {}
    private function __clone() {}

    public static function getInstance(): PDO
    {
        if (self::$pdo === null) {
            $host    = $_ENV['DB_HOST']    ?? 'db';
            $port    = $_ENV['DB_PORT']    ?? '3306';
            $dbname  = $_ENV['DB_NAME']    ?? 'flexarena';
            $user    = $_ENV['DB_USER']    ?? 'flexarena_user';
            $pass    = $_ENV['DB_PASS']    ?? '';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

            // Para quién trabaja esta conexión. Los triggers del historial (historial_bd) lo
            // leen de @id_usuario_app y lo guardan junto a cada cambio. Es informativo: lo fija
            // la aplicación. La cuenta real de MySQL queda registrada aparte y esa no se falsea.
            $usuarioApp = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

            try {
                self::$pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE              => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE   => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES     => false,
                    // Fija la zona horaria de la sesión MySQL para que NOW()/CURRENT_TIMESTAMP
                    // coincidan con la app (America/Argentina/Buenos_Aires = UTC-03:00).
                    PDO::MYSQL_ATTR_INIT_COMMAND   => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '-03:00', "
                                                    . "@id_usuario_app = " . ($usuarioApp ?? 'NULL'),
                ]);
            } catch (PDOException $e) {
                error_log('DB connection failed: ' . $e->getMessage());
                http_response_code(500);
                die('Error de conexión a la base de datos. Consulte los logs.');
            }
        }

        return self::$pdo;
    }

    /**
     * Ejecuta $fn dentro de una transacción: o queda todo guardado, o no queda nada.
     *
     * Sirve para las operaciones que escriben varias filas (generar un fixture, registrar
     * una cuenta con su perfil, guardar la matriz de permisos). Sin transacción, un error a
     * mitad de camino deja la estructura a medio hacer: rondas sin partidos, una cuenta sin
     * perfil, permisos a medio cambiar.
     *
     * Se puede anidar: si ya hay una transacción abierta —por otra llamada a esta función o
     * porque quien nos llamó la abrió— $fn corre como un paso más de ella y NO hace commit ni
     * rollback. Eso lo decide quien la abrió. Si $fn lanza una excepción, la más externa
     * deshace todo y la deja pasar para que el controlador la muestre.
     *
     * La anidación es «plana»: no hay puntos de guardado. Por eso la excepción de una operación
     * transaccional tiene que llegar hasta la más externa; si alguien la atrapa en el medio y
     * sigue, lo que esa operación alcanzó a escribir queda dentro de la transacción de quien la
     * llamó y se confirma con ella.
     *
     * @template T
     * @param  callable(): T $fn
     * @return T lo que devuelva $fn
     */
    public static function transaccion(callable $fn): mixed
    {
        $pdo = self::getInstance();

        if (self::$nivelTransaccion > 0 || $pdo->inTransaction()) {
            self::$nivelTransaccion++;
            try {
                return $fn();
            } finally {
                self::$nivelTransaccion--;
            }
        }

        $pdo->beginTransaction();
        self::$nivelTransaccion = 1;
        try {
            $resultado = $fn();
            $pdo->commit();
            return $resultado;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            self::$nivelTransaccion = 0;
        }
    }

    /**
     * Cambia el usuario de la aplicación al que se atribuyen los cambios de esta
     * conexión (login y logout). Si todavía no hay conexión no hace falta: al abrirla
     * se toma de la sesión.
     */
    public static function fijarUsuarioApp(?int $id): void
    {
        if (self::$pdo === null) return;
        self::$pdo->exec('SET @id_usuario_app = ' . ($id === null ? 'NULL' : (string) $id));
    }

    /** Resetea la instancia (útil para testing) */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$nivelTransaccion = 0;
    }
}
