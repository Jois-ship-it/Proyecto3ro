<?php
$slug      = $tipo['slug'] ?? '';
$esLiga    = $slug === 'liga';
$esElim    = $slug === 'eliminacion_directa';
$esSuizo   = $slug === 'suizo';
$puedeEditar = true;
?>
<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Gestión de torneo</div>
    <h1><?= View::e($torneo['nombre']) ?></h1>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.4rem">
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
  </div>
</section>

<!-- Campeón -->
<?php if ($torneo['estado'] === 'finalizado'): ?>
<div class="card" style="background:rgba(37,194,129,.1);border-color:rgba(37,194,129,.3);margin-bottom:1rem;text-align:center;padding:1.5rem">
  <div class="eyebrow" style="color:var(--success)">Torneo finalizado</div>
  <h2 style="margin:.3rem 0">🏆 <?= View::e($torneo['campeon_participante_nombre'] ?? $torneo['campeon_equipo_nombre'] ?? 'Sin definir') ?></h2>
  <span class="chip success">Campeón</span>
</div>
<?php endif; ?>

<!-- Desempate pendiente -->
<?php if (!empty($desempatePendiente)): ?>
<div class="card" style="background:rgba(241,196,15,.1);border-color:rgba(241,196,15,.35);margin-bottom:1rem;padding:1rem 1.25rem">
  <div class="eyebrow" style="color:var(--warning)">Desempate en curso</div>
  <p style="margin:.3rem 0 0">Hay un empate exacto en la cima de la tabla. Se generó un <strong>partido de desempate</strong> entre los dos primeros: cargá su resultado para definir al campeón. Si vuelve a empatar, se generará otro automáticamente.</p>
</div>
<?php endif; ?>

<!-- Generar competencia -->
<?php if (in_array($torneo['estado'], ['borrador','inscripcion']) && count($inscritos) >= 2): ?>
<div class="panel" style="margin-bottom:1rem">
  <h3>Generar competencia</h3>
  <p class="muted"><?= count($inscritos) ?> inscritos. Se generará el <?= $esLiga ? 'fixture' : ($esElim ? 'bracket' : 'primera ronda') ?>.</p>
  <form method="POST" action="/admin/torneos/<?= (int)$torneo['id'] ?>/generar"
        onsubmit="return confirm('¿Confirmar generación de la competencia? Esta acción no se puede deshacer.')">
    <?= Csrf::field() ?>
    <button type="submit" class="btn primary">Generar competencia</button>
  </form>
</div>
<?php endif; ?>

<!-- Siguiente ronda (Suizo) -->
<?php if ($esSuizo && $torneo['estado'] === 'en_curso' && $ultimaRondaCompleta && $totalRondasJugadas < $torneo['rondas_suizo']): ?>
<div class="panel" style="margin-bottom:1rem;border-color:rgba(52,152,219,.3)">
  <h3>Siguiente ronda Suizo</h3>
  <p class="muted">Todas las partidas de la ronda <?= (int)$totalRondasJugadas ?> están finalizadas.</p>
  <form method="POST" action="/admin/torneos/<?= (int)$torneo['id'] ?>/siguiente-ronda"
        onsubmit="return confirm('¿Generar ronda ' + <?= (int)$totalRondasJugadas + 1 ?> + '?')">
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
  <div style="margin-bottom:1.5rem">
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
<div id="modalCargar" style="display:none;position:fixed;inset:0;background:rgba(3,8,16,.78);z-index:100;place-items:center;display:none" aria-hidden="true">
  <div class="form-card modal-card" style="width:min(100%,460px)">
    <h3 id="modalCargarTitulo">Cargar resultado</h3>
    <form method="POST" action="/admin/resultados/cargar" id="formCargar">
      <?= Csrf::field() ?>
      <input type="hidden" name="enfrentamiento_id" id="cargarEnfId">
      <input type="hidden" name="torneo_id" value="<?= (int)$torneo['id'] ?>">
      <div class="form-grid">
        <div class="field">
          <label id="lblCargarA">Local</label>
          <input type="number" name="puntos_a" min="0" step="1" required id="cargarPuntosA">
        </div>
        <div class="field">
          <label id="lblCargarB">Visitante</label>
          <input type="number" name="puntos_b" min="0" step="1" required id="cargarPuntosB">
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn" onclick="cerrarModal('modalCargar')">Cancelar</button>
        <button type="submit" class="btn primary">Confirmar resultado</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal corregir resultado -->
