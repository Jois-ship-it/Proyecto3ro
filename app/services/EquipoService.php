<?php
declare(strict_types=1);

class EquipoService
{
    private EquipoModel      $model;
    private AuditoriaService $auditoria;

    public function __construct()
    {
        $this->model     = new EquipoModel();
        $this->auditoria = new AuditoriaService();
    }

    public function getAll(): array            { return $this->model->findAllConConteo(); }
    public function getById(int $id): ?array   { return $this->model->findByIdConParticipantes($id); }
    public function findNoInscritos(int $t): array { return $this->model->findNoInscritos($t); }

    public function crear(array $d): int
    {
        $this->validar($d);
        if ($this->model->nombreExiste(trim($d['nombre']))) {
            throw new RuntimeException('Ya existe un equipo con ese nombre.');
        }
        $datos = [
            'nombre'      => trim($d['nombre']),
            'categoria'   => trim($d['categoria'] ?? ''),
            'disciplina'  => trim($d['disciplina'] ?? ''),
            'descripcion' => trim($d['descripcion'] ?? ''),
            'estado'      => $d['estado'] ?? 'activo',
        ];
        if (array_key_exists('logo', $d)) $datos['logo'] = $d['logo'];
        $id = $this->model->insert($datos);
        $this->auditoria->log('crear_equipo', 'equipos', $id, "Equipo creado: {$d['nombre']}");
        return $id;
    }

    public function editar(int $id, array $d): void
    {
        $this->validar($d);
        if ($this->model->nombreExiste(trim($d['nombre']), $id)) {
            throw new RuntimeException('Ya existe otro equipo con ese nombre.');
        }
        $datos = [
            'nombre'      => trim($d['nombre']),
            'categoria'   => trim($d['categoria'] ?? ''),
            'disciplina'  => trim($d['disciplina'] ?? ''),
            'descripcion' => trim($d['descripcion'] ?? ''),
            'estado'      => $d['estado'] ?? 'activo',
        ];
        if (array_key_exists('logo', $d)) $datos['logo'] = $d['logo'];
        $this->model->update($id, $datos);
        $this->auditoria->log('editar_equipo', 'equipos', $id, "Equipo editado: {$d['nombre']}");
    }

    public function eliminar(int $id): void
    {
        $e = $this->model->findById($id);
        if (!$e) throw new RuntimeException('Equipo no encontrado.');
        $this->model->update($id, ['estado' => 'inactivo']);
        $this->auditoria->log('eliminar_equipo', 'equipos', $id, "Equipo desactivado: {$e['nombre']}");
    }

    /** Activa/desactiva de forma reversible (no borra: preserva el historial). Devuelve el nuevo estado. */
    public function toggleActivo(int $id): string
    {
        $e = $this->model->findById($id);
        if (!$e) throw new RuntimeException('Equipo no encontrado.');
        $nuevo = $e['estado'] === 'activo' ? 'inactivo' : 'activo';
        $this->model->update($id, ['estado' => $nuevo]);
        $this->auditoria->log(
            $nuevo === 'activo' ? 'activar_equipo' : 'desactivar_equipo',
            'equipos', $id,
            ($nuevo === 'activo' ? 'Equipo reactivado: ' : 'Equipo desactivado: ') . $e['nombre']
        );
        return $nuevo;
    }

    public function agregarParticipante(int $equipoId, int $participanteId, string $rol = 'jugador'): void
    {
        if ($this->model->tieneParticipante($equipoId, $participanteId)) {
            throw new RuntimeException('El participante ya pertenece a este equipo.');
        }
        $this->model->agregarParticipante($equipoId, $participanteId, $rol);
        $this->auditoria->log('agregar_a_equipo', 'equipo_participantes', $equipoId, "Participante {$participanteId} agregado al equipo {$equipoId}");
    }

    public function quitarParticipante(int $equipoId, int $participanteId): void
    {
        $this->model->quitarParticipante($equipoId, $participanteId);
        $this->auditoria->log('quitar_de_equipo', 'equipo_participantes', $equipoId, "Participante {$participanteId} removido del equipo {$equipoId}");
    }

    private function validar(array $d): void
    {
        if (empty($d['nombre'])) throw new RuntimeException('El nombre del equipo es obligatorio.');
    }
}
