<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Torneo</div>
    <h1><?= $torneo ? 'Editar torneo' : 'Nuevo torneo' ?></h1>
    <p class="muted">Los campos visibles cambian según el formato seleccionado.</p>
  </div>
  <a class="btn" href="/admin/torneos">← Volver</a>
</section>

<?php $bloqueadoEstructural = ($torneo['estado'] ?? '') === 'en_curso'; ?>
<?php if ($bloqueadoEstructural): ?>
<div class="card aviso-info">
  <strong>Torneo en curso.</strong> El formato, la modalidad y la estructura quedan bloqueados. Podés ajustar nombre, descripción, fechas, puntuación, unidad de puntos y empates — los cambios se aplican de inmediato.
</div>
<?php endif; ?>

<form method="POST" action="/admin/torneos/<?= $torneo ? 'editar/' . (int)$torneo['id'] : 'crear' ?>" class="form-card" id="torneoForm">
  <?= Csrf::field() ?>
  <div class="form-grid">

    <div class="field">
      <label for="torneo-form-nombre">Nombre *</label>
      <input id="torneo-form-nombre" type="text" name="nombre" required value="<?= View::e($torneo['nombre'] ?? '') ?>">
    </div>

    <div class="field">
      <label for="tipoTorneo">Formato *</label>
      <select name="tipo_torneo_id" id="tipoTorneo" required <?= $bloqueadoEstructural ? 'disabled' : '' ?>>
        <option value="">Seleccionar formato…</option>
        <?php foreach ($tipos as $tipo): ?>
          <option value="<?= (int)$tipo['id'] ?>"
                  data-slug="<?= View::e($tipo['slug']) ?>"
                  <?= ($torneo['tipo_torneo_id'] ?? '') == $tipo['id'] ? 'selected' : '' ?>>
            <?= View::e($tipo['nombre']) ?><?= empty($tipo['modulo_activo']) ? ' — módulo deshabilitado' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="modalidad">Modalidad</label>
      <select name="modalidad" id="modalidad" <?= $bloqueadoEstructural ? 'disabled' : '' ?>>
        <option value="individual" <?= ($torneo['modalidad'] ?? 'individual') === 'individual' ? 'selected' : '' ?>>Individual (participantes)</option>
        <option value="equipos"    <?= ($torneo['modalidad'] ?? '') === 'equipos' ? 'selected' : '' ?>>Por equipos</option>
      </select>
    </div>

    <!-- Solo modalidad equipos -->
    <div class="field" id="campoMinEquipo">
      <label for="torneo-form-min_integrantes_equipo">Mínimo de integrantes por equipo *</label>
      <input id="torneo-form-min_integrantes_equipo" type="number" name="min_integrantes_equipo" min="1" max="50"
             value="<?= (int)($torneo['min_integrantes_equipo'] ?? 0) ?: '' ?>"
             placeholder="Ej: 11" <?= $bloqueadoEstructural ? 'disabled' : '' ?>>
      <span class="muted fs-80">Los equipos con menos integrantes no podrán inscribirse.</span>
    </div>

    <div class="field">
      <label for="torneo-form-organizador_id">Organizador asignado *</label>
      <select id="torneo-form-organizador_id" name="organizador_id" required <?= Auth::isAdmin() ? '' : 'disabled' ?>>
        <option value="">Seleccionar organizador…</option>
        <?php foreach ($organizadores as $org): ?>
          <option value="<?= (int)$org['id'] ?>" <?= ((string)($organizadorActual ?? '') === (string)$org['id']) ? 'selected' : '' ?>>
            <?= View::e($org['nombre']) ?> (<?= View::e($org['rol_nombre']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (!Auth::isAdmin()): ?>
        <span class="muted fs-80">Solo un administrador puede reasignar el torneo a otro organizador.</span>
      <?php elseif (empty($organizadores)): ?>
        <span class="muted fs-80 color-aviso">No hay organizadores. Creá uno en “Organizadores” antes de crear un torneo.</span>
      <?php endif; ?>
    </div>

    <div class="field">
      <label for="torneo-form-estado">Estado inicial</label>
      <select id="torneo-form-estado" name="estado">
        <option value="borrador"     <?= ($torneo['estado'] ?? 'borrador') === 'borrador' ? 'selected' : '' ?>>Borrador</option>
        <option value="inscripcion"  <?= ($torneo['estado'] ?? '') === 'inscripcion' ? 'selected' : '' ?>>Inscripción abierta</option>
      </select>
    </div>

    <div class="field">
      <label>
        <input type="checkbox" name="publico" value="1" <?= ($torneo['publico'] ?? 1) ? 'checked' : '' ?>>
        Visible en la vista pública
      </label>
    </div>

    <div class="field">
      <label for="torneo-form-fecha_inicio">Fecha inicio *</label>
      <input id="torneo-form-fecha_inicio" type="date" name="fecha_inicio" required value="<?= View::e($torneo['fecha_inicio'] ?? '') ?>">
    </div>

    <div class="field">
      <label for="torneo-form-fecha_fin">Fecha fin *</label>
      <input id="torneo-form-fecha_fin" type="date" name="fecha_fin" required value="<?= View::e($torneo['fecha_fin'] ?? '') ?>">
    </div>

    <div class="field">
      <label for="torneo-form-nombre_puntos">Nombre de la unidad de puntos</label>
      <select id="torneo-form-nombre_puntos" name="nombre_puntos">
        <?php foreach (['puntos','goles','mapas','rondas','sets'] as $np): ?>
          <option value="<?= $np ?>" <?= ($torneo['nombre_puntos'] ?? 'puntos') === $np ? 'selected' : '' ?>><?= ucfirst($np) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field" id="campoPuntuacion">
      <label for="torneo-form-puntos_victoria">Puntuación (victoria / empate / derrota)</label>
      <div class="grilla-puntos">
        <input id="torneo-form-puntos_victoria" type="number" aria-label="Puntos por victoria" name="puntos_victoria" value="<?= (int)($torneo['puntos_victoria'] ?? 3) ?>" min="0" placeholder="V">
        <input type="number" aria-label="Puntos por empate" name="puntos_empate"   value="<?= (int)($torneo['puntos_empate']   ?? 1) ?>" min="0" placeholder="E">
        <input type="number" aria-label="Puntos por derrota" name="puntos_derrota"  value="<?= (int)($torneo['puntos_derrota']  ?? 0) ?>" min="0" placeholder="D">
      </div>
    </div>

    <!-- Solo Liga/Suizo -->
    <div class="field" id="campoEmpates">
      <label>
        <input type="checkbox" name="permite_empates" value="1" <?= ($torneo['permite_empates'] ?? 0) ? 'checked' : '' ?>>
        Permitir empates
      </label>
    </div>

    <div class="field">
      <?php
        /*
         * El hidden en 0 antes de la casilla es a proposito: un checkbox sin
         * tildar no manda nada, y el servidor no podria distinguir "el usuario
         * lo destildo" de "el formulario no trae el campo". Con el hidden, el
         * campo llega siempre; si la casilla esta tildada, su valor lo pisa.
         *
         * Un torneo nuevo arranca con el criterio activado, que es el DEFAULT
         * del esquema.
         */
        $usaPF = (int)($torneo['usa_puntos_favor'] ?? 1) === 1;
      ?>
      <label>
        <input type="hidden" name="usa_puntos_favor" value="0">
        <input type="checkbox" name="usa_puntos_favor" value="1" <?= $usaPF ? 'checked' : '' ?>>
        Desempatar por puntos a favor
      </label>
      <small class="muted">
        Con esto activado, dos participantes con los mismos puntos se ordenan por
        diferencia y luego por puntos a favor. Conviene desactivarlo cuando el
        marcador no mide rendimiento (ajedrez: 1, &frac12; o 0), porque ahi
        &laquo;puntos a favor&raquo; repite lo que ya dice la columna de puntos.
      </small>
    </div>

    <!-- Solo Suizo -->
    <div class="field" id="campoRondasSuizo">
      <label for="torneo-form-rondas_suizo">Cantidad de rondas (Suizo) *</label>
      <input id="torneo-form-rondas_suizo" type="number" name="rondas_suizo" min="2" max="20" value="<?= (int)($torneo['rondas_suizo'] ?? 5) ?>" <?= $bloqueadoEstructural ? 'disabled' : '' ?>>
    </div>

    <div class="field" id="campoByeSuizo">
      <label for="byeSuizo">Puntuación por bye (Suizo)</label>
      <select name="bye_suizo" id="byeSuizo" <?= $bloqueadoEstructural ? 'disabled' : '' ?>>
        <option value="sin_puntos"    <?= ($torneo['bye_suizo'] ?? 'sin_puntos') === 'sin_puntos' ? 'selected' : '' ?>>Sin puntos</option>
        <option value="victoria"      <?= ($torneo['bye_suizo'] ?? '') === 'victoria' ? 'selected' : '' ?>>Equivalente a victoria</option>
        <option value="personalizado" <?= ($torneo['bye_suizo'] ?? '') === 'personalizado' ? 'selected' : '' ?>>Puntaje personalizado</option>
      </select>
    </div>

    <div class="field" id="campoPuntsBye" hidden>
      <label for="torneo-form-puntos_bye_suizo">Puntos por bye (personalizado)</label>
      <input id="torneo-form-puntos_bye_suizo" type="number" name="puntos_bye_suizo" min="0" step="0.5" value="<?= (float)($torneo['puntos_bye_suizo'] ?? 0) ?>" <?= $bloqueadoEstructural ? 'disabled' : '' ?>>
    </div>

    <div class="field span-all">
      <label for="torneo-form-descripcion">Descripción</label>
      <textarea id="torneo-form-descripcion" name="descripcion"><?= View::e($torneo['descripcion'] ?? '') ?></textarea>
    </div>

    <?php
      /*
       * Datos del evento: tabla `configuraciones_torneo` (clave/valor).
       * Los campos salen del catálogo declarado en ConfiguracionTorneoService,
       * así que agregar una clave nueva no requiere tocar esta vista.
       */
    ?>
    <div class="separador-seccion">
      <div class="eyebrow">Datos del evento</div>
      <p class="muted ayuda-seccion">
        Opcionales. Se muestran en la ficha pública del torneo.
      </p>
    </div>

    <?php foreach ($clavesConfig as $clave => $def): ?>
      <?php $valor = $configuracion[$clave] ?? ''; ?>
      <div class="field<?= $def['tipo'] === 'texto_largo' ? ' span-all' : '' ?>">
        <label for="config_<?= View::e($clave) ?>"><?= View::e($def['etiqueta']) ?></label>
        <?php if ($def['tipo'] === 'texto_largo'): ?>
          <textarea id="config_<?= View::e($clave) ?>"
                    name="config[<?= View::e($clave) ?>]"
                    maxlength="<?= (int)$def['max'] ?>"><?= View::e($valor) ?></textarea>
        <?php else: ?>
          <input id="config_<?= View::e($clave) ?>" autocomplete="off"
                 name="config[<?= View::e($clave) ?>]"
                 type="<?= $def['tipo'] === 'fecha' ? 'date' : ($def['tipo'] === 'email' ? 'email' : 'text') ?>"
                 maxlength="<?= (int)$def['max'] ?>"
                 value="<?= View::e($valor) ?>">
        <?php endif; ?>
        <small class="muted"><?= View::e($def['ayuda']) ?></small>
      </div>
    <?php endforeach; ?>

  </div>
  <div class="form-actions">
    <a class="btn" href="/admin/torneos">Cancelar</a>
    <button type="submit" class="btn primary"><?= $torneo ? 'Guardar cambios' : 'Crear torneo' ?></button>
  </div>
</form>
<script src="/assets/js/torneo-form.js"></script>
