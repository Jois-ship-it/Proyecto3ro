<?php
declare(strict_types=1);

/**
 * Test de la inscripción de participantes desde el panel del organizador.
 *
 * El problema: el organizador no podía inscribir a nadie, y el sistema le decía
 * que sí. Son dos fallas encadenadas, y ninguna está en las validaciones —el
 * admin y el organizador pasan por las mismas tres compuertas y por el mismo
 * InscripcionService—:
 *
 *   1. `app/views/partials/inscripciones_panel.php` dibuja el selector como un
 *      combobox que arma `public/assets/js/combobox.js`. TorneoController::gestion()
 *      cargaba ese script; OrganizadorController::gestion() no. Sin el script, el
 *      <input type="text"> visible no tiene `name` (no viaja nada) y el hidden
 *      `participante_id` se manda vacío.
 *   2. OrganizadorController::inscribir() flasheaba «Inscripción realizada.»
 *      FUERA del if/elseif, así que con el id vacío no inscribía a nadie y
 *      anunciaba éxito igual.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/inscripcion_organizador_test.php
 */

require __DIR__ . '/bootstrap.php';

// Constantes y configuración que en producción define public/index.php. El
// bootstrap de los tests no las define a propósito; las vistas las necesitan.
if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));
if (!defined('APP_PATH'))  define('APP_PATH',  BASE_PATH . '/app');
require_once BASE_PATH . '/config/app.php';

// ── Modo sub-proceso ────────────────────────────────────────────────────────
// inscribir() termina en redirect(), que llama a exit(): no se puede ejecutar
// dentro del proceso del test sin matarlo. Se corre en uno aparte, que devuelve
// por salida estándar el flash que quedó en la sesión.
if (($argv[1] ?? '') === '--inscribir') {
    $torneoId = (int) ($argv[2] ?? 0);
    $posteado = (string) ($argv[3] ?? '');

    Fixtures::loguearComo(Fixtures::ORGANIZADOR);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'csrf_token'      => Csrf::generate(),
        // 'vacio' es lo que manda el formulario cuando el combobox no se armó.
        'participante_id' => $posteado === 'vacio' ? '' : $posteado,
    ];

    // El flash se imprime aunque redirect() corte el proceso.
    register_shutdown_function(static function (): void {
        fwrite(STDOUT, "\nFLASH:" . json_encode($_SESSION['_flash'] ?? [], JSON_UNESCAPED_UNICODE));
    });

    (new OrganizadorController())->inscribir((string) $torneoId);
    exit;
}

