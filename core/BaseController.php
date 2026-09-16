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

    /**
     * Corta la acción si el rol del usuario no tiene ese permiso sobre ese
     * módulo (tabla `permisos`). El administrador pasa siempre: la letra le da
     * control completo del sistema (§5.1).
     *
     * Es la segunda de las tres compuertas —rol, permiso de módulo y propiedad
     * del torneo— y no reemplaza a las otras: se llama DESPUÉS de requireRole()
     * y junto a requireTorneoOwnership() donde corresponda.
     *
     * @param string      $redirectUrl Si se pasa, avisa con un flash y redirige,
     *                                 que es lo razonable después de un POST. Sin
     *                                 él muestra el 403, igual que requireRole().
     */
    protected function requirePermiso(string $modulo, string $accion, string $redirectUrl = ''): void
    {
        if ((new PermisoService())->puedeUsuarioActual($modulo, $accion)) {
            return;
        }

        if ($redirectUrl !== '') {
            $this->flash('error', "Tu rol no tiene permiso para {$accion} en el módulo «{$modulo}». "
                                . 'Un administrador puede habilitarlo desde Sistema → Permisos.');
            $this->redirect($redirectUrl);
        }

        http_response_code(403);
        $this->render('shared/403', [], 'public');
        exit;
    }

    /**
     * Un organizador solo puede gestionar SUS torneos; el administrador, todos.
     * Centraliza la regla que antes estaba duplicada (y parcialmente ausente)
     * entre TorneoController, OrganizadorController y ResultadoController.
     */
    protected function requireTorneoOwnership(int $torneoId, string $redirectUrl): void
    {
        if (Auth::isAdmin()) return;
        $ownerId = (new TorneoModel())->getOrganizadorId($torneoId);
        if ($ownerId !== (int)Auth::id()) {
            $this->flash('error', 'No tenés acceso a este torneo.');
            $this->redirect($redirectUrl);
        }
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
