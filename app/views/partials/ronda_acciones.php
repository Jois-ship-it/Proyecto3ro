<?php
/**
 * Botón de cerrar / reabrir una ronda.
 *
 * Variables: $ronda (array), $panelBase ('/admin' | '/organizador'), $puedeEditar (bool).
 *
 * Solo ofrece la acción que corresponde al estado actual; la regla de verdad
 * (quién puede, cuándo se puede) vive en RondaService y en TorneoController.
 */
if (!($puedeEditar ?? false)) return;

$ra_id   = (int) $ronda['id'];
$ra_base = rtrim($panelBase ?? '/admin', '/');
?>
<?php if ($ronda['estado'] === 'cerrada'): ?>
  <form method="POST" action="<?= $ra_base ?>/rondas/<?= $ra_id ?>/reabrir" style="display:inline"
        onsubmit="return confirm('¿Reabrir esta ronda? Va a volver a admitir carga de resultados.')">
    <?= Csrf::field() ?>
    <button type="submit" class="btn small">Reabrir ronda</button>
  </form>
<?php else: ?>
  <form method="POST" action="<?= $ra_base ?>/rondas/<?= $ra_id ?>/cerrar" style="display:inline"
        onsubmit="return confirm('¿Cerrar esta ronda? No se van a poder cargar resultados hasta que la reabras.')">
    <?= Csrf::field() ?>
    <button type="submit" class="btn small danger">Cerrar ronda</button>
  </form>
<?php endif; ?>
