<?php
$slug      = $tipo['slug'] ?? '';
$esLiga    = $slug === 'liga';
$esElim    = $slug === 'eliminacion_directa';
$esSuizo   = $slug === 'suizo';
$puedeEditar = true;
$panelBase = '/admin';   // prefijo de rutas que usan los partials compartidos
?>
<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Gestión de torneo</div>
    <h1 class="rompe-texto"><?= View::e($torneo['nombre']) ?></h1>
    <div class="flex gap-05 flex-wrap mt-04">
      <?= View::estadoChip($torneo['estado']) ?>
      <span class="chip"><?= View::e($tipo['nombre'] ?? '') ?></span>
      <span class="chip"><?= View::e(ucfirst($torneo['modalidad'])) ?></span>
      <?php if ($esSuizo): ?>
        <span class="chip"><?= (int)$totalRondasJugadas ?>/<?= (int)$torneo['rondas_suizo'] ?> rondas</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="actions">
    <a class="btn" href="/admin/torneos">← Torneos</a>
    <a class="btn" href="/admin/torneos/editar/<?= (int)$torneo['id'] ?>">Editar</a>
    <a class="btn" href="/admin/torneos/<?= (int)$torneo['id'] ?>/reporte">Reporte</a>
  </div>
</section>

<!-- Campeón -->
<?php if ($torneo['estado'] === 'finalizado'): ?>
<div class="card banner-campeon">
  <div class="eyebrow color-ok">Torneo finalizado</div>
  <h2 class="my-03">🏆 <?= View::e($torneo['campeon_participante_nombre'] ?? $torneo['campeon_equipo_nombre'] ?? 'Sin definir') ?></h2>
  <span class="chip success">Campeón</span>
</div>
<?php endif; ?>

<!-- Desempate pendiente -->
<?php if (!empty($desempatePendiente)): ?>
<div class="card aviso-advertencia">
  <div class="eyebrow color-aviso">Desempate en curso</div>
  <p class="m-03-0-0">Hay un empate exacto en la cima de la tabla. Se generó un <strong>partido de desempate</strong> entre los dos primeros: cargá su resultado para definir al campeón. Si vuelve a empatar, se generará otro automáticamente.</p>
</div>
<?php endif; ?>

<!-- Generar competencia -->
<?php if (in_array($torneo['estado'], ['borrador','inscripcion']) && count($inscritos) >= 2): ?>
<div class="panel mb-1">
  <h2 class="como-h3">Generar competencia</h2>
  <p class="muted"><?= count($inscritos) ?> inscritos. Se generará el <?= $esLiga ? 'fixture' : ($esElim ? 'bracket' : 'primera ronda') ?>.</p>
  <form method="POST" action="/admin/torneos/<?= (int)$torneo['id'] ?>/generar"
        data-confirmar="¿Confirmar generación de la competencia? Esta acción no se puede deshacer.">
    <?= Csrf::field() ?>
    <button type="submit" class="btn primary">Generar competencia</button>
  </form>
</div>
<?php endif; ?>

<!-- Siguiente ronda (Suizo) -->
<?php if ($esSuizo && $torneo['estado'] === 'en_curso' && $ultimaRondaCompleta && $totalRondasJugadas < $torneo['rondas_suizo']): ?>
<div class="panel panel-info">
  <h2 class="como-h3">Siguiente ronda Suizo</h2>
  <p class="muted">Todas las partidas de la ronda <?= (int)$totalRondasJugadas ?> están finalizadas.</p>
  <form method="POST" action="/admin/torneos/<?= (int)$torneo['id'] ?>/siguiente-ronda"
        data-confirmar="¿Generar ronda <?= (int)$totalRondasJugadas + 1 ?>?">
    <?= Csrf::field() ?>
    <button type="submit" class="btn primary">Generar ronda <?= (int)$totalRondasJugadas + 1 ?></button>
  </form>
</div>
<?php endif; ?>

<!-- Inscripciones -->
<?php $inscripcionBase = '/admin/torneos/' . (int)$torneo['id']; ?>
<?php include APP_PATH . '/views/partials/inscripciones_panel.php'; ?>

<!-- Tabla de posiciones / Ranking -->
<?php if (!empty($tabla)): ?>
  <div class="mb-15">
    <?php if ($esSuizo): ?>
      <?php include APP_PATH . '/views/partials/suizo_ranking.php'; ?>
    <?php else: ?>
      <?php include APP_PATH . '/views/partials/liga_tabla.php'; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- Bracket / Partidos -->
<?php if (!empty($rondasConPartidos)): ?>
  <?php if ($esElim): ?>
    <?php include APP_PATH . '/views/partials/eliminacion_bracket.php'; ?>
  <?php elseif ($esSuizo): ?>
    <?php include APP_PATH . '/views/partials/suizo_rondas.php'; ?>
  <?php else: ?>
    <?php include APP_PATH . '/views/partials/liga_fixture.php'; ?>
  <?php endif; ?>
