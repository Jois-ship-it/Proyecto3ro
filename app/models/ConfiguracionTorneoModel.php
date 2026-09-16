<?php
declare(strict_types=1);

/**
 * Acceso a `configuraciones_torneo`, la tabla clave/valor de cada torneo.
 *
 * Guarda los datos del evento que no tienen columna propia en `torneos` porque
 * son opcionales y de texto libre: sede, contacto, cierre de inscripción,
 * observaciones. Lo que sí tiene columna propia —puntuación, formato, fechas,
 * modalidad— vive en `torneos` y no debe duplicarse acá: son datos tipados, con
 * default y con reglas, y una columna los expresa mejor que un par de strings.
 *
 * El índice UNIQUE (torneo_id, clave) hace que haya como mucho un valor por
 * clave, y la FK con ON DELETE CASCADE limpia sola al borrar el torneo.
 *
 * Qué claves son válidas lo decide ConfiguracionTorneoService, no este modelo:
 * acá se lee y se escribe, la política está un nivel más arriba.
 */
class ConfiguracionTorneoModel extends BaseModel
{
    protected string $table = 'configuraciones_torneo';

    /**
     * Todas las claves de un torneo, listas para usar.
     *
     * @return array<string, string> clave => valor
     */
    public function getPorTorneo(int $torneoId): array
    {
        $filas = $this->fetchAll(
            "SELECT clave, valor FROM configuraciones_torneo WHERE torneo_id = :t ORDER BY clave",
            [':t' => $torneoId]
        );

        $mapa = [];
        foreach ($filas as $fila) {
            $mapa[(string) $fila['clave']] = (string) ($fila['valor'] ?? '');
        }
        return $mapa;
    }

    /** Un valor puntual, o null si el torneo no tiene esa clave. */
    public function get(int $torneoId, string $clave): ?string
    {
        $valor = $this->fetchColumn(
            "SELECT valor FROM configuraciones_torneo WHERE torneo_id = :t AND clave = :c LIMIT 1",
            [':t' => $torneoId, ':c' => $clave]
        );
        return $valor === false ? null : (string) $valor;
    }

    /**
     * Las claves de varios torneos de una sola consulta.
     *
     * Existe para los listados: pedir clave por clave dentro de un foreach de 52
     * torneos son 52 consultas, y acá alcanza con una.
     *
     * @param int[] $torneoIds
     * @return array<int, array<string, string>> torneo_id => clave => valor
     */
    public function getPorTorneos(array $torneoIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $torneoIds)));
        if ($ids === []) return [];

        // Marcadores numerados: con ATTR_EMULATE_PREPARES = false no se puede
        // repetir un marcador con nombre, así que se arma uno por id.
        $marcadores = [];
        $params     = [];
        foreach ($ids as $i => $id) {
            $marcadores[]     = ":t{$i}";
            $params[":t{$i}"] = $id;
        }

        $filas = $this->fetchAll(
            "SELECT torneo_id, clave, valor FROM configuraciones_torneo
              WHERE torneo_id IN (" . implode(', ', $marcadores) . ")
              ORDER BY torneo_id, clave",
            $params
        );

        $mapa = [];
        foreach ($filas as $fila) {
            $mapa[(int) $fila['torneo_id']][(string) $fila['clave']] = (string) ($fila['valor'] ?? '');
        }
        return $mapa;
    }

    /**
     * Inserta o actualiza una clave.
     *
     * ON DUPLICATE KEY contra el índice único evita tener que consultar antes si
     * existe. En el UPDATE se usa VALUES(valor) y no un marcador nuevo, por la
     * misma razón: los marcadores con nombre no se repiten.
     */
    public function guardar(int $torneoId, string $clave, string $valor): void
    {
        $this->execute(
            "INSERT INTO configuraciones_torneo (torneo_id, clave, valor)
             VALUES (:t, :c, :v)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)",
            [':t' => $torneoId, ':c' => $clave, ':v' => $valor]
        );
    }

    /** Quita una clave. Dejarla vacía y dejarla ausente son lo mismo. */
    public function borrar(int $torneoId, string $clave): void
    {
        $this->execute(
            "DELETE FROM configuraciones_torneo WHERE torneo_id = :t AND clave = :c",
            [':t' => $torneoId, ':c' => $clave]
        );
    }
}
