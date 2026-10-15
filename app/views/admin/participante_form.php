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
      <label for="participante-form-nombre">Nombre completo *</label>
      <input id="participante-form-nombre" type="text" name="nombre" required value="<?= View::e($participante['nombre'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="participante-form-nick">Nick / Alias</label>
      <input id="participante-form-nick" type="text" name="nick" value="<?= View::e($participante['nick'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="participante-form-documento">Documento (DNI / ID)</label>
      <input id="participante-form-documento" type="text" name="documento" value="<?= View::e($participante['documento'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="participante-form-email">Email</label>
      <input id="participante-form-email" type="email" autocomplete="off" name="email" value="<?= View::e($participante['email'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="participante-form-telefono">Teléfono</label>
      <input id="participante-form-telefono" type="text" name="telefono" value="<?= View::e($participante['telefono'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="participante-form-estado">Estado</label>
      <?php if (!empty($participante) && ($participante['estado'] ?? '') === 'suspendido'): ?>
        <select id="participante-form-estado" disabled>
          <option selected>Suspendido</option>
        </select>
        <input type="hidden" name="estado" value="suspendido">
      <?php else: ?>
        <select id="participante-form-estado" name="estado">
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
