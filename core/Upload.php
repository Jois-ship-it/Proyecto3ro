<?php
declare(strict_types=1);

/**
 * Subida segura de imágenes (avatars de participantes, logos de equipos).
 *
 * Seguridad:
 *  - Valida que el archivo sea realmente una imagen (getimagesize), no por extensión.
 *  - Whitelist de MIME → extensión.
 *  - Tamaño máximo del archivo y de la imagen (píxeles): ver Imagen::PIXELES_MAXIMOS.
 *  - La imagen se decodifica, se achica y se vuelve a codificar (ver Imagen): lo guardado es solo
 *    la imagen, sin metadatos ni nada pegado al final del archivo.
 *  - Nombre aleatorio (no se usa el nombre original).
 *  - La carpeta /uploads tiene .htaccess que desactiva la ejecución de scripts.
 */
class Upload
{
    public const MAX_BYTES = 2 * 1024 * 1024; // 2 MB

    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    private const SUBDIRS = ['avatars', 'logos'];

    /**
     * Procesa un archivo de $_FILES y devuelve el nombre guardado.
     * @param array  $file    Entrada de $_FILES (p. ej. $_FILES['foto'])
     * @param string $subdir  'avatars' | 'logos'
     * @return string|null     Nombre del archivo guardado, o null si no se subió nada.
     */
    public static function image(array $file, string $subdir): ?string
    {
        if (!in_array($subdir, self::SUBDIRS, true)) {
            throw new RuntimeException('Destino de subida inválido.');
        }

        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) {
            return null; // El campo es opcional: no se subió imagen.
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            // El servidor rechazó el archivo por pesado antes de que llegara acá (upload_max_filesize): mismo motivo que abajo.
            throw new ErrorNegocio('La imagen supera el máximo de 2 MB.');
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new ErrorNegocio('Error al subir la imagen (código ' . $err . ').');
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new ErrorNegocio('La imagen supera el máximo de 2 MB.');
        }
        if (!is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new ErrorNegocio('Subida inválida.');
        }

        return self::guardar((string) $file['tmp_name'], self::dir($subdir));
    }

    /**
     * Valida el contenido de un archivo ya subido, lo reduce y lo guarda en `$dir`.
     *
     * Es el paso de `image()` que no depende de que el archivo venga de un pedido HTTP, por eso los
     * tests lo llaman directo.
     *
     * Si esta instalación de GD no sabe tratar el formato (ver Imagen::soporta()), guarda el
     * archivo original: ya pasó las validaciones de arriba, y perder la reducción es mejor que
     * rechazar una imagen buena.
     *
     * @return string el nombre del archivo guardado
     * @throws ErrorNegocio si no es una imagen válida, es de un formato no permitido o es demasiado grande
     */
    public static function guardar(string $rutaTemporal, string $dir): string
    {
        // Validación real del contenido (no por extensión ni header del cliente)
        $info = @getimagesize($rutaTemporal);
        if ($info === false) {
            throw new ErrorNegocio('El archivo no es una imagen válida.');
        }
        $mime = $info['mime'] ?? '';
        if (!isset(self::ALLOWED[$mime])) {
            throw new ErrorNegocio('Formato no permitido. Usá JPG, PNG, WEBP o GIF.');
        }
        if (!Imagen::cabeEnMemoria((int) $info[0], (int) $info[1])) {
            throw new ErrorNegocio(sprintf(
                'La imagen es demasiado grande (%d × %d). Usá una de hasta %d megapíxeles.',
                $info[0], $info[1], intdiv(Imagen::PIXELES_MAXIMOS, 1_000_000)
            ));
        }

        $reducida = Imagen::reducir($rutaTemporal, $mime);
        $ext      = $reducida['ext'] ?? self::ALLOWED[$mime];
        $datos    = $reducida['datos'] ?? file_get_contents($rutaTemporal);
        if ($datos === false) {
            throw new RuntimeException('No se pudo leer la imagen subida.');
        }

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo preparar el destino de la imagen.');
        }

        $name = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
        $dest = $dir . DIRECTORY_SEPARATOR . $name;
        if (file_put_contents($dest, $datos) === false) {
            throw new RuntimeException('No se pudo guardar la imagen.');
        }
        @chmod($dest, 0644);

        return $name;
    }

    /** Elimina un archivo previo (al reemplazar o desvincular imagen). */
    public static function delete(string $subdir, ?string $name): void
    {
        if (!$name || !in_array($subdir, self::SUBDIRS, true)) return;
        $path = self::dir($subdir) . DIRECTORY_SEPARATOR . basename($name);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** URL pública para mostrar la imagen. */
    public static function url(string $subdir, ?string $name): ?string
    {
        if (!$name) return null;
        return '/assets/uploads/' . $subdir . '/' . rawurlencode($name);
    }

    private static function dir(string $subdir): string
    {
        return BASE_PATH . '/public/assets/uploads/' . $subdir;
    }
}
