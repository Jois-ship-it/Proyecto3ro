<?php
declare(strict_types=1);

/**
 * Estadísticas agregadas e historial competitivo (participantes y equipos).
 * Lee enfrentamientos/resultados/inscripciones/tabla_posiciones — sin tablas nuevas.
 * Reutilizado por los perfiles (secciones 3, 4 y 5) para no duplicar lógica.
 */
class StatsService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function participante(int $id): array
    {
        return $this->stats('participante', $id);
    }

    public function equipo(int $id): array
    {
        return $this->stats('equipo', $id);
    }

    /**
     * @param string $tipo 'participante' | 'equipo'
     */
    private const TIPO_MAP = [
        'participante' => [
            'colA'       => 'participante_a_id',
            'colB'       => 'participante_b_id',
            'tabla'      => 'participante_id',
            'campeonCol' => 'campeon_participante_id',
            'nombreJoin' => 'participantes',
        ],
        'equipo' => [
            'colA'       => 'equipo_a_id',
            'colB'       => 'equipo_b_id',
            'tabla'      => 'equipo_id',
            'campeonCol' => 'campeon_equipo_id',
            'nombreJoin' => 'equipos',
        ],
    ];

    private const IDENTIFIER_WHITELIST = [
        'participante_a_id', 'participante_b_id', 'participante_id', 'campeon_participante_id',
        'equipo_a_id', 'equipo_b_id', 'equipo_id', 'campeon_equipo_id',
        'participantes', 'equipos',
    ];

    private static function assertIdentifier(string $value): string
    {
        if (!in_array($value, self::IDENTIFIER_WHITELIST, true)) {
            throw new InvalidArgumentException("Identificador no permitido: $value");
        }
        return $value;
    }

    private function stats(string $tipo, int $id): array
    {
        if (!isset(self::TIPO_MAP[$tipo])) {
            throw new InvalidArgumentException("Tipo inválido: $tipo");
        }
        $map = self::TIPO_MAP[$tipo];
        $colA       = self::assertIdentifier($map['colA']);
        $colB       = self::assertIdentifier($map['colB']);
        $tabla      = self::assertIdentifier($map['tabla']);
        $campeonCol = self::assertIdentifier($map['campeonCol']);
        $nombreJoin = self::assertIdentifier($map['nombreJoin']);

        // ── Historial de enfrentamientos finalizados ──
        $rows = $this->all(
            "SELECT e.id, e.es_bye, e.$colA AS a_id, e.$colB AS b_id,
                    t.id AS torneo_id, t.nombre AS torneo_nombre, t.nombre_puntos, t.estado AS torneo_estado,
                    tt.slug AS tipo_slug, tt.nombre AS tipo_nombre,
                    r.numero AS ronda_num, r.nombre AS ronda_nombre,
                    na.nombre AS nombre_a, nb.nombre AS nombre_b,
                    res.puntos_a, res.puntos_b, res.corregido,
                    e.updated_at AS fecha
             FROM enfrentamientos e
             JOIN torneos t        ON t.id = e.torneo_id
             JOIN tipos_torneo tt  ON tt.id = t.tipo_torneo_id
             JOIN rondas r         ON r.id = e.ronda_id
             LEFT JOIN $nombreJoin na ON na.id = e.$colA
             LEFT JOIN $nombreJoin nb ON nb.id = e.$colB
             LEFT JOIN resultados res ON res.enfrentamiento_id = e.id
             WHERE (e.$colA = :a OR e.$colB = :b)
               AND e.estado IN ('finalizado','bye')
             ORDER BY t.id ASC, r.numero ASC, e.orden ASC",
            [':a' => $id, ':b' => $id]
        );

        $pj=$pg=$pe=$pp=$pf=$pc=$byes=0;
        $historial = [];
        foreach ($rows as $row) {
            $esA = (int)$row['a_id'] === $id;
            if ((int)$row['es_bye'] === 1) {
                $byes++;
                $historial[] = [
                    'torneo' => $row['torneo_nombre'], 'tipo' => $row['tipo_nombre'],
                    'ronda' => $row['ronda_nombre'], 'rival' => null,
                    'pf' => null, 'pc' => null, 'resultado' => 'BYE',
                    'nombre_puntos' => $row['nombre_puntos'], 'fecha' => $row['fecha'],
                ];
                continue;
            }
            // Sin resultado cargado (no debería pasar en finalizado) → saltar
            if ($row['puntos_a'] === null) continue;

            $own   = $esA ? (float)$row['puntos_a'] : (float)$row['puntos_b'];
            $opp   = $esA ? (float)$row['puntos_b'] : (float)$row['puntos_a'];
            $rival = $esA ? $row['nombre_b'] : $row['nombre_a'];

            $pj++; $pf += (int)$own; $pc += (int)$opp;
            if ($own > $opp)      { $pg++; $resultado = 'G'; }
            elseif ($own < $opp)  { $pp++; $resultado = 'P'; }
            else                  { $pe++; $resultado = 'E'; }

            $historial[] = [
                'torneo' => $row['torneo_nombre'], 'tipo' => $row['tipo_nombre'],
                'ronda' => $row['ronda_nombre'], 'rival' => $rival,
                'pf' => (int)$own, 'pc' => (int)$opp, 'resultado' => $resultado,
                'corregido' => (int)($row['corregido'] ?? 0),
                'nombre_puntos' => $row['nombre_puntos'], 'fecha' => $row['fecha'],
            ];
        }

        // ── Posiciones (liga/suizo) ──
        $posiciones = $this->all(
            "SELECT tp.torneo_id, tp.posicion, t.nombre AS torneo_nombre, t.estado, tt.nombre AS tipo_nombre,
                    (SELECT COUNT(*) FROM tabla_posiciones x WHERE x.torneo_id = tp.torneo_id) AS total
             FROM tabla_posiciones tp
             JOIN torneos t       ON t.id = tp.torneo_id
             JOIN tipos_torneo tt ON tt.id = t.tipo_torneo_id
             WHERE tp.$tabla = :id
             ORDER BY t.id DESC",
            [':id' => $id]
        );

        // ── Campeonatos ──
        $campeonatos = $this->all(
            "SELECT id, nombre, estado FROM torneos WHERE $campeonCol = :id ORDER BY id DESC",
            [':id' => $id]
        );

        // ── Torneos inscritos (lista + activos/finalizados) ──
        $torneos = $this->all(
            "SELECT t.id, t.nombre, t.estado, tt.nombre AS tipo_nombre, tt.slug AS tipo_slug,
                    t.fecha_inicio,
                    CASE WHEN t.$campeonCol = :cid THEN 1 ELSE 0 END AS es_campeon,
                    tp.posicion
             FROM inscripciones i
             JOIN torneos t       ON t.id = i.torneo_id
             JOIN tipos_torneo tt ON tt.id = t.tipo_torneo_id
             LEFT JOIN tabla_posiciones tp ON tp.torneo_id = t.id AND tp.$tabla = :tid
             WHERE i.$tabla = :iid AND i.estado = 'activa'
             ORDER BY t.id DESC",
            [':cid' => $id, ':tid' => $id, ':iid' => $id]
        );

        $activos = $finalizados = 0;
        foreach ($torneos as $t) {
            if ($t['estado'] === 'finalizado')      $finalizados++;
            elseif ($t['estado'] !== 'cancelado')   $activos++;
        }

        // ── Evolución del rendimiento (win-rate acumulado, cronológico) ──
        $evolucion = [];
        $accG = $accJ = 0;
        foreach ($historial as $h) {
            if ($h['resultado'] === 'BYE') continue;
            $accJ++;
            if ($h['resultado'] === 'G') $accG++;
            $evolucion[] = [
                'resultado' => $h['resultado'],
                'winrate'   => (int)round($accG / $accJ * 100),
                'rival'     => $h['rival'],
                'torneo'    => $h['torneo'],
            ];
        }

        return [
            'evolucion'           => $evolucion,
            'pj' => $pj, 'pg' => $pg, 'pe' => $pe, 'pp' => $pp,
            'pf' => $pf, 'pc' => $pc, 'dif' => $pf - $pc,
            'byes' => $byes,
            'winrate' => $pj > 0 ? (int)round($pg / $pj * 100) : 0,
            'torneos_total'       => count($torneos),
            'torneos_activos'     => $activos,
            'torneos_finalizados' => $finalizados,
            'campeonatos'         => count($campeonatos),
            'campeonatos_lista'   => $campeonatos,
            'posiciones'          => $posiciones,
            'torneos'             => $torneos,
            'historial'           => $historial,
        ];
    }

    private function all(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
