<?php
declare(strict_types=1);

require_once __DIR__ . '/entorno_cli.php';

/**
 * Contraseñas iniciales de las cuentas del seed.
 *
 * database/seed.sql no trae ninguna contraseña: cada cuenta nace con
 * `password_hash = '!sin-clave-definida'`, que no es un hash válido y por lo tanto
 * no lo verifica ninguna clave. Hasta que este script les asigne una, nadie puede
 * entrar con esas cuentas. Una contraseña escrita en el repositorio no es una
 * contraseña: la puede leer cualquiera que lo clone.
 *
 *   administrador  ADMIN_PASS del .env. Si falta, se genera una al azar y se
 *                  imprime UNA sola vez (en el log del contenedor).
 *   demás cuentas  DEMO_PASS del .env (organizadores y participantes de
 *                  demostración). Si falta quedan sin clave, que es lo correcto en
 *                  una instalación real.
 *
 * Las dos tienen que cumplir la política de contraseñas del sistema
 * (Validator::passwordErrors). Una que no la cumple se rechaza y la cuenta queda
 * sin clave: no se elige otra en silencio.
 *
 * Solo toca cuentas que siguen con el marcador. Una que ya tiene contraseña (la
 * cambió su dueño, la fijó un administrador) no se modifica nunca, así que correrlo
 * en cada arranque es seguro.
 *
 * Corre con el usuario de la aplicación: solo hace UPDATE de usuarios.
 *
 * Uso (CLI):  php database/inicializar_cuentas.php
 */
final class CuentasIniciales
{
    /** Valor de usuarios.password_hash de una cuenta que todavía no tiene contraseña. */
    public const SIN_CLAVE = '!sin-clave-definida';

    public const LARGO_GENERADA = 20;

    /**
     * Contraseña al azar que cumple la política (mayúscula, minúscula, número y
     * símbolo). Sin caracteres que se confunden al leerlos (0/O, 1/l/I).
     */
    public static function claveAleatoria(int $largo = self::LARGO_GENERADA): string
    {
        $juegos = [
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            'abcdefghijkmnopqrstuvwxyz',
            '23456789',
            '!@#$%&*-_+=?',
        ];
        $todos = implode('', $juegos);

        do {
            // Al menos uno de cada juego, y el resto de cualquiera.
            $letras = array_map(fn(string $j) => $j[random_int(0, strlen($j) - 1)], $juegos);
            while (count($letras) < $largo) {
                $letras[] = $todos[random_int(0, strlen($todos) - 1)];
            }
            // Fisher-Yates con random_int: el orden también tiene que ser impredecible.
            for ($i = count($letras) - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                [$letras[$i], $letras[$j]] = [$letras[$j], $letras[$i]];
            }
            $clave = implode('', $letras);
        } while (Validator::passwordErrors($clave) !== []);

        return $clave;
    }

    /**
     * Asigna las contraseñas que falten.
     *
     * @return array{
     *   admin: string, demo: string, cuentas_admin: int, cuentas_demo: int,
     *   emails_admin: string[], clave_generada: ?string, errores: string[]
     * } admin: ninguna|configurada|generada|invalida
     *   demo:  ninguna|configurada|sin_clave|invalida
     */
    public static function inicializar(PDO $db, string $adminPass, string $demoPass, int $costo = 12): array
    {
        $resultado = [
            'admin' => 'ninguna', 'demo' => 'ninguna',
            'cuentas_admin' => 0, 'cuentas_demo' => 0,
            'emails_admin' => [], 'clave_generada' => null, 'errores' => [],
        ];

        $st = $db->prepare(
            'SELECT u.id, u.email, r.nombre AS rol
               FROM usuarios u JOIN roles r ON r.id = u.rol_id
              WHERE u.password_hash = ? ORDER BY u.id');
        $st->execute([self::SIN_CLAVE]);

        $admins = [];
        $demas  = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            if ($fila['rol'] === 'administrador') $admins[] = $fila; else $demas[] = $fila;
        }

        $auditoria = new AuditoriaModel();

        // ── Administrador ───────────────────────────────────────────────────
        if ($admins !== []) {
            $clave = null;
            if ($adminPass !== '') {
                $errores = Validator::passwordErrors($adminPass);
                if ($errores === []) {
                    $clave = $adminPass;
                    $resultado['admin'] = 'configurada';
                } else {
                    $resultado['admin'] = 'invalida';
                    $resultado['errores'][] = 'ADMIN_PASS no cumple la política de contraseñas: ' . implode(' ', $errores);
                }
            } else {
                $clave = self::claveAleatoria();
                $resultado['admin'] = 'generada';
                $resultado['clave_generada'] = $clave;
            }

            if ($clave !== null) {
                $origen = $resultado['admin'] === 'generada' ? 'generada al azar' : 'tomada de ADMIN_PASS';
                $resultado['cuentas_admin'] = self::asignar($db, array_column($admins, 'id'), $clave, $costo);
                $resultado['emails_admin']  = array_column($admins, 'email');
                foreach ($admins as $a) {
                    $auditoria->registrar([
                        'usuario_id'     => null,
                        'accion'         => 'cuenta_inicializada',
                        'tabla_afectada' => 'usuarios',
                        'registro_id'    => (int) $a['id'],
                        'descripcion'    => "Contraseña inicial del administrador {$a['email']} ({$origen}).",
                    ]);
                }
            }
        }

        // ── Cuentas de demostración ─────────────────────────────────────────
        if ($demas !== []) {
            if ($demoPass === '') {
                $resultado['demo'] = 'sin_clave';
                $resultado['cuentas_demo'] = count($demas);
            } else {
                $errores = Validator::passwordErrors($demoPass);
                if ($errores !== []) {
                    $resultado['demo'] = 'invalida';
                    $resultado['cuentas_demo'] = count($demas);
                    $resultado['errores'][] = 'DEMO_PASS no cumple la política de contraseñas: ' . implode(' ', $errores);
                } else {
                    $n = self::asignar($db, array_column($demas, 'id'), $demoPass, $costo);
                    $resultado['demo'] = 'configurada';
                    $resultado['cuentas_demo'] = $n;
                    // Una sola fila de auditoría: una por cuenta serían decenas de ruido.
                    $auditoria->registrar([
                        'usuario_id'     => null,
                        'accion'         => 'cuentas_demo_inicializadas',
                        'tabla_afectada' => 'usuarios',
                        'descripcion'    => "{$n} cuentas de demostración recibieron su contraseña desde DEMO_PASS.",
                    ]);
                }
            }
        }

        return $resultado;
    }

