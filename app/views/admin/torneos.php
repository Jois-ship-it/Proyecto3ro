<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Gestión</div>
    <h1>Torneos</h1>
  </div>
  <a class="btn primary" href="/admin/torneos/crear">+ Nuevo torneo</a>
</section>

<div class="table-wrap">
  <div class="table-scroll">
    <table>
      <thead>
        <tr><th>Nombre</th><th>Formato</th><th>Modalidad</th><th>Inscritos</th><th>Estado</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php if (empty($torneos)): ?>
          <tr><td colspan="6" class="muted">Sin torneos creados.</td></tr>
        <?php else: ?>
          <?php foreach ($torneos as $t): ?>
          <tr>
            <td><strong><?= View::e($t['nombre']) ?></strong></td>
            <td><span class="chip"><?= View::e($t['tipo_nombre']) ?></span></td>
            <td><?= View::e(ucfirst($t['modalidad'])) ?></td>
            <td><?= (new TorneoModel())->contarInscritos((int)$t['id']) ?></td>
            <td><?= View::estadoChip($t['estado']) ?></td>
            <td>
              <a class="btn small" href="/admin/torneos/<?= (int)$t['id'] ?>">Gestionar</a>
              <a class="btn small" href="/admin/torneos/editar/<?= (int)$t['id'] ?>">Editar</a>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
