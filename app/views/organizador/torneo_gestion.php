<?php
// Reutiliza la misma lógica de la vista admin, pero con rutas de organizador
$baseUrl = '/organizador/torneos/' . (int)$torneo['id'];
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
      <?php if ($esSuizo): ?>
        <span class="chip"><?= (int)$totalRondasJugadas ?>/<?= (int)$torneo['rondas_suizo'] ?> rondas</span>
      <?php endif; ?>
    </div>
  </div>
  <a class="btn" href="/organizador/torneos">← Mis torneos</a>
</section>

<?php if ($torneo['estado'] === 'finalizado'): ?>
<div class="card" style="background:rgba(37,194,129,.1);border-color:rgba(37,194,129,.3);margin-bottom:1rem;text-align:center;padding:1.5rem">
  <h2>🏆 <?= View::e($torneo['campeon_participante_nombre'] ?? $torneo['campeon_equipo_nombre'] ?? 'Sin definir') ?></h2>
  <span class="chip success">Campeón</span>
</div>
<?php endif; ?>

<?php if (!empty($desempatePendiente)): ?>
<div class="card" style="background:rgba(241,196,15,.1);border-color:rgba(241,196,15,.35);margin-bottom:1rem;padding:1rem 1.25rem">
  <div class="eyebrow" style="color:var(--warning)">Desempate en curso</div>
  <p style="margin:.3rem 0 0">Hay un empate exacto en la cima de la tabla. Se generó un <strong>partido de desempate</strong> entre los dos primeros: cargá su resultado para definir al campeón. Si vuelve a empatar, se generará otro automáticamente.</p>
</div>
<?php endif; ?>

<?php if (in_array($torneo['estado'], ['borrador','inscripcion']) && count($inscritos) >= 2): ?>
<div class="panel" style="margin-bottom:1rem">
  <h3>Generar competencia</h3>
  <form method="POST" action="<?= $baseUrl ?>/generar" onsubmit="return confirm('¿Confirmar generación?')">
    <?= Csrf::field() ?>
    <button type="submit" class="btn primary">Generar</button>
  </form>
</div>
<?php endif; ?>

<?php if ($esSuizo && $torneo['estado'] === 'en_curso' && $ultimaRondaCompleta && $totalRondasJugadas < $torneo['rondas_suizo']): ?>
<div class="panel" style="margin-bottom:1rem">
  <h3>Siguiente ronda Suizo</h3>
  <form method="POST" action="<?= $baseUrl ?>/siguiente-ronda">
    <?= Csrf::field() ?>
    <button type="submit" class="btn primary">Generar ronda <?= (int)$totalRondasJugadas + 1 ?></button>
  </form>
</div>
<?php endif; ?>

<!-- Inscripciones -->
<?php $inscripcionBase = $baseUrl; ?>
<?php include APP_PATH . '/views/partials/inscripciones_panel.php'; ?>

<?php if (!empty($tabla)): ?>
  <div style="margin-bottom:1.5rem">
    <?php if ($esSuizo): ?>
      <?php include APP_PATH . '/views/partials/suizo_ranking.php'; ?>
    <?php else: ?>
      <?php include APP_PATH . '/views/partials/liga_tabla.php'; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!empty($rondasConPartidos)): ?>
  <?php if ($esElim): ?>
    <?php include APP_PATH . '/views/partials/eliminacion_bracket.php'; ?>
  <?php elseif ($esSuizo): ?>
    <?php include APP_PATH . '/views/partials/suizo_rondas.php'; ?>
  <?php else: ?>
    <?php include APP_PATH . '/views/partials/liga_fixture.php'; ?>
  <?php endif; ?>
<?php endif; ?>

<!-- Modales reutilizados de admin -->
<div id="modalCargar" style="display:none;position:fixed;inset:0;background:rgba(3,8,16,.78);z-index:100;place-items:center" aria-hidden="true">
  <div class="form-card modal-card" style="width:min(100%,460px)">
    <h3 id="modalCargarTitulo">Cargar resultado</h3>
    <form method="POST" action="/organizador/resultados/cargar">
      <?= Csrf::field() ?>
      <input type="hidden" name="enfrentamiento_id" id="cargarEnfId">
      <input type="hidden" name="torneo_id" value="<?= (int)$torneo['id'] ?>">
      <div class="form-grid">
        <div class="field"><label id="lblCargarA">Local</label><input type="number" name="puntos_a" min="0" required></div>
        <div class="field"><label id="lblCargarB">Visitante</label><input type="number" name="puntos_b" min="0" required></div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn" onclick="cerrarModal('modalCargar')">Cancelar</button>
        <button type="submit" class="btn primary">Confirmar</button>
      </div>
    </form>
  </div>
