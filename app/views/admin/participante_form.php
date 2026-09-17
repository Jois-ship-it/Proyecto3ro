<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Participante</div>
    <h1>Editar participante</h1>
  </div>
  <a class="btn" href="/admin/participantes">← Volver</a>
</section>

<!-- Solo edición: el alta la hace la propia persona desde el registro público. -->
<form method="POST" action="/admin/participantes/editar/<?= (int)$participante['id'] ?>" class="form-card">
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
      <label>Email</label>
      <input type="email" name="email" value="<?= View::e($participante['email'] ?? '') ?>">
    </div>
    <div class="field">
      <label>Teléfono</label>
      <input type="text" name="telefono" value="<?= View::e($participante['telefono'] ?? '') ?>">
    </div>
    <div class="field">
      <label>Estado</label>
      <?php if (!empty($participante) && ($participante['estado'] ?? '') === 'suspendido'): ?>
        <select disabled>
          <option selected>Suspendido</option>
        </select>
        <input type="hidden" name="estado" value="suspendido">
      <?php else: ?>
        <select name="estado">
          <option value="activo" <?= ($participante['estado'] ?? 'activo') === 'activo' ? 'selected' : '' ?>>Activo</option>
          <option value="inactivo" <?= ($participante['estado'] ?? '') === 'inactivo' ? 'selected' : '' ?>>Inactivo</option>
          <option value="suspendido" <?= ($participante['estado'] ?? '') === 'suspendido' ? 'selected' : '' ?>>Suspendido</option>
        </select>
      <?php endif; ?>
    </div>
  </div>
  <div class="form-actions">
    <a class="btn" href="/admin/participantes">Cancelar</a>
    <button type="submit" class="btn primary"><?= $participante ? 'Guardar cambios' : 'Crear participante' ?></button>
  </div>
</form>