<?php endif; ?>

<!-- Modal cargar resultado -->
<div class="modal-fondo" id="modalCargar" data-cierra-fuera aria-hidden="true">
  <div class="form-card modal-card modal-460" role="dialog" aria-modal="true" aria-labelledby="modalCargarTitulo">
    <h3 id="modalCargarTitulo" data-prefijo-titulo="">Cargar resultado</h3>
    <form method="POST" action="/admin/resultados/cargar" id="formCargar" data-api>
      <div class="chip danger alerta-form" data-api-error hidden role="alert"></div>
      <?= Csrf::field() ?>
      <input type="hidden" name="enfrentamiento_id" id="cargarEnfId">
      <input type="hidden" name="torneo_id" value="<?= (int)$torneo['id'] ?>">
      <div class="form-grid">
        <div class="field">
          <label id="lblCargarA" for="cargarPuntosA">Local</label>
          <input type="number" name="puntos_a" min="0" step="1" required id="cargarPuntosA">
        </div>
        <div class="field">
          <label id="lblCargarB" for="cargarPuntosB">Visitante</label>
          <input type="number" name="puntos_b" min="0" step="1" required id="cargarPuntosB">
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn" data-cerrar-modal>Cancelar</button>
        <button type="submit" class="btn primary">Confirmar resultado</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal corregir resultado -->
<div class="modal-fondo" id="modalCorregir" data-cierra-fuera aria-hidden="true">
  <div class="form-card modal-card modal-500" role="dialog" aria-modal="true" aria-labelledby="modalCorregirTitulo">
    <h3 id="modalCorregirTitulo">Corregir resultado</h3>
    <div class="chip warning mb-075 d-block">⚠️ Esta acción recalcula la tabla. Ingresá un motivo.</div>
    <form method="POST" action="/admin/resultados/corregir" id="formCorregir" data-api>
      <div class="chip danger alerta-form" data-api-error hidden role="alert"></div>
      <?= Csrf::field() ?>
      <input type="hidden" name="enfrentamiento_id" id="corregirEnfId">
      <input type="hidden" name="torneo_id" value="<?= (int)$torneo['id'] ?>">
      <div class="form-grid">
        <div class="field">
          <label id="lblCorregirA" for="corregirPuntosA">Local</label>
          <input type="number" name="puntos_a" min="0" step="1" required id="corregirPuntosA">
        </div>
        <div class="field">
          <label id="lblCorregirB" for="corregirPuntosB">Visitante</label>
          <input type="number" name="puntos_b" min="0" step="1" required id="corregirPuntosB">
        </div>
        <div class="field span-all">
          <label for="torneo-gestion-motivo_correccion">Motivo de la corrección *</label>
          <textarea id="torneo-gestion-motivo_correccion" name="motivo_correccion" required minlength="10" placeholder="Describí el motivo…"></textarea>
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn" data-cerrar-modal>Cancelar</button>
        <button type="submit" class="btn danger">Corregir resultado</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal programar partido -->
<div class="modal-fondo" id="modalProgramar" data-cierra-fuera aria-hidden="true">
  <div class="form-card modal-card modal-460" role="dialog" aria-modal="true" aria-labelledby="modalProgramarTitulo">
    <h3 id="modalProgramarTitulo" data-prefijo-titulo="Programar · ">Programar partido</h3>
    <form method="POST" action="/admin/resultados/programar" data-api>
      <div class="chip danger alerta-form" data-api-error hidden role="alert"></div>
      <?= Csrf::field() ?>
      <input type="hidden" name="enfrentamiento_id" id="programarEnfId">
      <input type="hidden" name="torneo_id" value="<?= (int)$torneo['id'] ?>">
      <div class="form-grid">
        <div class="field span-all">
          <label for="programarFecha">Fecha y hora del encuentro</label>
          <?php
            $tIni = !empty($torneo['fecha_inicio']) ? $torneo['fecha_inicio'] : null;
            $tFin = !empty($torneo['fecha_fin'])    ? $torneo['fecha_fin']    : null;
          ?>
          <input type="datetime-local" name="fecha_programada" id="programarFecha"
                 <?= $tIni ? 'min="' . View::e($tIni) . 'T00:00"' : '' ?>
                 <?= $tFin ? 'max="' . View::e($tFin) . 'T23:59"' : '' ?>>
          <span class="muted fs-80">
            <?php if ($tIni && $tFin): ?>Debe estar entre el <?= View::fecha($tIni) ?> y el <?= View::fecha($tFin) ?>. <?php endif; ?>
            Dejá el campo vacío para quitar la fecha programada.
          </span>
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn" data-cerrar-modal>Cancelar</button>
        <button type="submit" class="btn primary">Guardar fecha</button>
      </div>
    </form>
  </div>
</div>

