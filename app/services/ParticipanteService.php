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

    public function crear(array $d): int
    {
        $this->validar($d);
        if (!empty($d['documento']) && $this->model->documentoExiste(trim($d['documento']))) {
            throw new RuntimeException('Ya existe un participante con ese documento.');
        }
        $id = $this->model->insert([
            'nombre'    => trim($d['nombre']),
            'documento' => trim($d['documento'] ?? ''),
            'nick'      => trim($d['nick'] ?? ''),
            'email'     => trim(strtolower($d['email'] ?? '')),
            'telefono'  => trim($d['telefono'] ?? ''),
            'estado'    => $d['estado'] ?? 'activo',
        ]);
        $this->auditoria->log('crear_participante', 'participantes', $id, "Participante creado: {$d['nombre']}");
        return $id;
    }

    public function editar(int $id, array $d): void
    {
        $this->validar($d);
        if (!empty($d['documento']) && $this->model->documentoExiste(trim($d['documento']), $id)) {
            throw new RuntimeException('Ya existe otro participante con ese documento.');
        }
        $p = $this->model->findById($id);
        // Sincronizar la cuenta vinculada (si existe) con nombre/email
        if ($p && !empty($p['usuario_id'])) {
            $this->sincronizarCuenta((int)$p['usuario_id'], trim($d['nombre']), trim(strtolower($d['email'] ?? '')));
        }
        $this->model->update($id, [
            'nombre'    => trim($d['nombre']),
            'documento' => trim($d['documento'] ?? ''),
            'nick'      => trim($d['nick'] ?? ''),
            'email'     => trim(strtolower($d['email'] ?? '')),
            'telefono'  => trim($d['telefono'] ?? ''),
            'estado'    => $d['estado'] ?? 'activo',
        ]);
        $this->auditoria->log('editar_participante', 'participantes', $id, "Participante editado: {$d['nombre']}");
    }

    /**
     * Autogestión del propio perfil (participante): solo campos personales.
     * Preserva documento y estado. Soporta 'foto' (nombre de archivo) si se incluye.
     */
    public function actualizarPerfil(int $id, array $d): void
    {
        $p = $this->model->findById($id);
        if (!$p) throw new RuntimeException('Perfil no encontrado.');
        $nombre = trim($d['nombre'] ?? '');
        $email  = trim(strtolower($d['email'] ?? ''));
        if ($nombre === '') throw new RuntimeException('El nombre es obligatorio.');

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
                throw new RuntimeException('Ese email ya está en uso por otra cuenta.');
            }
            $update['email'] = $email;
        }
        $um->update($usuarioId, $update);
    }

    public function eliminar(int $id): void
    {
        $p = $this->model->findById($id);
        if (!$p) throw new RuntimeException('Participante no encontrado.');
        $this->model->update($id, ['estado' => 'inactivo']);
        $this->auditoria->log('eliminar_participante', 'participantes', $id, "Participante desactivado: {$p['nombre']}");
    }

    /** Activa/desactiva de forma reversible (no borra: preserva el historial). Devuelve el nuevo estado. */
    public function toggleActivo(int $id): string
    {
        $p = $this->model->findById($id);
        if (!$p) throw new RuntimeException('Participante no encontrado.');
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
        if (!$p) throw new RuntimeException('Participante no encontrado.');

        // Borrar foto física si existe
        if (!empty($p['foto'])) {
            Upload::delete('avatars', $p['foto']);
        }

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
    }

    public function findNoInscritos(int $torneoId): array { return $this->model->findNoInscritos($torneoId); }

    private function validar(array $d): void
    {
        if (empty($d['nombre'])) throw new RuntimeException('El nombre es obligatorio.');
    }
}
