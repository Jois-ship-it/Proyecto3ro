<?php
declare(strict_types=1);

/**
 * Reduce y vuelve a codificar imágenes subidas (fotos de perfil y logos).
 *
 * Antes se guardaba el archivo tal cual lo mandaba la persona: hasta 2 MB para mostrarse en un
 * círculo de 56 píxeles. Ahora se decodifica, se achica para que el lado mayor no pase de
 * `LADO_MAXIMO` y se vuelve a escribir. Además de ahorrar ancho de banda y disco, volver a
 * codificar es una defensa: lo que sobrevive es solo la imagen (un JPEG con código PHP pegado al
 * final, o con metadatos de ubicación de la cámara, sale limpio).
 *
 * Usa GD, que ya está en la imagen de Docker. Si esa instalación de GD no sabe leer o escribir un
 * formato (por ejemplo WebP en un PHP compilado sin él), `reducir()` devuelve null y quien llama
 * decide qué hacer: `Upload` guarda entonces el archivo original, que ya pasó las validaciones.
 */
final class Imagen
{
    /** Lado mayor, en píxeles, con el que se guarda una imagen. Se muestran a 32–120 px: alcanza para pantallas densas. */
    public const LADO_MAXIMO = 512;

    /** Calidad de los formatos con pérdida (JPEG y WebP). */
    public const CALIDAD = 85;

    /**
     * Píxeles máximos que se aceptan decodificar (ancho × alto). GD descomprime a 4 bytes por píxel:
     * una imagen de 16 megapíxeles ocupa ~64 MB en memoria. Más que eso es un riesgo para el servidor
     * (un PNG chico en disco puede declarar decenas de miles de píxeles por lado) y no tiene sentido
     * para una foto de perfil.
     */
    public const PIXELES_MAXIMOS = 16_000_000;

