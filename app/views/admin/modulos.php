<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Sistema</div>
    <h1>Módulos</h1>
  </div>
</section>

<div class="grid cols-3">
  <?php foreach ($modulos as $m): ?>
  <article class="card" style="display:flex;flex-direction:column;gap:.75rem">
    <div style="display:flex;align-items:center;justify-content:space-between">
      <strong><?= View::e($m['nombre']) ?></strong>
      <?= View::estadoChip($m['estado']) ?>
    </div>
    <?php if ($m['descripcion']): ?><p class="muted"><?= View::e($m['descripcion']) ?></p><?php endif; ?>
    <form method="POST" action="/admin/modulos/toggle/<?= (int)$m['id'] ?>" onsubmit="return confirm('¿Cambiar estado del módulo?')">
      <?= Csrf::field() ?>
      <button type="submit" class="btn small <?= $m['estado'] === 'activo' ? 'danger' : 'primary' ?>">
        <?= $m['estado'] === 'activo' ? 'Desactivar' : 'Activar' ?>
      </button>
    </form>
  </article>
  <?php endforeach; ?>
</div>
