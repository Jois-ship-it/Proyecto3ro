<?php
declare(strict_types=1);

/**
 * Los ajustes generales del sistema (tabla `configuracion_sistema`): leerlos y, el administrador,
 * cambiarlos desde la pantalla de configuración.
 *
 * Los límites de inicio de sesión estaban escritos en el código (`MAX_FAILED_ATTEMPTS = 5`) y la
 * tabla de ajustes, sembrada desde octubre, no la leía nadie. Ahora el código lee de ahí, así que
 * cambiar cuántos intentos se admiten o cuánto dura un bloqueo no exige tocar el programa. El
 * nombre del sitio, el correo de contacto y el interruptor de perfiles públicos también se leen de
 * acá.
 *
 * Si un ajuste no está en la base (se borró la fila, o es una instalación vieja) o trae un valor
 * que no sirve (texto donde va un número, un 0 donde el mínimo es 1), se usa el valor de fábrica:
 * una fila mal escrita no puede dejar el login sin límites ni dejar a todos afuera.
 */
class ConfiguracionSistemaService
{
    /** El valor de cada ajuste cuando la base no lo tiene. Es el mismo que trae seed.sql. */
    public const POR_DEFECTO = [
        'sitio_nombre'           => 'FlexArena',
        'sitio_contacto'         => '',
        'perfiles_publicos'      => '1',
        'login_max_intentos'     => '5',
        'login_minutos_bloqueo'  => '15',
        'login_max_intentos_ip'  => '20',
        'login_ventana_minutos'  => '15',
    ];

    /**
     * Qué es cada ajuste y qué acepta. Es lo que valida `guardar()` y lo que dibuja la pantalla de
     * configuración (el controlador se lo pasa a la vista), así no hay dos listas que mantener.
     *
     *  - tipo: 'texto' | 'email' | 'interruptor' | 'entero'
     *  - grupo: 'sitio' | 'acceso' (cómo se agrupan en la pantalla)
     *  - descripcion: el texto de la fila en la base (el mismo de seed.sql)
     *  - min/max: los límites de un entero, o el largo máximo de un texto
     *
     * Los mínimos de los enteros son los que usa `AuthService` al leerlos con `getInt()`.
     */
    public const CAMPOS = [
        'sitio_nombre' => [
            'grupo' => 'sitio', 'tipo' => 'texto', 'max' => 60, 'obligatorio' => true,
            'etiqueta' => 'Nombre del sitio',
            'ayuda' => 'Aparece en el menú, en el título de cada página y en el pie.',
            'descripcion' => 'Nombre de la plataforma, tal como aparece en el sitio.',
        ],
        'sitio_contacto' => [
            'grupo' => 'sitio', 'tipo' => 'email', 'max' => 150,
            'etiqueta' => 'Correo de contacto',
            'ayuda' => 'Se muestra en el pie de página. Vacío = no se muestra.',
            'descripcion' => 'Correo de contacto que se muestra en el pie de página. Vacío = no se muestra.',
        ],
        'perfiles_publicos' => [
            'grupo' => 'sitio', 'tipo' => 'interruptor',
            'etiqueta' => 'Perfiles públicos',
            'ayuda' => 'Activado: cualquiera ve los perfiles de participantes y equipos. Desactivado: solo quien inició sesión.',
            'descripcion' => '1 = los perfiles de participantes y equipos se ven sin iniciar sesión; 0 = solo con sesión.',
        ],
        'login_max_intentos' => [
            'grupo' => 'acceso', 'tipo' => 'entero', 'min' => 1, 'max' => 20,
            'etiqueta' => 'Intentos fallidos antes de bloquear una cuenta',
            'ayuda' => 'Al llegar a este número de intentos seguidos con la clave equivocada, la cuenta se bloquea.',
            'descripcion' => 'Intentos de inicio de sesión fallidos antes de bloquear la cuenta.',
        ],
        'login_minutos_bloqueo' => [
            'grupo' => 'acceso', 'tipo' => 'entero', 'min' => 0, 'max' => 1440,
            'etiqueta' => 'Minutos que dura el bloqueo',
            'ayuda' => '0 = hasta que un administrador la desbloquee. El único administrador activo nunca queda bloqueado para siempre.',
            'descripcion' => 'Minutos que dura el bloqueo por intentos fallidos. 0 = hasta que un administrador la desbloquee.',
        ],
        'login_max_intentos_ip' => [
            'grupo' => 'acceso', 'tipo' => 'entero', 'min' => 1, 'max' => 1000,
            'etiqueta' => 'Intentos fallidos por dirección IP',
            'ayuda' => 'Intentos fallidos desde una misma dirección, dentro de la ventana, antes de rechazar sus pedidos.',
            'descripcion' => 'Intentos fallidos desde una misma IP, dentro de la ventana, antes de rechazar sus pedidos.',
        ],
        'login_ventana_minutos' => [
            'grupo' => 'acceso', 'tipo' => 'entero', 'min' => 1, 'max' => 1440,
            'etiqueta' => 'Ventana para contar intentos por IP (minutos)',
            'ayuda' => 'Los intentos por dirección IP se cuentan sobre este período.',
            'descripcion' => 'Ventana, en minutos, sobre la que se cuentan los intentos fallidos por IP.',
        ],
    ];

