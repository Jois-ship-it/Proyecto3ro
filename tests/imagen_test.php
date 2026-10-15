<?php
declare(strict_types=1);

/**
 * Test de la reducción de imágenes subidas (core/Imagen.php y Upload::guardar()).
 *
 * Qué pasaba antes: Upload guardaba el archivo tal cual: hasta 2 MB para mostrarse en un círculo de
 * 56 píxeles (M5), y con todo lo que traiga adentro (metadatos, o código pegado al final de un
 * JPEG). Una imagen chica en disco podía además declarar decenas de miles de píxeles por lado.
 *
 * Qué se comprueba:
 *   - Las dimensiones: caben en 512 px, se conserva la proporción y no se agranda una imagen chica.
 *   - Que se conserve la transparencia (PNG y GIF) y la orientación de las fotos de celular (EXIF).
 *   - Que lo guardado sea solo la imagen: un JPEG con PHP pegado al final sale sin él.
 *   - Que lo que no es una imagen, un formato no permitido o una imagen enorme se rechacen con un
 *     motivo, sin llegar a decodificarse.
 *   - Que si GD no sabe tratar un formato, se guarde el original en lugar de rechazarlo.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/imagen_test.php
 */

require __DIR__ . '/bootstrap.php';

class ImagenTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/flexarena_imagen_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $archivo) @unlink($archivo);
        @rmdir($this->dir);
    }

    // ── ayudas ─────────────────────────────────────────────────────────

    /** Escribe un archivo temporal con ese contenido y devuelve su ruta. */
    private function archivo(string $contenido, string $ext = 'bin'): string
    {
        $ruta = $this->dir . '/origen_' . bin2hex(random_bytes(3)) . '.' . $ext;
        file_put_contents($ruta, $contenido);
        return $ruta;
    }

    /** Un degradé de color verdadero (comprime poco, como una foto). */
    private function degrade(int $ancho, int $alto): GdImage
    {
        $img = imagecreatetruecolor($ancho, $alto);
        for ($x = 0; $x < $ancho; $x += 4) {
            for ($y = 0; $y < $alto; $y += 4) {
                $color = imagecolorallocate($img, ($x * 255) % 256, ($y * 255) % 256, (($x + $y) * 7) % 256);
                imagefilledrectangle($img, $x, $y, $x + 3, $y + 3, $color);
            }
        }
        return $img;
    }

    private function jpeg(GdImage $img, int $calidad = 95): string
    {
        ob_start();
        imagejpeg($img, null, $calidad);
        return (string) ob_get_clean();
    }

    private function png(GdImage $img): string
    {
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }

    /** @return array{0:int,1:int,2:string} ancho, alto y MIME de lo guardado en `$dir` bajo ese nombre */
    private function medidas(string $nombre): array
    {
        $info = getimagesize($this->dir . '/' . $nombre);
        $this->assertTrue($info !== false, 'lo guardado se tiene que poder abrir como imagen');
        return [(int) $info[0], (int) $info[1], (string) $info['mime']];
    }

    /** Un JPEG con una orientación EXIF en un segmento APP1 mínimo (TIFF little endian, un solo tag). */
    private function conOrientacion(string $jpeg, int $orientacion): string
    {
        $tiff = "II\x2A\x00\x08\x00\x00\x00"                       // cabecera TIFF, primer IFD en el byte 8
              . "\x01\x00"                                          // un tag
              . "\x12\x01\x03\x00\x01\x00\x00\x00" . pack('v', $orientacion) . "\x00\x00" // 0x0112 Orientation, SHORT
              . "\x00\x00\x00\x00";                                 // sin más IFDs
        $app1 = "Exif\x00\x00" . $tiff;
        $segmento = "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1;
        return substr($jpeg, 0, 2) . $segmento . substr($jpeg, 2);   // justo después del SOI
    }

    /** Los archivos que `Upload::guardar()` dejó en la carpeta de destino (no los de origen de la preparación). */
    private function guardados(): array
    {
        return array_values(array_filter(
            array_map('basename', glob($this->dir . '/*') ?: []),
            fn(string $f) => !str_starts_with($f, 'origen_')
        ));
    }

    // ── dimensiones ────────────────────────────────────────────────────

    public function test_las_dimensiones_de_destino(): void
    {
        $this->assertSame([512, 256], Imagen::dimensionesDestino(2000, 1000), 'apaisada: el lado mayor se achica a 512');
        $this->assertSame([256, 512], Imagen::dimensionesDestino(1000, 2000), 'vertical');
        $this->assertSame([512, 512], Imagen::dimensionesDestino(4000, 4000), 'cuadrada');
        $this->assertSame([300, 200], Imagen::dimensionesDestino(300, 200), 'una imagen que ya entra no se agranda');
        $this->assertSame([512, 512], Imagen::dimensionesDestino(512, 512), 'justo en el límite');
        $this->assertSame([512, 1], Imagen::dimensionesDestino(513, 1), 'nunca queda un lado en 0');
        $this->assertSame([1, 512], Imagen::dimensionesDestino(1, 5000), 'una tira finita conserva al menos 1 px');
        $this->assertSame([1, 1], Imagen::dimensionesDestino(0, 0), 'un tamaño inválido se corrige a 1×1');
        $this->assertSame([100, 50], Imagen::dimensionesDestino(400, 200, 100), 'con otro lado máximo');
    }

    public function test_el_limite_de_pixeles(): void
    {
        $this->assertTrue(Imagen::cabeEnMemoria(4000, 4000), '16 megapíxeles justos entran');
        $this->assertFalse(Imagen::cabeEnMemoria(4001, 4000));
        $this->assertFalse(Imagen::cabeEnMemoria(30000, 30000), 'una imagen enorme no se decodifica');
        $this->assertFalse(Imagen::cabeEnMemoria(0, 100));
        $this->assertFalse(Imagen::cabeEnMemoria(-5, 100));
    }

    public function test_las_extensiones_y_lo_que_soporta_gd(): void
    {
        $this->assertSame('jpg', Imagen::extensionDe('image/jpeg'));
        $this->assertSame('png', Imagen::extensionDe('image/png'));
        $this->assertSame('png', Imagen::extensionDe('image/gif'), 'un GIF se guarda como PNG');
        $this->assertSame('webp', Imagen::extensionDe('image/webp'));
        $this->assertSame(null, Imagen::extensionDe('image/svg+xml'));
        $this->assertSame(null, Imagen::extensionDe('application/x-php'));
        $this->assertTrue(Imagen::soporta('image/jpeg'));
        $this->assertTrue(Imagen::soporta('image/png'));
        $this->assertTrue(Imagen::soporta('image/gif'));
        $this->assertFalse(Imagen::soporta('image/svg+xml'));
    }

    // ── reducir y guardar ──────────────────────────────────────────────

    public function test_una_foto_grande_se_achica_y_pesa_menos(): void
    {
        $original = $this->jpeg($this->degrade(2400, 1200));
        $ruta     = $this->archivo($original, 'jpg');

        $nombre = Upload::guardar($ruta, $this->dir);

        [$ancho, $alto, $mime] = $this->medidas($nombre);
        $this->assertSame([512, 256], [$ancho, $alto], 'el lado mayor queda en 512 y se conserva la proporción');
        $this->assertSame('image/jpeg', $mime);
        $this->assertTrue((bool) preg_match('/^[0-9a-f]{16}_\d+\.jpg$/', $nombre), 'nombre aleatorio y extensión del formato: ' . $nombre);
        $this->assertTrue(filesize($this->dir . '/' . $nombre) < strlen($original) / 3,
            'tiene que pesar bastante menos que el original (' . filesize($this->dir . '/' . $nombre) . ' contra ' . strlen($original) . ')');
        $this->assertTrue((fileperms($this->dir . '/' . $nombre) & 0777) === 0644, 'sin permisos de ejecución');
    }

    public function test_una_imagen_chica_no_se_agranda(): void
    {
        $nombre = Upload::guardar($this->archivo($this->png($this->degrade(120, 80)), 'png'), $this->dir);

        $this->assertSame([120, 80, 'image/png'], $this->medidas($nombre));
    }

    public function test_el_png_conserva_la_transparencia(): void
    {
        $img = imagecreatetruecolor(800, 800);
        imagesavealpha($img, true);
        imagealphablending($img, false);
        imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 0, 0, 0, 127));             // todo transparente
        imagefilledrectangle($img, 600, 600, 799, 799, (int) imagecolorallocate($img, 200, 30, 30)); // un cuadro rojo opaco
        $nombre = Upload::guardar($this->archivo($this->png($img), 'png'), $this->dir);

        $this->assertSame([512, 512, 'image/png'], $this->medidas($nombre));
        $res = imagecreatefrompng($this->dir . '/' . $nombre);
        $this->assertTrue(((imagecolorat($res, 10, 10) >> 24) & 0x7F) === 127, 'el fondo sigue siendo transparente');
        $this->assertTrue(((imagecolorat($res, 500, 500) >> 24) & 0x7F) === 0, 'el cuadro sigue siendo opaco');
    }

    public function test_el_gif_sale_como_png_y_conserva_la_transparencia(): void
    {
        $img = imagecreate(600, 600);                                   // de paleta, como un GIF
        $transparente = imagecolorallocate($img, 255, 0, 255);
        imagecolortransparent($img, $transparente);
        imagefill($img, 0, 0, $transparente);
        imagefilledrectangle($img, 400, 400, 599, 599, imagecolorallocate($img, 10, 160, 10));
        ob_start();
        imagegif($img);
        $gif = (string) ob_get_clean();

        $nombre = Upload::guardar($this->archivo($gif, 'gif'), $this->dir);

        $this->assertTrue(str_ends_with($nombre, '.png'), 'un GIF se guarda como PNG: ' . $nombre);
        $this->assertSame([512, 512, 'image/png'], $this->medidas($nombre));
        $res = imagecreatefrompng($this->dir . '/' . $nombre);
        $this->assertTrue(((imagecolorat($res, 10, 10) >> 24) & 0x7F) === 127, 'el color transparente del GIF sigue transparente');
        $this->assertTrue(((imagecolorat($res, 450, 450) >> 24) & 0x7F) === 0, 'el resto es opaco');
    }

    public function test_el_jpeg_sin_transparencia_no_deja_fondo_negro(): void
    {
        $nombre = Upload::guardar($this->archivo($this->jpeg($this->degrade(900, 900)), 'jpg'), $this->dir);

        $this->assertSame([512, 512, 'image/jpeg'], $this->medidas($nombre));
        $res = imagecreatefromjpeg($this->dir . '/' . $nombre);
        $this->assertTrue(imagecolorat($res, 5, 5) !== 0, 'el borde de la foto no es negro');
    }

    // ── seguridad ──────────────────────────────────────────────────────

    public function test_codigo_pegado_a_una_imagen_no_sobrevive(): void
    {
        $camuflado = $this->jpeg($this->degrade(300, 300)) . "\n<?php system(\$_GET['c']); ?>\n";
        $ruta = $this->archivo($camuflado, 'jpg');
        $this->assertTrue(@getimagesize($ruta) !== false, 'el archivo preparado sigue pareciendo una imagen');

        $nombre = Upload::guardar($ruta, $this->dir);

        $guardado = (string) file_get_contents($this->dir . '/' . $nombre);
        $this->assertStringNotContainsString('<?php', $guardado, 'lo guardado ya no lleva el código');
        $this->assertStringNotContainsString('system(', $guardado);
    }

    public function test_los_metadatos_no_se_conservan(): void
    {
        $conExif = $this->conOrientacion($this->jpeg($this->degrade(400, 300)), 1);
        $this->assertStringContainsString('Exif', $conExif, 'la preparación incluye metadatos');

        $nombre = Upload::guardar($this->archivo($conExif, 'jpg'), $this->dir);

        $this->assertStringNotContainsString('Exif', (string) file_get_contents($this->dir . '/' . $nombre));
    }

    public function test_lo_que_no_es_una_imagen_se_rechaza(): void
    {
        $e = $this->assertThrows(fn() => Upload::guardar($this->archivo('<?php echo 1;', 'jpg'), $this->dir));
        $this->assertTrue($e instanceof ErrorNegocio, 'el motivo es para la persona, no un error técnico');
        $this->assertThrows(fn() => Upload::guardar($this->archivo('', 'png'), $this->dir), 'no es una imagen');
        $this->assertThrows(fn() => Upload::guardar($this->archivo(str_repeat('A', 5000), 'gif'), $this->dir), 'no es una imagen');
        $this->assertSame([], $this->guardados(), 'nada se guardó');
    }

    public function test_un_formato_no_permitido_se_rechaza(): void
    {
        $bmp = imagecreatetruecolor(40, 40);
        ob_start();
        imagebmp($bmp);
        $datos = (string) ob_get_clean();

        $this->assertThrows(fn() => Upload::guardar($this->archivo($datos, 'bmp'), $this->dir), 'Formato no permitido');
    }

    public function test_una_imagen_enorme_se_rechaza_sin_decodificarla(): void
    {
        // Un PNG de una sola tinta con 6000 × 4000 px (24 megapíxeles) pesa muy poco en disco.
        $img = imagecreate(6000, 4000);
        imagecolorallocate($img, 0, 0, 0);
        $ruta = $this->archivo($this->png($img), 'png');
        $this->assertTrue(filesize($ruta) < Upload::MAX_BYTES, 'preparación: cabe en los 2 MB, como un archivo legítimo');
        unset($img);

        $e = $this->assertThrows(fn() => Upload::guardar($ruta, $this->dir), 'demasiado grande');
        $this->assertTrue($e instanceof ErrorNegocio);
        $this->assertStringContainsString('6000', $e->getMessage(), 'el motivo dice cuánto mide');
        $this->assertStringContainsString('16', $e->getMessage(), 'y cuántos megapíxeles se aceptan');
    }

    // ── orientación ────────────────────────────────────────────────────

    /**
     * Una foto de 400 × 200 con el borde IZQUIERDO rojo y el resto azul: sirve para ver hacia dónde
     * queda el rojo después de aplicar cada orientación EXIF.
     */
    private function fotoConBordeRojo(): string
    {
        $img = imagecreatetruecolor(400, 200);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 0, 0, 255));
        imagefilledrectangle($img, 0, 0, 59, 199, (int) imagecolorallocate($img, 255, 0, 0));
        return $this->jpeg($img, 100);
    }

    private function esRojo(GdImage $img, int $x, int $y): bool
    {
        $c = imagecolorat($img, $x, $y);
        return (($c >> 16) & 0xFF) > 180 && ($c & 0xFF) < 90;
    }

    public function test_la_orientacion_de_los_celulares_se_respeta(): void
    {
        if (!function_exists('exif_read_data')) {
            echo "    (se omite: esta instalación de PHP no tiene la extensión exif)\n";
            return;
        }
        $base = $this->fotoConBordeRojo();

        // [orientación EXIF, ancho, alto esperados, píxel que debe ser rojo]
        $casos = [
            1 => [400, 200, 5, 100],     // tal cual: el rojo queda a la izquierda
            3 => [400, 200, 394, 100],   // girada 180°: el rojo pasa a la derecha
            6 => [200, 400, 100, 5],     // hay que girarla 90° a la derecha: el borde izquierdo queda ARRIBA
            8 => [200, 400, 100, 394],   // 90° a la izquierda: el borde izquierdo queda ABAJO
            2 => [400, 200, 394, 100],   // espejada horizontal: el rojo pasa a la derecha
        ];
        foreach ($casos as $orientacion => [$ancho, $alto, $x, $y]) {
            $nombre = Upload::guardar($this->archivo($this->conOrientacion($base, $orientacion), 'jpg'), $this->dir);
            [$an, $al] = $this->medidas($nombre);
            $this->assertSame([$ancho, $alto], [$an, $al], "orientación {$orientacion}: dimensiones");
            $res = imagecreatefromjpeg($this->dir . '/' . $nombre);
            $this->assertTrue($this->esRojo($res, $x, $y), "orientación {$orientacion}: el borde rojo queda donde corresponde");
        }
    }

    public function test_una_orientacion_invalida_se_trata_como_normal(): void
    {
        if (!function_exists('exif_read_data')) return;

        $nombre = Upload::guardar($this->archivo($this->conOrientacion($this->fotoConBordeRojo(), 99), 'jpg'), $this->dir);

        $this->assertSame([400, 200], array_slice($this->medidas($nombre), 0, 2));
    }

    // ── cuando GD no sabe ──────────────────────────────────────────────

    /** Un WebP de 1 × 1 píxel (lossless), para probar el camino de «GD no lo soporta» sin depender de imagewebp. */
    private const WEBP_1X1 = 'UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==';

    public function test_un_webp_se_reduce_o_se_guarda_tal_cual_si_gd_no_lo_soporta(): void
    {
        $webp = (string) base64_decode(self::WEBP_1X1, true);
        $this->assertTrue(@getimagesizefromstring($webp) !== false, 'preparación: el WebP de prueba es válido');

        $nombre = Upload::guardar($this->archivo($webp, 'webp'), $this->dir);

        $this->assertTrue(str_ends_with($nombre, '.webp'));
        [, , $mime] = $this->medidas($nombre);
        $this->assertSame('image/webp', $mime);
        if (!Imagen::soporta('image/webp')) {
            $this->assertSame($webp, file_get_contents($this->dir . '/' . $nombre), 'sin soporte de WebP en GD se guarda el original');
        }
    }

    public function test_un_webp_grande_se_achica_si_gd_lo_soporta(): void
    {
        if (!Imagen::soporta('image/webp')) {
            echo "    (se omite: este GD no escribe WebP)\n";
            return;
        }
        ob_start();
        imagewebp($this->degrade(1600, 800), null, 90);
        $webp = (string) ob_get_clean();

        $nombre = Upload::guardar($this->archivo($webp, 'webp'), $this->dir);

        $this->assertSame([512, 256, 'image/webp'], $this->medidas($nombre));
        $this->assertTrue(filesize($this->dir . '/' . $nombre) < strlen($webp));
    }

    public function test_reducir_devuelve_null_para_lo_que_gd_no_soporta(): void
    {
        $ruta = $this->archivo('cualquier cosa', 'svg');
        $this->assertSame(null, Imagen::reducir($ruta, 'image/svg+xml'));
    }

    public function test_un_archivo_corrupto_con_cabecera_de_imagen_da_un_motivo(): void
    {
        // La cabecera de un JPEG verdadero seguida de basura: getimagesize lo acepta, GD no lo decodifica.
        $jpeg = $this->jpeg($this->degrade(200, 200));
        $roto = substr($jpeg, 0, 400) . str_repeat("\x00", 50);

        $e = $this->assertThrows(fn() => Upload::guardar($this->archivo($roto, 'jpg'), $this->dir));
        $this->assertTrue($e instanceof ErrorNegocio, 'el motivo es para la persona');
    }

    // ── el pedido HTTP (lo que se puede probar sin un servidor) ────────

    public function test_los_errores_de_subida_del_servidor_se_explican(): void
    {
        $this->assertSame(null, Upload::image([], 'avatars'), 'sin archivo: no es un error, el campo es opcional');
        $this->assertSame(null, Upload::image(['error' => UPLOAD_ERR_NO_FILE], 'avatars'));

        foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE] as $codigo) {
            $e = $this->assertThrows(fn() => Upload::image(['error' => $codigo], 'avatars'), 'supera el máximo de 2 MB', "código {$codigo}: el servidor lo rechazó por pesado");
            $this->assertTrue($e instanceof ErrorNegocio);
        }
        $this->assertThrows(fn() => Upload::image(['error' => UPLOAD_ERR_PARTIAL], 'avatars'), 'código ' . UPLOAD_ERR_PARTIAL);
        $this->assertThrows(fn() => Upload::image(['error' => UPLOAD_ERR_OK, 'size' => Upload::MAX_BYTES + 1, 'tmp_name' => 'x'], 'avatars'), 'supera el máximo de 2 MB');
        $this->assertThrows(fn() => Upload::image(['error' => UPLOAD_ERR_OK, 'size' => 10, 'tmp_name' => $this->archivo('x')], 'avatars'), 'Subida inválida', 'un archivo que no vino por HTTP no vale');
        $this->assertThrows(fn() => Upload::image([], 'otra_carpeta'), 'Destino de subida inválido');
    }

    public function test_la_carpeta_de_destino_se_crea_si_no_existe(): void
    {
        $destino = $this->dir . '/sub/carpeta';

        $nombre = Upload::guardar($this->archivo($this->png($this->degrade(50, 50)), 'png'), $destino);

        $this->assertTrue(is_file($destino . '/' . $nombre));
        @unlink($destino . '/' . $nombre);
        @rmdir($destino);
        @rmdir($this->dir . '/sub');
    }
}

TestCase::correrSiEsScript(ImagenTest::class);
