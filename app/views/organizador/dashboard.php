<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Organizador</div>
    <h1>Panel del organizador</h1>
    <p class="muted">Torneos asignados y accesos rápidos.</p>
  </div>
</section>

<?php if (empty($torneos)): ?>
  <div class="card"><p class="muted">No tenés torneos asignados aún.</p></div>
<?php else: ?>
  <div class="grid cols-3">
    <?php foreach ($torneos as $t): ?>
    <article class="card">
      <div style="display:flex;justify-content:space-between;align-items:start;gap:.5rem">
        <h3 style="margin:0"><?= View::e($t['nombre']) ?></h3>
        <?= View::estadoChip($t['estado']) ?>
      </div>
      <p class="muted"><?= View::e($t['tipo_nombre']) ?> · <?= View::e(ucfirst($t['modalidad'])) ?></p>
      <a class="btn primary" href="/organizador/torneos/<?= (int)$t['id'] ?>">Gestionar →</a>
    </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
