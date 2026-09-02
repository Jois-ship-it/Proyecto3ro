<?php
declare(strict_types=1);

class TorneoService
{
    private TorneoModel      $model;
    private TipoTorneoModel  $tipoModel;
    private AuditoriaService $auditoria;

    public function __construct()
    {
        $this->model     = new TorneoModel();
        $this->tipoModel = new TipoTorneoModel();
        $this->auditoria = new AuditoriaService();
    }

    public function getAll(): array          { return $this->model->findAllConTipo(); }
    public function getPublicos(): array     { return $this->model->findPublicos(); }
    public function getTipos(): array        { return $this->tipoModel->findAll(); }
    public function getById(int $id): ?array { return $this->model->findByIdCompleto($id); }
    public function getByOrganizador(int $uid): array { return $this->model->findByOrganizador($uid); }

    public function crear(array $d): int
    {
        $this->validar($d);
        $id = $this->model->insert($this->prepararDatos($d));
        // Asociar organizador (modelo "un organizador por torneo")
        if (array_key_exists('organizador_id', $d)) {
            $this->setOrganizador($id, $d['organizador_id'] ? (int)$d['organizador_id'] : null);
        }
        $this->auditoria->log('crear_torneo', 'torneos', $id, "Torneo creado: {$d['nombre']}");
        return $id;
    }

    public function editar(int $id, array $d): void
    {
        $torneo = $this->model->findById($id);
        if (!$torneo) throw new RuntimeException('Torneo no encontrado.');

        // Un torneo finalizado o cancelado no se modifica.
        if ($torneo['estado'] === 'finalizado') {
            throw new RuntimeException('No se puede modificar un torneo finalizado.');
        }
        if ($torneo['estado'] === 'cancelado') {
            throw new RuntimeException('No se puede modificar un torneo cancelado.');
        }
        // Un torneo siempre debe conservar un organizador asignado
        if (array_key_exists('organizador_id', $d) && empty($d['organizador_id'])) {
            throw new RuntimeException('El torneo debe tener un organizador asignado.');
        }

        if ($torneo['estado'] === 'en_curso') {
            // En curso: la ESTRUCTURA ya está generada, por lo que el formato, la
            // modalidad, el mínimo de integrantes y la cantidad de rondas quedan
            // bloqueados. Sí se pueden ajustar nombre, descripción, visibilidad,
            // puntuación, empates, unidad de puntos y fechas — y tienen efecto real.
            if (trim($d['nombre'] ?? '') === '') {
                throw new RuntimeException('El nombre del torneo es obligatorio.');
            }

            // Fechas: si se editan, deben ser coherentes y no dejar partidos
            // ya programados fuera del nuevo rango.
            $inicio = ($d['fecha_inicio'] ?? '') !== '' ? $d['fecha_inicio'] : ($torneo['fecha_inicio'] ?? null);
            $fin    = ($d['fecha_fin'] ?? '')    !== '' ? $d['fecha_fin']    : ($torneo['fecha_fin'] ?? null);
            if ($inicio && $fin) {
                if (strtotime($inicio) > strtotime($fin)) {
                    throw new RuntimeException('La fecha de inicio no puede ser posterior a la fecha de finalización.');
                }
                $fuera = (new EnfrentamientoModel())->contarProgramadosFueraDeRango($id, $inicio, $fin);
                if ($fuera > 0) {
                    throw new RuntimeException(
                        "No se puede acotar el rango de fechas: hay {$fuera} partido(s) programado(s) fuera del nuevo período. " .
                        "Reprogramalos primero."
                    );
                }
            }

            $this->model->update($id, [
                'nombre'          => trim($d['nombre']),
                'descripcion'     => trim($d['descripcion'] ?? ''),
                'publico'         => isset($d['publico']) ? 1 : 0,
                'permite_empates' => isset($d['permite_empates']) ? 1 : 0,
                'puntos_victoria' => (int)($d['puntos_victoria'] ?? $torneo['puntos_victoria']),
                'puntos_empate'   => (int)($d['puntos_empate']   ?? $torneo['puntos_empate']),
                'puntos_derrota'  => (int)($d['puntos_derrota']  ?? $torneo['puntos_derrota']),
                'nombre_puntos'   => trim($d['nombre_puntos'] ?? $torneo['nombre_puntos']),
                'fecha_inicio'    => $inicio ?: null,
                'fecha_fin'       => $fin ?: null,
            ]);

            // La puntuación pudo cambiar: recalcular la tabla en formatos que la usan.
            $slug = $this->tipoModel->findById((int)$torneo['tipo_torneo_id'])['slug'] ?? '';
            if (in_array($slug, ['liga', 'suizo'], true)) {
                (new TablaPosicionesService())->recalcular($id);
            }
        } else {
            // borrador / inscripción: edición completa con validación.
            $this->validar($d);
            $this->model->update($id, $this->prepararDatos($d));
        }
        // El organizador puede reasignarse mientras el torneo no esté finalizado.
        if (array_key_exists('organizador_id', $d)) {
            $this->setOrganizador($id, $d['organizador_id'] ? (int)$d['organizador_id'] : null);
        }
        $this->auditoria->log('editar_torneo', 'torneos', $id, "Torneo editado: {$d['nombre']}");
    }

