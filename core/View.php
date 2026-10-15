<?php
declare(strict_types=1);

class View
{
    /** Las hojas de estilo del sitio, en el orden en que se cargan (cada una es un archivo de public/assets/css/). */
    public const HOJAS = ['tokens', 'base', 'componentes', 'paginas', 'responsive', 'utilidades'];

    /**
     * La dirección de un archivo de public/ con la fecha de su última modificación (`?v=`): cuando el
     * archivo cambia, la dirección cambia y el navegador no sigue usando la copia vieja. Mientras no
     * cambie, se puede guardar por mucho tiempo (ver el caché de public/.htaccess).
     */
    public static function asset(string $ruta): string
    {
        $archivo = BASE_PATH . '/public' . $ruta;
        return is_file($archivo) ? $ruta . '?v=' . filemtime($archivo) : $ruta;
    }

    /**
     * Renderiza una vista dentro de un layout.
     *
     * @param string $view   Ruta relativa a app/views/, sin .php
     * @param array  $data   Variables a pasar a la vista
     * @param string $layout Nombre del layout (app/views/layouts/{layout}.php) o '' para sin layout
     */
    public static function render(string $view, array $data = [], string $layout = 'public'): void
    {
        // Las páginas que no pasan por un controlador (el 404 del router, las de error) no traen la
        // configuración del sitio: sin ella, el nombre sale del .env y no se muestra contacto.
        $data += ['_sitio' => [
            'nombre'            => defined('APP_NAME') ? APP_NAME : 'FlexArena',
            'contacto'          => '',
            'perfiles_publicos' => true,
        ]];
        extract($data, EXTR_SKIP);

        // Capturar la vista
        ob_start();
        $viewFile = APP_PATH . '/views/' . $view . '.php';
        if (!file_exists($viewFile)) {
            ob_end_clean();
            throw new RuntimeException("Vista no encontrada: {$viewFile}");
        }
        require $viewFile;
        $content = ob_get_clean();

        if ($layout !== '') {
            $layoutFile = APP_PATH . '/views/layouts/' . $layout . '.php';
            if (!file_exists($layoutFile)) {
                throw new RuntimeException("Layout no encontrado: {$layoutFile}");
            }
            require $layoutFile;
        } else {
            echo $content;
        }
    }

    /** Escapa HTML para prevenir XSS */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Escapa y devuelve una URL (solo caracteres seguros) */
    public static function url(string $path): string
    {
        return '/' . ltrim($path, '/');
    }

    /** @var array<string,string> clase => declaraciones, de lo que pidió la página en este pedido */
    private static array $estilosDinamicos = [];

    /**
     * Una clase para un estilo que se calcula al dibujar la página (la posición de una tarjeta del bracket, el
     * ancho de una barra, el tamaño de un avatar). Como el CSP no admite `style="…"`, el estilo va a un
     * bloque `<style nonce>` que imprime el layout (ver estilosDinamicos()) y el elemento lleva la clase.
     *
     * Solo se aceptan propiedades con valores NUMÉRICOS (con rem, px o %): lo que arma el código, nunca
     * texto de una persona. Así lo que se imprime no puede cerrar la regla ni la etiqueta.
     *
     * @throws LogicException si algo no es numérico
     */
    public static function claseDinamica(string $declaraciones): string
    {
        if (!preg_match('/^(?:[a-z-]+:-?(?:\d+\.?\d*|\.\d+)(?:rem|px|%)?;?)+\z/', $declaraciones)) {
            throw new LogicException('Estilo dinámico no permitido: ' . $declaraciones);
        }
        $clase = 'd' . substr(md5($declaraciones), 0, 10);
        self::$estilosDinamicos[$clase] = rtrim($declaraciones, ';');
        return $clase;
    }

    /** El CSS de las clases dinámicas que pidió la página: lo imprime el layout dentro de un `<style nonce>`. */
    public static function estilosDinamicos(): string
    {
        $css = '';
        foreach (self::$estilosDinamicos as $clase => $declaraciones) {
            $css .= '.' . $clase . '{' . $declaraciones . "}\n";
        }
        return $css;
    }

