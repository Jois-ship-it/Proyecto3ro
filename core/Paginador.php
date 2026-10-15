<?php
declare(strict_types=1);

/**
 * La cuenta de una página de un listado: desde dónde se lee, cuántas hay y qué números mostrar.
 *
 * No toca la base: recibe el total que devolvió el COUNT(*) del propio listado y calcula el
 * resto. El listado pide después solo las filas de su página (LIMIT/OFFSET en la consulta).
 *
 * Una página fuera de rango se corrige en lugar de fallar: quien escribe ?page=999 a mano ve la
 * última página, y ?page=abc o ?page=-3 la primera. Ni una pantalla vacía ni un error.
 */
final class Paginador
{
    /** Tope de filas por página, para que ningún llamado pida la tabla entera. */
    public const POR_PAGINA_MAXIMO = 100;

    /** Primera fila de la página, contada desde 1 (0 si no hay resultados). */
    public readonly int $desde;
    /** Última fila de la página, contada desde 1 (0 si no hay resultados). */
    public readonly int $hasta;
    /** Cuántas filas se saltea la consulta: el OFFSET. */
    public readonly int $desplazamiento;

    private function __construct(
        public readonly int $total,
        public readonly int $porPagina,
        public readonly int $pagina,
        public readonly int $paginas,
    ) {
        $this->desplazamiento = ($pagina - 1) * $porPagina;
        $this->desde          = $total === 0 ? 0 : $this->desplazamiento + 1;
        $this->hasta          = min($total, $this->desplazamiento + $porPagina);
    }

    /**
     * @param int   $total     filas que cumplen los filtros (el COUNT(*))
     * @param mixed $pagina    lo que vino en ?page= (cualquier cosa: se sanea)
     * @param int   $porPagina filas por página
     */
    public static function de(int $total, mixed $pagina, int $porPagina): self
    {
        $total     = max(0, $total);
        $porPagina = max(1, min($porPagina, self::POR_PAGINA_MAXIMO));
        $paginas   = max(1, (int) ceil($total / $porPagina));

        $n = filter_var($pagina, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $n = $n === false ? 1 : min($n, $paginas);

        return new self($total, $porPagina, $n, $paginas);
    }

    public function hayAnterior(): bool  { return $this->pagina > 1; }
    public function hayPosterior(): bool { return $this->pagina < $this->paginas; }

    /**
     * Los números de página a dibujar: la primera, la última y las vecinas de la actual.
     * Un hueco de dos o más páginas es un null (los puntos suspensivos); un hueco de una
     * sola se rellena con su número, porque «…» ocuparía el mismo lugar.
     *
     * Ejemplo, en la página 10 de 20: [1, null, 9, 10, 11, null, 20].
     *
     * @return array<int, int|null>
     */
    public function ventana(int $vecinas = 1): array
    {
        $numeros = [1, $this->paginas];
        for ($i = $this->pagina - $vecinas; $i <= $this->pagina + $vecinas; $i++) {
            if ($i >= 1 && $i <= $this->paginas) {
                $numeros[] = $i;
            }
        }
        $numeros = array_values(array_unique($numeros));
        sort($numeros);

        $salida = [];
        $previo = 0;
        foreach ($numeros as $n) {
            if ($previo !== 0 && $n - $previo > 1) {
                $salida[] = $n - $previo === 2 ? $previo + 1 : null;
            }
            $salida[] = $n;
            $previo   = $n;
        }
        return $salida;
    }

    /**
     * Enlace a una página del listado conservando los filtros vigentes. Los filtros vacíos no
     * viajan (la URL queda corta) y la página 1 tampoco lleva ?page=.
     *
     * @param array<string, mixed> $filtros
     */
    public static function enlace(string $base, array $filtros, int $pagina): string
    {
        $parametros = array_filter($filtros, fn($v) => $v !== '' && $v !== null);
        if ($pagina > 1) {
            $parametros['page'] = $pagina;
        }
        return $base . ($parametros ? '?' . http_build_query($parametros) : '');
    }
}
