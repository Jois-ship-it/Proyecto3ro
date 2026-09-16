<?php
declare(strict_types=1);

/**
 * Test de integración de las reglas de propiedad (ownership) de torneos.
 *
 * Reemplaza a la versión que reproducía los gates con funciones sueltas: ahora
 * se ejecuta TorneoController de verdad, con sesión y $_POST armados, contra una
 * base de prueba.
 *
 * Para poder testearlo sin un servidor web se usa una subclase que neutraliza
 * las DOS únicas cosas que en CLI no se pueden observar: redirect() (que hace
 * header()+exit) pasa a lanzar una excepción con la URL, y render() guarda lo que
 * se le pasó en lugar de imprimir la vista. Toda la lógica de autorización que se
 * está probando es la real, sin tocar.
 *
 * Ejecutar:
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS= php tests/torneo_ownership_test.php
 */

require __DIR__ . '/bootstrap.php';

/** Se lanza en lugar del header()+exit de BaseController::redirect(). */
final class RedirigidoA extends Exception
{
    public function __construct(public readonly string $url)
    {
        parent::__construct("redirect: {$url}");
    }
}

final class TorneoControllerObservable extends TorneoController
{
    /** Última vista renderizada: ['view' => …, 'data' => …]. */
    public array $vista = [];

    protected function render(string $view, array $data = [], string $layout = 'public'): void
    {
        $this->vista = ['view' => $view, 'data' => $data, 'layout' => $layout];
    }

    protected function redirect(string $url): never
    {
        throw new RedirigidoA($url);
    }
}

final class TorneoOwnershipTest extends TestCase
{
    private int $torneoDeCarlos = 0;
    private int $torneoDeLaura  = 0;

    protected function setUp(): void
    {
        Fixtures::reset($this->db);
        Fixtures::loguearComo(Fixtures::ADMIN);

        $this->torneoDeCarlos = Fixtures::torneo('liga', [
            'nombre'         => 'Torneo de Carlos',
            'organizador_id' => Fixtures::ORGANIZADOR,
        ]);
        $this->torneoDeLaura = Fixtures::torneo('liga', [
            'nombre'         => 'Torneo de Laura',
            'organizador_id' => Fixtures::ORGANIZADOR2,
        ]);
    }

    /** Arma $_POST con el token CSRF válido para la sesión actual. */
    private function postear(array $datos): void
    {
        $_POST = array_merge([
            'nombre'          => 'Sin nombre',
            'tipo_torneo_id'  => (string)(new TipoTorneoModel())->findBySlug('liga')['id'],
            'modalidad'       => 'individual',
            'estado'          => 'inscripcion',
            'fecha_inicio'    => date('Y-m-d'),
            'fecha_fin'       => date('Y-m-d', strtotime('+30 days')),
            'organizador_id'  => (string)Fixtures::ORGANIZADOR,
            'nombre_puntos'   => 'puntos',
        ], $datos);
        $_POST['csrf_token'] = Csrf::generate();
    }

    /** Ejecuta $fn y devuelve la URL del redirect que haya provocado. */
    private function urlDelRedirect(callable $fn): string
    {
        try {
            $fn();
        } catch (RedirigidoA $r) {
            return $r->url;
        }
        $this->fallar('se esperaba un redirect y no hubo ninguno');
    }

    private function nombreDelTorneo(int $id): string
    {
        return (string) $this->db->query("SELECT nombre FROM torneos WHERE id = $id")->fetchColumn();
    }

    private function organizadorDelTorneo(int $id): int
    {
        return (int) (new TorneoModel())->getOrganizadorId($id);
    }

    private function cantidadDeTorneos(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM torneos')->fetchColumn();
    }

    // ─── Crear ──────────────────────────────────────────────────────────────

    public function test_el_administrador_puede_crear_un_torneo(): void
    {
        Fixtures::loguearComo(Fixtures::ADMIN);
        $this->postear(['nombre' => 'Creado por el admin']);

        $destino = $this->urlDelRedirect(fn() => (new TorneoControllerObservable())->guardar());

        $this->assertSame('/admin/torneos', $destino);
        $this->assertSame(3, $this->cantidadDeTorneos(), 'se creó el torneo');
        $this->assertSame('Torneo creado correctamente.', Session::getFlash('success'));
    }

    public function test_un_organizador_no_puede_crear_torneos_nuevos(): void
    {
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $this->postear(['nombre' => 'Creado por un organizador']);

        $destino = $this->urlDelRedirect(fn() => (new TorneoControllerObservable())->guardar());

        $this->assertSame('/admin/torneos', $destino);
        $this->assertSame(2, $this->cantidadDeTorneos(), 'no se creó nada');
        $this->assertStringContainsString(
            'Solo un administrador puede crear torneos',
            (string) Session::getFlash('error')
        );
    }

    public function test_el_formulario_de_alta_tampoco_se_le_muestra_al_organizador(): void
    {
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);

        $destino = $this->urlDelRedirect(fn() => (new TorneoControllerObservable())->formulario());
        $this->assertSame('/admin/torneos', $destino);

