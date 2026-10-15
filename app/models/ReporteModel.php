<?php
declare(strict_types=1);

/**
 * Las consultas de los reportes: cifras agregadas del sistema y todo lo que hace falta para
 * contar un torneo (resumen, posiciones, partidos e inscritos). Solo lee.
 *
 * Un «partido» en las cifras es un enfrentamiento que se juega: no cuenta los bye (un lado sin
 * rival, que pasa de ronda sin jugar) ni los cancelados. Así el reporte global y el de cada
 * torneo hablan de lo mismo y la suma de uno coincide con el otro.
 */
class ReporteModel extends BaseModel
{
    protected string $table = 'torneos';

    /**
     * Cuántos torneos hay de cada formato, en el orden de los formatos. Uno sin torneos figura con 0.
     *
     * @return array<string,int> nombre del formato => cantidad
     */
    public function torneosPorFormato(): array
    {
        $filas = $this->fetchAll(
            "SELECT tt.nombre, COUNT(t.id) AS n
             FROM tipos_torneo tt
             LEFT JOIN torneos t ON t.tipo_torneo_id = tt.id
             GROUP BY tt.id, tt.nombre
             ORDER BY tt.id ASC"
        );
        return array_map('intval', array_column($filas, 'n', 'nombre'));
    }

    /**
     * Solicitudes de corrección por estado. Siempre trae los tres estados.
     *
     * @return array{pendiente:int,aprobada:int,rechazada:int}
     */
    public function correccionesPorEstado(): array
    {
        $cuenta = ['pendiente' => 0, 'aprobada' => 0, 'rechazada' => 0];
        foreach ($this->fetchAll("SELECT estado, COUNT(*) AS n FROM solicitudes_correccion GROUP BY estado") as $f) {
            $cuenta[$f['estado']] = (int) $f['n'];
        }
        return $cuenta;
    }

    /**
     * Las cuentas: activas por rol, más las bloqueadas y las pendientes de aprobación.
     *
     * @return array{administradores:int,organizadores:int,participantes:int,bloqueadas:int,pendientes:int}
     */
    public function cuentas(): array
    {
        $porRol = array_column($this->fetchAll(
            "SELECT r.nombre, COUNT(u.id) AS n
             FROM roles r
             LEFT JOIN usuarios u ON u.rol_id = r.id AND u.estado = 'activo'
             GROUP BY r.id, r.nombre"
        ), 'n', 'nombre');
        $porEstado = array_column($this->fetchAll(
            "SELECT estado, COUNT(*) AS n FROM usuarios WHERE estado IN ('bloqueada','pendiente') GROUP BY estado"
        ), 'n', 'estado');

        return [
            'administradores' => (int) ($porRol['administrador'] ?? 0),
            'organizadores'   => (int) ($porRol['organizador']   ?? 0),
            'participantes'   => (int) ($porRol['participante']  ?? 0),
            'bloqueadas'      => (int) ($porEstado['bloqueada']  ?? 0),
            'pendientes'      => (int) ($porEstado['pendiente']  ?? 0),
        ];
    }

    /**
     * Los números de un torneo. Ver la nota de la clase sobre qué es un «partido».
     *
     * @return array{inscritos:int,partidos_total:int,partidos_jugados:int,byes:int,puntos_total:float}
     */
    public function resumenTorneo(int $torneoId): array
    {
        $f = $this->fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM inscripciones
                  WHERE torneo_id = :t1 AND estado = 'activa') AS inscritos,
                (SELECT COUNT(*) FROM enfrentamientos
                  WHERE torneo_id = :t2 AND es_bye = 0 AND estado <> 'cancelado') AS partidos_total,
                (SELECT COUNT(*) FROM enfrentamientos
                  WHERE torneo_id = :t3 AND es_bye = 0 AND estado = 'finalizado') AS partidos_jugados,
                (SELECT COUNT(*) FROM enfrentamientos
                  WHERE torneo_id = :t4 AND es_bye = 1) AS byes,
                (SELECT COALESCE(SUM(r.puntos_a + r.puntos_b), 0)
                   FROM resultados r JOIN enfrentamientos e ON e.id = r.enfrentamiento_id
                  WHERE e.torneo_id = :t5 AND e.es_bye = 0) AS puntos_total",
            [':t1' => $torneoId, ':t2' => $torneoId, ':t3' => $torneoId, ':t4' => $torneoId, ':t5' => $torneoId]
        );
        return [
            'inscritos'        => (int) ($f['inscritos'] ?? 0),
            'partidos_total'   => (int) ($f['partidos_total'] ?? 0),
            'partidos_jugados' => (int) ($f['partidos_jugados'] ?? 0),
            'byes'             => (int) ($f['byes'] ?? 0),
            'puntos_total'     => (float) ($f['puntos_total'] ?? 0),
        ];
    }

    /** La tabla de posiciones tal como está guardada (v_posiciones), de la 1.ª a la última. */
    public function posiciones(int $torneoId): array
    {
        return $this->fetchAll(
            "SELECT * FROM v_posiciones WHERE torneo_id = :t ORDER BY posicion ASC, id ASC",
            [':t' => $torneoId]
        );
    }

    /** Todos los partidos del torneo, ronda por ronda (v_calendario). */
    public function partidos(int $torneoId): array
    {
        return $this->fetchAll(
            "SELECT * FROM v_calendario WHERE torneo_id = :t ORDER BY ronda_numero ASC, orden ASC, id ASC",
            [':t' => $torneoId]
        );
    }

    /** Los inscritos (participantes o equipos), con su estado. Sin datos de contacto. */
    public function inscritos(int $torneoId): array
    {
        return $this->fetchAll(
            "SELECT COALESCE(p.nombre, e.nombre) AS competidor,
                    IF(i.participante_id IS NULL, 'Equipo', 'Participante') AS tipo,
                    i.estado, i.fecha_inscripcion
             FROM inscripciones i
             LEFT JOIN participantes p ON p.id = i.participante_id
             LEFT JOIN equipos       e ON e.id = i.equipo_id
             WHERE i.torneo_id = :t
             ORDER BY competidor ASC, i.id ASC",
            [':t' => $torneoId]
        );
    }
}
