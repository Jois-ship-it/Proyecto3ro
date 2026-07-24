<?php
declare(strict_types=1);

class View
{
    /**
     * Renderiza una vista dentro de un layout.
     *
     * @param string $view   Ruta relativa a app/views/, sin .php
     * @param array  $data   Variables a pasar a la vista
     * @param string $layout Nombre del layout (app/views/layouts/{layout}.php) o '' para sin layout
     */
    public static function render(string $view, array $data = [], string $layout = 'public'): void
    {
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

    /** Avatar: imagen si hay URL, si no un círculo con iniciales. */
    public static function avatar(string $nombre, ?string $url, int $size = 64): string
    {
        $s = (int)$size;
        if ($url) {
            return '<img src="' . self::e($url) . '" alt="' . self::e($nombre) . '"'
                 . ' style="width:' . $s . 'px;height:' . $s . 'px;border-radius:50%;object-fit:cover;'
                 . 'border:1px solid var(--line);box-shadow:0 6px 18px rgba(0,0,0,.2)">';
        }
        $parts = preg_split('/\s+/', trim($nombre)) ?: [''];
        $ini = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
        return '<span style="width:' . $s . 'px;height:' . $s . 'px;border-radius:50%;display:inline-grid;'
             . 'place-items:center;background:linear-gradient(135deg,var(--primary),var(--primary-dark));'
             . 'color:#fff;font-weight:800;font-size:' . round($s * 0.38, 1) . 'px;border:1px solid var(--line)">'
             . self::e($ini ?: '?') . '</span>';
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

    /** Genera un chip/badge de estado con la clase CSS correspondiente */
    public static function estadoChip(string $estado): string
    {
        $map = [
            'activo'      => 'success',
            'finalizado'  => 'success',
            'en_curso'    => '',           // primary (azul por defecto)
            'pendiente'   => 'warning',
            'programado'  => 'warning',
            'inscripcion' => 'warning',
            'corregido'   => 'warning',
            'borrador'    => 'warning',
            'cancelado'   => 'danger',
            'bloqueado'   => 'danger',
            'inactivo'    => 'danger',
            'bye'         => 'warning',
            'pospuesto'   => 'warning',
        ];

        $clase  = $map[$estado] ?? '';
        $claseHtml = $clase ? " {$clase}" : '';
        $label  = ucfirst(str_replace('_', ' ', $estado));

        return '<span class="chip' . $claseHtml . '">' . self::e($label) . '</span>';
    }
}
