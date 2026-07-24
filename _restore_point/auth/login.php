<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Iniciar sesión · <?= APP_NAME ?></title>
  <link rel="icon" type="image/svg+xml" href="/assets/img/fa-icon.svg">
  <link rel="stylesheet" href="/assets/css/styles.css">
</head>
<body>
<div class="auth-layout">
  <div class="auth-card">
    <a class="brand" href="/" style="margin-bottom:1.4rem;display:inline-flex">
      <img src="/assets/img/fa-logo.svg" alt="FlexArena" style="height:54px;width:auto">
    </a>

    <h2 style="margin-bottom:.25rem">Iniciar sesión</h2>
    <p class="muted" style="margin-bottom:1.2rem">Accedé al panel de gestión de torneos.</p>

    <?php if ($msg = ($_flash['error'] ?? null)): ?>
      <div class="chip danger" style="margin-bottom:1rem;display:block;padding:.65rem;border-radius:12px;white-space:normal">
        <?= View::e($msg) ?>
      </div>
    <?php endif; ?>
    <?php if ($msg = ($_flash['success'] ?? null)): ?>
      <div class="chip success" style="margin-bottom:1rem;display:block;padding:.65rem;border-radius:12px;white-space:normal">
        <?= View::e($msg) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="/login" autocomplete="on">
      <?= Csrf::field() ?>
      <div class="form-grid" style="grid-template-columns:1fr;gap:.85rem">
        <div class="field">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" required autocomplete="email"
                 placeholder="tu@email.com"
                 value="<?= View::e($_POST['email'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="password">Contraseña</label>
          <input type="password" id="password" name="password" required autocomplete="current-password"
                 placeholder="••••••••">
        </div>
      </div>
      <div class="form-actions" style="margin-top:1.2rem">
        <a class="btn ghost" href="/">Volver</a>
        <button type="submit" class="btn primary">Ingresar</button>
      </div>
    </form>
  </div>
</div>
<script src="/assets/js/app.js"></script>
</body>
</html>
