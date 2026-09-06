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

    private function buildPattern(string $path): string
    {
        $p = preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', '([^/]+)', $path);
        return '#^' . $p . '$#';
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
