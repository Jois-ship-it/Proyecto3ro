<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Participante</div>
    <h1>Mis torneos</h1>
  </div>
</section>

<div class="table-wrap">
  <div class="table-scroll">
    <table>
      <thead>
        <tr><th>Torneo</th><th>Formato</th><th>Estado</th><th>Inscripción</th><th>Ver</th></tr>
      </thead>
      <tbody>
        <?php if (empty($torneos)): ?>
          <tr><td colspan="5" class="muted">No estás inscrito en ningún torneo.</td></tr>
        <?php else: ?>
          <?php foreach ($torneos as $t): ?>
          <tr>
            <td><strong><?= View::e($t['torneo_nombre']) ?></strong></td>
            <td><span class="chip"><?= View::e($t['tipo_slug']) ?></span></td>
            <td><?= View::estadoChip($t['torneo_estado']) ?></td>
            <td><?= View::estadoChip($t['estado']) ?></td>
            <td><a class="btn small" href="/torneo/<?= (int)$t['torneo_id'] ?>">Ver →</a></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
