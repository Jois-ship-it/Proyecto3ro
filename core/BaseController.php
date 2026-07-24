<?php
declare(strict_types=1);

abstract class BaseController
{
    protected function render(string $view, array $data = [], string $layout = 'public'): void
    {
        // Inyectar usuario y flash automáticamente
        $data['_user']  = Auth::user();
        $data['_flash'] = [
            'success' => Session::getFlash('success'),
            'error'   => Session::getFlash('error'),
            'warning' => Session::getFlash('warning'),
            'info'    => Session::getFlash('info'),
        ];

        View::render($view, $data, $layout);
    }

    protected function redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }

    protected function flash(string $tipo, string $mensaje): void
    {
        Session::flash($tipo, $mensaje);
    }

    protected function checkCsrf(): void
    {
        Csrf::checkOrFail();
    }

    protected function requireLogin(): void
    {
        Auth::requireLogin();
    }

    protected function requireAdmin(): void
    {
        Auth::requireAdmin();
    }

    protected function requireOrganizador(): void
    {
        Auth::requireOrganizador();
    }

    protected function requireRole(array $roles): void
    {
        Auth::requireRole($roles);
    }

    protected function post(string $key, mixed $default = null): mixed
    {
        return isset($_POST[$key]) ? $_POST[$key] : $default;
    }

    protected function get(string $key, mixed $default = null): mixed
    {
        return isset($_GET[$key]) ? $_GET[$key] : $default;
    }

    protected function postInt(string $key, int $default = 0): int
    {
        return (int) ($this->post($key, $default));
    }

    protected function postFloat(string $key, float $default = 0.0): float
    {
        return (float) ($this->post($key, $default));
    }

    protected function postStr(string $key, string $default = ''): string
    {
        $v = $this->post($key, $default);
        return is_string($v) ? trim($v) : $default;
    }

    protected function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
