<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Crear cuenta · <?= View::e($_sitio['nombre']) ?></title>
  <link rel="icon" type="image/svg+xml" href="/assets/img/flexarena-logo.svg">
<?php include APP_PATH . '/views/partials/estilos.php'; ?>
</head>
<body>
<main class="auth-layout" id="contenido">
  <div class="auth-card auth-520">
    <a class="brand mb-12 d-inline-flex" href="/">
      <img class="logo-48" src="/assets/img/flexarena-logo.svg" alt="FlexArena">
    </a>

    <h1 class="como-h2 mb-025">Crear cuenta de participante</h1>
    <p class="muted mb-12">Tu cuenta quedará <strong>pendiente de aprobación</strong> hasta que un administrador la habilite.</p>

    <?php if ($msg = ($_flash['error'] ?? null)): ?>
      <div class="chip danger aviso-auth"><?= View::e($msg) ?></div>
    <?php endif; ?>

    <form method="POST" action="/registro" autocomplete="on" id="registroForm">
      <?= Csrf::field() ?>
      <div class="form-grid">
        <div class="field">
          <label for="nombre">Nombre completo *</label>
          <input type="text" id="nombre" name="nombre" required value="<?= View::e($old['nombre'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="nick">Nick / Alias</label>
          <input type="text" id="nick" name="nick" value="<?= View::e($old['nick'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="email">Email *</label>
          <input type="email" id="email" name="email" required autocomplete="email" value="<?= View::e($old['email'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="telefono">Teléfono</label>
          <input type="text" id="telefono" name="telefono" value="<?= View::e($old['telefono'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="password">Contraseña *</label>
          <input type="password" id="password" name="password" required autocomplete="new-password">
        </div>
        <div class="field">
          <label for="password_confirm">Confirmar contraseña *</label>
          <input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">
        </div>
      </div>

      <ul class="pw-reglas" id="pwHints">
        <li data-rule="len">• Al menos 8 caracteres</li>
        <li data-rule="upper">• Una letra mayúscula</li>
        <li data-rule="lower">• Una letra minúscula</li>
        <li data-rule="num">• Un número</li>
        <li data-rule="sym">• Un símbolo (!@#$%&*…)</li>
        <li data-rule="match">• Las contraseñas coinciden</li>
      </ul>

      <div class="form-actions mt-12">
        <a class="btn ghost" href="/login">Ya tengo cuenta</a>
        <button type="submit" class="btn primary" id="submitBtn">Crear cuenta</button>
      </div>
    </form>
  </div>
</main>

<script src="<?= View::e(View::asset('/assets/js/registro.js')) ?>"></script>
</body>
</html>