</div>
<div id="modalCorregir" style="display:none;position:fixed;inset:0;background:rgba(3,8,16,.78);z-index:100;place-items:center" aria-hidden="true">
  <div class="form-card modal-card" style="width:min(100%,500px)">
    <h3 id="modalCorregirTitulo">Solicitar corrección</h3>
    <div class="chip warning" style="margin-bottom:.75rem;display:block;white-space:normal">
      Esta solicitud quedará <strong>pendiente de aprobación administrativa</strong>. El resultado no cambia hasta que un administrador la apruebe.
    </div>
    <form method="POST" action="/organizador/correcciones/solicitar">
      <?= Csrf::field() ?>
      <input type="hidden" name="enfrentamiento_id" id="corregirEnfId">
      <input type="hidden" name="torneo_id" value="<?= (int)$torneo['id'] ?>">
      <div class="form-grid">
        <div class="field"><label id="lblCorregirA">Local</label><input type="number" name="puntos_a" min="0" required id="corregirPuntosA"></div>
        <div class="field"><label id="lblCorregirB">Visitante</label><input type="number" name="puntos_b" min="0" required id="corregirPuntosB"></div>
        <div class="field" style="grid-column:1/-1"><label>Motivo *</label><textarea name="motivo_correccion" required minlength="10" placeholder="Explicá por qué debería corregirse…"></textarea></div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn" onclick="cerrarModal('modalCorregir')">Cancelar</button>
        <button type="submit" class="btn primary">Enviar solicitud</button>
      </div>
    </form>
  </div>
</div>
<!-- Modal programar partido -->
<div id="modalProgramar" style="display:none;position:fixed;inset:0;background:rgba(3,8,16,.78);z-index:100;place-items:center" aria-hidden="true">
  <div class="form-card modal-card" style="width:min(100%,460px)">
    <h3 id="modalProgramarTitulo">Programar partido</h3>
    <form method="POST" action="/organizador/resultados/programar">
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
document.querySelectorAll('[data-modal-programar]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('programarEnfId').value = btn.dataset.enfId;
    document.getElementById('programarFecha').value = btn.dataset.fecha || '';
    document.getElementById('modalProgramarTitulo').textContent = 'Programar · ' + (btn.dataset.nombreA||'') + ' vs ' + (btn.dataset.nombreB||'');
    const m = document.getElementById('modalProgramar');
    m.style.display = 'grid'; m.setAttribute('aria-hidden','false');
  });
});
document.querySelectorAll('[data-modal-cargar]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('cargarEnfId').value = btn.dataset.enfId;
    document.getElementById('lblCargarA').textContent = btn.dataset.nombreA || 'Local';
    document.getElementById('lblCargarB').textContent = btn.dataset.nombreB || 'Visitante';
    const m = document.getElementById('modalCargar');
    m.style.display = 'grid'; m.setAttribute('aria-hidden','false');
  });
});
document.querySelectorAll('[data-modal-corregir]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('corregirEnfId').value = btn.dataset.enfId;
    document.getElementById('corregirPuntosA').value = btn.dataset.puntosA;
    document.getElementById('corregirPuntosB').value = btn.dataset.puntosB;
    document.getElementById('lblCorregirA').textContent = btn.dataset.nombreA || 'Local';
    document.getElementById('lblCorregirB').textContent = btn.dataset.nombreB || 'Visitante';
    document.getElementById('modalCorregirTitulo').textContent = 'Solicitar corrección · ' + (btn.dataset.nombreA||'') + ' vs ' + (btn.dataset.nombreB||'');
    const m = document.getElementById('modalCorregir');
    m.style.display = 'grid'; m.setAttribute('aria-hidden','false');
  });
});
function cerrarModal(id) { const m=document.getElementById(id); m.style.display='none'; m.setAttribute('aria-hidden','true'); }
['modalCargar','modalCorregir','modalProgramar'].forEach(id => {
  document.getElementById(id).addEventListener('click', e => { if(e.target===e.currentTarget) cerrarModal(id); });
});
</script>
