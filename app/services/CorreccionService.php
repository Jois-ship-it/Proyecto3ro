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

    /**
     * Quien pide una corrección es el organizador DEL TORNEO (o un administrador). Se comprueba acá
     * con el usuario que pide —igual que lo hace el controlador con la sesión— para que ninguna ruta
     * nueva pueda registrar solicitudes sobre torneos ajenos.
     */
    private function asegurarQueEsSuTorneo(int $torneoId, int $usuarioId): void
    {
        $usuario = (new UsuarioModel())->findByIdConRol($usuarioId);
        $rol     = $usuario['rol_nombre'] ?? '';

        if ($rol === 'administrador') return;
        if ($rol === 'organizador' && (new TorneoModel())->getOrganizadorId($torneoId) === $usuarioId) return;

        throw new ErrorNegocio('Solo el organizador del torneo puede pedir la corrección de sus partidos.');
    }

    /** Crea una solicitud de corrección (estado pendiente). No modifica el resultado. */
    public function solicitar(int $enfrentamientoId, float $puntosA, float $puntosB, string $motivo, int $solicitanteId): void
    {
        $enf = $this->enfModel->findById($enfrentamientoId);
        if (!$enf) throw new ErrorNegocio('Enfrentamiento no encontrado.');
        $this->asegurarQueEsSuTorneo((int)$enf['torneo_id'], $solicitanteId);
        if ($enf['estado'] !== 'finalizado') {
            throw new ErrorNegocio('Solo se pueden solicitar correcciones de partidos finalizados.');
        }
        if (!$this->resModel->getByEnfrentamiento($enfrentamientoId)) {
            throw new ErrorNegocio('El partido no tiene un resultado cargado.');
        }
        // Misma regla que usa aprobar() al aplicarla. Se consulta acá para que el
        // organizador se entere en el momento: una solicitud que no se va a poder
        // aprobar no tiene por qué existir, y antes se descubría recién cuando un
        // admin la intentaba, dejándola trabada en «pendiente».
        $bloqueo = $this->resultadoService->motivoBloqueoCorreccion($enfrentamientoId);
        if ($bloqueo !== null) throw new ErrorNegocio($bloqueo);
        if (mb_strlen(trim($motivo)) < ResultadoService::MOTIVO_CORRECCION_MINIMO) {
            throw new ErrorNegocio('El motivo es obligatorio (mínimo ' . ResultadoService::MOTIVO_CORRECCION_MINIMO . ' caracteres).');
        }
        if ($puntosA < 0 || $puntosB < 0) {
            throw new ErrorNegocio('Los puntos no pueden ser negativos.');
        }
        // Misma regla que corregir(): un empate donde no vale tampoco se puede pedir.
        $noEmpate = $this->resultadoService->motivoMarcadorNoPermitido($enfrentamientoId, $puntosA, $puntosB);
        if ($noEmpate !== null) throw new ErrorNegocio($noEmpate);
        if ($this->model->hasPendiente($enfrentamientoId)) {
            throw new ErrorNegocio('Ya existe una solicitud de corrección pendiente para este partido.');
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

    /**
     * Aprueba una solicitud: aplica la corrección real y la marca aprobada. Devuelve el aviso de la
     * corrección si cambió el campeón o se reabrió el torneo (ver ResultadoService::corregir()).
     */
    public function aprobar(int $solicitudId, int $revisorId): ?string
    {
        // Aplicar la corrección y marcar la solicitud como aprobada son una sola cosa:
        // si falla lo segundo, no queda el resultado corregido con la solicitud pendiente.
        return Database::transaccion(fn() => $this->aprobarSinTransaccion($solicitudId, $revisorId));
    }

    /** El cuerpo de aprobar(), separado para que corra entero dentro de una transacción. */
    private function aprobarSinTransaccion(int $solicitudId, int $revisorId): ?string
    {
        $sol = $this->model->getByIdDetalle($solicitudId);
        if (!$sol) throw new ErrorNegocio('Solicitud no encontrada.');
        if ($sol['estado'] !== 'pendiente') {
            throw new ErrorNegocio('La solicitud ya fue resuelta.');
        }

        // Aplica la corrección (valida rondas posteriores, recalcula tabla, decide el campeón, audita).
        $aviso = $this->resultadoService->corregir(
            (int)$sol['enfrentamiento_id'],
            (float)$sol['puntos_a'],
            (float)$sol['puntos_b'],
            $sol['motivo'] . ' (aprobada por administración)',
            $revisorId
        );

        $this->model->marcarResuelta($solicitudId, 'aprobada', $revisorId);
        $this->auditoria->log('aprobar_correccion', 'solicitudes_correccion', $solicitudId,
            "Solicitud {$solicitudId} aprobada y aplicada.");
        return $aviso;
    }

    /** Rechaza una solicitud con un motivo. */
    public function rechazar(int $solicitudId, int $revisorId, string $motivoRechazo): void
    {
        $sol = $this->model->getByIdDetalle($solicitudId);
        if (!$sol) throw new ErrorNegocio('Solicitud no encontrada.');
        if ($sol['estado'] !== 'pendiente') {
            throw new ErrorNegocio('La solicitud ya fue resuelta.');
        }
        if (trim($motivoRechazo) === '') {
            throw new ErrorNegocio('Indicá un motivo de rechazo.');
        }

        $this->model->marcarResuelta($solicitudId, 'rechazada', $revisorId, trim($motivoRechazo));
        $this->auditoria->log('rechazar_correccion', 'solicitudes_correccion', $solicitudId,
            "Solicitud {$solicitudId} rechazada. Motivo: {$motivoRechazo}");
    }

    /** Una página de las solicitudes (con búsqueda y filtro por estado) y el total que cumple el filtro. */
    public function buscar(array $filtros, int $limite, int $desde, ?int $organizadorId = null): array
    {
        return $this->model->buscarConDetalle($filtros, $limite, $desde, $organizadorId);
    }

    public function contar(array $filtros, ?int $organizadorId = null): int
    {
        return $this->model->contarBuscadas($filtros, $organizadorId);
    }

    public function getTodas(string $estado = '', ?int $organizadorId = null): array
    {
        return $this->model->getConDetalle($estado, $organizadorId);
    }

    public function countPendientes(): int
    {
        return $this->model->countPendientes();
    }
}