        // Al admin sí.
        Fixtures::loguearComo(Fixtures::ADMIN);
        $ctrl = new TorneoControllerObservable();
        $ctrl->formulario();
        $this->assertSame('admin/torneo_form', $ctrl->vista['view']);
    }

    // ─── Editar ─────────────────────────────────────────────────────────────

    public function test_el_organizador_dueno_puede_editar_su_torneo(): void
    {
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $this->postear(['nombre' => 'Renombrado por su dueño']);

        $destino = $this->urlDelRedirect(
            fn() => (new TorneoControllerObservable())->guardar((string)$this->torneoDeCarlos)
        );

        $this->assertSame('/admin/torneos', $destino);
        $this->assertSame('Renombrado por su dueño', $this->nombreDelTorneo($this->torneoDeCarlos));
    }

    public function test_un_organizador_no_puede_editar_un_torneo_ajeno(): void
    {
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $this->postear(['nombre' => 'Intento de intruso']);

        $destino = $this->urlDelRedirect(
            fn() => (new TorneoControllerObservable())->guardar((string)$this->torneoDeLaura)
        );

        $this->assertSame('/admin/torneos', $destino);
        $this->assertSame('Torneo de Laura', $this->nombreDelTorneo($this->torneoDeLaura),
            'el torneo ajeno quedó intacto');
        $this->assertStringContainsString('No tenés acceso a este torneo', (string) Session::getFlash('error'));
    }

    public function test_el_administrador_puede_editar_cualquier_torneo(): void
    {
        Fixtures::loguearComo(Fixtures::ADMIN);
        $this->postear(['nombre' => 'Renombrado por el admin', 'organizador_id' => (string)Fixtures::ORGANIZADOR2]);

        $this->urlDelRedirect(
            fn() => (new TorneoControllerObservable())->guardar((string)$this->torneoDeLaura)
        );

        $this->assertSame('Renombrado por el admin', $this->nombreDelTorneo($this->torneoDeLaura));
    }

    // ─── Reasignación de organizador ────────────────────────────────────────

    public function test_el_administrador_puede_reasignar_el_organizador(): void
    {
        Fixtures::loguearComo(Fixtures::ADMIN);
        $this->postear([
            'nombre'         => 'Torneo de Carlos',
            'organizador_id' => (string)Fixtures::ORGANIZADOR2,
        ]);

        $this->urlDelRedirect(
            fn() => (new TorneoControllerObservable())->guardar((string)$this->torneoDeCarlos)
        );

        $this->assertSame(Fixtures::ORGANIZADOR2, $this->organizadorDelTorneo($this->torneoDeCarlos),
            'el admin reasignó el torneo a Laura');
    }

    public function test_un_organizador_no_puede_reasignarse_ni_regalar_su_torneo(): void
    {
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        // POST manipulado: intenta pasarle el torneo a Laura.
        $this->postear([
            'nombre'         => 'Torneo de Carlos',
            'organizador_id' => (string)Fixtures::ORGANIZADOR2,
        ]);

        $this->urlDelRedirect(
            fn() => (new TorneoControllerObservable())->guardar((string)$this->torneoDeCarlos)
        );

        $this->assertSame(Fixtures::ORGANIZADOR, $this->organizadorDelTorneo($this->torneoDeCarlos),
            'el organizador_id posteado se ignora: sigue siendo Carlos');
    }

    public function test_tras_transferir_el_torneo_el_organizador_anterior_pierde_el_acceso(): void
    {
        // El admin se lo pasa a Laura…
        Fixtures::loguearComo(Fixtures::ADMIN);
        $this->postear(['nombre' => 'Torneo de Carlos', 'organizador_id' => (string)Fixtures::ORGANIZADOR2]);
        $this->urlDelRedirect(fn() => (new TorneoControllerObservable())->guardar((string)$this->torneoDeCarlos));

        // …y Carlos ya no puede tocarlo.
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $this->postear(['nombre' => 'Carlos insiste']);
        $this->urlDelRedirect(fn() => (new TorneoControllerObservable())->guardar((string)$this->torneoDeCarlos));

        $this->assertSame('Torneo de Carlos', $this->nombreDelTorneo($this->torneoDeCarlos));
        $this->assertStringContainsString('No tenés acceso', (string) Session::getFlash('error'));
    }

    // ─── Listado ────────────────────────────────────────────────────────────

    public function test_el_administrador_ve_todos_los_torneos_del_listado(): void
    {
        Fixtures::loguearComo(Fixtures::ADMIN);
        $ctrl = new TorneoControllerObservable();
        $ctrl->listadoAdmin();

        $this->assertSame('admin/torneos', $ctrl->vista['view']);
        $this->assertCount(2, $ctrl->vista['data']['torneos'], 'el admin ve los 2 torneos');
    }

    public function test_cada_organizador_solo_ve_los_suyos(): void
    {
        Fixtures::loguearComo(Fixtures::ORGANIZADOR);
        $ctrl = new TorneoControllerObservable();
        $ctrl->listadoAdmin();

        $torneos = $ctrl->vista['data']['torneos'];
        $this->assertCount(1, $torneos, 'Carlos ve solo su torneo');
        $this->assertSame($this->torneoDeCarlos, (int)$torneos[0]['id']);

        Fixtures::loguearComo(Fixtures::ORGANIZADOR2);
        $ctrl2 = new TorneoControllerObservable();
        $ctrl2->listadoAdmin();

        $torneos2 = $ctrl2->vista['data']['torneos'];
        $this->assertCount(1, $torneos2, 'Laura ve solo el suyo');
        $this->assertSame($this->torneoDeLaura, (int)$torneos2[0]['id']);
    }
}

exit(TestCase::ejecutar(TorneoOwnershipTest::class));
