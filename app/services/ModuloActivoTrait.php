<?php
declare(strict_types=1);

/**
 * Guarda de módulo para los servicios que dependen de un módulo habilitado.
 *
 * Política (ver docs/manual_administrador.md → "Gestionar módulos"):
 * deshabilitar un módulo impide EMPEZAR cosas nuevas con ese formato —crear el
 * torneo y generar su estructura inicial— pero NO interrumpe los torneos que ya
 * están en curso: sus rondas siguientes, la carga de resultados y la definición
 * del campeón siguen funcionando hasta que el torneo termine.
 *
 * El motivo es evitar dejar torneos reales a mitad de camino por un cambio de
 * configuración: un torneo en curso tiene partidos jugados y una tabla de
 * posiciones que dependen de poder cerrarse.
 */
trait ModuloActivoTrait
{
    /**
     * Corta la operación si el módulo no está habilitado.
     *
     * @param string $slug Slug del módulo en `modulos` (coincide con `tipos_torneo.slug`
     *                     para los tres formatos de competencia).
     * @throws RuntimeException si el módulo está inactivo/en revisión o no existe.
     */
    private function assertModuloActivo(string $slug): void
    {
        $modulo = (new ModuloModel())->findBySlug($slug);

        if ($modulo === null) {
            throw new RuntimeException(
                "El módulo «{$slug}» no está registrado en el sistema, por lo que no se puede operar con este formato."
            );
        }

        if ($modulo['estado'] !== 'activo') {
            throw new RuntimeException(
                "El módulo «{$modulo['nombre']}» está en estado «{$modulo['estado']}». "
                . "Un administrador debe reactivarlo para crear torneos o generar la estructura de este formato. "
                . "Los torneos ya en curso pueden terminarse normalmente."
            );
        }
    }
}
