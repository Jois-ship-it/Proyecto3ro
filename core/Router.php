<?php
declare(strict_types=1);

class Router
{
    private array $routes = [];

    public function get(string $path, string $controller, string $action): void
    {
        $this->addRoute('GET', $path, $controller, $action);
    }

    public function post(string $path, string $controller, string $action): void
    {
        $this->addRoute('POST', $path, $controller, $action);
    }

    private function addRoute(string $method, string $path, string $controller, string $action): void
    {
        $this->routes[] = [
            'method'     => $method,
            'path'       => $path,
            'controller' => $controller,
            'action'     => $action,
            'pattern'    => $this->buildPattern($path),
            'params'     => $this->extractParamNames($path),
        ];
    }

    /**
     * Qué valores acepta cada parámetro de ruta, según su nombre.
     *
     * Antes «{id}» compilaba a ([^/]+) —cualquier cosa que no sea una barra— y
     * el valor llegaba crudo al controlador, que hace (int)$id. Como (int)'1 OR
     * 1=1' vale 1, la URL /torneo/1 OR 1=1 devolvía la página del torneo 1 con
     * código 200. No era inyección (los modelos usan consultas preparadas), pero
     * una URL inválida no puede devolver una página válida: si el id no es un id,
     * la ruta no existe y corresponde un 404.
     *
     * La lista es restrictiva a propósito. Si alguna vez hace falta un parámetro
     * que no sea un id —un slug, por ejemplo— hay que agregarlo acá eligiendo qué
     * acepta; un nombre sin entrada rompe al registrar la ruta, no en silencio.
     */
    private const PATRONES = [
        // Entero positivo, sin signo, sin ceros a la izquierda y de hasta 10
        // dígitos: las claves primarias son INT UNSIGNED (máximo 4294967295),
        // así que nada más largo puede ser un id de este sistema.
        'id' => '([1-9][0-9]{0,9})',
    ];

    private function buildPattern(string $path): string
    {
        // La ruta se parte en literales y parámetros para poder escapar los
        // literales: así el texto de la ruta nunca se interpreta como regex.
        $partes = preg_split('/(\{[a-zA-Z_][a-zA-Z0-9_]*\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        $patron = '';
        foreach ($partes as $parte) {
            if (!preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $parte, $m)) {
                $patron .= preg_quote($parte, '#');
                continue;
            }

            $nombre = $m[1];
            if (!isset(self::PATRONES[$nombre])) {
                throw new RuntimeException(
                    "Ruta «{$path}»: el parámetro «{$nombre}» no tiene patrón definido. "
                    . 'Agregalo a Router::PATRONES decidiendo qué valores acepta.'
                );
            }
            $patron .= self::PATRONES[$nombre];
        }

        // Modificador D: sin él, «$» también matchea justo antes de un salto de
        // línea final, y "torneo/1\n" pasaría como si fuera "torneo/1".
        return '#^' . $patron . '$#D';
    }

    private function extractParamNames(string $path): array
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $path, $m);
        return $m[1];
    }

    public function dispatch(string $url, string $method): void
    {
        // Normalizar el método (puede llegar POST disfrazado via _method)
        if ($method === 'POST' && isset($_POST['_method'])) {
            $method = strtoupper($_POST['_method']);
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($method)) continue;

            if (preg_match($route['pattern'], $url, $matches)) {
                array_shift($matches);

                // Construir array de parámetros con nombre
                $params = [];
                foreach ($route['params'] as $i => $name) {
                    $params[$name] = $matches[$i] ?? null;
                }

                $controllerClass = $route['controller'];
                $action          = $route['action'];

                $controllerFile = APP_PATH . '/controllers/' . $controllerClass . '.php';
                if (!file_exists($controllerFile)) {
                    $this->abort(500, "Controlador no encontrado: {$controllerClass}");
                    return;
                }
                require_once $controllerFile;

                if (!class_exists($controllerClass)) {
                    $this->abort(500, "Clase no encontrada: {$controllerClass}");
                    return;
                }

                $controller = new $controllerClass();

                if (!method_exists($controller, $action)) {
                    $this->abort(500, "Acción no encontrada: {$controllerClass}::{$action}");
                    return;
                }

                // Pasar parámetros posicionales (por order en la URL)
                $controller->$action(...array_values($params));
                return;
            }
        }

        // Ninguna ruta coincide
        $this->abort(404);
    }

    private function abort(int $code, string $msg = ''): void
    {
        http_response_code($code);
        if ($code === 404) {
            View::render('shared/404', [], 'public');
        } else {
            if (APP_ENV === 'development' && $msg) {
                echo "<pre>Error {$code}: {$msg}</pre>";
            } else {
                View::render('shared/404', [], 'public');
            }
        }
    }
}
