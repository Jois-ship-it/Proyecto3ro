<?php
declare(strict_types=1);

abstract class BaseController
{
    protected function render(string $view, array $data = [], string $layout = 'public'): void
    {
        // Inyectar usuario y flash automáticamente
        $data['_user']  = Auth::user();
        $data['_sitio'] = (new ConfiguracionSistemaService())->sitio();   // nombre, contacto, perfiles (ajustes del sistema)
        $data['_pendientes'] = $this->pendientesDelMenu($layout);
        $data['_flash'] = [
            'success' => Session::getFlash('success'),
            'error'   => Session::getFlash('error'),
            'warning' => Session::getFlash('warning'),
            'info'    => Session::getFlash('info'),
        ];

        View::render($view, $data, $layout);
    }

    /**
     * Los números que muestra el menú lateral del administrador (registros por aprobar y
     * correcciones por resolver).
     *
     * Los calcula el controlador y no la vista: el menú es parte de cada pantalla del panel, pero una
     * vista no consulta la base. Solo se piden para el administrador en el layout del panel; para
     * cualquier otro rol (o una página pública) no se hace ninguna consulta.
     *
     * @return array{registros:int,correcciones:int}
     */
    protected function pendientesDelMenu(string $layout): array
    {
        if ($layout !== 'admin' || Auth::rol() !== 'administrador') {
            return ['registros' => 0, 'correcciones' => 0];
        }
        return [
            'registros'    => (new RegistroService())->countPendientes(),
            'correcciones' => (new CorreccionService())->countPendientes(),
        ];
    }

    /**
     * Redirige, pero nunca fuera del sitio.
     *
     * El filtro va acá adentro a propósito, además de en cada lugar que arma un
     * destino con datos del pedido: así un controlador nuevo que se olvide de
     * filtrar no abre un agujero, solo manda al visitante a la portada. Ver
     * `core/Url.php`.
     */
    protected function redirect(string $url): never
    {
        header('Location: ' . Url::interna($url, '/'));
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

        // Un fetch espera JSON: no se lo redirige a una página, se le responde 403 (lo arma ManejadorErrores).
        if ($redirectUrl !== '' && !ManejadorErrores::esPeticionApi()) {
            $this->flash('error', "Tu rol no tiene permiso para {$accion} en el módulo «{$modulo}». "
                                . 'Un administrador puede habilitarlo desde Sistema → Permisos.');
            $this->redirect($redirectUrl);
        }

        throw new AccesoDenegado("Tu rol no tiene permiso para {$accion} en el módulo «{$modulo}».");
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
            // Un fetch espera JSON: se le responde 403 en vez de redirigirlo a una página.
            if (ManejadorErrores::esPeticionApi()) {
                throw new AccesoDenegado('No tenés acceso a este torneo.');
            }
            $this->flash('error', 'No tenés acceso a este torneo.');
            $this->redirect($redirectUrl);
        }
    }

    /**
     * Lo mismo que requireTorneoOwnership(), a partir de un partido: el organizador solo opera
     * sobre partidos de SUS torneos. Si el partido no existe no hace nada y deja que el servicio
     * avise «no encontrado».
     */
    protected function requirePartidoPropio(int $enfrentamientoId, string $redirectUrl): void
    {
        $enf = (new EnfrentamientoModel())->findById($enfrentamientoId);
        if (!$enf) return;
        $this->requireTorneoOwnership((int) $enf['torneo_id'], $redirectUrl);
    }

    /**
     * Cierra una acción de formulario que se puede disparar de dos maneras: con el formulario
     * común (flash y redirección, como siempre) o con un fetch, que espera JSON.
     *
     *   - Con un fetch: el éxito responde {"ok":true,"datos":{"mensaje":…,"aviso":…}} y un fallo de
     *     negocio, 422 con su mensaje (un fallo técnico, 500 con el código de referencia). El
     *     aviso de éxito queda también como flash: lo muestra la página que el cliente recarga.
     *   - Sin fetch: el flash de éxito o de error y la redirección a $destino.
     *
     * @param RuntimeException|null $falla  lo que atrapó la acción, o null si salió bien
     * @param string|null           $aviso  un segundo aviso del servicio (por ejemplo «el campeón cambió»)
     */
    protected function terminarAccion(?RuntimeException $falla, string $exito, string $destino, ?string $aviso = null): never
    {
        $api = ManejadorErrores::esPeticionApi();

        if ($falla !== null) {
            $mensaje = $this->mensajeDe($falla);
            if ($api) {
                $estado = $falla instanceof ErrorNegocio ? 422 : 500;
                $this->responderJson(Respuesta::cuerpoError(Respuesta::codigoPara($estado), $mensaje), $estado);
            }
            $this->flash('error', $mensaje);
            $this->redirect($destino);
        }

        $this->flash('success', $exito);
        if ($aviso !== null) $this->flash('warning', $aviso);
        if ($api) {
            $this->responderJson(Respuesta::cuerpoOk(['mensaje' => $exito, 'aviso' => $aviso]), 200);
        }
        $this->redirect($destino);
    }

    /**
     * Envía una respuesta JSON y termina. Es un método aparte (y no una llamada directa a
     * Respuesta::enviar()) para que los tests lo sustituyan y miren el cuerpo sin cabeceras ni exit.
     */
    protected function responderJson(array $cuerpo, int $estado = 200): never
    {
        Respuesta::enviar($cuerpo, $estado);
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

    /**
     * El texto que se le muestra a la persona cuando una acción falló.
     *
     * Un ErrorNegocio es una regla que no se cumplió y su mensaje está escrito para
     * mostrarse. Cualquier otra cosa que llegue hasta acá —sobre todo un PDOException, que
     * también es un RuntimeException— habla de tablas y consultas: se registra con un código
     * de referencia y la persona ve un aviso genérico con ese código. Los controladores NO
     * deben mostrar getMessage() directamente (lo comprueba tests/manejador_errores_test.php).
     */
    protected function mensajeDe(Throwable $e): string
    {
        if ($e instanceof ErrorNegocio) {
            return $e->getMessage();
        }
        $id = ManejadorErrores::registrarEnLog($e);
        return ManejadorErrores::MENSAJE_GENERICO . " (código {$id})";
    }
}
