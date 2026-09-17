<?php
declare(strict_types=1);

/**
 * Test del catálogo de valores de los ENUM del esquema.
 *
 * El esquema traía valores que ningún código escribía nunca: un partido
 * «pospuesto» o «bloqueado», una inscripción «descalificada», un resultado
 * «anulado», un módulo «en revisión». Ninguno tenía forma de producirse: no
 * había pantalla, ni servicio, ni ruta que los generara. Es el mismo problema
 * que tenía `torneos.requiere_desempate_final` — una promesa en la base de datos
 * que el sistema no cumple, y que en una defensa se responde con «nada».
 *
 * Este test fija el catálogo: cada columna ENUM vale exactamente lo que dice
 * CATALOGO, y cada valor del catálogo aparece por lo menos una vez en el código.
 * Agregar un valor al esquema sin usarlo en ningún lado rompe el test.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/esquema_enums_test.php
 */

require __DIR__ . '/bootstrap.php';

final class EsquemaEnumsTest extends TestCase
{
    /**
     * Todos los ENUM del esquema, con los valores que el sistema sabe producir.
     *
     * @var array<string, string[]>
     */
    private const CATALOGO = [
        'usuarios.estado'               => ['pendiente', 'activo', 'inactivo', 'suspendido', 'rechazado', 'bloqueada'],
        'participantes.estado'          => ['pendiente', 'activo', 'inactivo', 'suspendido', 'rechazado'],
        'equipos.estado'                => ['activo', 'inactivo'],
        'modulos.estado'                => ['activo', 'inactivo'],
        'torneos.modalidad'             => ['individual', 'equipos'],
        'torneos.estado'                => ['borrador', 'inscripcion', 'en_curso', 'finalizado', 'cancelado'],
        'torneos.bye_suizo'             => ['sin_puntos', 'victoria', 'personalizado'],
        'inscripciones.estado'          => ['activa', 'retirada'],
        'rondas.estado'                 => ['pendiente', 'en_curso', 'cerrada'],
        // 'cancelado' no lo escribe ningún servicio, pero RondaModel lo cuenta
        // como partido terminado: sacarlo obligaría a tocar esas consultas.
        'enfrentamientos.estado'        => ['pendiente', 'en_curso', 'finalizado', 'bye', 'cancelado'],
        'resultados.estado'             => ['cargado', 'corregido'],
        'solicitudes_correccion.estado' => ['pendiente', 'aprobada', 'rechazada'],
    ];

    /** Los valores que la base declara hoy para una columna. @return string[] */
    private function valoresEnLaBase(string $tabla, string $columna): array
    {
        $tipo = (string) $this->db->query(
            "SELECT COLUMN_TYPE FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name   = '{$tabla}'
                AND column_name  = '{$columna}'"
        )->fetchColumn();

        if ($tipo === '') $this->fallar("No existe la columna {$tabla}.{$columna}.");

        preg_match_all("/'((?:[^']|'')*)'/", $tipo, $m);
        return array_map(fn(string $v) => str_replace("''", "'", $v), $m[1]);
    }

    // ─── El esquema dice exactamente lo que el catálogo ─────────────────────

    public function test_ninguna_columna_declara_valores_de_mas(): void
    {
        $sobrantes = [];
        foreach (self::CATALOGO as $ruta => $esperados) {
            [$tabla, $columna] = explode('.', $ruta);
            foreach (array_diff($this->valoresEnLaBase($tabla, $columna), $esperados) as $v) {
                $sobrantes[] = "{$ruta} = '{$v}'";
            }
        }

        $this->assertCount(0, $sobrantes,
            "el esquema declara valores que el sistema no sabe producir:\n        - "
            . implode("\n        - ", $sobrantes));
    }

    public function test_ninguna_columna_perdio_un_valor_que_el_sistema_usa(): void
    {
        $faltantes = [];
        foreach (self::CATALOGO as $ruta => $esperados) {
            [$tabla, $columna] = explode('.', $ruta);
            foreach (array_diff($esperados, $this->valoresEnLaBase($tabla, $columna)) as $v) {
                $faltantes[] = "{$ruta} = '{$v}'";
            }
        }

        // Este es el lado que importa al recortar: sacar de más rompe en
        // silencio, porque un ENUM guarda cadena vacía en vez de dar error.
        $this->assertCount(0, $faltantes,
            "el esquema perdió valores que el código escribe:\n        - "
            . implode("\n        - ", $faltantes));
    }

    // ─── Y el catálogo dice lo que el código realmente usa ──────────────────

    public function test_cada_valor_del_catalogo_aparece_en_el_codigo(): void
    {
        $fuente = '';
        foreach (['app', 'core'] as $dir) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $dir)
            );
            foreach ($it as $archivo) {
                if ($archivo->isFile() && $archivo->getExtension() === 'php') {
                    $fuente .= (string) file_get_contents($archivo->getPathname());
                }
            }
        }

        $huerfanos = [];
        foreach (self::CATALOGO as $ruta => $valores) {
            foreach ($valores as $v) {
                if (!str_contains($fuente, "'{$v}'") && !str_contains($fuente, "\"{$v}\"")) {
                    $huerfanos[] = "{$ruta} = '{$v}'";
                }
            }
        }

        $this->assertCount(0, $huerfanos,
            "están en el catálogo pero no aparecen en app/ ni core/:\n        - "
            . implode("\n        - ", $huerfanos));
    }

    public function test_el_mapa_de_colores_no_nombra_estados_inexistentes(): void
    {
        // View::estadoChip() pinta cada estado. Un estado en ese mapa que ningún
        // ENUM tiene es código que nunca se ejecuta, y confunde: parece que el
        // sistema sabe manejarlo.
        $todos = [];
        foreach (self::CATALOGO as $valores) {
            foreach ($valores as $v) $todos[$v] = true;
        }
        $r      = new ReflectionMethod(View::class, 'estadoChip');
        $lineas = file((string) $r->getFileName());
        $cuerpo = implode('', array_slice(
            (array) $lineas,
            $r->getStartLine() - 1,
            $r->getEndLine() - $r->getStartLine() + 1
        ));

        preg_match_all("/'([a-z_]+)'\s*=>/", $cuerpo, $m);

        $inexistentes = array_values(array_filter(
            array_unique($m[1]),
            fn(string $estado) => !isset($todos[$estado])
        ));

        $this->assertCount(0, $inexistentes,
            'el mapa de colores pinta estados que no existen: ' . implode(', ', $inexistentes));
    }
}

exit(TestCase::ejecutar(EsquemaEnumsTest::class));
