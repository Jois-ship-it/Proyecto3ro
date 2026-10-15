<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($pageTitle) ? View::e($pageTitle) . ' · ' : '' ?><?= View::e($_sitio['nombre']) ?></title>
  <meta name="csrf-token" content="<?= View::e(Csrf::generate()) ?>">
  <link rel="icon" type="image/svg+xml" href="/assets/img/flexarena-logo.svg">
<?php include APP_PATH . '/views/partials/estilos.php'; ?>
</head>
<body data-page="<?= isset($bodyPage) ? View::e($bodyPage) : '' ?>">
<a class="saltar" href="#contenido">Saltar al contenido</a>

<div class="app-shell">
  <?php require APP_PATH . '/views/partials/sidebar.php'; ?>
  <main class="app-main">
    <?php require APP_PATH . '/views/partials/topbar.php'; ?>
    <section class="page" id="contenido" tabindex="-1">
      <?php require APP_PATH . '/views/partials/flash_messages.php'; ?>
      <?= $content ?>
    </section>
  </main>
</div>

<script src="<?= View::e(View::asset('/assets/js/app.js')) ?>"></script>
<?php if (isset($extraJs)): foreach ($extraJs as $js): ?>
  <script src="<?= View::e(View::asset('/assets/js/' . $js)) ?>"></script>
<?php endforeach; endif; ?>
</body>
</html>
