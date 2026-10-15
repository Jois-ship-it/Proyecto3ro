<?php
/**
 * Página de error genérica (400 y 500). Variables: $estado, $titulo, $mensaje, $id, $detalle.
 * La arma ManejadorErrores; tiene el mismo aspecto que 403 y 404.
 */
?>
<section class="section pagina-error">
  <div>
    <div class="eyebrow">Error <?= (int) $estado ?></div>
    <h1><?= View::e($titulo) ?></h1>
    <p><?= View::e($mensaje) ?></p>
    <?php if (!empty($id)): ?>
      <p class="muted">Código de referencia: <strong><?= View::e($id) ?></strong></p>
    <?php endif; ?>
    <?php if (!empty($detalle)): ?>
      <pre class="error-detalle"><?= View::e($detalle) ?></pre>
    <?php endif; ?>
    <div class="actions justify-center mt-15">
      <a class="btn primary" href="/">Ir al inicio</a>
    </div>
  </div>
</section>
