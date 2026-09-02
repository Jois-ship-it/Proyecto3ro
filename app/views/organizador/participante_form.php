<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Participante</div>
    <h1><?= $participante ? 'Editar participante' : 'Nuevo participante' ?></h1>
  </div>
  <a class="btn" href="/organizador/participantes">← Volver</a>
</section>

<form method="POST" action="/organizador/participantes/<?= $participante ? 'editar/' . (int)$participante['id'] : 'crear' ?>" class="form-card">
  <?= Csrf::field() ?>
  <div class="form-grid">
    <div class="field">
      <label>Nombre completo *</label>
      <input type="text" name="nombre" required value="<?= View::e($participante['nombre'] ?? '') ?>">
    </div>
    <div class="field">
      <label>Nick / Alias</label>
      <input type="text" name="nick" value="<?= View::e($participante['nick'] ?? '') ?>">
    </div>
    <div class="field">
      <label>Documento (DNI / ID)</label>
      <input type="text" name="documento" value="<?= View::e($participante['documento'] ?? '') ?>">
    </div>
    <div class="field">
      <label>Email *</label>
      <input type="email" name="email" required value="<?= View::e($participante['email'] ?? '') ?>">
    </div>
    <div class="field">
      <label>Teléfono</label>
      <input type="text" name="telefono" value="<?= View::e($participante['telefono'] ?? '') ?>">
    </div>
    <?php if (!$participante): ?>
    <div class="field">
      <label>Contraseña *</label>
      <input type="password" name="password" required autocomplete="new-password">
    </div>
    <div class="field">
      <label>Confirmar contraseña *</label>
      <input type="password" name="password_confirm" required autocomplete="new-password">
    </div>
    <?php endif; ?>
    <?php if ($participante): ?>
    <div class="field">
      <label>Estado</label>
      <select name="estado">
        <option value="activo" <?= ($participante['estado'] ?? 'activo') === 'activo' ? 'selected' : '' ?>>Activo</option>
        <option value="inactivo" <?= ($participante['estado'] ?? '') === 'inactivo' ? 'selected' : '' ?>>Inactivo</option>
        <option value="suspendido" <?= ($participante['estado'] ?? '') === 'suspendido' ? 'selected' : '' ?>>Suspendido</option>
      </select>
    </div>
    <?php endif; ?>
  </div>
  <?php if (!$participante): ?>
  <ul id="pwHints" style="list-style:none;padding:0;margin:.6rem 0 0;font-size:.82rem;display:grid;gap:.2rem">
    <li data-rule="len">• Al menos 8 caracteres</li>
    <li data-rule="upper">• Una letra mayúscula</li>
    <li data-rule="lower">• Una letra minúscula</li>
    <li data-rule="num">• Un número</li>
    <li data-rule="sym">• Un símbolo (!@#$%&*…)</li>
    <li data-rule="match">• Las contraseñas coinciden</li>
  </ul>
  <?php endif; ?>
  <div class="form-actions">
    <a class="btn" href="/organizador/participantes">Cancelar</a>
    <button type="submit" class="btn primary"><?= $participante ? 'Guardar cambios' : 'Crear participante' ?></button>
  </div>
</form>

<?php if (!$participante): ?>
<script>
(function () {
  const pw = document.querySelector('[name=password]');
  const pc = document.querySelector('[name=password_confirm]');
  const hints = document.getElementById('pwHints');
  const btn = document.querySelector('[type=submit]');
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
<?php endif; ?>
