<?php
// Variables: $rondasConPartidos (array), $torneo (array), $puedeEditar (bool), $csrf (string)
?>
<?php foreach ($rondasConPartidos as $bloque): ?>
  <?php $ronda = $bloque['ronda']; $partidos = $bloque['partidos']; ?>
  <div class="panel" style="margin-bottom:1rem">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:.75rem;">
      <strong><?= View::e($ronda['nombre']) ?></strong>
      <div style="display:flex;align-items:center;gap:.5rem">
        <?= View::estadoChip($ronda['estado']) ?>
        <?php include __DIR__ . '/ronda_acciones.php'; ?>
      </div>
    </div>
    <?php if (empty($partidos)): ?>
      <p class="muted">Sin partidos en esta fecha.</p>
    <?php else: ?>
      <?php include __DIR__ . '/match_list.php'; ?>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
