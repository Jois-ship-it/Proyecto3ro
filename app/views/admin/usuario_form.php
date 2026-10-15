<?php
/** Formulario de una cuenta de UN rol. Variables: $ctx (singular, ruta, rolEtiqueta, aviso), $usuario (o null). */
$estadoActual = $usuario['estado'] ?? 'activo';
?>
<section class="page-header">
  <div class="page-title">
    <div class="eyebrow"><?= View::e(ucfirst($ctx['singular'])) ?></div>
    <h1><?= $usuario ? 'Editar ' : 'Nuevo ' ?><?= View::e($ctx['singular']) ?></h1>
    <p class="muted"><?= $ctx['aviso'] ?></p>
  </div>
  <a class="btn" href="<?= $ctx['ruta'] ?>">← Volver</a>
</section>

<form method="POST" action="<?= $ctx['ruta'] ?>/<?= $usuario ? 'editar/' . (int)$usuario['id'] : 'crear' ?>" class="form-card">
  <?= Csrf::field() ?>
  <div class="form-grid">
    <div class="field">
      <label for="usuario-form-nombre">Nombre *</label>
      <input id="usuario-form-nombre" type="text" name="nombre" required value="<?= View::e($usuario['nombre'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="usuario-form-email">Email *</label>
      <input id="usuario-form-email" type="email" autocomplete="off" name="email" required value="<?= View::e($usuario['email'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="usuario-form-rol">Rol</label>
      <input id="usuario-form-rol" type="text" value="<?= View::e($ctx['rolEtiqueta']) ?>" disabled>
    </div>
    <div class="field">
      <label for="usuario-form-estado">Estado</label>
      <?php if ($estadoActual === 'bloqueada'): ?>
        <select id="usuario-form-estado" disabled>
          <option selected>Bloqueada</option>
        </select>
        <input type="hidden" name="estado" value="bloqueada">
      <?php else: ?>
        <select id="usuario-form-estado" name="estado">
          <option value="activo" <?= $estadoActual === 'activo' ? 'selected' : '' ?>>Activo</option>
          <option value="inactivo" <?= $estadoActual === 'inactivo' ? 'selected' : '' ?>>Inactivo</option>
          <?php if (!in_array($estadoActual, ['activo', 'inactivo'], true)): /* otro estado que ya tenía la cuenta: se conserva */ ?>
            <option value="<?= View::e($estadoActual) ?>" selected><?= View::e(ucfirst($estadoActual)) ?></option>
          <?php endif; ?>
        </select>
      <?php endif; ?>
    </div>
    <div class="field">
      <label for="usuario-form-password">Contraseña <?= $usuario ? '(dejar en blanco para no cambiar)' : '(obligatoria)' ?></label>
      <input id="usuario-form-password" type="password" name="password" <?= $usuario ? '' : 'required' ?> autocomplete="new-password" minlength="8">
      <small class="muted">Al menos 8 caracteres, con mayúscula, minúscula, número y símbolo.</small>
    </div>
  </div>
  <div class="form-actions">
    <a class="btn" href="<?= $ctx['ruta'] ?>">Cancelar</a>
    <button type="submit" class="btn primary"><?= $usuario ? 'Guardar cambios' : 'Crear ' . View::e($ctx['singular']) ?></button>
  </div>
</form>
