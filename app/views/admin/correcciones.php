<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Resultados</div>
    <h1>Solicitudes de corrección</h1>
    <p class="muted">Revisá y aprobá o rechazá las correcciones solicitadas por los organizadores.</p>
  </div>
</section>

<div class="table-wrap">
  <div class="table-scroll">
    <table>
      <thead>
        <tr>
          <th>Estado</th>
          <th>Torneo</th>
          <th>Partido</th>
          <th>Actual → Solicitado</th>
          <th>Motivo</th>
          <th>Solicitante</th>
          <th>Fecha</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($solicitudes)): ?>
          <tr><td colspan="8" class="muted">No hay solicitudes de corrección.</td></tr>
        <?php else: ?>
          <?php foreach ($solicitudes as $s): ?>
            <?php
              $nombreA = $s['participante_a_nombre'] ?? $s['equipo_a_nombre'] ?? '—';
              $nombreB = $s['participante_b_nombre'] ?? $s['equipo_b_nombre'] ?? '—';
              $pendiente = $s['estado'] === 'pendiente';
            ?>
            <tr>
              <td><?= View::estadoChip($s['estado']) ?></td>
              <td><strong><?= View::e($s['torneo_nombre']) ?></strong></td>
              <td><?= View::e($nombreA) ?> <span class="muted">vs</span> <?= View::e($nombreB) ?></td>
              <td>
                <span class="muted"><?= (int)$s['actual_a'] ?>–<?= (int)$s['actual_b'] ?></span>
                &nbsp;→&nbsp;
                <strong><?= (int)$s['puntos_a'] ?>–<?= (int)$s['puntos_b'] ?></strong>
              </td>
              <td style="max-width:240px"><?= View::e($s['motivo']) ?>
                <?php if ($s['estado'] === 'rechazada' && $s['motivo_rechazo']): ?>
                  <div class="chip danger" style="margin-top:.3rem;display:block;white-space:normal">Rechazo: <?= View::e($s['motivo_rechazo']) ?></div>
                <?php endif; ?>
              </td>
              <td><?= View::e($s['solicitante_nombre']) ?>
                <?php if ($s['revisor_nombre']): ?><div class="muted" style="font-size:.8rem">Revisó: <?= View::e($s['revisor_nombre']) ?></div><?php endif; ?>
              </td>
              <td style="white-space:nowrap" class="muted"><?= View::e($s['created_at']) ?></td>
              <td>
                <?php if ($pendiente): ?>
                  <form method="POST" action="/admin/correcciones/<?= (int)$s['id'] ?>/aprobar" style="display:inline"
                        onsubmit="return confirm('¿Aprobar y aplicar esta corrección? Se recalculará la tabla.')">
                    <?= Csrf::field() ?>
                    <button class="btn small primary">Aprobar</button>
                  </form>
                  <button class="btn small danger" data-rechazar data-id="<?= (int)$s['id'] ?>">Rechazar</button>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal rechazar -->
<div id="modalRechazar" style="display:none;position:fixed;inset:0;background:rgba(3,8,16,.78);z-index:100;place-items:center" aria-hidden="true">
  <div class="form-card modal-card" style="width:min(100%,460px)">
    <h3>Rechazar solicitud</h3>
    <form method="POST" id="formRechazar">
      <?= Csrf::field() ?>
      <div class="field">
        <label>Motivo del rechazo *</label>
        <textarea name="motivo_rechazo" required minlength="5" placeholder="Explicá por qué se rechaza…"></textarea>
      </div>
      <div class="form-actions">
        <button type="button" class="btn" onclick="document.getElementById('modalRechazar').style.display='none'">Cancelar</button>
        <button type="submit" class="btn danger">Rechazar</button>
      </div>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('[data-rechazar]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('formRechazar').action = '/admin/correcciones/' + btn.dataset.id + '/rechazar';
    const m = document.getElementById('modalRechazar');
    m.style.display = 'grid'; m.setAttribute('aria-hidden','false');
  });
});
document.getElementById('modalRechazar').addEventListener('click', e => {
  if (e.target === e.currentTarget) e.currentTarget.style.display = 'none';
});
</script>
