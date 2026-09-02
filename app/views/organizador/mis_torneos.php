<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Organizador</div>
    <h1>Mis torneos</h1>
  </div>
</section>

<div class="table-wrap">
  <div class="table-scroll">
    <table>
      <thead>
        <tr><th>Nombre</th><th>Formato</th><th>Estado</th><th>Acciones</th></tr>
      </thead>
      <tbody>
        <?php if (empty($torneos)): ?>
          <tr><td colspan="4" class="muted">Sin torneos asignados.</td></tr>
        <?php else: ?>
          <?php foreach ($torneos as $t): ?>
          <tr>
            <td><strong><?= View::e($t['nombre']) ?></strong></td>
            <td><span class="chip"><?= View::e($t['tipo_nombre']) ?></span></td>
            <td><?= View::estadoChip($t['estado']) ?></td>
            <td><a class="btn small primary" href="/organizador/torneos/<?= (int)$t['id'] ?>">Gestionar</a></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
