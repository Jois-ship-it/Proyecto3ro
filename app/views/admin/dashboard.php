<section class="page-header">
  <div class="page-title">
    <div class="eyebrow">Administración</div>
    <h1>Panel general</h1>
    <p>Resumen operativo del sistema <?= View::e($_sitio['nombre']) ?>.</p>
  </div>
</section>

<div class="kpi-row">
  <div class="stat-card"><strong><?= (int)$totalTorneos ?></strong><span>Torneos</span></div>
  <div class="stat-card"><strong><?= (int)$totalParticipantes ?></strong><span>Participantes</span></div>
  <div class="stat-card"><strong><?= (int)$totalEquipos ?></strong><span>Equipos</span></div>
  <div class="stat-card"><strong><?= count($actividad) ?></strong><span>Acciones recientes</span></div>
</div>

<div class="grid cols-2">
  <article class="card">
    <h2 class="como-h3">Actividad reciente</h2>
    <div class="timeline">
      <?php if (empty($actividad)): ?>
        <p class="muted">Sin actividad registrada.</p>
      <?php else: ?>
        <?php foreach (array_slice($actividad, 0, 8) as $log): ?>
          <div class="timeline-item">
            <strong><?= View::e($log['accion']) ?></strong>
            <?php if ($log['descripcion']): ?>
              <div class="muted rompe-texto"><?= View::e($log['descripcion']) ?></div>
            <?php endif; ?>
            <div class="muted fs-80"><?= View::e($log['created_at']) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </article>

  <article class="card">
    <h2 class="como-h3">Accesos rápidos</h2>
    <div class="actions flex-col items-stretch">
      <a class="btn" href="/admin/registros">Registros de participantes</a>
      <a class="btn" href="/admin/equipos/crear">+ Nuevo equipo</a>
      <a class="btn primary" href="/admin/torneos/crear">+ Nuevo torneo</a>
      <a class="btn" href="/admin/organizadores/crear">+ Nuevo organizador</a>
    </div>
  </article>
</div>
