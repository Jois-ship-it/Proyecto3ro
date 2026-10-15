<?php
declare(strict_types=1);

class Auth
{
    /** ¿Ya se comparó la sesión con la base en este pedido? Una sola vez por pedido alcanza. */
    private static bool $revalidada = false;

    /**
     * ¿Hay alguien con la sesión iniciada Y con la cuenta en regla?
     *
     * Antes bastaba con que la sesión tuviera un `user_id`: el rol y el estado salían de lo que
     * se guardó al iniciar sesión. Una cuenta bloqueada, desactivada, borrada o con el rol
     * cambiado seguía operando con los permisos de antes hasta que la sesión expirara. Ahora, en
     * cada pedido se compara con la base (una consulta por clave primaria).
     */
    public static function isLoggedIn(): bool
    {
        return Session::has('user_id') && self::revalidar();
    }

    public static function user(): ?array
    {
        if (!self::isLoggedIn()) return null;
        return [
            'id'     => Session::get('user_id'),
            'nombre' => Session::get('user_nombre'),
            'email'  => Session::get('user_email'),
            'rol'    => Session::get('user_rol'),
            'rol_id' => Session::get('user_rol_id'),
        ];
    }

    public static function id(): ?int
    {
        return self::isLoggedIn() ? Session::get('user_id') : null;
    }

    public static function rol(): ?string
    {
        return self::isLoggedIn() ? Session::get('user_rol') : null;
    }

    /**
     * Compara la sesión con la cuenta que hay en la base. Si la cuenta ya no está en regla, cierra
     * la sesión y deja un aviso; si lo está, la base manda: el rol, el nombre y el email de la
     * sesión se ponen al día. Devuelve si la sesión sigue en pie.
     */
    private static function revalidar(): bool
    {
        if (self::$revalidada) return Session::has('user_id');
        self::$revalidada = true;

        $cuenta = (new UsuarioModel())->findByIdConRol((int) Session::get('user_id'));
        $motivo = self::motivoDeExpulsion($cuenta);

        if ($motivo !== null) {
            Session::destroy();
            Session::start();
            Session::flash('warning', $motivo);
            Database::fijarUsuarioApp(null);
            return false;
        }

        Session::set('user_nombre', $cuenta['nombre']);
        Session::set('user_email',  $cuenta['email']);
        Session::set('user_rol',    $cuenta['rol_nombre']);
        Session::set('user_rol_id', (int) $cuenta['rol_id']);
        return true;
    }

    /**
     * Por qué una sesión ya no puede seguir, o null si puede. Solo una cuenta `activa` sigue operando.
     *
     * @param array|null $cuenta la fila de usuarios con `rol_nombre`, o null si ya no existe
     */
    public static function motivoDeExpulsion(?array $cuenta): ?string
    {
        if ($cuenta === null) {
            return 'Tu cuenta ya no existe. Si creés que es un error, contactá al administrador.';
        }
        return match ($cuenta['estado'] ?? '') {
            'activo'    => null,
            'bloqueada' => 'Tu cuenta fue bloqueada, así que se cerró tu sesión. Contactá al administrador.',
            default     => 'Tu cuenta ya no está activa, así que se cerró tu sesión. Contactá al administrador.',
        };
    }

    /** Hace que el próximo pedido a la sesión vuelva a consultar la base. Para los tests. */
    public static function olvidarRevalidacion(): void
    {
        self::$revalidada = false;
    }

    public static function isAdmin(): bool
    {
        return self::rol() === 'administrador';
    }

    public static function isOrganizador(): bool
    {
        return in_array(self::rol(), ['administrador', 'organizador'], true);
    }

    public static function isParticipante(): bool
    {
        return self::rol() === 'participante';
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            Session::flash('error', 'Debés iniciar sesión para acceder a esta sección.');
            throw new SesionRequerida('Hace falta iniciar sesión.');
        }
    }

    public static function requireRole(array|string $roles): void
    {
        self::requireLogin();
        $roles = (array) $roles;
        if (!in_array(self::rol(), $roles, true)) {
            throw new AccesoDenegado('Tu rol no tiene acceso a esta sección.');
        }
    }

    public static function requireAdmin(): void
    {
        self::requireRole(['administrador']);
    }

    public static function requireOrganizador(): void
    {
        self::requireRole(['administrador', 'organizador']);
    }

    public static function login(array $userData): void
    {
        Session::regenerate();
        self::$revalidada = true;   // acaba de comprobarse la cuenta: no hace falta volver a consultarla
        Session::set('_ultima_actividad', time());
        Session::set('user_id',     (int)  $userData['id']);
        Session::set('user_nombre',        $userData['nombre']);
        Session::set('user_email',         $userData['email']);
        Session::set('user_rol',           $userData['rol_nombre']);
        Session::set('user_rol_id', (int)  $userData['rol_id']);
        // Desde acá, lo que se escriba en la base queda atribuido a este usuario.
        Database::fijarUsuarioApp((int) $userData['id']);
    }

    public static function logout(): void
    {
        Session::reiniciar();          // sesión nueva y vacía (una sola cookie): en ella viaja el aviso de despedida
        self::$revalidada = false;
        Database::fijarUsuarioApp(null);
    }
}