    /** @var array<string, string>|null los ajustes, leídos una sola vez por pedido */
    private static ?array $cache = null;

    public function get(string $clave): string
    {
        self::$cache ??= (new ConfiguracionSistemaModel())->todos();
        return self::$cache[$clave] ?? self::POR_DEFECTO[$clave] ?? '';
    }

    /**
     * Un ajuste numérico. Si el valor no es un entero o es menor que $minimo, se usa el de fábrica.
     */
    public function getInt(string $clave, int $minimo = 0): int
    {
        $valor = trim($this->get($clave));
        if (preg_match('/^\d{1,9}$/', $valor) === 1 && (int) $valor >= $minimo) {
            return (int) $valor;
        }
        return (int) (self::POR_DEFECTO[$clave] ?? $minimo);
    }

    /**
     * ¿Los perfiles de participantes y equipos se ven sin iniciar sesión?
     * Solo un «0» los cierra: cualquier otro valor (vacío, texto) es el de fábrica, que es abierto.
     */
    public function perfilesPublicos(): bool
    {
        return trim($this->get('perfiles_publicos')) !== '0';
    }

    /**
     * Lo que muestra cada página del sitio de la configuración: el nombre, el correo de contacto
     * y si los perfiles están abiertos. Lo agrega BaseController::render() a todas las vistas.
     *
     * @return array{nombre:string,contacto:string,perfiles_publicos:bool}
     */
    public function sitio(): array
    {
        $nombre   = trim($this->get('sitio_nombre'));
        $contacto = trim($this->get('sitio_contacto'));
        return [
            'nombre'            => $nombre !== '' ? $nombre : self::POR_DEFECTO['sitio_nombre'],
            // Un correo mal escrito en la base no se muestra como enlace.
            'contacto'          => filter_var($contacto, FILTER_VALIDATE_EMAIL) !== false ? $contacto : '',
            'perfiles_publicos' => $this->perfilesPublicos(),
        ];
    }

    /**
     * Todos los ajustes para dibujar la pantalla de configuración: lo que hay en la base, o el valor de
     * fábrica donde falta o no sirve. Lee de la base y no del caché de este pedido.
     *
     * @return array<string,string> clave => valor
     */
    public function valores(): array
    {
        $enBase = (new ConfiguracionSistemaModel())->todos();
        $valores = [];
        foreach (self::CAMPOS as $clave => $_) {
            $valores[$clave] = $enBase[$clave] ?? self::POR_DEFECTO[$clave];
        }
        return $valores;
    }

    /**
     * Comprueba lo que mandó el formulario y lo deja en su forma normal («  Mi liga » → «Mi liga»,
     * «007» → «7»). Solo mira las claves que vengan: una que falta no se toca, y una que no es un
     * ajuste se ignora. Es una función pura (no lee ni escribe nada) para poder probarla sola.
     *
     * @param array<string,mixed> $datos
     * @return array<string,string> clave => valor normalizado, solo de las claves recibidas
     * @throws ErrorNegocio con el motivo para quien llenó el formulario
     */
    public function validar(array $datos): array
    {
        $nuevos = [];
        foreach (self::CAMPOS as $clave => $campo) {
            if (!array_key_exists($clave, $datos)) continue;
            $crudo = $datos[$clave];
            if (!is_string($crudo) && !is_int($crudo) && !is_bool($crudo)) {
                throw new ErrorNegocio("{$campo['etiqueta']}: el valor no es válido.");
            }
            $nuevos[$clave] = $this->normalizar($clave, $campo, trim((string) (is_bool($crudo) ? (int) $crudo : $crudo)));
        }
        return $nuevos;
    }

