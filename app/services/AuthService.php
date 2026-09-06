<?php
declare(strict_types=1);

class AuthService
{
    private UsuarioModel  $usuarioModel;
    private AuditoriaService $auditoria;
    private const MAX_FAILED_ATTEMPTS = 5;

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

        // Buscar usuario sin filtrar por estado para controlar intentos fallidos
        $user = $this->usuarioModel->findByEmailAnyStatus($email);

        // Si no existe el usuario, registrar intento y devolver mensaje genérico
        if (!$user) {
            $this->auditoria->log('login_fallido', 'usuarios', 0, "Intento fallido para: {$email}");
            throw new RuntimeException('Email o contraseña incorrectos.');
        }

        // Si la cuenta ya está bloqueada, denegar inmediatamente
        if (($user['estado'] ?? '') === 'bloqueada') {
            $this->auditoria->log('login_intento_en_bloqueada', 'usuarios', (int)$user['id'], "Intento de login en cuenta bloqueada: {$email}");
            throw new RuntimeException('Tu cuenta ha sido bloqueada tras varios intentos. Contactá al administrador.');
        }

        // Verificar contraseña
        if (!password_verify($password, $user['password_hash'])) {
            // Incrementar contador de intentos fallidos
            $attempts = $this->usuarioModel->incrementFailedAttempts((int)$user['id']);
            $this->auditoria->log('login_fallido', 'usuarios', (int)$user['id'], "Intento fallido para usuario id: {$user['id']}");

            if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
                // Bloquear cuenta
                $this->usuarioModel->lockAccount((int)$user['id']);
                $this->auditoria->log('cuenta_bloqueada', 'usuarios', (int)$user['id'], "Cuenta bloqueada por excesivos intentos de login");
            }

            throw new RuntimeException('Email o contraseña incorrectos.');
        }

        // Contraseña correcta; comprobar estado
        if (($user['estado'] ?? '') !== 'activo') {
            throw new RuntimeException('Tu cuenta no está activa. Contactá al administrador.');
        }

        // Login exitoso: resetear contador de intentos y continuar
        $this->usuarioModel->resetFailedAttempts((int)$user['id']);

        Auth::login($user);

        $this->auditoria->log('login_exitoso', 'usuarios', (int)$user['id'], "Inicio de sesión: {$user['email']}", null, null, (int)$user['id']);

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
