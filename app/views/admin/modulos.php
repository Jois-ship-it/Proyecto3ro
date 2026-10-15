<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Sistema</div>
    <h1>Módulos</h1>
  </div>
</section>

<p class="muted">
  Desactivar un módulo de formato (Liga, Eliminación Directa, Sistema Suizo) impide
  <strong>crear torneos nuevos</strong> de ese formato y <strong>generar su estructura</strong>
  (fixture, bracket o primera ronda). Los torneos que ya están en curso no se interrumpen:
  siguen aceptando resultados y pueden terminarse normalmente.
</p>

<div class="grid cols-3">
  <?php foreach ($modulos as $m): ?>
  <article class="card flex flex-col gap-075">
    <div class="flex items-center justify-between">
      <strong><?= View::e($m['nombre']) ?></strong>
      <?= View::estadoChip($m['estado']) ?>
    </div>
    <?php if ($m['descripcion']): ?><p class="muted"><?= View::e($m['descripcion']) ?></p><?php endif; ?>
    <form method="POST" action="/admin/modulos/toggle/<?= (int)$m['id'] ?>" data-confirmar="¿Cambiar estado del módulo?">
      <?= Csrf::field() ?>
      <button type="submit" class="btn small <?= $m['estado'] === 'activo' ? 'danger' : 'primary' ?>">
        <?= $m['estado'] === 'activo' ? 'Desactivar' : 'Activar' ?>
      </button>
    </form>
  </article>
  <?php endforeach; ?>
</div>
