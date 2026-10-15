<?php
declare(strict_types=1);

/**
 * Elige la base de los tests según quién los corra.
 *
 *   - `php tests/run.php` (o un archivo suelto): la base propia, sin Composer ni nada que instalar → TestCaseSolo.php.
 *   - PHPUnit (`php tests/run.php --phpunit`): sus aserciones y su informe → TestCasePhpunit.php.
 *
 * Los tests extienden `TestCase` en los dos casos y usan los mismos nombres de aserción. Van en archivos aparte porque PHP declara
 * las clases de nivel superior al compilar el archivo: dos `class TestCase` en uno solo chocarían aunque una nunca se ejecute.
 */
require_once __DIR__ . (class_exists(\PHPUnit\Framework\TestCase::class) ? '/TestCasePhpunit.php' : '/TestCaseSolo.php');
