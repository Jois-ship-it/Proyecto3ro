<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Equipo</div>
    <h1><?= $equipo ? View::e($equipo['nombre']) : 'Nuevo equipo' ?></h1>
  </div>
  <a class="btn" href="/admin/equipos">← Volver</a>
</section>

<div class="grid cols-2">

<?php $logoUrl = $equipo ? Upload::url('logos', $equipo['logo'] ?? null) : null; ?>
<form method="POST" action="/admin/equipos/<?= $equipo ? 'editar/' . (int)$equipo['id'] : 'crear' ?>" class="form-card" enctype="multipart/form-data">
  <?= Csrf::field() ?>
  <h3>Datos del equipo</h3>
  <div class="form-grid">
    <div class="field" style="grid-column:1/-1">
      <label>Logo del equipo</label>
      <div style="display:flex;align-items:center;gap:1rem">
        <?= View::avatar($equipo['nombre'] ?? 'Equipo', $logoUrl, 56) ?>
        <input type="file" name="logo" accept="image/jpeg,image/png,image/webp,image/gif">
      </div>
      <span class="muted" style="font-size:.8rem">JPG, PNG, WEBP o GIF. Máx 2 MB.</span>
    </div>
    <div class="field">
      <label>Nombre *</label>
      <input type="text" name="nombre" required value="<?= View::e($equipo['nombre'] ?? '') ?>">
    </div>
    <div class="field">
      <label>Categoría</label>
      <input type="text" name="categoria" value="<?= View::e($equipo['categoria'] ?? '') ?>">
    </div>
    <div class="field">
      <label>Disciplina</label>
      <input type="text" name="disciplina" value="<?= View::e($equipo['disciplina'] ?? '') ?>">
    </div>
    <div class="field">
      <label>Estado</label>
      <select name="estado">
        <option value="activo" <?= ($equipo['estado'] ?? 'activo') === 'activo' ? 'selected' : '' ?>>Activo</option>
        <option value="inactivo" <?= ($equipo['estado'] ?? '') === 'inactivo' ? 'selected' : '' ?>>Inactivo</option>
      </select>
    </div>
    <div class="field" style="grid-column:1/-1">
      <label>Descripción</label>
      <textarea name="descripcion" placeholder="Breve descripción del equipo…"><?= View::e($equipo['descripcion'] ?? '') ?></textarea>
    </div>
  </div>
  <div class="form-actions">
    <a class="btn" href="/admin/equipos">Cancelar</a>
    <?php if ($equipo): ?><a class="btn" href="/equipo/<?= (int)$equipo['id'] ?>" target="_blank">Ver perfil público ↗</a><?php endif; ?>
    <button type="submit" class="btn primary"><?= $equipo ? 'Guardar' : 'Crear equipo' ?></button>
  </div>
</form>

<?php if ($equipo): ?>
<div class="panel">
  <h3>Integrantes actuales</h3>
  <?php if (empty($equipo['participantes'])): ?>
    <p class="muted">Sin integrantes aún.</p>
  <?php else: ?>
    <?php foreach ($equipo['participantes'] as $p): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid var(--line)">
      <div>
        <strong><?= View::e($p['nombre']) ?></strong>
        <span class="chip" style="margin-left:.4rem"><?= View::e($p['rol_en_equipo']) ?></span>
      </div>
      <form method="POST" action="/admin/equipos/<?= (int)$equipo['id'] ?>/quitar-participante" style="display:inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="participante_id" value="<?= (int)$p['id'] ?>">
        <button class="btn small danger">Quitar</button>
      </form>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <h3 style="margin-top:1rem">Agregar participante</h3>
  <form method="POST" action="/admin/equipos/<?= (int)$equipo['id'] ?>/agregar-participante" class="form-grid" style="align-items:end">
    <?= Csrf::field() ?>
    <div class="field">
      <label>Participante</label>
      <select name="participante_id" required>
        <option value="">Seleccionar…</option>
        <?php foreach ($participantes as $p): ?>
          <option value="<?= (int)$p['id'] ?>"><?= View::e($p['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Rol</label>
      <select name="rol_en_equipo">
        <option value="jugador">Jugador</option>
        <option value="capitan">Capitán</option>
        <option value="suplente">Suplente</option>
      </select>
    </div>
    <div><button type="submit" class="btn primary">Agregar</button></div>
  </form>
</div>
<?php endif; ?>

</div>
