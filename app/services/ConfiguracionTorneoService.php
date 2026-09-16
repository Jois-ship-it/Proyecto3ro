<?php
declare(strict_types=1);

/**
 * Datos del evento que acompañan a cada torneo: dónde se juega, a quién
 * escribirle, hasta cuándo hay tiempo de anotarse y qué más hay que saber.
 *
 * Viven en la tabla clave/valor `configuraciones_torneo` y no en columnas de
 * `torneos` porque son opcionales, de texto libre y la lista puede crecer sin
 * tocar el esquema. Lo que tiene reglas y default —puntuación, formato, fechas,
 * modalidad— se queda en `torneos`: ahí una columna tipada es mejor que dos
 * strings.
 *
 * El riesgo de una tabla clave/valor es que se convierta en un cajón de sastre
 * donde cada quien escribe la clave que se le ocurre. Por eso el catálogo de
 * claves se declara acá: una clave que no esté en CLAVES se rechaza. Agregar una
 * nueva es editar esta constante, no adivinar qué se escribió en la base.
 *
 * `cierre_inscripcion` no es solo informativo: InscripcionService lo consulta y
 * rechaza anotarse después de esa fecha. Ver docs/documentacion_funcional.md.
 */
class ConfiguracionTorneoService
{
    /**
     * Catálogo de claves admitidas.
     *
     * tipo: 'texto' | 'texto_largo' | 'email' | 'fecha'
     * max:  longitud máxima; la columna es TEXT, el límite es de usabilidad.
     */
    public const CLAVES = [
        'sede' => [
            'etiqueta' => 'Sede',
            'tipo'     => 'texto',
            'max'      => 150,
            'ayuda'    => 'Dónde se juega. Aparece en la ficha pública del torneo.',
        ],
        'contacto' => [
            'etiqueta' => 'Contacto',
            'tipo'     => 'email',
            'max'      => 120,
            'ayuda'    => 'Correo de consulta para los participantes.',
        ],
        'cierre_inscripcion' => [
            'etiqueta' => 'Cierre de inscripción',
            'tipo'     => 'fecha',
            'max'      => 10,
            'ayuda'    => 'Después de esta fecha no se aceptan más inscripciones. Vacío = sin límite.',
        ],
        'observaciones' => [
            'etiqueta' => 'Observaciones',
            'tipo'     => 'texto_largo',
            'max'      => 1000,
            'ayuda'    => 'Reglamento, material a llevar, condiciones especiales.',
        ],
    ];

    private ConfiguracionTorneoModel $model;
    private AuditoriaService         $auditoria;

    public function __construct()
    {
        $this->model     = new ConfiguracionTorneoModel();
        $this->auditoria = new AuditoriaService();
    }

    /**
     * Las cuatro claves de un torneo, siempre las cuatro.
     *
     * Las que no están guardadas vuelven como cadena vacía, así el formulario y
     * las vistas no tienen que preguntarse si la clave existe.
     *
     * @return array<string, string>
     */
    public function getPorTorneo(int $torneoId): array
    {
        $guardadas = $this->model->getPorTorneo($torneoId);

        $salida = [];
        foreach (array_keys(self::CLAVES) as $clave) {
            $salida[$clave] = $guardadas[$clave] ?? '';
        }
        return $salida;
    }

    /**
     * El juego de claves vacío, para el formulario de un torneo que todavía no
     * existe. Evita tener que consultar por un id inventado.
     *
     * @return array<string, string>
     */
    public function vacio(): array
    {
        return array_fill_keys(array_keys(self::CLAVES), '');
    }

    /**
     * Ídem para varios torneos, en una sola consulta.
     *
     * @param int[] $torneoIds
     * @return array<int, array<string, string>>
     */
    public function getPorTorneos(array $torneoIds): array
    {
        $crudo  = $this->model->getPorTorneos($torneoIds);
        $salida = [];

        foreach ($torneoIds as $id) {
            $id = (int) $id;
            foreach (array_keys(self::CLAVES) as $clave) {
                $salida[$id][$clave] = $crudo[$id][$clave] ?? '';
            }
        }
        return $salida;
    }

