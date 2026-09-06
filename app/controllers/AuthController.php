<?php
declare(strict_types=1);

class AuthController extends BaseController
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    public function loginForm(): void
    {
        if (Auth::isLoggedIn()) {
            $this->redirect($this->urlRolPrincipal());
        }
        $this->render('auth/login', ['pageTitle' => 'Iniciar sesión'], '');
    }

    // ─── Registro público de participantes ──────────────────────

    public function registroForm(): void
    {
        if (Auth::isLoggedIn()) {
            $this->redirect($this->urlRolPrincipal());
        }
        $this->render('auth/registro', [
            'pageTitle' => 'Crear cuenta',
            'old'       => Session::getFlash('old') ?? [],
        ], '');
    }

    public function registro(): void
    {
        $this->checkCsrf();
        $datos = [
            'nombre'           => $this->postStr('nombre'),
            'email'            => $this->postStr('email'),
            'nick'             => $this->postStr('nick'),
            'telefono'         => $this->postStr('telefono'),
            'documento'        => $this->postStr('documento'),
            'password'         => $this->post('password', ''),
            'password_confirm' => $this->post('password_confirm', ''),
        ];
        try {
            (new RegistroService())->registrar($datos);
            Session::flash('success', 'Tu cuenta fue creada y quedó pendiente de aprobación. Vas a poder iniciar sesión cuando un administrador la apruebe.');
            $this->redirect('/login');
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            // Conservar lo ingresado (menos la contraseña)
            unset($datos['password'], $datos['password_confirm']);
            Session::flash('old', $datos);
            $this->redirect('/registro');
        }
    }

    public function login(): void
    {
        $this->checkCsrf();

        $email    = $this->postStr('email');
        $password = $this->post('password', '');

        try {
            $user = $this->authService->login($email, (string)$password);
            $this->redirect($this->urlRolPrincipal($user['rol_nombre']));
        } catch (RuntimeException $e) {
            Session::flash('error', $e->getMessage());
            $this->redirect('/login');
        }
    }

    public function logout(): void
    {
        $this->requireLogin();
        $this->authService->logout();
        Session::flash('success', 'Sesión cerrada correctamente.');
        $this->redirect('/login');
    }

    private function urlRolPrincipal(string $rol = ''): string
    {
        $rol = $rol ?: (Auth::rol() ?? '');
        return match ($rol) {
            'administrador' => '/admin',
            'organizador'   => '/organizador',
            'participante'  => '/participante',
            default         => '/',
        };
    }
}
