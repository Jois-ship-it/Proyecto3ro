<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Gestión</div>
    <h1>Participantes</h1>
    <p class="muted">Personas individuales que compiten en torneos. Se registran por cuenta propia.</p>
  </div>
  <a class="btn" href="/admin/registros">Registros pendientes →</a>
</section>

<div class="table-wrap">
  <div class="table-scroll">
    <table>
      <thead>
        <tr><th>Nombre</th><th>Nick / Doc</th><th>Contacto</th><th>Equipos</th><th>Estado</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php if (empty($participantes)): ?>
          <tr><td colspan="6" class="muted">Sin participantes registrados.</td></tr>
        <?php else: ?>
          <?php foreach ($participantes as $p): ?>
          <tr>
            <td><strong><?= View::e($p['nombre']) ?></strong></td>
            <td><?= View::e($p['nick'] ?? $p['documento'] ?? '—') ?></td>
            <td><?= View::e($p['email'] ?? $p['telefono'] ?? '—') ?></td>
            <td><span class="muted"><?= View::e($p['equipos'] ?? '—') ?></span></td>
            <td><?= View::estadoChip($p['estado']) ?></td>
            <td>
              <a class="btn small" href="/admin/participantes/editar/<?= (int)$p['id'] ?>">Editar</a>
              <?php $activo = $p['estado'] === 'activo'; ?>
              <form method="POST" action="/admin/participantes/eliminar/<?= (int)$p['id'] ?>" style="display:inline"
                    onsubmit="return confirm('<?= $activo ? '¿Desactivar' : '¿Reactivar' ?> este participante?')">
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
