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
  <form class="d-inline" method="POST" action="<?= $ra_base ?>/rondas/<?= $ra_id ?>/reabrir"
        data-confirmar="¿Reabrir esta ronda? Va a volver a admitir carga de resultados.">
    <?= Csrf::field() ?>
    <button type="submit" class="btn small">Reabrir ronda</button>
  </form>
<?php else: ?>
  <form class="d-inline" method="POST" action="<?= $ra_base ?>/rondas/<?= $ra_id ?>/cerrar"
        data-confirmar="¿Cerrar esta ronda? No se van a poder cargar resultados hasta que la reabras.">
    <?= Csrf::field() ?>
    <button type="submit" class="btn small danger">Cerrar ronda</button>
  </form>
<?php endif; ?>
