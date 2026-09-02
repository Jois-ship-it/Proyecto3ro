<?php
declare(strict_types=1);

class InscripcionService
{
    private InscripcionModel $model;
    private TorneoModel      $torneoModel;
    private EquipoModel      $equipoModel;
    private AuditoriaService $auditoria;

    public function __construct()
    {
        $this->model       = new InscripcionModel();
        $this->torneoModel = new TorneoModel();
        $this->equipoModel = new EquipoModel();
        $this->auditoria   = new AuditoriaService();
    }

    public function inscribirParticipante(int $torneoId, int $participanteId): void
    {
        $torneo = $this->torneoModel->findById($torneoId);
        if (!$torneo) throw new RuntimeException('Torneo no encontrado.');
        if (!in_array($torneo['estado'], ['borrador', 'inscripcion'])) {
            throw new RuntimeException('El torneo no está abierto para inscripciones.');
        }
        if ($torneo['modalidad'] !== 'individual') {
            throw new RuntimeException('Este torneo es por equipos. Inscribí un equipo.');
        }
        if ($this->model->isInscritoParticipante($torneoId, $participanteId)) {
            throw new RuntimeException('El participante ya está inscrito en este torneo.');
        }
        $participante = (new ParticipanteModel())->findById($participanteId);
        if (!$participante) throw new RuntimeException('El participante no existe.');
        if ($participante['estado'] !== 'activo') {
            throw new RuntimeException('No se puede inscribir un participante inactivo.');
        }
        $this->model->insert([
            'torneo_id'       => $torneoId,
            'participante_id' => $participanteId,
            'estado'          => 'activa',
        ]);
        $this->auditoria->log('inscribir_participante', 'inscripciones', $torneoId, "Participante {$participanteId} inscrito en torneo {$torneoId}");
    }

    public function inscribirEquipo(int $torneoId, int $equipoId): void
    {
        $torneo = $this->torneoModel->findById($torneoId);
        if (!$torneo) throw new RuntimeException('Torneo no encontrado.');
        if (!in_array($torneo['estado'], ['borrador', 'inscripcion'])) {
            throw new RuntimeException('El torneo no está abierto para inscripciones.');
        }
        if ($torneo['modalidad'] !== 'equipos') {
            throw new RuntimeException('Este torneo es individual. Inscribí un participante.');
        }
        if ($this->model->isInscritoEquipo($torneoId, $equipoId)) {
            throw new RuntimeException('El equipo ya está inscrito en este torneo.');
        }
        // El equipo debe existir y estar activo.
        $equipo = $this->equipoModel->findById($equipoId);
        if (!$equipo) throw new RuntimeException('El equipo no existe.');
        if ($equipo['estado'] !== 'activo') {
            throw new RuntimeException('No se puede inscribir un equipo inactivo.');
        }

        // Un equipo necesita al menos 1 integrante, y cumplir el mínimo del torneo si lo hay.
        $minimo      = max(1, (int)($torneo['min_integrantes_equipo'] ?? 0));
        $integrantes = $this->equipoModel->contarIntegrantes($equipoId);
        if ($integrantes < $minimo) {
            throw new RuntimeException(
                "El equipo no cumple el mínimo de {$minimo} integrante(s) (tiene {$integrantes}). " .
                "Agregá jugadores antes de inscribirlo."
            );
        }
        $this->model->insert([
            'torneo_id' => $torneoId,
            'equipo_id' => $equipoId,
            'estado'    => 'activa',
        ]);
        $this->auditoria->log('inscribir_equipo', 'inscripciones', $torneoId, "Equipo {$equipoId} inscrito en torneo {$torneoId}");
    }

    public function desinscribir(int $torneoId, ?int $participanteId, ?int $equipoId): void
    {
        if ($participanteId) {
            $this->model->desinscribirParticipante($torneoId, $participanteId);
            $this->auditoria->log('desinscribir_participante', 'inscripciones', $torneoId, "Participante {$participanteId} retirado del torneo {$torneoId}");
        } elseif ($equipoId) {
            $this->model->desinscribirEquipo($torneoId, $equipoId);
            $this->auditoria->log('desinscribir_equipo', 'inscripciones', $torneoId, "Equipo {$equipoId} retirado del torneo {$torneoId}");
        }
    }

    public function getByTorneo(int $torneoId): array
    {
        return $this->model->getByTorneo($torneoId);
    }
}
