<?php
declare(strict_types=1);

/**
 * La base de los tests cuando los corre PHPUnit (`tests/run.php --phpunit`).
 *
 * `tests/lib/TestCase.php` la carga en lugar de la base propia si PHPUnit ya está presente. Los tests no cambian: siguen
 * extendiendo `TestCase` y usando los mismos nombres de aserción, que son los de PHPUnit a propósito. Lo único que no existe
 * en PHPUnit son tres ayudas del proyecto, que se agregan acá:
 *
 *   - assertThrows($fn, $fragmento)   exige que $fn lance y que el mensaje contenga el fragmento (devuelve la excepción);
 *   - assertDoesNotThrow($fn)         exige que $fn NO lance (devuelve lo que $fn devolvió);
 *   - fallar($mensaje)                termina el caso como fallido.
 *
 * Y `$this->db`, la conexión a la base de pruebas, que la base propia abre en su constructor.
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected PDO $db;

    public function __construct(string $name)
    {
        parent::__construct($name);
        $this->db = Database::getInstance();
    }

    /**
     * Al pie de cada archivo de test. Con la base propia corre las clases y sale; con PHPUnit no hace nada,
     * porque PHPUnit descubre y corre los casos por su cuenta (y un `exit` acá lo cortaría).
     */
    public static function correrSiEsScript(string ...$clases): void
    {
    }

    protected function assertThrows(callable $fn, string $fragmento = '', string $msg = ''): Throwable
    {
        try {
            $fn();
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            throw $e; // una aserción fallida adentro no cuenta como «lanzó bien»
        } catch (Throwable $e) {
            if ($fragmento !== '') {
                $this->assertStringContainsString($fragmento, $e->getMessage(),
                    ($msg ? "$msg — " : '') . 'lanzó, pero el mensaje no menciona lo esperado: ' . $e->getMessage());
            } else {
                $this->addToAssertionCount(1);
            }
            return $e;
        }
        $this->fallar(($msg ? "$msg — " : '') . 'no lanzó ninguna excepción');
    }

    protected function assertDoesNotThrow(callable $fn, string $msg = ''): mixed
    {
        try {
            $r = $fn();
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->fallar(($msg ? "$msg — " : '') . get_class($e) . ': ' . $e->getMessage());
        }
        $this->addToAssertionCount(1);
        return $r;
    }

    protected function fallar(string $msg): never
    {
        self::fail($msg);
    }
}