    /**
     * Fija $clave en las cuentas $ids que SIGUEN sin contraseña. La condición se
     * repite en el UPDATE para que dos arranques simultáneos no se pisen.
     *
     * @param  int[]|string[] $ids
     */
    private static function asignar(PDO $db, array $ids, string $clave, int $costo): int
    {
        $hash   = password_hash($clave, PASSWORD_BCRYPT, ['cost' => $costo]);
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("UPDATE usuarios SET password_hash = ? WHERE password_hash = ? AND id IN ({$marcas})");
        $st->execute(array_merge([$hash, self::SIN_CLAVE], array_map('intval', $ids)));
        return $st->rowCount();
    }

    /** Punto de entrada de la línea de comandos. Devuelve el código de salida. */
    public static function ejecutarCli(): int
    {
        $raiz = dirname(__DIR__);
        $e = entornoCli($raiz, ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'ADMIN_PASS', 'DEMO_PASS']);

        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $clave) {
            if (isset($e[$clave])) $_ENV[$clave] = $e[$clave];
        }
        spl_autoload_register(function (string $clase) use ($raiz): void {
            foreach ([$raiz . '/core', $raiz . '/app/models'] as $dir) {
                $archivo = "{$dir}/{$clase}.php";
                if (is_file($archivo)) { require_once $archivo; return; }
            }
        });

        try {
            $r = self::inicializar(Database::getInstance(), $e['ADMIN_PASS'] ?? '', $e['DEMO_PASS'] ?? '');
        } catch (Throwable $ex) {
            fwrite(STDERR, 'No se pudieron inicializar las cuentas: ' . $ex->getMessage() . "\n");
            return 1;
        }

        if ($r['admin'] === 'ninguna' && $r['demo'] === 'ninguna') {
            echo "Cuentas iniciales: todas tienen contraseña, sin cambios.\n";
            return 0;
        }

        $cuentas = implode(', ', $r['emails_admin']);
        switch ($r['admin']) {
            case 'configurada':
                echo "Administrador ({$cuentas}): contraseña tomada de ADMIN_PASS.\n";
                break;
            case 'generada':
                echo str_repeat('=', 66) . "\n";
                echo " CONTRASEÑA INICIAL DEL ADMINISTRADOR ({$cuentas})\n\n";
                echo "     {$r['clave_generada']}\n\n";
                echo " Guardala ahora: NO se vuelve a mostrar. Cambiala al ingresar.\n";
                echo " Para elegirla vos, definí ADMIN_PASS en el .env antes del primer arranque.\n";
                echo str_repeat('=', 66) . "\n";
                break;
            case 'invalida':
                fwrite(STDERR, "ADVERTENCIA: la cuenta del administrador SIGUE SIN CONTRASEÑA.\n");
                break;
        }
        switch ($r['demo']) {
            case 'configurada':
                echo "Cuentas de demostración ({$r['cuentas_demo']}): contraseña tomada de DEMO_PASS.\n";
                break;
            case 'sin_clave':
                echo "Cuentas de demostración ({$r['cuentas_demo']}): sin contraseña, nadie puede entrar con ellas.\n";
                echo "    Es lo correcto en producción. Para usarlas, definí DEMO_PASS en el .env y reiniciá.\n";
                break;
            case 'invalida':
                fwrite(STDERR, "ADVERTENCIA: las {$r['cuentas_demo']} cuentas de demostración SIGUEN SIN CONTRASEÑA.\n");
                break;
        }
        foreach ($r['errores'] as $error) {
            fwrite(STDERR, "    {$error}\n");
        }

        return $r['errores'] === [] ? 0 : 1;
    }
}

// Solo corre cuando se invoca como script, no cuando un test lo incluye.
if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(CuentasIniciales::ejecutarCli());
}
