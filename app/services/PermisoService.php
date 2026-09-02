<?php
declare(strict_types=1);

class PermisoService
{
    private static array $cache = [];

    /** Verifica si el usuario autenticado puede realizar una acción sobre un módulo */
    public static function puede(string $accion, string $modulo): bool
    {
        $rol = Auth::rol();
        if (!$rol) return false;

        // Administrador tiene acceso total
        if ($rol === 'administrador') return true;

        $cacheKey = "{$rol}:{$modulo}:{$accion}";
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT p.{$accion}
             FROM permisos p
             JOIN roles r ON r.id = p.rol_id
             WHERE r.nombre = :rol AND p.modulo_slug = :modulo LIMIT 1"
        );
        $stmt->execute([':rol' => $rol, ':modulo' => $modulo]);
        $row = $stmt->fetch();

        $result = $row ? (bool)$row[$accion] : false;
        self::$cache[$cacheKey] = $result;

        return $result;
    }

    public static function puedeVer(string $modulo): bool    { return self::puede('puede_ver', $modulo); }
    public static function puedeCrear(string $modulo): bool  { return self::puede('puede_crear', $modulo); }
    public static function puedeEditar(string $modulo): bool { return self::puede('puede_editar', $modulo); }
    public static function puedeEliminar(string $modulo): bool { return self::puede('puede_eliminar', $modulo); }
}