<div id="modalCorregir" style="display:none;position:fixed;inset:0;background:rgba(3,8,16,.78);z-index:100;place-items:center" aria-hidden="true">
  <div class="form-card modal-card" style="width:min(100%,500px)">
    <h3>Corregir resultado</h3>
    <div class="chip warning" style="margin-bottom:.75rem;display:block">⚠️ Esta acción recalcula la tabla. Ingresá un motivo.</div>
    <form method="POST" action="/admin/resultados/corregir" id="formCorregir">
      <?= Csrf::field() ?>
      <input type="hidden" name="enfrentamiento_id" id="corregirEnfId">
      <input type="hidden" name="torneo_id" value="<?= (int)$torneo['id'] ?>">
      <div class="form-grid">
        <div class="field">
          <label id="lblCorregirA">Local</label>
          <input type="number" name="puntos_a" min="0" step="1" required id="corregirPuntosA">
        </div>
        <div class="field">
          <label id="lblCorregirB">Visitante</label>
          <input type="number" name="puntos_b" min="0" step="1" required id="corregirPuntosB">
        </div>
        <div class="field" style="grid-column:1/-1">
          <label>Motivo de la corrección *</label>
          <textarea name="motivo_correccion" required minlength="10" placeholder="Describí el motivo…"></textarea>
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn" onclick="cerrarModal('modalCorregir')">Cancelar</button>
        <button type="submit" class="btn danger">Corregir resultado</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal programar partido -->
<div id="modalProgramar" style="display:none;position:fixed;inset:0;background:rgba(3,8,16,.78);z-index:100;place-items:center" aria-hidden="true">
  <div class="form-card modal-card" style="width:min(100%,460px)">
    <h3 id="modalProgramarTitulo">Programar partido</h3>
    <form method="POST" action="/admin/resultados/programar">
      <?= Csrf::field() ?>
      <input type="hidden" name="enfrentamiento_id" id="programarEnfId">
      <input type="hidden" name="torneo_id" value="<?= (int)$torneo['id'] ?>">
      <div class="form-grid">
        <div class="field" style="grid-column:1/-1">
          <label>Fecha y hora del encuentro</label>
          <?php
            $tIni = !empty($torneo['fecha_inicio']) ? $torneo['fecha_inicio'] : null;
            $tFin = !empty($torneo['fecha_fin'])    ? $torneo['fecha_fin']    : null;
          ?>
          <input type="datetime-local" name="fecha_programada" id="programarFecha"
                 <?= $tIni ? 'min="' . View::e($tIni) . 'T00:00"' : '' ?>
                 <?= $tFin ? 'max="' . View::e($tFin) . 'T23:59"' : '' ?>>
          <span class="muted" style="font-size:.8rem">
            <?php if ($tIni && $tFin): ?>Debe estar entre el <?= View::fecha($tIni) ?> y el <?= View::fecha($tFin) ?>. <?php endif; ?>
            Dejá el campo vacío para quitar la fecha programada.
          </span>
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn" onclick="cerrarModal('modalProgramar')">Cancelar</button>
        <button type="submit" class="btn primary">Guardar fecha</button>
      </div>
    </form>
  </div>
</div>

<script>
// Abrir modal cargar resultado
document.querySelectorAll('[data-modal-cargar]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('cargarEnfId').value   = btn.dataset.enfId;
    document.getElementById('modalCargarTitulo').textContent = btn.dataset.nombreA + ' vs ' + btn.dataset.nombreB;
    document.getElementById('lblCargarA').textContent = btn.dataset.nombreA;
    document.getElementById('lblCargarB').textContent = btn.dataset.nombreB;
    document.getElementById('cargarPuntosA').value = '';
    document.getElementById('cargarPuntosB').value = '';
    const modal = document.getElementById('modalCargar');
    modal.style.display = 'grid';
    modal.setAttribute('aria-hidden','false');
  });
});
// Abrir modal corregir
document.querySelectorAll('[data-modal-corregir]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('corregirEnfId').value    = btn.dataset.enfId;
    document.getElementById('lblCorregirA').textContent = btn.dataset.nombreA;
    document.getElementById('lblCorregirB').textContent = btn.dataset.nombreB;
    document.getElementById('corregirPuntosA').value  = btn.dataset.puntosA;
    document.getElementById('corregirPuntosB').value  = btn.dataset.puntosB;
    const modal = document.getElementById('modalCorregir');
    modal.style.display = 'grid';
    modal.setAttribute('aria-hidden','false');
  });
});
// Abrir modal programar partido
document.querySelectorAll('[data-modal-programar]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('programarEnfId').value = btn.dataset.enfId;
    document.getElementById('programarFecha').value = btn.dataset.fecha || '';
    document.getElementById('modalProgramarTitulo').textContent = 'Programar · ' + btn.dataset.nombreA + ' vs ' + btn.dataset.nombreB;
    const modal = document.getElementById('modalProgramar');
    modal.style.display = 'grid';
    modal.setAttribute('aria-hidden','false');
  });
});
function cerrarModal(id) {
  const modal = document.getElementById(id);
  modal.style.display = 'none';
  modal.setAttribute('aria-hidden','true');
}
// Cerrar al hacer clic fuera
['modalCargar','modalCorregir','modalProgramar'].forEach(id => {
  document.getElementById(id).addEventListener('click', e => {
    if (e.target === e.currentTarget) cerrarModal(id);
  });
});
</script>