    /** Descarta los estilos dinámicos pedidos (para los tests, que simulan varios pedidos en un proceso). */
    public static function olvidarEstilosDinamicos(): void
    {
        self::$estilosDinamicos = [];
    }

    /** Avatar: imagen si hay URL, si no un círculo con iniciales. El tamaño va en una clase dinámica. */
    public static function avatar(string $nombre, ?string $url, int $size = 64): string
    {
        $s = max(1, (int) $size);
        if ($url) {
            return '<img src="' . self::e($url) . '" alt="' . self::e($nombre) . '"'
                 . ' class="avatar avatar-img ' . self::claseDinamica("width:{$s}px;height:{$s}px") . '">';
        }
        $parts = preg_split('/\s+/', trim($nombre)) ?: [''];
        $ini = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
        $clase = self::claseDinamica("width:{$s}px;height:{$s}px;font-size:" . round($s * 0.38, 1) . 'px');
        return '<span class="avatar avatar-ini ' . $clase . '">' . self::e($ini ?: '?') . '</span>';
    }

    /** Formatea una fecha+hora a "dd/mm/aaaa HH:MM" (zona horaria de la app). */
    public static function fechaHora(?string $dt, string $placeholder = '—'): string
    {
        if (empty($dt) || str_starts_with((string)$dt, '0000-00-00')) return $placeholder;
        $ts = strtotime((string)$dt);
        return $ts === false ? $placeholder : date('d/m/Y H:i', $ts);
    }

    /** Formatea solo la fecha a "dd/mm/aaaa". */
    public static function fecha(?string $dt, string $placeholder = '—'): string
    {
        if (empty($dt) || str_starts_with((string)$dt, '0000-00-00')) return $placeholder;
        $ts = strtotime((string)$dt);
        return $ts === false ? $placeholder : date('d/m/Y', $ts);
    }

    /** Formatea un puntaje: sin decimales si es entero («3»), con coma si no («2,5»). */
    public static function numero(mixed $valor): string
    {
        return rtrim(rtrim(number_format((float) $valor, 2, ',', ''), '0'), ',');
    }

    /**
     * El resultado de un partido que viene de EnfrentamientoModel::getByRonda(), o null si todavía no
     * tiene. Las vistas lo usan en lugar de pedirlo a la base: antes cada partido dibujado hacía su
     * propia consulta desde la vista.
     *
     * @param array<string,mixed> $partido
     * @return array{puntos_a:mixed,puntos_b:mixed,corregido:bool}|null
     */
    public static function resultadoDe(array $partido): ?array
    {
        if (($partido['puntos_a'] ?? null) === null) return null;
        return [
            'puntos_a'  => $partido['puntos_a'],
            'puntos_b'  => $partido['puntos_b'] ?? 0,
            'corregido' => (bool) ($partido['resultado_corregido'] ?? false),
        ];
    }

    /** Genera un chip/badge de estado con la clase CSS correspondiente */
    public static function estadoChip(string $estado): string
    {
        $map = [
            'activo'      => 'success',
            'finalizado'  => 'success',
            'en_curso'    => '',           // primary (azul por defecto)
            'pendiente'   => 'warning',
            'inscripcion' => 'warning',
            'corregido'   => 'warning',
            'cerrada'     => 'success',
            'borrador'    => 'warning',
            'suspendido'  => 'danger',
            'cancelado'   => 'danger',
            'bloqueada'   => 'danger',
            'inactivo'    => 'danger',
            'rechazado'   => 'danger',
            'bye'         => 'warning',
        ];

        $clase  = $map[$estado] ?? '';
        $claseHtml = $clase ? " {$clase}" : '';
        $label  = ucfirst(str_replace('_', ' ', $estado));

        return '<span class="chip' . $claseHtml . '">' . self::e($label) . '</span>';
    }
}
