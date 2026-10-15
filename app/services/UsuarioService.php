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

    /** Las cuentas de UN rol (los apartados «Organizadores» y «Administradores» son uno por rol). */
    public function getPorRol(string $rol): array
    {
        return $this->model->findByRolNombre($rol);
    }

    /** Una página de las cuentas de UN rol, con búsqueda y filtro por estado, y el total que cumple el filtro. */
    public function buscarPorRol(string $rol, array $filtros, int $limite, int $desde): array
    {
        return $this->model->buscarPorRol($rol, $filtros, $limite, $desde);
    }

    public function contarPorRol(string $rol, array $filtros): int
    {
        return $this->model->contarPorRol($rol, $filtros);
    }

    public function getById(int $id): ?array
    {
        return $this->model->findByIdConRol($id);
    }

    /** ID de un rol por su nombre (para forzar el rol al crear desde el apartado de ese rol). */
    public function getRolId(string $nombre): int
    {
        $rol = $this->rolModel->findByNombre($nombre);
        if (!$rol) throw new ErrorNegocio("El rol {$nombre} no existe en el sistema.");
        return (int)$rol['id'];
    }

    /**
     * El id del administrador activo si es el ÚNICO; 0 si hay más de uno o ninguno. La lista de
     * administradores lo usa para no ofrecer desactivarlo ni bloquearlo (el servicio igual lo impide).
     */
    public function unicoAdministradorActivoId(): int
    {
        $ids = $this->model->idsAdministradoresActivos();
        return count($ids) === 1 ? $ids[0] : 0;
    }

    public function crear(array $datos): int
    {
        $this->validar($datos);

        if ($this->model->emailExiste($datos['email'])) {
            throw new ErrorNegocio("El email ya está registrado.");
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
            throw new ErrorNegocio("El email ya está registrado por otro usuario.");
        }

        $actual = $this->model->findByIdConRol($id);
        if (!$actual) throw new ErrorNegocio("Usuario no encontrado.");

        // Defensa en profundidad: el formulario ya bloquea el <select> cuando la
        // cuenta está bloqueada, pero si llegara un estado distinto (POST manipulado),
        // se ignora: el desbloqueo solo puede hacerse vía desbloquear().
        $estadoNuevo = $actual['estado'] === 'bloqueada' ? 'bloqueada' : ($datos['estado'] ?? 'activo');
        $rolNuevo    = (string) $this->rolModel->findById((int) $datos['rol_id'])['nombre'];

        // Un administrador no puede degradarse ni desactivarse a sí mismo, y el sistema no puede
        // quedarse sin ningún administrador activo (si no, nadie podría volver a entrar a administrar).
        if ($actual['rol_nombre'] === 'administrador' && $actual['estado'] === 'activo'
            && ($rolNuevo !== 'administrador' || $estadoNuevo !== 'activo')) {
            if ($id === (int) Auth::id()) {
                throw new ErrorNegocio('No podés quitarte el rol de administrador ni desactivar tu propia cuenta.');
            }
            $this->protegerUltimoAdministrador($actual, 'cambiar el rol o desactivar la cuenta');
        }

        $update = [
            'rol_id' => (int)$datos['rol_id'],
            'nombre' => trim($datos['nombre']),
            'email'  => trim(strtolower($datos['email'])),
            'estado' => $estadoNuevo,
        ];

        if (!empty($datos['password'])) {
            $update['password_hash'] = password_hash($datos['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        }

        $this->model->update($id, $update);
        $this->auditoria->log('editar_usuario', 'usuarios', $id, "Usuario editado: {$datos['email']}");
    }

    /** Activa/desactiva una cuenta (organizador o administrador) de forma reversible. Devuelve el nuevo estado. */
    public function toggleActivo(int $id): string
    {
        $usuario = $this->model->findByIdConRol($id);
        if (!$usuario) throw new ErrorNegocio('Usuario no encontrado.');
        if ((int)$id === (int)Auth::id()) {
            throw new ErrorNegocio('No podés cambiar el estado de tu propia cuenta.');
        }
        // No permitir activar una cuenta que esté bloqueada sin pasar por desbloquear().
        if (($usuario['estado'] ?? '') === 'bloqueada') {
            throw new ErrorNegocio('Esta cuenta está bloqueada. Desbloqueala primero para poder activarla.');
        }
        $nuevo = $usuario['estado'] === 'activo' ? 'inactivo' : 'activo';
        if ($nuevo === 'inactivo') {
            $this->protegerUltimoAdministrador($usuario, 'desactivar la cuenta');
        }
        $this->model->update($id, ['estado' => $nuevo]);
        $this->auditoria->log(
            $nuevo === 'activo' ? 'activar_usuario' : 'desactivar_usuario',
            'usuarios', $id,
            ($nuevo === 'activo' ? 'Cuenta reactivada: ' : 'Cuenta desactivada: ') . $usuario['email']
        );
        return $nuevo;
    }

    /**
     * Desbloquea una cuenta BLOQUEADA (vuelve a activa y se reinician los intentos).
     *
     * Antes la ponía `activa` fuera cual fuese su estado: desbloquear una cuenta pendiente de
     * aprobación o rechazada la activaba sin pasar por la aprobación.
     */
    public function desbloquear(int $id): void
    {
        $usuario = $this->model->findById($id);
        if (!$usuario) throw new ErrorNegocio('Usuario no encontrado.');
        if (!$this->model->unlockAccount($id)) {
            throw new ErrorNegocio('Esta cuenta no está bloqueada, así que no hay nada que desbloquear.');
        }
        $this->auditoria->log('desbloquear_cuenta', 'usuarios', $id, 'Cuenta desbloqueada por administrador');
    }

    /**
     * Bloquea manualmente una cuenta ACTIVA, hasta que un administrador la desbloquee. No se puede
     * bloquear la propia cuenta ni la del único administrador activo.
     */
    public function bloquear(int $id): void
    {
        $usuario = $this->model->findByIdConRol($id);
        if (!$usuario) throw new ErrorNegocio('Usuario no encontrado.');
        if ($id === (int) Auth::id()) {
            throw new ErrorNegocio('No podés bloquear tu propia cuenta.');
        }
        if ($usuario['estado'] !== 'activo') {
            throw new ErrorNegocio('Solo se puede bloquear una cuenta activa.');
        }
        $this->protegerUltimoAdministrador($usuario, 'bloquear la cuenta');

        $this->model->lockAccount($id);
        $this->auditoria->log('bloquear_cuenta', 'usuarios', $id, 'Cuenta bloqueada manualmente por administrador');
    }

    /**
     * El sistema no puede quedarse sin un administrador activo: sin él nadie podría volver a
     * entrar a administrar, y desde la interfaz no hay cómo recuperarlo.
     *
     * @param array  $usuario con `rol_nombre`
     * @param string $accion  lo que se intentaba, para el mensaje: «bloquear la cuenta», «desactivar la cuenta»…
     */
    private function protegerUltimoAdministrador(array $usuario, string $accion): void
    {
        if (($usuario['rol_nombre'] ?? '') !== 'administrador' || ($usuario['estado'] ?? '') !== 'activo') {
            return;
        }
        if ($this->model->contarAdministradoresActivos((int) $usuario['id']) === 0) {
            throw new ErrorNegocio("No se puede {$accion} del único administrador activo: el sistema quedaría sin nadie que lo administre.");
        }
    }

    private function validar(array $datos, int $excludeId = 0): void
    {
        if (empty($datos['nombre'])) throw new ErrorNegocio('El nombre es obligatorio.');
        Validator::assertLargos([
            'El nombre' => [$datos['nombre'], 100],
            'El email'  => [$datos['email'] ?? '', 150],
        ]);
        if (empty($datos['email']) || !Validator::email($datos['email'])) {
            throw new ErrorNegocio('Email inválido.');
        }
        if (empty($datos['rol_id'])) throw new ErrorNegocio('El rol es obligatorio.');
        if (!$this->rolModel->findById((int) $datos['rol_id'])) throw new ErrorNegocio('El rol elegido no existe.');
        if ($excludeId === 0 && empty($datos['password'])) {
            throw new ErrorNegocio('La contraseña es obligatoria al crear un usuario.');
        }
        // Política robusta de contraseñas (mayús, minús, número, símbolo, longitud)
        if (!empty($datos['password'])) {
            Validator::assertPassword((string)$datos['password']);
        }
    }
}
