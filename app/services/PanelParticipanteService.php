<?php
declare(strict_types=1);

/**
 * Lo que muestra «Mi panel» del participante además de su perfil: sus próximos partidos, sus últimos
 * resultados y cómo va en cada torneo (letra §5.3: «el participante consulta sus torneos, calendario,
 * resultados y posición»).
 *
 * Antes el panel prometía calendario, resultados y posición y solo traía la lista de torneos.
 *
 * Un participante compite de dos maneras: solo, en un torneo individual, o a través de un equipo en
 * un torneo por equipos. El panel mira las dos, así que cada partido dice «como» quién se juega
 * (el nombre del equipo, o null si juega solo).
 */
class PanelParticipanteService
{
    /** Cuántos partidos se muestran en cada lista y en cuántos torneos se mira la posición. */
    public const CANTIDAD = 5;

    private EnfrentamientoModel $partidos;
    private EquipoModel $equipos;
    private InscripcionModel $inscripciones;
    private TablaPosicionesModel $tabla;

    public function __construct()
    {
        $this->partidos      = new EnfrentamientoModel();
        $this->equipos       = new EquipoModel();
        $this->inscripciones = new InscripcionModel();
        $this->tabla         = new TablaPosicionesModel();
    }

    /**
     * @return array{
     *   proximos: list<array{torneo_id:int,torneo:string,ronda:string,rival:string,como:?string,fecha:?string}>,
     *   recientes: list<array{torneo_id:int,torneo:string,ronda:string,rival:string,como:?string,mios:float,rival_puntos:float,resultado:string}>,
     *   competencias: list<array{torneo_id:int,torneo:string,estado:string,como:?string,formato:string,posicion:?int,total:?int,puntos:?int,pj:?int,llave:?string,ronda_fuera:?string}>
     * }
     */
    public function panel(int $participanteId): array
    {
        $equipoIds = $this->equipos->idsDeParticipante($participanteId);

        return [
            'proximos'     => array_map(
                fn(array $p) => $this->proximo($p, $participanteId, $equipoIds),
                $this->partidos->proximosDe($participanteId, $equipoIds, self::CANTIDAD)
            ),
            'recientes'    => array_map(
                fn(array $p) => $this->reciente($p, $participanteId, $equipoIds),
                $this->partidos->recientesDe($participanteId, $equipoIds, self::CANTIDAD)
            ),
            'competencias' => array_map(
                fn(array $c) => $this->competencia($c, $participanteId, $equipoIds),
                $this->inscripciones->competenciasDe($participanteId, $equipoIds, self::CANTIDAD)
            ),
        ];
    }

    /**
     * ¿El competidor del panel es el lado A del partido? Si el participante está en los dos lados (por
     * ejemplo, dos equipos suyos se enfrentan) se toma el A.
     *
     * @param int[] $equipoIds
     */
    private function esLadoA(array $partido, int $participanteId, array $equipoIds): bool
    {
        if ((int) ($partido['participante_a_id'] ?? 0) === $participanteId) return true;
        $equipoA = (int) ($partido['equipo_a_id'] ?? 0);
        return $equipoA !== 0 && in_array($equipoA, $equipoIds, true);
    }

    /** El nombre del equipo con el que juega, si el partido es de un torneo por equipos. */
    private function como(array $partido, bool $ladoA): ?string
    {
        $esDeEquipos = $ladoA ? !empty($partido['equipo_a_id']) : !empty($partido['equipo_b_id']);
        return $esDeEquipos ? (string) ($ladoA ? $partido['lado_a'] : $partido['lado_b']) : null;
    }

    private function proximo(array $p, int $participanteId, array $equipoIds): array
    {
        $a = $this->esLadoA($p, $participanteId, $equipoIds);
        return [
            'torneo_id' => (int) $p['torneo_id'],
            'torneo'    => (string) $p['torneo_nombre'],
            'ronda'     => (string) $p['ronda_nombre'],
            'rival'     => (string) ($a ? $p['lado_b'] : $p['lado_a']),
            'como'      => $this->como($p, $a),
            'fecha'     => $p['fecha_programada'] ?: null,
        ];
    }

