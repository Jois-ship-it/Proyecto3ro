<?php
declare(strict_types=1);

class LigaService
{
    use DesempateTrait;
    use ModuloActivoTrait;

    private TorneoModel          $torneoModel;
    private InscripcionModel     $insModel;
    private RondaModel           $rondaModel;
    private EnfrentamientoModel  $enfModel;
    private TablaPosicionesService $tablaService;
    private AuditoriaService     $auditoria;

    public function __construct()
    {
        $this->torneoModel  = new TorneoModel();
        $this->insModel     = new InscripcionModel();
        $this->rondaModel   = new RondaModel();
        $this->enfModel     = new EnfrentamientoModel();
        $this->tablaService = new TablaPosicionesService();
        $this->auditoria    = new AuditoriaService();
    }

    /**
     * Genera el fixture round-robin todos-contra-todos.
     * Soporta cantidad par e impar de participantes/equipos.
     */
    public function generarFixture(int $torneoId): void
    {
        // Generar el fixture es EMPEZAR el torneo: si el módulo está deshabilitado
        // no se arranca nada nuevo (los torneos ya en curso sí pueden terminarse).
        $this->assertModuloActivo('liga');

        $torneo = $this->torneoModel->findByIdCompleto($torneoId);
        if (!$torneo) throw new RuntimeException('Torneo no encontrado.');
        if ($torneo['estado'] !== 'inscripcion' && $torneo['estado'] !== 'borrador') {
            throw new RuntimeException('El torneo debe estar en estado inscripción o borrador para generar el fixture.');
        }

        $inscripciones = $this->insModel->getByTorneo($torneoId);
        $n = count($inscripciones);
        if ($n < 2)   throw new RuntimeException('Se necesitan al menos 2 participantes/equipos inscritos.');
        if ($n > 64)  throw new RuntimeException('La Liga admite hasta 64 participantes/equipos.');

        $esEquipos = $torneo['modalidad'] === 'equipos';

        // Extraer IDs
        $ids = InscripcionModel::idsDesdeInscripciones($inscripciones, $esEquipos);

        // Si impar, agregar null como "bye virtual"
        $byeVirtual = false;
        if ($n % 2 !== 0) {
            $ids[] = null;
            $byeVirtual = true;
            $n++;
        }

        $totalRondas = $n - 1;

        // Generar rondas y partidos usando algoritmo de rotación de Berger
        for ($r = 0; $r < $totalRondas; $r++) {
            $rondaId = $this->rondaModel->insert([
                'torneo_id' => $torneoId,
                'numero'    => $r + 1,
                'nombre'    => 'Fecha ' . ($r + 1),
                'estado'    => 'pendiente',
            ]);

            for ($i = 0; $i < $n / 2; $i++) {
                $a = $ids[$i];
                $b = $ids[$n - 1 - $i];

                // Saltar si alguno es el bye virtual (null)
                if ($a === null || $b === null) continue;

                $enf = [
                    'torneo_id' => $torneoId,
                    'ronda_id'  => $rondaId,
                    'estado'    => 'pendiente',
                    'es_bye'    => 0,
                    'orden'     => $i + 1,
                ];

                if ($esEquipos) {
                    $enf['equipo_a_id'] = $a;
                    $enf['equipo_b_id'] = $b;
                } else {
                    $enf['participante_a_id'] = $a;
                    $enf['participante_b_id'] = $b;
                }

                $this->enfModel->insert($enf);
            }

            // Rotar: mantener ids[0] fijo, rotar el resto
            $last = array_pop($ids);
            array_splice($ids, 1, 0, [$last]);
        }

        $this->torneoModel->updateEstado($torneoId, 'en_curso');
        $this->tablaService->recalcular($torneoId);
        // Las rondas recién creadas arrancan con el estado que les corresponde
        // (ver RondaService: 'en_curso' si son jugables, 'pendiente' si les faltan cruces).
        (new RondaService())->sincronizarTorneo($torneoId);
        $this->auditoria->log('generar_fixture', 'torneos', $torneoId, "Fixture Liga generado para torneo {$torneoId}");
    }

    /**
     * Verifica si todos los partidos están finalizados y declara campeón.
     *
     * Desempate: si los dos primeros puestos terminan empatados en TODAS las
     * métricas, se genera un nuevo partido de desempate entre ellos. El partido
     * de desempate suma a la tabla como uno más, de modo que:
     *   - si hay ganador, ese puntaje rompe el empate y queda 1.º (campeón);
     *   - si vuelve a empatar, esta función generará otro desempate.
     * El proceso se repite sin límite hasta que exista un ganador real.
     */
    public function intentarFinalizar(int $torneoId): bool
    {
        $db = Database::getInstance();

        // ¿Quedan partidos por jugar? (incluye un eventual desempate en curso)
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM enfrentamientos
             WHERE torneo_id = :t AND estado NOT IN ('finalizado','bye','cancelado')"
        );
        $stmt->execute([':t' => $torneoId]);
        if ((int)$stmt->fetchColumn() > 0) return false;

        $tabla = $this->tablaService->getByTorneo($torneoId);
        if (empty($tabla)) return false;

        $torneo    = $this->torneoModel->findById($torneoId);
        $esEquipos = $torneo['modalidad'] === 'equipos';

        // Empate exacto entre los dos primeros → generar (otro) partido de desempate.
        if ($this->hayEmpateEnCima($tabla)) {
            $this->crearRondaDesempate($torneoId, $tabla[0], $tabla[1], $esEquipos);
            return false;
        }

        // Hay un líder claro → campeón.
        $campeon = $tabla[0];
        $this->torneoModel->setCampeon($esEquipos, $torneoId, (int)($esEquipos ? $campeon['equipo_id'] : $campeon['participante_id']));
        $this->auditoria->log('finalizar_torneo', 'torneos', $torneoId, "Torneo {$torneoId} finalizado — Liga");
        return true;
    }

}
