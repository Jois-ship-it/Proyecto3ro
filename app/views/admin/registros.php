<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Administración</div>
    <h1>Registros pendientes</h1>
    <p class="muted">Solicitudes de participantes que se registraron por cuenta propia.</p>
  </div>
</section>

<div class="table-wrap">
  <div class="table-scroll">
    <table>
      <thead>
        <tr><th>Nombre</th><th>Nick</th><th>Email</th><th>Teléfono</th><th>Fecha</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php if (empty($pendientes)): ?>
          <tr><td colspan="6" class="muted">No hay registros pendientes. 🎉</td></tr>
        <?php else: ?>
          <?php foreach ($pendientes as $u): ?>
          <tr>
            <td><strong><?= View::e($u['nombre']) ?></strong></td>
            <td><?= View::e($u['nick'] ?? '—') ?></td>
            <td><?= View::e($u['email']) ?></td>
            <td><?= View::e($u['telefono'] ?? '—') ?></td>
            <td class="muted" style="white-space:nowrap"><?= View::e($u['created_at']) ?></td>
            <td>
              <form method="POST" action="/admin/registros/<?= (int)$u['id'] ?>/aprobar" style="display:inline"
                    onsubmit="return confirm('¿Aprobar a <?= View::e($u['nombre']) ?>? Podrá iniciar sesión.')">
                <?= Csrf::field() ?>
                <button class="btn small primary">Aprobar</button>
              </form>
              <form method="POST" action="/admin/registros/<?= (int)$u['id'] ?>/rechazar" style="display:inline"
                    onsubmit="return confirm('¿Rechazar esta solicitud?')">
                <?= Csrf::field() ?>
                <button class="btn small danger">Rechazar</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
