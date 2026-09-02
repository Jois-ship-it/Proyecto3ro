<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Participante</div>
    <h1><?= $participante ? 'Editar participante' : 'Nuevo participante' ?></h1>
  </div>
  <a class="btn" href="/admin/participantes">← Volver</a>
</section>

<form method="POST" action="/admin/participantes/<?= $participante ? 'editar/' . (int)$participante['id'] : 'crear' ?>" class="form-card">
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
      <select name="estado">
        <option value="activo" <?= ($participante['estado'] ?? 'activo') === 'activo' ? 'selected' : '' ?>>Activo</option>
        <option value="inactivo" <?= ($participante['estado'] ?? '') === 'inactivo' ? 'selected' : '' ?>>Inactivo</option>
        <option value="suspendido" <?= ($participante['estado'] ?? '') === 'suspendido' ? 'selected' : '' ?>>Suspendido</option>
      </select>
    </div>
  </div>
  <div class="form-actions">
    <a class="btn" href="/admin/participantes">Cancelar</a>
    <button type="submit" class="btn primary"><?= $participante ? 'Guardar cambios' : 'Crear participante' ?></button>
  </div>
</form>
