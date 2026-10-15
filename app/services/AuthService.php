<?php
declare(strict_types=1);

class AuthService
{
    /**
     * Hash de una clave cualquiera que nadie conoce. Cuando el email no existe se verifica contra
     * este, para que "no existe" tarde lo mismo que "clave incorrecta": sin esto, responder más
     * rápido delataba qué emails tienen cuenta.
     */
    private const CLAVE_FALSA = '$2y$12$t3uY5kcpc9V/opoJac3TVuim/vvZ/fIBfdTP9GxEYNb6m9A6DGm5S';

    /** Lo que dura el bloqueo del último administrador si el ajuste dice «hasta que lo desbloqueen». */
    private const MINUTOS_BLOQUEO_ULTIMO_ADMIN = 15;

    private UsuarioModel $usuarioModel;
    private IntentoLoginModel $intentos;
    private ConfiguracionSistemaService $config;
    private AuditoriaService $auditoria;

    public function __construct()
    {
        $this->usuarioModel = new UsuarioModel();
        $this->intentos     = new IntentoLoginModel();
        $this->config       = new ConfiguracionSistemaService();
        $this->auditoria    = new AuditoriaService();
    }

    /**
     * Autentica al usuario. Devuelve el registro de usuario o lanza excepción.
     *
     * Los límites (cuántos intentos, cuánto dura el bloqueo, cuántos por IP) salen de
     * `configuracion_sistema`; ver ConfiguracionSistemaService.
     *
     * Esta función NO va dentro de una transacción a propósito: cuenta el intento fallido y
     * después lanza el error, y la excepción deshacería el contador.
     */
    public function login(string $email, string $password): array
    {
        if (empty($email) || empty($password)) {
            throw new ErrorNegocio('Email y contraseña son obligatorios.');
        }

        // Primero la IP: si ya agotó sus intentos ni siquiera se mira la cuenta.
        $ip = $this->ipCliente();
        $this->asegurarQueLaIpNoExcedio($ip);

        // Buscar usuario sin filtrar por estado para controlar intentos fallidos
        $user = $this->usuarioModel->findByEmailAnyStatus($email);

        // Si no existe el usuario, registrar intento y devolver mensaje genérico
        if (!$user) {
            password_verify($password, self::CLAVE_FALSA);   // mismo costo que con una cuenta real
            $this->registrarFallo($ip, $email);
            $this->auditoria->log('login_fallido', 'usuarios', 0, "Intento fallido para: {$email}");
            throw new ErrorNegocio('Email o contraseña incorrectos.');
        }

        if (($user['estado'] ?? '') === 'bloqueada') {
            if ((int) ($user['bloqueo_vencido'] ?? 0) === 1) {
                // El bloqueo tenía fecha de fin y ya pasó: la cuenta se desbloquea sola.
                $this->usuarioModel->unlockAccount((int) $user['id']);
                $this->auditoria->log('cuenta_desbloqueada_auto', 'usuarios', (int) $user['id'],
                    "Venció el bloqueo temporal de la cuenta: {$email}");
                $user['estado'] = 'activo';
                $user['failed_attempts'] = 0;
            } else {
                // Sigue bloqueada: se la deniega sin mirar la contraseña, y cuenta contra la IP.
                $this->registrarFallo($ip, $email);
                $this->auditoria->log('login_intento_en_bloqueada', 'usuarios', (int) $user['id'], "Intento de login en cuenta bloqueada: {$email}");
                throw new ErrorNegocio($this->mensajeDeCuentaBloqueada($user));
            }
        }

        // Verificar contraseña
        if (!password_verify($password, $user['password_hash'])) {
            $this->registrarFallo($ip, $email);
            // Incrementar contador de intentos fallidos
            $attempts = $this->usuarioModel->incrementFailedAttempts((int)$user['id']);
            $this->auditoria->log('login_fallido', 'usuarios', (int)$user['id'], "Intento fallido para usuario id: {$user['id']}");

            if ($attempts >= $this->config->getInt('login_max_intentos', 1)) {
                $this->bloquearPorIntentos($user);
            }

            throw new ErrorNegocio('Email o contraseña incorrectos.');
        }

        // Contraseña correcta; comprobar estado
        if (($user['estado'] ?? '') !== 'activo') {
            throw new ErrorNegocio('Tu cuenta no está activa. Contactá al administrador.');
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

    // ─── Límites ────────────────────────────────────────────────────────────

    /**
     * La dirección de quien hace el pedido, tal como la ve Apache. No se confía en cabeceras
     * como X-Forwarded-For: las escribe el cliente y quien ataca pondría una IP distinta en cada
     * intento para no llegar nunca al límite.
     */
    private function ipCliente(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private function asegurarQueLaIpNoExcedio(string $ip): void
    {
        $maximo  = $this->config->getInt('login_max_intentos_ip', 1);
        $ventana = $this->config->getInt('login_ventana_minutos', 1);

        if ($this->intentos->contarPorIp($ip, $ventana) >= $maximo) {
            throw new ErrorNegocio('Hubo demasiados intentos fallidos desde tu conexión. Esperá unos minutos antes de volver a intentar.');
        }
    }

    /** Anota un inicio de sesión fallido contra la IP, y de paso limpia lo viejo (de vez en cuando). */
    private function registrarFallo(string $ip, string $email): void
    {
        $this->intentos->registrar($ip, strtolower(trim($email)));
        if (random_int(1, 25) === 1) {
            $this->intentos->purgarAnteriores(24);
        }
    }

    /**
     * Bloquea la cuenta por exceso de intentos, por el tiempo que dice el ajuste (0 = hasta que
     * un administrador la desbloquee). El único administrador activo nunca queda bloqueado para
     * siempre: cualquiera que conozca su email podría dejar al sistema sin quien lo administre.
     */
    private function bloquearPorIntentos(array $user): void
    {
        $minutos = $this->config->getInt('login_minutos_bloqueo', 0);
        if ($minutos === 0 && ($user['rol_nombre'] ?? '') === 'administrador'
            && $this->usuarioModel->contarAdministradoresActivos((int) $user['id']) === 0) {
            $minutos = self::MINUTOS_BLOQUEO_ULTIMO_ADMIN;
        }

        // Solo bloquea cuentas activas: una pendiente o rechazada que falla la clave no pasa a «bloqueada».
        if ($this->usuarioModel->lockAccount((int) $user['id'], $minutos)) {
            $this->auditoria->log('cuenta_bloqueada', 'usuarios', (int) $user['id'],
                $minutos > 0
                    ? "Cuenta bloqueada por excesivos intentos de login, por {$minutos} minutos"
                    : 'Cuenta bloqueada por excesivos intentos de login, hasta que un administrador la desbloquee');
        }
    }

    private function mensajeDeCuentaBloqueada(array $user): string
    {
        if (empty($user['bloqueada_hasta'])) {
            return 'Tu cuenta está bloqueada. Contactá al administrador para que la desbloquee.';
        }
        $hora = date('H:i', (int) strtotime((string) $user['bloqueada_hasta']));
        return "Tu cuenta está bloqueada por varios intentos fallidos. Podés volver a intentarlo a las {$hora}, "
             . 'o pedirle a un administrador que la desbloquee.';
    }
}
