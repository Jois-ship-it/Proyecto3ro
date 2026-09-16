<?php
declare(strict_types=1);

/** Se lanza cuando una aserción no se cumple. Corta el método de test en curso. */
class FalloDeAsercion extends Exception {}

/**
 * Base mínima para los tests de integración del proyecto.
 *
 * No reemplaza a PHPUnit: es lo justo para poder correr los tests con el `php`
 * que ya tiene el proyecto, sin Composer. Los nombres de las aserciones son los
 * de PHPUnit a propósito, de modo que migrar consista en cambiar la clase base
 * y poco más (ver README → Tests).
 *
 * Cada método público cuyo nombre empieza con `test_` es un caso. El resto del
 * nombre, con los guiones bajos convertidos en espacios, es lo que se imprime,
 * así que conviene que se lea como una frase.
 */
abstract class TestCase
{
    protected PDO $db;

    private int   $casosOk      = 0;
    private array $casosFallidos = [];
    private int   $aserciones    = 0;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /** Se ejecuta antes de cada método `test_*`. */
    protected function setUp(): void {}

    /** Se ejecuta después de cada método `test_*`, pase o falle. */
    protected function tearDown(): void {}

    /** Corre la clase indicada e imprime el resumen. Devuelve el código de salida. */
    public static function ejecutar(string $clase): int
    {
        /** @var TestCase $test */
        $test = new $clase();
        $r = $test->correr();

        echo "\n";
        if ($r['fallos'] > 0) {
            echo "RESULTADO: FALLÓ — {$r['fallos']} caso(s) de " . ($r['ok'] + $r['fallos'])
               . ", {$r['aserciones']} aserciones.\n";
            return 1;
        }
        echo "RESULTADO: OK — {$r['ok']} caso(s), {$r['aserciones']} aserciones.\n";
        return 0;
    }

    /** @return array{ok:int,fallos:int,aserciones:int} */
    final public function correr(): array
    {
        $metodos = array_values(array_filter(
            get_class_methods($this),
            fn(string $m) => str_starts_with($m, 'test_')
        ));

        echo static::class . "\n";

        foreach ($metodos as $metodo) {
            $etiqueta = str_replace('_', ' ', substr($metodo, 5));
            try {
                $this->setUp();
                $this->$metodo();
                $this->casosOk++;
                printf("  OK    %s\n", $etiqueta);
            } catch (FalloDeAsercion $e) {
                $this->casosFallidos[] = $etiqueta;
                printf("  FALLA %s\n        %s\n", $etiqueta, $e->getMessage());
            } catch (Throwable $e) {
                $this->casosFallidos[] = $etiqueta;
                printf("  ERROR %s\n        %s: %s\n        en %s:%d\n",
                    $etiqueta, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
            } finally {
                try { $this->tearDown(); } catch (Throwable $e) {
                    printf("        (tearDown falló: %s)\n", $e->getMessage());
                }
            }
        }

        return [
            'ok'         => $this->casosOk,
            'fallos'     => count($this->casosFallidos),
            'aserciones' => $this->aserciones,
        ];
    }

    // ─── Aserciones ─────────────────────────────────────────────────────────

    protected function assertTrue(bool $cond, string $msg = ''): void
    {
        $this->aserciones++;
        if (!$cond) $this->fallar($msg ?: 'se esperaba true');
    }

    protected function assertFalse(bool $cond, string $msg = ''): void
    {
        $this->aserciones++;
        if ($cond) $this->fallar($msg ?: 'se esperaba false');
    }

    protected function assertSame(mixed $esperado, mixed $real, string $msg = ''): void
    {
        $this->aserciones++;
        if ($esperado !== $real) {
            $this->fallar(($msg ? "$msg — " : '')
                . 'esperado ' . $this->describir($esperado) . ', obtenido ' . $this->describir($real));
        }
    }

    protected function assertNotSame(mixed $noEsperado, mixed $real, string $msg = ''): void
    {
        $this->aserciones++;
        if ($noEsperado === $real) {
            $this->fallar(($msg ? "$msg — " : '') . 'no debía ser ' . $this->describir($noEsperado));
        }
    }

    protected function assertCount(int $n, array $items, string $msg = ''): void
    {
        $this->aserciones++;
        if (count($items) !== $n) {
            $this->fallar(($msg ? "$msg — " : '') . "se esperaban $n elementos, hay " . count($items));
        }
    }

    protected function assertGreaterThan(float|int $minimo, float|int $real, string $msg = ''): void
    {
        $this->aserciones++;
        if (!($real > $minimo)) {
            $this->fallar(($msg ? "$msg — " : '') . "se esperaba > $minimo, obtenido $real");
        }
    }

    protected function assertStringContainsString(string $aguja, string $pajar, string $msg = ''): void
    {
        $this->aserciones++;
        if (!str_contains($pajar, $aguja)) {
            $this->fallar(($msg ? "$msg — " : '') . "no se encontró «{$aguja}» en: "
                . mb_substr($pajar, 0, 300));
        }
    }

    protected function assertStringNotContainsString(string $aguja, string $pajar, string $msg = ''): void
    {
        $this->aserciones++;
        if (str_contains($pajar, $aguja)) {
            $this->fallar(($msg ? "$msg — " : '') . "no debía aparecer «{$aguja}» en: "
                . mb_substr($pajar, 0, 300));
        }
    }

    protected function assertNotNull(mixed $valor, string $msg = ''): void
    {
        $this->aserciones++;
        if ($valor === null) $this->fallar($msg ?: 'se esperaba un valor, llegó null');
    }

    /**
     * Exige que $fn lance una excepción y, si se indica $fragmento, que el
     * mensaje lo contenga. Devuelve la excepción atrapada.
     */
    protected function assertThrows(callable $fn, string $fragmento = '', string $msg = ''): Throwable
    {
        $this->aserciones++;
        try {
            $fn();
        } catch (FalloDeAsercion $e) {
            throw $e; // una aserción fallida adentro no cuenta como "lanzó bien"
        } catch (Throwable $e) {
            if ($fragmento !== '' && !str_contains($e->getMessage(), $fragmento)) {
                $this->fallar(($msg ? "$msg — " : '')
                    . "lanzó, pero el mensaje no menciona «{$fragmento}»: " . $e->getMessage());
            }
            return $e;
        }
        $this->fallar(($msg ? "$msg — " : '') . 'no lanzó ninguna excepción');
    }

    /** Exige que $fn NO lance. Devuelve lo que $fn haya devuelto. */
    protected function assertDoesNotThrow(callable $fn, string $msg = ''): mixed
    {
        $this->aserciones++;
        try {
            return $fn();
        } catch (FalloDeAsercion $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->fallar(($msg ? "$msg — " : '') . get_class($e) . ': ' . $e->getMessage());
        }
    }

    protected function fallar(string $msg): never
    {
        throw new FalloDeAsercion($msg);
    }

    private function describir(mixed $v): string
    {
        if (is_array($v)) {
            $json = json_encode($v, JSON_UNESCAPED_UNICODE);
            return $json !== false && strlen($json) <= 300 ? $json : 'array(' . count($v) . ')';
        }
        return var_export($v, true);
    }
}
