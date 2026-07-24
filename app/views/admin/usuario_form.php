<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Organizador</div>
    <h1><?= $usuario ? 'Editar organizador' : 'Nuevo organizador' ?></h1>
    <p class="muted">Las cuentas creadas aquí tienen rol <strong>Organizador</strong>.</p>
  </div>
  <a class="btn" href="/admin/usuarios">← Volver</a>
</section>

<form method="POST" action="/admin/usuarios/<?= $usuario ? 'editar/' . (int)$usuario['id'] : 'crear' ?>" class="form-card">
  <?= Csrf::field() ?>
  <div class="form-grid">
    <div class="field">
      <label>Nombre *</label>
      <input type="text" name="nombre" required value="<?= View::e($usuario['nombre'] ?? '') ?>">
    </div>
    <div class="field">
      <label>Email *</label>
      <input type="email" name="email" required value="<?= View::e($usuario['email'] ?? '') ?>">
    </div>
    <div class="field">
      <label>Rol</label>
      <input type="text" value="Organizador" disabled>
    </div>
    <div class="field">
      <label>Estado</label>
      <select name="estado">
        <option value="activo" <?= ($usuario['estado'] ?? 'activo') === 'activo' ? 'selected' : '' ?>>Activo</option>
        <option value="inactivo" <?= ($usuario['estado'] ?? '') === 'inactivo' ? 'selected' : '' ?>>Inactivo</option>
        <option value="suspendido" <?= ($usuario['estado'] ?? '') === 'suspendido' ? 'selected' : '' ?>>Suspendido</option>
      </select>
    </div>
    <div class="field">
      <label>Contraseña <?= $usuario ? '(dejar en blanco para no cambiar)' : '(obligatoria)' ?></label>
      <input type="password" name="password" <?= $usuario ? '' : 'required' ?> autocomplete="new-password" minlength="8">
    </div>
  </div>
  <div class="form-actions">
    <a class="btn" href="/admin/usuarios">Cancelar</a>
    <button type="submit" class="btn primary"><?= $usuario ? 'Guardar cambios' : 'Crear organizador' ?></button>
  </div>
</form>
