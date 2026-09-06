<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Crear cuenta · <?= APP_NAME ?></title>
  <link rel="icon" type="image/svg+xml" href="/assets/img/flexarena-logo.svg">
  <link rel="stylesheet" href="/assets/css/styles.css">
</head>
<body>
<div class="auth-layout">
  <div class="auth-card" style="width:min(100%,520px)">
    <a class="brand" href="/" style="margin-bottom:1.2rem;display:inline-flex">
      <img src="/assets/img/flexarena-logo.svg" alt="FlexArena" style="height:48px;width:auto">
    </a>

    <h2 style="margin-bottom:.25rem">Crear cuenta de participante</h2>
    <p class="muted" style="margin-bottom:1.2rem">Tu cuenta quedará <strong>pendiente de aprobación</strong> hasta que un administrador la habilite.</p>

    <?php if ($msg = ($_flash['error'] ?? null)): ?>
      <div class="chip danger" style="margin-bottom:1rem;display:block;padding:.65rem;border-radius:12px;white-space:normal"><?= View::e($msg) ?></div>
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

      <ul id="pwHints" style="list-style:none;padding:0;margin:.6rem 0 0;font-size:.82rem;display:grid;gap:.2rem">
        <li data-rule="len">• Al menos 8 caracteres</li>
        <li data-rule="upper">• Una letra mayúscula</li>
        <li data-rule="lower">• Una letra minúscula</li>
        <li data-rule="num">• Un número</li>
        <li data-rule="sym">• Un símbolo (!@#$%&*…)</li>
        <li data-rule="match">• Las contraseñas coinciden</li>
      </ul>

      <div class="form-actions" style="margin-top:1.2rem">
        <a class="btn ghost" href="/login">Ya tengo cuenta</a>
        <button type="submit" class="btn primary" id="submitBtn">Crear cuenta</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  const pw = document.getElementById('password');
  const pc = document.getElementById('password_confirm');
  const hints = document.getElementById('pwHints');
  const btn = document.getElementById('submitBtn');
  const ok = (li, good) => { li.style.color = good ? 'var(--success)' : 'var(--muted)'; li.dataset.ok = good ? '1' : '0'; };

  function check() {
    const v = pw.value;
    const rules = {
      len:   v.length >= 8,
      upper: /[A-ZÁÉÍÓÚÑ]/.test(v),
      lower: /[a-záéíóúñ]/.test(v),
      num:   /[0-9]/.test(v),
      sym:   /[^A-Za-z0-9]/.test(v),
      match: v.length > 0 && v === pc.value,
    };
    let allOk = true;
    hints.querySelectorAll('li').forEach(li => {
      const good = rules[li.dataset.rule];
      ok(li, good);
      if (!good) allOk = false;
    });
    btn.disabled = !allOk;
    btn.style.opacity = allOk ? '1' : '.6';
  }
  pw.addEventListener('input', check);
  pc.addEventListener('input', check);
  check();
})();
</script>
</body>
</html>