    /**
     * Guarda los valores enviados desde el formulario del torneo.
     *
     * Una clave con valor vacío se borra en vez de guardarse en blanco: «sin
     * sede» y «sede vacía» son lo mismo, y así no quedan filas sin información.
     *
     * @param array<string, string> $valores  clave => valor (se ignoran las claves ajenas al catálogo)
     * @param array|null $torneo  El torneo, para validar el cierre contra su fecha de inicio.
     * @return int Cantidad de claves que quedaron con valor.
     * @throws RuntimeException si algún valor no es válido.
     */
    public function guardar(int $torneoId, array $valores, ?array $torneo = null): int
    {
        $limpios = $this->validar($valores, $torneo);
        $antes   = $this->getPorTorneo($torneoId);
        $conValor = 0;

        foreach ($limpios as $clave => $valor) {
            if ($valor === '') {
                $this->model->borrar($torneoId, $clave);
                continue;
            }
            $this->model->guardar($torneoId, $clave, $valor);
            $conValor++;
        }

        $despues = $this->getPorTorneo($torneoId);
        if ($antes !== $despues) {
            $this->auditoria->log(
                'editar_configuracion_torneo',
                'configuraciones_torneo',
                $torneoId,
                "Datos del evento actualizados en el torneo {$torneoId}.",
                $antes,   // columnas JSON: AuditoriaModel serializa arrays
                $despues
            );
        }

        return $conValor;
    }

    /**
     * Valida el juego completo de valores y lo devuelve normalizado, SIN escribir
     * nada.
     *
     * Es pública porque el controlador la llama antes de guardar el torneo: el
     * formulario es uno solo, así que un contacto mal escrito no puede dejar el
     * torneo guardado y los datos del evento sin guardar.
     *
     * @param array<string, string> $valores
     * @param array|null $torneo Para contrastar el cierre con la fecha de inicio.
     * @return array<string, string> clave => valor normalizado ('' = sin valor)
     * @throws RuntimeException si algún valor no sirve.
     */
    public function validar(array $valores, ?array $torneo = null): array
    {
        $limpios = [];
        foreach (self::CLAVES as $clave => $def) {
            $valor = trim((string) ($valores[$clave] ?? ''));
            $limpios[$clave] = $valor === '' ? '' : $this->validarValor($valor, $def);
        }

        // El cierre de inscripción no puede caer después del arranque: aceptarlo
        // dejaría una fecha que no significa nada, porque el torneo ya empezó.
        if ($limpios['cierre_inscripcion'] !== '' && !empty($torneo['fecha_inicio'])) {
            if (strtotime($limpios['cierre_inscripcion']) > strtotime((string) $torneo['fecha_inicio'])) {
                throw new RuntimeException(
                    'El cierre de inscripción no puede ser posterior a la fecha de inicio del torneo ('
                    . $torneo['fecha_inicio'] . ').'
                );
            }
        }

        return $limpios;
    }

    /** Valida y normaliza un valor según el tipo declarado en el catálogo. */
    private function validarValor(string $valor, array $def): string
    {
        $etiqueta = $def['etiqueta'];

        if (mb_strlen($valor) > $def['max']) {
            throw new RuntimeException("«{$etiqueta}» no puede superar los {$def['max']} caracteres.");
        }

        switch ($def['tipo']) {
            case 'email':
                if (!filter_var($valor, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException("«{$etiqueta}» tiene que ser un correo electrónico válido.");
                }
                break;

            case 'fecha':
                // Formato exacto Y-m-d y fecha que exista de verdad: checkdate
                // descarta cosas como 2026-02-31, que strtotime aceptaría.
                $partes = explode('-', $valor);
                if (count($partes) !== 3
                    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)
                    || !checkdate((int) $partes[1], (int) $partes[2], (int) $partes[0])) {
                    throw new RuntimeException("«{$etiqueta}» tiene que ser una fecha válida (AAAA-MM-DD).");
                }
                break;
        }

        return $valor;
    }

    // ─── Cierre de inscripción ──────────────────────────────────────────────

    /** La fecha de cierre del torneo, o null si no tiene. */
    public function cierreInscripcion(int $torneoId): ?string
    {
        $valor = $this->model->get($torneoId, 'cierre_inscripcion');
        return ($valor === null || $valor === '') ? null : $valor;
    }

    /**
     * ¿Ya pasó el plazo para anotarse?
     *
     * Se compara por día: el cierre incluye a su propia fecha, así que un torneo
     * que cierra el 20 acepta inscripciones durante todo el 20.
     *
     * Sin fecha de cierre no hay plazo y devuelve false: la clave es opcional y
     * su ausencia no puede cerrar un torneo.
     */
    public function inscripcionVencida(int $torneoId, ?string $hoy = null): bool
    {
        $cierre = $this->cierreInscripcion($torneoId);
        if ($cierre === null) return false;

        return ($hoy ?? date('Y-m-d')) > $cierre;
    }
}
