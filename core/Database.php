<?php
declare(strict_types=1);

class Database
{
    private static ?PDO $pdo = null;

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

            try {
                self::$pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE              => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE   => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES     => false,
                    // Fija la zona horaria de la sesión MySQL para que NOW()/CURRENT_TIMESTAMP
                    // coincidan con la app (America/Argentina/Buenos_Aires = UTC-03:00).
                    PDO::MYSQL_ATTR_INIT_COMMAND   => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '-03:00'",
                ]);
            } catch (PDOException $e) {
                error_log('DB connection failed: ' . $e->getMessage());
                http_response_code(500);
                die('Error de conexión a la base de datos. Consulte los logs.');
            }
        }

        return self::$pdo;
    }

    /** Resetea la instancia (útil para testing) */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
