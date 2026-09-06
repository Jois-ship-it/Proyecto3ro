<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Gestión</div>
    <h1>Equipos</h1>
  </div>
  <a class="btn primary" href="/admin/equipos/crear">+ Nuevo equipo</a>
</section>

<div class="table-wrap">
  <div class="table-scroll">
    <table>
      <thead>
        <tr><th>Nombre</th><th>Categoría</th><th>Integrantes</th><th>Estado</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php if (empty($equipos)): ?>
          <tr><td colspan="5" class="muted">Sin equipos registrados.</td></tr>
        <?php else: ?>
          <?php foreach ($equipos as $e): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:.6rem">
                <?= View::avatar($e['nombre'], Upload::url('logos', $e['logo'] ?? null), 32) ?>
                <strong><?= View::e($e['nombre']) ?></strong>
              </div>
            </td>
            <td><?= View::e($e['categoria'] ?? $e['disciplina'] ?? '—') ?></td>
            <td><?= (int)($e['total_integrantes'] ?? 0) ?></td>
            <td><?= View::estadoChip($e['estado']) ?></td>
            <td>
              <a class="btn small" href="/admin/equipos/editar/<?= (int)$e['id'] ?>">Gestionar</a>
              <?php $activo = $e['estado'] === 'activo'; ?>
              <form method="POST" action="/admin/equipos/eliminar/<?= (int)$e['id'] ?>" style="display:inline"
                    onsubmit="return confirm('<?= $activo ? '¿Desactivar' : '¿Reactivar' ?> este equipo?')">
                <?= Csrf::field() ?>
                <button class="btn small <?= $activo ? 'danger' : 'primary' ?>"><?= $activo ? 'Desactivar' : 'Activar' ?></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
