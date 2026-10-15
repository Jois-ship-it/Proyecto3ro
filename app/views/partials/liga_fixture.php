<?php
// Variables: $rondasConPartidos (array), $torneo (array), $puedeEditar (bool), $csrf (string)
?>
<?php foreach ($rondasConPartidos as $bloque): ?>
  <?php $ronda = $bloque['ronda']; $partidos = $bloque['partidos']; ?>
  <div class="panel mb-1">
    <div class="flex items-center justify-between gap-1 mb-075">
      <strong><?= View::e($ronda['nombre']) ?></strong>
      <div class="flex items-center gap-05">
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
