<?php
declare(strict_types=1);

class UsuarioService
{
    private UsuarioModel   $model;
    private RolModel       $rolModel;
    private AuditoriaService $auditoria;

    public function __construct()
    {
        $this->model     = new UsuarioModel();
        $this->rolModel  = new RolModel();
        $this->auditoria = new AuditoriaService();
    }

    /** Solo usuarios con rol organizador (este apartado es exclusivo de organizadores). */
    public function getOrganizadores(): array
    {
        return $this->model->findByRolNombre('organizador');
    }

    public function getById(int $id): ?array
    {
        return $this->model->findByIdConRol($id);
    }

    /** ID del rol 'organizador' (para forzar el rol al crear desde este apartado). */
    public function getRolIdOrganizador(): int
    {
        $rol = $this->rolModel->findByNombre('organizador');
        if (!$rol) throw new RuntimeException('El rol organizador no existe en el sistema.');
        return (int)$rol['id'];
    }

    public function crear(array $datos): int
    {
        $this->validar($datos);

        if ($this->model->emailExiste($datos['email'])) {
            throw new RuntimeException("El email ya está registrado.");
        }

        $id = $this->model->insert([
            'rol_id'        => (int)$datos['rol_id'],
            'nombre'        => trim($datos['nombre']),
            'email'         => trim(strtolower($datos['email'])),
            'password_hash' => password_hash($datos['password'], PASSWORD_BCRYPT, ['cost' => 12]),
            'estado'        => $datos['estado'] ?? 'activo',
        ]);

        $this->auditoria->log('crear_usuario', 'usuarios', $id, "Usuario creado: {$datos['email']}");
        return $id;
    }

    public function editar(int $id, array $datos): void
    {
        $this->validar($datos, $id);

        if ($this->model->emailExiste($datos['email'], $id)) {
            throw new RuntimeException("El email ya está registrado por otro usuario.");
        }

        $actual = $this->model->findById($id);
        if (!$actual) throw new RuntimeException("Usuario no encontrado.");

        $update = [
            'rol_id' => (int)$datos['rol_id'],
            'nombre' => trim($datos['nombre']),
            'email'  => trim(strtolower($datos['email'])),
            // Defensa en profundidad: el formulario ya bloquea el <select> cuando la
            // cuenta está bloqueada, pero si llegara un estado distinto (POST manipulado),
            // se ignora: el desbloqueo solo puede hacerse vía desbloquear().
            'estado' => $actual['estado'] === 'bloqueada' ? 'bloqueada' : ($datos['estado'] ?? 'activo'),
        ];

        if (!empty($datos['password'])) {
            $update['password_hash'] = password_hash($datos['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        }

        $this->model->update($id, $update);
        $this->auditoria->log('editar_usuario', 'usuarios', $id, "Usuario editado: {$datos['email']}");
    }

    /** Activa/desactiva un organizador de forma reversible. Devuelve el nuevo estado. */
    public function toggleActivo(int $id): string
    {
        $usuario = $this->model->findById($id);
        if (!$usuario) throw new RuntimeException('Organizador no encontrado.');
        if ((int)$id === (int)Auth::id()) {
            throw new RuntimeException('No podés cambiar el estado de tu propia cuenta.');
        }
        // No permitir activar una cuenta que esté bloqueada sin pasar por desbloquear().
        if (($usuario['estado'] ?? '') === 'bloqueada') {
            throw new RuntimeException('Esta cuenta está bloqueada. Desbloqueala primero para poder activarla.');
        }
        $nuevo = $usuario['estado'] === 'activo' ? 'inactivo' : 'activo';
        $this->model->update($id, ['estado' => $nuevo]);
        $this->auditoria->log(
            $nuevo === 'activo' ? 'activar_usuario' : 'desactivar_usuario',
            'usuarios', $id,
            ($nuevo === 'activo' ? 'Organizador reactivado: ' : 'Organizador desactivado: ') . $usuario['email']
        );
        return $nuevo;
    }

    /** Desbloquea una cuenta (poniendo estado activo y reseteando intentos). */
    public function desbloquear(int $id): void
    {
        $usuario = $this->model->findById($id);
        if (!$usuario) throw new RuntimeException('Usuario no encontrado.');
        $this->model->unlockAccount($id);
        $this->auditoria->log('desbloquear_cuenta', 'usuarios', $id, 'Cuenta desbloqueada por administrador');
    }

    /** Bloquea manualmente una cuenta desde el administrador. */
    public function bloquear(int $id): void
    {
        $usuario = $this->model->findById($id);
        if (!$usuario) throw new RuntimeException('Usuario no encontrado.');
        $this->model->lockAccount($id);
        $this->auditoria->log('bloquear_cuenta', 'usuarios', $id, 'Cuenta bloqueada manualmente por administrador');
    }

    private function validar(array $datos, int $excludeId = 0): void
    {
        if (empty($datos['nombre'])) throw new RuntimeException('El nombre es obligatorio.');
        if (empty($datos['email']) || !Validator::email($datos['email'])) {
            throw new RuntimeException('Email inválido.');
        }
        if (empty($datos['rol_id'])) throw new RuntimeException('El rol es obligatorio.');
        if ($excludeId === 0 && empty($datos['password'])) {
            throw new RuntimeException('La contraseña es obligatoria al crear un usuario.');
        }
        // Política robusta de contraseñas (mayús, minús, número, símbolo, longitud)
        if (!empty($datos['password'])) {
            Validator::assertPassword((string)$datos['password']);
        }
    }
}
