<?php
declare(strict_types=1);

class AuthService
{
    private UsuarioModel  $usuarioModel;
    private AuditoriaService $auditoria;

    public function __construct()
    {
        $this->usuarioModel = new UsuarioModel();
        $this->auditoria    = new AuditoriaService();
    }

    /**
     * Autentica al usuario. Devuelve el registro de usuario o lanza excepción.
     */
    public function login(string $email, string $password): array
    {
        if (empty($email) || empty($password)) {
            throw new RuntimeException('Email y contraseña son obligatorios.');
        }

        $user = $this->usuarioModel->findByEmail($email);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            // Registro de intento fallido (sin revelar si el usuario existe)
            $this->auditoria->log(
                'login_fallido',
                'usuarios', 0,
                "Intento fallido para: {$email}",
                null, null, null
            );
            throw new RuntimeException('Email o contraseña incorrectos.');
        }

        if ($user['estado'] !== 'activo') {
            throw new RuntimeException('Tu cuenta está inactiva. Contactá al administrador.');
        }

        Auth::login($user);

        $this->auditoria->log(
            'login_exitoso',
            'usuarios', (int)$user['id'],
            "Inicio de sesión: {$user['email']}",
            null, null, (int)$user['id']
        );

        return $user;
    }

    public function logout(): void
    {
        $userId = Auth::id();
        $email  = Auth::user()['email'] ?? 'desconocido';
        Auth::logout();
        $this->auditoria->log('logout', 'usuarios', (int)$userId, "Cierre de sesión: {$email}", null, null, $userId);
    }
}
