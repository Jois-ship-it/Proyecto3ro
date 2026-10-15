<?php
/**
 * Las hojas de estilo del sitio (ver View::HOJAS), con su versión. La de impresión solo se carga al imprimir.
 */
foreach (View::HOJAS as $hoja): ?>
  <link rel="stylesheet" href="<?= View::e(View::asset('/assets/css/' . $hoja . '.css')) ?>">
<?php endforeach; ?>
  <link rel="stylesheet" href="<?= View::e(View::asset('/assets/css/impresion.css')) ?>" media="print">
<?php
// Las posiciones y tamaños que calculó la página (bracket, barras, avatares). Va en un <style> con el nonce del pedido
// porque el CSP no admite atributos de estilo en las etiquetas. El contenido es numérico por construcción (View::claseDinamica()).
$estilosDinamicos = View::estilosDinamicos();
if ($estilosDinamicos !== ''): ?>
  <style nonce="<?= View::e(Csp::nonce()) ?>"><?= $estilosDinamicos ?></style>
<?php endif; ?>
