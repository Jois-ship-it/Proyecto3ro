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
  <h2 class="como-h3">Datos del equipo</h2>
  <div class="form-grid">
    <div class="field span-all">
      <label for="equipo-form-logo">Logo del equipo</label>
      <div class="flex items-center gap-1">
        <?= View::avatar($equipo['nombre'] ?? 'Equipo', $logoUrl, 56) ?>
        <input id="equipo-form-logo" type="file" name="logo" accept="image/jpeg,image/png,image/webp,image/gif">
      </div>
      <span class="muted fs-80">JPG, PNG, WEBP o GIF. Máx 2 MB.</span>
    </div>
    <div class="field">
      <label for="equipo-form-nombre">Nombre *</label>
      <input id="equipo-form-nombre" type="text" name="nombre" required value="<?= View::e($equipo['nombre'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="equipo-form-categoria">Categoría</label>
      <input id="equipo-form-categoria" type="text" name="categoria" value="<?= View::e($equipo['categoria'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="equipo-form-disciplina">Disciplina</label>
      <input id="equipo-form-disciplina" type="text" name="disciplina" value="<?= View::e($equipo['disciplina'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="equipo-form-estado">Estado</label>
      <select id="equipo-form-estado" name="estado">
        <option value="activo" <?= ($equipo['estado'] ?? 'activo') === 'activo' ? 'selected' : '' ?>>Activo</option>
        <option value="inactivo" <?= ($equipo['estado'] ?? '') === 'inactivo' ? 'selected' : '' ?>>Inactivo</option>
      </select>
    </div>
    <div class="field span-all">
      <label for="equipo-form-descripcion">Descripción</label>
      <textarea id="equipo-form-descripcion" name="descripcion" placeholder="Breve descripción del equipo…"><?= View::e($equipo['descripcion'] ?? '') ?></textarea>
    </div>
  </div>
  <div class="form-actions">
    <a class="btn" href="/admin/equipos">Cancelar</a>
    <?php if ($equipo): ?><a class="btn" href="/equipo/<?= (int)$equipo['id'] ?>" target="_blank" rel="noopener">Ver perfil público ↗</a><?php endif; ?>
    <button type="submit" class="btn primary"><?= $equipo ? 'Guardar' : 'Crear equipo' ?></button>
  </div>
</form>

<?php if ($equipo): ?>
<div class="panel">
  <h2 class="como-h3">Integrantes actuales</h2>
  <?php if (empty($equipo['participantes'])): ?>
    <p class="muted">Sin integrantes aún.</p>
  <?php else: ?>
    <?php foreach ($equipo['participantes'] as $p): ?>
    <div class="fila-integrante">
      <div>
        <strong><?= View::e($p['nombre']) ?></strong>
        <span class="chip ml-04"><?= View::e($p['rol_en_equipo']) ?></span>
      </div>
      <form class="d-inline" method="POST" action="/admin/equipos/<?= (int)$equipo['id'] ?>/quitar-participante">
        <?= Csrf::field() ?>
        <input type="hidden" name="participante_id" value="<?= (int)$p['id'] ?>">
        <button class="btn small danger">Quitar</button>
      </form>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <h2 class="como-h3 mt-1">Agregar participante</h2>
  <form method="POST" action="/admin/equipos/<?= (int)$equipo['id'] ?>/agregar-participante" class="form-grid alinear-abajo">
    <?= Csrf::field() ?>
    <div class="field">
      <label for="equipo-form-participante">Participante</label>
      <div class="combobox" data-combobox data-name="participante_id" data-required="true"
           data-options='<?= View::e(json_encode($participantes, JSON_UNESCAPED_UNICODE)) ?>'>
        <input id="equipo-form-participante" type="text" placeholder="Buscar participante…" autocomplete="off">
        <input type="hidden" name="participante_id" value="">
        <ul class="combobox-list"></ul>
      </div>
    </div>
    <div class="field">
      <label for="equipo-form-rol_en_equipo">Rol</label>
      <select id="equipo-form-rol_en_equipo" name="rol_en_equipo">
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
