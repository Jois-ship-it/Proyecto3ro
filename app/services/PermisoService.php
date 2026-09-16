<?php
declare(strict_types=1);

/**
 * Autorización por rol y módulo.
 *
 * La letra del proyecto (§5) define roles «con permisos claramente definidos» y,
 * para el organizador, incluye una acción condicionada: «corregir resultados si
 * cuenta con autorización». Esa autorización es exactamente una fila de la tabla
 * `permisos`, y esta clase es la que la lee.
 *
 * Tres reglas, en este orden:
 *
 *   1. El administrador tiene control completo (§5.1) y no pasa por la tabla.
 *      No se le guardan filas: así no hay forma de dejarlo sin acceso editando
 *      la matriz.
 *   2. Si hay fila para (rol, módulo), manda lo que diga esa fila.
 *   3. Si no hay fila, se niega. Negar por omisión: agregar un módulo nuevo no
 *      le abre la puerta a nadie por accidente.
 *
 * No reemplaza a las otras dos compuertas, las complementa:
 *
 *   - `modulos.estado` (ModuloActivoTrait) dice si el módulo está habilitado
 *     para TODO el sistema.
 *   - `permisos` dice si ESTE ROL puede hacer ESTA ACCIÓN sobre el módulo.
 *   - `requireTorneoOwnership()` dice si es SU torneo, que es por fila y la
 *     tabla de permisos no puede expresar.
 *
 * Las tres tienen que dar verde. Ver docs/documentacion_tecnica.md → «Las tres
 * compuertas de autorización».
 */
class PermisoService
{
    /** Las cuatro columnas de acción de la tabla. */
    public const ACCIONES = ['ver', 'crear', 'editar', 'eliminar'];

    private PermisoModel     $model;
    private AuditoriaService $auditoria;

    /** Caché por request: rol_id → slug → fila. Evita repetir la consulta. */
    private array $cache = [];

    private ?int $rolAdminId = null;

    public function __construct()
    {
        $this->model     = new PermisoModel();
        $this->auditoria = new AuditoriaService();
    }

    /** Id del rol administrador, buscado por nombre para no clavar un número. */
    public function rolAdministradorId(): int
    {
        if ($this->rolAdminId === null) {
            $rol = (new RolModel())->findByNombre('administrador');
            if ($rol === null) {
                throw new RuntimeException('No existe el rol «administrador» en la tabla roles.');
            }
            $this->rolAdminId = (int) $rol['id'];
        }
        return $this->rolAdminId;
    }

    /**
     * ¿El rol indicado puede hacer la acción sobre el módulo?
     *
     * @param int|null $rolId  null = visitante sin sesión: no puede nada.
     * @throws InvalidArgumentException si la acción no es una de las cuatro.
     *         Es un error de programación —un typo en la guarda— y tiene que
     *         romper, no negar en silencio.
     */
    public function puede(?int $rolId, string $modulo, string $accion): bool
    {
        if (!in_array($accion, self::ACCIONES, true)) {
            throw new InvalidArgumentException(
                "Acción «{$accion}» desconocida. Las válidas son: " . implode(', ', self::ACCIONES) . '.'
            );
        }

        if ($rolId === null) return false;
        if ($rolId === $this->rolAdministradorId()) return true;

        if (!isset($this->cache[$rolId])) {
            $this->cache[$rolId] = $this->model->porRol($rolId);
        }

        $fila = $this->cache[$rolId][$modulo] ?? null;
        if ($fila === null) return false;

        return (int) $fila['puede_' . $accion] === 1;
    }

    /** Lo mismo, para el usuario que tiene la sesión abierta. */
    public function puedeUsuarioActual(string $modulo, string $accion): bool
    {
        $usuario = Auth::user();
        if ($usuario === null) return false;

        return $this->puede((int) $usuario['rol_id'], $modulo, $accion);
    }

    // ─── Pantalla de administración ─────────────────────────────────────────

    /**
     * Todo lo que la pantalla necesita para dibujar la matriz.
     *
     * @return array{roles: array, modulos: array, permisos: array, rol_admin_id: int}
     */
    public function matrizCompleta(): array
    {
        return [
            'roles'        => (new RolModel())->findAll(),
            'modulos'      => (new ModuloModel())->findAll(),
            'permisos'     => $this->model->matriz(),
            'rol_admin_id' => $this->rolAdministradorId(),
        ];
    }

    /**
     * Guarda la matriz enviada desde la pantalla.
     *
     * Recibe el formulario tal como llega: $marcados[rol_id][modulo_slug][accion].
     * Solo se tocan los roles distintos del administrador y los módulos que
     * existen en el catálogo; cualquier otra clave se ignora en vez de creer en
     * lo que venga del navegador.
     *
     * @param array $marcados Casillas tildadas del formulario.
     * @return int Cantidad de filas rol × módulo que quedaron con algún permiso.
     */
    public function guardarMatriz(array $marcados): int
    {
        $rolAdmin = $this->rolAdministradorId();
        $roles    = (new RolModel())->findAll();
        $modulos  = (new ModuloModel())->findAll();

        $antes = $this->model->matriz();
        $guardadas = 0;

        foreach ($roles as $rol) {
            $rolId = (int) $rol['id'];
            if ($rolId === $rolAdmin) continue; // §5.1: control completo, sin filas

            foreach ($modulos as $modulo) {
                $slug  = (string) $modulo['slug'];
                $flags = [];
                foreach (self::ACCIONES as $accion) {
                    $flags[$accion] = !empty($marcados[$rolId][$slug][$accion]);
                }

                // Sin ninguna acción marcada no se guarda una fila en cero: se
                // borra, y la ausencia de fila ya significa «no puede nada».
                if (!in_array(true, $flags, true)) {
                    $this->model->borrar($rolId, $slug);
                    continue;
                }

                $this->model->guardar($rolId, $slug, $flags['ver'], $flags['crear'], $flags['editar'], $flags['eliminar']);
                $guardadas++;
            }
        }

        $this->cache = [];
        $despues = $this->model->matriz();

        // Guardar sin haber cambiado nada no deja registro: el historial de
        // auditoría sirve para saber QUÉ cambió, y una fila «anterior igual a
        // nuevo» solo agrega ruido a la bandeja del administrador.
        $anteriorPlano = $this->paraAuditoria($antes);
        $nuevoPlano    = $this->paraAuditoria($despues);

        if ($anteriorPlano !== $nuevoPlano) {
            $this->auditoria->log(
                'actualizar_permisos',
                'permisos',
                0,
                'Matriz de permisos por rol actualizada desde el panel de administración.',
                $anteriorPlano,
                $nuevoPlano
            );
        }

        return $guardadas;
    }

    /**
     * Aplana la matriz a "rol:modulo" => "VCE-" para que el registro de
     * auditoría se pueda leer de un vistazo. Las columnas valor_anterior y
     * valor_nuevo son JSON, así que se les pasa un array.
     *
     * Las iniciales se fijan a mano porque «editar» y «eliminar» empiezan igual.
     */
    private const INICIALES = ['ver' => 'V', 'crear' => 'C', 'editar' => 'E', 'eliminar' => 'X'];

    private function paraAuditoria(array $matriz): array
    {
        $plano = [];
        foreach ($matriz as $rolId => $porModulo) {
            foreach ($porModulo as $slug => $fila) {
                $letras = '';
                foreach (self::ACCIONES as $accion) {
                    $letras .= ((int) $fila['puede_' . $accion] === 1) ? self::INICIALES[$accion] : '-';
                }
                $plano["{$rolId}:{$slug}"] = $letras;
            }
        }
        ksort($plano);
        return $plano;
    }
}
