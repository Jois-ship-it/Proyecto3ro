<?php
declare(strict_types=1);

class ParticipanteService
{
    private ParticipanteModel $model;
    private AuditoriaService  $auditoria;

    public function __construct()
    {
        $this->model     = new ParticipanteModel();
        $this->auditoria = new AuditoriaService();
    }

    public function getAll(): array       { return $this->model->findAllConEquipo(); }
    public function getById(int $id): ?array { return $this->model->findByIdConEquipos($id); }

    /** Una página de la lista (con búsqueda y filtro por estado) y el total que cumple el filtro. */
    public function buscar(array $filtros, int $limite, int $desde): array { return $this->model->buscarConEquipo($filtros, $limite, $desde); }
    public function contar(array $filtros): int                            { return $this->model->contarBuscados($filtros); }

    // No hay crear(): un participante nace de su propio registro público, que
    // arma la cuenta y el perfil juntos (RegistroService::aprobar). El alta
    // manual desde el panel no existe, así que un alta sin cuenta asociada no es
    // un estado que el sistema pueda alcanzar.

    public function editar(int $id, array $d): void
    {
        // La cuenta de acceso y el perfil son la misma identidad: se cambian los dos o ninguno.
        Database::transaccion(fn() => $this->editarSinTransaccion($id, $d));
    }

    /** El cuerpo de editar(), separado para que corra entero dentro de una transacción. */
    private function editarSinTransaccion(int $id, array $d): void
    {
        $this->validar($d);
        $documento = trim((string)($d['documento'] ?? ''));
        if ($documento !== '' && $this->model->documentoExiste($documento, $id)) {
            throw new ErrorNegocio('Ya existe otro participante con ese documento.');
        }
        $p = $this->model->findById($id);
        if (!$p) throw new ErrorNegocio('Participante no encontrado.');
        // Sincronizar la cuenta vinculada (si existe) con nombre/email
        if (!empty($p['usuario_id'])) {
            $this->sincronizarCuenta((int)$p['usuario_id'], trim($d['nombre']), trim(strtolower($d['email'] ?? '')));
        }
        $this->model->update($id, [
            'nombre'    => trim($d['nombre']),
            // Sin documento se guarda NULL, no '': el índice único
            // uq_participantes_documento admite varios NULL pero no dos cadenas vacías.
            'documento' => $documento === '' ? null : $documento,
            'nick'      => trim($d['nick'] ?? ''),
            'email'     => trim(strtolower($d['email'] ?? '')),
            'telefono'  => trim($d['telefono'] ?? ''),
            // Defensa en profundidad: el formulario ya bloquea el <select> cuando el
            // participante está suspendido por bloqueo de cuenta; si llegara un estado
            // distinto (POST manipulado), se ignora — el desbloqueo solo puede hacerse
            // vía desbloquear().
            'estado'    => $p['estado'] === 'suspendido' ? 'suspendido' : ($d['estado'] ?? 'activo'),
        ]);
        $this->auditoria->log('editar_participante', 'participantes', $id, "Participante editado: {$d['nombre']}");
    }

    /**
     * Autogestión del propio perfil (participante): solo campos personales.
     * Preserva documento y estado. Soporta 'foto' (nombre de archivo) si se incluye.
     */
    public function actualizarPerfil(int $id, array $d): void
    {
        // La cuenta de acceso y el perfil son la misma identidad: se cambian los dos o ninguno.
        Database::transaccion(fn() => $this->actualizarPerfilSinTransaccion($id, $d));
    }

    /** El cuerpo de actualizarPerfil(), separado para que corra entero dentro de una transacción. */
    private function actualizarPerfilSinTransaccion(int $id, array $d): void
    {
        $p = $this->model->findById($id);
        if (!$p) throw new ErrorNegocio('Perfil no encontrado.');
        $nombre = trim($d['nombre'] ?? '');
        $email  = trim(strtolower($d['email'] ?? ''));
        if ($nombre === '') throw new ErrorNegocio('El nombre es obligatorio.');
        $this->validarLargos($d);

        // La cuenta de acceso (usuarios) y el perfil (participantes) son la
        // MISMA identidad: hay que mantenerlos sincronizados para que la
        // topbar/login y el perfil no muestren datos distintos.
        if (!empty($p['usuario_id'])) {
            $this->sincronizarCuenta((int)$p['usuario_id'], $nombre, $email);
        }

        $update = [
            'nombre'   => $nombre,
            'nick'     => trim($d['nick'] ?? ''),
            'email'    => $email,
            'telefono' => trim($d['telefono'] ?? ''),
        ];
        if (array_key_exists('foto', $d)) {
            $update['foto'] = $d['foto'];
        }
        $this->model->update($id, $update);
        $this->auditoria->log('editar_perfil', 'participantes', $id, "Perfil actualizado: {$nombre}");
    }

