<?php
declare(strict_types=1);

/**
 * Qué publica docker-compose.yml hacia fuera de la máquina.
 *
 * Un puerto publicado sin dirección (`"8081:80"`) escucha en TODAS las interfaces: quien esté
 * en la misma red llega a él. Para la aplicación es lo que se quiere; para la base de datos y
 * para phpMyAdmin, una consola de administración de esa base, no. Antes phpMyAdmin se
 * publicaba así, y la documentación decía que «solo estaba en la red interna de Docker».
 *
 * Este test lee el compose y exige:
 *   - la base de datos no publica ningún puerto;
 *   - phpMyAdmin solo lo publica en 127.0.0.1 (desde la propia máquina anda igual);
 *   - cualquier servicio que se agregue después y no sea la aplicación también tiene que
 *     publicarse solo en la propia máquina.
 *
 * Es una lectura del archivo (no hace falta Docker). Lo que cubre: que nadie lo deshaga sin
 * darse cuenta al editar el compose.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/exposicion_test.php
 */

require __DIR__ . '/bootstrap.php';

class ExposicionTest extends TestCase
{
    /** @return array<string, string[]> servicio => líneas de su bloque */
    private function servicios(): array
    {
        $lineas = file(dirname(__DIR__) . '/docker-compose.yml', FILE_IGNORE_NEW_LINES);
        $this->assertTrue($lineas !== false, 'se pudo leer docker-compose.yml');

        $enServicios = false;
        $actual      = null;
        $bloques     = [];
        foreach ($lineas as $linea) {
            $linea = rtrim($linea, "\r");
            if (preg_match('/^services:\s*$/', $linea)) { $enServicios = true; continue; }
            // Otra clave de primer nivel (volumes:, networks:) cierra la sección.
            if ($enServicios && preg_match('/^[^\s#]/', $linea)) { $enServicios = false; $actual = null; continue; }
            if (!$enServicios) continue;

            if (preg_match('/^  ([A-Za-z0-9_-]+):\s*$/', $linea, $m)) { $actual = $m[1]; $bloques[$actual] = []; continue; }
            if ($actual !== null) $bloques[$actual][] = $linea;
        }
        return $bloques;
    }

    /** @return string[] los mapeos de puerto de un servicio, tal cual figuran (sin comillas) */
    private function puertos(array $bloque): array
    {
        $mapeos = [];
        $dentro = false;
        foreach ($bloque as $linea) {
            if (preg_match('/^    ports:\s*$/', $linea)) { $dentro = true; continue; }
            if (!$dentro) continue;
            if (trim($linea) === '' || preg_match('/^\s*#/', $linea)) continue;   // comentarios dentro de la lista
            if (preg_match('/^      - ["\']?([^"\'#]+?)["\']?\s*(#.*)?$/', $linea, $m)) { $mapeos[] = trim($m[1]); continue; }
            break;                                                                 // terminó la lista
        }
        return $mapeos;
    }

    private function esSoloLocal(string $mapeo): bool
    {
        return str_starts_with($mapeo, '127.0.0.1:')
            || str_starts_with($mapeo, '[::1]:')
            || str_starts_with($mapeo, 'localhost:');
    }

    public function test_el_compose_declara_los_tres_servicios(): void
    {
        // Control: si el lector no encuentra los servicios, los demás casos pasarían en vacío.
        $this->assertSame(['app', 'db', 'pma'], array_keys($this->servicios()));
    }

    public function test_la_base_de_datos_no_publica_ningun_puerto(): void
    {
        $this->assertSame([], $this->puertos($this->servicios()['db']),
            'la base solo se alcanza desde dentro de la red de Docker');
    }

    public function test_phpmyadmin_solo_escucha_en_la_propia_maquina(): void
    {
        $puertos = $this->puertos($this->servicios()['pma']);

        $this->assertGreaterThan(0, count($puertos), 'control: phpMyAdmin sí publica un puerto (si no, no se podría usar)');
        foreach ($puertos as $mapeo) {
            $this->assertTrue($this->esSoloLocal($mapeo),
                "phpMyAdmin publica «{$mapeo}»: sin 127.0.0.1 escucha en todas las interfaces y cualquiera en la red llega a la consola de la base");
        }
    }

    public function test_solo_la_aplicacion_se_publica_a_la_red(): void
    {
        $abiertos = [];
        foreach ($this->servicios() as $servicio => $bloque) {
            foreach ($this->puertos($bloque) as $mapeo) {
                if (!$this->esSoloLocal($mapeo)) $abiertos[] = "$servicio ($mapeo)";
            }
        }

        $this->assertGreaterThan(0, count($abiertos), 'control: la aplicación sí se publica');
        foreach ($abiertos as $entrada) {
            $this->assertTrue(str_starts_with($entrada, 'app '),
                "{$entrada} escucha en todas las interfaces y no es la aplicación: ¿hace falta, o alcanza con 127.0.0.1?");
        }
    }
}

TestCase::correrSiEsScript(ExposicionTest::class);
