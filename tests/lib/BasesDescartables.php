<?php
declare(strict_types=1);

/**
 * Bases de datos descartables para los tests que necesitan una estructura
 * propia (migraciones, privilegios).
 *
 * Cada una se crea vacía junto a la base de pruebas compartida, se llena con el
 * archivo SQL que el test indique y se borra al terminar el proceso, pase o
 * falle. Así un test puede aplicar migraciones, o romper una restricción a
 * propósito, sin tocar los datos que usan los demás.
 *
 * Requiere una conexión con permiso para crear bases (en los tests, root).
 */
final class BasesDescartables
{
    /** @var string[] */
    private static array $creadas = [];
    private static bool  $limpiezaRegistrada = false;

    /**
     * Crea `<DB_NAME>_<sufijo>`, le aplica $archivoEsquema (si se indica) y
     * devuelve una conexión propia a ella.
     *
     * La conexión NO es la del singleton de la aplicación: ese usa sentencias
     * preparadas nativas, y con ellas MySQL no admite varias sentencias en una
     * consulta, que es justo lo que es un archivo SQL. Esta se comporta como el
     * cliente `mysql`, que es como se aplican de verdad.
     */
    public static function crear(string $sufijo, ?string $archivoEsquema = null): PDO
    {
        $nombre = $_ENV['DB_NAME'] . '_' . $sufijo;
        $root   = Database::getInstance();

        $root->exec("DROP DATABASE IF EXISTS `{$nombre}`");
        $root->exec("CREATE DATABASE `{$nombre}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        self::$creadas[] = $nombre;

        if (!self::$limpiezaRegistrada) {
            register_shutdown_function([self::class, 'limpiar']);
            self::$limpiezaRegistrada = true;
        }

        $pdo = new PDO(
            "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$nombre};charset=utf8mb4",
            $_ENV['DB_USER'],
            $_ENV['DB_PASS'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        if ($archivoEsquema !== null) {
            self::ejecutarArchivo($pdo, $archivoEsquema);
        }
        return $pdo;
    }

    /** Ejecuta un archivo SQL entero, consumiendo todo lo que devuelva. */
    public static function ejecutarArchivo(PDO $pdo, string $ruta): void
    {
        $sql = file_get_contents($ruta);
        if ($sql === false) {
            throw new RuntimeException("No se pudo leer {$ruta}");
        }

        // Algunas migraciones devuelven filas (sus SELECT de verificación): hay
        // que consumirlas todas, o la conexión queda en un estado que rechaza la
        // consulta siguiente. Y avanzar por los resultados es lo que hace saltar
        // el error de una sentencia que no sea la primera.
        $stmt = $pdo->query($sql);
        if ($stmt === false) return;
        do {
            if ($stmt->columnCount() > 0) $stmt->fetchAll();
        } while ($stmt->nextRowset());
    }

    /** Borra las bases creadas. Corre sola al terminar el proceso. */
    public static function limpiar(): void
    {
        foreach (self::$creadas as $nombre) {
            try {
                Database::getInstance()->exec("DROP DATABASE IF EXISTS `{$nombre}`");
            } catch (Throwable) {
                // Nada más que hacer: es una base descartable.
            }
        }
        self::$creadas = [];
    }
}
