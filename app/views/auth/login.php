<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Iniciar sesión · <?= View::e($_sitio['nombre']) ?></title>
  <link rel="icon" type="image/svg+xml" href="/assets/img/flexarena-logo.svg">
<?php include APP_PATH . '/views/partials/estilos.php'; ?>
</head>
<body>
<main class="auth-layout" id="contenido">
  <div class="auth-card">
    <a class="brand mb-14 d-inline-flex" href="/">
      <img class="logo-52" src="/assets/img/flexarena-logo.svg" alt="FlexArena">
    </a>

    <h1 class="como-h2 mb-025">Iniciar sesión</h1>
    <p class="muted mb-12">Accedé al panel de gestión de torneos.</p>

    <?php if (($_GET['msg'] ?? '') === 'datos_eliminados'): ?>
      <div class="chip success aviso-auth">
        Tus datos personales fueron eliminados correctamente (ley 18331 — Habeas Data). Tu sesión fue cerrada.
      </div>
    <?php endif; ?>
    <?php if ($msg = ($_flash['warning'] ?? null)): ?>
      <?php /* El motivo por el que se cerró la sesión (inactividad, cuenta bloqueada…) */ ?>
      <div class="chip warning aviso-auth">
        <?= View::e($msg) ?>
      </div>
    <?php endif; ?>
    <?php if ($msg = ($_flash['error'] ?? null)): ?>
      <div class="chip danger aviso-auth">
        <?= View::e($msg) ?>
      </div>
    <?php endif; ?>
    <?php if ($msg = ($_flash['success'] ?? null)): ?>
      <div class="chip success aviso-auth">
        <?= View::e($msg) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="/login" autocomplete="on">
      <?= Csrf::field() ?>
      <div class="form-grid auth-grid">
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
      <div class="form-actions mt-12">
        <a class="btn ghost" href="/">Volver</a>
        <button type="submit" class="btn primary">Ingresar</button>
      </div>
    </form>
    <p class="muted text-center mt-1 fs-90">
      ¿No tenés cuenta? <a class="color-primario bold" href="/registro">Registrate como participante</a>
    </p>
  </div>
</main>
<script src="<?= View::e(View::asset('/assets/js/app.js')) ?>"></script>
</body>
</html>
