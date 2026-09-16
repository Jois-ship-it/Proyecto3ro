<?php
declare(strict_types=1);

/**
 * Acceso a la tabla `permisos`: qué puede hacer cada rol sobre cada módulo.
 *
 * La tabla tiene UNIQUE (rol_id, modulo_slug), así que hay como mucho una fila
 * por combinación. La ausencia de fila significa «no puede nada»: la política es
 * negar por omisión (ver PermisoService).
 */
class PermisoModel extends BaseModel
{
    protected string $table = 'permisos';

    /** Una fila puntual, o null si el rol no tiene permisos sobre ese módulo. */
    public function find(int $rolId, string $modulo): ?array
    {
        $r = $this->fetchOne(
            "SELECT * FROM permisos WHERE rol_id = :r AND modulo_slug = :m LIMIT 1",
            [':r' => $rolId, ':m' => $modulo]
        );
        return $r ?: null;
    }

    /**
     * Todos los permisos de un rol, indexados por slug de módulo.
     *
     * Se trae de una sola vez porque el chequeo se hace varias veces por
     * request (guardas de controlador + armado del menú lateral).
     *
     * @return array<string, array>
     */
    public function porRol(int $rolId): array
    {
        $filas = $this->fetchAll(
            "SELECT * FROM permisos WHERE rol_id = :r",
            [':r' => $rolId]
        );

        $porSlug = [];
        foreach ($filas as $fila) {
            $porSlug[(string) $fila['modulo_slug']] = $fila;
        }
        return $porSlug;
    }

    /**
     * La matriz completa para la pantalla de administración: una fila por cada
     * combinación rol × módulo que EXISTE en la tabla.
     *
     * @return array<int, array<string, array>> rol_id → slug → fila
     */
    public function matriz(): array
    {
        $filas = $this->fetchAll("SELECT * FROM permisos ORDER BY rol_id, modulo_slug");

        $matriz = [];
        foreach ($filas as $fila) {
            $matriz[(int) $fila['rol_id']][(string) $fila['modulo_slug']] = $fila;
        }
        return $matriz;
    }

    /**
     * Inserta o actualiza la fila de un rol sobre un módulo.
     *
     * Se usa ON DUPLICATE KEY UPDATE contra el índice único (rol_id,
     * modulo_slug) para no tener que consultar antes si la fila existe. En el
     * UPDATE se referencian los valores con VALUES(col) y no con marcadores
     * nuevos, porque la conexión va con ATTR_EMULATE_PREPARES = false y ahí un
     * marcador con nombre no se puede repetir en la misma sentencia.
     */
    public function guardar(int $rolId, string $modulo, bool $ver, bool $crear, bool $editar, bool $eliminar): void
    {
        $this->execute(
            "INSERT INTO permisos (rol_id, modulo_slug, puede_ver, puede_crear, puede_editar, puede_eliminar)
             VALUES (:r, :m, :v, :c, :e, :d)
             ON DUPLICATE KEY UPDATE
                puede_ver      = VALUES(puede_ver),
                puede_crear    = VALUES(puede_crear),
                puede_editar   = VALUES(puede_editar),
                puede_eliminar = VALUES(puede_eliminar)",
            [
                ':r' => $rolId,
                ':m' => $modulo,
                ':v' => $ver      ? 1 : 0,
                ':c' => $crear    ? 1 : 0,
                ':e' => $editar   ? 1 : 0,
                ':d' => $eliminar ? 1 : 0,
            ]
        );
    }

    /** Borra la fila de un rol sobre un módulo (equivale a negarle todo). */
    public function borrar(int $rolId, string $modulo): void
    {
        $this->execute(
            "DELETE FROM permisos WHERE rol_id = :r AND modulo_slug = :m",
            [':r' => $rolId, ':m' => $modulo]
        );
    }
}
