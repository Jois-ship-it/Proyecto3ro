<?php
declare(strict_types=1);

/**
 * Subida segura de imágenes (avatars de participantes, logos de equipos).
 *
 * Seguridad:
 *  - Valida que el archivo sea realmente una imagen (getimagesize), no por extensión.
 *  - Whitelist de MIME → extensión.
 *  - Tamaño máximo.
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
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Error al subir la imagen (código ' . $err . ').');
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new RuntimeException('La imagen supera el máximo de 2 MB.');
        }
        if (!is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new RuntimeException('Subida inválida.');
        }

        // Validación real del contenido (no por extensión ni header del cliente)
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            throw new RuntimeException('El archivo no es una imagen válida.');
        }
        $mime = $info['mime'] ?? '';
        if (!isset(self::ALLOWED[$mime])) {
            throw new RuntimeException('Formato no permitido. Usá JPG, PNG, WEBP o GIF.');
        }

        $ext  = self::ALLOWED[$mime];
        $name = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
        $dir  = self::dir($subdir);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo preparar el destino de la imagen.');
        }

        $dest = $dir . DIRECTORY_SEPARATOR . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
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
