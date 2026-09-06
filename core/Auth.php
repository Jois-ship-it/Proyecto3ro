<?php
declare(strict_types=1);

class Auth
{
    public static function isLoggedIn(): bool
    {
        return Session::has('user_id');
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
        return Session::get('user_id');
    }

    public static function rol(): ?string
    {
        return Session::get('user_rol');
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
            header('Location: /login');
            exit;
        }
    }

    public static function requireRole(array|string $roles): void
    {
        self::requireLogin();
        $roles = (array) $roles;
        if (!in_array(self::rol(), $roles, true)) {
            http_response_code(403);
            View::render('shared/403', ['user' => self::user()], 'public');
            exit;
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
        Session::set('user_id',     (int)  $userData['id']);
        Session::set('user_nombre',        $userData['nombre']);
        Session::set('user_email',         $userData['email']);
        Session::set('user_rol',           $userData['rol_nombre']);
        Session::set('user_rol_id', (int)  $userData['rol_id']);
    }

    public static function logout(): void
    {
        Session::destroy();
    }
}