    /**
     * Guarda los ajustes que cambiaron, deja una sola entrada en la auditoría con los valores de
     * antes y de después, y devuelve cuáles cambiaron. Si nada cambió no escribe nada.
     *
     * Solo un administrador puede: se comprueba acá, con el id de quien actúa, y no solo en el
     * controlador, para que ninguna ruta nueva se lo salte.
     *
     * @param array<string,mixed> $datos lo que mandó el formulario
     * @return array<string,array{antes:string,despues:string}> clave => valores, solo de los que cambiaron
     * @throws ErrorNegocio si no es un administrador o algún valor no sirve (no se guarda ninguno)
     */
    public function guardar(array $datos, int $actorId): array
    {
        $actor = (new UsuarioModel())->findByIdConRol($actorId);
        if (!$actor || $actor['rol_nombre'] !== 'administrador') {
            throw new ErrorNegocio('Solo un administrador puede cambiar la configuración del sistema.');
        }

        $nuevos = $this->validar($datos);

        $cambios = Database::transaccion(function () use ($nuevos, $actorId): array {
            $modelo   = new ConfiguracionSistemaModel();
            $actuales = $this->valores();
            $cambios  = [];
            foreach ($nuevos as $clave => $valor) {
                if ($actuales[$clave] === $valor) continue;
                $modelo->guardar($clave, $valor, self::CAMPOS[$clave]['descripcion'], $actorId);
                $cambios[$clave] = ['antes' => $actuales[$clave], 'despues' => $valor];
            }
            if ($cambios !== []) {
                $etiquetas = array_map(fn(string $c) => mb_strtolower(self::CAMPOS[$c]['etiqueta']), array_keys($cambios));
                (new AuditoriaService())->log(
                    'actualizar_configuracion', 'configuracion_sistema', 0,
                    'Configuración del sistema actualizada: ' . implode(', ', $etiquetas) . '.',
                    array_map(fn(array $c) => $c['antes'], $cambios),
                    array_map(fn(array $c) => $c['despues'], $cambios),
                    $actorId
                );
            }
            return $cambios;
        });

        self::olvidar();
        return $cambios;
    }

    /** Descarta lo leído: para cuando un ajuste cambia en medio del mismo pedido (y en los tests). */
    public static function olvidar(): void
    {
        self::$cache = null;
    }

    /** @param array<string,mixed> $campo */
    private function normalizar(string $clave, array $campo, string $valor): string
    {
        $etiqueta = $campo['etiqueta'];

        switch ($campo['tipo']) {
            case 'interruptor':
                if (!in_array($valor, ['0', '1'], true)) {
                    throw new ErrorNegocio("{$etiqueta}: el valor tiene que ser activado o desactivado.");
                }
                return $valor;

            case 'entero':
                if (preg_match('/^\d{1,9}$/', $valor) !== 1 || (int) $valor < $campo['min'] || (int) $valor > $campo['max']) {
                    throw new ErrorNegocio("{$etiqueta} tiene que ser un número entero entre {$campo['min']} y {$campo['max']}.");
                }
                return (string) (int) $valor;

            case 'email':
                if ($valor === '') return '';
                // Un correo no admite espacios ni saltos de línea: sin esto, «a@b.com\nBcc: ...» llegaría a un enlace mailto.
                if (mb_strlen($valor) > $campo['max'] || filter_var($valor, FILTER_VALIDATE_EMAIL) === false) {
                    throw new ErrorNegocio("{$etiqueta} no es una dirección de correo válida.");
                }
                return $valor;

            default: // texto
                if ($valor === '' && !empty($campo['obligatorio'])) {
                    throw new ErrorNegocio("{$etiqueta} no puede quedar vacío.");
                }
                if (preg_match('/[\x00-\x1F\x7F]/u', $valor) === 1 || !mb_check_encoding($valor, 'UTF-8')) {
                    throw new ErrorNegocio("{$etiqueta} tiene caracteres no permitidos.");
                }
                if (mb_strlen($valor) > $campo['max']) {
                    throw new ErrorNegocio("{$etiqueta} admite hasta {$campo['max']} caracteres.");
                }
                return $valor;
        }
    }
}
