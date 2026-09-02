<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Organizador</div>
    <h1>Participantes</h1>
    <p class="muted">Gestioná los participantes registrados en el sistema.</p>
  </div>
  <a class="btn primary" href="/organizador/participantes/crear">+ Nuevo participante</a>
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
              <a class="btn small" href="/organizador/participantes/editar/<?= (int)$p['id'] ?>">Editar</a>
              <?php $activo = $p['estado'] === 'activo'; ?>
              <form method="POST" action="/organizador/participantes/eliminar/<?= (int)$p['id'] ?>" style="display:inline"
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
