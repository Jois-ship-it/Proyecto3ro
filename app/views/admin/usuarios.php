<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Administración</div>
    <h1>Organizadores</h1>
    <p class="muted">Usuarios con permiso para gestionar torneos asignados.</p>
  </div>
  <a class="btn primary" href="/admin/usuarios/crear">+ Nuevo organizador</a>
</section>

<div class="table-wrap">
  <div class="table-scroll">
    <table>
      <thead>
        <tr><th>ID</th><th>Nombre</th><th>Email</th><th>Estado</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php if (empty($usuarios)): ?>
          <tr><td colspan="5" class="muted">No hay organizadores. Creá uno con “Nuevo organizador”.</td></tr>
        <?php else: ?>
          <?php foreach ($usuarios as $u): ?>
          <tr>
            <td><?= (int)$u['id'] ?></td>
            <td><strong><?= View::e($u['nombre']) ?></strong></td>
            <td><?= View::e($u['email']) ?></td>
            <td><?= View::estadoChip($u['estado']) ?></td>
            <td>
              <a class="btn small" href="/admin/usuarios/editar/<?= (int)$u['id'] ?>">Editar</a>
              <?php $activo = $u['estado'] === 'activo'; ?>
              <form method="POST" action="/admin/usuarios/eliminar/<?= (int)$u['id'] ?>" style="display:inline"
                    onsubmit="return confirm('<?= $activo ? '¿Desactivar' : '¿Reactivar' ?> este organizador?')">
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