    /** Asocia (o reemplaza) el único organizador de un torneo. */
    public function setOrganizador(int $torneoId, ?int $usuarioId): void
    {
        $this->model->setOrganizador($torneoId, $usuarioId);
        if ($usuarioId) {
            $this->auditoria->log('asignar_organizador', 'torneo_organizadores', $torneoId, "Organizador {$usuarioId} asignado al torneo {$torneoId}");
        }
    }

    public function eliminar(int $id): void
    {
        $t = $this->model->findById($id);
        if (!$t) throw new RuntimeException('Torneo no encontrado.');
        if ($t['estado'] === 'en_curso') throw new RuntimeException('No se puede eliminar un torneo en curso.');
        $this->model->update($id, ['estado' => 'cancelado']);
        $this->auditoria->log('cancelar_torneo', 'torneos', $id, "Torneo cancelado: {$t['nombre']}");
    }

    private function prepararDatos(array $d): array
    {
        return [
            'nombre'                   => trim($d['nombre']),
            'descripcion'              => trim($d['descripcion'] ?? ''),
            'tipo_torneo_id'           => (int)$d['tipo_torneo_id'],
            'modalidad'                => $d['modalidad'] ?? 'individual',
            'min_integrantes_equipo'   => (($d['modalidad'] ?? 'individual') === 'equipos' && !empty($d['min_integrantes_equipo']))
                                            ? (int)$d['min_integrantes_equipo'] : null,
            'estado'                   => $d['estado'] ?? 'borrador',
            'fecha_inicio'             => $d['fecha_inicio']  ?: null,
            'fecha_fin'                => $d['fecha_fin']     ?: null,
            'publico'                  => isset($d['publico']) ? 1 : 0,
            'permite_empates'          => isset($d['permite_empates']) ? 1 : 0,
            'puntos_victoria'          => (int)($d['puntos_victoria']  ?? 3),
            'puntos_empate'            => (int)($d['puntos_empate']    ?? 1),
            'puntos_derrota'           => (int)($d['puntos_derrota']   ?? 0),
            'usa_puntos_favor'         => isset($d['usa_puntos_favor']) ? 1 : 0,
            'requiere_desempate_final' => isset($d['requiere_desempate_final']) ? 1 : 0,
            'rondas_suizo'             => !empty($d['rondas_suizo']) ? (int)$d['rondas_suizo'] : null,
            'bye_suizo'                => $d['bye_suizo'] ?? 'sin_puntos',
            'puntos_bye_suizo'         => (float)($d['puntos_bye_suizo'] ?? 0),
            'nombre_puntos'            => trim($d['nombre_puntos'] ?? 'puntos'),
            'creado_por'               => (int)($d['creado_por'] ?? Auth::id()),
        ];
    }

    private function validar(array $d): void
    {
        if (empty($d['nombre']))         throw new RuntimeException('El nombre del torneo es obligatorio.');
        if (empty($d['tipo_torneo_id'])) throw new RuntimeException('El formato del torneo es obligatorio.');
        if (empty($d['organizador_id'])) throw new RuntimeException('Debés asignar un organizador al torneo.');

        // Fechas: obligatorias y coherentes (inicio <= fin).
        $inicio = trim((string)($d['fecha_inicio'] ?? ''));
        $fin    = trim((string)($d['fecha_fin'] ?? ''));
        if ($inicio === '') throw new RuntimeException('La fecha de inicio es obligatoria.');
        if ($fin === '')    throw new RuntimeException('La fecha de finalización es obligatoria.');
        $tsInicio = strtotime($inicio);
        $tsFin    = strtotime($fin);
        if ($tsInicio === false || $tsFin === false) {
            throw new RuntimeException('Las fechas ingresadas no son válidas.');
        }
        if ($tsInicio > $tsFin) {
            throw new RuntimeException('La fecha de inicio no puede ser posterior a la fecha de finalización.');
        }

        // Modalidad válida; por equipos exige un mínimo de integrantes.
        $modalidad = $d['modalidad'] ?? 'individual';
        if (!in_array($modalidad, ['individual', 'equipos'], true)) {
            throw new RuntimeException('La modalidad seleccionada no es válida.');
        }
        if ($modalidad === 'equipos' && (int)($d['min_integrantes_equipo'] ?? 0) < 1) {
            throw new RuntimeException('Definí el mínimo de integrantes por equipo (al menos 1).');
        }

        // Rondas suizas dentro de rango si se especifican.
        if (!empty($d['rondas_suizo'])) {
            $r = (int)$d['rondas_suizo'];
            if ($r < 2 || $r > 20) throw new RuntimeException('La cantidad de rondas (Suizo) debe estar entre 2 y 20.');
        }
    }
}
