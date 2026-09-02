<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($pageTitle) ? View::e($pageTitle) . ' · ' : '' ?><?= APP_NAME ?></title>
  <link rel="icon" type="image/svg+xml" href="/assets/img/fa-icon.svg">
  <link rel="stylesheet" href="/assets/css/styles.css">
</head>
<body data-page="<?= isset($bodyPage) ? View::e($bodyPage) : '' ?>">

<div class="app-shell">
  <?php require APP_PATH . '/views/partials/sidebar.php'; ?>
  <main class="app-main">
    <?php require APP_PATH . '/views/partials/topbar.php'; ?>
    <section class="page">
      <?php require APP_PATH . '/views/partials/flash_messages.php'; ?>
      <?= $content ?>
    </section>
  </main>
</div>

<script src="/assets/js/app.js"></script>
<?php if (isset($extraJs)): foreach ($extraJs as $js): ?>
  <script src="/assets/js/<?= View::e($js) ?>"></script>
<?php endforeach; endif; ?>
</body>
</html>
