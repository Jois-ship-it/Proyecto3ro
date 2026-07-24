<?php
declare(strict_types=1);

/**
 * Flujo de revisión y aprobación de correcciones de resultados.
 *
 * - El ORGANIZADOR solicita una corrección (no la aplica).
 * - El ADMINISTRADOR aprueba (se aplica la corrección real) o rechaza.
 *
 * El administrador conserva la posibilidad de corregir directamente
 * (ResultadoService::corregir) por su autoridad.
 */
class CorreccionService
{
    private SolicitudCorreccionModel $model;
    private EnfrentamientoModel      $enfModel;
    private ResultadoModel           $resModel;
    private ResultadoService         $resultadoService;
    private AuditoriaService         $auditoria;

    public function __construct()
    {
        $this->model            = new SolicitudCorreccionModel();
        $this->enfModel         = new EnfrentamientoModel();
        $this->resModel         = new ResultadoModel();
        $this->resultadoService = new ResultadoService();
        $this->auditoria        = new AuditoriaService();
    }

    /** Crea una solicitud de corrección (estado pendiente). No modifica el resultado. */
    public function solicitar(int $enfrentamientoId, float $puntosA, float $puntosB, string $motivo, int $solicitanteId): void
    {
        $enf = $this->enfModel->findById($enfrentamientoId);
        if (!$enf) throw new RuntimeException('Enfrentamiento no encontrado.');
        if ($enf['estado'] !== 'finalizado') {
            throw new RuntimeException('Solo se pueden solicitar correcciones de partidos finalizados.');
        }
        if (!$this->resModel->getByEnfrentamiento($enfrentamientoId)) {
            throw new RuntimeException('El partido no tiene un resultado cargado.');
        }
        if (trim($motivo) === '' || mb_strlen(trim($motivo)) < 10) {
            throw new RuntimeException('El motivo es obligatorio (mínimo 10 caracteres).');
        }
        if ($puntosA < 0 || $puntosB < 0) {
            throw new RuntimeException('Los puntos no pueden ser negativos.');
        }
        if ($this->model->hasPendiente($enfrentamientoId)) {
            throw new RuntimeException('Ya existe una solicitud de corrección pendiente para este partido.');
        }

        $id = $this->model->insert([
            'enfrentamiento_id' => $enfrentamientoId,
            'torneo_id'         => (int)$enf['torneo_id'],
            'puntos_a'          => $puntosA,
            'puntos_b'          => $puntosB,
            'motivo'            => trim($motivo),
            'estado'            => 'pendiente',
            'solicitado_por'    => $solicitanteId,
        ]);

        $this->auditoria->log('solicitar_correccion', 'solicitudes_correccion', $id,
            "Solicitud de corrección — Enfrentamiento {$enfrentamientoId}: {$puntosA}-{$puntosB} | Motivo: {$motivo}");
    }

    /** Aprueba una solicitud: aplica la corrección real y la marca aprobada. */
    public function aprobar(int $solicitudId, int $revisorId): void
    {
        $sol = $this->model->getByIdDetalle($solicitudId);
        if (!$sol) throw new RuntimeException('Solicitud no encontrada.');
        if ($sol['estado'] !== 'pendiente') {
            throw new RuntimeException('La solicitud ya fue resuelta.');
        }

        // Aplica la corrección (valida rondas posteriores, recalcula tabla, audita).
        $this->resultadoService->corregir(
            (int)$sol['enfrentamiento_id'],
            (float)$sol['puntos_a'],
            (float)$sol['puntos_b'],
            $sol['motivo'] . ' (aprobada por administración)',
            $revisorId
        );

        $this->model->marcarResuelta($solicitudId, 'aprobada', $revisorId);
        $this->auditoria->log('aprobar_correccion', 'solicitudes_correccion', $solicitudId,
            "Solicitud {$solicitudId} aprobada y aplicada.");
    }

    /** Rechaza una solicitud con un motivo. */
    public function rechazar(int $solicitudId, int $revisorId, string $motivoRechazo): void
    {
        $sol = $this->model->getByIdDetalle($solicitudId);
        if (!$sol) throw new RuntimeException('Solicitud no encontrada.');
        if ($sol['estado'] !== 'pendiente') {
            throw new RuntimeException('La solicitud ya fue resuelta.');
        }
        if (trim($motivoRechazo) === '') {
            throw new RuntimeException('Indicá un motivo de rechazo.');
        }

        $this->model->marcarResuelta($solicitudId, 'rechazada', $revisorId, trim($motivoRechazo));
        $this->auditoria->log('rechazar_correccion', 'solicitudes_correccion', $solicitudId,
            "Solicitud {$solicitudId} rechazada. Motivo: {$motivoRechazo}");
    }

    public function getTodas(string $estado = '', ?int $organizadorId = null): array
    {
        return $this->model->getConDetalle($estado, $organizadorId);
    }

    public function countPendientes(): int
    {
        return $this->model->countPendientes();
    }

    /** IDs de enfrentamientos con solicitud pendiente (para marcar en la UI). */
    public function pendientesIdsByTorneo(int $torneoId): array
    {
        return $this->model->pendientesIdsByTorneo($torneoId);
    }
}
