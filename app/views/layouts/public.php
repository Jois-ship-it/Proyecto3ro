<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= isset($pageTitle) ? View::e($pageTitle) . ' · ' : '' ?><?= APP_NAME ?></title>
  <link rel="icon" type="image/svg+xml" href="/assets/img/flexarena-logo.svg">
  <link rel="stylesheet" href="/assets/css/styles.css">
</head>
<body data-page="<?= isset($bodyPage) ? View::e($bodyPage) : '' ?>">

<div class="public-shell">
  <?php require APP_PATH . '/views/partials/navbar_public.php'; ?>
  <?php require APP_PATH . '/views/partials/flash_messages.php'; ?>
  <?= $content ?>
  <?php require APP_PATH . '/views/partials/footer.php'; ?>
</div>

<script src="/assets/js/app.js"></script>
<?php if (isset($extraJs)): foreach ($extraJs as $js): ?>
  <script src="/assets/js/<?= View::e($js) ?>"></script>
<?php endforeach; endif; ?>
</body>
</html>
