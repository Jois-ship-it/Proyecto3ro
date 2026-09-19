<?php
declare(strict_types=1);

/**
 * Test de que la documentación no cite código que no existe.
 *
 * `docs/restricciones_no_estructurales.md` documenta 115 reglas de negocio y
 * cada una indica dónde vive. Hasta septiembre de 2026 las citaba por número de
 * línea, y las referencias se habían podrido: apuntaban a constructores, a
 * métodos que no eran la regla, o a líneas en blanco. Un número de línea
 * envejece con cada edición del archivo.
 *
 * Ahora se citan por nombre de método, que es estable. Pero un nombre también
 * puede quedar viejo —si el método se renombra o se elimina— y entonces el
 * documento vuelve a mentir, solo que más despacio. Este test lo impide: cada
 * `Clase::metodo()` que aparezca en la documentación tiene que existir de verdad.
 *
 * Es la clase de cosa que se revisa en una defensa: abrir el documento y el
 * archivo al lado.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/docs_referencias_test.php
 */

require __DIR__ . '/bootstrap.php';

final class DocsReferenciasTest extends TestCase
{
    /** Documentos que citan código y tienen que estar al día. */
    private const DOCUMENTOS = [
        'docs/restricciones_no_estructurales.md',
        'docs/documentacion_tecnica.md',
        'docs/INSTALACION.md',
        'database/migrations/README.md',
    ];

    /** Clases del proyecto, con la ruta de su archivo. @return array<string,string> */
    private function clasesDelProyecto(): array
    {
        $clases = [];
        foreach (['core', 'app/models', 'app/services', 'app/controllers'] as $dir) {
            foreach (glob(dirname(__DIR__) . '/' . $dir . '/*.php') ?: [] as $archivo) {
                $clases[basename($archivo, '.php')] = $archivo;
            }
        }
        return $clases;
    }

    /**
     * Las referencias «Clase::metodo()» de un documento.
     *
     * @return array<int, array{0:string, 1:string}>
     */
    private function referencias(string $doc): array
    {
        $texto = (string) file_get_contents(dirname(__DIR__) . '/' . $doc);

        // `Clase::metodo()` y también la forma abreviada «`A::x()` y `::y()`»,
        // donde el segundo método pertenece a la misma clase.
        preg_match_all('/`(\w+)::(\w+)\(\)`(?:\s*y\s*`::(\w+)\(\)`)?/', $texto, $m, PREG_SET_ORDER);

        $salida = [];
        foreach ($m as $hit) {
            $salida[] = [$hit[1], $hit[2]];
            if (($hit[3] ?? '') !== '') $salida[] = [$hit[1], $hit[3]];
        }
        return $salida;
    }

    public function test_los_documentos_citan_codigo(): void
    {
        // Control del escenario: si los regex no encontraran nada, el test de
        // abajo pasaría de vacío y no estaría comprobando nada.
        $total = 0;
        foreach (self::DOCUMENTOS as $doc) $total += count($this->referencias($doc));

        $this->assertGreaterThan(50, $total,
            "solo se encontraron {$total} referencias: revisá el patrón de búsqueda");
    }

    public function test_toda_clase_citada_existe(): void
    {
        $clases = $this->clasesDelProyecto();

        $inexistentes = [];
        foreach (self::DOCUMENTOS as $doc) {
            foreach ($this->referencias($doc) as [$clase, $metodo]) {
                if (!isset($clases[$clase])) {
                    $inexistentes[] = "{$doc}: {$clase}::{$metodo}() — no existe la clase";
                }
            }
        }

        $this->assertCount(0, array_unique($inexistentes),
            "\n        - " . implode("\n        - ", array_unique($inexistentes)));
    }

    public function test_todo_metodo_citado_existe(): void
    {
        $clases = $this->clasesDelProyecto();

        $inexistentes = [];
        foreach (self::DOCUMENTOS as $doc) {
            foreach ($this->referencias($doc) as [$clase, $metodo]) {
                if (!isset($clases[$clase])) continue;   // lo reporta el test anterior

                // No se usa method_exists() para no cargar clases que arrastran
                // una conexión a la base al construirse: alcanza con el fuente.
                $fuente = (string) file_get_contents($clases[$clase]);
                if (preg_match('/function\s+' . preg_quote($metodo, '/') . '\s*\(/', $fuente) !== 1) {
                    $inexistentes[] = "{$doc}: {$clase}::{$metodo}()";
                }
            }
        }

        $this->assertCount(0, array_unique($inexistentes),
            "la documentación cita métodos que no existen:\n        - "
            . implode("\n        - ", array_unique($inexistentes)));
    }

    public function test_no_vuelven_las_referencias_por_numero_de_linea(): void
    {
        // `Clase:123` o `Clase:12,45` o `Clase:12-18`. Es la convención que se
        // retiró: se pudre sola con cada edición del archivo citado.
        $porLinea = [];
        foreach (self::DOCUMENTOS as $doc) {
            $texto = (string) file_get_contents(dirname(__DIR__) . '/' . $doc);
            if (preg_match_all('/`(\w+):(\d[\d,\-]*)`/', $texto, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) $porLinea[] = "{$doc}: `{$hit[0]}`";
            }
        }

        $this->assertCount(0, $porLinea,
            "citan por número de línea en vez de por método:\n        - "
            . implode("\n        - ", $porLinea));
    }

    public function test_los_archivos_citados_existen(): void
    {
        // Rutas del estilo `database/schema.sql` o `tests/permisos_test.php`.
        $faltantes = [];
        foreach (self::DOCUMENTOS as $doc) {
            $texto = (string) file_get_contents(dirname(__DIR__) . '/' . $doc);
            preg_match_all('#`((?:app|core|config|database|docs|public|scripts|tests)/[\w./-]+\.(?:php|sql|md|js|css))`#', $texto, $m);

            foreach (array_unique($m[1]) as $ruta) {
                if (!file_exists(dirname(__DIR__) . '/' . $ruta)) {
                    $faltantes[] = "{$doc}: {$ruta}";
                }
            }
        }

        $this->assertCount(0, $faltantes,
            "la documentación cita archivos que no existen:\n        - "
            . implode("\n        - ", $faltantes));
    }
}

exit(TestCase::ejecutar(DocsReferenciasTest::class));