    private function reciente(array $p, int $participanteId, array $equipoIds): array
    {
        $a     = $this->esLadoA($p, $participanteId, $equipoIds);
        $mios  = (float) ($a ? $p['puntos_a'] : $p['puntos_b']);
        $suyos = (float) ($a ? $p['puntos_b'] : $p['puntos_a']);
        return [
            'torneo_id'    => (int) $p['torneo_id'],
            'torneo'       => (string) $p['torneo_nombre'],
            'ronda'        => (string) $p['ronda_nombre'],
            'rival'        => (string) ($a ? $p['lado_b'] : $p['lado_a']),
            'como'         => $this->como($p, $a),
            'mios'         => $mios,
            'rival_puntos' => $suyos,
            // Se decide por los puntos y no por la columna `ganador`: un empate no tiene ganador.
            'resultado'    => $mios > $suyos ? 'victoria' : ($mios < $suyos ? 'derrota' : 'empate'),
        ];
    }

    /**
     * Cómo va en un torneo. En una liga o un suizo es su lugar en la tabla; en una llave de eliminación
     * no hay tabla, así que se dice si sigue en carrera, si ganó o en qué ronda quedó afuera.
     *
     * @param int[] $equipoIds
     */
    private function competencia(array $c, int $participanteId, array $equipoIds): array
    {
        $esEquipo  = !empty($c['equipo_id']);
        $salida = [
            'torneo_id'   => (int) $c['torneo_id'],
            'torneo'      => (string) $c['torneo_nombre'],
            'estado'      => (string) $c['torneo_estado'],
            'como'        => $esEquipo ? (string) $c['equipo_nombre'] : null,
            'formato'     => (string) $c['tipo_slug'],
            'posicion'    => null, 'total' => null, 'puntos' => null, 'pj' => null,
            'llave'       => null, 'ronda_fuera' => null,
        ];

        if ($c['tipo_slug'] === 'eliminacion_directa') {
            return $this->estadoEnLlave($salida, $c, $participanteId, $esEquipo);
        }

        $fila = $this->tabla->filaDe(
            (int) $c['torneo_id'],
            $esEquipo ? null : (int) $c['participante_id'],
            $esEquipo ? (int) $c['equipo_id'] : null
        );
        if ($fila && (int) $fila['pj'] > 0) {
            // Con cero partidos jugados la tabla ordena por desempate técnico: ese «lugar» no dice nada.
            $salida['posicion'] = (int) $fila['posicion'];
            $salida['total']    = (int) $fila['total'];
            $salida['puntos']   = (int) $fila['puntos'];
            $salida['pj']       = (int) $fila['pj'];
        }
        return $salida;
    }

    /**
     * @return array el mismo arreglo de la competencia con `llave` ('campeon' | 'en_carrera' | 'eliminado' | null) y, si quedó afuera, `ronda_fuera`
     */
    private function estadoEnLlave(array $salida, array $c, int $participanteId, bool $esEquipo): array
    {
        $campeonId = (int) ($esEquipo ? $c['campeon_equipo_id'] : $c['campeon_participante_id']);
        $yo        = $esEquipo ? (int) $c['equipo_id'] : $participanteId;
        if ($campeonId !== 0 && $campeonId === $yo) {
            $salida['llave'] = 'campeon';
            return $salida;
        }

        // En este torneo solo cuentan los partidos del competidor que está inscrito (no los de otro equipo suyo).
        $misPartidos = $this->partidos->delCompetidorEnTorneo(
            (int) $c['torneo_id'],
            $esEquipo ? 0 : $participanteId,
            $esEquipo ? [(int) $c['equipo_id']] : []
        );
        if ($misPartidos === []) return $salida;       // la llave todavía no tiene partidos suyos

        foreach ($misPartidos as $p) {
            if (in_array($p['estado'], ['pendiente', 'en_curso'], true)) {
                $salida['llave'] = $c['torneo_estado'] === 'en_curso' ? 'en_carrera' : null;
                return $salida;
            }
        }

        // Todos sus partidos terminaron: si el último lo perdió, quedó afuera en esa ronda.
        $ultimo = $misPartidos[0];
        if ($ultimo['estado'] === 'finalizado') {
            $gano = $esEquipo
                ? (int) $ultimo['ganador_equipo_id'] === $yo
                : (int) $ultimo['ganador_participante_id'] === $yo;
            if (!$gano) {
                $salida['llave']       = 'eliminado';
                $salida['ronda_fuera'] = (string) $ultimo['ronda_nombre'];
                return $salida;
            }
        }
        // Ganó su último partido y todavía no tiene el siguiente: sigue en carrera mientras el torneo siga.
        $salida['llave'] = $c['torneo_estado'] === 'en_curso' ? 'en_carrera' : null;
        return $salida;
    }
}