final class InscripcionOrganizadorTest extends TestCase
{
    private int $torneoId = 0;
    /** @var int[] */
    private array $participantes = [];

    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);

        $this->participantes = Fixtures::participantes(4, 'Jugador');
        $this->torneoId      = Fixtures::torneo('liga');   // queda a nombre del ORGANIZADOR
        // Uno inscrito y tres libres: así el panel tiene algo que ofrecer.
        Fixtures::inscribir($this->torneoId, [$this->participantes[0]]);
    }

    /** Ejecuta de verdad la acción del controlador y devuelve el HTML que produce. */
    private function paginaDeGestion(BaseController $controlador): string
    {
        ob_start();
        $controlador->gestion((string) $this->torneoId);
        return (string) ob_get_clean();
    }

    /** @return array<string, string> etiqueta => HTML */
    private function paginasDeGestion(): array
    {
        return [
            '/organizador/torneos/{id}' => $this->paginaDeGestion(new OrganizadorController()),
            '/admin/torneos/{id}'       => $this->paginaDeGestion(new TorneoController()),
        ];
    }

    /** Corre inscribir() en un proceso aparte y devuelve el flash resultante. */
    private function inscribirComoOrganizador(string $participanteIdPosteado): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
             . ' --inscribir ' . (int) $this->torneoId
             . ' ' . escapeshellarg($participanteIdPosteado) . ' 2>&1';

        $salida = (string) shell_exec($cmd);
        if (!preg_match('/FLASH:(\{.*\})\s*$/s', $salida, $m)) {
            $this->fallar("el sub-proceso no devolvió el flash. Salida:\n{$salida}");
        }
        return (array) json_decode($m[1], true);
    }

    private function inscritos(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM inscripciones
              WHERE torneo_id = {$this->torneoId} AND estado = 'activa'"
        )->fetchColumn();
    }

    // ─── El selector se puede usar ──────────────────────────────────────────

    public function test_el_panel_del_organizador_ofrece_participantes(): void
    {
        // Control del escenario: si no hubiera nadie para inscribir, el panel no
        // dibujaría el selector y las comprobaciones de abajo pasarían de vacío.
        $html = $this->paginaDeGestion(new OrganizadorController());

        $this->assertStringContainsString('/organizador/torneos/' . $this->torneoId . '/inscribir', $html,
            'el panel tiene que mostrar el formulario de inscripción');
        $this->assertStringContainsString('data-combobox', $html,
            'el selector de participantes se dibuja como combobox');
    }

    public function test_toda_pagina_con_combobox_carga_el_script_que_lo_arma(): void
    {
        // Sin combobox.js el selector es decorado: el input de texto visible no
        // tiene `name` y el hidden viaja vacío. El formulario se manda «en
        // blanco» sin que el usuario pueda hacer nada al respecto.
        $rotas = [];
        foreach ($this->paginasDeGestion() as $etiqueta => $html) {
            if (!str_contains($html, 'data-combobox')) continue;
            if (str_contains($html, 'combobox.js'))    continue;
            $rotas[] = $etiqueta;
        }

        $this->assertCount(0, $rotas,
            'dibujan un combobox sin cargar su script: ' . implode(', ', $rotas));
    }

    public function test_el_organizador_y_el_admin_reciben_el_mismo_panel(): void
    {
        // Las dos vistas incluyen el mismo partial. Que una funcione y la otra no
        // era justamente el síntoma.
        foreach ($this->paginasDeGestion() as $etiqueta => $html) {
            $this->assertStringContainsString('combobox.js', $html,
                "«{$etiqueta}» tiene que cargar el script del selector");
        }
    }

    // ─── El formulario no miente ────────────────────────────────────────────

    public function test_sin_participante_elegido_no_dice_que_inscribio(): void
    {
        $antes = $this->inscritos();
        $flash = $this->inscribirComoOrganizador('vacio');

        $this->assertSame($antes, $this->inscritos(), 'no se inscribió a nadie');
        $this->assertSame(null, $flash['success'] ?? null,
            'anunciar «Inscripción realizada» sin inscribir a nadie es la mentira que había que sacar');
        $this->assertNotNull($flash['error'] ?? null,
            'tiene que avisar que no se eligió a nadie');
    }

    public function test_con_un_participante_elegido_inscribe_y_lo_dice(): void
    {
        $antes = $this->inscritos();
        $flash = $this->inscribirComoOrganizador((string) $this->participantes[1]);

        $this->assertSame($antes + 1, $this->inscritos(), 'el participante quedó inscrito');
        $this->assertNotNull($flash['success'] ?? null, 'y se anuncia el éxito');
    }

    public function test_un_error_del_servicio_llega_al_usuario(): void
    {
        // El que ya está inscrito: el servicio corta y el mensaje tiene que
        // llegar como error, no taparse con un éxito.
        $antes = $this->inscritos();
        $flash = $this->inscribirComoOrganizador((string) $this->participantes[0]);

        $this->assertSame($antes, $this->inscritos());
        $this->assertStringContainsString('ya está inscrito', (string) ($flash['error'] ?? ''));
        $this->assertSame(null, $flash['success'] ?? null);
    }
}

exit(TestCase::ejecutar(InscripcionOrganizadorTest::class));