    /** @var array<string,string> tipo MIME de entrada => extensión de salida. Un GIF sale como PNG (ver reducir()). */
    private const SALIDA = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'png',
    ];

    /**
     * Las dimensiones con las que cabe una imagen en un cuadrado de `$lado`: mantiene la proporción,
     * no agranda una imagen que ya entra y nunca devuelve 0.
     *
     * @return array{0:int,1:int} [ancho, alto]
     */
    public static function dimensionesDestino(int $ancho, int $alto, int $lado = self::LADO_MAXIMO): array
    {
        $ancho = max(1, $ancho);
        $alto  = max(1, $alto);
        $mayor = max($ancho, $alto);
        if ($mayor <= $lado) return [$ancho, $alto];

        $escala = $lado / $mayor;
        return [max(1, (int) round($ancho * $escala)), max(1, (int) round($alto * $escala))];
    }

    /** ¿Se pueden decodificar tantos píxeles sin arriesgar la memoria del servidor? */
    public static function cabeEnMemoria(int $ancho, int $alto): bool
    {
        return $ancho > 0 && $alto > 0 && $ancho * $alto <= self::PIXELES_MAXIMOS;
    }

    /** La extensión con la que se guarda una imagen de ese tipo MIME, o null si el tipo no está permitido. */
    public static function extensionDe(string $mime): ?string
    {
        return self::SALIDA[$mime] ?? null;
    }

    /** ¿Esta instalación de GD puede leer Y escribir ese tipo? */
    public static function soporta(string $mime): bool
    {
        if (!extension_loaded('gd')) return false;
        return match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') && function_exists('imagejpeg'),
            'image/png'  => function_exists('imagecreatefrompng')  && function_exists('imagepng'),
            'image/gif'  => function_exists('imagecreatefromgif')  && function_exists('imagepng'),
            'image/webp' => function_exists('imagecreatefromwebp') && function_exists('imagewebp'),
            default      => false,
        };
    }

    /**
     * Decodifica el archivo, lo achica y devuelve la imagen ya codificada.
     *
     * Qué se conserva: la proporción, la transparencia (PNG, WebP y GIF) y la orientación de las
     * fotos de celular (el JPEG la trae en un dato EXIF que se pierde al re-codificar, así que se
     * aplica antes). Un GIF sale como PNG: GD solo lee su primer cuadro, y una foto de perfil no
     * necesita animarse.
     *
     * @return array{datos:string,ext:string,ancho:int,alto:int}|null null si GD no soporta este formato
     * @throws ErrorNegocio si el archivo no se puede decodificar como imagen
     */
    public static function reducir(string $ruta, string $mime, int $lado = self::LADO_MAXIMO): ?array
    {
        if (!self::soporta($mime)) return null;

        $origen = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($ruta),
            'image/png'  => @imagecreatefrompng($ruta),
            'image/gif'  => @imagecreatefromgif($ruta),
            'image/webp' => @imagecreatefromwebp($ruta),
        };
        if ($origen === false) {
            throw new ErrorNegocio('No se pudo leer la imagen. Probá con otra.');
        }

        if ($mime === 'image/jpeg') {
            $origen = self::orientar($origen, self::orientacionExif($ruta));
        }

        [$ancho, $alto] = [imagesx($origen), imagesy($origen)];
        [$nuevoAncho, $nuevoAlto] = self::dimensionesDestino($ancho, $alto, $lado);

        $destino = self::lienzo($nuevoAncho, $nuevoAlto, $mime !== 'image/jpeg');
        // Con la mezcla apagada, el remuestreo copia también el canal alfa. Un GIF o un PNG de paleta
        // guarda la transparencia como un índice de color; GD lo convierte a alfa al leer cada píxel.
        imagealphablending($destino, false);
        imagecopyresampled($destino, $origen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);
        imagedestroy($origen);

        ob_start();
        match ($mime) {
            'image/jpeg' => imagejpeg($destino, null, self::CALIDAD),
            'image/webp' => imagewebp($destino, null, self::CALIDAD),
            default      => imagepng($destino, null, 6),
        };
        $datos = (string) ob_get_clean();
        imagedestroy($destino);

        if ($datos === '') {
            throw new ErrorNegocio('No se pudo procesar la imagen. Probá con otra.');
        }
        return ['datos' => $datos, 'ext' => self::SALIDA[$mime], 'ancho' => $nuevoAncho, 'alto' => $nuevoAlto];
    }

    /** Un lienzo de color verdadero, transparente si la imagen puede tener transparencia. */
    private static function lienzo(int $ancho, int $alto, bool $transparente): GdImage
    {
        $lienzo = imagecreatetruecolor($ancho, $alto);
        if ($transparente) {
            imagesavealpha($lienzo, true);
            imagefill($lienzo, 0, 0, (int) imagecolorallocatealpha($lienzo, 0, 0, 0, 127));
        } else {
            imagefill($lienzo, 0, 0, (int) imagecolorallocate($lienzo, 255, 255, 255));
        }
        return $lienzo;
    }

    /**
     * La orientación EXIF de un JPEG (1 a 8), o 1 («tal cual») si no la tiene o la extensión exif no está.
     * Los celulares guardan la foto «de costado» y anotan acá cuánto girarla.
     */
    private static function orientacionExif(string $ruta): int
    {
        if (!function_exists('exif_read_data')) return 1;
        $exif = @exif_read_data($ruta);
        $valor = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        return $valor >= 1 && $valor <= 8 ? $valor : 1;
    }

    /**
     * Gira y/o espeja la imagen según el valor de orientación EXIF, para que se vea como se vería
     * en un navegador (que sí lo respeta) aunque el archivo nuevo ya no traiga el dato.
     */
    private static function orientar(GdImage $imagen, int $orientacion): GdImage
    {
        // [grados en sentido antihorario, espejar horizontalmente antes de girar]
        $pasos = [
            2 => [0, true],   3 => [180, false], 4 => [180, true],
            5 => [90, true],  6 => [270, false], 7 => [270, true],  8 => [90, false],
        ];
        if (!isset($pasos[$orientacion])) return $imagen;

        [$grados, $espejar] = $pasos[$orientacion];
        if ($espejar) imageflip($imagen, IMG_FLIP_HORIZONTAL);
        if ($grados !== 0) {
            $girada = imagerotate($imagen, $grados, 0);
            if ($girada !== false) {
                imagedestroy($imagen);
                return $girada;
            }
        }
        return $imagen;
    }
}
