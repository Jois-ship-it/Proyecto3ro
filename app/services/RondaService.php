<?php
declare(strict_types=1);

/**
 * Estado de las rondas.
 *
 * Hasta ahora `rondas.estado` era metadata muerta: se escribía al crear la ronda
 * y nadie lo volvía a tocar ni a consultar, así que quedaban torneos finalizados
 * con rondas todavía en 'pendiente'.
 *
 * Significado de cada estado (los tres que quedan en el ENUM):
 *
 *   pendiente  La ronda existe pero todavía no se puede jugar entera: le faltan
 *              cruces por definir. Pasa sobre todo en Eliminación Directa, donde
 *              la ronda siguiente se crea con los slots a medio llenar mientras
 *              van avanzando los ganadores.
 *   en_curso   Todos sus cruces están definidos y quedan partidos por resolver.
 *   cerrada    No admite más carga de resultados. Se llega de dos maneras:
 *              automáticamente, cuando todos sus partidos terminaron, o a mano,
 *              cuando el organizador la cierra (facultad de "publicar o cerrar
 *              rondas" del §5.2 de la letra del proyecto).
 *
 * 'bloqueada' se sacó del ENUM: significaba lo mismo que 'cerrada' y tener dos
 * valores para un mismo estado solo invitaba a que se desincronizaran.
 * Ver database/migrations/2026_09_rondas_estado.sql.
 *
 * Regla clave de la sincronización automática: **solo avanza**, nunca retrocede.
 * Así un cierre manual sobre una ronda con partidos pendientes no se deshace
 * solo en la siguiente carga de resultado; para revertirlo hay que reabrirla
 * explícitamente.
 */
class RondaService
{
    /** Orden de avance: la sincronización nunca baja de nivel. */
    private const ORDEN = ['pendiente' => 0, 'en_curso' => 1, 'cerrada' => 2];

    private RondaModel       $model;
    private TorneoModel      $torneoModel;
    private AuditoriaService $auditoria;

    public function __construct()
    {
        $this->model       = new RondaModel();
        $this->torneoModel = new TorneoModel();
        $this->auditoria   = new AuditoriaService();
    }

    public function getById(int $rondaId): ?array
    {
        return $this->model->findById($rondaId);
    }

    /** Estado que le corresponde a una ronda según cómo están sus partidos. */
    public function estadoSegunPartidos(int $rondaId): string
    {
        $c = $this->model->contarPartidos($rondaId);

        if ($c['total'] === 0)                 return 'pendiente'; // ronda recién creada, sin cruces
        if ($c['incompletos'] > 0)             return 'pendiente'; // bracket a medio armar
        if ($c['terminados'] === $c['total'])  return 'cerrada';   // no queda nada por jugar
        return 'en_curso';
    }

    /**
     * Pone al día el estado de todas las rondas de un torneo.
     *
     * Se llama después de generar estructura y después de cada carga o corrección
     * de resultado. Como solo avanza, es idempotente y respeta los cierres manuales.
     */
    public function sincronizarTorneo(int $torneoId): void
    {
        foreach ($this->model->getByTorneo($torneoId) as $ronda) {
            $actual   = (string) $ronda['estado'];
            $calculado = $this->estadoSegunPartidos((int) $ronda['id']);

            $nivelActual   = self::ORDEN[$actual]    ?? 0;
            $nivelCalculado = self::ORDEN[$calculado] ?? 0;

            if ($nivelCalculado > $nivelActual) {
                $this->model->updateEstado((int) $ronda['id'], $calculado);
            }
        }
    }

    /**
     * Cierra una ronda a mano. A partir de acá no se pueden cargar resultados
     * de sus partidos hasta que alguien la reabra.
     *
     * @return array la ronda tal como quedó
     */
    public function cerrar(int $rondaId, ?int $usuarioId = null): array
    {
        $ronda = $this->model->findById($rondaId);
        if (!$ronda) throw new RuntimeException('Ronda no encontrada.');

        if ($ronda['estado'] === 'cerrada') {
            throw new RuntimeException('La ronda ya está cerrada.');
        }

        $this->model->updateEstado($rondaId, 'cerrada');

        $c = $this->model->contarPartidos($rondaId);
        $pendientes = $c['total'] - $c['terminados'];
        $detalle = "Ronda «{$ronda['nombre']}» cerrada"
                 . ($pendientes > 0 ? " con {$pendientes} partido(s) sin resolver" : '')
                 . " — torneo {$ronda['torneo_id']}";

        $this->auditoria->log(
            'cerrar_ronda', 'rondas', $rondaId, $detalle,
            ['estado' => $ronda['estado']],
            ['estado' => 'cerrada'],
            $usuarioId
        );

        return $this->model->findById($rondaId);
    }

    /**
     * Reabre una ronda cerrada a mano y la deja en el estado que le corresponde
     * según sus partidos.
     *
     * Una ronda cuyos partidos terminaron todos no se reabre: no habría nada que
     * cargar. Para cambiar un resultado ya cargado está la corrección.
     */
    public function reabrir(int $rondaId, ?int $usuarioId = null): array
    {
        $ronda = $this->model->findById($rondaId);
        if (!$ronda) throw new RuntimeException('Ronda no encontrada.');

        if ($ronda['estado'] !== 'cerrada') {
            throw new RuntimeException('La ronda no está cerrada.');
        }

        $nuevo = $this->estadoSegunPartidos($rondaId);
        if ($nuevo === 'cerrada') {
            throw new RuntimeException(
                'No se puede reabrir: todos los partidos de la ronda están resueltos. '
                . 'Si hay que cambiar un resultado, usá la corrección de resultados.'
            );
        }

        $this->model->updateEstado($rondaId, $nuevo);

        $this->auditoria->log(
            'reabrir_ronda', 'rondas', $rondaId,
            "Ronda «{$ronda['nombre']}» reabierta — torneo {$ronda['torneo_id']}",
            ['estado' => 'cerrada'],
            ['estado' => $nuevo],
            $usuarioId
        );

        return $this->model->findById($rondaId);
    }

    /**
     * Frena la carga de un resultado si su ronda está cerrada.
     * La corrección de resultados NO pasa por acá: una ronda terminada se cierra
     * sola, y bloquear también la corrección dejaría los errores sin arreglo.
     */
    public function assertAbiertaParaCarga(int $enfrentamientoId): void
    {
        $ronda = $this->model->findByEnfrentamiento($enfrentamientoId);
        if (!$ronda) return; // sin ronda asociada no hay nada que controlar

        if ($ronda['estado'] === 'cerrada') {
            throw new RuntimeException(
                "La ronda «{$ronda['nombre']}» está cerrada: no admite carga de resultados. "
                . 'Reabrila para poder cargarlos.'
            );
        }
    }
}