    /** Sincroniza la cuenta de acceso vinculada con el nombre/email del perfil. */
    private function sincronizarCuenta(int $usuarioId, string $nombre, string $email): void
    {
        $um = new UsuarioModel();
        $update = ['nombre' => $nombre];
        if ($email !== '') {
            if ($um->emailExiste($email, $usuarioId)) {
                throw new ErrorNegocio('Ese email ya está en uso por otra cuenta.');
            }
            $update['email'] = $email;
        }
        $um->update($usuarioId, $update);
    }

    /** Activa/desactiva de forma reversible (no borra: preserva el historial). Devuelve el nuevo estado. */
    public function toggleActivo(int $id): string
    {
        $p = $this->model->findById($id);
        if (!$p) throw new ErrorNegocio('Participante no encontrado.');
        // No permitir activar un participante que esté suspendido por bloqueo de cuenta.
        if (($p['estado'] ?? '') === 'suspendido') {
            throw new ErrorNegocio('Esta cuenta está bloqueada. Desbloqueala primero para poder activarla.');
        }
        $nuevo = $p['estado'] === 'activo' ? 'inactivo' : 'activo';
        $this->model->update($id, ['estado' => $nuevo]);
        $this->auditoria->log(
            $nuevo === 'activo' ? 'activar_participante' : 'desactivar_participante',
            'participantes', $id,
            ($nuevo === 'activo' ? 'Participante reactivado: ' : 'Participante desactivado: ') . $p['nombre']
        );
        return $nuevo;
    }

    /**
     * Habeas Data (ley 18331): anonimiza todos los datos personales del participante
     * y de la cuenta de usuario vinculada. El registro permanece por integridad
     * referencial pero sin ningún dato identificable.
     */
    public function anonimizarDatos(int $participanteId, int $usuarioId): void
    {
        $p = $this->model->findById($participanteId);
        if (!$p) throw new ErrorNegocio('Participante no encontrado.');

        // El perfil y la cuenta se anonimizan juntos: no puede quedar la persona
        // anonimizada a medias (el perfil sin datos y la cuenta todavía con su email).
        Database::transaccion(function () use ($participanteId, $usuarioId) {
            // Anonimizar el perfil del participante
            $this->model->update($participanteId, [
                'nombre'    => 'Participante eliminado',
                'documento' => null,
                'nick'      => null,
                'email'     => null,
                'telefono'  => null,
                'foto'      => null,
                'usuario_id'=> null,
                'estado'    => 'inactivo',
            ]);

            // Anonimizar la cuenta de login (no se puede eliminar si creó torneos)
            $um = new UsuarioModel();
            $um->update($usuarioId, [
                'nombre'        => 'Usuario eliminado',
                'email'         => 'eliminado_' . $usuarioId . '@anonimizado.local',
                'password_hash' => '*ELIMINADO',
                'estado'        => 'inactivo',
            ]);

            $this->auditoria->log(
                'habeas_data_anonimizar',
                'participantes',
                $participanteId,
                "Datos anonimizados por solicitud Habeas Data (ley 18331). Usuario ID: {$usuarioId}"
            );
        });

        // La foto se borra DESPUÉS de que la base quedó bien: borrar un archivo no se puede
        // deshacer, y si la base fallara antes la persona se quedaría con sus datos y sin foto.
        if (!empty($p['foto'])) {
            Upload::delete('avatars', $p['foto']);
        }
    }

    private function validar(array $d): void
    {
        if (empty($d['nombre'])) throw new ErrorNegocio('El nombre es obligatorio.');
        $this->validarLargos($d);
    }

    /** Ningún texto puede ser más largo que su columna: la base lo rechazaría con un error técnico. */
    private function validarLargos(array $d): void
    {
        Validator::assertLargos([
            'El nombre'    => [$d['nombre']    ?? '', 100],
            'El nick'      => [$d['nick']      ?? '', 80],
            'El email'     => [$d['email']     ?? '', 150],
            'El teléfono'  => [$d['telefono']  ?? '', 30],
            'El documento' => [$d['documento'] ?? '', 50],
        ]);
    }
}
