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
<div class="card" style="background:rgba(52,152,219,.08);border-color:rgba(52,152,219,.3);margin-bottom:1rem;padding:.9rem 1.1rem">
  <strong>Torneo en curso.</strong> El formato, la modalidad y la estructura quedan bloqueados. Podés ajustar nombre, descripción, fechas, puntuación, unidad de puntos y empates — los cambios se aplican de inmediato.
</div>
<?php endif; ?>

<form method="POST" action="/admin/torneos/<?= $torneo ? 'editar/' . (int)$torneo['id'] : 'crear' ?>" class="form-card" id="torneoForm">
  <?= Csrf::field() ?>
  <div class="form-grid">

    <div class="field">
      <label>Nombre *</label>
      <input type="text" name="nombre" required value="<?= View::e($torneo['nombre'] ?? '') ?>">
    </div>

    <div class="field">
      <label>Formato *</label>
      <select name="tipo_torneo_id" id="tipoTorneo" required <?= $bloqueadoEstructural ? 'disabled' : '' ?>>
        <option value="">Seleccionar formato…</option>
        <?php foreach ($tipos as $tipo): ?>
          <option value="<?= (int)$tipo['id'] ?>"
                  data-slug="<?= View::e($tipo['slug']) ?>"
                  <?= ($torneo['tipo_torneo_id'] ?? '') == $tipo['id'] ? 'selected' : '' ?>>
            <?= View::e($tipo['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label>Modalidad</label>
      <select name="modalidad" id="modalidad" <?= $bloqueadoEstructural ? 'disabled' : '' ?>>
        <option value="individual" <?= ($torneo['modalidad'] ?? 'individual') === 'individual' ? 'selected' : '' ?>>Individual (participantes)</option>
        <option value="equipos"    <?= ($torneo['modalidad'] ?? '') === 'equipos' ? 'selected' : '' ?>>Por equipos</option>
      </select>
    </div>

    <!-- Solo modalidad equipos -->
    <div class="field" id="campoMinEquipo">
      <label>Mínimo de integrantes por equipo *</label>
      <input type="number" name="min_integrantes_equipo" min="1" max="50"
             value="<?= (int)($torneo['min_integrantes_equipo'] ?? 0) ?: '' ?>"
             placeholder="Ej: 11" <?= $bloqueadoEstructural ? 'disabled' : '' ?>>
      <span class="muted" style="font-size:.8rem">Los equipos con menos integrantes no podrán inscribirse.</span>
    </div>

    <div class="field">
      <label>Organizador asignado *</label>
      <select name="organizador_id" required>
        <option value="">Seleccionar organizador…</option>
        <?php foreach ($organizadores as $org): ?>
          <option value="<?= (int)$org['id'] ?>" <?= ((string)($organizadorActual ?? '') === (string)$org['id']) ? 'selected' : '' ?>>
            <?= View::e($org['nombre']) ?> (<?= View::e($org['rol_nombre']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (empty($organizadores)): ?>
        <span class="muted" style="font-size:.8rem;color:var(--warning)">No hay organizadores. Creá uno en “Organizadores” antes de crear un torneo.</span>
      <?php endif; ?>
    </div>

    <div class="field">
      <label>Estado inicial</label>
      <select name="estado">
        <option value="borrador"     <?= ($torneo['estado'] ?? 'borrador') === 'borrador' ? 'selected' : '' ?>>Borrador</option>
        <option value="inscripcion"  <?= ($torneo['estado'] ?? '') === 'inscripcion' ? 'selected' : '' ?>>Inscripción abierta</option>
      </select>
    </div>

    <div class="field">
      <label>Fecha inicio *</label>
      <input type="date" name="fecha_inicio" required value="<?= View::e($torneo['fecha_inicio'] ?? '') ?>">
    </div>

    <div class="field">
      <label>Fecha fin *</label>
      <input type="date" name="fecha_fin" required value="<?= View::e($torneo['fecha_fin'] ?? '') ?>">
    </div>

    <div class="field">
      <label>Nombre de la unidad de puntos</label>
      <select name="nombre_puntos">
        <?php foreach (['puntos','goles','mapas','rondas','sets'] as $np): ?>
          <option value="<?= $np ?>" <?= ($torneo['nombre_puntos'] ?? 'puntos') === $np ? 'selected' : '' ?>><?= ucfirst($np) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field" id="campoPuntuacion">
      <label>Puntuación (victoria / empate / derrota)</label>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem">
        <input type="number" name="puntos_victoria" value="<?= (int)($torneo['puntos_victoria'] ?? 3) ?>" min="0" placeholder="V">
        <input type="number" name="puntos_empate"   value="<?= (int)($torneo['puntos_empate']   ?? 1) ?>" min="0" placeholder="E">
        <input type="number" name="puntos_derrota"  value="<?= (int)($torneo['puntos_derrota']  ?? 0) ?>" min="0" placeholder="D">
      </div>
    </div>

    <!-- Solo Liga/Suizo -->
    <div class="field" id="campoEmpates">
      <label>
        <input type="checkbox" name="permite_empates" value="1" <?= ($torneo['permite_empates'] ?? 0) ? 'checked' : '' ?>>
        Permitir empates
      </label>
    </div>

    <!-- Solo Suizo -->
    <div class="field" id="campoRondasSuizo">
      <label>Cantidad de rondas (Suizo) *</label>
      <input type="number" name="rondas_suizo" min="2" max="20" value="<?= (int)($torneo['rondas_suizo'] ?? 5) ?>" <?= $bloqueadoEstructural ? 'disabled' : '' ?>>
    </div>

    <div class="field" id="campoByeSuizo">
      <label>Puntuación por bye (Suizo)</label>
      <select name="bye_suizo" id="byeSuizo" <?= $bloqueadoEstructural ? 'disabled' : '' ?>>
        <option value="sin_puntos"    <?= ($torneo['bye_suizo'] ?? 'sin_puntos') === 'sin_puntos' ? 'selected' : '' ?>>Sin puntos</option>
        <option value="victoria"      <?= ($torneo['bye_suizo'] ?? '') === 'victoria' ? 'selected' : '' ?>>Equivalente a victoria</option>
        <option value="personalizado" <?= ($torneo['bye_suizo'] ?? '') === 'personalizado' ? 'selected' : '' ?>>Puntaje personalizado</option>
      </select>
    </div>

    <div class="field" id="campoPuntsBye" style="display:none">
      <label>Puntos por bye (personalizado)</label>
      <input type="number" name="puntos_bye_suizo" min="0" step="0.5" value="<?= (float)($torneo['puntos_bye_suizo'] ?? 0) ?>" <?= $bloqueadoEstructural ? 'disabled' : '' ?>>
    </div>

    <div class="field" style="grid-column:1/-1">
      <label>Descripción</label>
      <textarea name="descripcion"><?= View::e($torneo['descripcion'] ?? '') ?></textarea>
    </div>

    <div class="field">
      <label>
        <input type="checkbox" name="publico" value="1" <?= ($torneo['publico'] ?? 1) ? 'checked' : '' ?>>
        Visible en la vista pública
      </label>
    </div>

  </div>
  <div class="form-actions">
    <a class="btn" href="/admin/torneos">Cancelar</a>
    <button type="submit" class="btn primary"><?= $torneo ? 'Guardar cambios' : 'Crear torneo' ?></button>
  </div>
</form>
<script src="/assets/js/torneo-form.js"></script>
